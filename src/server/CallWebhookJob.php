<?php
declare(strict_types=1);

namespace wise\webhook\server;

use think\facade\Cache;
use think\facade\Event;
use think\queue\Job;
use wise\support\backoff\BackoffStrategy;
use wise\support\circuit\CircuitBreaker;
use wise\webhook\server\contract\SignerInterface;
use wise\webhook\server\event\WebhookFailed;
use wise\webhook\server\event\WebhookSending;
use wise\webhook\server\event\WebhookSent;
use wise\webhook\server\exception\ServiceUnavailableException;
use wise\webhook\server\exception\WebhookSendException;
use wise\webhook\server\model\WebhookSendModel;

/**
 * Webhook 发送队列任务
 *
 * 支持两种执行模式：
 * - 同步：调用 send() 方法，可执行闭包回调
 * - 异步：通过 think-queue 调用 fire() 方法，仅触发事件
 *
 * 功能：
 * - curl 发送 HTTP 请求
 * - 签名计算（通过 SignerInterface）
 * - 重试与可配置退避策略
 * - 数据库记录
 * - 事件触发
 */
class CallWebhookJob
{
    /** @var array 任务数据 */
    private array $data;

    /** @var WebhookSendModel|null 数据库记录 */
    private ?WebhookSendModel $model = null;

    /** @var int 已发送次数（含本次），队列模式下由 data['_attempt'] 追踪 */
    private int $attemptCount = 0;

    /**
     * @param array $data WebhookCall::toArray() 的输出（队列模式下可不传）
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
        $this->attemptCount = $data['_attempt'] ?? 0;
    }

    /**
     * 队列执行入口（think-queue 约定）
     *
     * @param Job   $job  队列任务对象
     * @param array $data 任务数据
     */
    public function fire(Job $job, array $data): void
    {
        $this->data = $data;
        $this->attemptCount = $data['_attempt'] ?? 0;
        $this->attemptCount++;

        try {
            $result = $this->sendRequest();

            // 持久化成功记录
            $this->persistSuccess($result);

            // 触发成功事件
            $this->fireSentEvent($result);

            // 从队列删除
            $job->delete();

        } catch (ServiceUnavailableException $e) {
            // 熔断开启：包装为 WebhookSendException 触发队列退避重试，不丢死信
            $wrapped = new WebhookSendException(
                $e->getMessage(), 0, $this->data['url'] ?? '', $this->data['method'] ?? 'POST', $this->attemptCount, $e
            );
            $this->persistFailure($wrapped);
            $this->handleRetry($job, $data, $wrapped);
        } catch (WebhookSendException $e) {
            $this->persistFailure($e);
            $this->handleRetry($job, $data, $e);
        } catch (\Throwable $e) {
            $this->persistFailure(
                new WebhookSendException(
                    $e->getMessage(),
                    0,
                    $this->data['url'] ?? '',
                    $this->data['method'] ?? 'POST',
                    $this->attemptCount,
                    $e
                )
            );
            $job->delete();
        }
    }

    /**
     * 同步发送入口
     *
     * @return array{success: bool, response: string, code: int, duration_ms: int}
     *
     * @throws WebhookSendException
     */
    public function send(): array
    {
        $this->attemptCount++;

        // 发送前回调
        if ($this->data['has_callbacks'] ?? false) {
            $callback = $this->data['_on_sending'] ?? null;
            if (is_callable($callback)) {
                call_user_func($callback, $this->data['url'], $this->data['payload']);
            }
        }

        // 触发发送前事件
        Event::trigger(new WebhookSending(
            $this->data['url'],
            $this->data['payload'] ?? [],
            $this->data['headers'] ?? [],
            $this->data['meta'] ?? [],
            $this->attemptCount
        ));

        try {
            $result = $this->sendRequest();
            $this->persistSuccess($result);
            $this->fireSentEvent($result);

            // 成功回调
            if ($this->data['has_callbacks'] ?? false) {
                $callback = $this->data['_on_sent'] ?? null;
                if (is_callable($callback)) {
                    call_user_func(
                        $callback,
                        $this->data['url'],
                        $result['response'],
                        $result['duration_ms']
                    );
                }
            }

            return $result;

        } catch (WebhookSendException $e) {
            $this->persistFailure($e);

            // 失败回调
            if ($this->data['has_callbacks'] ?? false) {
                $callback = $this->data['_on_failed'] ?? null;
                if (is_callable($callback)) {
                    call_user_func(
                        $callback,
                        $this->data['url'],
                        $e->getMessage(),
                        $this->attemptCount
                    );
                }
            }

            $this->fireFailedEvent($e, false);
            throw $e;
        }
    }

    /**
     * 发送 HTTP 请求（核心）
     *
     * @return array{success: bool, response: string, code: int, duration_ms: int}
     *
     * @throws WebhookSendException
     */
    private function sendRequest(): array
    {
        $url     = $this->data['url'] ?? '';
        $method  = $this->data['method'] ?? 'POST';
        $payload = $this->data['payload'] ?? [];
        $headers = $this->data['headers'] ?? [];
        $timeout = $this->data['timeout'] ?? 15;
        $connectTimeout = $this->data['connect_timeout'] ?? 5;
        $verifySsl = $this->data['verify_ssl'] ?? true;

        if (empty($url)) {
            throw new WebhookSendException('Webhook URL 未设置', 0, $url, $method, $this->attemptCount);
        }

        // SSRF 防护：
        //   1) 仅允许 http/https；
        //   2) 校验目标主机解析出的 IP 不得为内网/保留地址；
        //   3) 返回 CURLOPT_RESOLVE 的 IP 固定条目（防 DNS 重绑定：校验与连接使用同一 IP）。
        $parsed = parse_url($url);
        $host = (string) ($parsed['host'] ?? '');
        if ($host === '') {
            throw new WebhookSendException('Webhook URL 缺少主机名', 0, $url, $method, $this->attemptCount);
        }
        $resolveEntries = $this->assertOutboundUrlAllowed($url);

        // 断路器 guard（per-host）
        $breaker = $this->getHostBreaker($host);
        if (!$breaker->isAvailable()) {
            throw new ServiceUnavailableException(host: $host);
        }

        // 预先序列化 JSON body（避免重复编码，确保签名与发送体严格一致）
        $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 计算签名：优先队列数据（同步模式的内存 secret），否则从 config 重读（异步模式，防密钥明文落队列）
        $secret = $this->data['secret'] ?? $this->secretFromConfig();
        if ($secret !== null && $secret !== '') {
            $signer = $this->makeSigner();
            $signature = $signer->calculateSignature($jsonBody, $secret);
            $headerName = $signer->signatureHeaderName();
            if ($headerName !== '' && $signature !== '') {
                $headers[$headerName] = $signature;
            }
        }

        // 构建 curl 请求头数组
        // 确保 Content-Type 已设置（如果用户未覆盖）
        $hasContentType = false;
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === 'content-type') {
                $hasContentType = true;
                break;
            }
        }
        if (!$hasContentType) {
            $headers['Content-Type'] = 'application/json; charset=utf-8';
        }

        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            // 关闭自动跟随，改由下方手动逐跳跟随并重新执行 SSRF 校验
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);

        // 固定已校验 IP，防止 DNS 重绑定
        if (!empty($resolveEntries)) {
            curl_setopt($ch, CURLOPT_RESOLVE, $resolveEntries);
        }

        // HTTP 方法处理
        $method = strtoupper($method);

        switch ($method) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
                break;
            case 'PUT':
            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
                break;
            case 'GET':
                // GET 请求：将 payload 转为查询参数
                if (!empty($payload)) {
                    $separator = (strpos($url, '?') === false) ? '?' : '&';
                    $url .= $separator . http_build_query($payload);
                    curl_setopt($ch, CURLOPT_URL, $url);
                }
                break;
            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if ($jsonBody !== '[]') {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
                }
                break;
        }

        // 手动跟随重定向：逐跳重新执行 SSRF 校验，防止被 3xx 引导到内网/保留地址
        $startTime    = microtime(true);
        $currentUrl   = $url;
        $redirects    = 0;
        $response     = false;
        $httpCode     = 0;
        $error        = '';

        do {
            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);

            if ($response === false || !empty($error)) {
                break;
            }

            // 非 3xx：请求结束
            if (!in_array($httpCode, [301, 302, 303, 307, 308], true)) {
                break;
            }

            // 3xx：读取 Location 并手动跳转（受限协议 + 逐跳 SSRF 校验）
            $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            if ($location === '' || ++$redirects > 5) {
                break;
            }
            if (!preg_match('#^https?://#i', $location)) {
                $location = $this->resolveRelativeUrl($currentUrl, $location);
            }
            $resolveEntries = $this->assertOutboundUrlAllowed($location);
            $currentUrl     = $location;
            curl_setopt($ch, CURLOPT_URL, $location);
            if (!empty($resolveEntries)) {
                curl_setopt($ch, CURLOPT_RESOLVE, $resolveEntries);
            }
        } while (true);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);
        curl_close($ch);

        if ($response === false || !empty($error)) {
            $breaker->reportFailure();
            throw new WebhookSendException(
                "curl 请求失败: {$error}",
                0,
                $url,
                $method,
                $this->attemptCount
            );
        }

        $responseStr = is_string($response) ? $response : '';

        // 判断成功（2xx）
        $success = $httpCode >= 200 && $httpCode < 300;

        if (!$success) {
            // 仅 5xx（上游故障）计入熔断，4xx 属调用方问题不计入
            if ($httpCode >= 500) {
                $breaker->reportFailure();
            }
            throw new WebhookSendException(
                "HTTP {$httpCode}: {$responseStr}",
                $httpCode,
                $url,
                $method,
                $this->attemptCount
            );
        }

        $breaker->reportSuccess();
        return [
            'success'     => true,
            'response'    => $responseStr,
            'code'        => $httpCode,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * 校验出站 URL 是否允许（SSRF 防护）
     *
     * 规则：
     *   1. 协议仅允许 http/https
     *   2. 解析主机所有 IP（含 AAAA），任一命中内网/保留地址即拒绝
     *   3. 返回用于 CURLOPT_RESOLVE 的 "host:port:ip" 固定条目，防止 DNS 重绑定
     *
     * @param string $url 待发送 URL
     * @return string[] CURLOPT_RESOLVE 条目（无法解析时返回空数组，交由 curl 失败兜底）
     * @throws WebhookSendException URL 不合法或命中内网地址时
     */
    private function assertOutboundUrlAllowed(string $url): array
    {
        $parsed = parse_url($url);
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new WebhookSendException('仅允许 http/https 协议的 Webhook: ' . $url, 0, $url);
        }
        $host = (string) ($parsed['host'] ?? '');
        if ($host === '') {
            throw new WebhookSendException('Webhook URL 缺少主机名: ' . $url, 0, $url);
        }

        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            return []; // 域名解析失败，交由 curl 连接失败兜底
        }
        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new WebhookSendException('不允许向内部地址发送 Webhook: ' . $host, 0, $url);
            }
        }

        $port = (int) ($parsed['port'] ?? ($scheme === 'https' ? 443 : 80));
        $entries = [];
        foreach ($ips as $ip) {
            $entries[] = "{$host}:{$port}:{$ip}";
        }
        return $entries;
    }

    /**
     * 解析主机名的全部 IP（IPv4 A + IPv6 AAAA）
     *
     * @return string[]
     */
    private function resolveHostIps(string $host): array
    {
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        // A 记录
        $resolved = @gethostbynamel($host);
        if (is_array($resolved)) {
            foreach ($resolved as $ip) {
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ips[] = $ip;
                }
            }
        }

        // AAAA 记录（gethostbynamel 不返回 IPv6）
        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $rec) {
                if (!empty($rec['ipv6']) && filter_var($rec['ipv6'], FILTER_VALIDATE_IP)) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * 判断 IP 是否为内网 / 保留地址（SSRF 防护），兼容 IPv4/IPv6
     */
    private function isBlockedIp(string $ip): bool
    {
        $blockedCidrs = [
            // IPv4 回环/私网/链路本地/保留
            '127.0.0.0/8',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '169.254.0.0/16',
            '0.0.0.0/8',
            '100.64.0.0/10',
            '224.0.0.0/4',
            // IPv6 回环/未指定/私网/链路本地/组播/映射段（::ffff 封 IPv4 也应被拦截）
            '::1/128',
            '::/128',
            'fc00::/7',
            'fe80::/10',
            'ff00::/8',
            '::ffff:0:0/96',
        ];

        foreach ($blockedCidrs as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断 IP 是否在 CIDR 网段内（IPv4 / IPv6 通用）
     *
     * @param string $ip   IP 地址
     * @param string $cidr CIDR 表示法，如 192.168.0.0/16 或 ::1/128
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return false;
        }
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $packedIp  = @inet_pton($ip);
        $packedNet = @inet_pton($subnet);
        if ($packedIp === false || $packedNet === false) {
            return false;
        }
        if (strlen($packedIp) !== strlen($packedNet)) {
            return false; // IPv4 与 IPv6 不直接比较
        }

        $fullBytes = intdiv($bits, 8);
        $remain    = $bits % 8;
        if ($fullBytes > 0 && substr($packedIp, 0, $fullBytes) !== substr($packedNet, 0, $fullBytes)) {
            return false;
        }
        if ($remain > 0) {
            $mask = (0xFF << (8 - $remain)) & 0xFF;
            if ((ord($packedIp[$fullBytes]) & $mask) !== (ord($packedNet[$fullBytes]) & $mask)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 将相对地址按当前 URL 解析为绝对地址
     */
    private function resolveRelativeUrl(string $base, string $relative): string
    {
        $parts = parse_url($base);
        if ($parts === false || empty($parts['host'])) {
            return $relative;
        }
        $scheme = $parts['scheme'] ?? 'http';
        $host   = $parts['host'];
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
        $dir    = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '') ?: '/';

        if ($relative === '') {
            return $relative;
        }
        if ($relative[0] === '/') {
            return $scheme . '://' . $host . $port . $relative;
        }
        if ($relative[0] === '?') {
            return $scheme . '://' . $host . $port . ($parts['path'] ?? '/') . $relative;
        }

        $segments = [];
        foreach (explode('/', $dir . $relative) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($segments);
            } else {
                $segments[] = $seg;
            }
        }
        return $scheme . '://' . $host . $port . '/' . implode('/', $segments);
    }

    /**
     * 创建签名器实例
     *
     * @return SignerInterface
     */
    private function makeSigner(): SignerInterface
    {
        $signerClass = $this->data['signer_class'] ?? \wise\webhook\server\signer\DefaultSigner::class;
        return new $signerClass();
    }

    /**
     * 持久化发送成功记录
     *
     * @param array $result
     */
    private function persistSuccess(array $result): void
    {
        try {
            $model = $this->getOrCreateModel();
            $model->markSuccess($result['code'], $result['response'], $result['duration_ms']);
        } catch (\Throwable $e) {
            // 持久化失败不阻塞主流程，但记录错误日志
            trigger_error(
                sprintf('Webhook persistSuccess failed: %s', $e->getMessage()),
                E_USER_WARNING
            );
        }
    }

    /**
     * 持久化发送失败记录
     *
     * @param WebhookSendException $e
     */
    private function persistFailure(WebhookSendException $e): void
    {
        try {
            $model = $this->getOrCreateModel();
            $model->markFailed($e->getMessage(), $this->attemptCount);
        } catch (\Throwable $ignore) {
            // 持久化失败不阻塞主流程，但记录错误日志
            trigger_error(
                sprintf('Webhook persistFailure failed: %s', $ignore->getMessage()),
                E_USER_WARNING
            );
        }
    }

    /**
     * 获取或创建数据库记录
     *
     * @return WebhookSendModel
     */
    private function getOrCreateModel(): WebhookSendModel
    {
        if ($this->model !== null) {
            return $this->model;
        }

        $modelId = $this->data['_model_id'] ?? null;
        if ($modelId !== null) {
            $model = WebhookSendModel::find($modelId);
            if ($model) {
                $this->model = $model;
                return $this->model;
            }
            // modelId 存在但记录未找到（可能被误删），尝试通过 URL+状态 去重恢复
            $exists = WebhookSendModel::where('url', $this->data['url'] ?? '')
                ->where('status', 'sending')
                ->order('id', 'desc')
                ->find();
            if ($exists) {
                $this->data['_model_id'] = $exists->id;
                $this->model = $exists;
                return $this->model;
            }
        }

        // 创建新记录
        $model = new WebhookSendModel();
        $model->save([
            'url'          => $this->data['url'] ?? '',
            'method'       => $this->data['method'] ?? 'POST',
            'payload'      => $this->data['payload'] ?? [],
            'headers'      => $this->data['headers'] ?? [],
            'status'       => 'sending',
            'attempt'      => $this->attemptCount,
            'max_attempts' => $this->data['max_retries'] ?? 3,
            'meta'         => $this->data['meta'] ?? [],
        ]);

        // 保存 id 以便后续引用
        $this->data['_model_id'] = $model->id;

        $this->model = $model;
        return $this->model;
    }

    /**
     * 处理队列任务的重试
     *
     * @param Job                  $job  队列任务
     * @param array                $data 任务数据
     * @param WebhookSendException $e    异常
     */
    private function handleRetry(Job $job, array $data, WebhookSendException $e): void
    {
        $maxRetries  = $data['max_retries'] ?? 3;
        $willRetry   = $this->attemptCount < $maxRetries;

        // 触发失败事件
        $this->fireFailedEvent($e, $willRetry);

        if (!$willRetry) {
            // 超出最大重试次数，删除任务
            $job->delete();
            return;
        }

        // 计算退避延迟
        $backoffData = $data['backoff'] ?? ['method' => 'linear', 'base_delay_ms' => 1000];
        $delayMs = $this->calculateBackoffDelay($backoffData, $this->attemptCount);

        // 转换为秒（最小延迟 1 秒，避免队列立即重试）
        $delaySeconds = max(1, (int) ceil($delayMs / 1000));

        // 更新重试次数
        $data['_attempt'] = $this->attemptCount;

        // 延迟重新入队
        /** @var \think\Queue $queue */
        $queue = app()->make('queue');
        $queueName = $data['queue_name'] ?? null;

        if ($queueName !== null) {
            $queue->later($delaySeconds, self::class, $data, $queueName);
        } else {
            $queue->later($delaySeconds, self::class, $data);
        }

        // 标记旧任务完成
        $job->delete();
    }

    /**
     * 计算退避延迟
     *
     * @param array $backoffData 退避配置
     * @param int   $attempt     当前重试次数
     * @return int 延迟毫秒数
     */
    private function calculateBackoffDelay(array $backoffData, int $attempt): int
    {
        $strategy = BackoffStrategy::fromArray($backoffData);
        return $strategy->delayForAttempt($attempt);
    }

    /**
     * 触发发送成功事件
     *
     * @param array $result
     */
    private function fireSentEvent(array $result): void
    {
        Event::trigger(new WebhookSent(
            $this->data['url'] ?? '',
            $this->data['payload'] ?? [],
            $result['response'],
            $result['code'],
            $result['duration_ms'],
            $this->data['meta'] ?? [],
            $this->attemptCount
        ));
    }

    /**
     * 触发发送失败事件
     *
     * @param WebhookSendException $e        异常
     * @param bool                  $willRetry 是否还会重试
     */
    private function fireFailedEvent(WebhookSendException $e, bool $willRetry): void
    {
        Event::trigger(new WebhookFailed(
            $this->data['url'] ?? '',
            $this->data['payload'] ?? [],
            $e->getMessage(),
            $e->getCode(),
            $this->attemptCount,
            $this->data['max_retries'] ?? 3,
            $willRetry,
            $this->data['meta'] ?? []
        ));
    }

    /**
     * 按目标 host 创建/获取断路器实例（key_prefix=wise_webhook:cb）
     */
    private function getHostBreaker(string $host): CircuitBreaker
    {
        static $breakers = [];
        if (!isset($breakers[$host])) {
            $cfg = config('wise-webhook.circuit', []);
            $breakers[$host] = new CircuitBreaker(
                $host,
                Cache::store('redis'),
                [
                    'failure_threshold' => $cfg['failure_threshold'] ?? 5,
                    'timeout'           => $cfg['timeout'] ?? 120,
                    'key_prefix'        => $cfg['key_prefix'] ?? 'wise_webhook:cb',
                ]
            );
        }
        return $breakers[$host];
    }

    /**
     * 从 config 重读签名密钥（异步投递时 secret 不进队列序列化，见 WebhookCall::toArray，L1）。
     *
     * 兼容两个键：优先 wise-agent 的 webhook secret（WebhookConnector 的来源键），
     * 其次 wise-webhook 自己的 server.secret（本包独立使用场景）。
     */
    private function secretFromConfig(): ?string
    {
        foreach (['wise-agent.closure.webhook.secret', 'wise-webhook.server.secret'] as $key) {
            try {
                $secret = config($key, '');
                if (is_string($secret) && $secret !== '') {
                    return $secret;
                }
            } catch (\Throwable) {
                // 配置不可用时继续尝试下一个键
            }
        }

        return null;
    }
}

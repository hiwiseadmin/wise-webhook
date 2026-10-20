<?php
declare(strict_types=1);

namespace wise\webhook\server;

use think\facade\Queue;
use wise\support\backoff\BackoffStrategy;
use wise\webhook\server\contract\SignerInterface;
use wise\webhook\server\signer\DefaultSigner;

/**
 * Webhook 发送调用 — 流式构建器
 *
 * 构建一个 Webhook 调用并通过 dispatch() 发送。
 *
 * 用法：
 * <pre>
 * WebhookCall::create()
 *     ->url('https://example.com/hooks')
 *     ->payload(['event' => 'order.paid'])
 *     ->useSecret('my-secret')
 *     ->dispatch();
 * </pre>
 *
 * @method $this onSending(callable $callback)  发送前回调 fn(string $url, array $payload): void
 * @method $this onSent(callable $callback)     成功后回调 fn(string $url, string $response, int $durationMs): void
 * @method $this onFailed(callable $callback)   失败后回调 fn(string $url, string $error, int $attempt): void
 */
class WebhookCall
{
    /** @var string 目标 URL */
    private string $url = '';

    /** @var array 请求体 */
    private array $payload = [];

    /** @var string|null 签名密钥 */
    private ?string $secret = null;

    /** @var class-string<SignerInterface> 签名器类名 */
    private string $signerClass;

    /** @var string HTTP 方法 */
    private string $method = 'POST';

    /** @var int 超时时间（秒） */
    private int $timeout;

    /** @var int 连接超时（秒） */
    private int $connectTimeout;

    /** @var int 最大重试次数 */
    private int $maxRetries;

    /** @var BackoffStrategy 退避策略 */
    private BackoffStrategy $backoffStrategy;

    /** @var array 额外请求头 */
    private array $headers = [];

    /** @var array 元数据 */
    private array $meta = [];

    /** @var bool SSL 证书验证 */
    private bool $verifySsl;

    /** @var string|null 队列名称 */
    private ?string $queueName;

    /** @var callable|null 发送前回调 */
    private $onSendingCallback = null;

    /** @var callable|null 发送成功回调 */
    private $onSentCallback = null;

    /** @var callable|null 发送失败回调 */
    private $onFailedCallback = null;

    /**
     * 静态工厂
     *
     * @return static
     */
    public static function create(): self
    {
        $config = config('wise-webhook.server', []);

        $instance = new self();
        $instance->signerClass     = $config['signer'] ?? DefaultSigner::class;
        $instance->timeout         = $config['http']['timeout'] ?? 15;
        $instance->connectTimeout  = $config['http']['connect_timeout'] ?? 5;
        $instance->method          = $config['http']['method'] ?? 'POST';
        $instance->verifySsl       = $config['http']['verify_ssl'] ?? true;
        $instance->maxRetries      = $config['retry']['max_attempts'] ?? 3;
        $instance->queueName       = $config['queue'] ?? null;
        $instance->headers         = $config['headers'] ?? [];

        // 退避策略
        $backoffMethod  = $config['retry']['backoff_method'] ?? 'linear';
        $backoffBaseMs  = $config['retry']['backoff_base_ms'] ?? 1000;
        $backoffMaxMs   = $config['retry']['backoff_max_ms'] ?? 60000;

        switch ($backoffMethod) {
            case 'exponential':
                $instance->backoffStrategy = BackoffStrategy::exponential($backoffBaseMs, $backoffMaxMs);
                break;
            case 'fixed':
                $instance->backoffStrategy = BackoffStrategy::fixed($backoffBaseMs);
                break;
            case 'custom':
                $instance->backoffStrategy = BackoffStrategy::custom([$backoffBaseMs]);
                break;
            case 'linear':
            default:
                $instance->backoffStrategy = BackoffStrategy::linear($backoffBaseMs);
                break;
        }

        // 全局回调
        $callbacks = $config['callbacks'] ?? [];
        if (isset($callbacks['on_sending']) && is_callable($callbacks['on_sending'])) {
            $instance->onSendingCallback = $callbacks['on_sending'];
        }
        if (isset($callbacks['on_sent']) && is_callable($callbacks['on_sent'])) {
            $instance->onSentCallback = $callbacks['on_sent'];
        }
        if (isset($callbacks['on_failed']) && is_callable($callbacks['on_failed'])) {
            $instance->onFailedCallback = $callbacks['on_failed'];
        }

        return $instance;
    }

    /**
     * 设置目标 URL
     *
     * @param string $url
     * @return $this
     */
    public function url(string $url): self
    {
        $this->url = $url;
        return $this;
    }

    /**
     * 设置请求体
     *
     * @param array $payload
     * @return $this
     */
    public function payload(array $payload): self
    {
        $this->payload = $payload;
        return $this;
    }

    /**
     * 设置签名密钥
     *
     * @param string $secret 签名密钥
     * @return $this
     */
    public function useSecret(string $secret): self
    {
        $this->secret = $secret;
        return $this;
    }

    /**
     * 设置签名器类名
     *
     * @param class-string<SignerInterface> $signerClass
     * @return $this
     */
    public function signer(string $signerClass): self
    {
        $this->signerClass = $signerClass;
        return $this;
    }

    /**
     * 设置 HTTP 方法
     *
     * @param string $method POST/PUT/PATCH
     * @return $this
     */
    public function method(string $method): self
    {
        $this->method = strtoupper($method);
        return $this;
    }

    /**
     * 设置超时时间
     *
     * @param int $seconds 秒
     * @return $this
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * 设置连接超时
     *
     * @param int $seconds 秒
     * @return $this
     */
    public function connectTimeout(int $seconds): self
    {
        $this->connectTimeout = $seconds;
        return $this;
    }

    /**
     * 设置最大重试次数
     *
     * @param int $max 重试次数（不含首次发送）
     * @return $this
     */
    public function maxRetries(int $max): self
    {
        $this->maxRetries = $max;
        return $this;
    }

    /**
     * 设置退避策略
     *
     * @param BackoffStrategy $strategy
     * @return $this
     */
    public function backoffStrategy(BackoffStrategy $strategy): self
    {
        $this->backoffStrategy = $strategy;
        return $this;
    }

    /**
     * 设置额外请求头
     *
     * @param array $headers
     * @return $this
     */
    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    /**
     * 设置追踪元数据
     *
     * @param array $meta
     * @return $this
     */
    public function meta(array $meta): self
    {
        $this->meta = array_merge($this->meta, $meta);
        return $this;
    }

    /**
     * 设置 SSL 验证
     *
     * @param bool $verify
     * @return $this
     */
    public function verifySsl(bool $verify): self
    {
        $this->verifySsl = $verify;
        return $this;
    }

    /**
     * 设置队列名称
     *
     * @param string|null $name 队列名，null 为默认队列
     * @return $this
     */
    public function queue(?string $name): self
    {
        $this->queueName = $name;
        return $this;
    }

    /**
     * 设置发送前回调（覆盖全局配置）
     *
     * @param callable $callback fn(string $url, array $payload): void
     * @return $this
     */
    public function onSending(callable $callback): self
    {
        $this->onSendingCallback = $callback;
        return $this;
    }

    /**
     * 设置发送成功回调（覆盖全局配置）
     *
     * @param callable $callback fn(string $url, string $response, int $durationMs): void
     * @return $this
     */
    public function onSent(callable $callback): self
    {
        $this->onSentCallback = $callback;
        return $this;
    }

    /**
     * 设置发送失败回调（覆盖全局配置）
     *
     * @param callable $callback fn(string $url, string $error, int $attempt): void
     * @return $this
     */
    public function onFailed(callable $callback): self
    {
        $this->onFailedCallback = $callback;
        return $this;
    }

    /**
     * 按配置模式发送（默认同步，可全局配置为 queue）
     *
     * @return array{success: bool, response: string, code: int, duration_ms: int}|void
     */
    public function dispatch()
    {
        $defaultMode = config('wise-webhook.server.dispatch.default_mode', 'sync');

        if ($defaultMode === 'queue') {
            return $this->dispatchToQueue();
        }

        return $this->dispatchSync();
    }

    /**
     * 同步立即发送（阻塞）
     *
     * @return array{success: bool, response: string, code: int, duration_ms: int}
     */
    public function dispatchSync(): array
    {
        $data = $this->toArray();

        // 同步模式：secret 仅在内存传递（不落队列存储），补回签名密钥
        $data['secret'] = $this->secret;

        // 将闭包回调传入数据数组（仅同步模式可用，不可序列化）
        $data['_on_sending'] = $this->onSendingCallback;
        $data['_on_sent']    = $this->onSentCallback;
        $data['_on_failed']  = $this->onFailedCallback;
        $data['has_callbacks'] = $this->hasCallbacks();

        $job = new CallWebhookJob($data);
        return $job->send();
    }

    /**
     * 推入队列异步发送
     *
     * @param string|null $queue 队列名，null 使用实例预设值
     */
    public function dispatchToQueue(?string $queue = null): void
    {
        $queueName = $queue ?? $this->queueName;

        if ($queueName !== null) {
            Queue::push(CallWebhookJob::class, $this->toArray(), $queueName);
        } else {
            Queue::push(CallWebhookJob::class, $this->toArray());
        }
    }

    /**
     * 满足条件才发送
     *
     * @param bool $condition
     * @return array{success: bool, response: string, code: int, duration_ms: int}|null
     */
    public function dispatchIf(bool $condition): ?array
    {
        if ($condition) {
            $result = $this->dispatch();
            return is_array($result) ? $result : null;
        }
        return null;
    }

    /**
     * 不满足条件才发送
     *
     * @param bool $condition
     * @return array{success: bool, response: string, code: int, duration_ms: int}|null
     */
    public function dispatchUnless(bool $condition): ?array
    {
        return $this->dispatchIf(!$condition);
    }

    /**
     * 序列化为数组（用于队列任务传输）
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'url'             => $this->url,
            'payload'         => $this->payload,
            // secret 不进序列化（防签名密钥明文落队列存储，L1）；异步投递由 CallWebhookJob 从 config 重读
            'signer_class'    => $this->signerClass,
            'method'          => $this->method,
            'timeout'         => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'max_retries'     => $this->maxRetries,
            'backoff'         => $this->backoffStrategy->toArray(),
            'headers'         => $this->headers,
            'meta'            => $this->meta,
            'verify_ssl'      => $this->verifySsl,
            'queue_name'      => $this->queueName,
            // 闭包无法序列化，仅同步模式可用
            'has_callbacks'   => $this->hasCallbacks(),
        ];
    }

    /**
     * 是否有闭包回调
     *
     * @return bool
     */
    public function hasCallbacks(): bool
    {
        return $this->onSendingCallback !== null
            || $this->onSentCallback !== null
            || $this->onFailedCallback !== null;
    }

    /**
     * 获取发送前回调
     *
     * @return callable|null
     */
    public function getOnSendingCallback(): ?callable
    {
        return $this->onSendingCallback;
    }

    /**
     * 获取发送成功回调
     *
     * @return callable|null
     */
    public function getOnSentCallback(): ?callable
    {
        return $this->onSentCallback;
    }

    /**
     * 获取发送失败回调
     *
     * @return callable|null
     */
    public function getOnFailedCallback(): ?callable
    {
        return $this->onFailedCallback;
    }

    /**
     * 获取目标 URL
     *
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * 获取签名密钥
     *
     * @return string|null
     */
    public function getSecret(): ?string
    {
        return $this->secret;
    }
}

<?php
declare(strict_types=1);

namespace wise\webhook\client;

use think\facade\Event;
use think\facade\Queue;
use think\Request;
use wise\webhook\client\contract\SignatureValidatorInterface;
use wise\webhook\client\event\WebhookReceived;
use wise\webhook\client\event\WebhookRejected;
use wise\webhook\client\exception\InvalidSignatureException;
use wise\webhook\client\model\WebhookReceiveModel;

/**
 * Webhook 接收编排器
 *
 * 编排从"接收请求"到"推送队列处理"的完整流程：
 * 1. 解析端点名
 * 2. 二次签名验证（双保险）
 * 3. 存储 WebhookReceiveModel
 * 4. 触发 WebhookReceived 事件
 * 5. 推送 ProcessWebhookJob 到队列
 * 6. 返回结果
 */
class WebhookReceiver
{
    /**
     * 接收并处理一个 Webhook 请求
     *
     * @param  Request      $request
     * @param  string|null  $endpointName 端点名
     * @return array{success: bool, id: int|null, message: string}
     */
    public function receive(Request $request, ?string $endpointName = null): array
    {
        // 确定端点名
        $endpointName = $endpointName ?? $request->param('_webhook_endpoint') ?? $this->guessEndpointName($request);

        if ($endpointName === null || $endpointName === '') {
            return ['success' => false, 'id' => null, 'message' => '无法确定端点名'];
        }

        // 获取端点配置
        $config = $this->getEndpointConfig($endpointName);

        if ($config === null) {
            Event::trigger(new WebhookRejected($endpointName, $request, '端点未配置'));
            return ['success' => false, 'id' => null, 'message' => "端点 '{$endpointName}' 未配置"];
        }

        // 二次签名验证
        if (!$this->validateSignature($request, $config)) {
            Event::trigger(new WebhookRejected($endpointName, $request, '签名验证失败'));

            if ($config['store_webhook_calls'] ?? true) {
                $this->storeRejected($request, $endpointName, '签名验证失败');
            }

            return ['success' => false, 'id' => null, 'message' => '签名验证失败'];
        }

        // 存储 Webhook 记录
        $model = null;
        if ($config['store_webhook_calls'] ?? true) {
            $model = $this->store($request, $endpointName);
        }

        // 触发已接收事件
        if ($model !== null) {
            Event::trigger(new WebhookReceived($model, $request, $endpointName));
        }

        // 推入队列处理
        $this->dispatchJob($endpointName, $model, $request, $config);

        return [
            'success' => true,
            'id'      => $model ? $model->id : null,
            'message' => 'Webhook received and queued',
        ];
    }

    /**
     * 仅验证签名，不存储不处理
     *
     * @param  Request $request
     * @param  string  $endpointName
     * @return bool
     */
    public function validateOnly(Request $request, string $endpointName): bool
    {
        $config = $this->getEndpointConfig($endpointName);

        if ($config === null) {
            return false;
        }

        return $this->validateSignature($request, $config);
    }

    /**
     * 二次验证签名
     *
     * @param  Request $request
     * @param  array   $config
     * @return bool
     */
    private function validateSignature(Request $request, array $config): bool
    {
        $validator = $this->makeValidator($config);
        return $validator->isValid($request, $config);
    }

    /**
     * 存储 Webhook 记录
     *
     * @param  Request $request
     * @param  string  $endpointName
     * @return WebhookReceiveModel
     */
    private function store(Request $request, string $endpointName): WebhookReceiveModel
    {
        $model = new WebhookReceiveModel();
        $model->save([
            'endpoint_name' => $endpointName,
            'payload'       => $request->post() ?: $request->get() ?: [],
            'headers'       => $this->extractHeaders($request),
            'signature'     => $this->extractSignature($request),
            'source_ip'     => $request->ip(),
            'status'        => 'pending',
            'attempt'       => 0,
            'max_attempts'  => 3,
            'meta'          => [
                'user_agent' => $request->header('User-Agent', ''),
                'content_type' => $request->header('Content-Type', ''),
            ],
        ]);

        return $model;
    }

    /**
     * 存储被拒记录
     *
     * @param  Request $request
     * @param  string  $endpointName
     * @param  string  $reason
     */
    private function storeRejected(Request $request, string $endpointName, string $reason): void
    {
        $model = new WebhookReceiveModel();
        $model->save([
            'endpoint_name' => $endpointName,
            'payload'       => $request->post() ?: $request->get() ?: [],
            'headers'       => $this->extractHeaders($request),
            'signature'     => $this->extractSignature($request),
            'source_ip'     => $request->ip(),
            'status'        => 'rejected',
            'error_message' => $reason,
            'attempt'       => 0,
            'max_attempts'  => 0,   // 被拒记录不再重试
            'meta'          => [
                'user_agent'   => $request->header('User-Agent', ''),
                'content_type' => $request->header('Content-Type', ''),
            ],
        ]);
    }

    /**
     * 推送处理任务到队列
     *
     * @param  string               $endpointName
     * @param  WebhookReceiveModel|null $model
     * @param  Request              $request
     * @param  array                $config
     * @return int|null
     */
    private function dispatchJob(
        string $endpointName,
        ?WebhookReceiveModel $model,
        Request $request,
        array $config
    ): ?int {
        // 如果未存储模型，跳过处理（无数据可处理）
        if ($model === null) {
            return null;
        }

        $data = [
            'webhook_call_id'  => $model->id,
            'endpoint_name'    => $endpointName,
            'processor_class'  => $config['processor_class']
                ?? \wise\webhook\client\handler\LogOnlyProcessor::class,
            'max_attempts'     => 3,
            '_attempt'         => 0,
        ];

        $queueName = $config['queue'] ?? 'webhooks';

        Queue::push(ProcessWebhookJob::class, $data, $queueName);

        return $model->id;
    }

    /**
     * 从请求中提取 Headers
     *
     * @param  Request $request
     * @return array
     */
    private function extractHeaders(Request $request): array
    {
        $headers = $request->header();
        // 过滤敏感头
        $exclude = ['cookie', 'set-cookie', 'authorization'];
        $result = [];
        foreach ($headers as $key => $value) {
            if (!in_array(strtolower($key), $exclude, true)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * 从请求头中提取签名
     *
     * @param  Request $request
     * @return string
     */
    private function extractSignature(Request $request): string
    {
        $signatureHeader = config('wise-webhook.client.defaults.signature_header', 'X-Signature');
        $value = $request->header($signatureHeader);
        return is_array($value) ? reset($value) : (string) $value;
    }

    /**
     * 猜测端点名
     *
     * @param  Request $request
     * @return string|null
     */
    private function guessEndpointName(Request $request): ?string
    {
        // 从 URL 参数
        $route = $request->route();
        $endpoint = $route['endpoint'] ?? $route['endpointName'] ?? null;
        if ($endpoint !== null) {
            return $endpoint;
        }

        // 从 _endpoint 参数
        return $request->param('_endpoint');
    }

    /**
     * 获取端点配置（合并默认值）
     *
     * @param  string $endpointName
     * @return array|null
     */
    private function getEndpointConfig(string $endpointName): ?array
    {
        $endpoints = config('wise-webhook.client.endpoints', []);
        $config    = $endpoints[$endpointName] ?? null;

        if ($config === null) {
            return null;
        }

        $defaults = config('wise-webhook.client.defaults', []);

        return array_merge($defaults, $config);
    }

    /**
     * 创建签名验证器实例
     *
     * @param  array $config
     * @return SignatureValidatorInterface
     */
    private function makeValidator(array $config): SignatureValidatorInterface
    {
        $validatorClass = $config['signature_validator']
            ?? \wise\webhook\client\validator\DefaultSignatureValidator::class;

        return new $validatorClass();
    }
}

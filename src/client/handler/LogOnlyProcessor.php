<?php
declare(strict_types=1);

namespace wise\webhook\client\handler;

use wise\support\context\ContextualLogger;
use think\Request;
use wise\webhook\client\contract\WebhookProcessorInterface;
use wise\webhook\client\model\WebhookReceiveModel;

/**
 * 默认处理器 — 仅记录日志
 *
 * 当用户未为端点指定自定义 Processor 时使用。
 * 仅做日志记录，不做任何业务处理。
 */
class LogOnlyProcessor implements WebhookProcessorInterface
{
    /**
     * 记录 Webhook 日志
     *
     * @param  WebhookReceiveModel $webhookCall
     * @param  Request              $request
     * @return void
     */
    public function handle(WebhookReceiveModel $webhookCall, Request $request): void
    {
        ContextualLogger::info('Webhook received (no custom processor)', [
            'endpoint' => $webhookCall->endpoint_name,
            'id'       => $webhookCall->id,
            'payload'  => $webhookCall->payload,
        ]);
    }
}

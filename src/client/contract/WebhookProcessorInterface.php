<?php
declare(strict_types=1);

namespace wise\webhook\client\contract;

use think\Request;
use wise\webhook\client\model\WebhookReceiveModel;

/**
 * Webhook 业务处理器接口
 *
 * ProcessWebhookJob 从队列加载 Webhook 记录后，
 * 委托此接口的实现类执行具体业务逻辑。
 *
 * 用户需为每个端点实现此接口（或使用默认的 LogOnlyProcessor）。
 */
interface WebhookProcessorInterface
{
    /**
     * 处理 Webhook 业务逻辑
     *
     * @param  WebhookReceiveModel $webhookCall 存储的 Webhook 记录
     * @param  Request              $request    原始请求
     * @return void
     */
    public function handle(WebhookReceiveModel $webhookCall, Request $request): void;
}

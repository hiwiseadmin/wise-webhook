<?php
declare(strict_types=1);

namespace wise\webhook;

/**
 * WebhookManager — Server/Client 统一管理入口
 *
 * 桥接 Facade，将调用转发到具体的 Server 或 Client 入口。
 * 此类仅作为容器绑定目标，实际 Server/Client 逻辑在各自子命名空间。
 */
class WebhookManager
{
    /**
     * 创建 Server 侧 Webhook 发送调用
     *
     * @return \wise\webhook\server\WebhookCall
     */
    public function serverCreate(): \wise\webhook\server\WebhookCall
    {
        return \wise\webhook\server\WebhookCall::create();
    }

    /**
     * 获取 Client 侧 Webhook 接收器
     *
     * @return \wise\webhook\client\WebhookReceiver
     */
    public function clientReceiver(): \wise\webhook\client\WebhookReceiver
    {
        return new \wise\webhook\client\WebhookReceiver();
    }
}

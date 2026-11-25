<?php
declare(strict_types=1);

namespace wise\webhook\client;

/**
 * Webhook Client 入口
 *
 * 提供创建 Webhook 接收器的静态快捷入口。
 *
 * 用法：
 * <pre>
 * use wise\webhook\client\WebhookClient;
 *
 * $receiver = WebhookClient::receiver();
 * $result = $receiver->receive(request(), 'stripe');
 * </pre>
 */
class WebhookClient
{
    /**
     * 创建 Webhook 接收器实例
     *
     * @return WebhookReceiver
     */
    public static function receiver(): WebhookReceiver
    {
        return new WebhookReceiver();
    }
}

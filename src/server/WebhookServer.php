<?php
declare(strict_types=1);

namespace wise\webhook\server;

/**
 * Webhook Server 入口
 *
 * 提供创建 Webhook 发送调用的静态快捷入口。
 *
 * 用法：
 * <pre>
 * use wise\webhook\server\WebhookServer;
 *
 * WebhookServer::create()
 *     ->url('https://example.com/hooks')
 *     ->payload(['event' => 'test'])
 *     ->dispatch();
 * </pre>
 */
class WebhookServer
{
    /**
     * 创建 Webhook 发送调用
     *
     * @return WebhookCall
     */
    public static function create(): WebhookCall
    {
        return WebhookCall::create();
    }
}

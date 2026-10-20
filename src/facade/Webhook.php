<?php
declare(strict_types=1);

namespace wise\webhook\facade;

use think\Facade;

/**
 * Webhook Facade — Server/Client 统一入口门面
 *
 * @method static \wise\webhook\server\WebhookCall serverCreate()  创建 Server 发送调用
 * @method static \wise\webhook\client\WebhookReceiver clientReceiver()  获取 Client 接收器
 *
 * @see \wise\webhook\WebhookManager
 */
class Webhook extends Facade
{
    protected static function getFacadeClass(): string
    {
        return \wise\webhook\WebhookManager::class;
    }
}

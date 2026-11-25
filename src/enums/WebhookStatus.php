<?php
declare(strict_types=1);

namespace wise\webhook\enums;

/**
 * Webhook 状态枚举
 */
class WebhookStatus
{
    /** 待发送/待处理 */
    public const PENDING    = 'pending';

    /** 发送中 */
    public const SENDING    = 'sending';

    /** 处理中（Client 侧） */
    public const PROCESSING = 'processing';

    /** 成功 */
    public const SUCCESS    = 'success';
    public const PROCESSED  = 'processed';

    /** 失败 */
    public const FAILED     = 'failed';

    /** 签名验证被拒 */
    public const REJECTED   = 'rejected';

    /**
     * Server 侧有效状态
     */
    public static function serverStatuses(): array
    {
        return [self::PENDING, self::SENDING, self::SUCCESS, self::FAILED];
    }

    /**
     * Client 侧有效状态
     */
    public static function clientStatuses(): array
    {
        return [self::PENDING, self::PROCESSING, self::PROCESSED, self::FAILED, self::REJECTED];
    }
}

<?php
declare(strict_types=1);

namespace wise\webhook\server\exception;

use wise\support\exception\ErrorCodeInterface;

/**
 * wise-webhook server 业务错误码（号段 100100~100199）
 *
 * @package wise\webhook\server\exception
 */
enum ErrorCode: int implements ErrorCodeInterface
{
    /** 发送失败 */
    case SEND_FAILED = 100101;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::SEND_FAILED => 'Webhook 发送失败',
        };
    }
}

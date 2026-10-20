<?php
declare(strict_types=1);

namespace wise\webhook\client\exception;

use wise\support\exception\ErrorCodeInterface;

/**
 * wise-webhook client 业务错误码（号段 100000~100099）
 *
 * @package wise\webhook\client\exception
 */
enum ErrorCode: int implements ErrorCodeInterface
{
    /** 签名验证失败 */
    case SIGNATURE_INVALID = 100001;

    /** 接收处理失败 */
    case RECEPTION_ERROR   = 100002;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::SIGNATURE_INVALID => 'Webhook 签名验证失败',
            self::RECEPTION_ERROR   => 'Webhook 接收处理失败',
        };
    }
}

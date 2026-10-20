<?php
declare(strict_types=1);

namespace wise\webhook\client\exception;

use wise\support\exception\WiseException;

/**
 * 签名验证失败异常
 *
 * 错误码：ErrorCode::SIGNATURE_INVALID (100001)
 * HTTP：403
 */
class InvalidSignatureException extends WiseException
{
    /** @var string 端点名称 */
    private string $endpointName;

    /**
     * @param string $endpointName 端点名
     * @param string $message      错误消息
     */
    public function __construct(string $endpointName = '', string $message = 'Invalid webhook signature')
    {
        parent::__construct(ErrorCode::SIGNATURE_INVALID->value, $message, 403);
        $this->endpointName = $endpointName;
    }

    /**
     * 获取端点名称
     *
     * @return string
     */
    public function getEndpointName(): string
    {
        return $this->endpointName;
    }
}

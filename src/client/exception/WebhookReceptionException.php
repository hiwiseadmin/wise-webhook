<?php
declare(strict_types=1);

namespace wise\webhook\client\exception;

use wise\support\exception\WiseException;

/**
 * Webhook 接收处理异常
 *
 * 错误码：ErrorCode::RECEPTION_ERROR (100002)
 * HTTP：默认 400
 */
class WebhookReceptionException extends WiseException
{
    /** @var string 端点名称 */
    private string $endpointName;

    /**
     * @param string          $endpointName 端点名
     * @param string          $message      错误消息
     * @param int             $code         错误码
     * @param \Throwable|null $previous     前置异常
     */
    public function __construct(
        string $endpointName = '',
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($code ?: ErrorCode::RECEPTION_ERROR->value, $message, 400, $previous);
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

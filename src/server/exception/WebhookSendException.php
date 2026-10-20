<?php
declare(strict_types=1);

namespace wise\webhook\server\exception;

use wise\support\exception\WiseException;

/**
 * Webhook 发送异常
 *
 * 错误码：ErrorCode::SEND_FAILED (100101)
 * HTTP：$code 参数保留 HTTP 码覆盖语义
 */
class WebhookSendException extends WiseException
{
    /** @var string 目标 URL */
    private string $webhookUrl;

    /** @var string HTTP 方法 */
    private string $httpMethod;

    /** @var int 重试次数 */
    private int $attempt;

    /**
     * @param string          $message    错误消息
     * @param int             $code       HTTP 状态码覆盖（0=默认 500）
     * @param string          $url        目标 URL
     * @param string          $httpMethod HTTP 方法
     * @param int             $attempt    重试次数
     * @param \Throwable|null $previous   前置异常
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        string $url = '',
        string $httpMethod = 'POST',
        int $attempt = 0,
        ?\Throwable $previous = null
    ) {
        $httpCode = $code > 0 ? $code : 500;
        parent::__construct(
            ErrorCode::SEND_FAILED->value,
            $message,
            $httpCode,
            $previous,
            ['url' => $url, 'http_method' => $httpMethod, 'attempt' => $attempt],
        );
        $this->webhookUrl = $url;
        $this->httpMethod = $httpMethod;
        $this->attempt    = $attempt;
    }

    /**
     * 获取目标 URL
     *
     * @return string
     */
    public function getWebhookUrl(): string
    {
        return $this->webhookUrl;
    }

    /**
     * 获取 HTTP 方法
     *
     * @return string
     */
    public function getHttpMethod(): string
    {
        return $this->httpMethod;
    }

    /**
     * 获取重试次数
     *
     * @return int
     */
    public function getAttempt(): int
    {
        return $this->attempt;
    }
}

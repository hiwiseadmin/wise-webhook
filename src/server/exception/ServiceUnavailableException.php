<?php
declare(strict_types=1);

namespace wise\webhook\server\exception;

use wise\support\circuit\CircuitBreakerExceptionInterface;
use wise\support\exception\GeneralErrorCode;
use wise\support\exception\WiseException;

/**
 * 服务不可用异常（熔断触发）
 *
 * 当某 Webhook 目标 host 的断路器处于 OPEN 状态时抛出。
 * 由 CallWebhookJob::fire() 捕获后触发队列退避重试，不立即丢入死信。
 *
 * 错误码：GeneralErrorCode::SERVICE_UNAVAILABLE (151)
 * HTTP：503
 */
class ServiceUnavailableException extends WiseException implements CircuitBreakerExceptionInterface
{
    protected int $retryAfter;

    public function __construct(
        string $message = 'Webhook target is temporarily unavailable (circuit breaker open)',
        int $retryAfter = 120,
        string $host = '',
        ?\Throwable $previous = null
    ) {
        $this->retryAfter = $retryAfter;
        $msg = $host ? "{$message} [{$host}]" : $message;
        parent::__construct(
            GeneralErrorCode::SERVICE_UNAVAILABLE->value,
            $msg,
            503,
            $previous,
            ['retry_after' => $retryAfter, 'host' => $host],
        );
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}

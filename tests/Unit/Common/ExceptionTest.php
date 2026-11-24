<?php
declare(strict_types=1);

namespace wise\webhook\tests\Unit\Common;

use wise\support\exception\GeneralErrorCode;
use wise\webhook\client\exception\ErrorCode as ClientErrorCode;
use wise\webhook\client\exception\InvalidSignatureException;
use wise\webhook\client\exception\WebhookReceptionException;
use wise\webhook\server\exception\ErrorCode as ServerErrorCode;
use wise\webhook\server\exception\ServiceUnavailableException;
use wise\webhook\server\exception\WebhookSendException;
use wise\webhook\tests\TestCase;

/**
 * 错误码枚举与异常类单元测试
 */
class ExceptionTest extends TestCase
{
    public function testClientErrorCodeValues(): void
    {
        $this->assertSame(100001, ClientErrorCode::SIGNATURE_INVALID->value);
        $this->assertSame(100002, ClientErrorCode::RECEPTION_ERROR->value);
        $this->assertSame('Webhook 签名验证失败', ClientErrorCode::SIGNATURE_INVALID->defaultMessage());
        $this->assertSame('Webhook 接收处理失败', ClientErrorCode::RECEPTION_ERROR->defaultMessage());
    }

    public function testServerErrorCodeValues(): void
    {
        $this->assertSame(100101, ServerErrorCode::SEND_FAILED->value);
        $this->assertSame('Webhook 发送失败', ServerErrorCode::SEND_FAILED->defaultMessage());
    }

    public function testInvalidSignatureException(): void
    {
        $e = new InvalidSignatureException('stripe', 'custom message');

        $this->assertSame('stripe', $e->getEndpointName());
        $this->assertSame('custom message', $e->getMessage());
        $this->assertSame(ClientErrorCode::SIGNATURE_INVALID->value, $e->getCode());
        $this->assertSame(403, $e->getHttpCode());
    }

    public function testInvalidSignatureExceptionDefaults(): void
    {
        $e = new InvalidSignatureException();

        $this->assertSame('', $e->getEndpointName());
        $this->assertSame('Invalid webhook signature', $e->getMessage());
    }

    public function testWebhookReceptionException(): void
    {
        $prev = new \RuntimeException('root');
        $e = new WebhookReceptionException('github', 'bad payload', 100002, $prev);

        $this->assertSame('github', $e->getEndpointName());
        $this->assertSame('bad payload', $e->getMessage());
        $this->assertSame(100002, $e->getCode());
        $this->assertSame(400, $e->getHttpCode());
        $this->assertSame($prev, $e->getPrevious());
    }

    public function testWebhookReceptionExceptionDefaultCode(): void
    {
        $e = new WebhookReceptionException('github', 'msg');

        $this->assertSame(ClientErrorCode::RECEPTION_ERROR->value, $e->getCode());
    }

    public function testWebhookSendException(): void
    {
        $e = new WebhookSendException('curl failed', 503, 'https://example.com/hook', 'POST', 2);

        $this->assertSame('https://example.com/hook', $e->getWebhookUrl());
        $this->assertSame('POST', $e->getHttpMethod());
        $this->assertSame(2, $e->getAttempt());
        $this->assertSame(ServerErrorCode::SEND_FAILED->value, $e->getCode());
        $this->assertSame(503, $e->getHttpCode());
    }

    public function testWebhookSendExceptionDefaultHttpCode(): void
    {
        $e = new WebhookSendException('boom');

        $this->assertSame(ServerErrorCode::SEND_FAILED->value, $e->getCode());
        $this->assertSame(500, $e->getHttpCode());
    }

    public function testServiceUnavailableException(): void
    {
        $e = new ServiceUnavailableException('target unavailable', 60, 'api.example.com');

        $this->assertSame(60, $e->getRetryAfter());
        $this->assertStringContainsString('api.example.com', $e->getMessage());
        $this->assertSame(GeneralErrorCode::SERVICE_UNAVAILABLE->value, $e->getCode());
        $this->assertSame(503, $e->getHttpCode());
    }

    public function testServiceUnavailableExceptionDefaults(): void
    {
        $e = new ServiceUnavailableException();

        $this->assertSame(120, $e->getRetryAfter());
    }
}

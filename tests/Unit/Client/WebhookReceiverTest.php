<?php
declare(strict_types=1);

namespace wise\webhook\tests\Unit\Client;

use think\Request;
use wise\webhook\client\WebhookReceiver;
use wise\webhook\client\WebhookClient;
use wise\webhook\client\validator\DefaultSignatureValidator;
use wise\webhook\tests\TestCase;

/**
 * WebhookReceiver 单元测试
 */
class WebhookReceiverTest extends TestCase
{
    public function testReceiverInstantiation(): void
    {
        $receiver = new WebhookReceiver();
        $this->assertInstanceOf(WebhookReceiver::class, $receiver);
    }

    public function testWebhookClientFactory(): void
    {
        $receiver = WebhookClient::receiver();
        $this->assertInstanceOf(WebhookReceiver::class, $receiver);
    }

    public function testReceiveWithUnknownEndpoint(): void
    {
        $receiver = new WebhookReceiver();
        $request  = $this->makeRequest('https://example.com/webhooks/unknown');

        $result = $receiver->receive($request, 'unknown-endpoint');

        $this->assertFalse($result['success']);
        $this->assertNull($result['id']);
        $this->assertStringContainsString('未配置', $result['message']);
    }

    public function testValidateOnlyWithUnknownEndpoint(): void
    {
        $receiver = new WebhookReceiver();
        $request  = $this->makeRequest('https://example.com/webhooks/test');

        $this->assertFalse($receiver->validateOnly($request, 'unknown-endpoint'));
    }

    public function testValidateOnlyWithKnownEndpointInvalidSignature(): void
    {
        $secret = 'test-secret';
        $this->setWebhookConfig([
            'client' => [
                'endpoints' => [
                    'stripe' => [
                        'signing_secret'    => $secret,
                        'signature_header'  => 'X-Signature',
                        'store_webhook_calls' => false,
                    ],
                ],
                'defaults' => [
                    'signature_validator' => DefaultSignatureValidator::class,
                    'signature_header'    => 'X-Signature',
                    'store_webhook_calls' => false,
                ],
            ],
        ]);

        $receiver = new WebhookReceiver();
        $request  = $this->makeRequest(
            'https://example.com/webhooks/stripe',
            'POST',
            [],
            ['X-Signature' => 'invalid'],
            '{"event":"test"}'
        );

        $this->assertFalse($receiver->validateOnly($request, 'stripe'));
    }

    public function testValidateOnlyWithKnownEndpointValidSignature(): void
    {
        $secret  = 'test-secret';
        $payload = '{"event":"test"}';
        $sig     = hash_hmac('sha256', $payload, $secret);

        $this->setWebhookConfig([
            'client' => [
                'endpoints' => [
                    'stripe' => [
                        'signing_secret'      => $secret,
                        'signature_header'    => 'X-Signature',
                        'store_webhook_calls' => false,
                    ],
                ],
                'defaults' => [
                    'signature_validator' => DefaultSignatureValidator::class,
                    'signature_header'    => 'X-Signature',
                    'store_webhook_calls' => false,
                ],
            ],
        ]);

        $receiver = new WebhookReceiver();
        $request  = $this->makeRequest(
            'https://example.com/webhooks/stripe',
            'POST',
            [],
            ['X-Signature' => $sig],
            $payload
        );

        $this->assertTrue($receiver->validateOnly($request, 'stripe'));
    }

    /**
     * 验证通过且关闭落库时，receive 不触发数据库写入，直接返回成功（id 为 null）。
     * 该路径用于在无 DB 的纯单元环境下覆盖编排主流程。
     */
    public function testReceiveSuccessWithoutStorage(): void
    {
        $secret  = 'test-secret';
        $payload = '{"event":"paid"}';
        $sig     = hash_hmac('sha256', $payload, $secret);

        $this->setWebhookConfig([
            'client' => [
                'endpoints' => [
                    'stripe' => [
                        'signing_secret'      => $secret,
                        'signature_header'    => 'X-Signature',
                        'store_webhook_calls' => false,
                        'processor_class'     => \wise\webhook\client\handler\LogOnlyProcessor::class,
                    ],
                ],
                'defaults' => [
                    'signature_validator' => DefaultSignatureValidator::class,
                    'signature_header'    => 'X-Signature',
                    'store_webhook_calls' => false,
                    'processor_class'     => \wise\webhook\client\handler\LogOnlyProcessor::class,
                    'queue'               => 'webhooks',
                ],
            ],
        ]);

        $receiver = new WebhookReceiver();
        $request  = $this->makeRequest(
            'https://example.com/webhooks/stripe',
            'POST',
            [],
            ['X-Signature' => $sig],
            $payload
        );

        $result = $receiver->receive($request, 'stripe');

        $this->assertTrue($result['success']);
        $this->assertNull($result['id']);
        $this->assertSame('Webhook received and queued', $result['message']);
    }

    /**
     * 签名失败且关闭落库时，receive 直接返回失败，不写入数据库。
     */
    public function testReceiveInvalidSignatureWithoutStorage(): void
    {
        $this->setWebhookConfig([
            'client' => [
                'endpoints' => [
                    'stripe' => [
                        'signing_secret'      => 'test-secret',
                        'signature_header'    => 'X-Signature',
                        'store_webhook_calls' => false,
                    ],
                ],
                'defaults' => [
                    'signature_validator' => DefaultSignatureValidator::class,
                    'signature_header'    => 'X-Signature',
                    'store_webhook_calls' => false,
                ],
            ],
        ]);

        $receiver = new WebhookReceiver();
        $request  = $this->makeRequest(
            'https://example.com/webhooks/stripe',
            'POST',
            [],
            ['X-Signature' => 'bad'],
            '{"event":"test"}'
        );

        $result = $receiver->receive($request, 'stripe');

        $this->assertFalse($result['success']);
        $this->assertNull($result['id']);
        $this->assertSame('签名验证失败', $result['message']);
    }

    public function testReceiveWithoutEndpointName(): void
    {
        $receiver = new WebhookReceiver();
        $request  = $this->makeRequest('https://example.com/webhooks');

        $result = $receiver->receive($request);

        $this->assertFalse($result['success']);
        $this->assertNull($result['id']);
        $this->assertStringContainsString('端点名', $result['message']);
    }
}

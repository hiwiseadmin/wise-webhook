<?php
declare(strict_types=1);

namespace wise\webhook\tests\Unit\Client;

use Closure;
use think\Request;
use wise\webhook\client\exception\InvalidSignatureException;
use wise\webhook\client\middleware\VerifyWebhookSignature;
use wise\webhook\client\validator\DefaultSignatureValidator;
use wise\webhook\tests\TestCase;

/**
 * VerifyWebhookSignature 中间件单元测试
 */
class VerifyWebhookSignatureTest extends TestCase
{
    private function next(): Closure
    {
        return function (Request $request) {
            return ['handled' => true, 'request' => $request];
        };
    }

    public function testPassThroughWhenEndpointUnresolvable(): void
    {
        $middleware = new VerifyWebhookSignature();
        $request    = $this->makeRequest('https://example.com/webhooks');

        $result = $middleware->handle($request, $this->next());

        $this->assertSame(['handled' => true, 'request' => $request], $result);
    }

    public function testPassThroughWhenEndpointNotConfigured(): void
    {
        $middleware = new VerifyWebhookSignature();
        $request    = $this->makeRequest('https://example.com/webhooks/unknown', 'POST', ['endpoint' => 'unknown']);

        $result = $middleware->handle($request, $this->next(), 'unknown');

        $this->assertTrue($result['handled']);
    }

    public function testThrowsOnInvalidSignature(): void
    {
        $this->setWebhookConfig([
            'client' => [
                'endpoints' => [
                    'stripe' => [
                        'signing_secret'   => 'test-secret',
                        'signature_header' => 'X-Signature',
                    ],
                ],
                'defaults' => [
                    'signature_validator' => DefaultSignatureValidator::class,
                    'signature_header'    => 'X-Signature',
                ],
            ],
        ]);

        $middleware = new VerifyWebhookSignature();
        $request    = $this->makeRequest(
            'https://example.com/webhooks/stripe',
            'POST',
            ['endpoint' => 'stripe'],
            ['X-Signature' => 'bad-signature'],
            '{"event":"test"}'
        );

        $this->expectException(InvalidSignatureException::class);
        $middleware->handle($request, $this->next(), 'stripe');
    }

    public function testPassThroughOnValidSignature(): void
    {
        $secret  = 'test-secret';
        $payload = '{"event":"paid"}';
        $sig     = hash_hmac('sha256', $payload, $secret);

        $this->setWebhookConfig([
            'client' => [
                'endpoints' => [
                    'stripe' => [
                        'signing_secret'   => $secret,
                        'signature_header' => 'X-Signature',
                    ],
                ],
                'defaults' => [
                    'signature_validator' => DefaultSignatureValidator::class,
                    'signature_header'    => 'X-Signature',
                ],
            ],
        ]);

        $middleware = new VerifyWebhookSignature();
        $request    = $this->makeRequest(
            'https://example.com/webhooks/stripe',
            'POST',
            ['endpoint' => 'stripe'],
            ['X-Signature' => $sig],
            $payload
        );

        $result = $middleware->handle($request, $this->next(), 'stripe');

        $this->assertTrue($result['handled']);
        // 中间件将端点名注入 request param
        $this->assertSame('stripe', $result['request']->param('_webhook_endpoint'));
    }

    public function testEndpointFromRouteParam(): void
    {
        $secret  = 'route-secret';
        $payload = '{"a":1}';
        $sig     = hash_hmac('sha256', $payload, $secret);

        $this->setWebhookConfig([
            'client' => [
                'endpoints' => [
                    'github' => [
                        'signing_secret'   => $secret,
                        'signature_header' => 'X-Signature',
                    ],
                ],
                'defaults' => [
                    'signature_validator' => DefaultSignatureValidator::class,
                    'signature_header'    => 'X-Signature',
                ],
            ],
        ]);

        $middleware = new VerifyWebhookSignature();
        // 不传中间件参数，仅从 route() 解析端点名
        $request = $this->makeRequest(
            'https://example.com/webhooks/github',
            'POST',
            ['endpoint' => 'github'],
            ['X-Signature' => $sig],
            $payload
        );

        $result = $middleware->handle($request, $this->next());

        $this->assertTrue($result['handled']);
    }
}

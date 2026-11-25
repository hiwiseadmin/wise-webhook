<?php
declare(strict_types=1);

namespace wise\webhook\tests\Unit\Client;

use wise\webhook\client\contract\SignatureValidatorInterface;
use wise\webhook\client\validator\DefaultSignatureValidator;
use wise\webhook\tests\TestCase;

/**
 * DefaultSignatureValidator 单元测试
 */
class DefaultSignatureValidatorTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $validator = new DefaultSignatureValidator();
        $this->assertInstanceOf(SignatureValidatorInterface::class, $validator);
    }

    public function testDirectSignature(): void
    {
        $secret  = 'test-secret';
        $payload = '{"event":"test"}';
        $sig     = hash_hmac('sha256', $payload, $secret);

        $request = $this->createMockRequest(
            $payload,
            ['X-Signature' => $sig]
        );

        $config = [
            'signing_secret'   => $secret,
            'signature_header' => 'X-Signature',
        ];

        $validator = new DefaultSignatureValidator();
        $this->assertTrue($validator->isValid($request, $config));
    }

    public function testDirectSignatureInvalid(): void
    {
        $secret  = 'test-secret';
        $payload = '{"event":"test"}';
        $wrongSig = 'invalid-signature';

        $request = $this->createMockRequest(
            $payload,
            ['X-Signature' => $wrongSig]
        );

        $config = [
            'signing_secret'   => $secret,
            'signature_header' => 'X-Signature',
        ];

        $validator = new DefaultSignatureValidator();
        $this->assertFalse($validator->isValid($request, $config));
    }

    public function testMissingSignatureHeader(): void
    {
        $secret  = 'test-secret';
        $payload = '{"event":"test"}';

        $request = $this->createMockRequest($payload, []);

        $config = [
            'signing_secret'   => $secret,
            'signature_header' => 'X-Signature',
        ];

        $validator = new DefaultSignatureValidator();
        $this->assertFalse($validator->isValid($request, $config));
    }

    public function testEmptySecret(): void
    {
        $payload = '{"event":"test"}';
        $sig     = hash_hmac('sha256', $payload, 'secret');

        $request = $this->createMockRequest(
            $payload,
            ['X-Signature' => $sig]
        );

        $config = [
            'signing_secret'   => '',
            'signature_header' => 'X-Signature',
        ];

        $validator = new DefaultSignatureValidator();
        $this->assertFalse($validator->isValid($request, $config));
    }

    public function testCustomHeaderName(): void
    {
        $secret  = 'test-secret';
        $payload = '{"event":"test"}';
        $sig     = hash_hmac('sha256', $payload, $secret);

        $request = $this->createMockRequest(
            $payload,
            ['Stripe-Signature' => $sig]
        );

        $config = [
            'signing_secret'   => $secret,
            'signature_header' => 'Stripe-Signature',
        ];

        $validator = new DefaultSignatureValidator();
        $this->assertTrue($validator->isValid($request, $config));
    }

    public function testStripeStyleSignature(): void
    {
        $secret    = 'whsec_test';
        $timestamp = time();
        $payload   = '{"type":"payment_intent.succeeded"}';
        $signed    = $timestamp . '.' . $payload;
        $sig       = hash_hmac('sha256', $signed, $secret);
        $header    = "t={$timestamp},v1={$sig}";

        $request = $this->createMockRequest(
            $payload,
            ['Stripe-Signature' => $header]
        );

        $config = [
            'signing_secret'    => $secret,
            'signature_header'  => 'Stripe-Signature',
            'tolerance_seconds' => 300,
        ];

        $validator = new DefaultSignatureValidator();
        $this->assertTrue($validator->isValid($request, $config));
    }

    public function testStripeStyleSignatureExpired(): void
    {
        $secret    = 'whsec_test';
        $timestamp = time() - 600;
        $payload   = '{"type":"payment_intent.succeeded"}';
        $signed    = $timestamp . '.' . $payload;
        $sig       = hash_hmac('sha256', $signed, $secret);
        $header    = "t={$timestamp},v1={$sig}";

        $request = $this->createMockRequest(
            $payload,
            ['Stripe-Signature' => $header]
        );

        $config = [
            'signing_secret'    => $secret,
            'signature_header'  => 'Stripe-Signature',
            'tolerance_seconds' => 300,
        ];

        $validator = new DefaultSignatureValidator();
        $this->assertFalse($validator->isValid($request, $config));
    }

    public function testGitHubStyleSignature(): void
    {
        $secret  = 'my-github-secret';
        $payload = '{"action":"opened","number":1}';
        $sig     = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        $request = $this->createMockRequest(
            $payload,
            ['X-Hub-Signature-256' => $sig]
        );

        $config = [
            'signing_secret'   => $secret,
            'signature_header' => 'X-Hub-Signature-256',
        ];

        $validator = new DefaultSignatureValidator();
        $this->assertTrue($validator->isValid($request, $config));
    }

    public function testGitHubStyleSignatureInvalid(): void
    {
        $secret  = 'my-github-secret';
        $payload = '{"action":"opened","number":1}';
        $sig     = 'sha256=wrongwrongwrong';

        $request = $this->createMockRequest(
            $payload,
            ['X-Hub-Signature-256' => $sig]
        );

        $config = [
            'signing_secret'   => $secret,
            'signature_header' => 'X-Hub-Signature-256',
        ];

        $validator = new DefaultSignatureValidator();
        $this->assertFalse($validator->isValid($request, $config));
    }

    /**
     * 创建模拟请求
     *
     * @param  string $jsonBody
     * @param  array  $headers
     * @return \think\Request
     */
    /**
     * 回归（Swoole 常驻）：原始请求体缓存必须按 Request 实例隔离
     *
     * 旧实现用类静态字符串缓存 body，常驻 worker 下第二条请求会沿用第一条的 body：
     * 只要带上前一条合法请求的签名，任意伪造 body 都能通过校验。
     */
    public function testRawBodyCacheIsolatedPerRequest(): void
    {
        $secret = 'shared-secret';
        $config = [
            'signing_secret'   => $secret,
            'signature_header' => 'X-Signature',
        ];
        $validator = new DefaultSignatureValidator();

        $bodyA = '{"event":"a","amount":1}';
        $sigA  = hash_hmac('sha256', $bodyA, $secret);

        $this->assertTrue($validator->isValid(
            $this->createMockRequest($bodyA, ['X-Signature' => $sigA]),
            $config
        ));

        $bodyB = '{"event":"b","amount":999999}';
        $this->assertFalse($validator->isValid(
            $this->createMockRequest($bodyB, ['X-Signature' => $sigA]),
            $config
        ));

        $this->assertTrue($validator->isValid(
            $this->createMockRequest($bodyB, ['X-Signature' => hash_hmac('sha256', $bodyB, $secret)]),
            $config
        ));
    }

    private function createMockRequest(string $jsonBody, array $headers): \think\Request
    {
        return $this->makeRequest('https://example.com/webhooks/test', 'POST', [], $headers, $jsonBody);
    }
}

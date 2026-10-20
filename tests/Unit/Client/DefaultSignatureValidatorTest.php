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

        // 构造模拟 request
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
        $timestamp = time() - 600; // 10 minutes ago, beyond 300s tolerance
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
    private function createMockRequest(string $jsonBody, array $headers): \think\Request
    {
        return $this->makeRequest('https://example.com/webhooks/test', 'POST', [], $headers, $jsonBody);
    }
}

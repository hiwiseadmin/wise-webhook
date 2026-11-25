<?php
declare(strict_types=1);

namespace wise\webhook\tests\Unit\Server;

use wise\webhook\server\signer\DefaultSigner;
use wise\webhook\server\signer\NoSigner;
use wise\webhook\server\contract\SignerInterface;
use wise\webhook\tests\TestCase;

/**
 * 签名器单元测试
 */
class SignerTest extends TestCase
{
    public function testDefaultSignerImplementsInterface(): void
    {
        $signer = new DefaultSigner();
        $this->assertInstanceOf(SignerInterface::class, $signer);
    }

    public function testDefaultSignerHmacSha256(): void
    {
        $signer   = new DefaultSigner();
        $payload  = '{"event":"test"}';
        $secret   = 'my-secret-key';
        $expected = hash_hmac('sha256', $payload, $secret);

        $this->assertSame($expected, $signer->calculateSignature($payload, $secret));
    }

    public function testDefaultSignerSamePayloadSameSignature(): void
    {
        $signer  = new DefaultSigner();
        $payload = '{"order_id":123}';
        $secret  = 'key-123';

        $sig1 = $signer->calculateSignature($payload, $secret);
        $sig2 = $signer->calculateSignature($payload, $secret);

        $this->assertSame($sig1, $sig2);
    }

    public function testDefaultSignerDifferentSecretDifferentSignature(): void
    {
        $signer  = new DefaultSigner();
        $payload = '{"test":true}';

        $sig1 = $signer->calculateSignature($payload, 'secret1');
        $sig2 = $signer->calculateSignature($payload, 'secret2');

        $this->assertNotSame($sig1, $sig2);
    }

    public function testDefaultSignerDifferentPayloadDifferentSignature(): void
    {
        $signer = new DefaultSigner();
        $secret = 'same-secret';

        $sig1 = $signer->calculateSignature('{"a":1}', $secret);
        $sig2 = $signer->calculateSignature('{"a":2}', $secret);

        $this->assertNotSame($sig1, $sig2);
    }

    public function testDefaultSignerDefaultHeaderName(): void
    {
        $signer = new DefaultSigner();
        $this->assertSame('X-Signature', $signer->signatureHeaderName());
    }

    public function testDefaultSignerCustomHeaderName(): void
    {
        $signer = new DefaultSigner('X-Custom-Sig');
        $this->assertSame('X-Custom-Sig', $signer->signatureHeaderName());
    }

    public function testNoSignerReturnsEmpty(): void
    {
        $signer = new NoSigner();
        $this->assertSame('', $signer->calculateSignature('anything', 'secret'));
        $this->assertSame('', $signer->signatureHeaderName());
    }

    public function testNoSignerImplementsInterface(): void
    {
        $signer = new NoSigner();
        $this->assertInstanceOf(SignerInterface::class, $signer);
    }

    public function testSignatureLength(): void
    {
        $signer  = new DefaultSigner();
        $signature = $signer->calculateSignature('data', 'key');

        $this->assertSame(64, strlen($signature));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);
    }
}

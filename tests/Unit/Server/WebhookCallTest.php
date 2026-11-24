<?php
declare(strict_types=1);

namespace wise\webhook\tests\Unit\Server;

use wise\webhook\server\WebhookCall;
use wise\webhook\server\WebhookServer;
use wise\support\backoff\BackoffStrategy;
use wise\webhook\server\signer\DefaultSigner;
use wise\webhook\server\signer\NoSigner;
use wise\webhook\tests\TestCase;

/**
 * WebhookCall 单元测试
 *
 * 测试流式构建器的链式调用和参数配置。
 */
class WebhookCallTest extends TestCase
{
    public function testCreateReturnsInstance(): void
    {
        $call = WebhookCall::create();
        $this->assertInstanceOf(WebhookCall::class, $call);
    }

    /**
     * 安全审计：队列模式无法传递字面密钥，必须 fail-fast
     *
     * 原实现 toArray() 不含 secret，异步任务只能从 config 重读 ——
     * useSecret() + queue 会静默发出**未签名**请求，接收方一律拒绝。
     */
    public function testDispatchToQueueRejectsLiteralSecret(): void
    {
        $this->expectException(\LogicException::class);

        WebhookCall::create()
            ->url('https://example.com/hooks')
            ->payload(['event' => 'test'])
            ->useSecret('plain-secret')
            ->dispatchToQueue();
    }

    public function testWebhookServerCreate(): void
    {
        $call = WebhookServer::create();
        $this->assertInstanceOf(WebhookCall::class, $call);
    }

    public function testUrl(): void
    {
        $call = WebhookCall::create()->url('https://example.com/webhooks');
        $this->assertSame('https://example.com/webhooks', $call->getUrl());
    }

    public function testFluentChaining(): void
    {
        $call = WebhookCall::create()
            ->url('https://example.com/hooks')
            ->payload(['order_id' => 123])
            ->useSecret('test-secret')
            ->method('PUT')
            ->timeout(30)
            ->connectTimeout(10)
            ->maxRetries(5)
            ->withHeaders(['X-Custom' => 'value'])
            ->meta(['trace_id' => 'abc']);

        $this->assertSame('https://example.com/hooks', $call->getUrl());
        $this->assertSame('test-secret', $call->getSecret());
    }

    public function testToArray(): void
    {
        $call = WebhookCall::create()
            ->url('https://example.com/webhooks')
            ->payload(['event' => 'test'])
            ->useSecret('secret123');

        $data = $call->toArray();

        $this->assertSame('https://example.com/webhooks', $data['url']);
        $this->assertSame(['event' => 'test'], $data['payload']);
        $this->assertArrayNotHasKey('secret', $data, 'secret 不进序列化（防密钥明文落队列存储）');
        $this->assertSame('POST', $data['method']);
    }

    public function testBackoffStrategySet(): void
    {
        $call = WebhookCall::create()
            ->backoffStrategy(BackoffStrategy::exponential(2000, 30000));

        $data = $call->toArray();
        $this->assertSame('exponential', $data['backoff']['method']);
    }

    public function testSignerSet(): void
    {
        $call = WebhookCall::create()
            ->signer(NoSigner::class);

        $data = $call->toArray();
        $this->assertSame(NoSigner::class, $data['signer_class']);
    }

    public function testHasCallbacksFalseByDefault(): void
    {
        $call = WebhookCall::create();
        $this->assertFalse($call->hasCallbacks());
    }

    public function testHasCallbacksAfterSetting(): void
    {
        $call = WebhookCall::create()
            ->onSending(function () {})
            ->onSent(function () {});

        $this->assertTrue($call->hasCallbacks());
    }

    public function testGetCallbacks(): void
    {
        $onSending = function (string $url, array $payload) {};
        $onSent = function (string $url, string $response, int $durationMs) {};
        $onFailed = function (string $url, string $error, int $attempt) {};

        $call = WebhookCall::create()
            ->onSending($onSending)
            ->onSent($onSent)
            ->onFailed($onFailed);

        $this->assertSame($onSending, $call->getOnSendingCallback());
        $this->assertSame($onSent, $call->getOnSentCallback());
        $this->assertSame($onFailed, $call->getOnFailedCallback());
    }

    public function testMethodUppercase(): void
    {
        $call = WebhookCall::create()->method('post');
        $data = $call->toArray();
        $this->assertSame('POST', $data['method']);
    }

    public function testVerifySsl(): void
    {
        $call = WebhookCall::create()->verifySsl(false);
        $data = $call->toArray();
        $this->assertFalse($data['verify_ssl']);

        $call2 = WebhookCall::create()->verifySsl(true);
        $data2 = $call2->toArray();
        $this->assertTrue($data2['verify_ssl']);
    }

    public function testDispatchIfTrue(): void
    {
        $onSendingCalled = false;
        $call = WebhookCall::create()
            ->url('https://example.com/never-called')
            ->payload(['test' => true])
            ->onSending(function () use (&$onSendingCalled) {
                $onSendingCalled = true;
            });
        $result = $call->dispatchIf(false);
        $this->assertNull($result);
    }

    public function testDispatchUnless(): void
    {
        $result = WebhookCall::create()
            ->url('https://example.com/never-called')
            ->payload(['test' => true])
            ->dispatchUnless(true);
        $this->assertNull($result);
    }
}

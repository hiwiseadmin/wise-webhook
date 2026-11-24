<?php
declare(strict_types=1);

namespace wise\webhook\tests\Unit\Server;

use wise\webhook\server\exception\WebhookSendException;
use wise\webhook\server\CallWebhookJob;
use wise\webhook\tests\TestCase;

/**
 * CallWebhookJob 单元测试
 *
 * 聚焦可离线验证的逻辑：SSRF 防护、退避延迟计算、URL 解析。
 * 真实 curl 发送与数据库持久化需要网络/DB，不在此覆盖。
 */
class CallWebhookJobTest extends TestCase
{
    public function testConstructorSetsAttemptFromData(): void
    {
        $job = new CallWebhookJob(['_attempt' => 3]);
        $this->assertSame(3, $this->getInaccessibleProperty($job, 'attemptCount'));
    }

    public function testSendRequestEmptyUrlThrows(): void
    {
        $job = new CallWebhookJob(['url' => '']);

        $this->expectException(WebhookSendException::class);
        $this->expectExceptionMessage('Webhook URL 未设置');
        $this->invokeInaccessible($job, 'sendRequest');
    }

    public function testSendRequestMissingHostThrows(): void
    {
        $job = new CallWebhookJob(['url' => 'http:///path-only']);

        $this->expectException(WebhookSendException::class);
        $this->expectExceptionMessage('Webhook URL 缺少主机名');
        $this->invokeInaccessible($job, 'sendRequest');
    }

    public function testSendRequestBlockedIpThrows(): void
    {
        $job = new CallWebhookJob(['url' => 'http://127.0.0.1/webhook']);

        $this->expectException(WebhookSendException::class);
        $this->expectExceptionMessage('不允许向内部地址发送 Webhook');
        $this->invokeInaccessible($job, 'sendRequest');
    }

    public function testSendRequestBlockedLocalhostThrows(): void
    {
        $job = new CallWebhookJob(['url' => 'http://localhost/webhook']);

        $this->expectException(WebhookSendException::class);
        $this->expectExceptionMessage('不允许向内部地址发送 Webhook');
        $this->invokeInaccessible($job, 'sendRequest');
    }

    public function testAssertOutboundUrlAllowedRejectsNonHttp(): void
    {
        $job = new CallWebhookJob([]);

        $this->expectException(WebhookSendException::class);
        $this->expectExceptionMessage('仅允许 http/https');
        $this->invokeInaccessible($job, 'assertOutboundUrlAllowed', ['file:///etc/passwd']);
    }

    public function testIsBlockedIp(): void
    {
        $job = new CallWebhookJob([]);

        $this->assertTrue($this->invokeInaccessible($job, 'isBlockedIp', ['127.0.0.1']));
        $this->assertTrue($this->invokeInaccessible($job, 'isBlockedIp', ['10.0.0.5']));
        $this->assertTrue($this->invokeInaccessible($job, 'isBlockedIp', ['192.168.1.5']));
        $this->assertTrue($this->invokeInaccessible($job, 'isBlockedIp', ['172.16.5.5']));
        $this->assertTrue($this->invokeInaccessible($job, 'isBlockedIp', ['::1']));
        $this->assertTrue($this->invokeInaccessible($job, 'isBlockedIp', ['fe80::1']));
        $this->assertFalse($this->invokeInaccessible($job, 'isBlockedIp', ['8.8.8.8']));
        $this->assertFalse($this->invokeInaccessible($job, 'isBlockedIp', ['203.0.113.5']));
        $this->assertFalse($this->invokeInaccessible($job, 'isBlockedIp', ['2001:4860:4860::8888']));
    }

    public function testIpInCidr(): void
    {
        $job = new CallWebhookJob([]);

        $this->assertTrue($this->invokeInaccessible($job, 'ipInCidr', ['192.168.1.5', '192.168.1.0/24']));
        $this->assertTrue($this->invokeInaccessible($job, 'ipInCidr', ['10.5.5.5', '10.0.0.0/8']));
        $this->assertTrue($this->invokeInaccessible($job, 'ipInCidr', ['172.16.3.4', '172.16.0.0/12']));
        $this->assertFalse($this->invokeInaccessible($job, 'ipInCidr', ['8.8.8.8', '192.168.0.0/16']));
        $this->assertTrue($this->invokeInaccessible($job, 'ipInCidr', ['::1', '::1/128']));
    }

    public function testResolveRelativeUrl(): void
    {
        $job = new CallWebhookJob([]);

        $this->assertSame(
            'https://example.com/foo/bar',
            $this->invokeInaccessible($job, 'resolveRelativeUrl', ['https://example.com/foo/a', 'bar'])
        );
        $this->assertSame(
            'https://example.com/baz',
            $this->invokeInaccessible($job, 'resolveRelativeUrl', ['https://example.com/foo', '/baz'])
        );
        $this->assertSame(
            'https://example.com/foo?x=1',
            $this->invokeInaccessible($job, 'resolveRelativeUrl', ['https://example.com/foo', '?x=1'])
        );
    }

    public function testCalculateBackoffDelay(): void
    {
        $job = new CallWebhookJob([]);

        $this->assertSame(
            2000,
            $this->invokeInaccessible($job, 'calculateBackoffDelay', [['method' => 'linear', 'base_delay_ms' => 1000], 2])
        );
        $this->assertSame(
            8000,
            $this->invokeInaccessible(
                $job,
                'calculateBackoffDelay',
                [['method' => 'exponential', 'base_delay_ms' => 1000, 'max_delay_ms' => 60000], 4]
            )
        );
    }
}

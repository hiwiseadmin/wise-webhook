<?php
declare(strict_types=1);

namespace wise\webhook\tests\Unit\Server;

use wise\webhook\server\event\WebhookSending;
use wise\webhook\server\event\WebhookSent;
use wise\webhook\server\event\WebhookFailed;
use wise\webhook\tests\TestCase;

/**
 * Server 事件单元测试
 */
class EventTest extends TestCase
{
    public function testWebhookSendingEvent(): void
    {
        $event = new WebhookSending(
            'https://example.com/webhooks',
            ['order_id' => 123],
            ['X-Custom' => 'test'],
            ['trace' => 'abc'],
            2
        );

        $this->assertSame('https://example.com/webhooks', $event->url);
        $this->assertSame(['order_id' => 123], $event->payload);
        $this->assertSame(['X-Custom' => 'test'], $event->headers);
        $this->assertSame(['trace' => 'abc'], $event->meta);
        $this->assertSame(2, $event->attempt);
    }

    public function testWebhookSendingEventDefaults(): void
    {
        $event = new WebhookSending('https://example.com/hooks', []);

        $this->assertSame([], $event->payload);
        $this->assertSame([], $event->headers);
        $this->assertSame([], $event->meta);
        $this->assertSame(0, $event->attempt);
    }

    public function testWebhookSentEvent(): void
    {
        $event = new WebhookSent(
            'https://example.com/webhooks',
            ['order_id' => 123],
            '{"status":"ok"}',
            200,
            150,
            ['trace' => 'abc'],
            1
        );

        $this->assertSame('https://example.com/webhooks', $event->url);
        $this->assertSame(['order_id' => 123], $event->payload);
        $this->assertSame('{"status":"ok"}', $event->response);
        $this->assertSame(200, $event->responseCode);
        $this->assertSame(150, $event->durationMs);
        $this->assertSame(['trace' => 'abc'], $event->meta);
        $this->assertSame(1, $event->attempt);
    }

    public function testWebhookFailedEventWithRetry(): void
    {
        $event = new WebhookFailed(
            'https://example.com/webhooks',
            ['test' => true],
            'Connection timed out',
            0,
            1,
            3,
            true,
            ['trace' => 'xyz']
        );

        $this->assertSame('https://example.com/webhooks', $event->url);
        $this->assertSame(['test' => true], $event->payload);
        $this->assertSame('Connection timed out', $event->error);
        $this->assertSame(0, $event->responseCode);
        $this->assertSame(1, $event->attempt);
        $this->assertSame(3, $event->maxAttempts);
        $this->assertTrue($event->willRetry);
    }

    public function testWebhookFailedEventNoRetry(): void
    {
        $event = new WebhookFailed(
            'https://example.com/webhooks',
            [],
            'Max retries exceeded',
            500,
            3,
            3,
            false
        );

        $this->assertSame(3, $event->attempt);
        $this->assertSame(3, $event->maxAttempts);
        $this->assertFalse($event->willRetry);
    }
}

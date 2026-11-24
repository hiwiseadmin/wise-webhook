<?php
declare(strict_types=1);

namespace wise\webhook\tests\Unit\Client;

use think\Request;
use wise\webhook\client\event\WebhookReceived;
use wise\webhook\client\event\WebhookAccepted;
use wise\webhook\client\event\WebhookRejected;
use wise\webhook\client\event\WebhookProcessed;
use wise\webhook\client\model\WebhookReceiveModel;
use wise\webhook\tests\TestCase;

/**
 * Client 事件单元测试
 */
class ClientEventTest extends TestCase
{
    public function testWebhookReceivedEvent(): void
    {
        $model   = new WebhookReceiveModel();
        $request = $this->makeRequest('https://example.com/webhooks/stripe');

        $event = new WebhookReceived($model, $request, 'stripe');

        $this->assertSame($model, $event->webhookCall);
        $this->assertSame($request, $event->request);
        $this->assertSame('stripe', $event->endpointName);
    }

    public function testWebhookAcceptedEvent(): void
    {
        $request = $this->makeRequest('https://example.com/webhooks/stripe');

        $event = new WebhookAccepted('stripe', $request);

        $this->assertSame('stripe', $event->endpointName);
        $this->assertSame($request, $event->request);
    }

    public function testWebhookRejectedEvent(): void
    {
        $request = $this->makeRequest('https://example.com/webhooks/stripe');

        $event = new WebhookRejected('stripe', $request, '签名验证失败');

        $this->assertSame('stripe', $event->endpointName);
        $this->assertSame($request, $event->request);
        $this->assertSame('签名验证失败', $event->reason);
    }

    public function testWebhookRejectedEventDefaultReason(): void
    {
        $request = $this->makeRequest('https://example.com/webhooks/test');

        $event = new WebhookRejected('test', $request);

        $this->assertSame('', $event->reason);
    }

    public function testWebhookProcessedEvent(): void
    {
        $model = new WebhookReceiveModel();
        $result = ['order_processed' => true];

        $event = new WebhookProcessed($model, 'stripe', $result);

        $this->assertSame($model, $event->webhookCall);
        $this->assertSame('stripe', $event->endpointName);
        $this->assertSame(['order_processed' => true], $event->result);
    }

    public function testWebhookProcessedEventNullResult(): void
    {
        $model = new WebhookReceiveModel();

        $event = new WebhookProcessed($model, 'github');

        $this->assertNull($event->result);
    }
}

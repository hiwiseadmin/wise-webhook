<?php
declare(strict_types=1);

namespace wise\webhook\tests\Unit\Server;

use wise\webhook\client\WebhookReceiver;
use wise\webhook\facade\Webhook;
use wise\webhook\server\WebhookCall;
use wise\webhook\WebhookManager;
use wise\webhook\tests\TestCase;

/**
 * WebhookManager 与 Webhook Facade 单元测试
 */
class WebhookManagerTest extends TestCase
{
    public function testManagerInstantiation(): void
    {
        $manager = new WebhookManager();
        $this->assertInstanceOf(WebhookManager::class, $manager);
    }

    public function testServerCreate(): void
    {
        $manager = new WebhookManager();
        $this->assertInstanceOf(WebhookCall::class, $manager->serverCreate());
    }

    public function testClientReceiver(): void
    {
        $manager = new WebhookManager();
        $this->assertInstanceOf(WebhookReceiver::class, $manager->clientReceiver());
    }

    public function testFacadeServerCreate(): void
    {
        $call = Webhook::serverCreate();
        $this->assertInstanceOf(WebhookCall::class, $call);
    }

    public function testFacadeClientReceiver(): void
    {
        $receiver = Webhook::clientReceiver();
        $this->assertInstanceOf(WebhookReceiver::class, $receiver);
    }
}

<?php
declare(strict_types=1);

namespace wise\webhook\tests\Unit\Server;

use Mockery;
use think\App;
use wise\webhook\WebhookManager;
use wise\webhook\WebhookServiceProvider;
use wise\webhook\tests\TestCase;

/**
 * WebhookServiceProvider 单元测试
 */
class WebhookServiceProviderTest extends TestCase
{
    public function testEnvSeeds(): void
    {
        $seeds = WebhookServiceProvider::envSeeds();

        $this->assertArrayHasKey('GITHUB_WEBHOOK_SECRET', $seeds);
        $this->assertArrayHasKey('STRIPE_WEBHOOK_SECRET', $seeds);
        $this->assertSame('', $seeds['GITHUB_WEBHOOK_SECRET']['default']);
        $this->assertSame('external', $seeds['GITHUB_WEBHOOK_SECRET']['type']);
    }

    public function testRegisterBindsManager(): void
    {
        $app = Mockery::mock(App::class);
        $app->shouldReceive('bind')
            ->once()
            ->with(WebhookManager::class, WebhookManager::class);

        $provider = new WebhookServiceProvider($app);
        $provider->register();

        $this->expectNotToPerformAssertions();
    }

    public function testRegisterMergesConfig(): void
    {
        $app      = app();
        $provider = new WebhookServiceProvider($app);

        $provider->register();

        $this->assertIsArray(config('wise-webhook.server', []));
    }
}

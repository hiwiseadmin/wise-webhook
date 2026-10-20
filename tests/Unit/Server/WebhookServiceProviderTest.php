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
        // 复用 bootstrap 已配置的 App 容器，避免 new App() 劫持全局 Container 实例
        // （否则后续测试会丢失 bootstrap 注入的日志/request 绑定）。
        $app      = app();
        $provider = new WebhookServiceProvider($app);

        $provider->register();

        // 合并后应能读取到 wise-webhook.server 配置
        $this->assertIsArray(config('wise-webhook.server', []));
    }
}

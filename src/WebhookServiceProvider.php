<?php
declare(strict_types=1);

namespace wise\webhook;

use wise\support\contracts\EnvSeedProvider;

use wise\support\traits\RegistersMigrations;
use wise\support\traits\ServiceTrait;

/**
 * WiseAdmin Webhook — ThinkPHP Service 提供者
 *
 * 注册配置、命令和核心服务到容器。
 * 使用 DeferredService 延迟加载，仅在首次使用时初始化。
 */
class WebhookServiceProvider extends \think\Service implements EnvSeedProvider
{

    use \wise\support\traits\RegistersMigrations;
    use ServiceTrait;

    /**
     * 提供的服务映射
     */
    /**
     * 注册服务（延迟加载，首次 make() 时执行一次）
     */
    /**
     * 声明需要播种到 .env 的入站 Webhook 签名密钥
     *
     * GitHub/Stripe 等平台的 Webhook 签名密钥，由用户在对应平台获取后填写，
     * 留空占位，绝不在安装时生成假值。
     */
    public static function envSeeds(): array
    {
        return [
            'GITHUB_WEBHOOK_SECRET' => [
                'type'    => 'external',
                'default' => '',
                'comment' => 'GitHub Webhook 签名密钥',
            ],
            'STRIPE_WEBHOOK_SECRET' => [
                'type'    => 'external',
                'default' => '',
                'comment' => 'Stripe Webhook 签名密钥',
            ],
        ];
    }

    public function register(): void
    {
        $this->mergeConfig('wise-webhook');

        $this->app->bind(WebhookManager::class, WebhookManager::class);
    }

    /**
     * 启动服务（每个请求执行）
     */
    public function boot(): void
    {
        $this->registerMigrations();
    }
}

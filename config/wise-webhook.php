<?php
declare(strict_types=1);

return [
    // ========== Server 侧：发送 Webhook ==========
    'server' => [
        // 默认发送模式：sync（同步）| queue（队列）
        'dispatch' => [
            'default_mode' => 'sync',
        ],

        // 默认签名器
        'signer' => \wise\webhook\server\signer\DefaultSigner::class,

        // 全局签名密钥（异步 / 队列模式从这里重读，因为 secret 不进队列序列化）
        // 留空 = 不签名；同步模式仍可用 useSecret() 按次覆盖。
        'secret' => env('WEBHOOK_SIGNING_SECRET', ''),

        // 默认队列名称
        'queue' => 'webhooks',

        // 默认重试配置
        'retry' => [
            'max_attempts'     => 3,
            'backoff_method'   => 'linear',   // linear | exponential | fixed | custom
            'backoff_base_ms'  => 1000,        // 基础延迟（毫秒）
            'backoff_max_ms'   => 60000,       // 最大延迟（仅 exponential）
        ],

        // 默认 HTTP 配置
        'http' => [
            'timeout'          => 15,
            'connect_timeout'  => 5,
            'method'           => 'POST',
            'verify_ssl'       => true,
        ],

        // 默认请求头（自动添加）
        'headers' => [
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent'   => 'WiseAdmin-Webhook/1.0',
        ],

        // 签名头名称
        'signature_header' => 'X-Signature',

        // 全局闭包回调（可被单次调用的 onSending/onSent/onFailed 覆盖）
        'callbacks' => [
            'on_sending' => null,  // ?callable fn(string $url, array $payload): void
            'on_sent'    => null,  // ?callable fn(string $url, string $response, int $durationMs): void
            'on_failed'  => null,  // ?callable fn(string $url, string $error, int $attempt): void
        ],
    ],

    // ========== Client 侧：接收 Webhook ==========
    'client' => [
        // 多端点配置
        'endpoints' => [
            // 示例：Stripe
            // 'stripe' => [
            //     'signing_secret'      => env('STRIPE_WEBHOOK_SECRET'),
            //     'signature_header'    => 'Stripe-Signature',
            //     'signature_validator' => \wise\webhook\client\validator\DefaultSignatureValidator::class,
            //     'processor_class' => \App\webhook\jobs\HandleStripeWebhook::class,
            // ],
            //
            // 示例：GitHub
            // 'github' => [
            //     'signing_secret'      => env('GITHUB_WEBHOOK_SECRET'),
            //     'signature_header'    => 'X-Hub-Signature-256',
            //     'signature_validator' => null,  // null = 使用默认 HMAC 验证
            //     'processor_class' => \App\webhook\jobs\HandleGitHubWebhook::class,
            // ],
        ],

        // 默认值
        'defaults' => [
            'signature_validator'   => \wise\webhook\client\validator\DefaultSignatureValidator::class,
            'processor_class'   => \wise\webhook\client\handler\LogOnlyProcessor::class,
            'signature_header'      => 'X-Signature',
            'store_webhook_calls'   => true,
            'queue'                 => 'webhooks',
            'timeout'               => 30,
            'tolerance_seconds'     => 300,  // 时间戳容差（防重放攻击）
        ],
    ],

    // ================================================================
    // 目标 host 级断路器（Circuit Breaker，per-host 独立熔断）
    // ================================================================
    'circuit' => [
        'failure_threshold' => 5,
        'timeout'           => 120,
        'key_prefix'        => 'wise_webhook:cb',
        // 熔断状态所用缓存驱动；null = 框架默认驱动（cache.default）。
        // 多机部署建议显式设为 'redis'；单机默认 file 即可，避免因缺少 Redis 连接
        // 让熔断器自身成为故障点。
        'store'             => null,
    ],
];

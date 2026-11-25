# Wise-Webhook

> 适用：WiseAdmin 1.0+ / PHP 8.1+ / ThinkPHP 6/8
> 状态：稳定
> 可独立使用：是 —— 发送/接收与队列能力可单独用；迁移与 Redis 断路器按需启用
> 直接依赖：无

> 基于 ThinkPHP 6/8 的 Webhook 发送与接收双面解决方案，深度集成 WiseAdmin 生态
> 发送侧（Server）提供流式构建器 + HMAC 签名 + 可配置重试/退避 + 按 host 断路器 + 队列发送；
> 接收侧（Client）提供多端点签名验证 + 防重放 + 防时序攻击中间件 + 队列异步处理。
> 版本：v1.0 | PHP >= 7.4 | ThinkPHP ^6.0 || ^8.0

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-blue)](https://www.php.net)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E6.0%20%7C%7C%20%5E8.0-green)](https://www.thinkphp.cn)
[![License](https://img.shields.io/badge/license-Apache%202.0-brightgreen)](LICENSE)
[![Packagist](https://img.shields.io/badge/packagist-wiseadmin%2Fwise--webhook-orange)](https://packagist.org/packages/wiseadmin/wise-webhook)

---

## 目录

- [概述](#概述)
- [核心特性](#核心特性)
- [架构设计](#架构设计)
- [安装与配置](#安装与配置)
- [快速上手](#快速上手)
  - [Server 侧：发送 Webhook](#server-侧发送-webhook)
  - [Client 侧：接收 Webhook](#client-侧接收-webhook)
  - [自定义签名器](#自定义签名器)
  - [自定义签名验证器](#自定义签名验证器)
  - [自定义业务处理器](#自定义业务处理器)
  - [组合 Facade](#组合-facade)
  - [事件监听](#事件监听)
- [API 参考](#api-参考)
  - [WebhookCall 发送构建器](#webhookcall-发送构建器)
  - [WebhookServer 静态入口](#webhookserver-静态入口)
  - [WebhookReceiver 接收编排器](#webhookreceiver-接收编排器)
  - [WebhookClient 静态入口](#webhookclient-静态入口)
  - [模型方法](#模型方法)
  - [接口契约](#接口契约)
  - [BackoffStrategy 退避策略](#backoffstrategy-退避策略)
- [配置参考](#配置参考)
- [事件列表](#事件列表)
- [错误码](#错误码)
- [数据库表结构](#数据库表结构)
- [CLI 命令](#cli-命令)
- [与其他 wise-* 包协作](#与其他-wise-包协作)
- [目录结构](#目录结构)
- [常见问题](#常见问题)
- [依赖关系](#依赖关系)
- [许可证](#许可证)

---

## 概述

**Wise-Webhook** 是 WiseAdmin 生态的 Webhook 组件，同时解决**对外发送**与**对内接收**两个方向的问题：

- **Server 侧（发送）**：以流式构建器的方式组装一次 HTTP 回调，支持 HMAC-SHA256 签名、四种退避重试策略、按目标 host 的断路器熔断、同步/队列双模式发送，并自动将每次发送记录到数据库。
- **Client 侧（接收）**：为第三方平台（Stripe、GitHub 等）的回调提供签名验证中间件、接收编排器与队列异步处理器，支持多端点独立密钥、三种主流签名格式、防重放与防时序攻击，并自动落库记录。

包设计对标社区成熟的 `spatie/laravel-webhook-client` / `spatie/laravel-webhook-server` 思路，但在 WiseAdmin 中进一步补齐了**数据库持久化、队列重试退避、断路器熔断、双面统一门面**等工程化能力。

### 设计理念

| 设计原则 | 说明 |
|---------|------|
| **双面统一** | 一个包同时提供发送（Server）与接收（Client）能力，并通过 `WebhookManager` / Facade 统一入口 |
| **可扩展** | 签名器、签名验证器、业务处理器均为接口契约，可自由替换 |
| **工程化** | 数据库持久化、队列重试退避、per-host 断路器、SSRF 防护内置开箱即用 |
| **安全优先** | `hash_equals` 防时序攻击、时间戳容差防重放、内部地址拦截防 SSRF |

---

## 核心特性

### Server 侧（发送）

- 流式构建器 API：`WebhookCall::create()->url()->payload()->useSecret()->dispatch()`（另有 `WebhookServer` 静态入口）
- **HMAC-SHA256 签名**：`DefaultSigner` 默认签名器，可替换为自定义 `SignerInterface`
- **四种退避策略**：`linear`（线性）/ `exponential`（指数）/ `fixed`（固定）/ `custom`（自定义序列），源自 `wise-support` 的 `BackoffStrategy`
- **双模式发送**：同步 `dispatchSync()` / 队列 `dispatchToQueue()`，全局可通过配置切换默认模式
- **条件发送**：`dispatchIf()` / `dispatchUnless()` 满足业务条件才发送
- **闭包回调**：`onSending` / `onSent` / `onFailed` 细粒度钩子（仅同步模式可用）
- **事件系统**：`WebhookSending` / `WebhookSent` / `WebhookFailed`
- **per-host 断路器**：连续失败超过阈值自动熔断，冷却后半开探测恢复（状态存 Redis）
- **SSRF 防护**：禁止向回环 / 私有网段 / 链路本地 / 云元数据地址发送
- 零第三方依赖，纯 curl 实现，自带超时 / 连接超时 / SSL 验证控制

### Client 侧（接收）

- **多端点**：stripe / github / 自定义端点，各自独立 `signing_secret`、`signature_header`、验证器与处理器
- **签名验证中间件**：`VerifyWebhookSignature` 在请求到达控制器前拦截非法请求
- **三种签名格式**：纯 hex / Stripe `t=,v1=` / GitHub `sha256=` 自动识别
- **防时序攻击**：`hash_equals` 安全比对
- **防重放攻击**：Stripe 格式支持时间戳容差检查（`tolerance_seconds`）
- **WebhookReceiver 编排器**：接收 → 二次签名验证 → 存储 → 推队列 → 返回
- **自定义 Processor**：实现 `WebhookProcessorInterface` 处理业务逻辑（默认 `LogOnlyProcessor`）
- **完整事件**：`WebhookReceived` / `WebhookAccepted` / `WebhookRejected` / `WebhookProcessed`
- 敏感请求头过滤（cookie / set-cookie / authorization）后再落库

---

## 架构设计

### 双面分层

```
┌─────────────────────────────────────────────────────────────┐
│                      Facade 统一入口                        │
│      wise\webhook\facade\Webhook  ──►  WebhookManager       │
│        serverCreate()  │  clientReceiver()                 │
└───────────┬──────────────────────────┬──────────────────────┘
            │                          │
   ┌────────▼─────────┐      ┌─────────▼─────────┐
   │ Server 发送侧      │      │ Client 接收侧       │
   │ WebhookServer     │      │ WebhookClient      │
   │ WebhookCall       │      │ WebhookReceiver    │
   │ CallWebhookJob    │      │ VerifyWebhookSignature│
   │  SignerInterface  │      │ ProcessWebhookJob  │
   │  DefaultSigner    │      │  SignatureValidator│
   │  NoSigner         │      │  WebhookProcessor  │
   │  WebhookSendModel │      │  WebhookReceiveModel│
   └────────┬─────────┘      └─────────┬─────────┘
            │                          │
   ┌────────▼──────────────────────────▼─────────┐
   │         wise-support 共享能力                │
   │  BackoffStrategy（退避）  CircuitBreaker（熔断）│
   │  WiseException（异常基类） ContextualLogger     │
   └──────────────────────────────────────────────┘
```

### 发送请求处理流程（Server）

```
WebhookCall::create()
  → url() / payload() / useSecret() / maxRetries() / backoffStrategy() ...
  → dispatch() ──根据 default_mode──► sync / queue
        │
        ├─ queue 模式：Queue::push(CallWebhookJob, toArray())
        │              fire() → sendRequest() → 持久化 → 事件 → 失败退避重试
        │
        └─ sync 模式：CallWebhookJob->send()
                       → onSending 回调
                       → WebhookSending 事件
                       → sendRequest()（签名 + SSRF 检查 + 断路器 guard + curl）
                       → persistSuccess / fireSentEvent / onSent 回调
                       → 失败：persistFailure / fireFailedEvent / onFailed 回调 / 抛异常
```

### 接收请求处理流程（Client）

```
请求到达 → Route::post('webhooks/:endpoint')
  → VerifyWebhookSignature 中间件（可选）
      → 识别端点名 → 签名验证（DefaultSignatureValidator）
      → 通过：WebhookAccepted 事件，注入 _webhook_endpoint
      → 失败：WebhookRejected 事件，抛 InvalidSignatureException(403)
  → 控制器调用 WebhookReceiver::receive($request, $endpoint)
      → 端点配置解析（合并 defaults）
      → 二次签名验证（双保险）
      → 存储 WebhookReceiveModel（status=pending）
      → WebhookReceived 事件
      → Queue::push(ProcessWebhookJob)
  → 返回 json(['success'=>true, 'id'=>..., 'message'=>...])
  → ProcessWebhookJob->fire()
      → 加载模型 → markProcessing()
      → new Processor()->handle($model, $request)
      → markProcessed() → WebhookProcessed 事件
      → 失败：markFailed + 指数退避（5s~1h）延迟重试，超出 max_attempts 丢弃
```

---

## 安装与配置

### 前置条件

- PHP >= 7.4
- ThinkPHP ^6.0 || ^8.0
- `ext-json`、`ext-curl` 扩展
- [可选] `topthink/think-queue`（异步发送 / 接收处理，建议安装）
- [可选] `topthink/think-migration`（数据库迁移建表）
- [可选] Redis（per-host 断路器状态存储依赖 `Cache::store('redis')`）

### 通过 Composer 安装

```bash
composer require wiseadmin/wise-webhook
```

### 发布配置与创建数据表

`WebhookServiceProvider::boot()` 通过 `RegistersMigrations` trait 在 CLI 模式下自动注册迁移目录（默认 `{包根}/database/migrations`），因此：

```bash
php think migrate:run
```

### 环境变量种子

`WebhookServiceProvider` 实现了 `EnvSeedProvider`，会向 `.env` 声明两个**外部平台**的入站签名密钥占位（绝不在安装时生成假值，由用户在 GitHub / Stripe 平台获取后填写）：

| 变量 | 类型 | 说明 |
|------|------|------|
| `GITHUB_WEBHOOK_SECRET` | external | GitHub Webhook 签名密钥 |
| `STRIPE_WEBHOOK_SECRET` | external | Stripe Webhook 签名密钥 |

---

## 5 分钟最小示例

> 前置：发送/接收记录需要迁移；队列发送需 `php think queue:work --queue=webhooks`；接收侧需在 `.env` 配置 GitHub/Stripe 签名密钥。

```bash
php think migrate:run
```

```php
use wise\webhook\server\WebhookServer;

$result = WebhookServer::create()
    ->url('https://hooks.slack.com/services/T000/B000/XXXX')
    ->payload(['text' => 'Order #123 paid!'])
    ->useSecret('my-signing-secret')
    ->dispatch();

print_r($result);
```

## 快速上手

### Server 侧：发送 Webhook

#### 1. 同步发送

```php
use wise\webhook\server\WebhookServer;
use think\facade\Log;

$result = WebhookServer::create()
    ->url('https://hooks.slack.com/services/T000/B000/XXXX')
    ->payload(['text' => 'Order #123 paid!'])
    ->useSecret('my-signing-secret')
    ->onSending(function (string $url, array $payload) {
        Log::info("Preparing to send to {$url}");
    })
    ->onSent(function (string $url, string $response, int $durationMs) {
        Log::info("Webhook sent to {$url} in {$durationMs}ms");
    })
    ->onFailed(function (string $url, string $error, int $attempt) {
        Log::error("Webhook failed to {$url} (attempt {$attempt}): {$error}");
    })
    ->dispatch();
```

`dispatch()` 返回结构：

```php
[
    'success'     => true,
    'response'    => 'ok',
    'code'        => 200,
    'duration_ms' => 128,
]
```

#### 2. 队列异步发送（推荐）

```php
use wise\webhook\server\WebhookServer;
use wise\support\backoff\BackoffStrategy;

WebhookServer::create()
    ->url('https://example.com/webhooks')
    ->payload(['order_id' => 123, 'status' => 'paid'])
    ->maxRetries(5)
    ->backoffStrategy(BackoffStrategy::exponential(1000, 60000))
    ->queue('webhooks')          // 指定队列名
    ->dispatchToQueue();         // 推入队列，立即返回 void
```

> 注意：`onSending` / `onSent` / `onFailed` 闭包无法序列化，仅**同步模式**可用；队列模式通过**事件**（`WebhookSent` / `WebhookFailed`）传递结果。

#### 3. 自定义 HTTP 行为

```php
$result = WebhookServer::create()
    ->url('https://api.example.com/hooks')
    ->payload(['event' => 'user.created'])
    ->method('PUT')
    ->timeout(30)            // 超时 30s
    ->connectTimeout(5)      // 连接超时 5s
    ->withHeaders(['Authorization' => 'Bearer xxx'])
    ->meta(['tenant_id' => 42])   // 追踪元数据，随记录落库
    ->verifySsl(false)       // 本地调试时关闭 SSL 校验
    ->dispatch();
```

#### 4. 条件发送

```php
// 订单金额达到阈值才通知财务系统
WebhookServer::create()
    ->url('https://finance.example.com/webhook')
    ->payload(['order_id' => $order->id, 'amount' => $order->amount])
    ->dispatchIf($order->amount >= 10000);

// 非测试环境才发送
WebhookServer::create()
    ->url('https://log.example.com/webhook')
    ->payload(['msg' => 'daily report'])
    ->dispatchUnless(app()->isDebug());
```

### Client 侧：接收 Webhook

#### 1. 配置端点 `config/wise-webhook.php`

```php
'client' => [
    'endpoints' => [
        'stripe' => [
            'signing_secret'   => env('STRIPE_WEBHOOK_SECRET'),
            'signature_header' => 'Stripe-Signature',
            'processor_class'  => \app\webhook\HandleStripeWebhook::class,
        ],
        'github' => [
            'signing_secret'   => env('GITHUB_WEBHOOK_SECRET'),
            'signature_header' => 'X-Hub-Signature-256',
            'processor_class'  => \app\webhook\HandleGitHubWebhook::class,
        ],
    ],
],
```

#### 2. 定义路由

```php
use wise\webhook\client\middleware\VerifyWebhookSignature;

// 通用端点：URL 段自动提取端点名
Route::post('webhooks/:endpoint', 'webhook/Webhook@receive')
    ->middleware(VerifyWebhookSignature::class);

// 固定端点：中间件参数显式指定端点名
Route::post('webhooks/stripe', 'webhook/Webhook@stripe')
    ->middleware(VerifyWebhookSignature::class . ':stripe');
```

#### 3. 编写控制器

```php
use wise\webhook\client\WebhookClient;
use think\Request;

class Webhook extends BaseController
{
    public function receive(Request $request, string $endpoint = null)
    {
        $receiver = WebhookClient::receiver();
        $result = $receiver->receive($request, $endpoint);
        return json($result);
    }
}
```

#### 4. 编写业务处理器

```php
use wise\webhook\client\contract\WebhookProcessorInterface;
use wise\webhook\client\model\WebhookReceiveModel;
use think\Request;
use think\facade\Log;

class HandleStripeWebhook implements WebhookProcessorInterface
{
    public function handle(WebhookReceiveModel $webhookCall, Request $request): void
    {
        $type = $webhookCall->payload['type'] ?? '';

        match ($type) {
            'payment_intent.succeeded' => $this->handlePaymentSucceeded($webhookCall),
            'charge.refunded'          => $this->handleRefunded($webhookCall),
            default                    => Log::info("Unhandled Stripe event: {$type}"),
        };
    }

    protected function handlePaymentSucceeded(WebhookReceiveModel $webhookCall): void
    {
        // 执行业务逻辑，例如更新订单为已支付
        $data = $webhookCall->payload['data']['object'] ?? [];
        Log::info('Payment succeeded', $data);
    }

    protected function handleRefunded(WebhookReceiveModel $webhookCall): void
    {
        // ...
    }
}
```

> 说明：`WebhookProcessorInterface::handle()` 收到的 `$webhookCall` 是从数据库加载的 `WebhookReceiveModel`，包含 `payload` / `headers` / `signature` / `source_ip` 等完整信息，无需再访问原始网络请求。

### 自定义签名器（Server 发送）

实现 `SignerInterface`，替换默认 HMAC-SHA256 签名方式：

```php
use wise\webhook\server\contract\SignerInterface;

class MySigner implements SignerInterface
{
    public function calculateSignature(string $payload, string $secret): string
    {
        return hash_hmac('sha512', $payload, $secret);
    }

    public function signatureHeaderName(): string
    {
        return 'X-My-Signature';
    }
}
```

使用：

```php
$result = WebhookServer::create()
    ->url('https://example.com/hooks')
    ->payload(['event' => 'test'])
    ->useSecret('secret')
    ->signer(MySigner::class)
    ->dispatch();
```

### 自定义签名验证器（Client 接收）

适配私有平台的特殊签名格式，实现 `SignatureValidatorInterface`：

```php
use wise\webhook\client\contract\SignatureValidatorInterface;
use think\Request;

class MySignatureValidator implements SignatureValidatorInterface
{
    public function isValid(Request $request, array $config): bool
    {
        $secret = $config['signing_secret'] ?? '';
        $header = $request->header($config['signature_header'] ?? 'X-Signature', '');
        $body   = $request->getContent();
        return hash_equals(hash_hmac('sha256', $body, $secret), (string) $header);
    }
}
```

在端点配置中通过 `signature_validator` 指定：

```php
'endpoints' => [
    'myapp' => [
        'signing_secret'      => env('MY_WEBHOOK_SECRET'),
        'signature_validator' => \app\webhook\MySignatureValidator::class,
    ],
],
```

### 组合 Facade

`wise\webhook\facade\Webhook` 提供 Server / Client 统一门面：

```php
use wise\webhook\facade\Webhook;

// 创建发送调用
$call = Webhook::serverCreate()
    ->url('https://example.com/hooks')
    ->payload(['event' => 'order.created'])
    ->dispatch();

// 获取接收器
$result = Webhook::clientReceiver()->receive($request, 'stripe');
```

### 事件监听

```php
use think\facade\Event;
use wise\webhook\server\event\WebhookSent;
use wise\webhook\server\event\WebhookFailed;

// 发送成功
Event::listen(WebhookSent::class, function (WebhookSent $event) {
    \think\facade\Log::info("Webhook sent", [
        'url' => $event->url,
        'code' => $event->responseCode,
        'duration_ms' => $event->durationMs,
    ]);
});

// 发送失败（无论是否还会重试）
Event::listen(WebhookFailed::class, function (WebhookFailed $event) {
    if (!$event->willRetry) {
        // 最终失败，触发告警
        \think\facade\Log::error("Webhook permanently failed: {$event->error}", [
            'url' => $event->url,
        ]);
    }
});
```

---

## API 参考

### WebhookCall 发送构建器

命名空间：`wise\webhook\server`。核心类，流式构建一次 Webhook 发送。

| 方法 | 参数 | 返回 | 说明 |
|------|------|------|------|
| `create()` | 无 | `WebhookCall` | 静态工厂，从配置初始化默认值（签名器、超时、重试、退避、回调） |
| `url(string $url)` | 目标 URL | `$this` | 设置目标 URL |
| `payload(array $payload)` | 请求体数组 | `$this` | 设置请求体（发送前 JSON 编码） |
| `useSecret(string $secret)` | 签名密钥 | `$this` | 设置签名密钥（非空才签名） |
| `signer(string $signerClass)` | `SignerInterface` 类名 | `$this` | 自定义签名器类名 |
| `method(string $method)` | POST/PUT/PATCH/GET 等 | `$this` | 设置 HTTP 方法（自动转大写） |
| `timeout(int $seconds)` | 秒 | `$this` | 请求超时 |
| `connectTimeout(int $seconds)` | 秒 | `$this` | 连接超时 |
| `maxRetries(int $max)` | 次数 | `$this` | 最大重试次数（不含首次） |
| `backoffStrategy(BackoffStrategy $strategy)` | 退避策略 | `$this` | 自定义退避策略 |
| `withHeaders(array $headers)` | 头数组 | `$this` | 追加额外请求头 |
| `meta(array $meta)` | 元数据 | `$this` | 追加追踪元数据 |
| `verifySsl(bool $verify)` | 布尔 | `$this` | 是否校验 SSL 证书 |
| `queue(?string $name)` | 队列名 | `$this` | 设置发送队列名 |
| `onSending(callable $cb)` | `fn(string $url, array $payload)` | `$this` | 发送前回调（仅同步） |
| `onSent(callable $cb)` | `fn(string $url, string $response, int $durationMs)` | `$this` | 成功回调（仅同步） |
| `onFailed(callable $cb)` | `fn(string $url, string $error, int $attempt)` | `$this` | 失败回调（仅同步） |
| `dispatch()` | 无 | `array\|void` | 按 `default_mode` 发送（sync 同步 / queue 队列） |
| `dispatchSync()` | 无 | `array` | 同步阻塞发送，返回 `{success,response,code,duration_ms}` |
| `dispatchToQueue(?string $queue = null)` | 队列名（可选） | `void` | 推入队列异步发送 |
| `dispatchIf(bool $condition)` | 布尔 | `?array` | 条件为真才发送 |
| `dispatchUnless(bool $condition)` | 布尔 | `?array` | 条件为假才发送 |
| `toArray()` | 无 | `array` | 序列化为队列传输数组 |
| `hasCallbacks()` | 无 | `bool` | 是否配置了闭包回调 |
| `getOnSendingCallback()` / `getOnSentCallback()` / `getOnFailedCallback()` | 无 | `?callable` | 获取回调 |
| `getUrl()` / `getSecret()` | 无 | `string\|?string` | 获取 URL / 密钥 |

### WebhookServer 静态入口

命名空间：`wise\webhook\server`。仅一个静态工厂方法，等价于 `WebhookCall::create()`。

```php
public static function create(): WebhookCall
```

### WebhookReceiver 接收编排器

命名空间：`wise\webhook\client`。编排「接收 → 签名验证 → 存储 → 队列」完整流程。

| 方法 | 参数 | 返回 | 说明 |
|------|------|------|------|
| `receive(Request $request, ?string $endpointName = null)` | 请求、端点名 | `array{success,id,message}` | 接收并处理一个 Webhook 请求 |
| `validateOnly(Request $request, string $endpointName)` | 请求、端点名 | `bool` | 仅验证签名，不存储不处理 |

`receive()` 返回示例：

```php
['success' => true, 'id' => 123, 'message' => 'Webhook received and queued']
// 失败：['success' => false, 'id' => null, 'message' => '端点未配置' / '签名验证失败']
```

### WebhookClient 静态入口

命名空间：`wise\webhook\client`。创建接收器。

```php
public static function receiver(): WebhookReceiver
```

### 模型方法

#### WebhookSendModel（发送记录，表 `webhook_sends`）

命名空间：`wise\webhook\server\model`。JSON 字段 `payload/headers/meta` 自动序列化。

| 方法 | 参数 | 返回 | 说明 |
|------|------|------|------|
| `markSending()` | 无 | `$this` | 标记为发送中 |
| `markSuccess(int $responseCode, string $responseBody, int $durationMs)` | 状态码/响应体/耗时 | `$this` | 标记发送成功 |
| `markFailed(string $errorMessage, int $attempt)` | 错误/次数 | `$this` | 标记发送失败 |
| `incrementAttempt()` | 无 | `$this` | 递增重试次数 |

状态取值（`status`）：`pending` / `sending` / `success` / `failed`。

#### WebhookReceiveModel（接收记录，表 `webhook_receives`）

命名空间：`wise\webhook\client\model`。JSON 字段 `payload/headers/meta` 自动序列化。

| 方法 | 参数 | 返回 | 说明 |
|------|------|------|------|
| `markProcessing()` | 无 | `$this` | 标记处理中 |
| `markProcessed()` | 无 | `$this` | 标记处理完成（写入 `processed_at`） |
| `markFailed(string $errorMessage)` | 错误 | `$this` | 标记处理失败 |
| `markRejected(string $reason)` | 原因 | `$this` | 标记签名被拒 |
| `incrementAttempt()` | 无 | `$this` | 递增重试次数 |

状态取值（`status`）：`pending` / `processing` / `processed` / `failed` / `rejected`。

### 接口契约

| 接口 | 命名空间 | 方法 | 说明 |
|------|---------|------|------|
| `SignerInterface` | `wise\webhook\server\contract` | `calculateSignature(string $payload, string $secret): string`；`signatureHeaderName(): string` | 发送侧签名器 |
| `SignatureValidatorInterface` | `wise\webhook\client\contract` | `isValid(Request $request, array $config): bool` | 接收侧签名验证器 |
| `WebhookProcessorInterface` | `wise\webhook\client\contract` | `handle(WebhookReceiveModel $webhookCall, Request $request): void` | 接收侧业务处理器 |

内置实现：
- `DefaultSigner`（HMAC-SHA256，头名 `X-Signature`）
- `NoSigner`（不签名）
- `DefaultSignatureValidator`（纯 hex / Stripe / GitHub 三格式）
- `LogOnlyProcessor`（仅日志，默认处理器）

### BackoffStrategy 退避策略

命名空间：`wise\support\backoff`（由 `wise-support` 提供）。四种工厂方法与核心方法：

| 方法 | 说明 |
|------|------|
| `BackoffStrategy::linear(int $stepMs = 1000)` | 线性退避：step, step×2, step×3... |
| `BackoffStrategy::exponential(int $baseMs = 1000, ?int $maxMs = 60000)` | 指数退避：base, base×2, base×4...（maxMs 加顶） |
| `BackoffStrategy::fixed(int $delayMs = 5000)` | 固定延迟 |
| `BackoffStrategy::custom(array $delaysMs)` | 自定义序列，超出用最后值 |
| `delayForAttempt(int $attempt)` | 计算第 N 次重试延迟（毫秒） |
| `toArray(?int $maxAttempts = null)` | 序列化策略 / 生成延迟数组 |

> 该策略天然兼容 `wise-support` 的 `retry()` 函数与队列 Job 的 `backoff()` 方法。

---

## 配置参考

`config/wise-webhook.php` 真实配置键、默认值与含义：

### server 段

| 配置键 | 默认值 | 说明 |
|--------|--------|------|
| `server.dispatch.default_mode` | `'sync'` | 默认发送模式：`sync`（同步）\| `queue`（队列） |
| `server.signer` | `\wise\webhook\server\signer\DefaultSigner::class` | 默认签名器类名 |
| `server.queue` | `'webhooks'` | 默认发送队列名 |
| `server.retry.max_attempts` | `3` | 最大重试次数 |
| `server.retry.backoff_method` | `'linear'` | 退避方式：`linear`\|`exponential`\|`fixed`\|`custom` |
| `server.retry.backoff_base_ms` | `1000` | 基础延迟（毫秒） |
| `server.retry.backoff_max_ms` | `60000` | 最大延迟（毫秒，仅 exponential 生效） |
| `server.http.timeout` | `15` | 请求超时（秒） |
| `server.http.connect_timeout` | `5` | 连接超时（秒） |
| `server.http.method` | `'POST'` | 默认 HTTP 方法 |
| `server.http.verify_ssl` | `true` | 是否校验 SSL 证书 |
| `server.headers` | `['Content-Type'=>'application/json; charset=utf-8','User-Agent'=>'WiseAdmin-Webhook/1.0']` | 默认请求头（自动添加） |
| `server.signature_header` | `'X-Signature'` | 签名头名称 |
| `server.callbacks.on_sending` | `null` | 全局发送前回调 `fn(string $url, array $payload): void` |
| `server.callbacks.on_sent` | `null` | 全局成功回调 `fn(string $url, string $response, int $durationMs): void` |
| `server.callbacks.on_failed` | `null` | 全局失败回调 `fn(string $url, string $error, int $attempt): void` |

### client 段

| 配置键 | 默认值 | 说明 |
|--------|--------|------|
| `client.endpoints` | `[]` | 多端点配置数组（示例注释见 config 文件） |
| `client.defaults.signature_validator` | `\wise\webhook\client\validator\DefaultSignatureValidator::class` | 默认签名验证器 |
| `client.defaults.processor_class` | `\wise\webhook\client\handler\LogOnlyProcessor::class` | 默认业务处理器 |
| `client.defaults.signature_header` | `'X-Signature'` | 默认签名头名称 |
| `client.defaults.store_webhook_calls` | `true` | 是否将接收记录落库 |
| `client.defaults.queue` | `'webhooks'` | 接收处理队列名 |
| `client.defaults.timeout` | `30` | 处理超时（秒） |
| `client.defaults.tolerance_seconds` | `300` | 时间戳容差（秒，防重放攻击） |

每个端点可覆盖的键：`signing_secret`、`signature_header`、`signature_validator`、`processor_class`、`store_webhook_calls`、`queue`、`timeout`、`tolerance_seconds`。

### circuit 段

| 配置键 | 默认值 | 说明 |
|--------|--------|------|
| `circuit.failure_threshold` | `5` | 连续失败多少次后熔断 |
| `circuit.timeout` | `120` | 熔断后冷却时间（秒） |
| `circuit.key_prefix` | `'wise_webhook:cb'` | Redis key 前缀 |

---

## 事件列表

### Server 事件（`wise\webhook\server\event`）

| 事件类 | 触发时机 | 主要属性 |
|--------|---------|---------|
| `WebhookSending` | HTTP 请求发出前 | `url`, `payload`, `headers`, `meta`, `attempt` |
| `WebhookSent` | 响应码为 2xx | `url`, `payload`, `response`, `responseCode`, `durationMs`, `meta`, `attempt` |
| `WebhookFailed` | 响应非 2xx 或网络错误 | `url`, `payload`, `error`, `responseCode`, `attempt`, `maxAttempts`, `willRetry`, `meta` |

### Client 事件（`wise\webhook\client\event`）

| 事件类 | 触发时机 | 主要属性 |
|--------|---------|---------|
| `WebhookAccepted` | 中间件签名验证通过 | `endpointName`, `request` |
| `WebhookReceived` | 接收请求存储到数据库后 | `webhookCall`, `request`, `endpointName` |
| `WebhookRejected` | 签名验证失败 / 端点未配置 | `endpointName`, `request`, `reason` |
| `WebhookProcessed` | Processor 处理完成 | `webhookCall`, `endpointName`, `result` |

---

## 错误码

### Server 侧（号段 100100~100199）

| 常量 | 值 | 默认消息 | 关联异常 |
|------|-----|---------|---------|
| `ErrorCode::SEND_FAILED` | `100101` | Webhook 发送失败 | `WebhookSendException`（HTTP 500 或覆盖码） |

另有 `ServiceUnavailableException`（`GeneralErrorCode::SERVICE_UNAVAILABLE`，值 151，HTTP 503）——断路器 OPEN 时抛出，由队列任务捕获后触发退避重试，不立即丢弃。

### Client 侧（号段 100000~100099）

| 常量 | 值 | 默认消息 | 关联异常 |
|------|-----|---------|---------|
| `ErrorCode::SIGNATURE_INVALID` | `100001` | Webhook 签名验证失败 | `InvalidSignatureException`（HTTP 403） |
| `ErrorCode::RECEPTION_ERROR` | `100002` | Webhook 接收处理失败 | `WebhookReceptionException`（HTTP 400） |

---

## 数据库表结构

### webhook_sends（Webhook 发送记录表）

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT PK AUTO_INCREMENT | 主键 |
| `url` | VARCHAR(1024) | 目标 URL |
| `method` | VARCHAR(10) | HTTP 方法，默认 `POST` |
| `payload` | TEXT | 请求体 JSON |
| `headers` | TEXT | 请求头 JSON |
| `signature` | VARCHAR(512) | 签名值 |
| `status` | VARCHAR(32) | 状态：pending / sending / success / failed，默认 pending |
| `response_code` | INT | HTTP 响应码 |
| `response_body` | TEXT | 响应体 |
| `error_message` | TEXT | 错误信息 |
| `attempt` | INT | 当前重试次数，默认 0 |
| `max_attempts` | INT | 最大重试次数，默认 3 |
| `duration_ms` | INT | 请求耗时（毫秒） |
| `meta` | TEXT | 元数据 JSON |
| `create_time` | DATETIME | 创建时间 |
| `update_time` | DATETIME | 更新时间 |

索引：`idx_status(status)`、`idx_create_time(create_time)`、`idx_status_time(status, create_time)`。

### webhook_receives（Webhook 接收记录表）

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT PK AUTO_INCREMENT | 主键 |
| `endpoint_name` | VARCHAR(128) | 端点名称 |
| `payload` | TEXT | 请求体 JSON |
| `headers` | TEXT | 请求头 JSON（已过滤 cookie/set-cookie/authorization） |
| `signature` | VARCHAR(512) | 接收到的签名 |
| `status` | VARCHAR(32) | 状态：pending / processing / processed / failed / rejected，默认 pending |
| `error_message` | TEXT | 错误信息 |
| `attempt` | INT | 当前重试次数，默认 0 |
| `max_attempts` | INT | 最大重试次数，默认 3 |
| `source_ip` | VARCHAR(50) | 请求来源 IP |
| `meta` | TEXT | 元数据 JSON |
| `create_time` | DATETIME | 创建时间 |
| `update_time` | DATETIME | 更新时间 |
| `processed_at` | DATETIME | 处理完成时间 |

索引：`idx_endpoint_status(endpoint_name, status)`、`idx_status(status)`、`idx_create_time(create_time)`。

> 表名前缀由项目 `database.php` 的 `prefix` 决定（如 `ws_`），模型通过 `protected $name = 'webhook_sends'` 自动拼接。

---

## CLI 命令

```bash
# 执行迁移创建数据表
php think migrate:run
```

> 新版本迁移引擎（`RegistersMigrations`）会直接扫描各包原始迁移目录，因此已发布过迁移后，即使删除复制的迁移文件也可通过 `migrate:run` 重建。

---

## 与其他 wise-* 包协作

- **`wise-support`（必需）**：本包直接依赖 `wise-support` 的能力，包括 `BackoffStrategy`（退避策略）、`CircuitBreaker`（per-host 断路器）、`WiseException` / `ErrorCodeInterface`（异常基类与错误码）、`ContextualLogger`（上下文日志，用于默认 `LogOnlyProcessor`）、`ServiceTrait` / `RegistersMigrations`（服务注册与迁移注册）。
- **`wise-login-security` / `wise-api`**：WiseAdmin 中入站 Webhook 端点通常注册在 `api` 模块路由，可与 `wise-api` 的限流、签名等中间件叠加使用；`VerifyWebhookSignature` 作为业务级签名校验可与登录安全策略互补。
- **`wise-notification`**：可结合 `WebhookFailed` 事件（`willRetry=false`）触发多渠道告警通知，实现 Webhook 投递失败的可观测性。
- **`wise-operation-log`**：接收/发送记录均已落库，可配合操作日志体系审计第三方回调与对外投递。
- **`wise-agent` / 业务服务**：任何需要对外回调或接收第三方事件的模块（支付回调、订单状态、CI 通知、AI 异步任务完成回调等）均可通过 `WebhookServer` / `WebhookClient` 接入。

---

## 目录结构

```
wise-webhook/
├── composer.json                           # 包定义（PSR-4 命名空间: wise\webhook\）
├── config/
│   └── wise-webhook.php                    # 默认配置（server/client/circuit）
├── database/
│   └── migrations/
│       ├── 20260708000001_create_webhook_sends_table.php      # 发送记录表
│       └── 20260708000002_create_webhook_receives_table.php   # 接收记录表
├── src/
│   ├── WebhookServiceProvider.php          # ServiceProvider（配置合并+迁移注册+命令+envSeeds）
│   ├── WebhookManager.php                  # Server/Client 统一管理入口（Facade 目标）
│   ├── enums/
│   │   └── WebhookStatus.php               # 状态枚举
│   ├── facade/
│   │   └── Webhook.php                     # Webhook Facade（serverCreate/clientReceiver）
│   ├── server/                             # 发送侧
│   │   ├── WebhookServer.php               # 静态入口
│   │   ├── WebhookCall.php                 # 流式构建器（核心）
│   │   ├── CallWebhookJob.php              # 发送队列任务（同步/异步双模式）
│   │   ├── contract/SignerInterface.php    # 签名器接口
│   │   ├── signer/DefaultSigner.php        # 默认 HMAC-SHA256 签名器
│   │   ├── signer/NoSigner.php             # 无签名器
│   │   ├── event/WebhookSending.php        # 发送前事件
│   │   ├── event/WebhookSent.php           # 发送成功事件
│   │   ├── event/WebhookFailed.php         # 发送失败事件
│   │   ├── exception/ErrorCode.php         # 错误码枚举（100101）
│   │   ├── exception/WebhookSendException.php      # 发送异常
│   │   ├── exception/ServiceUnavailableException.php # 熔断异常（503）
│   │   └── model/WebhookSendModel.php      # 发送记录模型
│   └── client/                             # 接收侧
│       ├── WebhookClient.php               # 静态入口
│       ├── WebhookReceiver.php             # 接收编排器（核心）
│       ├── ProcessWebhookJob.php           # 处理队列任务
│       ├── contract/SignatureValidatorInterface.php # 签名验证器接口
│       ├── contract/WebhookProcessorInterface.php   # 业务处理器接口
│       ├── validator/DefaultSignatureValidator.php  # 默认验证器（hex/Stripe/GitHub）
│       ├── handler/LogOnlyProcessor.php    # 默认日志处理器
│       ├── middleware/VerifyWebhookSignature.php    # 签名验证中间件
│       ├── event/WebhookReceived.php       # 已接收事件
│       ├── event/WebhookAccepted.php       # 签名通过事件
│       ├── event/WebhookRejected.php       # 签名被拒事件
│       ├── event/WebhookProcessed.php      # 处理完成事件
│       ├── exception/ErrorCode.php         # 错误码枚举（100001/100002）
│       ├── exception/InvalidSignatureException.php  # 签名异常（403）
│       ├── exception/WebhookReceptionException.php  # 接收异常（400）
│       └── model/WebhookReceiveModel.php   # 接收记录模型
└── tests/                                  # PHPUnit 单元测试
    └── Unit/
        ├── Client/                          # 客户端事件、验证器、接收器测试
        └── Server/                          # 退避、事件、签名器、发送调用测试
```

---

## 常见问题

### Q: 队列发送模式下如何感知发送结果？

队列模式下闭包回调不可用，请监听 `WebhookSent` / `WebhookFailed` 事件。`WebhookFailed` 的 `willRetry` 属性可判断是否还有重试机会，`willRetry=false` 时即为最终失败。

### Q: 发送失败后会自动重试吗？

会。同步模式 `dispatchSync()` 内部不会自动重试（一次 `send()` 即抛异常，由调用方决定），但队列模式 `CallWebhookJob::fire()` 会依据 `max_retries` 与退避策略延迟重试，超出次数后删除任务（可结合 `WebhookFailed` 事件监控）。

### Q: 如何切换全局默认发送模式为队列？

修改 `config/wise-webhook.php`：

```php
'server' => [
    'dispatch' => [
        'default_mode' => 'queue',
    ],
],
```

之后所有 `dispatch()` 将默认走队列。亦可单次调用 `->dispatchToQueue()` 覆盖。

### Q: 自定义端点如何接收？

1. 在 `config/wise-webhook.php` 的 `client.endpoints` 增加端点（含 `signing_secret`）；
2. 定义路由并挂载 `VerifyWebhookSignature` 中间件（通用 `:endpoint` 或显式 `:name`）；
3. 控制器中 `WebhookClient::receiver()->receive($request, $endpoint)`；
4. 实现 `WebhookProcessorInterface` 并在端点配置指定 `processor_class`。

### Q: 不需要签名校验的公开 Webhook 端点怎么办？

不要配置 `signing_secret`（为空则验证失败并拒绝），或完全不加 `VerifyWebhookSignature` 中间件、直接调用 `WebhookReceiver::receive()`。若需校验可选用自定义 `SignatureValidatorInterface`。

### Q: 为什么发送请求被拒绝了？

`CallWebhookJob::sendRequest()` 内置 SSRF 防护，会拒绝向 `127.0.0.0/8`、`10.0.0.0/8`、`172.16.0.0/12`、`192.168.0.0/16`、`169.254.0.0/16`、`0.0.0.0/8`、`100.64.0.0/10` 等内部/保留网段发送。生产环境请使用公网地址；本地联调如需关闭，需调整代码或使用 `verifySsl(false)` 仅关闭 SSL（不关闭 SSRF）。

### Q: 表前缀如何配置？

遵循 ThinkPHP 约定，在 `database.php` 的 `prefix` 配置（如 `ws_`），模型会自动拼接为 `ws_webhook_sends` / `ws_webhook_receives`。

---

## 依赖关系

### 必需（require）

| 依赖 | 版本要求 | 说明 |
|------|---------|------|
| `php` | >= 7.4 | 核心语言 |
| `topthink/framework` | ^6.0 \|\| ^8.0 | ThinkPHP 框架 |
| `ext-json` | * | JSON 编码/解码 |
| `ext-curl` | * | HTTP 请求底层 |
| `wise-support`（monorepo 内） | * | 退避、熔断、异常、服务注册等共享能力 |

### 可选（suggest）

| 依赖 | 说明 |
|------|------|
| `topthink/think-queue` | 用于异步发送和处理 Webhook（强烈建议） |
| `topthink/think-migration` | 用于数据库迁移建表 |

### 开发依赖（require-dev）

| 依赖 | 版本 | 说明 |
|------|------|------|
| `phpunit/phpunit` | ^9.0 | 单元测试 |
| `mockery/mockery` | ^1.5 | Mock 框架 |

### 迁移

- 包在 `composer.json` 的 `extra.think` 中声明 `services`（`WebhookServiceProvider`）与 `config`（`wise-webhook`）；迁移目录由 `registerMigrations()` 声明。
- 安装后需执行 `php think migrate:run` 创建发送/接收记录表。

---

## 许可证

Apache-2.0 License

---

> 更多信息请参考 WiseAdmin 框架文档及相关演示插件。

# wise-webhook 单元测试生成报告

## 执行结果
- **状态**：✅ 全部通过（OK）
- **测试数量**：103 个用例，218 条断言
- **执行时间**：~0.16s
- **PHP / PHPUnit**：PHP 8.4.7 / PHPUnit 10.5.62
- **Deprecations**：1（环境级 — 框架在 PHP 8.4 下的 E_DEPRECATED，非测试代码问题）

## 测试环境搭建（monorepo 适配）
仓库为 `wise-*` 多包单体结构，`vendor` 位于 `src/vendor`。为可运行测试，在 `tests/bootstrap.php` 中做了如下兼容处理（仅测试文件）：
1. 多候选路径定位 `autoload.php`（仓库根 / `src/vendor` / 包内）。
2. 手动加载 ThinkPHP `helper.php`，提供 `config()`/`app()`/`request()` 等全局函数。
3. 引导最小 `think\App` 容器，注册核心门面（config/event/queue…）。
4. 为测试命名空间注册 PSR-4 自动加载。
5. 每次测试 `setUp` 重新应用日志通道（file 驱动写临时文件）与 `request()` 解析为 `TestingRequest`，避免被其它测试的容器/配置变更影响。

## 新增 / 修改的测试文件
| 文件 | 说明 |
|---|---|
| `tests/Unit/Client/VerifyWebhookSignatureTest.php` | 中间件：未知端点放行、签名失败抛异常、签名成功放行并注入端点名、路由参数解析端点名 |
| `tests/Unit/Client/LogOnlyProcessorTest.php` | 仅日志处理器（无 DB） |
| `tests/Unit/Server/CallWebhookJobTest.php` | **离线** SSRF 防护（IP/CIDR 阻断、localhost 解析、非法 scheme）、退避延迟计算、URL 解析、空 URL/缺主机早抛 |
| `tests/Unit/Server/ProcessWebhookJobTest.php` | 空 webhook_call_id 直接丢弃、请求重建 |
| `tests/Unit/Server/WebhookManagerTest.php` | Manager / Facade：`serverCreate` / `clientReceiver` |
| `tests/Unit/Server/WebhookServiceProviderTest.php` | `envSeeds()`、`register()` 绑定 Manager、配置合并 |
| `tests/Unit/Common/WebhookStatusTest.php` | 枚举常量与状态集合 |
| `tests/Unit/Common/ExceptionTest.php` | 错误码枚举 + 各类异常（业务码/HTTP 码/上下文字段） |
| 修正既有测试 | `DefaultSignatureValidatorTest` / `ClientEventTest` / `WebhookReceiverTest`（改用 `makeRequest`，修复 HMAC 静态体缓存隔离）、`BackoffStrategyTest`（`custom([])` 抛异常符合源码） |
| `tests/TestCase.php` | 新增 `makeRequest` / `setWebhookConfig` / 反射辅助 / 静态缓存与日志·request 绑定重置 |
| `tests/TestingRequest.php`、`tests/NullLogger.php` | 测试替身（见下方已知缺陷） |
| `tests/bootstrap.php`、`phpunit.xml.dist` | 环境与 schema 适配 |

## 覆盖说明（覆盖率未能在本环境测量）
本机未安装 `xdebug` / `pcov`，无法产出行覆盖率百分比。已针对**可单元化**的逻辑编写覆盖，下列类均被覆盖：
`WebhookCall`、`WebhookServer`、`WebhookClient`、`WebhookManager`、`WebhookReceiver`、`WebhookServiceProvider`、`DefaultSignatureValidator`、`VerifyWebhookSignature`、`LogOnlyProcessor`、`CallWebhookJob`（SSRF/退避/解析）、`ProcessWebhookJob`（空 ID/请求重建）、`DefaultSigner`/`NoSigner`、`BackoffStrategy`、客户端/服务端事件、`WebhookStatus`、各 `ErrorCode` 与异常类。

### 需集成环境方可覆盖（未纳入单元层，非测试失败）
- `CallWebhookJob::send()` 真实 `curl` 发送、熔断（redis）、死信。
- `ProcessWebhookJob::fire()` 完整处理/失败重试路径（依赖 DB 模型落库与 `queue`）。
- `WebhookReceiveModel` / `WebhookSendModel` 的 `markX()` 状态持久化（依赖数据库）。
上述建议以「集成/功能测试 + SQLite/Redis/队列」补齐。

## 发现并修复的生产源码缺陷
1. **`think\Request::withParam()` 不存在（已修复）**：`VerifyWebhookSignature.php:81` 与 `ProcessWebhookJob.php:98` 原先调用了当前安装版本 `topthink/framework` 未提供的 `withParam()`，真实运行会抛 `Error: Call to undefined method`。
   - 已改为等价方法 `setRoute()`——`think\Request::param()` 会将 `route` 变量合并进 `param` 包（见 `think/Request.php:811`），因此下游 `WebhookReceiver::receive()` 仍可通过 `param('_webhook_endpoint')` 正常读取。
   - 测试侧 `TestingRequest` 中用于补洞的 `withParam()` 垫片已移除。
2. `WebhookServiceProviderTest::testRegisterMergesConfig` 原先 `new App()` 会劫持全局 `Container` 实例，已改为复用 bootstrap 已配置的 App。

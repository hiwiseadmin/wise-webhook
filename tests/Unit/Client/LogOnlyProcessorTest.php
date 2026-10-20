<?php
declare(strict_types=1);

namespace wise\webhook\tests\Unit\Client;

use think\Request;
use wise\webhook\client\handler\LogOnlyProcessor;
use wise\webhook\client\model\WebhookReceiveModel;
use wise\webhook\tests\TestCase;

/**
 * LogOnlyProcessor 单元测试
 *
 * 该处理器仅做日志记录，不依赖数据库；测试验证其在无 DB 下可正常执行。
 */
class LogOnlyProcessorTest extends TestCase
{
    public function testHandleRunsWithoutError(): void
    {
        $model = new WebhookReceiveModel();
        $model->setAttr('endpoint_name', 'stripe');
        $model->setAttr('id', 42);
        $model->setAttr('payload', ['event' => 'order.paid']);

        $request  = $this->makeRequest('https://example.com/webhooks/stripe');
        $processor = new LogOnlyProcessor();

        // 使用空日志器（bootstrap 注入），不应抛出任何异常
        $processor->handle($model, $request);

        $this->expectNotToPerformAssertions();
    }

    public function testHandleWithEmptyPayload(): void
    {
        $model = new WebhookReceiveModel();
        $model->setAttr('endpoint_name', 'unknown');

        $request   = $this->makeRequest('https://example.com/webhooks/unknown');
        $processor = new LogOnlyProcessor();

        $processor->handle($model, $request);

        $this->expectNotToPerformAssertions();
    }
}

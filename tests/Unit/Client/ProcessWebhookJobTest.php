<?php
declare(strict_types=1);

namespace wise\webhook\tests\Unit\Client;

use Mockery;
use think\queue\Job;
use wise\webhook\client\model\WebhookReceiveModel;
use wise\webhook\client\ProcessWebhookJob;
use wise\webhook\tests\TestCase;

/**
 * ProcessWebhookJob 单元测试
 *
 * 覆盖无 DB 依赖的分支：空 webhook_call_id 直接丢弃、请求重建。
 * 完整处理/失败重试路径依赖数据库与队列，不在单元测试覆盖范围内。
 */
class ProcessWebhookJobTest extends TestCase
{
    public function testFireWithNullCallIdDeletesJob(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('delete')->once();

        $processJob = new ProcessWebhookJob([]);
        $processJob->fire($job, ['webhook_call_id' => null, 'endpoint_name' => 'unknown']);

        $this->expectNotToPerformAssertions();
    }

    public function testCreateRequestReturnsRequest(): void
    {
        $model = new WebhookReceiveModel();
        $model->setAttr('endpoint_name', 'stripe');
        $model->setAttr('id', 7);

        $processJob = new ProcessWebhookJob([]);
        $request = $this->invokeInaccessible($processJob, 'createRequest', [$model]);

        $this->assertInstanceOf(\think\Request::class, $request);
        $this->assertSame('stripe', $request->param('_webhook_endpoint'));
        $this->assertSame(7, $request->param('_webhook_model_id'));
    }
}

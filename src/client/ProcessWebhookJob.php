<?php
declare(strict_types=1);

namespace wise\webhook\client;

use think\facade\Event;
use think\queue\Job;
use wise\webhook\client\contract\WebhookProcessorInterface;
use wise\webhook\client\event\WebhookProcessed;
use wise\webhook\client\model\WebhookReceiveModel;
use wise\support\backoff\BackoffStrategy;

/**
 * Webhook 处理队列任务
 *
 * 从队列加载 Webhook 记录，委托给用户定义的 Processor 执行业务逻辑。
 * 支持失败重试和退避策略。
 *
 * 注意：由于无法在队列中还原整个 Request 对象，
 * 此处传递请求的关键信息（headers/payload 已在模型中存储）。
 */
class ProcessWebhookJob
{
    /**
     * 队列执行入口（think-queue 约定）
     *
     * @param  Job   $job  队列任务对象
     * @param  array $data 任务数据
     */
    public function fire(Job $job, array $data): void
    {
        $webhookCallId  = $data['webhook_call_id'] ?? null;
        $endpointName   = $data['endpoint_name'] ?? 'unknown';
        $processorClass = $data['processor_class'] ?? \wise\webhook\client\handler\LogOnlyProcessor::class;
        $maxAttempts    = $data['max_attempts'] ?? 3;
        $attempt        = ($data['_attempt'] ?? 0) + 1;

        try {
            // 加载模型
            $model = $this->loadModel($webhookCallId);

            if ($model === null) {
                // 无法找到记录，直接丢弃
                $job->delete();
                return;
            }

            // 标记处理中
            $model->markProcessing();

            // 创建处理器
            /** @var WebhookProcessorInterface $processor */
            $processor = new $processorClass();

            // 执行处理（创建空的 Request 传递关键信息）
            $request = $this->createRequest($model);
            $result  = $processor->handle($model, $request);

            // 标记完成
            $model->markProcessed();

            // 触发事件
            Event::trigger(new WebhookProcessed($model, $endpointName, $result));

            // 删除任务
            $job->delete();

        } catch (\Throwable $e) {
            $this->handleFailure($job, $data, $webhookCallId, $endpointName, $attempt, $maxAttempts, $e);
        }
    }

    /**
     * 加载 Webhook 接收记录
     *
     * @param  int|null $id
     * @return WebhookReceiveModel|null
     */
    private function loadModel(?int $id): ?WebhookReceiveModel
    {
        if ($id === null) {
            return null;
        }

        return WebhookReceiveModel::find($id);
    }

    /**
     * 从模型重建 Request
     *
     * @param  WebhookReceiveModel $model
     * @return \think\Request
     */
    private function createRequest(WebhookReceiveModel $model): \think\Request
    {
        $request = request();
        // 将模型信息注入到请求参数中（当前 topthink/framework 未提供 withParam，
        // 使用 setRoute 等价注入；避免动态属性，保持 PHP 8.2+ 兼容）
        $request->setRoute(['_webhook_endpoint' => $model->endpoint_name, '_webhook_model_id' => $model->id]);
        return $request;
    }

    /**
     * 处理失败情况
     *
     * @param  Job        $job
     * @param  array      $data
     * @param  int|null   $webhookCallId
     * @param  string     $endpointName
     * @param  int        $attempt
     * @param  int        $maxAttempts
     * @param  \Throwable $exception
     */
    private function handleFailure(
        Job $job,
        array $data,
        ?int $webhookCallId,
        string $endpointName,
        int $attempt,
        int $maxAttempts,
        \Throwable $exception
    ): void {
        // 更新模型状态
        if ($webhookCallId !== null) {
            $model = WebhookReceiveModel::find($webhookCallId);
            if ($model) {
                $model->markFailed($exception->getMessage());
                $model->incrementAttempt();
            }
        }

        if ($attempt >= $maxAttempts) {
            // 超出最大重试，删除任务
            $job->delete();
            return;
        }

        // 计算退避延迟（指数退避：5s 起步，最大 1h）
        $strategy = BackoffStrategy::exponential(5000, 3600000);
        $delayMs = $strategy->delayForAttempt($attempt);
        $delaySeconds = max(1, (int) ceil($delayMs / 1000));

        // 更新重试信息
        $data['_attempt'] = $attempt;

        // 延迟重新入队
        /** @var \think\Queue $queue */
        $queue = app()->make('queue');
        $queueName = $data['queue_name'] ?? 'webhooks';
        $queue->later($delaySeconds, self::class, $data, $queueName);

        // 移除旧任务
        $job->delete();
    }
}

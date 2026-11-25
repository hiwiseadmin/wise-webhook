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
            $model = $this->loadModel($webhookCallId);

            if ($model === null) {
                $job->delete();
                return;
            }

            $model->markProcessing();

            $processor = new $processorClass();

            $request = $this->createRequest($model);
            $result  = $processor->handle($model, $request);

            $model->markProcessed();

            Event::trigger(new WebhookProcessed($model, $endpointName, $result));

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
        $request = new \think\Request();

        $request->withHeader((array) ($model->headers ?? []));
        $request->withPost((array) ($model->payload ?? []));
        $request->withInput((string) json_encode($model->payload ?? [], JSON_UNESCAPED_UNICODE));
        $request->setRoute([
            '_webhook_endpoint' => $model->endpoint_name,
            '_webhook_model_id' => $model->id,
        ]);

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
        if ($webhookCallId !== null) {
            $model = WebhookReceiveModel::find($webhookCallId);
            if ($model) {
                $model->markFailed($exception->getMessage());
                $model->incrementAttempt();
            }
        }

        if ($attempt >= $maxAttempts) {
            $job->delete();
            return;
        }

        $strategy = BackoffStrategy::exponential(5000, 3600000);
        $delayMs = $strategy->delayForAttempt($attempt);
        $delaySeconds = max(1, (int) ceil($delayMs / 1000));

        $data['_attempt'] = $attempt;

        $queue = app()->make('queue');
        $queueName = $data['queue_name'] ?? 'webhooks';
        $queue->later($delaySeconds, self::class, $data, $queueName);

        $job->delete();
    }
}

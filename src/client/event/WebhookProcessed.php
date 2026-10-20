<?php
declare(strict_types=1);

namespace wise\webhook\client\event;

use wise\webhook\client\model\WebhookReceiveModel;

/**
 * Webhook 处理完成事件
 *
 * 在 ProcessWebhookJob 委托 Processor 执行完毕后触发。
 */
class WebhookProcessed
{
    /** @var WebhookReceiveModel */
    public WebhookReceiveModel $webhookCall;

    /** @var string 端点名称 */
    public string $endpointName;

    /** @var mixed 处理器返回结果 */
    public $result;

    /**
     * @param WebhookReceiveModel $webhookCall
     * @param string               $endpointName
     * @param mixed                $result
     */
    public function __construct(
        WebhookReceiveModel $webhookCall,
        string $endpointName,
        $result = null
    ) {
        $this->webhookCall  = $webhookCall;
        $this->endpointName = $endpointName;
        $this->result       = $result;
    }
}

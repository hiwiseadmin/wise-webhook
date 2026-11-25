<?php
declare(strict_types=1);

namespace wise\webhook\client\event;

use wise\webhook\client\model\WebhookReceiveModel;
use think\Request;

/**
 * Webhook 已接收事件
 *
 * 在请求存储到数据库后触发（签名验证已通过）。
 */
class WebhookReceived
{
    /** @var WebhookReceiveModel */
    public WebhookReceiveModel $webhookCall;

    /** @var Request */
    public Request $request;

    /** @var string 端点名称 */
    public string $endpointName;

    /**
     * @param WebhookReceiveModel $webhookCall
     * @param Request              $request
     * @param string               $endpointName
     */
    public function __construct(
        WebhookReceiveModel $webhookCall,
        Request $request,
        string $endpointName
    ) {
        $this->webhookCall  = $webhookCall;
        $this->request      = $request;
        $this->endpointName = $endpointName;
    }
}

<?php
declare(strict_types=1);

namespace wise\webhook\client\event;

use think\Request;

/**
 * Webhook 签名验证失败事件
 *
 * 在中间件或接收器签名验证失败时触发。
 */
class WebhookRejected
{
    /** @var string 端点名称 */
    public string $endpointName;

    /** @var Request */
    public Request $request;

    /** @var string 拒绝原因 */
    public string $reason;

    /**
     * @param string  $endpointName
     * @param Request $request
     * @param string  $reason
     */
    public function __construct(string $endpointName, Request $request, string $reason = '')
    {
        $this->endpointName = $endpointName;
        $this->request      = $request;
        $this->reason       = $reason;
    }
}

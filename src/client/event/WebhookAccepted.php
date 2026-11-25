<?php
declare(strict_types=1);

namespace wise\webhook\client\event;

use think\Request;

/**
 * Webhook 签名验证通过事件
 *
 * 在中间件验证签名通过后触发。
 */
class WebhookAccepted
{
    /** @var string 端点名称 */
    public string $endpointName;

    /** @var Request */
    public Request $request;

    /**
     * @param string  $endpointName
     * @param Request $request
     */
    public function __construct(string $endpointName, Request $request)
    {
        $this->endpointName = $endpointName;
        $this->request      = $request;
    }
}

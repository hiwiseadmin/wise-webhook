<?php
declare(strict_types=1);

namespace wise\webhook\client\middleware;

use think\facade\Event;
use think\Request;
use wise\webhook\client\contract\SignatureValidatorInterface;
use wise\webhook\client\event\WebhookAccepted;
use wise\webhook\client\event\WebhookRejected;

/**
 * Webhook 签名验证中间件
 *
 * 在请求到达控制器前验证签名，拦截非法请求。
 *
 * 路由用法：
 * <pre>
 * // 自动从 URL 段提取端点名
 * Route::post('webhooks/:endpoint', 'controller@action')
 *     ->middleware(\wise\webhook\client\middleware\VerifyWebhookSignature::class);
 *
 * // 显式指定端点名
 * Route::post('webhooks/stripe', 'controller@action')
 *     ->middleware(\wise\webhook\client\middleware\VerifyWebhookSignature::class . ':stripe');
 * </pre>
 */
class VerifyWebhookSignature
{
    /**
     * 处理请求
     *
     * @param  Request  $request
     * @param  \Closure $next
     * @param  string   $endpointName 端点名（中间件参数）
     * @return mixed
     *
     * @throws \wise\webhook\client\exception\InvalidSignatureException
     */
    public function handle(Request $request, \Closure $next, ?string $endpointName = null)
    {
        // 确定端点名称
        $endpointName = $this->resolveEndpointName($request, $endpointName);

        if ($endpointName === null) {
            // 无法确定端点名，放行由控制器处理
            return $next($request);
        }

        // 获取端点配置
        $config = $this->getEndpointConfig($endpointName);

        if ($config === null) {
            // 端点未配置，记录并放行
            Event::trigger(new WebhookRejected($endpointName, $request, '端点未配置'));
            return $next($request);
        }

        // 创建签名验证器
        $validator = $this->makeValidator($config);

        // 验证签名
        if (!$validator->isValid($request, $config)) {
            // 签名验证失败
            Event::trigger(new WebhookRejected(
                $endpointName,
                $request,
                '签名验证失败'
            ));

            throw new \wise\webhook\client\exception\InvalidSignatureException(
                $endpointName,
                'Webhook signature validation failed'
            );
        }

        // 签名通过
        Event::trigger(new WebhookAccepted($endpointName, $request));

        // 将端点名注入请求参数（当前 topthink/framework 未提供 withParam，
        // 使用 setRoute 等价注入；其会被 param() 合并并可被 WebhookReceiver 读取。
        // 避免动态属性，保持 PHP 8.2+ 兼容）
        $request->setRoute(['_webhook_endpoint' => $endpointName]);

        return $next($request);
    }

    /**
     * 确定端点名称
     *
     * 优先级：中间件参数 > 请求参数 _endpoint > URL 参数 endpoint
     *
     * @param  Request      $request
     * @param  string|null  $middlewareParam 中间件参数
     * @return string|null
     */
    private function resolveEndpointName(Request $request, ?string $middlewareParam): ?string
    {
        if ($middlewareParam !== null && $middlewareParam !== '') {
            return $middlewareParam;
        }

        // 从 URL 参数
        $routeParams = $request->route();
        if (isset($routeParams['endpoint'])) {
            return $routeParams['endpoint'];
        }
        if (isset($routeParams['endpointName'])) {
            return $routeParams['endpointName'];
        }

        // 从请求参数
        $param = $request->param('_endpoint');
        if ($param !== null && $param !== '') {
            return $param;
        }

        return null;
    }

    /**
     * 获取端点配置
     *
     * @param  string $endpointName
     * @return array|null
     */
    private function getEndpointConfig(string $endpointName): ?array
    {
        $endpoints = config('wise-webhook.client.endpoints', []);
        $config    = $endpoints[$endpointName] ?? null;

        if ($config === null) {
            return null;
        }

        // 合并默认值
        $defaults = config('wise-webhook.client.defaults', []);

        return array_merge($defaults, $config);
    }

    /**
     * 创建签名验证器实例
     *
     * @param  array $config 端点配置
     * @return SignatureValidatorInterface
     */
    private function makeValidator(array $config): SignatureValidatorInterface
    {
        $validatorClass = $config['signature_validator']
            ?? \wise\webhook\client\validator\DefaultSignatureValidator::class;

        return new $validatorClass();
    }
}

<?php
declare(strict_types=1);

namespace wise\webhook\client\contract;

use think\Request;

/**
 * 签名验证器接口
 *
 * Client 侧在接收 Webhook 时验证请求签名，
 * 确保请求来源可信且数据未被篡改。
 *
 * 内置实现：DefaultSignatureValidator（HMAC-SHA256）
 * 用户可以自定义验证器以适配 Stripe、GitHub 等特殊签名格式。
 */
interface SignatureValidatorInterface
{
    /**
     * 验证请求签名
     *
     * @param  Request $request 当前请求
     * @param  array   $config  端点配置（含 signing_secret, signature_header 等）
     * @return bool  签名有效返回 true
     */
    public function isValid(Request $request, array $config): bool;
}

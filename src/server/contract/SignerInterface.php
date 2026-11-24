<?php
declare(strict_types=1);

namespace wise\webhook\server\contract;

/**
 * 签名器接口
 *
 * Server 侧在发送 Webhook 前对请求体进行签名，
 * 确保接收方可以验证数据完整性和来源。
 */
interface SignerInterface
{
    /**
     * 计算签名
     *
     * @param  string $payload JSON 编码后的请求体
     * @param  string $secret  签名密钥
     * @return string 签名字符串
     */
    public function calculateSignature(string $payload, string $secret): string;

    /**
     * 签名头名称（放入 HTTP Header 的键名）
     *
     * @return string 如 'X-Signature'
     */
    public function signatureHeaderName(): string;
}

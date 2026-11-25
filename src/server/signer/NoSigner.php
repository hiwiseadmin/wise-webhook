<?php
declare(strict_types=1);

namespace wise\webhook\server\signer;

use wise\webhook\server\contract\SignerInterface;

/**
 * 无签名器
 *
 * 当不需要对 Webhook 请求签名时使用。
 * 返回空字符串，不添加签名头。
 */
class NoSigner implements SignerInterface
{
    /**
     * 不计算签名
     *
     * @param  string $payload
     * @param  string $secret
     * @return string 空字符串
     */
    public function calculateSignature(string $payload, string $secret): string
    {
        return '';
    }

    /**
     * 签名头名称（不会被实际使用）
     *
     * @return string
     */
    public function signatureHeaderName(): string
    {
        return '';
    }
}

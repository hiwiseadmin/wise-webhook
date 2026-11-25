<?php
declare(strict_types=1);

namespace wise\webhook\server\signer;

use wise\webhook\server\contract\SignerInterface;

/**
 * 默认签名器 — HMAC-SHA256
 *
 * 计算方式：hash_hmac('sha256', $payload, $secret)
 * 签名头：X-Signature（可通过 $headerName 自定义）
 *
 * 对标 Spatie DefaultSigner，接收方可使用相同算法验证。
 */
class DefaultSigner implements SignerInterface
{
    /** @var string 签名头名称 */
    private string $headerName;

    /**
     * @param string $headerName 签名头名称，默认 'X-Signature'
     */
    public function __construct(string $headerName = 'X-Signature')
    {
        $this->headerName = $headerName;
    }

    /**
     * 计算 HMAC-SHA256 签名
     *
     * @param  string $payload JSON 编码后的请求体
     * @param  string $secret  签名密钥
     * @return string HMAC-SHA256 十六进制签名字符串
     */
    public function calculateSignature(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * 签名头名称
     *
     * @return string
     */
    public function signatureHeaderName(): string
    {
        return $this->headerName;
    }
}

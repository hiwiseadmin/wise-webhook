<?php
declare(strict_types=1);

namespace wise\webhook\client\validator;

use think\Request;
use wise\webhook\client\contract\SignatureValidatorInterface;

/**
 * 默认签名验证器 — HMAC-SHA256
 *
 * 支持三种主流签名格式：
 * 1. 纯十六进制字符串：直接比对
 * 2. Stripe 格式：t=TIMESTAMP,v1=SIGNATURE（从 body 读取原始内容再验证）
 * 3. GitHub 格式：sha256=SIGNATURE（从 body 读取原始内容再验证）
 *
 * 验证逻辑：
 * - 从请求头中提取签名值
 * - 对原始请求体计算 HMAC-SHA256
 * - 比对签名（hash_equals 防时序攻击）
 */
class DefaultSignatureValidator implements SignatureValidatorInterface
{
    /** @var string|null 缓存原始请求体（php://input 只能读取一次） */
    private static ?string $cachedRawBody = null;
    /**
     * 验证请求签名
     *
     * @param  Request $request 当前请求
     * @param  array   $config  端点配置
     * @return bool
     */
    public function isValid(Request $request, array $config): bool
    {
        $secret       = $config['signing_secret'] ?? '';
        $headerName   = $config['signature_header'] ?? 'X-Signature';
        $tolerance    = $config['tolerance_seconds'] ?? 300;

        if (empty($secret)) {
            return false;
        }

        // 获取签名头
        $signatureHeader = $this->getHeaderValue($request, $headerName);
        if ($signatureHeader === null) {
            return false;
        }

        // Stripe 格式：t=1234567890,v1=abcdef,...
        if (strpos($signatureHeader, 't=') === 0) {
            return $this->validateStripeFormat($request, $signatureHeader, $secret, $tolerance);
        }

        // GitHub 格式：sha256=abcdef...
        if (strpos($signatureHeader, 'sha256=') === 0) {
            return $this->validateGitHubFormat($request, $signatureHeader, $secret);
        }

        // 纯 hex 签名：直接比对
        return $this->validatePlainFormat($request, $signatureHeader, $secret);
    }

    /**
     * 验证 Stripe 格式签名
     *
     * 格式：t=1234567890,v1=abcdef,v0=...
     * 计算方式：HMAC-SHA256(timestamp + '.' + raw_body, secret)
     *
     * @param  Request $request
     * @param  string  $header    签名头完整值
     * @param  string  $secret    密钥
     * @param  int     $tolerance 时间戳容差（秒）
     * @return bool
     */
    private function validateStripeFormat(
        Request $request,
        string $header,
        string $secret,
        int $tolerance
    ): bool {
        // 解析签名头
        $parts     = explode(',', $header);
        $timestamp = null;
        $schemes   = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if (strpos($part, 't=') === 0) {
                $timestamp = (int) substr($part, 2);
            } elseif (strpos($part, 'v1=') === 0) {
                $schemes['v1'] = substr($part, 3);
            } elseif (strpos($part, 'v0=') === 0) {
                $schemes['v0'] = substr($part, 3);
            }
        }

        if ($timestamp === null || !isset($schemes['v1'])) {
            return false;
        }

        // 时间戳容差检查（防重放攻击）
        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        // 获取原始请求体
        $rawBody = $this->getRawBody($request);

        // 计算期望签名
        $signedPayload   = $timestamp . '.' . $rawBody;
        $expectedSig     = hash_hmac('sha256', $signedPayload, $secret);
        $receivedSig     = $schemes['v1'];

        return hash_equals($expectedSig, $receivedSig);
    }

    /**
     * 验证 GitHub 格式签名
     *
     * 格式：sha256=abcdef...
     * 计算方式：HMAC-SHA256(raw_body, secret)
     *
     * @param  Request $request
     * @param  string  $header  签名头值
     * @param  string  $secret  密钥
     * @return bool
     */
    private function validateGitHubFormat(Request $request, string $header, string $secret): bool
    {
        $receivedSig = substr($header, 7); // 去掉 "sha256="
        $rawBody     = $this->getRawBody($request);
        $expectedSig = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expectedSig, $receivedSig);
    }

    /**
     * 验证纯 hex 签名
     *
     * 格式：直接十六进制 HMAC-SHA256 字符串
     * 计算方式：HMAC-SHA256(json_body, secret)
     *
     * @param  Request $request
     * @param  string  $header  签名头值
     * @param  string  $secret  密钥
     * @return bool
     */
    private function validatePlainFormat(Request $request, string $header, string $secret): bool
    {
        $rawBody     = $this->getRawBody($request);
        $expectedSig = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expectedSig, $header);
    }

    /**
     * 获取请求头值（大小写不敏感）
     *
     * @param  Request $request
     * @param  string  $name  头名称
     * @return string|null
     */
    private function getHeaderValue(Request $request, string $name): ?string
    {
        $headers = $request->header();

        // 直接匹配
        if (isset($headers[$name])) {
            $value = $headers[$name];
            return is_array($value) ? reset($value) : $value;
        }

        // 大小写不敏感
        $lowerName = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $lowerName) {
                return is_array($value) ? reset($value) : $value;
            }
        }

        return null;
    }

    /**
     * 获取原始请求体
     *
     * @param  Request $request
     * @return string
     */
    private function getRawBody(Request $request): string
    {
        if (self::$cachedRawBody === null) {
            $content = $request->getContent();
            if ($content === null || $content === '' || $content === false) {
                // getContent() 可能已被框架消费，尝试直接读取 php://input
                $raw = @file_get_contents('php://input');
                $content = ($raw !== false) ? $raw : '';
            }
            self::$cachedRawBody = $content;
        }
        return self::$cachedRawBody;
    }
}

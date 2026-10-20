<?php
declare(strict_types=1);

namespace wise\webhook\server\event;

/**
 * Webhook 发送成功事件
 *
 * HTTP 响应码为 2xx 时触发。
 */
class WebhookSent
{
    /** @var string 目标 URL */
    public string $url;

    /** @var array 请求体 */
    public array $payload;

    /** @var string 响应体 */
    public string $response;

    /** @var int HTTP 响应码 */
    public int $responseCode;

    /** @var int 请求耗时（毫秒） */
    public int $durationMs;

    /** @var array 元数据 */
    public array $meta;

    /** @var int 重试次数 */
    public int $attempt;

    /**
     * @param string $url          目标 URL
     * @param array  $payload      请求体
     * @param string $response     响应体
     * @param int    $responseCode HTTP 响应码
     * @param int    $durationMs   请求耗时（毫秒）
     * @param array  $meta         元数据
     * @param int    $attempt      重试次数
     */
    public function __construct(
        string $url,
        array $payload,
        string $response,
        int $responseCode,
        int $durationMs,
        array $meta = [],
        int $attempt = 0
    ) {
        $this->url          = $url;
        $this->payload      = $payload;
        $this->response     = $response;
        $this->responseCode = $responseCode;
        $this->durationMs   = $durationMs;
        $this->meta         = $meta;
        $this->attempt      = $attempt;
    }
}

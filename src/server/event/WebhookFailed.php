<?php
declare(strict_types=1);

namespace wise\webhook\server\event;

/**
 * Webhook 发送失败事件
 *
 * HTTP 响应非 2xx 或网络错误时触发。
 */
class WebhookFailed
{
    /** @var string 目标 URL */
    public string $url;

    /** @var array 请求体 */
    public array $payload;

    /** @var string 错误信息 */
    public string $error;

    /** @var int|null HTTP 响应码（网络错误时可能为 null） */
    public ?int $responseCode;

    /** @var int 当前重试次数 */
    public int $attempt;

    /** @var int 最大重试次数 */
    public int $maxAttempts;

    /** @var bool 是否还有重试机会 */
    public bool $willRetry;

    /** @var array 元数据 */
    public array $meta;

    /**
     * @param string   $url          目标 URL
     * @param array    $payload      请求体
     * @param string   $error        错误信息
     * @param int|null $responseCode HTTP 响应码
     * @param int      $attempt      当前重试次数
     * @param int      $maxAttempts  最大重试次数
     * @param bool     $willRetry    是否还有重试机会
     * @param array    $meta         元数据
     */
    public function __construct(
        string $url,
        array $payload,
        string $error,
        ?int $responseCode,
        int $attempt,
        int $maxAttempts,
        bool $willRetry,
        array $meta = []
    ) {
        $this->url          = $url;
        $this->payload      = $payload;
        $this->error        = $error;
        $this->responseCode = $responseCode;
        $this->attempt      = $attempt;
        $this->maxAttempts  = $maxAttempts;
        $this->willRetry    = $willRetry;
        $this->meta         = $meta;
    }
}

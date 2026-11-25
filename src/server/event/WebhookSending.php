<?php
declare(strict_types=1);

namespace wise\webhook\server\event;

/**
 * Webhook 发送前事件
 *
 * 在 HTTP 请求发出前触发，可用于日志记录或最后修改 payload。
 */
class WebhookSending
{
    /** @var string 目标 URL */
    public string $url;

    /** @var array 请求体 */
    public array $payload;

    /** @var array 请求头 */
    public array $headers;

    /** @var array 元数据 */
    public array $meta;

    /** @var int 当前重试次数 */
    public int $attempt;

    /**
     * @param string $url      目标 URL
     * @param array  $payload  请求体
     * @param array  $headers  请求头
     * @param array  $meta     元数据
     * @param int    $attempt  重试次数
     */
    public function __construct(
        string $url,
        array $payload,
        array $headers = [],
        array $meta = [],
        int $attempt = 0
    ) {
        $this->url     = $url;
        $this->payload = $payload;
        $this->headers = $headers;
        $this->meta    = $meta;
        $this->attempt = $attempt;
    }
}

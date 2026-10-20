<?php
declare(strict_types=1);

namespace wise\webhook\server\model;

use think\Model;

/**
 * Webhook 发送记录模型
 *
 * 对应 webhook_sends 表，记录每次对外发送的 Webhook 请求详情。
 * payload / headers / meta 字段为 text 类型存储的 JSON（模型自动序列化）。
 *
 * @property int    $id
 * @property string $url            目标 URL
 * @property string $method         HTTP 方法
 * @property array  $payload        请求体 JSON
 * @property array  $headers        请求头 JSON
 * @property string $signature      签名值
 * @property string $status         状态：pending/sending/success/failed
 * @property int    $response_code  HTTP 响应码
 * @property string $response_body  响应体
 * @property string $error_message  错误信息
 * @property int    $attempt        当前重试次数
 * @property int    $max_attempts   最大重试次数
 * @property int    $duration_ms    请求耗时（毫秒）
 * @property array  $meta           元数据 JSON
 * @property string $create_time    创建时间
 * @property string $update_time    更新时间
 */
class WebhookSendModel extends Model
{
    /** @var string 表名（框架自动添加 ws_ 前缀） */
    protected $name = 'webhook_sends';

    /** @var bool 自动时间戳 */
    protected $autoWriteTimestamp = true;

    /** @var string 创建时间字段 */
    protected $createTime = 'create_time';

    /** @var string 更新时间字段 */
    protected $updateTime = 'update_time';

    /** @var string[] JSON 类型字段（自动序列化/反序列化） */
    protected $json = ['payload', 'headers', 'meta'];

    /** @var string[] 字段默认值 */
    protected $default = [
        'method'       => 'POST',
        'status'       => 'pending',
        'attempt'      => 0,
        'max_attempts' => 3,
    ];

    /**
     * 标记为"发送中"
     *
     * 供外部手动调用（如自定义发送流程）。
     * 内置 CallWebhookJob 直接在 getOrCreateModel() 中设置 status。
     *
     * @return $this
     */
    public function markSending(): self
    {
        $this->save(['status' => 'sending']);
        return $this;
    }

    /**
     * 标记为"发送成功"
     *
     * @param int    $responseCode HTTP 响应码
     * @param string $responseBody 响应体
     * @param int    $durationMs   请求耗时（毫秒）
     * @return $this
     */
    public function markSuccess(int $responseCode, string $responseBody, int $durationMs): self
    {
        $this->save([
            'status'        => 'success',
            'response_code' => $responseCode,
            'response_body' => $responseBody,
            'duration_ms'   => $durationMs,
        ]);
        return $this;
    }

    /**
     * 标记为"发送失败"
     *
     * @param string $errorMessage 错误信息
     * @param int    $attempt      当前重试次数
     * @return $this
     */
    public function markFailed(string $errorMessage, int $attempt): self
    {
        $this->save([
            'status'        => 'failed',
            'error_message' => $errorMessage,
            'attempt'       => $attempt,
        ]);
        return $this;
    }

    /**
     * 递增重试次数
     *
     * @return $this
     */
    public function incrementAttempt(): self
    {
        $this->save(['attempt' => $this->attempt + 1]);
        return $this;
    }
}

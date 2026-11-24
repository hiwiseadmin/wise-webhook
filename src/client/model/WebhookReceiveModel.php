<?php
declare(strict_types=1);

namespace wise\webhook\client\model;

use think\Model;

/**
 * Webhook 接收记录模型
 *
 * 对应 webhook_receives 表，记录每次接收到的第三方 Webhook 请求详情。
 * payload / headers / meta 字段为 text 类型存储的 JSON（模型自动序列化）。
 *
 * @property int    $id
 * @property string $endpoint_name  端点名称
 * @property array  $payload        请求体 JSON
 * @property array  $headers        请求头 JSON
 * @property string $signature      签名值
 * @property string $status         状态：pending/processing/processed/failed/rejected
 * @property string $error_message  错误信息
 * @property int    $attempt        当前重试次数
 * @property int    $max_attempts   最大重试次数
 * @property string $source_ip      来源 IP
 * @property array  $meta           元数据 JSON
 * @property string $create_time    创建时间
 * @property string $update_time    更新时间
 * @property string $processed_at   处理完成时间
 */
class WebhookReceiveModel extends Model
{
    /** @var string 表名（框架自动添加 ws_ 前缀） */
    protected $name = 'webhook_receives';

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
        'status'       => 'pending',
        'attempt'      => 0,
        'max_attempts' => 3,
    ];

    /**
     * 标记为"处理中"
     *
     * @return $this
     */
    public function markProcessing(): self
    {
        $this->save(['status' => 'processing']);
        return $this;
    }

    /**
     * 标记为"处理完成"
     *
     * @return $this
     */
    public function markProcessed(): self
    {
        $this->save([
            'status'       => 'processed',
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
        return $this;
    }

    /**
     * 标记为"处理失败"
     *
     * @param string $errorMessage 错误信息
     * @return $this
     */
    public function markFailed(string $errorMessage): self
    {
        $this->save([
            'status'        => 'failed',
            'error_message' => $errorMessage,
        ]);
        return $this;
    }

    /**
     * 标记为"签名被拒"
     *
     * @param string $reason 拒绝原因
     * @return $this
     */
    public function markRejected(string $reason): self
    {
        $this->save([
            'status'        => 'rejected',
            'error_message' => $reason,
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

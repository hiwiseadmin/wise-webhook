<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建 Webhook 接收记录表
 *
 * 用于记录所有接收到的第三方 Webhook 请求，支持异步处理和状态追踪。
 */
class CreateWebhookReceivesTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('webhook_receives', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => 'Webhook接收记录表',
        ]);

        $table
            ->addColumn(Column::string('endpoint_name', 128)->setNull(false)->setComment('端点名称'))
            ->addColumn(Column::text('payload')->setNull(true)->setComment('请求体JSON'))
            ->addColumn(Column::text('headers')->setNull(true)->setComment('请求头JSON'))
            ->addColumn(Column::string('signature', 512)->setNull(true)->setComment('接收到的签名'))
            ->addColumn(Column::string('status', 32)->setNull(false)->setDefault('pending')->setComment('状态：pending/processing/processed/failed/rejected'))
            ->addColumn(Column::text('error_message')->setNull(true)->setComment('错误信息'))
            ->addColumn(Column::integer('attempt')->setNull(false)->setDefault(0)->setComment('当前重试次数'))
            ->addColumn(Column::integer('max_attempts')->setNull(false)->setDefault(3)->setComment('最大重试次数'))
            ->addColumn(Column::string('source_ip', 50)->setNull(true)->setComment('请求来源IP'))
            ->addColumn(Column::text('meta')->setNull(true)->setComment('元数据JSON'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setNull(true)->setComment('更新时间'))
            ->addColumn(Column::dateTime('processed_at')->setNull(true)->setComment('处理完成时间'))
            ->addIndex(['endpoint_name', 'status'], ['name' => 'idx_endpoint_status'])
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->addIndex(['create_time'], ['name' => 'idx_create_time'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('webhook_receives')) {
            $this->table('webhook_receives')->drop()->save();
        }
    }
}

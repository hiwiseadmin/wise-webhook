<?php
declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 创建 Webhook 发送记录表
 *
 * 用于记录所有对外发送的 Webhook 请求，追踪状态和重试历史。
 */
class CreateWebhookSendsTable extends Migrator
{
    public function up(): void
    {
        $table = $this->table('webhook_sends', [
            'engine'    => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment'   => 'Webhook发送记录表',
        ]);

        $table
            ->addColumn(Column::string('url', 1024)->setNull(false)->setComment('目标URL'))
            ->addColumn(Column::string('method', 10)->setNull(false)->setDefault('POST')->setComment('HTTP方法'))
            ->addColumn(Column::text('payload')->setNull(true)->setComment('请求体JSON'))
            ->addColumn(Column::text('headers')->setNull(true)->setComment('请求头JSON'))
            ->addColumn(Column::string('signature', 512)->setNull(true)->setComment('签名值'))
            ->addColumn(Column::string('status', 32)->setNull(false)->setDefault('pending')->setComment('状态：pending/sending/success/failed'))
            ->addColumn(Column::integer('response_code')->setNull(true)->setComment('HTTP响应码'))
            ->addColumn(Column::text('response_body')->setNull(true)->setComment('响应体'))
            ->addColumn(Column::text('error_message')->setNull(true)->setComment('错误信息'))
            ->addColumn(Column::integer('attempt')->setNull(false)->setDefault(0)->setComment('当前重试次数'))
            ->addColumn(Column::integer('max_attempts')->setNull(false)->setDefault(3)->setComment('最大重试次数'))
            ->addColumn(Column::integer('duration_ms')->setNull(true)->setComment('请求耗时(毫秒)'))
            ->addColumn(Column::text('meta')->setNull(true)->setComment('元数据JSON'))
            ->addColumn(Column::dateTime('create_time')->setNull(true)->setComment('创建时间'))
            ->addColumn(Column::dateTime('update_time')->setNull(true)->setComment('更新时间'))
            ->addIndex(['status'], ['name' => 'idx_status'])
            ->addIndex(['create_time'], ['name' => 'idx_create_time'])
            ->addIndex(['status', 'create_time'], ['name' => 'idx_status_time'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('webhook_sends')) {
            $this->table('webhook_sends')->drop()->save();
        }
    }
}

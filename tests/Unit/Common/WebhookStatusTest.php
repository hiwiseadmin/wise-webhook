<?php
declare(strict_types=1);

namespace wise\webhook\tests\Unit\Common;

use wise\webhook\enums\WebhookStatus;
use wise\webhook\tests\TestCase;

/**
 * WebhookStatus 枚举/常量单元测试
 */
class WebhookStatusTest extends TestCase
{
    public function testConstants(): void
    {
        $this->assertSame('pending', WebhookStatus::PENDING);
        $this->assertSame('sending', WebhookStatus::SENDING);
        $this->assertSame('processing', WebhookStatus::PROCESSING);
        $this->assertSame('success', WebhookStatus::SUCCESS);
        $this->assertSame('processed', WebhookStatus::PROCESSED);
        $this->assertSame('failed', WebhookStatus::FAILED);
        $this->assertSame('rejected', WebhookStatus::REJECTED);
    }

    public function testServerStatuses(): void
    {
        $this->assertSame(
            [WebhookStatus::PENDING, WebhookStatus::SENDING, WebhookStatus::SUCCESS, WebhookStatus::FAILED],
            WebhookStatus::serverStatuses()
        );
    }

    public function testClientStatuses(): void
    {
        $this->assertSame(
            [
                WebhookStatus::PENDING,
                WebhookStatus::PROCESSING,
                WebhookStatus::PROCESSED,
                WebhookStatus::FAILED,
                WebhookStatus::REJECTED,
            ],
            WebhookStatus::clientStatuses()
        );
    }
}

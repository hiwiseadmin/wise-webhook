<?php
declare(strict_types=1);

namespace wise\webhook\tests\Unit\Server;

use wise\support\backoff\BackoffStrategy;
use wise\webhook\tests\TestCase;

/**
 * BackoffStrategy 单元测试
 */
class BackoffStrategyTest extends TestCase
{
    public function testLinearBackoff(): void
    {
        $strategy = BackoffStrategy::linear(1000);

        $this->assertSame(1000, $strategy->delayForAttempt(1));
        $this->assertSame(2000, $strategy->delayForAttempt(2));
        $this->assertSame(3000, $strategy->delayForAttempt(3));
        $this->assertSame(5000, $strategy->delayForAttempt(5));
    }

    public function testLinearBackoffCustomStep(): void
    {
        $strategy = BackoffStrategy::linear(500);

        $this->assertSame(500, $strategy->delayForAttempt(1));
        $this->assertSame(1500, $strategy->delayForAttempt(3));
    }

    public function testExponentialBackoff(): void
    {
        $strategy = BackoffStrategy::exponential(1000, 60000);

        $this->assertSame(1000, $strategy->delayForAttempt(1));
        $this->assertSame(2000, $strategy->delayForAttempt(2));
        $this->assertSame(4000, $strategy->delayForAttempt(3));
        $this->assertSame(8000, $strategy->delayForAttempt(4));
        $this->assertSame(16000, $strategy->delayForAttempt(5));
    }

    public function testExponentialBackoffWithMaxCap(): void
    {
        $strategy = BackoffStrategy::exponential(1000, 5000);

        $this->assertSame(1000, $strategy->delayForAttempt(1));
        $this->assertSame(2000, $strategy->delayForAttempt(2));
        $this->assertSame(4000, $strategy->delayForAttempt(3));
        $this->assertSame(5000, $strategy->delayForAttempt(4)); // capped
        $this->assertSame(5000, $strategy->delayForAttempt(10)); // stays capped
    }

    public function testFixedBackoff(): void
    {
        $strategy = BackoffStrategy::fixed(5000);

        $this->assertSame(5000, $strategy->delayForAttempt(1));
        $this->assertSame(5000, $strategy->delayForAttempt(2));
        $this->assertSame(5000, $strategy->delayForAttempt(10));
    }

    public function testCustomBackoff(): void
    {
        $strategy = BackoffStrategy::custom([1000, 5000, 30000]);

        $this->assertSame(1000, $strategy->delayForAttempt(1));
        $this->assertSame(5000, $strategy->delayForAttempt(2));
        $this->assertSame(30000, $strategy->delayForAttempt(3));
        // 超出数组用最后一个值
        $this->assertSame(30000, $strategy->delayForAttempt(4));
        $this->assertSame(30000, $strategy->delayForAttempt(10));
    }

    public function testCustomBackoffEmptyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BackoffStrategy::custom([]);
    }

    public function testDelayForAttemptZeroOrNegative(): void
    {
        $strategy = BackoffStrategy::linear(1000);

        $this->assertSame(0, $strategy->delayForAttempt(0));
        $this->assertSame(0, $strategy->delayForAttempt(-1));
    }

    public function testGetMethod(): void
    {
        $this->assertSame('linear', BackoffStrategy::linear()->getMethod());
        $this->assertSame('exponential', BackoffStrategy::exponential()->getMethod());
        $this->assertSame('fixed', BackoffStrategy::fixed()->getMethod());
        $this->assertSame('custom', BackoffStrategy::custom([1000])->getMethod());
    }
}

<?php
declare(strict_types=1);

namespace wise\webhook\tests;

/**
 * 无副作用的日志器（测试替身）
 *
 * 仅用于满足 wise-support ContextualLogger 经 think\Log 门面发起的调用，
 * 避免测试触发真实文件系统写入。
 */
class NullLogger
{
    public function __call($name, $args)
    {
        return $this;
    }

    public function info($msg, $context = []): void {}
    public function error($msg, $context = []): void {}
    public function warning($msg, $context = []): void {}
    public function debug($msg, $context = []): void {}
    public function notice($msg, $context = []): void {}
    public function alert($msg, $context = []): void {}
    public function critical($msg, $context = []): void {}
    public function emergency($msg, $context = []): void {}
    public function sql($msg, $context = []): void {}

    public function channel($name = null)
    {
        return $this;
    }

    public function record($msg, $context = []): void {}
    public function write($msg, $context = []): void {}
    public function save(): void {}
    public function clear(): void {}
    public function getLog(): array
    {
        return [];
    }
}

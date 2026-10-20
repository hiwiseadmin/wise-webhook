<?php
declare(strict_types=1);

namespace wise\webhook\tests;

use think\Request;

/**
 * 测试用 Request 替身
 *
 * 仅作为 think\Request 的轻量子类，供单元测试构造请求实例使用。
 * 生产源码注入端点元数据已改用 setRoute()（等价且被 param() 合并读取），
 * 故此处无需额外提供 withParam()。
 */
class TestingRequest extends Request
{
}

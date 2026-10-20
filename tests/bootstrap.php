<?php
/**
 * PHPUnit 引导文件
 *
 * 自动加载 Composer autoload（从项目根目录），
 * 为 wise-webhook 测试环境做准备。
 */

// 在多个候选位置查找 Composer autoload（兼容不同项目布局）。
$candidates = [
    dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php', // 仓库根 vendor
    dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php', // src/vendor（monorepo 布局）
    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php', // 包自身 vendor
];

$autoloadLoaded = false;
foreach ($candidates as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        $autoloadLoaded = true;
        break;
    }
}

// 测试命名空间自动加载（必须在使用 TestingRequest / NullLogger 之前注册）。
spl_autoload_register(function (string $class): void {
    $prefix = 'wise\\webhook\\tests\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) === 0) {
        $relative = substr($class, $len);
        $file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// 加载 ThinkPHP 框架助手函数（提供 app()/config()/request() 等全局函数，
// 其并不在框架的 composer autoload.files 中，需手动引入）。
if (!function_exists('config')) {
    $frameworkHelper = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'vendor'
        . DIRECTORY_SEPARATOR . 'topthink' . DIRECTORY_SEPARATOR . 'framework'
        . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'helper.php';
    if (file_exists($frameworkHelper)) {
        require_once $frameworkHelper;
    }
}

// 引导一个最小 ThinkPHP 容器，注册核心服务（config / event / cache / queue 等门面绑定），
// 使被测代码中的 config()、Event::trigger、app()->make() 等可在 CLI 下工作。
if (class_exists('think\\App')) {
    try {
        $app = new \think\App();
        \think\facade\Facade::setFacadeApplication($app);

        // 'log' 在框架中绑定到一个会在 make 时重新注册的 ServiceProvider，
        // 无法通过容器 bind 覆盖；改为配置一个可写入的 file 通道，避免 NULL driver 报错。
        config([
            'log' => [
                'default'  => 'file',
                'channels' => [
                    'file' => [
                        'type' => 'File',
                        'path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise-webhook-test.log',
                    ],
                ],
            ],
        ]);

        // 'request' 默认绑定为普通类（非 ServiceProvider），可用容器 bind 安全覆盖。
        // 解析为测试用 Request 子类（供 makeRequest 等构造请求实例使用）。
        bind('request', \wise\webhook\tests\TestingRequest::class);
    } catch (\Throwable $e) {
        // 引导失败时忽略，由具体测试自行处理依赖
    }
}

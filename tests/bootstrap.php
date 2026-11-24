<?php
/**
 * PHPUnit 引导文件
 *
 * 自动加载 Composer autoload（从项目根目录），
 * 为 wise-webhook 测试环境做准备。
 */

$candidates = [
    dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
    dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
    dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php',
];

$autoloadLoaded = false;
foreach ($candidates as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        $autoloadLoaded = true;
        break;
    }
}

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

if (!function_exists('config')) {
    $frameworkHelper = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'vendor'
        . DIRECTORY_SEPARATOR . 'topthink' . DIRECTORY_SEPARATOR . 'framework'
        . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'helper.php';
    if (file_exists($frameworkHelper)) {
        require_once $frameworkHelper;
    }
}

if (class_exists('think\\App')) {
    try {
        $app = new \think\App();
        \think\facade\Facade::setFacadeApplication($app);

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

        bind('request', \wise\webhook\tests\TestingRequest::class);
    } catch (\Throwable $e) {
    }
}

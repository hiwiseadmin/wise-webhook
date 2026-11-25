<?php
declare(strict_types=1);

namespace wise\webhook\tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionClass;
use think\Request;
use wise\webhook\tests\TestingRequest;

/**
 * wise-webhook 测试基类
 *
 * 提供可复用的 Request 构造辅助（ThinkPHP 8 的 think\Request 无静态 create() 工厂）。
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();


        if (function_exists('config')) {
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
        }
        if (function_exists('bind')) {
            bind('request', \wise\webhook\tests\TestingRequest::class);
        }
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    /**
     * 构造一个 think\Request 测试替身
     *
     * 通过反射填充 content / header / param / route 等受保护属性，
     * 以覆盖 WebhookReceiver、Middleware、Validator 等的使用场景。
     *
     * @param string $url      请求 URL（仅用于语义，不发起真实连接）
     * @param string $method   HTTP 方法
     * @param array  $params   请求参数（param / route 共用）
     * @param array  $headers  请求头（键不区分大小写）
     * @param string $rawBody  原始请求体（用于 HMAC 校验）
     * @return Request
     */
    protected function makeRequest(
        string $url = 'https://example.com/webhooks',
        string $method = 'POST',
        array $params = [],
        array $headers = [],
        string $rawBody = ''
    ): TestingRequest {
        $request = new TestingRequest();
        $request->setMethod($method);

        $ref = new ReflectionClass($request);

        if ($rawBody !== '') {
            $contentProp = $ref->getProperty('content');
            $contentProp->setAccessible(true);
            $contentProp->setValue($request, $rawBody);
        }

        if ($headers !== []) {
            $request->withHeader($headers);
        }

        if ($params !== []) {
            foreach (['param', 'route', 'get', 'post'] as $key) {
                $prop = $ref->getProperty($key);
                $prop->setAccessible(true);
                $prop->setValue($request, array_merge($prop->getValue($request) ?? [], $params));
            }
        }

        return $request;
    }

    /**
     * 在运行时注入 wise-webhook 配置（覆盖 config()）。
     *
     * @param array $config 完整 wise-webhook 配置树
     */
    protected function setWebhookConfig(array $config): void
    {
        config(['wise-webhook' => $config]);
    }

    /**
     * 调用被测对象的受保护/私有方法（反射）。
     *
     * @param object $object
     * @param string $method
     * @param array  $args
     * @return mixed
     */
    protected function invokeInaccessible(object $object, string $method, array $args = [])
    {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($object, $args);
    }

    /**
     * 读取被测对象的受保护/私有属性（反射）。
     *
     * @param object $object
     * @param string $property
     * @return mixed
     */
    protected function getInaccessibleProperty(object $object, string $property)
    {
        $ref = new \ReflectionClass($object);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($object);
    }

    /**
     * 设置被测对象的受保护/私有属性（反射）。
     *
     * @param object $object
     * @param string $property
     * @param mixed  $value
     */
    protected function setInaccessibleProperty(object $object, string $property, $value): void
    {
        $ref = new \ReflectionClass($object);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }
}

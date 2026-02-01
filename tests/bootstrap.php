<?php

namespace nedarta\behaviors\tests;

// Ensure we have the composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

// Initialize Yii app for tests if not already done
class TestCase extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mockApplication();
    }

    protected function tearDown(): void
    {
        $this->destroyApplication();
        parent::tearDown();
    }

    protected function mockApplication()
    {
        new \yii\console\Application([
            'id' => 'testapp',
            'basePath' => __DIR__,
            'vendorPath' => dirname(__DIR__) . '/vendor',
            'components' => [
                'db' => [
                    'class' => 'yii\db\Connection',
                    'dsn' => 'sqlite::memory:',
                ],
                'user' => [
                    'class' => 'yii\web\User',
                    'identityClass' => 'nedarta\behaviors\tests\UserIdentity', 
                ]
            ]
        ]);
    }

    protected function destroyApplication()
    {
        \Yii::$app = null;
        restore_error_handler();
        restore_exception_handler();
    }
}

<?php

use Yangweijie\ThinkOrmAsync\AsyncContext;
use Yangweijie\ThinkOrmAsync\AsyncResultPlaceholder;

// 配置测试数据库
$dbConfig = [
    'hostname' => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => 'mysql',
    'hostport' => 3306,
    'charset' => 'utf8mb4',
];

beforeEach(function () use ($dbConfig) {
    // 确保每个测试从干净的状态开始
    if (AsyncContext::isActive()) {
        try {
            AsyncContext::end();
        } catch (\Exception $e) {
            // 如果 end 失败，使用反射清理
            $reflection = new \ReflectionClass(AsyncContext::class);
            $active = $reflection->getProperty('active');
            $active->setAccessible(true);
            $active->setValue(null, false);
            
            $instance = $reflection->getProperty('instance');
            $instance->setAccessible(true);
            $instance->setValue(null, null);
        }
    }
});

test('raw query returns correct data', function () use ($dbConfig) {
    AsyncContext::start(null, $dbConfig);
    
    $result = AsyncContext::query("SELECT 1 as test, 'hello' as message");
    
    $results = AsyncContext::end();
    
    expect($results)->toBeArray();
    expect($results)->toHaveCount(1);
    expect($results[$result->key])->toBeArray();
    expect($results[$result->key][0])->toBeArray();
    expect($results[$result->key][0]['test'])->toBe(1);
    expect($results[$result->key][0]['message'])->toBe('hello');
})->skip('requires database connection');

test('multiple raw queries execute in parallel', function () use ($dbConfig) {
    AsyncContext::start(null, $dbConfig);
    
    $result1 = AsyncContext::query("SELECT 1 as id");
    $result2 = AsyncContext::query("SELECT 2 as id");
    $result3 = AsyncContext::query("SELECT 3 as id");
    
    $results = AsyncContext::end();
    
    expect($results)->toHaveCount(3);
    expect($results[$result1->key][0]['id'])->toBe(1);
    expect($results[$result2->key][0]['id'])->toBe(2);
    expect($results[$result3->key][0]['id'])->toBe(3);
})->skip('requires database connection');

test('raw query with custom key works', function () use ($dbConfig) {
    AsyncContext::start(null, $dbConfig);
    
    $result = AsyncContext::query("SELECT 'test' as value", 'my_custom_key');
    
    $results = AsyncContext::end();
    
    expect($results)->toHaveKey('my_custom_key');
    expect($results['my_custom_key'][0]['value'])->toBe('test');
})->skip('requires database connection');

test('raw query placeholder lazy loads result', function () use ($dbConfig) {
    AsyncContext::start(null, $dbConfig);
    
    $result = AsyncContext::query("SELECT 'hello' as message");
    
    // 在调用 AsyncContext::end() 之前，result 是一个占位符
    expect($result)->toBeInstanceOf(AsyncResultPlaceholder::class);
    
    $results = AsyncContext::end();
    
    // 现在 result 可以访问数据
    expect($result->first()['message'])->toBe('hello');
    expect($result->toArray())->toBe($results[$result->key]);
})->skip('requires database connection');

test('raw query returns array for SELECT queries', function () use ($dbConfig) {
    AsyncContext::start(null, $dbConfig);
    
    $result = AsyncContext::query("SELECT 1 as num");
    
    $results = AsyncContext::end();
    
    expect($results[$result->key])->toBeArray();
    expect($results[$result->key][0]['num'])->toBe(1);
})->skip('requires database connection');
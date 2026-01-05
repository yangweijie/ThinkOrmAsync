<?php

use Yangweijie\ThinkOrmAsync\AsyncContext;
use Yangweijie\ThinkOrmAsync\AsyncResultPlaceholder;

beforeEach(function () {
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

test('query returns placeholder in async context', function () {
    AsyncContext::start();
    
    $result = AsyncContext::query("SELECT 1 as test");
    
    expect($result)->toBeInstanceOf(AsyncResultPlaceholder::class);
    
    // 使用反射清理异步上下文（不执行查询）
    $reflection = new \ReflectionClass(AsyncContext::class);
    $active = $reflection->getProperty('active');
    $active->setAccessible(true);
    $active->setValue(null, false);
    
    $instance = $reflection->getProperty('instance');
    $instance->setAccessible(true);
    $instance->setValue(null, null);
});

test('query throws exception when context not started', function () {
    expect(fn() => AsyncContext::query("SELECT 1"))
        ->toThrow(\RuntimeException::class, 'Async context not started');
});

test('query with custom key', function () {
    AsyncContext::start();
    
    $result = AsyncContext::query("SELECT 1 as test", 'custom_key');
    
    expect($result)->toBeInstanceOf(AsyncResultPlaceholder::class);
    
    // 使用反射清理异步上下文（不执行查询）
    $reflection = new \ReflectionClass(AsyncContext::class);
    $active = $reflection->getProperty('active');
    $active->setAccessible(true);
    $active->setValue(null, false);
    
    $instance = $reflection->getProperty('instance');
    $instance->setAccessible(true);
    $instance->setValue(null, null);
});

test('multiple queries can be registered', function () {
    AsyncContext::start();
    
    $result1 = AsyncContext::query("SELECT 1 as test");
    $result2 = AsyncContext::query("SELECT 2 as test");
    $result3 = AsyncContext::query("SELECT 3 as test");
    
    expect($result1)->toBeInstanceOf(AsyncResultPlaceholder::class);
    expect($result2)->toBeInstanceOf(AsyncResultPlaceholder::class);
    expect($result3)->toBeInstanceOf(AsyncResultPlaceholder::class);
    
    // 使用反射清理异步上下文（不执行查询）
    $reflection = new \ReflectionClass(AsyncContext::class);
    $active = $reflection->getProperty('active');
    $active->setAccessible(true);
    $active->setValue(null, false);
    
    $instance = $reflection->getProperty('instance');
    $instance->setAccessible(true);
    $instance->setValue(null, null);
});
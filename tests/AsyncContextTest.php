<?php

use Yangweijie\ThinkOrmAsync\AsyncContext;

beforeEach(function () {
    // 确保每个测试从干净的状态开始
    if (AsyncContext::isActive()) {
        AsyncContext::end();
    }
});

test('start creates active context', function () {
    AsyncContext::start();
    
    expect(AsyncContext::isActive())->toBeTrue();
    
    AsyncContext::end();
});

test('end executes and returns results', function () {
    AsyncContext::start();
    
    AsyncContext::getInstance()->setResult('test', ['data' => 'test']);
    
    $results = AsyncContext::end();
    
    expect($results)->toHaveKey('test');
    expect(AsyncContext::isActive())->toBeFalse();
});

test('throws exception on nested start', function () {
    AsyncContext::start();
    
    AsyncContext::start();
})->throws(\RuntimeException::class);

test('throws exception on end without start', function () {
    AsyncContext::end();
})->throws(\RuntimeException::class);

test('sets timeout', function () {
    $context = AsyncContext::start();
    
    expect($context->setTimeout(15))->toBe($context);
    
    AsyncContext::end();
});

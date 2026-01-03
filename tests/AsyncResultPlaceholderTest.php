<?php

use Yangweijie\ThinkOrmAsync\AsyncResultPlaceholder;
use Yangweijie\ThinkOrmAsync\AsyncContext;

beforeEach(function () {
    AsyncContext::start();
    AsyncContext::getInstance()->setResult('test_key', ['name' => 'Test', 'email' => 'test@example.com']);
    $this->placeholder = new AsyncResultPlaceholder('test_key', 'find');
});

afterEach(function () {
    AsyncContext::end();
});

test('lazy loads result on property access', function () {
    expect($this->placeholder->name)->toBe('Test');
});

test('implements ArrayAccess', function () {
    expect(isset($this->placeholder['name']))->toBeTrue();
    expect($this->placeholder['name'])->toBe('Test');
});

test('implements Countable', function () {
    expect(count($this->placeholder))->toBe(2);
});

test('implements Iterator', function () {
    $count = 0;
    foreach ($this->placeholder as $key => $value) {
        $count++;
    }
    
    expect($count)->toBeGreaterThan(0);
});

test('toArray returns array', function () {
    $array = $this->placeholder->toArray();
    
    expect($array)->toBeArray();
    expect($array['name'])->toBe('Test');
});

test('throws error on failed query', function () {
    AsyncContext::getInstance()->setResult('error_key', [
        'error' => 'Table not found',
        'code' => 1146,
    ]);
    
    $errorPlaceholder = new AsyncResultPlaceholder('error_key', 'find');
    
    expect(fn() => $errorPlaceholder->name)->toThrow(\Exception::class);
});

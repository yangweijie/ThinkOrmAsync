<?php

use Yangweijie\ThinkOrmAsync\AsyncContext;
use Yangweijie\ThinkOrmAsync\AsyncModelTrait;
use Yangweijie\ThinkOrmAsync\AsyncResultPlaceholder;

class TestModel extends \think\Model {
    use AsyncModelTrait;
}

test('find returns placeholder in async context', function () {
    AsyncContext::start();
    
    $result = TestModel::where('id', 1)->find();
    
    expect($result)->toBeInstanceOf(AsyncResultPlaceholder::class);
    
    AsyncContext::end();
});

test('select returns placeholder in async context', function () {
    AsyncContext::start();
    
    $result = TestModel::where('status', 1)->select();
    
    expect($result)->toBeInstanceOf(AsyncResultPlaceholder::class);
    
    AsyncContext::end();
});

test('find calls parent in sync context', function () {
    $query = TestModel::where('id', 1);
    
    expect($query)->toBeInstanceOf(\think\db\BaseQuery::class);
});

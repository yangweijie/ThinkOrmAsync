<?php
require __DIR__ . '/../vendor/autoload.php';

use Yangweijie\ThinkOrmAsync\AsyncContext;
use Yangweijie\ThinkOrmAsync\AsyncModelTrait;

class User extends \think\Model {
    use AsyncModelTrait;
}

// 开始异步上下文（自动从 ThinkPHP 配置读取数据库配置）
AsyncContext::start();

    $user = User::where('id', 1)->find();

try {
    $results = AsyncContext::end();
    
    if (isset($results['user'])) {
        echo "User: " . $results['user']->name . "\n";
    }
} catch (\Yangweijie\ThinkOrmAsync\Exception\AsyncQueryException $e) {
    echo "Query failed after reconnection: " . $e->getMessage() . "\n";
}

<?php
require __DIR__ . '/../vendor/autoload.php';

use Yangweijie\ThinkOrmAsync\AsyncContext;

// 配置数据库连接（可选）
// 如果不传递配置，会自动从 ThinkPHP 配置中读取
// $dbConfig = [
//     'hostname' => 'localhost',
//     'database' => 'test',
//     'username' => 'root',
//     'password' => '',
//     'hostport' => '3306',
//     'charset' => 'utf8mb4',
// ];

\think\facade\Db::setConfig([
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'type' => 'mysql',
            'hostname' => 'localhost',
            'database' => 'test',
            'username' => 'root',
            'password' => '',
            'hostport' => '3306',
            'charset' => 'utf8mb4',
        ],
    ],
]);

// 开始异步上下文，不传递配置（自动从 ThinkPHP 配置读取）
AsyncContext::start(null);

    // 原生查询 - 使用 AsyncContext::query()
    $result1 = AsyncContext::query("SELECT sleep(1) as wait_time");
    $result2 = AsyncContext::query("SELECT sleep(1) as wait_time");
    $result3 = AsyncContext::query("SELECT NOW() as `current_time`");
    
    // 也可以混合使用 ORM 查询
    // $users = User::where('status', 1)->select();

// 结束异步上下文，所有查询会并行执行
$results = AsyncContext::end();

// 获取结果
echo "Result 1: ";
var_dump($result1->toArray());

echo "Result 2: ";
var_dump($result2->toArray());

echo "Result 3: ";
var_dump($result3->toArray());

echo "Total execution time: ~1 second (parallel execution)" . PHP_EOL;

// 注意：不要再次调用 AsyncContext::end()，否则会抛出"Async context not started"错误

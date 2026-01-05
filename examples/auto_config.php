<?php
require __DIR__ . '/../vendor/autoload.php';

use Yangweijie\ThinkOrmAsync\AsyncContext;
use Yangweijie\ThinkOrmAsync\AsyncModelTrait;

// 配置数据库连接（ThinkPHP 配置）
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

class Action extends \think\Model {
    use AsyncModelTrait;
    protected $name = 'admin_action';
    protected $prefix = 'dp_';
}

echo "=== 自动配置示例 ===\n\n";

// 方式 1: 不传递配置（自动从 ThinkPHP 配置读取）
echo "方式 1: 不传递配置\n";
AsyncContext::start(null);
    $result1 = AsyncContext::query("SELECT sleep(1) as wait_time");
    $result2 = AsyncContext::query("SELECT sleep(1) as wait_time");
$results = AsyncContext::end();

echo "✓ 查询完成\n\n";

// 方式 2: 传递配置（手动指定）
echo "方式 2: 传递配置\n";
$dbConfig = [
    'hostname' => 'localhost',
    'database' => 'test',
    'username' => 'root',
    'password' => '',
    'hostport' => '3306',
    'charset' => 'utf8mb4',
];

AsyncContext::start(null, $dbConfig);
    $result3 = Action::find(3);
$results = AsyncContext::end();

echo "✓ 查询完成\n\n";

echo "=== 使用建议 ===\n";
echo "1. 在 ThinkPHP 项目中，直接使用 AsyncContext::start() 不传配置\n";
echo "2. 在非 ThinkPHP 项目中，需要手动传递配置\n";
echo "3. 配置会自动从 config('database.connections.mysql') 读取\n";

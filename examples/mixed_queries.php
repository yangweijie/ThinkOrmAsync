<?php
require __DIR__ . '/../vendor/autoload.php';

use Yangweijie\ThinkOrmAsync\AsyncContext;
use Yangweijie\ThinkOrmAsync\AsyncModelTrait;

// 配置数据库连接
$dbConfig = [
    'hostname' => 'localhost',
    'database' => 'test',
    'username' => 'root',
    'password' => '',
    'hostport' => '3306',
    'charset' => 'utf8mb4',
];

\think\facade\Db::setConfig([
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'type' => 'mysql',
            'hostname' => $dbConfig['hostname'],
            'database' => $dbConfig['database'],
            'username' => $dbConfig['username'],
            'password' => $dbConfig['password'],
            'hostport' => $dbConfig['hostport'],
            'charset' => $dbConfig['charset'],
        ],
    ],
]);

class User extends \think\Model {
    use AsyncModelTrait;
}

class Product extends \think\Model {
    use AsyncModelTrait;
}

// 开始异步上下文，传递数据库配置
AsyncContext::start(null, $dbConfig);

    // 混合使用 ORM 查询和原生查询
    // 所有查询会并行执行，总时间约等于最慢的查询时间
    
    // ORM 查询
    $users = User::where('status', 1)->select();
    $products = Product::where('stock', '>', 0)->select();
    
    // 原生查询 - 适合复杂查询或性能优化
    $stats = AsyncContext::query("
        SELECT 
            COUNT(*) as total_users,
            AVG(age) as avg_age
        FROM users
        WHERE status = 1
    ");
    
    $slowQuery = AsyncContext::query("SELECT sleep(1) as wait_time");
    
    // 另一个原生查询
    $recentOrders = AsyncContext::query("
        SELECT * FROM orders 
        WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        LIMIT 10
    ");

// 结束异步上下文，所有查询并行执行
$results = AsyncContext::end();

// 获取结果
echo "=== Users ===\n";
foreach ($users as $user) {
    echo "- {$user->name}\n";
}

echo "\n=== Products ===\n";
foreach ($products as $product) {
    echo "- {$product->name} (Stock: {$product->stock})\n";
}

echo "\n=== Statistics ===\n";
$statsData = $stats->first();
echo "Total Users: {$statsData['total_users']}\n";
echo "Average Age: {$statsData['avg_age']}\n";

echo "\n=== Slow Query Result ===\n";
$slowResult = $slowQuery->first();
echo "Wait time: {$slowResult['wait_time']} seconds\n";

echo "\n=== Recent Orders ===\n";
foreach ($recentOrders as $order) {
    echo "- Order #{$order['id']}: {$order['total']}\n";
}

echo "\n所有查询并行执行，总耗时约 1 秒（而不是 4 秒）\n";

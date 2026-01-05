<?php
/**
 * 原生查询使用示例
 * 
 * AsyncContext::query() 方法支持原生 SQL 查询，
 * 并使用 mysqli_poll 实现异步并行执行
 */

require __DIR__ . '/../vendor/autoload.php';

use Yangweijie\ThinkOrmAsync\AsyncContext;

// ============================================
// 使用场景 1: 简单的原生查询
// ============================================

AsyncContext::start();

    // 多个 sleep 查询会并行执行
    $result1 = AsyncContext::query("SELECT sleep(1) as wait_time");
    $result2 = AsyncContext::query("SELECT sleep(1) as wait_time");

$results = AsyncContext::end();

// 总耗时约 1 秒（串行执行需要 2 秒）
echo "Wait time 1: {$result1->first()['wait_time']} seconds\n";
echo "Wait time 2: {$result2->first()['wait_time']} seconds\n";

// ============================================
// 使用场景 2: 使用自定义 key
// ============================================

AsyncContext::start();

    // 使用自定义 key 方便后续获取结果
    $userCount = AsyncContext::query("SELECT COUNT(*) as count FROM users", 'user_count');
    $productCount = AsyncContext::query("SELECT COUNT(*) as count FROM products", 'product_count');

$results = AsyncContext::end();

// 从 results 数组中直接获取
echo "User count: {$results['user_count'][0]['count']}\n";
echo "Product count: {$results['product_count'][0]['count']}\n";

// ============================================
// 使用场景 3: 复杂的统计查询
// ============================================

AsyncContext::start();

    // 原生查询适合复杂的统计或聚合查询
    $stats = AsyncContext::query("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as orders,
            SUM(total) as revenue
        FROM orders
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ");

$results = AsyncContext::end();

echo "=== Daily Statistics ===\n";
foreach ($stats as $row) {
    echo "{$row['date']}: {$row['orders']} orders, ¥{$row['revenue']} revenue\n";
}

// ============================================
// 使用场景 4: INSERT/UPDATE/DELETE 查询
// ============================================

AsyncContext::start();

    // 执行类查询会返回受影响的行数
    $insertResult = AsyncContext::query("INSERT INTO logs (message) VALUES ('test')");
    $updateResult = AsyncContext::query("UPDATE users SET last_login = NOW() WHERE id = 1");

$results = AsyncContext::end();

echo "Insert affected rows: {$results[$insertResult->key]['affected_rows']}\n";
echo "Insert ID: {$results[$insertResult->key]['insert_id']}\n";
echo "Update affected rows: {$results[$updateResult->key]['affected_rows']}\n";

// ============================================
// 使用场景 5: 混合 ORM 和原生查询
// ============================================

class User extends \think\Model {
    use \Yangweijie\ThinkOrmAsync\AsyncModelTrait;
}

AsyncContext::start();

    // ORM 查询
    $users = User::where('status', 1)->select();
    
    // 原生查询 - 统计
    $stats = AsyncContext::query("SELECT COUNT(*) as count, AVG(score) as avg_score FROM users WHERE status = 1");
    
    // 原生查询 - 获取最新日志
    $logs = AsyncContext::query("SELECT * FROM logs ORDER BY id DESC LIMIT 10");

$results = AsyncContext::end();

echo "Users: " . count($users) . "\n";
echo "Stats: " . json_encode($stats->first()) . "\n";
echo "Logs: " . count($logs) . "\n";

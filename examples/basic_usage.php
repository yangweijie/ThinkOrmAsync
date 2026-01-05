<?php
require __DIR__ . '/../vendor/autoload.php';

use Yangweijie\ThinkOrmAsync\AsyncContext;
use Yangweijie\ThinkOrmAsync\AsyncModelTrait;

class User extends \think\Model {
    use AsyncModelTrait;
}

class Order extends \think\Model {
    use AsyncModelTrait;
}

class Product extends \think\Model {
    use AsyncModelTrait;
}

// 开始异步上下文（自动从 ThinkPHP 配置读取数据库配置）
AsyncContext::start();

    $user = User::where('id', 1)->find();
    $orders = Order::where('user_id', 1)->select();
    $products = Product::where('status', 1)->select();

$results = AsyncContext::end();

if (isset($results['user'])) {
    echo "User: " . $results['user']->name . "\n";
}

if (isset($results['orders']) && is_array($results['orders'])) {
    foreach ($results['orders'] as $order) {
        echo "Order: " . $order->order_no . "\n";
    }
}

if (isset($results['products']) && is_array($results['products'])) {
    echo "Products count: " . count($results['products']) . "\n";
}

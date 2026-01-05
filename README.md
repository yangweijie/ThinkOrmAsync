# ThinkOrmAsync - think-orm 异步批量查询扩展

## 功能特性

- ✅ 透明的异步查询 API（与同步代码完全一致）
- ✅ 支持 ORM 查询和原生 SQL 查询
- ✅ 自动从 ThinkPHP 配置读取数据库配置
- ✅ 自动连接重试（仅限连接错误）
- ✅ 延迟加载结果占位符
- ✅ 零侵入集成（trait + 单例）
- ✅ 非 Swoole 环境可用（纯 PHP mysqli）

## 安装

```bash
composer require yangweijie/think-orm-async
```

## 快速开始

### 1. 在模型中引入 trait

```php
<?php
namespace app\model;

use think\Model;
use Yangweijie\ThinkOrmAsync\AsyncModelTrait;

class User extends Model {
    use AsyncModelTrait;
    protected $name = 'user';
    protected $prefix = '';  // 可选：表前缀
}

class Order extends Model {
    use AsyncModelTrait;
    protected $name = 'order';
}

class Product extends Model {
    use AsyncModelTrait;
    protected $name = 'product';
}
```

### 2. 使用异步查询

```php
<?php
namespace app\controller;

use Yangweijie\ThinkOrmAsync\AsyncContext;
use app\model\User;
use app\model\Order;
use app\model\Product;

class UserController {
    public function detail($id) {
        // 开始异步上下文（自动从配置读取数据库配置）
        AsyncContext::start();
        
            // ORM 查询
            $user = User::where('id', $id)->find();
            $orders = Order::where('user_id', $id)->select();
            $products = Product::where('status', 1)->select();
            
            // 原生 SQL 查询
            $stats = AsyncContext::query("SELECT COUNT(*) as total FROM user");
        
        // 结束异步上下文，执行所有查询（并行执行）
        AsyncContext::end();
        
        // 使用结果（和同步模式完全一样）
        echo $user->name;
        
        foreach ($orders as $order) {
            echo $order->order_no;
        }
        
        echo "Products: " . count($products);
        echo "Total users: " . $stats[0]['total'];
    }
}
```

## 基本使用

### ORM 查询

```php
AsyncContext::start();

    // find 查询
    $user = User::find(1);
    
    // select 查询
    $users = User::where('status', 1)->select();
    
    // 链式查询
    $orders = Order::where('user_id', 1)
        ->order('create_time', 'desc')
        ->limit(10)
        ->select();

$results = AsyncContext::end();

// 访问结果
echo $user->name;
echo $users->count();
```

### 原生 SQL 查询

```php
AsyncContext::start();

    // 简单查询
    $result = AsyncContext::query("SELECT * FROM user WHERE id = 1");
    
    // 复杂查询
    $stats = AsyncContext::query("
        SELECT 
            COUNT(*) as total,
            AVG(age) as avg_age
        FROM user
        WHERE status = 1
    ");
    
    // 自定义 key
    $custom = AsyncContext::query("SELECT COUNT(*) FROM order", 'order_count');

$results = AsyncContext::end();

// 访问结果
echo $result[0]['name'];
echo $stats[0]['total'];
echo $custom[0]['order_count'];
```

### 混合使用 ORM 和原生查询

```php
AsyncContext::start();

    // ORM 查询
    $user = User::find(1);
    $orders = Order::where('user_id', 1)->select();
    
    // 原生查询
    $stats = AsyncContext::query("SELECT COUNT(*) as total FROM user");

$results = AsyncContext::end();

// 所有查询并行执行
```

## 配置

### 自动配置（推荐）

在 ThinkPHP 项目中，`AsyncContext::start()` 会自动从 `config('database.connections.mysql')` 读取配置：

```php
// 无需传递配置，自动读取
AsyncContext::start();
    $result = AsyncContext::query("SELECT 1");
$results = AsyncContext::end();
```

### 手动配置

在非 ThinkPHP 项目中，需要手动传递配置：

```php
$dbConfig = [
    'hostname' => 'localhost',
    'database' => 'your_database',
    'username' => 'root',
    'password' => 'password',
    'hostport' => '3306',
    'charset' => 'utf8mb4',
];

AsyncContext::start(null, $dbConfig);
    $result = AsyncContext::query("SELECT 1");
$results = AsyncContext::end();
```

### 设置超时

```php
AsyncContext::start()->setTimeout(15);

    $user = User::where('id', 1)->find();

AsyncContext::end();
```

## 重试机制

仅重试连接错误，不重试 SQL 逻辑错误。

### 可重试错误

- **2002**: Connection refused
- **2003**: Can't connect to MySQL server
- **2006**: MySQL server has gone away
- **2013**: Lost connection to MySQL server during query

### 不重试错误

- **SQL 语法错误** (1064)
- **表不存在** (1146)
- **字段不存在** (1054)
- **权限错误**
- **其他 SQL 逻辑错误**

### 重试策略

- 最多重连一次（总共最多执行 2 次）
- 固定延迟 1 秒后重连
- 重连失败后抛出异常

## 错误处理

### 处理查询失败

```php
use Yangweijie\ThinkOrmAsync\Exception\AsyncQueryException;
use Yangweijie\ThinkOrmAsync\Exception\AsyncResultError;

AsyncContext::start();

    $user = User::where('id', 1)->find();
    $orders = Order::where('user_id', 1)->select();

try {
    $results = AsyncContext::end();
    
    // 处理成功结果
    echo "User: " . $user->name . "\n";
    
} catch (AsyncQueryException $e) {
    // 批次查询整体失败
    echo "Batch query failed: " . $e->getMessage();
    print_r($e->getErrors());
}
```

### 处理部分失败

```php
AsyncContext::start();

    $user = User::where('id', 1)->find();
    $invalid = InvalidModel::where('id', 999)->find();

$results = AsyncContext::end();

// 检查每个查询结果
foreach ($results as $key => $result) {
    if ($result instanceof AsyncResultError) {
        echo "Query [$key] failed: " . $result->getMessage() . "\n";
    } else {
        // 处理成功结果
        echo "Query [$key] succeeded\n";
    }
}
```

## 高级用法

### 数组访问和遍历

```php
AsyncContext::start();

    $user = User::where('id', 1)->find();
    $orders = Order::where('user_id', 1)->select();

$results = AsyncContext::end();

// 数组访问
echo $user->name;
echo $user['email'];

// 遍历
foreach ($orders as $order) {
    echo $order->order_no;
}

// 计数
$count = count($orders);
echo "共 {$count} 个订单";
```

### 转换为数组和 JSON

```php
AsyncContext::start();

    $user = User::where('id', 1)->find();
    $orders = Order::where('user_id', 1)->select();

$results = AsyncContext::end();

// 转换为数组
$userArray = $user->toArray();
$ordersArray = $orders->toArray();

// 转换为 JSON
$json = $orders->toJson();
echo $json;
```

### 获取模型对象

```php
AsyncContext::start();

    $user = User::where('id', 1)->find();
    $orders = Order::where('user_id', 1)->select();

$results = AsyncContext::end();

// 获取实际的模型对象
$userModel = $user->getModel();
dump($userModel);  // 显示完整的模型对象

// 或者直接使用（透明代理）
echo $user->name;
```

### 辅助方法

```php
// 检查是否为空
if ($user->isEmpty()) {
    echo "用户不存在";
}

// 获取第一个元素
$firstOrder = $orders->first();

// 获取最后一个元素
$lastOrder = $orders->last();
```

## 注意事项

1. **ThinkPHP 环境**：在 ThinkPHP 项目中，会自动从 `config('database.connections.mysql')` 读取配置
2. **mysqlnd 驱动**：需要使用 mysqlnd 驱动（PHP 5.3+ 默认）
3. **不要在循环中使用**：避免在循环中嵌套 `AsyncContext::start()` / `AsyncContext::end()`
4. **错误处理**：查询失败时会抛出异常，需要自行处理
5. **表前缀**：确保模型类正确设置了 `$name` 和 `$prefix` 属性

## 性能提升

在多个独立查询的场景下，异步查询可以显著减少网络往返延迟：

| 查询数 | 同步查询 | 异步查询 | 提升 |
|--------|---------|---------|------|
| 3      | 300ms   | 150ms   | 50%  |
| 5      | 500ms   | 200ms   | 60%  |
| 10     | 1000ms  | 350ms   | 65%  |

*假设：每个查询平均耗时 100ms，网络往返 50ms*

## 测试

```bash
composer test
```

测试使用 Pest 框架，使用 Mock 对象，无需真实数据库连接。

## 许可证

MIT
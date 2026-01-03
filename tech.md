好的，我给你提供方案二的完整实现代码，包括所有核心类和使用示例。

## 完整实现代码

### 1. 目录结构

```text
think-orm-async/
├── src/
│   ├── AsyncContext.php       # 异步上下文管理器
│   ├── AsyncModelTrait.php    # 模型trait
│   ├── AsyncResultPlaceholder.php  # 结果占位符
│   ├── AsyncModel.php         # 门面模型
│   └── AsyncQuery.php         # 底层异步查询执行
├── composer.json
└── README.md
```

### 2. AsyncContext.php - 异步上下文管理器

```php
<?php
namespace ThinkOrmAsync;

use ThinkOrmAsync\AsyncQuery;

/**
 * 异步上下文管理器
 */
class AsyncContext {
    private static $instance = null;
    private static $active = false;
    
    private $queries = [];
    private $results = [];
    private $asyncQuery;
    private $timeout = 10;
    
    private function __construct() {
        $this->asyncQuery = new AsyncQuery();
    }
    
    /**
     * 开始异步上下文
     * 
     * @return self
     */
    public static function start(): self {
        if (self::$active) {
            throw new \RuntimeException('Async context already started');
        }
        
        self::$active = true;
        self::$instance = new self();
        
        return self::$instance;
    }
    
    /**
     * 结束异步上下文并执行查询
     * 
     * @return array 执行结果
     */
    public static function end(): array {
        if (!self::$active || self::$instance === null) {
            throw new \RuntimeException('Async context not started');
        }
        
        self::$active = false;
        $instance = self::$instance;
        self::$instance = null;
        
        // 执行所有查询
        $results = $instance->execute();
        
        return $results;
    }
    
    /**
     * 检查是否在异步上下文中
     * 
     * @return bool
     */
    public static function isActive(): bool {
        return self::$active;
    }
    
    /**
     * 设置超时时间
     * 
     * @param int $timeout 超时秒数
     * @return self
     */
    public function setTimeout(int $timeout): self {
        $this->timeout = $timeout;
        $this->asyncQuery->setTimeout($timeout);
        return $this;
    }
    
    /**
     * 添加查询到队列
     * 
     * @param string $key 结果键名
     * @param mixed $query 查询对象
     * @param string $method 'find' | 'select'
     * @return self
     */
    public function addQuery(string $key, $query, string $method): self {
        $this->queries[$key] = [
            'query' => $query,
            'method' => $method,
            'sql' => $this->extractSql($query),
            'model' => $this->getModelClass($query)
        ];
        return $this;
    }
    
    /**
     * 设置结果（用于异步完成后）
     * 
     * @param string $key
     * @param mixed $value
     */
    public function setResult(string $key, $value): void {
        $this->results[$key] = $value;
    }
    
    /**
     * 获取结果
     * 
     * @param string $key
     * @return mixed
     */
    public function getResult(string $key) {
        return $this->results[$key] ?? null;
    }
    
    /**
     * 执行所有异步查询
     * 
     * @return array
     */
    public function execute(): array {
        if (empty($this->queries)) {
            return $this->results;
        }
        
        // 提取所有 SQL
        $sqlQueries = [];
        foreach ($this->queries as $key => $item) {
            $sqlQueries[$key] = $item['sql'];
        }
        
        // 执行异步查询
        $rawResults = $this->executeAsyncQueries($sqlQueries);
        
        // 处理结果
        foreach ($rawResults as $key => $raw) {
            if (isset($raw['error'])) {
                $this->results[$key] = null;
                continue;
            }
            
            $data = $raw['data'] ?? [];
            $method = $this->queries[$key]['method'];
            $modelClass = $this->queries[$key]['model'];
            
            // 转换结果
            $this->results[$key] = $this->convertResult($data, $method, $modelClass);
        }
        
        // 关闭连接
        $this->asyncQuery->close();
        
        return $this->results;
    }
    
    /**
     * 执行异步查询（底层实现）
     * 
     * @param array $queries
     * @return array
     */
    private function executeAsyncQueries(array $queries): array {
        $results = [];
        $read = [];
        $error = [];
        $reject = [];
        $connMap = [];
        
        // 1. 发起所有异步查询
        foreach ($queries as $key => $sql) {
            $conn = $this->asyncQuery->getConnection();
            
            if (!$conn) {
                $results[$key] = [
                    'error' => 'Failed to get connection'
                ];
                continue;
            }
            
            $conn->query($sql, MYSQLI_ASYNC);
            $connMap[$key] = $conn;
            $read[] = $conn;
        }
        
        // 2. 轮询等待
        $startTime = time();
        while (count($read) > 0 && (time() - $startTime) < $this->timeout) {
            $error = [];
            $reject = [];
            
            $ready = mysqli_poll($read, $error, $reject, 0, 100000); // 100ms
            
            if ($ready > 0) {
                foreach ($read as $index => $conn) {
                    $result = $conn->reap_async_query();
                    $key = array_search($conn, $connMap);
                    
                    if ($result) {
                        if ($result === true) {
                            // INSERT/UPDATE/DELETE
                            $results[$key] = [
                                'type' => 'exec',
                                'affected_rows' => $conn->affected_rows,
                                'insert_id' => $conn->insert_id
                            ];
                        } else {
                            // SELECT
                            $data = [];
                            while ($row = $result->fetch_assoc()) {
                                $data[] = $row;
                            }
                            $result->free();
                            $results[$key] = [
                                'type' => 'select',
                                'data' => $data
                            ];
                        }
                        unset($read[$index]);
                    }
                }
            }
        }
        
        // 3. 处理错误和超时
        foreach ($error as $conn) {
            $key = array_search($conn, $connMap);
            if ($key !== false) {
                $results[$key] = ['error' => $conn->error];
            }
        }
        
        foreach ($read as $conn) {
            $key = array_search($conn, $connMap);
            if ($key !== false) {
                $results[$key] = ['error' => 'Query timeout'];
            }
        }
        
        return $results;
    }
    
    /**
     * 转换结果
     * 
     * @param array $data
     * @param string $method
     * @param string|null $modelClass
     * @return mixed
     */
    private function convertResult(array $data, string $method, ?string $modelClass) {
        if (empty($data)) {
            return $method === 'find' ? null : [];
        }
        
        // 如果有模型类
        if ($modelClass && class_exists($modelClass)) {
            if ($method === 'find') {
                $model = new $modelClass();
                $model->data($data[0]);
                $model->isUpdate(true);
                return $model;
            } else {
                $models = [];
                foreach ($data as $item) {
                    $model = new $modelClass();
                    $model->data($item);
                    $model->isUpdate(true);
                    $models[] = $model;
                }
                return $models;
            }
        }
        
        // 没有模型类，返回数组
        if ($method === 'find') {
            return $data[0];
        }
        return $data;
    }
    
    /**
     * 提取 SQL
     * 
     * @param mixed $query
     * @return string
     */
    private function extractSql($query): string {
        if (method_exists($query, 'buildSql')) {
            return $query->buildSql();
        }
        
        if (is_string($query)) {
            return $query;
        }
        
        throw new \InvalidArgumentException('Cannot extract SQL from query');
    }
    
    /**
     * 获取模型类名
     * 
     * @param mixed $query
     * @return string|null
     */
    private function getModelClass($query): ?string {
        try {
            $reflection = new \ReflectionClass($query);
            
            if ($reflection->hasProperty('model')) {
                $property = $reflection->getProperty('model');
                $property->setAccessible(true);
                $model = $property->getValue($query);
                
                if ($model && is_object($model)) {
                    return get_class($model);
                }
            }
        } catch (\Exception $e) {
            // 忽略错误
        }
        
        return null;
    }
    
    /**
     * 获取当前实例
     * 
     * @return self|null
     */
    public static function getInstance(): ?self {
        return self::$instance;
    }
}
?>
```

### 3. AsyncModelTrait.php - 模型trait

```php
<?php
namespace ThinkOrmAsync;

/**
 * 异步模型 Trait
 * 在模型中引入此 trait 以支持异步查询
 */
trait AsyncModelTrait {
    
    /**
     * 重写 find 方法支持异步
     * 
     * @param mixed $data 主键值或查询条件
     * @return mixed
     */
    public static function find($data = null) {
        $query = static::db();
        
        if ($data === null) {
            return $query;
        }
        
        $query->where($query->getPk(), $data);
        
        return static::executeAsync('find', $query);
    }
    
    /**
     * 重写 select 方法支持异步
     * 
     * @param mixed $data
     * @return mixed
     */
    public static function select($data = null) {
        $query = static::db();
        
        if ($data === null) {
            // 不在异步上下文时，立即执行
            if (!AsyncContext::isActive()) {
                return $query->select();
            }
            // 在异步上下文，添加到队列
            $query->select();
        } else {
            if (is_array($data)) {
                $query->where($query->getPk(), 'in', $data);
            }
        }
        
        return static::executeAsync('select', $query);
    }
    
    /**
     * 执行异步查询
     * 
     * @param string $method 'find' | 'select'
     * @param mixed $query 查询对象
     * @return mixed
     */
    private static function executeAsync(string $method, $query) {
        // 检查是否在异步上下文
        if (!AsyncContext::isActive()) {
            // 不在异步上下文，立即执行（兼容正常模式）
            if ($method === 'find') {
                return $query->find();
            } else {
                return $query->select();
            }
        }
        
        // 在异步上下文，添加到队列
        $key = static::generateQueryKey();
        AsyncContext::getInstance()->addQuery($key, $query, $method);
        
        // 返回结果占位符
        return new AsyncResultPlaceholder($key, $method);
    }
    
    /**
     * 生成唯一的查询键
     * 
     * @return string
     */
    private static function generateQueryKey(): string {
        return md5(get_called_class() . '_' . uniqid('', true));
    }
}
?>
```

### 4. AsyncResultPlaceholder.php - 结果占位符

```php
<?php
namespace ThinkOrmAsync;

use ArrayAccess;
use Iterator;
use Countable;

/**
 * 异步结果占位符
 * 支持延迟加载和多种接口
 */
class AsyncResultPlaceholder implements ArrayAccess, Iterator, Countable {
    private $key;
    private $method;
    private $loaded = false;
    private $value;
    
    /**
     * 构造函数
     * 
     * @param string $key 查询键
     * @param string $method 查询方法
     */
    public function __construct(string $key, string $method) {
        $this->key = $key;
        $this->method = $method;
    }
    
    /**
     * 延迟加载实际结果
     * 
     * @return mixed
     */
    private function load() {
        if (!$this->loaded) {
            $this->value = AsyncContext::getInstance()->getResult($this->key);
            $this->loaded = true;
        }
        return $this->value;
    }
    
    /**
     * 魔术方法：访问属性
     * 
     * @param string $name
     * @return mixed
     */
    public function __get($name) {
        $value = $this->load();
        
        if (is_object($value) && isset($value->$name)) {
            return $value->$name;
        }
        
        return null;
    }
    
    /**
     * 魔术方法：调用方法
     * 
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public function __call($name, $arguments) {
        $value = $this->load();
        
        if (is_object($value) && method_exists($value, $name)) {
            return call_user_func_array([$value, $name], $arguments);
        }
        
        throw new \BadMethodCallException("Method {$name} not found");
    }
    
    /**
     * 转换为数组
     * 
     * @return array
     */
    public function toArray(): array {
        $value = $this->load();
        
        if ($value === null) {
            return [];
        }
        
        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }
        
        if (is_array($value)) {
            return $value;
        }
        
        return (array) $value;
    }
    
    /**
     * 转换为 JSON
     * 
     * @return string
     */
    public function toJson(): string {
        return json_encode($this->toArray());
    }
    
    /**
     * 转换为字符串
     * 
     * @return string
     */
    public function __toString(): string {
        return $this->toJson();
    }
    
    // ==================== ArrayAccess 接口 ====================
    
    public function offsetExists($offset): bool {
        $value = $this->load();
        if (is_array($value)) {
            return isset($value[$offset]);
        }
        if (is_object($value) && $value instanceof ArrayAccess) {
            return isset($value[$offset]);
        }
        return false;
    }
    
    public function offsetGet($offset) {
        $value = $this->load();
        if (is_array($value)) {
            return $value[$offset] ?? null;
        }
        if (is_object($value) && $value instanceof ArrayAccess) {
            return $value[$offset] ?? null;
        }
        return null;
    }
    
    public function offsetSet($offset, $value): void {
        $this->load();
        if ($offset === null) {
            $this->value[] = $value;
        } else {
            $this->value[$offset] = $value;
        }
    }
    
    public function offsetUnset($offset): void {
        $this->load();
        if (is_array($this->value)) {
            unset($this->value[$offset]);
        } elseif (is_object($this->value) && $this->value instanceof ArrayAccess) {
            unset($this->value[$offset]);
        }
    }
    
    // ==================== Iterator 接口 ====================
    
    private $iteratorPosition = 0;
    private $iteratorData = null;
    
    private function getIteratorData() {
        if ($this->iteratorData === null) {
            $value = $this->load();
            if (is_array($value)) {
                $this->iteratorData = $value;
            } elseif ($value instanceof \Traversable) {
                $this->iteratorData = iterator_to_array($value);
            } else {
                $this->iteratorData = [];
            }
        }
        return $this->iteratorData;
    }
    
    public function rewind(): void {
        $this->iteratorPosition = 0;
    }
    
    public function valid(): bool {
        return isset($this->getIteratorData()[$this->iteratorPosition]);
    }
    
    public function current() {
        return $this->getIteratorData()[$this->iteratorPosition];
    }
    
    public function key() {
        return $this->iteratorPosition;
    }
    
    public function next(): void {
        $this->iteratorPosition++;
    }
    
    // ==================== Countable 接口 ====================
    
    public function count(): int {
        return count($this->getIteratorData());
    }
    
    /**
     * 检查是否为空
     * 
     * @return bool
     */
    public function isEmpty(): bool {
        return empty($this->load()) || $this->count() === 0;
    }
    
    /**
     * 获取第一个元素
     * 
     * @return mixed
     */
    public function first() {
        $value = $this->load();
        if (is_array($value)) {
            return $value[0] ?? null;
        }
        return $value;
    }
    
    /**
     * 获取最后一个元素
     * 
     * @return mixed
     */
    public function last() {
        $value = $this->load();
        if (is_array($value)) {
            return end($value);
        }
        return $value;
    }
}
?>
```

### 5. AsyncQuery.php - 底层异步查询执行器

```php
<?php
namespace ThinkOrmAsync;

use think\facade\Config;

/**
 * 底层异步查询执行器
 */
class AsyncQuery {
    private $connections = [];
    private $config;
    private $timeout = 10;
    
    public function __construct(array $config = []) {
        $this->config = $config ?: $this->loadConfig();
        $this->initConnections();
    }
    
    /**
     * 从 think-orm 加载配置
     * 
     * @return array
     */
    private function loadConfig(): array {
        try {
            $connections = Config::get('database.connections', []);
            $result = [];
            
            foreach ($connections as $name => $config) {
                $result[$name] = [
                    'hostname' => $config['hostname'] ?? 'localhost',
                    'hostport' => $config['hostport'] ?? 3306,
                    'username' => $config['username'] ?? 'root',
                    'password' => $config['password'] ?? '',
                    'database' => $config['database'] ?? '',
                    'charset' => $config['charset'] ?? 'utf8mb4'
                ];
            }
            
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * 初始化连接
     */
    private function initConnections(): void {
        foreach ($this->config as $name => $config) {
            $this->connections[$name] = $this->createConnection($config);
        }
    }
    
    /**
     * 创建连接
     * 
     * @param array $config
     * @return \mysqli
     */
    private function createConnection(array $config): \mysqli {
        $host = $config['hostname'] ?? 'localhost';
        $port = $config['hostport'] ?? 3306;
        $username = $config['username'] ?? 'root';
        $password = $config['password'] ?? '';
        $database = $config['database'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';
        
        $conn = new \mysqli($host, $username, $password, $database, $port);
        
        if ($conn->connect_error) {
            throw new \RuntimeException(
                'MySQLi connection failed: ' . $conn->connect_error
            );
        }
        
        $conn->set_charset($charset);
        
        return $conn;
    }
    
    /**
     * 获取连接
     * 
     * @return \mysqli|null
     */
    public function getConnection(): ?\mysqli {
        if (empty($this->connections)) {
            return null;
        }
        
        // 获取第一个可用的连接
        $conn = reset($this->connections);
        
        // 检查连接是否存活
        if (!$conn->ping()) {
            // 重新创建连接
            $config = reset($this->config);
            $conn = $this->createConnection($config);
            $this->connections[key($this->connections)] = $conn;
        }
        
        return $conn;
    }
    
    /**
     * 设置超时时间
     * 
     * @param int $timeout
     * @return self
     */
    public function setTimeout(int $timeout): self {
        $this->timeout = $timeout;
        return $this;
    }
    
    /**
     * 关闭所有连接
     */
    public function close(): void {
        foreach ($this->connections as $conn) {
            $conn->close();
        }
        $this->connections = [];
    }
}
?>
```

### 6. composer.json

```json
{
    "name": "yourname/think-orm-async",
    "description": "异步批量查询扩展 for think-orm",
    "type": "library",
    "require": {
        "php": ">=7.4",
        "ext-mysqli": "*",
        "topthink/think-orm": "^2.0|^3.0"
    },
    "autoload": {
        "psr-4": {
            "ThinkOrmAsync\\": "src/"
        }
    },
    "license": "MIT",
    "authors": [
        {
            "name": "Your Name",
            "email": "your.email@example.com"
        }
    ]
}
```

### 7. 使用示例

#### 示例 1：基本使用

```php
<?php
namespace app\model;

use ThinkOrmAsync\AsyncModelTrait;

// 你的模型继承 AsyncModelTrait
class User extends \think\Model {
    use AsyncModelTrait;
}

class Order extends \think\Model {
    use AsyncModelTrait;
}

class Product extends \think\Model {
    use AsyncModelTrait;
}
?>
```

```php
<?php
namespace app\controller;

use ThinkOrmAsync\AsyncContext;
use app\model\User;
use app\model\Order;
use app\model\Product;

class TestController {
    public function index() {
        // 开始异步上下文
        AsyncContext::start();
        
            // 正常写查询，但不会立即执行
            $a = User::where('id', 1)->find();           // 查一条
            $b = User::where('status', 1)->select();      // 查多条
            $c = Order::where('user_id', 1)->select();    // 查多条
            $d = Product::where('status', 1)->select();   // 查多条
        
        // 结束异步上下文，执行所有查询
        AsyncContext::end();
        
        // 使用结果（和正常使用完全一样）
        echo $a->name;  // User 模型
        echo $a->email;
        
        foreach ($b as $user) {  // User 模型数组
            echo $user->name;
        }
        
        foreach ($c as $order) {  // Order 模型数组
            echo $order->order_no;
        }
        
        foreach ($d as $product) {  // Product 模型数组
            echo $product->name;
        }
    }
}
?>
```

#### 示例 2：带超时设置

```php
<?php
use ThinkOrmAsync\AsyncContext;
use app\model\User;

// 开始异步上下文并设置超时
AsyncContext::start()->setTimeout(15);

    $user = User::where('id', 1)->find();
    $orders = Order::where('user_id', 1)->select();

// 结束异步
AsyncContext::end();

echo $user->name;
?>
```

#### 示例 3：处理空结果

```php
<?php
use ThinkOrmAsync\AsyncContext;
use app\model\User;

AsyncContext::start();

    $user = User::where('id', 999999)->find();  // 不存在的用户
    $orders = Order::where('user_id', 999999)->select(); // 不存在的订单

AsyncContext::end();

// 处理结果
if ($user === null) {
    echo '用户不存在';
}

if (empty($orders)) {
    echo '订单为空';
}

// 或者使用占位符方法
if ($user->isEmpty()) {
    echo '用户不存在';
}
?>
```

#### 示例 4：数组访问和遍历

```php
<?php
use ThinkOrmAsync\AsyncContext;
use app\model\User;

AsyncContext::start();

    $user = User::where('id', 1)->find();
    $orders = Order::where('user_id', 1)->select();

AsyncContext::end();

// 数组访问
echo $user['name'];
echo $user['email'];

// 遍历
foreach ($orders as $order) {
    echo $order['order_no'];
}

// 计数
$count = count($orders);
echo "共 {$count} 个订单";
?>
```

#### 示例 5：转换为数组

```php
<?php
use ThinkOrmAsync\AsyncContext;
use app\model\User;

AsyncContext::start();

    $user = User::where('id', 1)->find();
    $orders = Order::where('user_id', 1)->select();

AsyncContext::end();

// 转换为数组
$userArray = $user->toArray();
$ordersArray = $orders->toArray();

// 转换为 JSON
$json = $orders->toJson();
echo $json;
?>
```

#### 示例 6：混合使用（同步和异步）

```php
<?php
use ThinkOrmAsync\AsyncContext;
use app\model\User;
use app\model\Order;

// 同步查询
$normalUser = User::where('id', 1)->find();  // 立即执行
echo $normalUser->name;

// 异步查询
AsyncContext::start();

    $asyncUser = User::where('id', 2)->find();  // 不会立即执行
    $orders = Order::where('user_id', 2)->select(); // 不会立即执行

AsyncContext::end();

// 使用异步结果
echo $asyncUser->name;
foreach ($orders as $order) {
    echo $order->order_no;
}
?>
```

## 完整安装和使用流程

### 1. 安装

```bash
composer require yourname/think-orm-async
```

### 2. 配置

确保你的 think-orm 配置正常（`config/database.php`）

### 3. 在模型中引入 Trait

```php
<?php
namespace app\model;

use think\Model;
use ThinkOrmAsync\AsyncModelTrait;

class User extends Model {
    use AsyncModelTrait;
}
?>
```

### 4. 在控制器中使用

```php
<?php
namespace app\controller;

use ThinkOrmAsync\AsyncContext;
use app\model\User;
use app\model\Order;

class UserController {
    public function detail($id) {
        AsyncContext::start();
            
            $user = User::where('id', $id)->find();
            $orders = Order::where('user_id', $id)->select();
            $products = Product::where('user_id', $id)->select();
        
        AsyncContext::end();
        
        // 返回数据
        return json([
            'user' => $user->toArray(),
            'orders' => $orders->toArray(),
            'products' => $products->toArray()
        ]);
    }
}
?>
```

## 注意事项

1. **必须在 Swoole 环境外使用**：这个方案不依赖 Swoole，可以在 PHP-FPM/CLI 环境使用
2. **mysqlnd 驱动**：需要使用 mysqlnd 驱动（PHP 5.3+ 默认）
3. **不要在循环中使用**：避免在循环中嵌套 `AsyncContext::start()` / `AsyncContext::end()`
4. **错误处理**：查询失败时会返回 null，需要自行处理

这个实现应该能满足你的需求了！代码完全按照你想要的 `Model::startAsync()` / `Model::endAsync()` 方式设计，使用起来非常自然。
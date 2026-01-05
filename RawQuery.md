# 原生查询支持

ThinkOrmAsync 现在支持原生 SQL 查询，并使用 `mysqli_poll` 实现异步并行执行。

## 基本用法

```php
use Yangweijie\ThinkOrmAsync\AsyncContext;

// 开始异步上下文，可以传递数据库配置
AsyncContext::start(null, [
    'hostname' => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => 'test',
    'hostport' => 3306,
    'charset' => 'utf8mb4',
]);

// 添加原生查询
$result1 = AsyncContext::query("SELECT sleep(1) as wait_time");
$result2 = AsyncContext::query("SELECT sleep(1) as wait_time");

// 结束异步上下文，所有查询并行执行
$results = AsyncContext::end();

// 获取结果
echo $result1->first()['wait_time']; // 输出: 1
echo $result2->first()['wait_time']; // 输出: 1

// 总耗时约 1 秒（串行执行需要 2 秒）
```

## 使用自定义 Key

```php
AsyncContext::start(null, $dbConfig);

// 使用自定义 key 方便后续获取结果
$userCount = AsyncContext::query("SELECT COUNT(*) as count FROM users", 'user_count');
$productCount = AsyncContext::query("SELECT COUNT(*) as count FROM products", 'product_count');

$results = AsyncContext::end();

// 从 results 数组中直接获取
echo $results['user_count'][0]['count'];
echo $results['product_count'][0]['count'];
```

## 混合使用 ORM 和原生查询

```php
use Yangweijie\ThinkOrmAsync\AsyncModelTrait;

class User extends \think\Model {
    use AsyncModelTrait;
}

AsyncContext::start(null, $dbConfig);

// ORM 查询
$users = User::where('status', 1)->select();

// 原生查询 - 统计
$stats = AsyncContext::query("SELECT COUNT(*) as count, AVG(score) as avg_score FROM users WHERE status = 1");

// 原生查询 - 获取最新日志
$logs = AsyncContext::query("SELECT * FROM logs ORDER BY id DESC LIMIT 10");

$results = AsyncContext::end();

// 所有查询并行执行
echo count($users);
echo $stats->first()['count'];
echo count($logs);
```

## INSERT/UPDATE/DELETE 查询

```php
AsyncContext::start(null, $dbConfig);

// 执行类查询会返回受影响的行数
$insertResult = AsyncContext::query("INSERT INTO logs (message) VALUES ('test')");
$updateResult = AsyncContext::query("UPDATE users SET last_login = NOW() WHERE id = 1");

$results = AsyncContext::end();

// 获取执行结果
echo $results[$insertResult->key]['affected_rows']; // 受影响的行数
echo $results[$insertResult->key]['insert_id']; // 插入的 ID
echo $results[$updateResult->key]['affected_rows']; // 受影响的行数
```

## 性能优势

```php
// 串行执行（3 秒）
Db::query("SELECT sleep(1)");
Db::query("SELECT sleep(1)");
Db::query("SELECT sleep(1)");

// 异步并行执行（1 秒）
AsyncContext::start(null, $dbConfig);
    AsyncContext::query("SELECT sleep(1)");
    AsyncContext::query("SELECT sleep(1)");
    AsyncContext::query("SELECT sleep(1)");
AsyncContext::end();
```

## 注意事项

1. **数据库配置**：使用原生查询时，需要在 `AsyncContext::start()` 时传递数据库配置
2. **事务支持**：异步查询不支持事务，每个查询都是独立的
3. **错误处理**：查询失败时，结果会包含错误信息
4. **结果类型**：
   - SELECT 查询返回数组
   - INSERT/UPDATE/DELETE 查询返回包含 `affected_rows` 和 `insert_id` 的数组

## API 参考

### AsyncContext::query(string $sql, string $key = null): AsyncResultPlaceholder

注册一个原生 SQL 查询到异步上下文。

**参数：**
- `$sql` - SQL 查询语句
- `$key` - 可选的自定义 key，用于标识查询

**返回：**
- `AsyncResultPlaceholder` - 异步结果占位符

**示例：**
```php
$result = AsyncContext::query("SELECT * FROM users WHERE id = 1");
$data = $result->first();
```
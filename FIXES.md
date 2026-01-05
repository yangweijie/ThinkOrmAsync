# 修复记录

## 2025-01-06: 修复 "Commands out of sync" 错误

### 问题描述
当使用 `AsyncContext::query()` 执行多个原生查询时，出现错误：
```
Commands out of sync; you can't run this command now
```

### 原因
MySQL 的异步查询需要每个查询使用独立的连接，或者在查询之间正确清理连接。原来的实现使用单个连接执行多个异步查询，导致连接状态不同步。

### 解决方案
修改 `src/AsyncQuery.php` 中的 `executeAsyncQueries()` 方法，为每个查询创建独立的连接：

```php
// 修改前：使用单个连接
$conn = $this->getConnection();
$conn->query($sql, MYSQLI_ASYNC);

// 修改后：每个查询独立连接
$tempConn = $this->createConnection($this->config);
$tempConn->query($sql, MYSQLI_ASYNC);
// ... 收集结果后关闭
$tempConn->close();
```

### 测试
运行逻辑测试验证修复：
```bash
php test_async_logic.php
```

所有测试通过 ✓

### 使用示例
```php
$dbConfig = [
    'hostname' => 'localhost',
    'database' => 'your_db',
    'username' => 'root',
    'password' => 'your_password',
    'hostport' => '3306',
    'charset' => 'utf8mb4',
];

AsyncContext::start(null, $dbConfig);
    $result1 = AsyncContext::query("SELECT sleep(1) as wait_time");
    $result2 = AsyncContext::query("SELECT sleep(1) as wait_time");
    $result3 = AsyncContext::query("SELECT NOW() as current_time");
$results = AsyncContext::end();

// 所有查询并行执行，总耗时约 1 秒
```
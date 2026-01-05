# 示例说明

## 运行示例

### 1. 快速测试（推荐）

```bash
# 修改 test_raw_query.php 中的数据库配置
php test_raw_query.php
```

### 2. 原生查询示例

```bash
# 修改 examples/raw_query.php 中的数据库配置
php examples/raw_query.php
```

### 3. 混合查询示例

```bash
# 修改 examples/mixed_queries.php 中的数据库配置
php examples/mixed_queries.php
```

### 4. 详细用法示例

```bash
php examples/raw_query_usage.php
```

## 数据库配置

所有示例都需要配置数据库连接。在运行前，请修改相应的配置：

```php
$dbConfig = [
    'hostname' => 'localhost',  // 数据库主机
    'database' => 'test',       // 数据库名称
    'username' => 'root',       // 用户名
    'password' => '',           // 密码
    'hostport' => '3306',       // 端口
    'charset' => 'utf8mb4',     // 字符集
];
```

## 性能对比

### 串行执行（3 秒）

```php
Db::query("SELECT sleep(1)");
Db::query("SELECT sleep(1)");
Db::query("SELECT sleep(1)");
```

### 异步并行执行（1 秒）

```php
AsyncContext::start(null, $dbConfig);
    AsyncContext::query("SELECT sleep(1)");
    AsyncContext::query("SELECT sleep(1)");
    AsyncContext::query("SELECT sleep(1)");
AsyncContext::end();
```

## 注意事项

1. **数据库连接**：确保 MySQL 服务正在运行，并且用户有足够的权限
2. **mysqli 扩展**：确保 PHP 已安装 mysqli 扩展
3. **异步执行**：所有查询在 `AsyncContext::end()` 时才会并行执行
4. **错误处理**：如果查询失败，会返回错误信息

## 常见问题

### Q: 提示 "Failed to get connection"

A: 检查数据库配置是否正确，特别是：
- 主机名和端口
- 用户名和密码
- 数据库是否存在

### Q: 提示 "Access denied"

A: 检查用户权限，确保用户有访问指定数据库的权限

### Q: 查询没有结果

A: 确保查询的表存在，并且有数据

## 更多信息

- [README.md](README.md) - 项目说明
- [RawQuery.md](RawQuery.md) - 原生查询详细文档
- [tech.md](tech.md) - 技术实现细节
# ThinkOrmAsync 项目说明

## 项目概述

ThinkOrmAsync 是一个为 ThinkPHP ORM 提供异步批量查询功能的扩展库。通过使用 PHP 的 `mysqli_poll` 功能，实现了多个独立查询的并行执行，显著提升查询性能。

### 核心特性

- **透明的异步查询 API**：使用体验与同步代码完全一致
- **支持 ORM 查询和原生 SQL 查询**
- **自动配置**：自动从 ThinkPHP 配置读取数据库配置
- **连接重试机制**：自动重试连接错误（2002, 2003, 2006, 2013）
- **延迟加载**：使用占位符模式，按需加载结果
- **零侵入集成**：通过 trait + 单例模式，无需修改现有代码
- **非 Swoole 环境可用**：纯 PHP mysqli 实现，不依赖 Swoole

### 技术栈

- **PHP**: >= 7.4
- **扩展**: ext-mysqli, ext-mysqlnd
- **框架**: topthink/think-orm ^3.0|^4.0
- **测试**: Pest PHP ^2.0
- **Mock**: Mockery ^1.6

## 项目结构

```
/Volumes/data/git/php/ThinkOrmAsync/
├── src/                          # 源代码目录
│   ├── Exception/                # 异常类
│   │   ├── AsyncQueryException.php
│   │   ├── AsyncResultError.php
│   │   └── RetryExhaustedException.php
│   ├── Retry/                    # 重试机制
│   │   ├── BatchResult.php
│   │   ├── ConnectionErrorRetryStrategy.php
│   │   ├── RetryExecutor.php
│   │   └── RetryStrategy.php
│   ├── AsyncContext.php          # 异步上下文管理器（核心）
│   ├── AsyncModelTrait.php       # 模型 Trait（核心）
│   ├── AsyncQuery.php            # 底层异步查询执行器
│   ├── AsyncQueryBuilder.php     # 查询构建器包装器
│   ├── AsyncQueryWrapper.php     # 查询包装器
│   └── AsyncResultPlaceholder.php # 结果占位符（核心）
├── tests/                        # 测试目录
│   ├── Unit/                     # 单元测试
│   │   └── AsyncQueryTest.php
│   ├── AsyncContextTest.php
│   ├── AsyncModelTraitTest.php
│   ├── AsyncRawQueryIntegrationTest.php
│   ├── AsyncRawQueryTest.php
│   ├── AsyncResultPlaceholderTest.php
│   └── ConnectionErrorRetryTest.php
├── examples/                     # 示例代码
│   ├── auto_config.php
│   ├── basic_usage.php
│   ├── error_handling.php
│   ├── mixed_queries.php
│   ├── raw_query.php
│   ├── raw_query_usage.php
│   └── retry_example.php
├── composer.json                 # Composer 配置
├── phpunit.xml                  # PHPUnit 配置
├── README.md                    # 项目文档
├── EXAMPLES.md                  # 示例文档
├── FIXES.md                     # 修复记录
├── RawQuery.md                  # 原生查询文档
└── tech.md                      # 技术文档
```

## 核心类说明

### 1. AsyncContext（异步上下文管理器）

**职责**：管理异步查询上下文，协调查询的注册和执行

**核心方法**：
- `start(?BaseQuery $query = null, ?array $dbConfig = null): self` - 启动异步上下文
- `end(): array` - 结束异步上下文并执行所有查询
- `query(string $sql, string $key = null): AsyncResultPlaceholder` - 注册原生 SQL 查询
- `setTimeout(int $timeout): self` - 设置超时时间

**关键特性**：
- 单例模式，确保全局只有一个异步上下文
- 自动从 ThinkPHP 配置读取数据库配置
- 检查数据库类型是否为 MySQL
- 检查 mysqli_poll 支持情况
- 支持混合使用 ORM 和原生 SQL 查询

### 2. AsyncModelTrait（模型 Trait）

**职责**：拦截模型的静态方法调用，将查询注册到异步上下文

**核心方法**：
- `__callStatic($method, $args)` - 拦截静态方法调用
- `getModelTableName(string $modelClass): ?string` - 获取模型表名
- `getTablePrefix($modelInstance): string` - 获取表前缀

**拦截的方法**：
- `find()` - 单条查询
- `select()` - 列表查询
- 其他链式方法（通过 `AsyncQueryBuilder`）

**关键特性**：
- 通过反射读取模型的 `$name` 和 `$prefix` 属性
- 支持表前缀处理
- 创建轻量级查询包装器，避免调用 `db()` 方法

### 3. AsyncResultPlaceholder（结果占位符）

**职责**：延迟加载查询结果，提供透明的访问接口

**核心方法**：
- `getValue()` - 获取原始数据
- `getModel()` - 获取模型对象
- `toArray()` - 转换为数组
- `toJson()` - 转换为 JSON

**实现的接口**：
- `ArrayAccess` - 数组访问
- `Iterator` - 遍历
- `Countable` - 计数
- `Collection` - ThinkPHP 集合接口

**关键特性**：
- 透明代理模型对象的属性和方法访问
- 支持 `dump()` 显示正确信息
- 延迟加载，按需获取结果

### 4. AsyncQuery（底层异步查询执行器）

**职责**：使用 mysqli_poll 执行异步查询

**核心方法**：
- `executeAsyncQueries(array $queries): array` - 执行异步查询
- `createConnection(array $config): \mysqli` - 创建数据库连接
- `pollAndCollect(array $pending, array $connMap, array $results): array` - 轮询收集结果

**关键特性**：
- 每个查询使用独立连接，避免连接状态冲突
- 支持连接重试机制
- 自动关闭临时连接
- 处理查询超时和错误

### 5. AsyncQueryWrapper（查询包装器）

**职责**：轻量级查询包装器，避免调用 `db()` 方法

**核心方法**：
- `where($field, $op = null, $value = null)` - 添加 where 条件
- `field($fields)` - 设置查询字段
- `order($field, $order = null)` - 设置排序
- `limit($offset, $length = null)` - 设置限制
- `getConnection()` - 获取连接对象

**关键特性**：
- 支持链式调用
- 不依赖 ThinkPHP 的 `db()` 方法
- 提供 SQL 生成器

### 6. AsyncQueryBuilder（查询构建器包装器）

**职责**：包装查询构建器，返回占位符而不是实际查询结果

**核心方法**：
- `find(...$args)` - find 查询
- `select(array $data = [])` - select 查询

**关键特性**：
- 代理所有查询方法
- 自动注册到异步上下文
- 返回占位符对象

## 构建和运行

### 安装依赖

```bash
composer install
```

### 运行测试

```bash
# 运行所有测试
composer test

# 运行带覆盖率的测试
composer test-coverage

# 或直接使用 pest
vendor/bin/pest
vendor/bin/pest --coverage
```

### 测试说明

- 使用 Pest PHP 测试框架
- 使用 Mockery 进行 Mock
- 测试分为单元测试和集成测试
- 所有测试使用 Mock 对象，无需真实数据库连接

### 测试覆盖

- `AsyncContextTest` - 异步上下文管理器测试（5 个测试）
- `AsyncModelTraitTest` - 模型 Trait 测试（3 个测试）
- `AsyncRawQueryTest` - 原生查询测试（4 个测试）
- `AsyncResultPlaceholderTest` - 结果占位符测试（6 个测试）
- `ConnectionErrorRetryTest` - 连接错误重试测试
- `AsyncRawQueryIntegrationTest` - 原生查询集成测试
- `AsyncQueryTest` - 异步查询单元测试

## 开发约定

### 代码风格

- 遵循 PSR-4 自动加载规范
- 使用 PSR-12 代码风格
- 命名空间：`Yangweijie\ThinkOrmAsync`
- 类名使用 PascalCase
- 方法名使用 camelCase
- 常量使用 UPPER_CASE

### 命名规范

- Trait 类以 `Trait` 结尾：`AsyncModelTrait`
- 异常类以 `Exception` 结尾：`AsyncQueryException`
- 接口类以 `Interface` 结尾（如有）
- 抽象类以 `Abstract` 开头（如有）

### 注释规范

- 所有公共方法必须添加 PHPDoc 注释
- 注释说明方法的用途、参数、返回值
- 复杂逻辑添加行内注释说明"为什么"而不是"做什么"

### 测试规范

- 新功能必须编写测试
- 测试覆盖率目标：>= 90%
- 测试命名：`test_[方法名]_[场景]`
- 使用 Mock 避免依赖真实数据库

### 错误处理

- 使用自定义异常类
- 异常类继承自 `\Exception`
- 异常消息清晰明确，包含上下文信息
- 区分可重试错误和不可重试错误

### 安全规范

- 不硬编码敏感信息（密钥、密码等）
- SQL 查询使用参数化查询，避免 SQL 注入
- 输入验证和过滤
- 错误消息不泄露敏感信息

## 使用示例

### 基本使用

```php
use Yangweijie\ThinkOrmAsync\AsyncContext;
use app\model\User;
use app\model\Order;

// 开始异步上下文（自动从配置读取）
AsyncContext::start();

    $user = User::where('id', 1)->find();
    $orders = Order::where('user_id', 1)->select();

// 结束异步上下文，执行所有查询
AsyncContext::end();

// 使用结果（和同步完全一样）
echo $user->name;
foreach ($orders as $order) {
    echo $order->order_no;
}
```

### 原生 SQL 查询

```php
AsyncContext::start();

    $stats = AsyncContext::query("SELECT COUNT(*) as total FROM user");

AsyncContext::end();

echo $stats[0]['total'];
```

### 混合使用

```php
AsyncContext::start();

    // ORM 查询
    $user = User::find(1);
    
    // 原生查询
    $stats = AsyncContext::query("SELECT COUNT(*) FROM user");

AsyncContext::end();
```

### 错误处理

```php
use Yangweijie\ThinkOrmAsync\Exception\AsyncQueryException;

try {
    AsyncContext::start();
        $user = User::where('id', 1)->find();
    AsyncContext::end();
} catch (AsyncQueryException $e) {
    echo "Query failed: " . $e->getMessage();
}
```

## 性能提升

在多个独立查询的场景下，异步查询可以显著减少网络往返延迟：

| 查询数 | 同步查询 | 异步查询 | 提升 |
|--------|---------|---------|------|
| 3      | 300ms   | 150ms   | 50%  |
| 5      | 500ms   | 200ms   | 60%  |
| 10     | 1000ms  | 350ms   | 65%  |

*假设：每个查询平均耗时 100ms，网络往返 50ms*

## 注意事项

1. **数据库类型**：仅支持 MySQL 数据库
2. **mysqli 扩展**：需要 mysqli 扩展和 mysqlnd 驱动
3. **mysqli_poll**：需要支持 mysqli_poll 函数
4. **不要嵌套使用**：避免在循环中嵌套 `AsyncContext::start()` / `AsyncContext::end()`
5. **表前缀**：确保模型类正确设置了 `$name` 和 `$prefix` 属性
6. **错误处理**：查询失败时会抛出异常，需要自行处理

## 许可证

MIT
# 修复记录

## 2026-11-26: 支持 with() 关联预载 / 修复 cache() 在异步路径失效

### with() 关联预载

旧实现直接抛 `AsyncQueryException`，异步上下文里完全不能用 `with()`。

新实现：

- `AsyncRelationLoader` 归一化 `with()` 的字符串 / 数组 / 闭包 / 嵌套写法。
- 用 ThinkPHP 原生关联对象 + `fetchSql(true)` 生成关联 SQL，交给 `AsyncQuery`
  批量并行执行。软删除、全局 scope、闭包条件、字段限制、JOIN 均由 ORM 处理。
- 同一层的所有关联查询放在一个异步批次；嵌套关联按层级推进。
- 支持 `HasOne` / `HasMany` / `BelongsTo` / `BelongsToMany`。
  `MorphTo` / `MorphMany` / `MorphToMany` / `HasOneThrough` / `HasManyThrough`
  等复杂关联走 ThinkPHP 原生 `eagerlyResultSet()` 降级，保证语义正确。
- 原生降级失败时抛 `AsyncQueryException`，不静默丢关联数据。

注意：`belongsToMany` 的分组键来自中间表（`pivot__xxx`），模型构造函数会过滤掉
非表字段，因此分组必须用原始结果集完成，不能用模型属性。

### cache() 在异步路径失效

根因：ThinkPHP 的缓存由 `Connection::pdoQuery()` 处理；异步路径把 SQL 直接交给
mysqli 执行，绕过了 `Connection`，`options['cache']` 只被存进数组从未消费。

新实现（`AsyncQueryCache`）：

- `cache(true)` 用 SQL 指纹生成 key，`cache('key')` 用显式 key，
  `cache(3600)` 识别为过期时间，`cache(false)` 禁用。
- 注册查询时先读缓存；命中则跳过异步批次，直接走结果转换。
- 主体查询返回后把**原始结果集**写回缓存，与 ThinkPHP 自身行为一致。
- 立即执行路径（`AsyncContext` 外）也走同一套缓存逻辑。
- 缓存未配置或驱动异常时静默降级，不影响查询本身。

其他修正：`buildQueryKey()` 序列化 options 时排除 `with` / `cache`，避免闭包导致
`Serialization of 'Closure' is not allowed`。

### 验证

`php tests/integration_with_cache_check.php`（SQLite 内存库，无需 MySQL）：
15 项检查全部通过，覆盖四种关联、嵌套预载、预载与懒加载区分、缓存参数归一化、
异步路径缓存命中与带闭包 `with()`。

## 2026-11-26: 修复链头方法被静默丢弃（fieldRaw / where / column 等）

### 问题描述

在 `AsyncContext` 生效期间，模型静态调用的**第一个方法**（链头）会被丢弃，只有后续通过
`AsyncQueryBuilder` 转发的方法才生效。典型表现：

```php
AsyncContext::start();

// 现象 1：fieldRaw 丢失 → SELECT * ... GROUP BY
$rows = Order::fieldRaw('app_module, sum(total_amount) as sales_volume')
    ->where('order_status', 1)
    ->group('app_module')
    ->select();
// 结果集没有 sales_volume 键 → PHP 报 Undefined array key "sales_volume"

// 现象 2：where 丢失 → 全表统计
$sum = Order::where([['mch_id', '=', 12], ['order_status', '=', 1]])
    ->field('total_amount')
    ->sum('total_amount');
// 实际执行 SELECT SUM(total_amount) FROM order_combine（无 WHERE）
// 跨商户数据串号，且返回值看起来"正常"，很难发现

AsyncContext::end();
```

### 根因

`AsyncModelTrait::__callStatic()` 在非 `find/select` 分支里直接
`return new AsyncQueryBuilder($query, $modelClass);`，既没有把 `$method` 交给 wrapper，
也没有传 `$args`，所以 `where()` / `fieldRaw()` / `field()` / `whereIn()` 全部蒸发。

### 修复

1. `AsyncModelTrait::__callStatic()` 转发链头调用，并区分「链式方法」与「终止型方法」：

```php
$query  = new AsyncQueryWrapper($modelClass, $tableName);
$result = $query->$method(...$args);

// count/sum/avg/max/min/column/value 返回标量或数组，不能再包 builder
if ($result !== $query) {
    return $result;
}

return new AsyncQueryBuilder($query, $modelClass);
```

2. `AsyncQueryWrapper` 补齐条件构造能力与语义修正：

- 新增 `whereOr`、`whereNull`、`whereNotNull`、`whereLike`、`whereNotLike`、
  `whereBetween`、`whereNotBetween`、`whereRaw`、`column`、`value`、`first`、
  `page`、`orderRaw`
- `where()` 两参数形式修正：`where('name', 'john')` 之前被当成
  `name john NULL`（非法 SQL），现在按 `name = 'john'` 处理
- `buildWhereClause()` 改为按操作符分派：`between` 之前被当成 `IN` 展开成
  `BETWEEN (a, b)`（非法 SQL）；`in` 现在统一走 `IN (...)`，空数组按
  `1 = 0 / 1 = 1` 处理，语义与 SQL 一致
- 操作符增加白名单校验，未知操作符直接抛 `AsyncQueryException`
- `order()` 支持多次调用、数组形式、`order('create_time desc')` 写法，方向做白名单
- `count()` 在存在 `GROUP BY` 时改为对子查询 `COUNT(*)`，统计的是分组数
- `column()` / `value()` 尊重已设置的 `where/order/limit`

3. 不再静默吞异常 / 吞方法：

- `AsyncQueryWrapper::__call()` 以前 `return $this`，未知方法被无声忽略
  （`paginate()`、`whereDate()` 等会悄悄少条件）。现在抛
  `BadMethodCallException`，并在消息里列出支持的方法与降级方案
- 立即执行的查询（`sum/count/column/value`）以前 catch 后返回 `0`，
  SQL 语法错误表现为"统计值恒为 0"。现在默认抛 `AsyncQueryException`
  （含出错 SQL），可用 `AsyncQueryWrapper::$throwOnError = false` 恢复旧行为

### 影响面

- 行为变更（需要留意）：异步上下文里调用未实现的方法会抛异常，而不是静默返回。
  这是刻意的——静默少条件会产生"看起来正常但数字不对"的数据问题，代价远高于报错。
- 兼容：`find()` / `select()` 的延迟占位符行为不变。

### 验证

在真实业务库上用同一份数据对比「原生 ORM」与「AsyncContext 内异步」的结果，
覆盖 `sum`、`fieldRaw + group + select`、`column + limit`、两参数 `where(字符串)`、
`whereOr`、`whereBetween`、`whereRaw`，全部逐值一致；未实现方法按预期抛错。

## 2026-11-26: 异步执行器连接池 + 失败快速返回

### 问题描述

`AsyncQuery::executeAsyncQueries()` 为每条 SQL `new mysqli()` 建独立连接。同机房建连 1~5ms 无所谓，
但跨地域/走 VPN 时一次握手 150~250ms，且这批建连是**串行**的：

```
6 条查询 × 200ms 建连 = 1.2s，全部查询实际执行只有几十毫秒
```

并行收益被建连成本完全吃掉——一个看板接口从 1.8s 变成 3~7s。

### 修复

1. 增加跨请求复用的空闲连接池（`AsyncQuery::$connectionPool`，按 host/port/user/db 分桶）：

```php
$conn = $this->borrowConnection();   // 池里有就直接用
...
$this->releaseConnection($conn);     // 成功的连接归还，出错的连接直接关闭
```

workerman 等常驻进程的 worker 可直接复用，建连成本只在进程启动后的首批请求发生。
池大小上限 `$maxIdleConnections = 16`（等于一批查询的最大并发数），另提供 `AsyncQuery::flushPool()`。

2. 不要用 `mysqli::ping()` 探活。PHP 8.4 起该方法已废弃，调用会抛
`mysqli::ping() is deprecated ...`——第一版实现因此把所有池内连接误判为失效，
日志显示池里连接数一直增长但复用次数为 0。改为不做探活：连接真断了，
`query()` 会抛连接类异常，由重试分支**用新建连接**重跑（不能再用 `borrowConnection()`，
否则会拿到池里同样失效的连接）。

3. `reap_async_query()` 返回 `false`（查询失败）时立即记录错误并从 pending 移除。
旧实现只在 `$result` 为真时 `unset($pending[$key])`，失败/超时的查询会一直留在 pending 里，
直到 `$timeout`（默认 10s）才返回，表现为接口偶发卡 10 秒。

### 效果

本地（走 VPN，RTT≈50ms、建连≈200ms）实测同一接口：

| 场景 | 改造前（原生串行） | 改造后（3 批并行 + 连接池） |
| --- | --- | --- |
| 冷请求（进程刚启动） | 1.8~2.1s | 4.7s（首批要建 7 条连接） |
| 冷请求（池已热） | 1.8~2.1s | 0.62~0.91s |

同机房部署时建连几乎为 0，热池路径只剩 3 个并行批次的实际查询耗时。

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
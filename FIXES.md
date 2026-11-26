# 修复记录

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
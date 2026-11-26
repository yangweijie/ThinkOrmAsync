<?php
namespace Yangweijie\ThinkOrmAsync;

use think\db\BaseQuery;
use Yangweijie\ThinkOrmAsync\Exception\AsyncQueryException;

class AsyncQueryWrapper {
    /**
     * 立即执行的查询（sum/count/avg/max/min/column/value）出错时是否抛异常。
     * 之前这里把异常吞掉返回 0，导致 SQL 错误表现为"统计值恒为 0"，极难排查。
     * 如需保留旧的宽松行为，可在启动时设为 false。
     */
    public static bool $throwOnError = true;

    /**
     * 可以安全忽略的方法：只影响执行计划提示，不影响结果集。
     */
    private static array $ignorableMethods = ['force'];

    /**
     * where 允许的操作符白名单
     */
    private const OPERATORS = [
        '=', '<>', '!=', '>', '>=', '<', '<=',
        'LIKE', 'NOT LIKE', 'IN', 'NOT IN',
        'BETWEEN', 'NOT BETWEEN',
        'IS NULL', 'IS NOT NULL',
        'REGEXP', 'NOT REGEXP', 'EXP',
    ];

    private string $modelClass;
    private array $whereConditions = [];
    private array $orConditions = [];
    private array $rawConditions = [];
    private array $options = [];
    private ?int $primaryKeyValue = null;
    private ?string $tableName = null;
    private ?string $lastError = null;

    public function __construct(string $modelClass, ?string $tableName = null) {
        $this->modelClass = $modelClass;
        $this->tableName = $tableName ?? $this->getDefaultTableName();
    }

    private function getDefaultTableName(): string {
        $className = basename(str_replace('\\', '/', $this->modelClass));
        // ThinkPHP snake_case: AppInfo → app_info
        if (!ctype_lower($className)) {
            $className = preg_replace('/\s+/u', '', $className);
            $className = mb_strtolower(preg_replace('/(.)(?=[A-Z])/u', '$1_', $className), 'UTF-8');
        }
        return $className;
    }

    public function setPrimaryKeyValue($value): void {
        $this->primaryKeyValue = $value;
    }

    public function getPrimaryKeyValue(): ?int {
        return $this->primaryKeyValue;
    }

    /**
     * 最近一次立即执行查询的错误信息（$throwOnError = false 时用于排查）
     */
    public function getLastError(): ?string {
        return $this->lastError;
    }

    // ==================== Query Builder Methods ====================

    public function where($field, $op = null, $value = null) {
        $argc = func_num_args();

        if (is_array($field)) {
            $this->whereConditions = array_merge($this->whereConditions, $this->normalizeArrayConditions($field));
            return $this;
        }

        $this->whereConditions[] = $this->normalizeCondition($field, $op, $value, $argc);
        return $this;
    }

    public function whereOr($field, $op = null, $value = null) {
        $argc = func_num_args();

        if (is_array($field)) {
            $this->orConditions = array_merge($this->orConditions, $this->normalizeArrayConditions($field));
            return $this;
        }

        $this->orConditions[] = $this->normalizeCondition($field, $op, $value, $argc);
        return $this;
    }

    public function whereIn($field, $values) {
        $this->whereConditions[] = [
            'field' => $field,
            'op' => 'IN',
            'value' => $values,
        ];
        return $this;
    }

    public function whereNotIn($field, $values) {
        $this->whereConditions[] = [
            'field' => $field,
            'op' => 'NOT IN',
            'value' => $values,
        ];
        return $this;
    }

    public function whereNull($field) {
        $this->whereConditions[] = ['field' => $field, 'op' => 'IS NULL', 'value' => null];
        return $this;
    }

    public function whereNotNull($field) {
        $this->whereConditions[] = ['field' => $field, 'op' => 'IS NOT NULL', 'value' => null];
        return $this;
    }

    public function whereLike($field, $value) {
        $this->whereConditions[] = ['field' => $field, 'op' => 'LIKE', 'value' => $value];
        return $this;
    }

    public function whereNotLike($field, $value) {
        $this->whereConditions[] = ['field' => $field, 'op' => 'NOT LIKE', 'value' => $value];
        return $this;
    }

    public function whereBetween($field, $range) {
        $this->whereConditions[] = ['field' => $field, 'op' => 'BETWEEN', 'value' => $range];
        return $this;
    }

    public function whereNotBetween($field, $range) {
        $this->whereConditions[] = ['field' => $field, 'op' => 'NOT BETWEEN', 'value' => $range];
        return $this;
    }

    /**
     * 原生条件片段，多个片段之间用 AND 连接。
     * 支持 ? 占位符（按顺序用 $bind 转义后替换）。
     */
    public function whereRaw($sql, array $bind = []) {
        if (!empty($bind)) {
            $parts = explode('?', $sql);
            $sql = array_shift($parts);
            foreach ($parts as $i => $part) {
                $sql .= $this->escapeValue($bind[$i] ?? null) . $part;
            }
        }
        $this->rawConditions[] = (string) $sql;
        return $this;
    }

    public function field($fields) {
        $this->options['field'] = $fields;
        return $this;
    }

    public function fieldRaw($expression) {
        $this->options['fieldRaw'] = $expression;
        return $this;
    }

    public function distinct($distinct = true) {
        $this->options['distinct'] = $distinct;
        return $this;
    }

    public function alias($alias) {
        $this->options['alias'] = $alias;
        return $this;
    }

    public function order($field, $order = null) {
        if (is_array($field)) {
            foreach ($field as $key => $val) {
                if (is_int($key)) {
                    $this->pushOrder((string) $val, null);
                } else {
                    $this->pushOrder((string) $key, (string) $val);
                }
            }
            return $this;
        }

        $this->pushOrder((string) $field, $order === null ? null : (string) $order);
        return $this;
    }

    /**
     * 原生排序片段，如 orderRaw('field IS NULL, id DESC')
     */
    public function orderRaw(string $sql) {
        $this->options['order'][] = ['raw' => true, 'sql' => $sql];
        return $this;
    }

    public function limit($offset, $length = null) {
        $this->options['limit'] = [$offset, $length];
        return $this;
    }

    public function offset($offset) {
        $this->options['offset'] = $offset;
        return $this;
    }

    public function page(int $page, int $listRows = 20) {
        $page = max(1, $page);
        $this->options['limit'] = [($page - 1) * $listRows, $listRows];
        return $this;
    }

    public function group($field) {
        $this->options['group'] = $field;
        return $this;
    }

    public function having($field, $op = null, $value = null) {
        $this->options['having'] = [$field, $op, $value];
        return $this;
    }

    public function join($table, $on = null, $type = null) {
        $this->options['join'][] = [$table, $on, $type];
        return $this;
    }

    public function leftJoin($table, $on = null) {
        return $this->join($table, $on, 'LEFT');
    }

    public function rightJoin($table, $on = null) {
        return $this->join($table, $on, 'RIGHT');
    }

    public function innerJoin($table, $on = null) {
        return $this->join($table, $on, 'INNER');
    }

    /**
     * 关联预载：保存归一化后的关联树，在结果回填阶段并行加载。
     *
     * 旧实现直接抛异常，导致异步上下文里根本没法用 with()。
     * 现在支持 HasOne / HasMany / BelongsTo / BelongsToMany 的异步预载，
     * Morph* / Through 等复杂关联走 ThinkPHP 原生预载降级。
     */
    public function with($relations) {
        $this->options['with'] = AsyncRelationLoader::normalize($relations);
        return $this;
    }

    public function cache($key = true, $expire = null, $tag = null) {
        $this->options['cache'] = AsyncQueryCache::normalize($key, $expire, $tag);
        return $this;
    }

    /**
     * 给 AsyncContext 读取归一化后的缓存配置
     */
    public function getCacheOption(): ?array {
        return $this->options['cache'] ?? null;
    }

    /**
     * 给 AsyncContext / 立即执行路径读取归一化后的关联树
     */
    public function getWithOption(): array {
        return $this->options['with'] ?? [];
    }

    private function normalizeCondition($field, $op, $value, int $argc): array {
        if ($argc <= 2) {
            // where('field', value) —— 两参数形式的第二个参数是值，不是操作符
            if (is_string($op)) {
                $lower = strtolower(trim($op));
                if ($lower === 'null') {
                    return ['field' => $field, 'op' => 'IS NULL', 'value' => null];
                }
                if ($lower === 'not null') {
                    return ['field' => $field, 'op' => 'IS NOT NULL', 'value' => null];
                }
            }
            return ['field' => $field, 'op' => '=', 'value' => $op];
        }

        return ['field' => $field, 'op' => (string) $op, 'value' => $value];
    }

    private function normalizeArrayConditions(array $conditions): array {
        if (empty($conditions)) {
            return [];
        }

        $result = [];
        $first = reset($conditions);

        if (is_array($first) && isset($first[0])) {
            // ThinkPHP 数组套数组：[['field','op','value'], ...]
            foreach ($conditions as $cond) {
                if (!is_array($cond)) {
                    continue;
                }
                $values = array_values($cond);
                if (count($values) >= 3) {
                    $result[] = ['field' => $values[0], 'op' => (string) $values[1], 'value' => $values[2]];
                } elseif (count($values) === 2) {
                    $result[] = ['field' => $values[0], 'op' => '=', 'value' => $values[1]];
                }
            }
            return $result;
        }

        // 关联数组：['field' => value, ...]
        foreach ($conditions as $key => $val) {
            if (is_int($key)) {
                continue;
            }
            $result[] = ['field' => $key, 'op' => '=', 'value' => $val];
        }
        return $result;
    }

    private function pushOrder(string $field, ?string $dir): void {
        $field = trim($field);
        if ($field === '') {
            return;
        }

        // 兼容 order('create_time desc') 这种把方向写在字段里的写法
        if ($dir === null && preg_match('/^(.+?)\s+(asc|desc)$/i', $field, $m)) {
            $field = $m[1];
            $dir = $m[2];
        }

        if ($dir !== null && !in_array(strtoupper($dir), ['ASC', 'DESC'], true)) {
            $dir = null;
        }

        $this->options['order'][] = [
            'raw' => false,
            'field' => $field,
            'dir' => $dir === null ? null : strtoupper($dir),
        ];
    }

    // ==================== Scope Support ====================

    /**
     * Support Model::scope('name', args...) in async context.
     * Creates a temporary model instance and calls its scopeXxx() method.
     */
    public function scope($name, ...$args) {
        $modelClass = $this->modelClass;
        if (class_exists($modelClass)) {
            $instance = new $modelClass();
            $method = 'scope' . ucwords($name);
            if (method_exists($instance, $method)) {
                call_user_func_array([$instance, $method], array_merge([$this], $args));
            }
        }
        return $this;
    }

    // ==================== Select/Find ====================

    /**
     * SELECT — 在异步上下文中延迟执行（加入批量并行），否则立即执行。
     */
    public function select() {
        if (AsyncContext::isActive()) {
            // 延迟：加入异步上下文，等 end() 时并行执行
            $key = $this->buildQueryKey('select');
            AsyncContext::getInstance()->addQuery($key, $this, 'select');
            return new AsyncResultPlaceholder($key, 'select');
        }
        // 非异步：立即执行
        return $this->executeImmediateSelect();
    }

    /**
     * SELECT ... LIMIT 1 — 在异步上下文中延迟执行，否则立即执行。
     */
    public function find() {
        if (AsyncContext::isActive()) {
            $key = $this->buildQueryKey('find');
            AsyncContext::getInstance()->addQuery($key, $this, 'find');
            return new AsyncResultPlaceholder($key, 'find');
        }
        return $this->executeImmediateFind();
    }

    /**
     * first() 与 find() 同义
     */
    public function first() {
        return $this->find();
    }

    /**
     * 立即执行 select，返回 Collection
     */
    private function executeImmediateSelect(): \think\Collection {
        $sql = $this->buildSql('select');
        $rows = $this->executeImmediateWithCache($sql);
        $modelClass = $this->modelClass;

        if ($modelClass && class_exists($modelClass)) {
            $models = [];
            foreach ($rows as $row) {
                $models[] = new $modelClass($row);
            }
            $this->applyRelations($models);
            return new \think\Collection($models);
        }

        return new \think\Collection($rows);
    }

    /**
     * 立即执行 find，返回 model 或 null
     */
    private function executeImmediateFind() {
        $sql = $this->buildSql('find');
        $rows = $this->executeImmediateWithCache($sql);

        if (empty($rows)) {
            return null;
        }

        $row = $rows[0];
        $modelClass = $this->modelClass;

        if ($modelClass && class_exists($modelClass)) {
            $model = new $modelClass($row);
            $this->applyRelations([$model]);
            return $model;
        }

        return $row;
    }

    /**
     * 立即执行路径的缓存读写，与异步路径共用同一套 key 规则。
     */
    private function executeImmediateWithCache(string $sql): array {
        $cache = $this->getCacheOption();
        if ($cache !== null) {
            $cacheKey = AsyncQueryCache::makeKey($sql, $cache[0]);
            $cached = AsyncQueryCache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $rows = $this->executeImmediateAll($sql);

        if ($cache !== null) {
            AsyncQueryCache::set($cacheKey, $rows, $cache[1], $cache[2]);
        }

        return $rows;
    }

    private function applyRelations(array $models): void {
        $with = $this->getWithOption();
        if (empty($with) || empty($models)) {
            return;
        }

        (new AsyncRelationLoader())->eagerLoad($this->modelClass, $models, $with);
    }

    private function buildQueryKey(string $method): string {
        // with 里可能带闭包，serialize() 会直接抛错；cache/with 也不影响 SQL 本身
        $options = $this->options;
        unset($options['with'], $options['cache']);

        return md5($this->modelClass . '_' . $method . '_' . md5(serialize([
            $this->whereConditions,
            $this->orConditions,
            $this->rawConditions,
            $options,
        ])) . '_' . uniqid('', true));
    }

    // ==================== Aggregate Methods ====================

    public function count($field = '*') {
        // 带 GROUP BY 时 count(*) 统计的应该是分组数量，而不是总行数
        if (!empty($this->options['group'])) {
            $inner = $this->buildSql('select');
            return (int) $this->executeImmediate("SELECT COUNT(*) AS aggregate FROM ({$inner}) AS __async_sub");
        }

        $sql = $this->buildAggregateSql('COUNT', $field);
        return (int) $this->executeImmediate($sql);
    }

    public function sum($field) {
        $sql = $this->buildAggregateSql('SUM', $field);
        return (float) $this->executeImmediate($sql);
    }

    public function avg($field) {
        $sql = $this->buildAggregateSql('AVG', $field);
        return (float) $this->executeImmediate($sql);
    }

    public function max($field) {
        $sql = $this->buildAggregateSql('MAX', $field);
        return $this->executeImmediate($sql);
    }

    public function min($field) {
        $sql = $this->buildAggregateSql('MIN', $field);
        return $this->executeImmediate($sql);
    }

    /**
     * 取一列的值，行为对齐 think-orm 的 column($field, $key)
     */
    public function column($field, $key = '') {
        $fieldSql = $this->quoteField((string) $field);
        $keySql = null;

        if ($key !== '' && $key !== null) {
            $keySql = $this->quoteField((string) $key);
            $fieldSql = $keySql . ', ' . $fieldSql;
        }

        $rows = $this->executeImmediateAll($this->buildSelectSql($fieldSql, $this->buildLimitClause()));
        if (empty($rows)) {
            return [];
        }

        $fieldName = $this->plainFieldName((string) $field);

        if ($keySql !== null) {
            return array_column($rows, $fieldName, $this->plainFieldName((string) $key));
        }

        return array_column($rows, $fieldName);
    }

    /**
     * 取单行单列的值，行为对齐 think-orm 的 value($field)
     */
    public function value($field = '*') {
        $fieldSql = $field === '*' ? '*' : $this->quoteField((string) $field);

        $limitSql = $this->buildLimitClause();

        $rows = $this->executeImmediateAll($this->buildSelectSql($fieldSql, $limitSql === '' ? ' LIMIT 1' : $limitSql));
        if (empty($rows)) {
            return null;
        }

        if ($field === '*') {
            return $rows[0];
        }

        return $rows[0][$this->plainFieldName((string) $field)] ?? null;
    }

    private function executeImmediate(string $sql) {
        $result = $this->executeImmediateAll($sql);

        if (!empty($result)) {
            $firstRow = reset($result);
            $firstValue = is_array($firstRow) ? reset($firstRow) : $firstRow;
            if (is_numeric($firstValue)) {
                return $firstValue;
            }
        }

        return 0;
    }

    /**
     * Execute SQL and return ALL rows as array of associative arrays.
     */
    private function executeImmediateAll(string $sql): array {
        try {
            $connection = $this->getModelConnection();
            $query = $connection ? \think\facade\Db::connect($connection) : \think\facade\Db::connect();
            $result = $query->query($sql);
            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage() . ' [SQL] ' . $sql;

            if (self::$throwOnError) {
                throw new AsyncQueryException(
                    'Async query failed: ' . $e->getMessage() . ' [SQL] ' . $sql,
                    ['error' => $e->getMessage(), 'sql' => $sql],
                    0,
                    $e
                );
            }

            return [];
        }
    }

    private function getModelConnection(): ?string {
        $modelClass = $this->modelClass;
        if (class_exists($modelClass)) {
            try {
                $reflection = new \ReflectionClass($modelClass);
                if ($reflection->hasProperty('connection')) {
                    $prop = $reflection->getProperty('connection');
                    $prop->setAccessible(true);
                    $val = $prop->getValue(null);
                    return is_string($val) ? $val : null;
                }
            } catch (\Throwable $e) {
            }
        }
        return null;
    }

    // ==================== SQL Generation ====================

    /**
     * Build SQL for select queries (find/select)
     */
    public function buildSql(string $method = 'select') {
        $limitSql = $this->buildLimitClause();

        // For find: add LIMIT 1 if not already present
        if ($method === 'find' && $limitSql === '') {
            $limitSql = ' LIMIT 1';
        }

        return $this->buildSelectSql($this->buildFieldSql(), $limitSql);
    }

    /**
     * 用指定字段列表拼装 SELECT，column()/value() 复用同一套 WHERE/JOIN/GROUP/ORDER
     */
    private function buildSelectSql(string $fieldSql, string $limitSql = ''): string {
        $table = $this->getTable();
        $alias = $this->options['alias'] ?? null;
        $distinct = !empty($this->options['distinct']) ? 'DISTINCT ' : '';

        $tableSql = $alias ? "`{$table}` AS `{$alias}`" : "`{$table}`";

        return "SELECT {$distinct}{$fieldSql} FROM {$tableSql}"
            . $this->buildJoinClause()
            . $this->buildWhereClause()
            . $this->buildGroupClause()
            . $this->buildHavingClause()
            . $this->buildOrderClause()
            . $limitSql;
    }

    /**
     * Build SQL for aggregate queries (count/sum/avg/max/min)
     */
    public function buildAggregateSql(string $aggregateType, $field = '*') {
        $table = $this->getTable();
        $alias = $this->options['alias'] ?? null;

        // Build aggregate field
        $aggregateField = strtoupper($aggregateType) . '(' . ($field === '*' ? '*' : $field) . ')';

        // Build table clause
        $tableSql = $alias ? "`{$table}` AS `{$alias}`" : "`{$table}`";

        // Build WHERE clause
        $whereSql = $this->buildWhereClause();

        // Build JOIN clause
        $joinSql = $this->buildJoinClause();

        // Build GROUP BY clause (for aggregate with group, we need subquery or HAVING)
        // For simple aggregates, GROUP BY is not needed in the SQL

        return "SELECT {$aggregateField} FROM {$tableSql}{$joinSql}{$whereSql}";
    }

    private function buildFieldSql() {
        // Priority: fieldRaw > field > *
        if (!empty($this->options['fieldRaw'])) {
            $raw = $this->options['fieldRaw'];
            return is_array($raw) ? implode(', ', $raw) : (string) $raw;
        }

        if (!empty($this->options['field'])) {
            $field = $this->options['field'];
            if (is_array($field)) {
                return implode(', ', array_map(function ($f) {
                    return $this->quoteField((string) $f);
                }, $field));
            }
            return (string) $field;
        }

        return '*';
    }

    private function buildWhereClause() {
        $andParts = [];
        foreach ($this->whereConditions as $cond) {
            $andParts[] = $this->buildSingleCondition($cond);
        }
        foreach ($this->rawConditions as $raw) {
            $andParts[] = '(' . $raw . ')';
        }

        $orParts = [];
        foreach ($this->orConditions as $cond) {
            $orParts[] = $this->buildSingleCondition($cond);
        }

        if (!empty($andParts) && !empty($orParts)) {
            return ' WHERE (' . implode(' AND ', $andParts) . ') OR (' . implode(' OR ', $orParts) . ')';
        }

        if (!empty($andParts)) {
            return ' WHERE ' . implode(' AND ', $andParts);
        }

        if (!empty($orParts)) {
            return ' WHERE ' . implode(' OR ', $orParts);
        }

        return '';
    }

    private function buildSingleCondition(array $cond): string {
        $field = trim((string) ($cond['field'] ?? ''));
        $op = strtoupper(trim((string) ($cond['op'] ?? '=')));
        $value = $cond['value'] ?? null;

        if ($field === '') {
            throw new AsyncQueryException('Empty field name in where condition');
        }

        if ($op === '') {
            $op = '=';
        }

        // ThinkPHP 里 ['field', 'null'] / ['field', 'not null'] 的写法
        $nullOps = ['NULL' => 'IS NULL', 'NOT NULL' => 'IS NOT NULL'];
        if (isset($nullOps[$op])) {
            $op = $nullOps[$op];
        }

        if (!in_array($op, self::OPERATORS, true)) {
            throw new AsyncQueryException("Unsupported where operator: {$op} (field: {$field})");
        }

        $quoted = $this->quoteField($field);

        // 原生表达式：where('field', 'exp', '> 1')
        if ($op === 'EXP') {
            return $quoted . ' ' . (string) $value;
        }

        if ($op === 'IS NULL' || ($op === '=' && $value === null)) {
            return $quoted . ' IS NULL';
        }

        if ($op === 'IS NOT NULL' || (in_array($op, ['<>', '!='], true) && $value === null)) {
            return $quoted . ' IS NOT NULL';
        }

        if ($op === 'BETWEEN' || $op === 'NOT BETWEEN') {
            $values = is_array($value) ? array_values($value) : [];
            if (count($values) < 2) {
                throw new AsyncQueryException("{$op} requires 2 values (field: {$field})");
            }
            return $quoted . ' ' . $op . ' '
                . $this->escapeValue($values[0]) . ' AND ' . $this->escapeValue($values[1]);
        }

        if (in_array($op, ['IN', 'NOT IN'], true) || is_array($value)) {
            $values = is_array($value) ? array_values($value) : [$value];
            $not = in_array($op, ['NOT IN', '<>', '!='], true);

            if (empty($values)) {
                // 空集合：IN 恒假、NOT IN 恒真，保持与 SQL 语义一致
                return $not ? '1 = 1' : '1 = 0';
            }

            $escaped = implode(', ', array_map([$this, 'escapeValue'], $values));
            return $quoted . ($not ? ' NOT IN (' : ' IN (') . $escaped . ')';
        }

        return $quoted . ' ' . $op . ' ' . $this->escapeValue($value);
    }

    private function buildGroupClause(): string {
        if (empty($this->options['group'])) {
            return '';
        }

        $group = $this->options['group'];
        if (is_array($group)) {
            $group = implode(', ', $group);
        }

        return ' GROUP BY ' . $group;
    }

    private function buildHavingClause(): string {
        if (empty($this->options['having'])) {
            return '';
        }

        [$f, $op, $v] = $this->options['having'];

        return ' HAVING ' . $f . ' ' . $op . ' ' . $this->escapeValue($v);
    }

    private function buildOrderClause(): string {
        if (empty($this->options['order'])) {
            return '';
        }

        $parts = [];
        foreach ($this->options['order'] as $order) {
            if (!empty($order['raw'])) {
                $parts[] = $order['sql'];
                continue;
            }

            $field = $order['field'];
            // 已带反引号、函数、表别名或表达式的保持原样
            $fieldSql = preg_match('/[`()\s.]/', $field) ? $field : ('`' . $field . '`');
            $parts[] = $fieldSql . ($order['dir'] ? ' ' . $order['dir'] : '');
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    private function buildLimitClause(): string {
        $offset = $this->options['offset'] ?? null;

        if (!empty($this->options['limit'])) {
            [$limitOffset, $length] = $this->options['limit'];
            if ($length !== null) {
                return ' LIMIT ' . (int) $limitOffset . ', ' . (int) $length;
            }
            return ' LIMIT ' . (int) $limitOffset;
        }

        if ($offset !== null) {
            return ' LIMIT ' . (int) $offset . ', 18446744073709551615';
        }

        return '';
    }

    private function buildJoinClause() {
        if (empty($this->options['join'])) {
            return '';
        }

        $clauses = [];
        foreach ($this->options['join'] as $join) {
            [$table, $on, $type] = $join;
            $type = $type ?: 'INNER';
            $clauses[] = ' ' . $type . ' JOIN ' . $table . ' ON ' . $on;
        }

        return implode('', $clauses);
    }

    /**
     * 字段名安全引号：支持 a.b、已带反引号的、以及函数/表达式原样透传
     */
    private function quoteField(string $field): string {
        $field = trim($field);

        if ($field === '') {
            return $field;
        }

        // 表达式 / 函数 / 已带引号 → 原样返回
        if (preg_match('/[`()\s+*\/,]/', $field)) {
            return $field;
        }

        if (strpos($field, '.') !== false) {
            return implode('.', array_map(function ($part) {
                return '`' . $part . '`';
            }, explode('.', $field)));
        }

        return '`' . $field . '`';
    }

    /**
     * 从字段表达式里取出结果集里的列名（支持 "a as b"）
     */
    private function plainFieldName(string $field): string {
        $field = trim($field);

        if (preg_match('/\bas\s+([A-Za-z_][A-Za-z0-9_]*)\s*$/i', $field, $m)) {
            return $m[1];
        }

        $field = str_replace('`', '', $field);

        if (strpos($field, '.') !== false) {
            $parts = explode('.', $field);
            $field = end($parts);
        }

        return preg_replace('/[^A-Za-z0-9_]/', '', $field);
    }

    private function escapeValue($value) {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . addslashes((string) $value) . "'";
    }

    // ==================== Accessors ====================

    public function getPk() {
        return 'id';
    }

    public function model($model = null) {
        if ($model !== null) {
            $this->options['model'] = $model;
        }
        return $this->options['model'] ?? $this->modelClass;
    }

    public function getConnection() {
        $wrapper = $this;
        return new class($wrapper) {
            private $wrapper;

            public function __construct($wrapper) {
                $this->wrapper = $wrapper;
            }

            public function getBuilder() {
                $wrapper = $this->wrapper;
                return new class($wrapper) {
                    private $wrapper;

                    public function __construct($wrapper) {
                        $this->wrapper = $wrapper;
                    }

                    public function select($query, $one = false) {
                        return $query->buildSql($one ? 'find' : 'select');
                    }
                    public function insert() {
                        return 'INSERT INTO table';
                    }
                    public function update() {
                        return 'UPDATE table';
                    }
                    public function delete() {
                        return 'DELETE FROM table';
                    }
                };
            }
            public function getRealSql($sql, $bind) {
                return $sql;
            }
        };
    }

    public function getConfig() {
        return [];
    }

    public function getBind() {
        return [];
    }

    public function parseOptions() {
        return [];
    }

    public function parsePkWhere($data) {
        return null;
    }

    public function getOptions() {
        return $this->options;
    }

    public function getTable() {
        return $this->tableName ?? strtolower($this->modelClass);
    }

    /**
     * 未显式实现的方法不再静默吞掉。
     *
     * 旧实现直接 `return $this`，于是 Model::whereNull(...) / column(...) / whereOr(...)
     * 这类方法会被无声丢弃，SQL 少一截条件、统计结果看起来"正常但不对"，极难排查。
     * 现在改为快速失败，错误信息里直接给出替代方案。
     */
    public function __call($method, $args) {
        if (in_array($method, self::$ignorableMethods, true)) {
            return $this;
        }

        throw new \BadMethodCallException(sprintf(
            'AsyncQueryWrapper does not support %s::%s() in async context. '
            . 'Supported chain methods: where/whereOr/whereIn/whereNull/whereBetween/whereLike/whereRaw, '
            . 'field/fieldRaw/alias/group/having/order/limit/page, join, scope, select/find, '
            . 'count/sum/avg/max/min, column/value. '
            . 'Fallback: run this query outside AsyncContext (native think-orm).',
            $this->modelClass,
            $method
        ));
    }
}

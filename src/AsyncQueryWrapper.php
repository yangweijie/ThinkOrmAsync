<?php
namespace Yangweijie\ThinkOrmAsync;

use think\db\BaseQuery;

class AsyncQueryWrapper {
    private string $modelClass;
    private array $whereConditions = [];
    private array $options = [];
    private ?int $primaryKeyValue = null;
    private ?string $tableName = null;
    
    public function __construct(string $modelClass, ?string $tableName = null) {
        $this->modelClass = $modelClass;
        $this->tableName = $tableName ?? $this->getDefaultTableName();
    }
    
    private function getDefaultTableName(): string {
        $className = basename(str_replace('\\', '/', $this->modelClass));
        return strtolower($className);
    }
    
    public function setPrimaryKeyValue($value): void {
        $this->primaryKeyValue = $value;
    }
    
    public function getPrimaryKeyValue(): ?int {
        return $this->primaryKeyValue;
    }
    
    // ==================== Query Builder Methods ====================
    
    public function where($field, $op = null, $value = null) {
        if (is_array($field)) {
            // where(['field1' => value1, 'field2' => value2])
            foreach ($field as $k => $v) {
                $this->whereConditions[] = ['field' => $k, 'op' => '=', 'value' => $v];
            }
        } elseif ($value === null && !is_string($op)) {
            // where('field', value) — 2 args, op is actually the value, implicit =
            $this->whereConditions[] = ['field' => $field, 'op' => '=', 'value' => $op];
        } else {
            // where('field', 'op', value) — 3 args with explicit operator
            $this->whereConditions[] = ['field' => $field, 'op' => $op, 'value' => $value];
        }
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
        $this->options['order'] = [$field, $order];
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
    
    public function with($relations) {
        $this->options['with'] = $relations;
        return $this;
    }
    
    public function cache($key = true, $expire = null, $tag = null) {
        $this->options['cache'] = [$key, $expire, $tag];
        return $this;
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
    
    // ==================== Select/Find (Immediate Execution) ====================
    
    /**
     * Execute SELECT and return a Collection of model instances or raw arrays.
     * Called directly by model code in async context (bypasses AsyncContext batching).
     */
    public function select() {
        $sql = $this->buildSql('select');
        $rows = $this->executeImmediateAll($sql);
        $modelClass = $this->modelClass;
        
        if ($modelClass && class_exists($modelClass)) {
            $models = [];
            foreach ($rows as $row) {
                $models[] = new $modelClass($row);
            }
            return new \think\Collection($models);
        }
        
        return new \think\Collection($rows);
    }
    
    /**
     * Execute SELECT ... LIMIT 1 and return a single model instance or null.
     */
    public function find() {
        $sql = $this->buildSql('find');
        $rows = $this->executeImmediateAll($sql);
        $modelClass = $this->modelClass;
        
        if (empty($rows)) {
            return null;
        }
        
        $row = $rows[0];
        
        if ($modelClass && class_exists($modelClass)) {
            return new $modelClass($row);
        }
        
        return $row;
    }
    
    // ==================== Aggregate Methods ====================
    
    public function count($field = '*') {
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
    
    private function executeImmediate(string $sql) {
        try {
            $result = $this->executeImmediateAll($sql);
            if (!empty($result)) {
                $firstRow = reset($result);
                $firstValue = is_array($firstRow) ? reset($firstRow) : $firstRow;
                if (is_numeric($firstValue)) {
                    return $firstValue;
                }
            }
            return 0;
        } catch (\Throwable $e) {
            return 0;
        }
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
        $table = $this->getTable();
        $alias = $this->options['alias'] ?? null;
        $distinct = !empty($this->options['distinct']) ? 'DISTINCT ' : '';
        
        // Build field expression
        $fieldSql = $this->buildFieldSql();
        
        // Build table clause
        $tableSql = $alias ? "`{$table}` AS `{$alias}`" : "`{$table}`";
        
        // Build WHERE clause
        $whereSql = $this->buildWhereClause();
        
        // Build JOIN clause
        $joinSql = $this->buildJoinClause();
        
        // Build GROUP BY clause
        $groupSql = !empty($this->options['group']) ? ' GROUP BY ' . $this->options['group'] : '';
        
        // Build HAVING clause
        $havingSql = '';
        if (!empty($this->options['having'])) {
            [$f, $op, $v] = $this->options['having'];
            $havingSql = ' HAVING ' . $f . ' ' . $op . ' ' . $this->escapeValue($v);
        }
        
        // Build ORDER BY clause
        $orderSql = '';
        if (!empty($this->options['order'])) {
            [$f, $o] = $this->options['order'];
            $orderSql = ' ORDER BY ' . $f . ($o ? ' ' . $o : '');
        }
        
        // Build LIMIT clause
        $limitSql = '';
        if (!empty($this->options['limit'])) {
            [$offset, $length] = $this->options['limit'];
            if ($length !== null) {
                $limitSql = ' LIMIT ' . (int)$offset . ', ' . (int)$length;
            } else {
                $limitSql = ' LIMIT ' . (int)$offset;
            }
        }
        
        // For find: add LIMIT 1 if not already present
        if ($method === 'find' && empty($this->options['limit'])) {
            $limitSql = ' LIMIT 1';
        }
        
        return "SELECT {$distinct}{$fieldSql} FROM {$tableSql}{$joinSql}{$whereSql}{$groupSql}{$havingSql}{$orderSql}{$limitSql}";
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
            return $this->options['fieldRaw'];
        }
        
        if (!empty($this->options['field'])) {
            $field = $this->options['field'];
            if (is_array($field)) {
                return implode(', ', array_map(function($f) {
                    return "`{$f}`";
                }, $field));
            }
            return $field;
        }
        
        return '*';
    }
    
    private function buildWhereClause() {
        if (empty($this->whereConditions)) {
            return '';
        }
        
        $clauses = [];
        foreach ($this->whereConditions as $i => $cond) {
            $prefix = $i === 0 ? '' : ' AND ';
            $field = $cond['field'];
            $op = $cond['op'];
            $value = $cond['value'];
            
            if (is_array($value)) {
                // IN / NOT IN — value is array
                $escaped = array_map([$this, 'escapeValue'], $value);
                $escapedStr = implode(', ', $escaped);
                $opUpper = strtoupper($op);
                $clauses[] = $prefix . '`' . $field . '` ' . $opUpper . ' (' . $escapedStr . ')';
            } else {
                // Normal comparison: field op value
                $clauses[] = $prefix . '`' . $field . '` ' . $op . ' ' . $this->escapeValue($value);
            }
        }
        
        return ' WHERE ' . implode('', $clauses);
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
    
    private function escapeValue($value) {
        if ($value === null) {
            return 'NULL';
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
    
    public function __call($method, $args) {
        // Fallback: return self for chain support
        return $this;
    }
}

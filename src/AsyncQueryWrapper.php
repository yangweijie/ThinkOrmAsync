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
        // 使用类名的小写版本作为默认表名
        $className = basename(str_replace('\\', '/', $this->modelClass));
        return strtolower($className);
    }
    
    public function setPrimaryKeyValue($value): void {
        $this->primaryKeyValue = $value;
    }
    
    public function getPrimaryKeyValue(): ?int {
        return $this->primaryKeyValue;
    }
    
    public function where($field, $op = null, $value = null) {
        $this->whereConditions[] = [
            'field' => $field,
            'op' => $op,
            'value' => $value,
        ];
        return $this;
    }
    
    public function field($fields) {
        $this->options['field'] = $fields;
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
                        $table = $this->wrapper->getTable();
                        if ($one && $this->wrapper->getPrimaryKeyValue() !== null) {
                            return "SELECT * FROM {$table} WHERE id = {$this->wrapper->getPrimaryKeyValue()}";
                        }
                        return "SELECT * FROM {$table}";
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
        // 对于其他方法，返回自身以支持链式调用
        return $this;
    }
}
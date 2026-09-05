<?php
namespace Yangweijie\ThinkOrmAsync;

use think\db\BaseQuery;

class AsyncContext {
    private static ?self $instance = null;
    private static bool $active = false;
    
    private array $queries = [];
    private array $results = [];
    private ?AsyncQuery $asyncQuery = null;
    private int $timeout = 10;
    
    private function __construct(?BaseQuery $query = null, ?array $dbConfig = null) {
        $this->asyncQuery = new AsyncQuery($query, $dbConfig);
    }
    
    public static function start(?BaseQuery $query = null, ?array $dbConfig = null): self {
        if (self::$active) {
            throw new \RuntimeException('Async context already started');
        }
        
        // 如果没有传递数据库配置，自动从 ThinkPHP 配置中读取
        if ($dbConfig === null) {
            $dbConfig = self::getDefaultDbConfig();
        }
        
        // 检查数据库类型是否为 MySQL
        self::checkDatabaseType($dbConfig);
        
        // 检查是否支持 mysqli_poll
        self::checkMysqliPollSupport();
        
        self::$active = true;
        self::$instance = new self($query, $dbConfig);
        
        return self::$instance;
    }
    
    private static function checkDatabaseType(array $dbConfig): void {
        // 检查数据库类型
        $dbType = $dbConfig['type'] ?? 'mysql';
        
        if (strtolower($dbType) !== 'mysql') {
            throw new \RuntimeException(
                "Async query only supports MySQL database, current database type is: {$dbType}"
            );
        }
    }
    
    private static function checkMysqliPollSupport(): void {
        // 检查 mysqli 扩展是否可用
        if (!extension_loaded('mysqli')) {
            throw new \RuntimeException(
                'mysqli extension is required for async queries but is not loaded'
            );
        }
        
        // 检查是否支持 MYSQLI_ASYNC 常量
        if (!defined('MYSQLI_ASYNC')) {
            throw new \RuntimeException(
                'mysqli extension does not support async queries (MYSQLI_ASYNC constant not defined)'
            );
        }
        
        // 检查是否支持 mysqli_poll 函数
        if (!function_exists('mysqli_poll')) {
            throw new \RuntimeException(
                'mysqli_poll function is not available, async queries are not supported'
            );
        }
    }
    
    private static function getDefaultDbConfig(): array {
        try {
            $config = [];
            
            if (function_exists('config')) {
                // Webman/ThinkPHP think-orm 配置路径
                $dbConfig = config('think-orm.connections.mysql', []);
                
                // 兼容标准 ThinkPHP database 配置路径
                if (empty($dbConfig)) {
                    $dbConfig = config('database.connections.mysql', []);
                }
                
                if (!empty($dbConfig)) {
                    $config = [
                        'hostname' => $dbConfig['hostname'] ?? $dbConfig['host'] ?? 'localhost',
                        'database' => $dbConfig['database'] ?? '',
                        'username' => $dbConfig['username'] ?? $dbConfig['user'] ?? 'root',
                        'password' => $dbConfig['password'] ?? '',
                        'hostport' => $dbConfig['hostport'] ?? $dbConfig['port'] ?? 3306,
                        'charset' => $dbConfig['charset'] ?? 'utf8mb4',
                    ];
                    
                    return $config;
                }
            }
            
            return [];
        } catch (\Exception $e) {
            return [];
        }
    }
    
    public static function end(): array {
        if (!self::$active || self::$instance === null) {
            // 幂等：上下文已结束，返回空结果
            return [];
        }
        
        self::$active = false;
        $instance = self::$instance;
        self::$instance = null;
        
        $results = $instance->execute();
        
        return $results;
    }
    
    public static function isActive(): bool {
        return self::$active;
    }
    
    public static function getInstance(): ?self {
        return self::$instance;
    }
    
    public function setTimeout(int $timeout): self {
        $this->timeout = $timeout;
        $this->asyncQuery->setTimeout($timeout);
        return $this;
    }
    
    public function addQuery(string $key, $query, string $method): void {
        $this->queries[$key] = [
            'query' => $query,
            'method' => $method,
            'sql' => $this->extractSql($query, $method),
            'model' => $this->getModelClass($query),
            'type' => 'orm',
        ];
    }
    
    public static function query(string $sql, string $key = ''): AsyncResultPlaceholder {
        if (!self::$active) {
            throw new \RuntimeException('Async context not started');
        }
        
        $instance = self::$instance;
        $queryKey = $key ?? md5($sql . '_' . uniqid('', true));
        
        $instance->queries[$queryKey] = [
            'sql' => $sql,
            'type' => 'raw',
        ];
        
        return new AsyncResultPlaceholder($queryKey, 'raw');
    }
    
    public function setResult(string $key, $value): void {
        $this->results[$key] = $value;
    }
    
    public function getResult(string $key) {
        return $this->results[$key] ?? null;
    }
    
    private function execute(): array {
        if (empty($this->queries)) {
            return $this->results;
        }
        
        $sqlQueries = [];
        foreach ($this->queries as $key => $item) {
            $sqlQueries[$key] = $item['sql'];
        }
        
        $rawResults = $this->asyncQuery->executeAsyncQueries($sqlQueries);
        
        foreach ($rawResults as $key => $raw) {
            if (isset($raw['error'])) {
                $this->results[$key] = $raw;
                continue;
            }
            
            $queryType = $this->queries[$key]['type'] ?? 'orm';
            
            if ($queryType === 'raw') {
                // 原生查询，直接返回结果
                if (isset($raw['type']) && $raw['type'] === 'exec') {
                    // 执行类查询（INSERT/UPDATE/DELETE）
                    $this->results[$key] = [
                        'type' => 'exec',
                        'affected_rows' => $raw['affected_rows'],
                        'insert_id' => $raw['insert_id'],
                    ];
                } else {
                    // SELECT 查询
                    $this->results[$key] = $raw['data'] ?? [];
                }
            } else {
                // ORM 查询
                $data = $raw['data'] ?? [];
                $method = $this->queries[$key]['method'];
                $modelClass = $this->queries[$key]['model'];
                
                $this->results[$key] = $this->convertResult($data, $method, $modelClass);
            }
        }
        
        $this->asyncQuery->close();
        
        return $this->results;
    }
    
    private function convertResult(array $data, string $method, ?string $modelClass) {
        if (empty($data)) {
            if (in_array($method, ['count', 'sum', 'avg', 'max', 'min'])) {
                return 0;
            }
            return $method === 'find' ? null : [];
        }
        
        // Handle aggregate results (count/sum/avg/max/min)
        if (in_array($method, ['count', 'sum', 'avg', 'max', 'min'])) {
            if (!empty($data[0])) {
                // Aggregate queries return a single row with the aggregate value
                $firstRow = $data[0];
                if (is_array($firstRow)) {
                    // Get the first (and only) value from the row
                    return reset($firstRow);
                }
                return $firstRow;
            }
            return 0;
        }
        
        if ($modelClass && class_exists($modelClass)) {
            if ($method === 'find') {
                $model = new $modelClass($data[0]);
                // 对于异步查询的结果，直接返回模型对象，不需要标记为更新状态
                return $model;
            } else {
                $models = [];
                foreach ($data as $item) {
                    $model = new $modelClass($item);
                    $models[] = $model;
                }
                return $models;
            }
        }
        
        if ($method === 'find') {
            return $data[0];
        }
        
        return $data;
    }
    
    private function extractSql($query, string $method): string {
        if ($query instanceof AsyncQueryWrapper) {
            $builder = $query->getConnection()->getBuilder();
            return $method === 'find' ? $builder->select($query, true) : $builder->select($query, false);
        }
        
        $fetchClass = new \think\db\Fetch($query);
        
        if ($method === 'find') {
            return $fetchClass->find();
        } elseif ($method === 'select') {
            return $fetchClass->select();
        }
        
        throw new \InvalidArgumentException("Unknown method: {$method}");
    }
    
    private function getModelClass($query): ?string {
        if ($query instanceof AsyncQueryWrapper) {
            return $query->model();
        }
        
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
        }
        
        return null;
    }
}

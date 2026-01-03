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
    
    private function __construct(?BaseQuery $query = null) {
        $this->asyncQuery = new AsyncQuery($query);
    }
    
    public static function start(?BaseQuery $query = null): self {
        if (self::$active) {
            throw new \RuntimeException('Async context already started');
        }
        
        self::$active = true;
        self::$instance = new self($query);
        
        return self::$instance;
    }
    
    public static function end(): array {
        if (!self::$active || self::$instance === null) {
            throw new \RuntimeException('Async context not started');
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
    
    public function addQuery(string $key, BaseQuery $query, string $method): void {
        $this->queries[$key] = [
            'query' => $query,
            'method' => $method,
            'sql' => $this->extractSql($query, $method),
            'model' => $this->getModelClass($query),
        ];
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
            
            $data = $raw['data'] ?? [];
            $method = $this->queries[$key]['method'];
            $modelClass = $this->queries[$key]['model'];
            
            $this->results[$key] = $this->convertResult($data, $method, $modelClass);
        }
        
        $this->asyncQuery->close();
        
        return $this->results;
    }
    
    private function convertResult(array $data, string $method, ?string $modelClass) {
        if (empty($data)) {
            return $method === 'find' ? null : [];
        }
        
        if ($modelClass && class_exists($modelClass)) {
            if ($method === 'find') {
                $model = new $modelClass($data[0]);
                $model->isUpdate(true);
                return $model;
            } else {
                $models = [];
                foreach ($data as $item) {
                    $model = new $modelClass($item);
                    $model->isUpdate(true);
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
    
    private function extractSql(BaseQuery $query, string $method): string {
        $fetchClass = new \think\db\Fetch($query);
        
        if ($method === 'find') {
            return $fetchClass->find();
        } elseif ($method === 'select') {
            return $fetchClass->select();
        }
        
        throw new \InvalidArgumentException("Unknown method: {$method}");
    }
    
    private function getModelClass(BaseQuery $query): ?string {
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

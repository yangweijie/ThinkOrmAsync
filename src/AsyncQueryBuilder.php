<?php
namespace Yangweijie\ThinkOrmAsync;

class AsyncQueryBuilder {
    private $originalQuery;
    private string $modelClass;
    
    public function __construct($query, string $modelClass) {
        $this->originalQuery = $query;
        $this->modelClass = $modelClass;
    }
    
    public function find(...$args) {
        if (!AsyncContext::isActive()) {
            return $this->originalQuery->find(...$args);
        }
        
        $key = $this->generateQueryKey();
        AsyncContext::getInstance()->addQuery($key, $this->originalQuery, 'find');
        
        return new AsyncResultPlaceholder($key, 'find');
    }
    
    public function select(array $data = []): \think\Collection {
        if (!AsyncContext::isActive()) {
            return $this->originalQuery->select($data);
        }
        
        $key = $this->generateQueryKey();
        AsyncContext::getInstance()->addQuery($key, $this->originalQuery, 'select');
        
        return new AsyncResultPlaceholder($key, 'select');
    }
    
    public function __call($method, $args) {
        return call_user_func_array([$this->originalQuery, $method], $args);
    }
    
    public function __get($name) {
        return $this->originalQuery->$name;
    }
    
    public function __set($name, $value) {
        $this->originalQuery->$name = $value;
    }
    
    private function generateQueryKey(): string {
        return md5($this->modelClass . '_' . uniqid('', true));
    }
}
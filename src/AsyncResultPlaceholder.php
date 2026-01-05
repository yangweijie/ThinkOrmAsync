<?php
namespace Yangweijie\ThinkOrmAsync;

use think\Collection;

class AsyncResultPlaceholder extends Collection {
    private string $key;
    private string $method;
    private bool $loaded = false;
    private $data = null;  // 可以是模型对象、数组或其他类型
    private ?AsyncContext $context;
    
    public function __construct(string $key, string $method) {
        $this->key = $key;
        $this->method = $method;
        $this->context = AsyncContext::getInstance();
        parent::__construct([]);
    }
    
    private function load(): void {
        if (!$this->loaded) {
            $value = $this->context ? $this->context->getResult($this->key) : null;
            
            if ($value !== null && isset($value['error'])) {
                $value = new Exception\AsyncResultError(
                    $this->key,
                    $value['error'],
                    $value['code'] ?? 0
                );
            }
            
            // 直接存储原始结果，不进行转换
            $this->data = $value;

            $this->loaded = true;
            
            if ($this->data instanceof Exception\AsyncResultError) {
                throw $this->data;
            }
        }
    }
    
    public function __get($name) {
        $this->load();
        
        // 如果是模型对象，直接访问属性
        if (is_object($this->data) && !($this->data instanceof \Traversable)) {
            if (isset($this->data->$name)) {
                return $this->data->$name;
            }
            return null;
        }
        
        // 如果是数组，访问数组元素
        if (is_array($this->data) && isset($this->data[$name])) {
            return $this->data[$name];
        }
        
        // 如果是数组且是 find 方法，访问第一个元素的属性
        if (is_array($this->data) && $this->method === 'find' && !empty($this->data)) {
            $first = $this->data[0];
            if (is_array($first) && isset($first[$name])) {
                return $first[$name];
            }
        }
        
        return null;
    }
    
    public function __call($name, $arguments) {
        $this->load();
        
        // 如果是模型对象，调用模型方法
        if (is_object($this->data) && method_exists($this->data, $name)) {
            return call_user_func_array([$this->data, $name], $arguments);
        }
        
        // 如果是 Collection 对象，调用 Collection 方法
        if ($this->data instanceof \think\Collection && method_exists($this->data, $name)) {
            return call_user_func_array([$this->data, $name], $arguments);
        }
        
        throw new \BadMethodCallException("Method {$name} not found");
    }
    
    public function toArray(): array {
        $this->load();
        
        if ($this->data instanceof \think\Collection) {
            return $this->data->toArray();
        }
        
        if (is_object($this->data) && method_exists($this->data, 'toArray')) {
            return $this->data->toArray();
        }
        
        if (is_array($this->data)) {
            return $this->data;
        }
        
        if ($this->data !== null) {
            return (array) $this->data;
        }
        
        return [];
    }
    
    public function all(): array {
        return $this->toArray();
    }
    
    public function offsetExists($offset): bool {
        $this->load();
        return isset($this->data[$offset]);
    }
    
    public function offsetGet($offset) {
        $this->load();
        return $this->data[$offset] ?? null;
    }
    
    public function offsetSet($offset, $value): void {
        $this->load();
        if ($offset === null) {
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
        }
    }
    
    public function offsetUnset($offset): void {
        $this->load();
        if (is_array($this->data)) {
            unset($this->data[$offset]);
        }
    }
    
    public function count(): int {
        $this->load();
        return count($this->data);
    }
    
    public function getIterator(): \Traversable {
        $this->load();
        return new \ArrayIterator($this->data);
    }
    
    public function isEmpty(): bool {
        $this->load();
        return empty($this->data);
    }
    
    public function first(?callable $callback = null, $default = null) {
        $this->load();
        
        if ($callback) {
            return parent::first($callback, $default);
        }
        
        if (is_array($this->data) && !empty($this->data)) {
            return $this->data[0] ?? $default;
        }
        
        return $default;
    }
    
    /**
     * 获取原始数据（模型对象或数组）
     * @return mixed
     */
    public function getValue() {
        $this->load();
        return $this->data;
    }
    
    /**
     * 获取模型对象（仅适用于 ORM 查询）
     * @return mixed|null
     */
    public function getModel() {
        $this->load();
        return $this->data;
    }
    
    /**
     * 让 dump() 显示模型对象的信息
     */
    public function __debugInfo(): array {
        $this->load();
        
        if (is_object($this->data)) {
            // 如果是模型对象，返回模型对象的调试信息
            return (array) $this->data;
        }
        
        if (is_array($this->data)) {
            // 如果是数组，返回数组
            return $this->data;
        }
        
        return ['data' => $this->data];
    }
}
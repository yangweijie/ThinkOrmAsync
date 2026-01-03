<?php
namespace Yangweijie\ThinkOrmAsync;

use ArrayAccess;
use Iterator;
use Countable;
use JsonSerializable;

class AsyncResultPlaceholder implements ArrayAccess, Iterator, Countable, JsonSerializable {
    private string $key;
    private string $method;
    private bool $loaded = false;
    private $value;
    private int $iteratorPosition = 0;
    private ?array $iteratorData = null;
    
    public function __construct(string $key, string $method) {
        $this->key = $key;
        $this->method = $method;
    }
    
    private function load() {
        if (!$this->loaded) {
            $value = AsyncContext::getInstance()->getResult($this->key);
            
            if ($value !== null && isset($value['error'])) {
                $value = new Exception\AsyncResultError(
                    $this->key,
                    $value['error'],
                    $value['code'] ?? 0
                );
            }
            
            $this->value = $value;
            $this->loaded = true;
        }
        return $this->value;
    }
    
    public function __get($name) {
        $value = $this->load();
        
        if ($value instanceof Exception\AsyncResultError) {
            throw $value;
        }
        
        if (is_object($value) && isset($value->$name)) {
            return $value->$name;
        }
        
        return null;
    }
    
    public function __call($name, $arguments) {
        $value = $this->load();
        
        if ($value instanceof Exception\AsyncResultError) {
            throw $value;
        }
        
        if (is_object($value) && method_exists($value, $name)) {
            return call_user_func_array([$value, $name], $arguments);
        }
        
        throw new \BadMethodCallException("Method {$name} not found");
    }
    
    public function toArray(): array {
        $value = $this->load();
        
        if ($value instanceof Exception\AsyncResultError) {
            throw $value;
        }
        
        if ($value === null) {
            return [];
        }
        
        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }
        
        if (is_array($value)) {
            return $value;
        }
        
        return (array) $value;
    }
    
    public function jsonSerialize() {
        return $this->toArray();
    }
    
    public function __toString(): string {
        return json_encode($this->toArray());
    }
    
    public function offsetExists($offset): bool {
        $value = $this->load();
        
        if ($value instanceof Exception\AsyncResultError) {
            return false;
        }
        
        if (is_array($value)) {
            return isset($value[$offset]);
        }
        
        if (is_object($value) && $value instanceof ArrayAccess) {
            return isset($value[$offset]);
        }
        
        return false;
    }
    
    public function offsetGet($offset) {
        $value = $this->load();
        
        if ($value instanceof Exception\AsyncResultError) {
            throw $value;
        }
        
        if (is_array($value)) {
            return $value[$offset] ?? null;
        }
        
        if (is_object($value) && $value instanceof ArrayAccess) {
            return $value[$offset] ?? null;
        }
        
        return null;
    }
    
    public function offsetSet($offset, $value): void {
        $this->load();
        
        if ($offset === null) {
            $this->value[] = $value;
        } else {
            $this->value[$offset] = $value;
        }
    }
    
    public function offsetUnset($offset): void {
        $this->load();
        
        if (is_array($this->value)) {
            unset($this->value[$offset]);
        }
    }
    
    public function rewind(): void {
        $this->iteratorPosition = 0;
    }
    
    public function valid(): bool {
        return isset($this->getIteratorData()[$this->iteratorPosition]);
    }
    
    public function current() {
        return $this->getIteratorData()[$this->iteratorPosition];
    }
    
    public function key() {
        return $this->iteratorPosition;
    }
    
    public function next(): void {
        $this->iteratorPosition++;
    }
    
    private function getIteratorData() {
        if ($this->iteratorData === null) {
            $value = $this->load();
            
            if ($value instanceof Exception\AsyncResultError) {
                $this->iteratorData = [];
            } elseif (is_array($value)) {
                $this->iteratorData = $value;
            } elseif ($value instanceof \Traversable) {
                $this->iteratorData = iterator_to_array($value);
            } else {
                $this->iteratorData = [];
            }
        }
        
        return $this->iteratorData;
    }
    
    public function count(): int {
        return count($this->getIteratorData());
    }
    
    public function isEmpty(): bool {
        $value = $this->load();
        
        if ($value instanceof Exception\AsyncResultError) {
            return true;
        }
        
        return empty($value) || $this->count() === 0;
    }
    
    public function first() {
        $value = $this->load();
        
        if ($value instanceof Exception\AsyncResultError) {
            throw $value;
        }
        
        if (is_array($value)) {
            return $value[0] ?? null;
        }
        
        return $value;
    }
}

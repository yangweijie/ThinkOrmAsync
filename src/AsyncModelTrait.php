<?php
namespace Yangweijie\ThinkOrmAsync;

trait AsyncModelTrait {
    public static function __callStatic($method, $args) {
        if (in_array($method, ['find', 'select']) && AsyncContext::isActive()) {
            return self::executeAsync($method, $args);
        }
        
        return parent::__callStatic($method, $args);
    }
    
    private static function executeAsync(string $method, array $args) {
        $query = static::db();
        
        if ($method === 'find') {
            if (isset($args[0])) {
                $query->where($query->getPk(), $args[0]);
            }
        } elseif ($method === 'select') {
            if (isset($args[0]) && is_array($args[0])) {
                $query->where($query->getPk(), 'in', $args[0]);
            }
        }
        
        $key = self::generateQueryKey();
        AsyncContext::getInstance()->addQuery($key, $query, $method);
        
        return new AsyncResultPlaceholder($key, $method);
    }
    
    private static function generateQueryKey(): string {
        return md5(get_called_class() . '_' . uniqid('', true));
    }
}

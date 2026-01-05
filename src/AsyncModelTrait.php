<?php
namespace Yangweijie\ThinkOrmAsync;

trait AsyncModelTrait {
    public static function __callStatic($method, $args) {
        if (AsyncContext::isActive()) {
            // 在异步上下文中，直接拦截 find 和 select 方法
            if (in_array($method, ['find', 'select'])) {
                $modelClass = get_called_class();
                $key = md5($modelClass . '_' . $method . '_' . serialize($args));
                
                // 获取模型的表名
                $tableName = self::getModelTableName($modelClass);
                
                // 创建一个模拟的查询对象
                $query = new AsyncQueryWrapper($modelClass, $tableName);
                
                // 如果是 find 方法，保存主键值
                if ($method === 'find' && !empty($args)) {
                    $query->setPrimaryKeyValue($args[0]);
                }
                
                // 直接添加到异步上下文
                AsyncContext::getInstance()->addQuery($key, $query, $method);
                
                // 返回占位符
                return new AsyncResultPlaceholder($key, $method);
            }
            
            // 其他方法，创建查询构建器包装器
            $modelClass = get_called_class();
            $tableName = self::getModelTableName($modelClass);
            $query = new AsyncQueryWrapper($modelClass, $tableName);
            return new AsyncQueryBuilder($query, $modelClass);
        }
        
        return parent::__callStatic($method, $args);
    }
    
    private static function getModelTableName(string $modelClass): ?string {
        try {
            if (class_exists($modelClass)) {
                $reflection = new \ReflectionClass($modelClass);
                
                // 创建一个临时实例来获取属性值
                $instance = $reflection->newInstanceWithoutConstructor();
                
                // 尝试获取 $table 属性（完整表名）
                if ($reflection->hasProperty('table')) {
                    $property = $reflection->getProperty('table');
                    $property->setAccessible(true);
                    $table = $property->getValue($instance);
                    
                    if ($table) {
                        return $table;
                    }
                }
                
                // 尝试获取 $name 属性（不带前缀的表名）
                if ($reflection->hasProperty('name')) {
                    $property = $reflection->getProperty('name');
                    $property->setAccessible(true);
                    $name = $property->getValue($instance);
                    
                    if ($name) {
                        // 获取表前缀
                        $prefix = self::getTablePrefix($instance);
                        return $prefix . $name;
                    }
                }
                
                // 如果都没有，使用类名的小写版本
                $className = basename(str_replace('\\', '/', $modelClass));
                $prefix = self::getTablePrefix($instance);
                return $prefix . strtolower($className);
            }
        } catch (\Exception $e) {
            // 如果出错，使用类名的小写版本
            $className = basename(str_replace('\\', '/', $modelClass));
            return strtolower($className);
        }
        
        return null;
    }
    
    private static function getTablePrefix($modelInstance): string {
        try {
            $reflection = new \ReflectionClass($modelInstance);
            
            // 尝试获取 $prefix 属性
            if ($reflection->hasProperty('prefix')) {
                $property = $reflection->getProperty('prefix');
                $property->setAccessible(true);
                $prefix = $property->getValue($modelInstance);
                
                if ($prefix) {
                    return $prefix;
                }
            }
            
            // 尝试从配置中获取表前缀
            if (function_exists('config')) {
                $prefix = config('database.connections.mysql.prefix', '');
                if ($prefix) {
                    return $prefix;
                }
            }
        } catch (\Exception $e) {
        }
        
        return '';
    }
}

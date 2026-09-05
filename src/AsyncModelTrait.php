<?php
namespace Yangweijie\ThinkOrmAsync;

trait AsyncModelTrait {
    public static function __callStatic($method, $args) {
        if (AsyncContext::isActive()) {
            // 在异步上下文中，拦截 find 和 select 方法 — 直接通过 wrapper 执行
            if (in_array($method, ['find', 'select'])) {
                $modelClass = get_called_class();
                $tableName = self::getModelTableName($modelClass);
                
                $wrapper = new AsyncQueryWrapper($modelClass, $tableName);
                
                // 如果是 find 方法，设置主键条件
                if ($method === 'find' && !empty($args)) {
                    $pk = self::getModelPk($modelClass);
                    $wrapper->where($pk, $args[0]);
                }
                
                // 直接执行查询并返回结果
                return $wrapper->$method();
            }
            
            // 其他方法，创建查询构建器包装器
            $modelClass = get_called_class();
            $tableName = self::getModelTableName($modelClass);
            $query = new AsyncQueryWrapper($modelClass, $tableName);
            return new AsyncQueryBuilder($query, $modelClass);
        }
        
        return parent::__callStatic($method, $args);
    }
    
    private static function getModelPk(string $modelClass): string {
        try {
            if (class_exists($modelClass)) {
                $instance = (new \ReflectionClass($modelClass))->newInstanceWithoutConstructor();
                if (method_exists($instance, 'getPk')) {
                    return $instance->getPk();
                }
            }
        } catch (\Throwable $e) {
        }
        return 'id';
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
                
                // 如果都没有，使用 ThinkPHP 的 snake_case 规则：AppInfo → app_info
                $className = basename(str_replace('\\', '/', $modelClass));
                $prefix = self::getTablePrefix($instance);
                return $prefix . self::toSnakeCase($className);
            }
        } catch (\Exception $e) {
            $className = basename(str_replace('\\', '/', $modelClass));
            return self::toSnakeCase($className);
        }
        
        return null;
    }
    
    private static function getTablePrefix($modelInstance): string {
        try {
            $reflection = new \ReflectionClass($modelInstance);
            
            if ($reflection->hasProperty('prefix')) {
                $property = $reflection->getProperty('prefix');
                $property->setAccessible(true);
                $prefix = $property->getValue($modelInstance);
                
                if ($prefix) {
                    return $prefix;
                }
            }
            
            if (function_exists('config')) {
                // 先读 webman think-orm 配置
                $prefix = config('think-orm.connections.mysql.prefix', '');
                if ($prefix) {
                    return $prefix;
                }
                // 兼容标准 ThinkPHP
                $prefix = config('database.connections.mysql.prefix', '');
                if ($prefix) {
                    return $prefix;
                }
            }
        } catch (\Exception $e) {
        }
        
        return '';
    }
    
    /**
     * ThinkPHP 风格 snake_case：AppInfo → app_info, MchMember → mch_member
     */
    private static function toSnakeCase(string $className): string {
        if (!ctype_lower($className)) {
            $className = preg_replace('/\s+/u', '', $className);
            $className = mb_strtolower(preg_replace('/(.)(?=[A-Z])/u', '$1' . '_', $className), 'UTF-8');
        }
        return $className;
    }
}

<?php
namespace Yangweijie\ThinkOrmAsync;

/**
 * 异步查询的缓存适配层。
 *
 * ThinkPHP 的 cache() 由 Connection 在真正执行 SQL 的那一步处理，
 * 异步路径里 SQL 被交给 mysqli 直接执行，绕过了 Connection，因此缓存完全失效。
 * 这里在「注册查询」和「结果回填」两个点补上同样的语义。
 */
class AsyncQueryCache {
    /**
     * 归一化 cache() 参数，false 表示不缓存。
     *
     * ThinkPHP 允许 cache(3600) 这种把过期时间放在第一个参数的写法。
     */
    public static function normalize($key, $expire = null, $tag = null): ?array {
        if ($key === false) {
            return null;
        }

        if ($key instanceof \DateTimeInterface
            || $key instanceof \DateInterval
            || (is_int($key) && $expire === null)) {
            $expire = $key;
            $key = true;
        }

        return [$key, $expire, $tag];
    }

    /**
     * cache(true) 时用 SQL 指纹做 key，保证同一条 SQL 命中同一份缓存。
     */
    public static function makeKey(string $sql, $key): string {
        if ($key === true || $key === null || $key === '') {
            return 'think_async_query_' . md5($sql);
        }
        return (string) $key;
    }

    /**
     * 缓存未配置或缓存驱动异常时静默降级：查询照常执行，只是不走缓存。
     */
    public static function get(string $key) {
        $driver = self::driver();
        if ($driver === null) {
            return null;
        }

        try {
            return $driver::get($key);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function set(string $key, $value, $expire = null, $tag = null): void {
        $driver = self::driver();
        if ($driver === null) {
            return;
        }

        try {
            if ($tag !== null && $tag !== '' && method_exists($driver, 'tag')) {
                $driver::tag($tag)->set($key, $value, $expire);
                return;
            }
            $driver::set($key, $value, $expire);
        } catch (\Throwable $e) {
            // 缓存失败不应影响查询结果
        }
    }

    private static function driver(): ?string {
        // ThinkPHP / webman 的标准入口
        if (class_exists('\\think\\facade\\Cache')) {
            return '\\think\\facade\\Cache';
        }
        // 部分精简环境只装了 think-cache
        if (class_exists('\\think\\Cache')) {
            return '\\think\\Cache';
        }
        return null;
    }
}

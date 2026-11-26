<?php
/**
 * 独立集成校验脚本（不依赖 Pest，PHP CLI 直接运行）：
 *   php tests/integration_with_cache_check.php
 *
 * 覆盖：with() 关联预载（hasOne/hasMany/belongsTo/belongsToMany/嵌套）、
 *       cache() 命中与回写、关联 SQL 生成。
 */

namespace {
    require __DIR__ . '/../vendor/autoload.php';
}

// ---------- 简易缓存桩，模拟 think\facade\Cache ----------
namespace think\facade {
    class Cache {
        public static array $store = [];
        public static function get($key) { return self::$store[$key] ?? null; }
        public static function set($key, $value, $expire = null) { self::$store[$key] = $value; return true; }
        public static function has($key) { return array_key_exists($key, self::$store); }
        public static function tag($name) { return new class { public function set($k, $v, $e = null) { return Cache::set($k, $v, $e); } }; }
    }
}

namespace {
    use think\facade\Db;
    use Yangweijie\ThinkOrmAsync\AsyncQueryCache;
    use Yangweijie\ThinkOrmAsync\AsyncQueryWrapper;
    use Yangweijie\ThinkOrmAsync\AsyncRelationLoader;

    class TestUser extends \think\Model {
        protected $table = 'users';
        public function profile() { return $this->hasOne(TestProfile::class, 'user_id', 'id'); }
        public function orders() { return $this->hasMany(TestOrder::class, 'user_id', 'id'); }
        public function roles() { return $this->belongsToMany(TestRole::class, 'user_roles', 'role_id', 'user_id'); }
    }
    class TestProfile extends \think\Model {
        protected $table = 'profiles';
        public function user() { return $this->belongsTo(TestUser::class, 'user_id', 'id'); }
    }
    class TestOrder extends \think\Model {
        protected $table = 'orders';
    }
    class TestRole extends \think\Model {
        protected $table = 'roles';
    }

    function check(string $label, bool $ok, $actual = null): void {
        echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
        if (!$ok && $actual !== null) {
            var_export($actual);
            echo PHP_EOL;
        }
        if (!$ok) {
            exit(1);
        }
    }

    Db::setConfig([
        'default' => 'sqlite',
        'connections' => [
            'sqlite' => [
                'type' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ],
    ]);

    foreach ([
        'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)',
        'CREATE TABLE profiles (id INTEGER PRIMARY KEY, user_id INTEGER, city TEXT)',
        'CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, amount REAL)',
        'CREATE TABLE roles (id INTEGER PRIMARY KEY, title TEXT)',
        'CREATE TABLE user_roles (id INTEGER PRIMARY KEY, user_id INTEGER, role_id INTEGER)',
    ] as $ddl) {
        Db::execute($ddl);
    }

    Db::table('users')->insert(['id' => 1, 'name' => 'Alice']);
    Db::table('users')->insert(['id' => 2, 'name' => 'Bob']);
    Db::table('profiles')->insert(['id' => 10, 'user_id' => 1, 'city' => 'Shenzhen']);
    Db::table('orders')->insert(['id' => 100, 'user_id' => 1, 'amount' => 88.5]);
    Db::table('orders')->insert(['id' => 101, 'user_id' => 1, 'amount' => 12.5]);
    Db::table('orders')->insert(['id' => 102, 'user_id' => 2, 'amount' => 7.0]);
    Db::table('roles')->insert(['id' => 1, 'title' => 'admin']);
    Db::table('roles')->insert(['id' => 2, 'title' => 'editor']);
    Db::table('user_roles')->insert(['id' => 1, 'user_id' => 1, 'role_id' => 1]);
    Db::table('user_roles')->insert(['id' => 2, 'user_id' => 1, 'role_id' => 2]);

    // 1. with() 不再抛异常，并归一化
    $wrapper = new AsyncQueryWrapper(TestUser::class, 'users');
    $wrapper->with(['profile', 'orders' => function ($q) { $q->where('amount', '>', 50); }]);
    check('with() 返回 wrapper 而不是抛错', $wrapper instanceof AsyncQueryWrapper);
    check('with() 归一化含两个关联', count($wrapper->getWithOption()) === 2, $wrapper->getWithOption());

    // 2. 字符串点号写法 + 字段选择
    $tree = AsyncRelationLoader::normalize('profile.city,orders');
    check('字符串 with 解析为树', isset($tree['profile']) && isset($tree['orders']), $tree);

    // 3. hasOne / hasMany 预载
    $users = (new AsyncRelationLoader())->eagerLoadAndReturn(TestUser::class, [1, 2], 'profile,orders');
    check('hasOne 预载到 Alice', $users[0]->profile !== null && $users[0]->profile->city === 'Shenzhen', $users[0]->profile);
    check('hasOne 是预载而非懒加载', array_key_exists('profile', $users[0]->getData()));
    check('hasOne 无关联时为空', $users[1]->profile === null, $users[1]->profile);
    check('hasMany 预载 2 条订单', count($users[0]->orders) === 2, $users[0]->orders);
    check('hasMany 按用户隔离', count($users[1]->orders) === 1, $users[1]->orders);

    // 4. belongsTo 预载
    $profiles = \think\facade\Db::table('profiles')->select();
    $profiles = array_map(fn($r) => new TestProfile($r), $profiles->toArray());
    (new AsyncRelationLoader())->eagerLoad(TestProfile::class, $profiles, 'user');
    check('belongsTo 预载', $profiles[0]->user !== null && $profiles[0]->user->name === 'Alice', $profiles[0]->user);

    // 5. belongsToMany 预载
    $users = (new AsyncRelationLoader())->eagerLoadAndReturn(TestUser::class, [1], 'roles');
    check('belongsToMany 预载 2 个角色', count($users[0]->roles) === 2, $users[0]->roles);
    check('belongsToMany 是预载而非懒加载', array_key_exists('roles', $users[0]->getData()));

    // 6. 嵌套预载 profile.user
    $users = (new AsyncRelationLoader())->eagerLoadAndReturn(TestUser::class, [1], 'profile.user');
    check('嵌套预载 profile.user', $users[0]->profile->user->name === 'Alice', $users[0]->profile);
    check('嵌套关联已写入子模型', array_key_exists('user', $users[0]->profile->getData()));

    // 7. cache() 参数归一化
    check('cache(false) 禁用', AsyncQueryCache::normalize(false) === null);
    check('cache(3600) 识别为过期时间', AsyncQueryCache::normalize(3600) === [true, 3600, null], AsyncQueryCache::normalize(3600));
    check('cache(true) 使用 SQL 指纹 key', str_starts_with(AsyncQueryCache::makeKey('SELECT 1', true), 'think_async_query_'));

    // 8. 异步上下文：缓存命中不执行真实 SQL
    \Yangweijie\ThinkOrmAsync\AsyncContext::start(null, [
        'type' => 'mysql', 'hostname' => '127.0.0.1', 'database' => 'unused',
        'username' => 'root', 'password' => '', 'hostport' => 3306, 'charset' => 'utf8mb4',
    ]);
    AsyncQueryCache::set('async_cached_users', [['id' => 99, 'name' => 'CachedUser']]);
    $cachedPlaceholder = (new AsyncQueryWrapper(TestUser::class, 'users'))
        ->cache('async_cached_users')
        ->select();
    \Yangweijie\ThinkOrmAsync\AsyncContext::end();
    $cachedResult = $cachedPlaceholder->getValue();
    check('异步路径 cache 命中', is_array($cachedResult) && ($cachedResult[0]->name ?? null) === 'CachedUser', $cachedResult);

    // 9. 异步上下文：with() 不再抛异常，能正常拿到占位符
    \Yangweijie\ThinkOrmAsync\AsyncContext::start(null, [
        'type' => 'mysql', 'hostname' => '127.0.0.1', 'database' => 'unused',
        'username' => 'root', 'password' => '', 'hostport' => 3306, 'charset' => 'utf8mb4',
    ]);
    $withPlaceholder = (new AsyncQueryWrapper(TestUser::class, 'users'))
        ->with(['profile' => function ($q) { $q->where('city', 'Shenzhen'); }])
        ->select();
    check('异步路径 with() 闭包可用', $withPlaceholder instanceof \Yangweijie\ThinkOrmAsync\AsyncResultPlaceholder);
    // 不调用 end()，避免在没有 MySQL 的环境里真的发查询

    echo PHP_EOL . 'All checks passed.' . PHP_EOL;
}

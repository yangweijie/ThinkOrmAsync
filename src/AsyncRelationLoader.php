<?php
namespace Yangweijie\ThinkOrmAsync;

use Yangweijie\ThinkOrmAsync\Exception\AsyncQueryException;

/**
 * 异步上下文里的关联预载（with）加载器。
 *
 * 设计要点：
 * 1. 关联查询不再同步执行，而是用 ThinkPHP 原生关联对象 + fetchSql(true) 生成 SQL，
 *    再交给 AsyncQuery 批量并行执行。
 * 2. 嵌套关联按层级推进：同一层的所有关联查询放在一个批次里并行，
 *    上一层结果落地后再构建下一层查询。
 * 3. 复杂关联（MorphTo / MorphMany / Through 等）走 ThinkPHP 原生 eagerlyResultSet，
 *    保证语义正确；只有 HasOne/HasMany/BelongsTo/BelongsToMany 走异步快路径。
 */
class AsyncRelationLoader {
    private ?AsyncQuery $asyncQuery;

    public function __construct(?AsyncQuery $asyncQuery = null) {
        $this->asyncQuery = $asyncQuery;
    }

    /**
     * 把 with() 的多种写法归一化成树：
     *   'user'                       => ['user' => []]
     *   'user.profile,user.account'  => ['user' => ['children' => ['profile'=>[], 'account'=>[]]]]
     *   ['user' => ['id','name']]    => ['user' => ['fields' => ['id','name']]]
     *   ['user' => fn($q) => ...]    => ['user' => ['closure' => Closure]]
     *   ['user' => ['profile' => fn($q) => ...]] => 嵌套闭包
     */
    public static function normalize($relations): array {
        if ($relations === null || $relations === false || $relations === true || $relations === '') {
            return [];
        }

        if ($relations instanceof \Closure) {
            // 没有关联名，无法预载，直接忽略而不是抛错
            return [];
        }

        $tree = [];
        if (is_string($relations)) {
            foreach (explode(',', $relations) as $token) {
                $token = trim($token);
                if ($token === '') {
                    continue;
                }
                $alias = null;
                if (strpos($token, '|') !== false) {
                    [$token, $alias] = array_pad(explode('|', $token, 2), 2, null);
                    $token = trim($token);
                    $alias = $alias === null ? null : trim($alias);
                }

                $parts = array_values(array_filter(array_map('trim', explode('.', $token)), 'strlen'));
                if (empty($parts)) {
                    continue;
                }

                $name = array_shift($parts);
                if (!isset($tree[$name]) || !is_array($tree[$name])) {
                    $tree[$name] = [];
                }
                $node =& $tree[$name];
                if ($alias !== null) {
                    $node['alias'] = $alias;
                }
                self::appendPath($node, $parts);
                unset($node);
            }
            return $tree;
        }

        if (is_array($relations)) {
            foreach ($relations as $key => $value) {
                if (is_int($key)) {
                    foreach (self::normalize($value) as $name => $node) {
                        if (!isset($tree[$name]) || !is_array($tree[$name])) {
                            $tree[$name] = [];
                        }
                        self::mergeNode($tree[$name], $node);
                    }
                    continue;
                }

                $parts = array_values(array_filter(array_map('trim', explode('.', (string) $key)), 'strlen'));
                if (empty($parts)) {
                    continue;
                }
                $name = array_shift($parts);
                if (!isset($tree[$name]) || !is_array($tree[$name])) {
                    $tree[$name] = [];
                }
                $node =& $tree[$name];
                self::appendPath($node, $parts);
                self::applyValue($node, $value);
                unset($node);
            }
        }

        return $tree;
    }

    private static function appendPath(array &$node, array $parts): void {
        foreach ($parts as $part) {
            if (!isset($node['children'][$part]) || !is_array($node['children'][$part])) {
                $node['children'][$part] = [];
            }
            $node =& $node['children'][$part];
        }
    }

    private static function applyValue(array &$node, $value): void {
        if ($value instanceof \Closure) {
            $node['closure'] = $value;
            return;
        }

        if (is_string($value)) {
            $node['fields'] = array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            if (is_int($key)) {
                if (is_string($item)) {
                    $fields = array_values(array_filter(array_map('trim', explode(',', $item)), 'strlen'));
                    foreach ($fields as $field) {
                        $node['fields'][] = $field;
                    }
                }
                continue;
            }

            $parts = array_values(array_filter(array_map('trim', explode('.', (string) $key)), 'strlen'));
            if (empty($parts)) {
                continue;
            }
            $child = array_shift($parts);
            if (!isset($node['children'][$child]) || !is_array($node['children'][$child])) {
                $node['children'][$child] = [];
            }
            self::appendPath($node['children'][$child], $parts);
            self::applyValue($node['children'][$child], $item);
        }
    }

    private static function mergeNode(array &$target, array $source): void {
        foreach (['alias', 'closure'] as $key) {
            if (array_key_exists($key, $source)) {
                $target[$key] = $source[$key];
            }
        }
        $target['fields'] = array_values(array_unique(array_merge(
            $target['fields'] ?? [],
            $source['fields'] ?? []
        )));
        foreach ($source['children'] ?? [] as $name => $child) {
            if (!isset($target['children'][$name])) {
                $target['children'][$name] = [];
            }
            self::mergeNode($target['children'][$name], $child);
        }
    }

    /**
     * 按主键取模型并预载关联，方便独立使用与测试。
     */
    public function eagerLoadAndReturn(string $modelClass, array $ids, $relations): array {
        if (empty($ids) || !class_exists($modelClass)) {
            return [];
        }

        $model = new $modelClass();
        $pk = $model->getPk();
        $rows = \think\facade\Db::table($model->getTable())->whereIn($pk, $ids)->select();

        $models = [];
        foreach ($rows as $row) {
            $models[] = new $modelClass($row);
        }

        $this->eagerLoad($modelClass, $models, $relations);
        return $models;
    }

    /**
     * 执行预载。$models 必须是 think\Model 对象数组。
     */
    public function eagerLoad(string $modelClass, array $models, $relations): void {
        $tree = self::normalize($relations);
        if (empty($tree) || empty($models)) {
            return;
        }

        // 队列按层级推进，保证嵌套关联每一层内部都能并行
        $queue = [[$modelClass, $models, $tree]];

        while (!empty($queue)) {
            $specs = [];
            $nextQueue = [];

            foreach ($queue as [$parentClass, $parents, $node]) {
                foreach ($node as $name => $config) {
                    $spec = $this->buildSpec($parentClass, $parents, (string) $name, $config);
                    if ($spec !== null) {
                        $specs[] = $spec;
                    }
                }
            }

            if (empty($specs)) {
                break;
            }

            $rawResults = $this->executeBatch($specs);

            foreach ($specs as $spec) {
                $rows = $rawResults[$spec['id']] ?? [];
                $related = $this->hydrate($spec, $rows);
                $this->attach($spec, $related, $rows);

                if (!empty($spec['children']) && !empty($related)) {
                    $nextQueue[] = [$spec['relatedClass'], $related, $spec['children']];
                }
            }

            $queue = $nextQueue;
        }
    }

    /**
     * 构建一个关联查询规格。返回 null 表示该关联无法异步加载（已走原生降级）。
     */
    private function buildSpec(string $parentClass, array $parents, string $name, array $config): ?array {
        $relation = $this->makeRelation($parentClass, $parents[0] ?? null, $name);
        if ($relation === null) {
            // 关联方法不存在：与 ThinkPHP 行为一致，忽略而不是静默丢数据
            return null;
        }

        $relatedClass = $this->relationRelatedClass($relation);
        $config = $this->resolveAmbiguousChildren($config, $relatedClass);

        // 不支持异步生成的关联类型 → 使用 ThinkPHP 原生预载（同步，但语义完整）
        if (!$this->isAsyncSupported($relation)) {
            $this->nativeEagerLoad($relation, $parents, $name, $config);
            return null;
        }

        $foreignKey = $relation->getForeignKey();
        $localKey = $relation->getLocalKey();
        $kind = $this->relationKind($relation);
        $isBelongsTo = $kind === 'BelongsTo';
        $isManyToMany = $kind === 'BelongsToMany';

        // belongsToMany 的 localKey 是中间表列名（user_id），
        // 父模型上对应的是主键（id），不能直接拿 localKey 取值。
        $parentPk = $isManyToMany && $parents[0] instanceof \think\Model
            ? $parents[0]->getPk()
            : null;
        $parentKeyField = $isManyToMany ? $parentPk : ($isBelongsTo ? $foreignKey : $localKey);

        // 收集父模型上的关联键
        $range = [];
        foreach ($parents as $parent) {
            $value = $this->modelValue($parent, $parentKeyField);
            if ($value !== null && $value !== '') {
                $range[] = $value;
            }
        }
        $range = array_values(array_unique($range));
        if (empty($range)) {
            // 没有外键，直接给所有父模型挂空关联
            $this->attach($this->emptySpec($name, $relation, $relatedClass, $config, $kind), []);
            return null;
        }

        try {
            $query = $this->buildRelationQuery($relation, $kind, $range, $config, $name);
        } catch (\Throwable $e) {
            // 无法生成 SQL（自定义关联、复杂闭包等）→ 原生降级，绝不静默缺数据
            $this->nativeEagerLoad($relation, $parents, $name, $config);
            return null;
        }

        $sql = $query->fetchSql(true)->select();
        if (!is_string($sql) || $sql === '') {
            $this->nativeEagerLoad($relation, $parents, $name, $config);
            return null;
        }

        $groupField = $isManyToMany ? 'pivot__' . $localKey : ($isBelongsTo ? $localKey : $foreignKey);

        return [
            'id' => 'rel_' . md5($sql . '_' . uniqid('', true)),
            'sql' => $sql,
            'name' => $name,
            'parents' => $parents,
            'kind' => $kind,
            'groupField' => $groupField,
            'parentKey' => $parentKeyField ?? $localKey,
            'relatedClass' => $relatedClass,
            'one' => in_array($kind, ['HasOne', 'BelongsTo'], true),
            'children' => $config['children'] ?? [],
            'alias' => $config['alias'] ?? null,
        ];
    }

    private function emptySpec(string $name, $relation, string $relatedClass, array $config, string $kind): array {
        return [
            'name' => $name,
            'parents' => [],
            'kind' => $kind,
            'groupField' => '',
            'parentKey' => $relation->getLocalKey(),
            'relatedClass' => $relatedClass,
            'one' => in_array($kind, ['HasOne', 'BelongsTo'], true),
            'children' => $config['children'] ?? [],
            'alias' => $config['alias'] ?? null,
        ];
    }

    /**
     * 用 ThinkPHP 原生关联查询对象 + fetchSql(true) 生成 SQL。
     * 好处：软删除、全局 scope、闭包条件、字段限制、JOIN 全部由 ORM 自己处理。
     */
    private function buildRelationQuery($relation, string $kind, array $range, array $config, string $relationName) {
        if ($kind === 'BelongsToMany') {
            // belongsToManyQuery 是 protected，用反射调用，避免重复实现中间表 JOIN
            $method = new \ReflectionMethod($relation, 'belongsToManyQuery');
            $method->setAccessible(true);
            $query = $method->invoke(
                $relation,
                $relation->getForeignKey(),
                $relation->getLocalKey(),
                [['pivot.' . $relation->getLocalKey(), 'in', $range]]
            );
        } else {
            $query = clone $relation->getQuery();
            $query->removeWhereField($relation->getForeignKey());
            $isBelongsTo = $kind === 'BelongsTo';
            $query->whereIn($isBelongsTo ? $relation->getLocalKey() : $relation->getForeignKey(), $range);
        }

        if (!empty($config['closure']) && $config['closure'] instanceof \Closure) {
            $this->applyClosure($config['closure'], $query, $relationName);
        }

        if (!empty($config['fields'])) {
            $fields = $config['fields'];
            // 必须保留分组键，否则结果无法回填到父模型
            $groupKey = $kind === 'BelongsToMany'
                ? 'pivot.' . $relation->getLocalKey()
                : ($kind === 'BelongsTo' ? $relation->getLocalKey() : $relation->getForeignKey());
            if (!in_array($groupKey, $fields, true)) {
                $fields[] = $groupKey;
            }
            $query->field($fields);
        }

        return $query;
    }

    private function applyClosure(\Closure $closure, $query, string $relationName): void {
        $params = (new \ReflectionFunction($closure))->getNumberOfParameters();
        if ($params >= 2) {
            $closure($query, $relationName);
        } else {
            $closure($query);
        }
    }

    /**
     * 'user.name,user.profile' 里的 name 可能是字段也可能是子关联，
     * 与 ThinkPHP 一样按「关联方法是否存在」判断。
     */
    private function resolveAmbiguousChildren(array $config, string $relatedClass): array {
        if (empty($config['children']) || !class_exists($relatedClass)) {
            return $config;
        }

        try {
            $probe = (new \ReflectionClass($relatedClass))->newInstanceWithoutConstructor();
        } catch (\Throwable $e) {
            return $config;
        }

        foreach ($config['children'] as $childName => $childConfig) {
            if (!method_exists($probe, (string) $childName)) {
                $config['fields'][] = (string) $childName;
                unset($config['children'][$childName]);
            }
        }

        return $config;
    }

    private function makeRelation(string $modelClass, $fallbackModel, string $name) {
        if (!class_exists($modelClass) || !method_exists($modelClass, $name)) {
            return null;
        }

        try {
            $instance = $fallbackModel instanceof $modelClass ? $fallbackModel : new $modelClass();
            $relation = $instance->$name();
        } catch (\Throwable $e) {
            try {
                $instance = (new \ReflectionClass($modelClass))->newInstanceWithoutConstructor();
                $relation = $instance->$name();
            } catch (\Throwable $ignored) {
                return null;
            }
        }

        if ($relation instanceof \think\Model\Relation) {
            return $relation;
        }
        return null;
    }

    private function relationKind($relation): string {
        foreach (['HasOne', 'HasMany', 'BelongsTo', 'BelongsToMany'] as $kind) {
            $class = '\\think\\model\\relation\\' . $kind;
            if (class_exists($class) && $relation instanceof $class) {
                return $kind;
            }
        }
        return 'Other';
    }

    private function isAsyncSupported($relation): bool {
        return $this->relationKind($relation) !== 'Other';
    }

    private function relationRelatedClass($relation): string {
        $model = $relation->getModel();
        return is_object($model) ? get_class($model) : (string) $model;
    }

    private function modelValue($model, string $key) {
        if ($model instanceof \think\Model) {
            return $model->getData($key) ?? $model->getAttr($key);
        }
        if (is_array($model)) {
            return $model[$key] ?? null;
        }
        return is_object($model) ? ($model->$key ?? null) : null;
    }

    /**
     * 批量执行关联 SQL。异步上下文走 AsyncQuery；降级路径走普通查询。
     */
    private function executeBatch(array $specs): array {
        $sqls = [];
        foreach ($specs as $spec) {
            $sqls[$spec['id']] = $spec['sql'];
        }

        if ($this->asyncQuery !== null) {
            $raw = $this->asyncQuery->executeAsyncQueries($sqls);
            $result = [];
            foreach ($raw as $key => $row) {
                $result[$key] = $row['data'] ?? [];
            }
            return $result;
        }

        $result = [];
        foreach ($sqls as $key => $sql) {
            try {
                $rows = \think\facade\Db::query($sql);
                $result[$key] = is_array($rows) ? $rows : [];
            } catch (\Throwable $e) {
                throw new AsyncQueryException(
                    'Relation eager loading failed: ' . $e->getMessage() . ' [SQL] ' . $sql,
                    ['sql' => $sql],
                    0,
                    $e
                );
            }
        }
        return $result;
    }

    private function hydrate(array $spec, array $rows): array {
        $relatedClass = $spec['relatedClass'];
        $models = [];
        $pivotPrefix = 'pivot__';

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pivotData = [];
            $data = [];
            foreach ($row as $key => $value) {
                if (strpos((string) $key, $pivotPrefix) === 0) {
                    $pivotData[substr((string) $key, strlen($pivotPrefix))] = $value;
                } else {
                    $data[$key] = $value;
                }
            }

            if (class_exists($relatedClass)) {
                $model = new $relatedClass($data);
                if (!empty($pivotData) && method_exists($model, 'setRelation')) {
                    $model->setRelation('pivot', new \think\model\Pivot($pivotData));
                }
                $models[] = $model;
            } else {
                $models[] = $data;
            }
        }

        return $models;
    }

    private function attach(array $spec, array $related, array $rows = []): void {
        $name = $spec['name'];
        $groupField = $spec['groupField'] ?? '';
        $grouped = [];

        // 优先用原始行分组：模型构造函数会过滤掉非表字段（如 pivot__user_id），
        // belongsToMany 的分组键只能从原始结果集里取。
        if (!empty($rows)) {
            foreach (array_values($rows) as $index => $row) {
                $key = is_array($row) ? ($row[$groupField] ?? null) : null;
                $model = $related[$index] ?? null;
                if ($key === null || $model === null) {
                    continue;
                }
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [];
                }
                $grouped[$key][] = $model;
            }
        } else {
            foreach ($related as $model) {
                $key = $this->modelValue($model, $groupField);
                if ($key === null) {
                    continue;
                }
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [];
                }
                $grouped[$key][] = $model;
            }
        }

        foreach ($spec['parents'] as $parent) {
            if (!is_object($parent) || !method_exists($parent, 'setRelation')) {
                continue;
            }
            $parentKey = $this->modelValue($parent, $spec['parentKey']);
            $items = $parentKey !== null && isset($grouped[$parentKey]) ? $grouped[$parentKey] : [];

            if (!empty($spec['one'])) {
                $parent->setRelation($name, $items[0] ?? null);
            } else {
                $parent->setRelation($name, new \think\Collection($items));
            }
        }
    }

    /**
     * 原生降级：复杂关联（Morph* / Through / 自定义关联）交给 ThinkPHP 自己处理。
     */
    private function nativeEagerLoad($relation, array $parents, string $name, array $config): void {
        if (!method_exists($relation, 'eagerlyResultSet')) {
            return;
        }

        $subRelations = [];
        foreach ($config['children'] ?? [] as $childName => $childConfig) {
            $subRelations[] = (string) $childName;
        }

        try {
            $closure = $config['closure'] ?? null;
            $relation->eagerlyResultSet($parents, $name, $subRelations, $closure);
        } catch (\Throwable $e) {
            // 原生降级也失败：明确报错，不要静默缺关联数据
            throw new AsyncQueryException(
                'Failed to eager load relation "' . $name . '": ' . $e->getMessage(),
                ['relation' => $name],
                0,
                $e
            );
        }
    }
}

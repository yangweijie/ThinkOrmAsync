<?php
require __DIR__ . '/../vendor/autoload.php';

use Yangweijie\ThinkOrmAsync\AsyncContext;
use Yangweijie\ThinkOrmAsync\AsyncModelTrait;

class User extends \think\Model {
    use AsyncModelTrait;
}

class InvalidModel extends \think\Model {
    use AsyncModelTrait;
}

AsyncContext::start();

    $user = User::where('id', 1)->find();
    $invalid = InvalidModel::where('id', 999)->find();

try {
    $results = AsyncContext::end();
    
    if (isset($results['user'])) {
        echo "User found: " . $results['user']->name . "\n";
    }
    
    foreach ($results as $key => $result) {
        if (isset($result['error'])) {
            echo "Query [$key] failed: " . $result['error'] . "\n";
        }
    }
} catch (\Yangweijie\ThinkOrmAsync\Exception\AsyncQueryException $e) {
    echo "Batch query failed: " . $e->getMessage() . "\n";
    print_r($e->getErrors());
}

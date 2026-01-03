<?php

use Yangweijie\ThinkOrmAsync\AsyncQuery;

test('creates async query from mock config', function () {
    $mockQuery = Mockery::mock(\think\db\BaseQuery::class);
    $mockQuery->shouldReceive('getConfig')
        ->andReturn([
            'hostname' => 'localhost',
            'username' => 'root',
            'password' => '',
            'database' => 'test',
            'hostport' => 3306,
            'charset' => 'utf8mb4',
        ]);
    
    $asyncQuery = new AsyncQuery($mockQuery);
    
    expect($asyncQuery)->toBeInstanceOf(AsyncQuery::class);
})->skip('requires mysqli extension');

test('sets timeout', function () {
    $mockQuery = Mockery::mock(\think\db\BaseQuery::class);
    $mockQuery->shouldReceive('getConfig')
        ->andReturn(['hostname' => 'localhost', 'database' => 'test']);
    
    $asyncQuery = new AsyncQuery($mockQuery);
    $result = $asyncQuery->setTimeout(15);
    
    expect($result)->toBe($asyncQuery);
})->skip('requires mysqli extension');

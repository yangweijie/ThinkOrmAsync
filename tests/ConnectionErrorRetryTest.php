<?php

use Yangweijie\ThinkOrmAsync\Retry\ConnectionErrorRetryStrategy;

beforeEach(function () {
    $this->strategy = new ConnectionErrorRetryStrategy();
});

test('retries on connection lost error (2006)', function () {
    $exception = new \mysqli_sql_exception('MySQL server has gone away', 2006, 'HY000');
    
    expect($this->strategy->shouldRetry(1, $exception))->toBeTrue();
});

test('retries on connection refused (2002)', function () {
    $exception = new \mysqli_sql_exception('Connection refused', 2002, 'HY000');
    
    expect($this->strategy->shouldRetry(1, $exception))->toBeTrue();
});

test('retries on lost connection during query (2013)', function () {
    $exception = new \mysqli_sql_exception('Lost connection', 2013, 'HY000');
    
    expect($this->strategy->shouldRetry(1, $exception))->toBeTrue();
});

test('does not retry on syntax error (1064)', function () {
    $exception = new \mysqli_sql_exception('Syntax error', 1064, '42000');
    
    expect($this->strategy->shouldRetry(1, $exception))->toBeFalse();
});

test('does not retry on table not found (1146)', function () {
    $exception = new \mysqli_sql_exception("Table doesn't exist", 1146, '42S02');
    
    expect($this->strategy->shouldRetry(1, $exception))->toBeFalse();
});

test('does not retry on column not found (1054)', function () {
    $exception = new \mysqli_sql_exception("Unknown column", 1054, '42S22');
    
    expect($this->strategy->shouldRetry(1, $exception))->toBeFalse();
});

test('does not retry after max attempts', function () {
    $exception = new \mysqli_sql_exception('MySQL server has gone away', 2006, 'HY000');
    
    expect($this->strategy->shouldRetry(1, $exception))->toBeTrue();
    
    expect($this->strategy->shouldRetry(2, $exception))->toBeFalse();
});

test('returns 1000ms retry delay', function () {
    expect($this->strategy->getBackoffDelay(1))->toBe(1000);
    
    expect($this->strategy->getBackoffDelay(2))->toBe(1000);
});

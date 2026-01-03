<?php
namespace Yangweijie\ThinkOrmAsync\Retry;

interface RetryStrategy {
    public function shouldRetry(int $attempt, \Throwable $exception): bool;
    
    public function getBackoffDelay(int $attempt): int;
}

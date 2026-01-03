<?php
namespace Yangweijie\ThinkOrmAsync\Retry;

class ConnectionErrorRetryStrategy implements RetryStrategy {
    private const RETRYABLE_ERROR_CODES = [
        2002,
        2003,
        2006,
        2013,
    ];
    
    private int $maxAttempts = 2;
    private int $retryDelay = 1000;
    
    public function shouldRetry(int $attempt, \Throwable $exception): bool {
        if ($attempt > $this->maxAttempts) {
            return false;
        }
        
        if (!$exception instanceof \mysqli_sql_exception) {
            return false;
        }
        
        return in_array($exception->getCode(), self::RETRYABLE_ERROR_CODES);
    }
    
    public function getBackoffDelay(int $attempt): int {
        return $this->retryDelay;
    }
    
    public function setMaxAttempts(int $maxAttempts): self {
        $this->maxAttempts = $maxAttempts;
        return $this;
    }
    
    public function setRetryDelay(int $delay): self {
        $this->retryDelay = $delay;
        return $this;
    }
}

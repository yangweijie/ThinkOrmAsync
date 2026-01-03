<?php
namespace Yangweijie\ThinkOrmAsync\Exception;

use RuntimeException;

class RetryExhaustedException extends RuntimeException {
    private int $attempts;
    private \Throwable $lastException;
    
    public function __construct(int $attempts, \Throwable $lastException) {
        $message = sprintf('Retry exhausted after %d attempts: %s', $attempts, $lastException->getMessage());
        parent::__construct($message, 0, $lastException);
        $this->attempts = $attempts;
        $this->lastException = $lastException;
    }
    
    public function getAttempts(): int {
        return $this->attempts;
    }
    
    public function getLastException(): \Throwable {
        return $this->lastException;
    }
}

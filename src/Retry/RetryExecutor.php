<?php
namespace Yangweijie\ThinkOrmAsync\Retry;

use Yangweijie\ThinkOrmAsync\Exception\RetryExhaustedException;

class RetryExecutor {
    private RetryStrategy $strategy;
    /** @var callable|null callable 不能做属性类型（PHP 7.4+ 禁止），只能靠注解 */
    private $beforeRetry = null;

    /** @var callable|null */
    private $onFailure = null;
    
    public function __construct(RetryStrategy $strategy) {
        $this->strategy = $strategy;
    }
    
    public function execute(callable $operation) {
        $attempt = 1;
        $lastException = null;
        
        while (true) {
            try {
                return $operation();
            } catch (\Throwable $exception) {
                $lastException = $exception;
                
                if (!$this->strategy->shouldRetry($attempt, $exception)) {
                    throw $exception;
                }
                
                $delay = $this->strategy->getBackoffDelay($attempt);
                
                if ($this->beforeRetry !== null) {
                    call_user_func($this->beforeRetry, $attempt, $exception, $delay);
                }
                
                usleep($delay * 1000);
                $attempt++;
            }
        }
    }
    
    public function setBeforeRetry(?callable $callback): self {
        $this->beforeRetry = $callback;
        return $this;
    }
}

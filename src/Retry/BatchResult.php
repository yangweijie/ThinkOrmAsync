<?php
namespace Yangweijie\ThinkOrmAsync\Retry;

class BatchResult {
    private array $successful = [];
    private array $failed = [];
    
    public function addSuccess(string $key, $result): void {
        $this->successful[$key] = $result;
    }
    
    public function addFailure(string $key, \Throwable $exception): void {
        $this->failed[$key] = $exception;
    }
    
    public function getSuccessful(): array {
        return $this->successful;
    }
    
    public function getFailed(): array {
        return $this->failed;
    }
    
    public function hasFailures(): bool {
        return !empty($this->failed);
    }
    
    public function getTotalCount(): int {
        return count($this->successful) + count($this->failed);
    }
    
    public function getSuccessRate(): float {
        $total = $this->getTotalCount();
        return $total > 0 ? count($this->successful) / $total : 0;
    }
}

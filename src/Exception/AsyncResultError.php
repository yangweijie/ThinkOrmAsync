<?php
namespace Yangweijie\ThinkOrmAsync\Exception;

use RuntimeException;

class AsyncResultError extends RuntimeException {
    private string $queryKey;
    private string $originalMessage;
    private int $errorCode;
    
    public function __construct(string $queryKey, string $message, int $code = 0) {
        $this->queryKey = $queryKey;
        $this->originalMessage = $message;
        $this->errorCode = $code;
        parent::__construct("Query [$queryKey] failed: $message", $code);
    }
    
    public function getQueryKey(): string {
        return $this->queryKey;
    }
    
    public function getOriginalMessage(): string {
        return $this->originalMessage;
    }
    
    public function getErrorCode(): int {
        return $this->errorCode;
    }
}

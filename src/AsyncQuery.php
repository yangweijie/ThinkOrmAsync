<?php
namespace Yangweijie\ThinkOrmAsync;

use think\db\BaseQuery;
use Yangweijie\ThinkOrmAsync\Retry\ConnectionErrorRetryStrategy;
use Yangweijie\ThinkOrmAsync\Exception\AsyncQueryException;

class AsyncQuery {
    private array $connections = [];
    private array $config = [];
    private ?ConnectionErrorRetryStrategy $retryStrategy = null;
    private int $timeout = 10;
    
    public function __construct(?BaseQuery $query = null) {
        if ($query) {
            $this->config = $query->getConfig();
            $this->retryStrategy = new ConnectionErrorRetryStrategy();
            $this->initConnections();
        }
    }
    
    private function initConnections(): void {
        if (empty($this->config)) {
            return;
        }
        
        $this->connections['default'] = $this->createConnection($this->config);
    }
    
    private function createConnection(array $config): \mysqli {
        $conn = new \mysqli(
            $config['hostname'] ?? 'localhost',
            $config['username'] ?? 'root',
            $config['password'] ?? '',
            $config['database'] ?? '',
            $config['hostport'] ?? 3306
        );
        
        if ($conn->connect_error) {
            throw new AsyncQueryException('MySQLi connection failed: ' . $conn->connect_error);
        }
        
        $conn->set_charset($config['charset'] ?? 'utf8mb4');
        
        return $conn;
    }
    
    public function getConnection(): ?\mysqli {
        if (empty($this->connections)) {
            return null;
        }
        
        return $this->connections['default'];
    }
    
    public function executeAsyncQueries(array $queries): array {
        $results = [];
        $pending = [];
        $connMap = [];
        
        foreach ($queries as $key => $sql) {
            $conn = $this->getConnection();
            
            if (!$conn) {
                $results[$key] = ['error' => 'Failed to get connection'];
                continue;
            }
            
            try {
                $conn->query($sql, MYSQLI_ASYNC);
                $connMap[$key] = $conn;
                $pending[$key] = $conn;
            } catch (\mysqli_sql_exception $e) {
                if ($this->retryStrategy && $this->retryStrategy->shouldRetry(1, $e)) {
                    $conn = $this->reconnect($conn);
                    $conn->query($sql, MYSQLI_ASYNC);
                    $connMap[$key] = $conn;
                    $pending[$key] = $conn;
                } else {
                    $results[$key] = [
                        'error' => $e->getMessage(),
                        'code' => $e->getCode(),
                    ];
                }
            }
        }
        
        $results = $this->pollAndCollect($pending, $connMap, $results);
        
        return $results;
    }
    
    private function reconnect(\mysqli $oldConn): \mysqli {
        $oldConn->close();
        
        $newConn = $this->createConnection($this->config);
        $this->connections['default'] = $newConn;
        
        return $newConn;
    }
    
    private function pollAndCollect(array $pending, array $connMap, array $results): array {
        $startTime = time();
        
        while (count($pending) > 0 && (time() - $startTime) < $this->timeout) {
            $read = $pending;
            $error = $reject = [];
            
            $ready = mysqli_poll($read, $error, $reject, 0, 100000);
            
            if ($ready > 0) {
                foreach ($read as $conn) {
                    $key = array_search($conn, $connMap, true);
                    
                    if ($key !== false) {
                        $result = $conn->reap_async_query();
                        
                        if ($result) {
                            if (is_object($result)) {
                                $data = [];
                                while ($row = $result->fetch_assoc()) {
                                    $data[] = $row;
                                }
                                $result->free();
                                $results[$key] = ['data' => $data];
                            } else {
                                $results[$key] = [
                                    'type' => 'exec',
                                    'affected_rows' => $conn->affected_rows,
                                    'insert_id' => $conn->insert_id,
                                ];
                            }
                            unset($pending[$key]);
                        }
                    }
                }
            }
            
            foreach ($error as $conn) {
                $key = array_search($conn, $connMap, true);
                if ($key !== false) {
                    $results[$key] = ['error' => $conn->error];
                    unset($pending[$key]);
                }
            }
        }
        
        foreach ($pending as $key => $conn) {
            $results[$key] = ['error' => 'Query timeout'];
        }
        
        return $results;
    }
    
    public function setTimeout(int $timeout): self {
        $this->timeout = $timeout;
        return $this;
    }
    
    public function close(): void {
        foreach ($this->connections as $conn) {
            $conn->close();
        }
        $this->connections = [];
    }
}

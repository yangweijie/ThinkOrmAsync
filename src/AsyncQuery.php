<?php
namespace Yangweijie\ThinkOrmAsync;

use think\db\BaseQuery;
use Yangweijie\ThinkOrmAsync\Retry\ConnectionErrorRetryStrategy;
use Yangweijie\ThinkOrmAsync\Exception\AsyncQueryException;

class AsyncQuery {
    /**
     * 跨请求复用的空闲连接池：poolKey => \mysqli[]
     *
     * 每条异步 SQL 都需要独立连接（否则 Commands out of sync），但每次 new mysqli 的握手成本
     * 等于 3~4 个网络往返：同机房 <5ms 无所谓，跨地域/公网可能 200ms 一条，
     * 一批 6 条查询光建连就要 1s+，并行收益被完全吃掉。这里按连接配置缓存空闲连接，
     * workerman 等常驻进程的 worker 可以直接复用，建连成本只在首批请求发生。
     */
    private static array $connectionPool = [];

    /** 单个配置最多缓存的空闲连接数（等于一批查询的最大并发数） */
    private static int $maxIdleConnections = 16;

    private string $poolKey = '';

    private array $connections = [];
    private array $config = [];
    private ?ConnectionErrorRetryStrategy $retryStrategy = null;
    private int $timeout = 10;
    
    public function __construct(?BaseQuery $query = null, ?array $config = null) {
        if ($config) {
            $this->config = $config;
            $this->retryStrategy = new ConnectionErrorRetryStrategy();
            $this->initConnections();
        } elseif ($query) {
            $this->config = $query->getConfig();
            $this->retryStrategy = new ConnectionErrorRetryStrategy();
            $this->initConnections();
        }
    }
    
    private function initConnections(): void {
        if (empty($this->config)) {
            return;
        }
        
        // 不立即创建连接，在执行查询时才创建
        // $this->connections['default'] = $this->createConnection($this->config);
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

    private function getPoolKey(): string {
        if ($this->poolKey === '') {
            $this->poolKey = md5(implode('|', [
                $this->config['hostname'] ?? '',
                $this->config['hostport'] ?? '',
                $this->config['username'] ?? '',
                $this->config['database'] ?? '',
            ]));
        }

        return $this->poolKey;
    }

    /**
     * 从池中取一个连接，没有则新建。
     *
     * 这里刻意不用 mysqli::ping() 探活：PHP 8.4 起该方法已废弃且会抛异常，
     * 会把池中连接全部误判为失效（本地实测 pool 永远为 0、零复用）。
     * 空闲连接被服务端断开时，query() 会抛连接类异常，由重试分支用新连接重跑。
     */
    private function borrowConnection(): \mysqli {
        $key = $this->getPoolKey();

        while (!empty(self::$connectionPool[$key])) {
            $conn = array_pop(self::$connectionPool[$key]);

            if ($conn instanceof \mysqli) {
                return $conn;
            }
        }

        return $this->createConnection($this->config);
    }

    /**
     * 归还连接；池满则直接关闭。
     */
    private function releaseConnection(\mysqli $conn): void {
        $key = $this->getPoolKey();

        if (count(self::$connectionPool[$key] ?? []) >= self::$maxIdleConnections) {
            @$conn->close();
            return;
        }

        // 清理残留结果集，避免下一条异步查询报 "Commands out of sync"
        try {
            while ($conn->more_results() && $conn->next_result()) {
                // drain
            }
        } catch (\Throwable $e) {
        }

        self::$connectionPool[$key][] = $conn;
    }

    /**
     * 关闭所有空闲连接（进程退出前可调用）
     */
    public static function flushPool(): void {
        foreach (self::$connectionPool as $connections) {
            foreach ($connections as $conn) {
                if ($conn instanceof \mysqli) {
                    @$conn->close();
                }
            }
        }

        self::$connectionPool = [];
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
        $connectionsToClose = [];
        
        foreach ($queries as $key => $sql) {
            // 每条查询使用独立连接（异步并发要求），连接从池中复用
            $conn = null;
            try {
                $conn = $this->borrowConnection();
                $connectionsToClose[$key] = $conn;
                $conn->query($sql, MYSQLI_ASYNC);
                $connMap[$key] = $conn;
                $pending[$key] = $conn;
            } catch (\mysqli_sql_exception $e) {
                if ($conn instanceof \mysqli) {
                    @$conn->close();
                }

                if ($this->retryStrategy && $this->retryStrategy->shouldRetry(1, $e)) {
                    $conn = null;
                    try {
                        $conn = $this->createConnection($this->config);
                        $connectionsToClose[$key] = $conn;
                        $conn->query($sql, MYSQLI_ASYNC);
                        $connMap[$key] = $conn;
                        $pending[$key] = $conn;
                    } catch (\Exception $retryEx) {
                        if ($conn instanceof \mysqli) {
                            @$conn->close();
                        }
                        $results[$key] = [
                            'error' => $retryEx->getMessage(),
                            'code' => $retryEx->getCode(),
                        ];
                    }
                } else {
                    $results[$key] = [
                        'error' => $e->getMessage(),
                        'code' => $e->getCode(),
                    ];
                }
            }
        }
        
        $results = $this->pollAndCollect($pending, $connMap, $results);
        
        // 成功的连接归还连接池；出错/超时的连接直接关闭，避免污染池
        foreach ($connectionsToClose as $key => $conn) {
            if (isset($results[$key]['error'])) {
                @$conn->close();
                continue;
            }

            $this->releaseConnection($conn);
        }
        
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

                        // false 表示查询失败；旧实现不处理，key 会一直留在 pending 里空转
                        // 直到 timeout（默认 10s）才返回
                        if ($result === false) {
                            $results[$key] = ['error' => $conn->error ?: 'Query failed'];
                            unset($pending[$key]);
                            continue;
                        }

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

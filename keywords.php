<?php
// keywords.php - 返回关键词列表JSON（优先Redis原子弹出1个；否则MySQL取1000条 is_read=0 并标记为1，推入Redis后弹出1个）

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

date_default_timezone_set('Asia/Shanghai');

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['code' => 1, 'msg' => '缺少配置文件config.php', 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}
$config = require $configPath;

$keywords = [];

// 1) 优先从 Redis 列表 keyword_list 原子弹出 1 条
try {
    $redisConf = $config['redis'] ?? null;
    if ($redisConf) {
        if (class_exists('Redis')) {
            $redis = new Redis();
            $redis->connect($redisConf['host'], (int)$redisConf['port'], (float)$redisConf['timeout']);
            if (!empty($redisConf['password'])) { $redis->auth($redisConf['password']); }
            if (isset($redisConf['db'])) { $redis->select((int)$redisConf['db']); }
            
            // 原子弹出1个
            $val = $redis->lPop('keyword_list');
            if (is_string($val)) {
                $val = trim($val);
                if ($val !== '') {
                    $keywords = [$val];
                }
            }
        } elseif (class_exists('Predis\Client')) {
            $client = new Predis\Client([
                'scheme' => 'tcp',
                'host' => $redisConf['host'],
                'port' => (int)$redisConf['port'],
                'database' => (int)($redisConf['db'] ?? 0),
                'timeout' => (float)($redisConf['timeout'] ?? 2.0),
            ]);
            if (!empty($redisConf['password'])) { $client->auth($redisConf['password']); }
            
            // 原子弹出1个
            $val = $client->lpop('keyword_list');
            if (is_string($val)) {
                $val = trim($val);
                if ($val !== '') {
                    $keywords = [$val];
                }
            }
        }
    }
} catch (Throwable $e) {
    // 忽略Redis错误，回落到MySQL
}

// 2) 若 Redis 未取到值，则回落 MySQL: 取 is_read=0 的 1000 条，推入Redis，然后弹出1个
$pdo = null;
if (count($keywords) === 0) {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['db_host'],
        (int)$config['db_port'],
        $config['db_name'],
        $config['db_charset']
    );
    try {
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$config['db_charset']}"
        ]);
        
        // 查询 is_read=0 的 1000 条，同时获取 id 用于标记
        $sql = 'SELECT `id`, `keyword` FROM `keyword` WHERE `keyword` <> "" AND `is_read` = 0 ORDER BY `id` DESC LIMIT 1000';
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();
        
        if (count($rows) > 0) {
            $kw = [];
            $idsToMark = [];
            foreach ($rows as $r) {
                $val = trim((string)$r['keyword']);
                if ($val === '') { continue; }
                $kw[] = $val;
                $idsToMark[] = (int)$r['id'];
            }
            
            if (count($kw) > 0) {
                // 立即将取出的这 1000 条标记为 is_read=1
                try {
                    $chunkSize = 200;
                    foreach (array_chunk($idsToMark, $chunkSize) as $chunk) {
                        $placeholders = rtrim(str_repeat('?,', count($chunk)), ',');
                        $sqlUpdate = "UPDATE `keyword` SET `is_read` = 1, `update_time` = CURRENT_TIMESTAMP WHERE `id` IN ($placeholders)";
                        $stmtUpdate = $pdo->prepare($sqlUpdate);
                        $stmtUpdate->execute($chunk);
                    }
                } catch (Throwable $e) {
                    error_log("Failed to mark keywords as read: " . $e->getMessage());
                }
                
                // 将关键词推入Redis（原子操作）
                try {
                    if (isset($redis) || isset($client)) {
                        $redisClient = $redis ?? $client;
                        if ($redisClient instanceof Redis) {
                            // 批量推入Redis
                            foreach (array_chunk($kw, 500) as $chunk) {
                                $args = array_merge(['keyword_list'], $chunk);
                                call_user_func_array([$redisClient, 'rPush'], $args);
                            }
                        } else {
                            // Predis批量推入
                            foreach (array_chunk($kw, 500) as $chunk) {
                                foreach ($chunk as $val) {
                                    $redisClient->rpush('keyword_list', [$val]);
                                }
                            }
                        }
                        
                        // 推入成功后，立即弹出1个返回
                        if ($redisClient instanceof Redis) {
                            $val = $redisClient->lPop('keyword_list');
                        } else {
                            $val = $redisClient->lpop('keyword_list');
                        }
                        
                        if (is_string($val)) {
                            $val = trim($val);
                            if ($val !== '') {
                                $keywords = [$val];
                            }
                        }
                    }
                } catch (Throwable $e) {
                    error_log("Failed to push to Redis: " . $e->getMessage());
                    // Redis失败时，直接返回第一个关键词
                    $keywords = [reset($kw)];
                }
            }
        }
        
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 1, 'msg' => '数据库错误: ' . $e->getMessage(), 'data' => null], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

echo json_encode(['code' => 0, 'msg' => 'ok', 'data' => ['keywords' => $keywords]], JSON_UNESCAPED_UNICODE); 
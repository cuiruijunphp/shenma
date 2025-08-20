<?php
// keywords.php - 返回关键词列表JSON（优先Redis，再回落MySQL）

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

$keywords = null;
$maxCount = 1001; // 0..1000 共1001条

// 1) 尝试Redis读取 list: keyword_list，并在返回后弹出这些元素
try {
    $redisConf = $config['redis'] ?? null;
    if ($redisConf) {
        if (class_exists('Redis')) {
            // ext-redis
            $redis = new Redis();
            $redis->connect($redisConf['host'], (int)$redisConf['port'], (float)$redisConf['timeout']);
            if (!empty($redisConf['password'])) { $redis->auth($redisConf['password']); }
            if (isset($redisConf['db'])) { $redis->select((int)$redisConf['db']); }

            $list = $redis->lRange('keyword_list', 0, $maxCount - 1);
            if ($list && is_array($list) && count($list) > 0) {
                $clean = array_values(array_filter(array_map('trim', $list), static function($v){ return $v !== ''; }));
                $keywords = $clean;
                // 弹出已返回的元素：通过LTRIM保留剩余部分
                $popCount = count($list);
                $redis->lTrim('keyword_list', $popCount, -1);
            }
        } else if (class_exists('Predis\Client')) {
            $client = new Predis\Client([
                'scheme' => 'tcp',
                'host' => $redisConf['host'],
                'port' => (int)$redisConf['port'],
                'database' => (int)($redisConf['db'] ?? 0),
                'timeout' => (float)($redisConf['timeout'] ?? 2.0),
            ]);
            if (!empty($redisConf['password'])) { $client->auth($redisConf['password']); }

            $list = $client->lrange('keyword_list', 0, $maxCount - 1);
            if ($list && is_array($list) && count($list) > 0) {
                $clean = array_values(array_filter(array_map('trim', $list), static function($v){ return $v !== ''; }));
                $keywords = $clean;
                // 弹出已返回的元素
                $popCount = count($list);
                $client->ltrim('keyword_list', $popCount, -1);
            }
        }
    }
} catch (Throwable $e) {
    // 忽略Redis错误，回落到MySQL
}

// 2) 回落MySQL: 读取 is_read=0 的关键词
if ($keywords === null || count($keywords) === 0) {
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
        $sql = 'SELECT `keyword` FROM `keyword` WHERE `keyword` <> "" AND `is_read` = 0 ORDER BY `id` DESC LIMIT 1000';
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();
        $kw = [];
        $seen = [];
        foreach ($rows as $r) {
            $val = trim((string)$r['keyword']);
            if ($val === '' || isset($seen[$val])) { continue; }
            $seen[$val] = 1;
            $kw[] = $val;
        }
        $keywords = $kw;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 1, 'msg' => '数据库错误: ' . $e->getMessage(), 'data' => null], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 3) 标记数据库中本次下发的关键词为 is_read=1（分片批量更新）
if (is_array($keywords) && count($keywords) > 0) {
    try {
        if (!isset($pdo)) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['db_host'],
                (int)$config['db_port'],
                $config['db_name'],
                $config['db_charset']
            );
            $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$config['db_charset']}"
            ]);
        }
        // 去重
        $distinct = array_values(array_unique(array_filter(array_map('trim', $keywords), static function($v){ return $v !== ''; })));
        $chunkSize = 200;
        foreach (array_chunk($distinct, $chunkSize) as $chunk) {
            $placeholders = rtrim(str_repeat('?,', count($chunk)), ',');
            $sql = "UPDATE `keyword` SET `is_read` = 1, `update_time` = CURRENT_TIMESTAMP WHERE `is_read` = 0 AND `keyword` IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($chunk);
        }
    } catch (Throwable $e) {
        // 标记失败不影响下发
    }
}

echo json_encode(['code' => 0, 'msg' => 'ok', 'data' => ['keywords' => $keywords]], JSON_UNESCAPED_UNICODE); 
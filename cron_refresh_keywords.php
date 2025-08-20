<?php
// cron_refresh_keywords.php
// 计划任务：每天 00:30 执行，将 MySQL 中 is_read=0 的关键词分批（每批1000，共10批）写入 Redis 列表 keyword_list，并标记 is_read=1

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

date_default_timezone_set('Asia/Shanghai');

$root = __DIR__;
$autoload = $root . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "[ERROR] vendor/autoload.php 不存在，请先 composer install\n");
    exit(1);
}
require $autoload;

$configPath = $root . '/config.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "[ERROR] 缺少配置文件 config.php\n");
    exit(1);
}
$config = require $configPath;

function logInfo(string $msg): void {
    $ts = date('Y-m-d H:i:s');
    echo "[$ts] $msg\n";
}

// 连接 Redis（优先 ext-redis，其次 Predis）
$redisClient = null;
try {
    $rc = $config['redis'] ?? [];
    if (class_exists('Redis')) {
        $r = new Redis();
        $r->connect($rc['host'] ?? '127.0.0.1', (int)($rc['port'] ?? 6379), (float)($rc['timeout'] ?? 2.0));
        if (!empty($rc['password'])) { $r->auth($rc['password']); }
        if (isset($rc['db'])) { $r->select((int)$rc['db']); }
        $redisClient = $r;
        logInfo('Redis: 使用 ext-redis');
    } elseif (class_exists('Predis\Client')) {
        $redisClient = new Predis\Client([
            'scheme' => 'tcp',
            'host' => $rc['host'] ?? '127.0.0.1',
            'port' => (int)($rc['port'] ?? 6379),
            'database' => (int)($rc['db'] ?? 0),
            'timeout' => (float)($rc['timeout'] ?? 2.0),
        ]);
        if (!empty($rc['password'])) { $redisClient->auth($rc['password']); }
        logInfo('Redis: 使用 Predis');
    } else {
        throw new RuntimeException('未找到 Redis 扩展或 Predis 依赖');
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] Redis 连接失败: {$e->getMessage()}\n");
    exit(1);
}

// 连接 MySQL
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['db_host'], (int)$config['db_port'], $config['db_name'], $config['db_charset']
);
try {
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$config['db_charset']}"
    ]);
    logInfo('MySQL: 连接成功');
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] MySQL 连接失败: {$e->getMessage()}\n");
    exit(1);
}

$limitPerBatch = 1000;
$maxBatches = 10; // 共 10 批
$totalPushed = 0;

// 清空旧列表（避免混入历史数据）
try {
    if ($redisClient instanceof Redis) {
        $redisClient->del('keyword_list');
    } else {
        $redisClient->del(['keyword_list']);
    }
    logInfo('Redis: 已清空 keyword_list');
} catch (Throwable $e) {
    fwrite(STDERR, "[WARN] 清空 keyword_list 失败: {$e->getMessage()}\n");
}

for ($batch = 0; $batch < $maxBatches; $batch++) {
    $offset = $batch * $limitPerBatch;
    // 同时查询 id 与 keyword，便于回写 is_read
    $sql = 'SELECT `id`, `keyword` FROM `keyword` WHERE `keyword` <> "" AND `is_read` = 0 ORDER BY `id` DESC LIMIT :limit OFFSET :offset';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limitPerBatch, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        fwrite(STDERR, "[ERROR] 第 {$batch} 批查询失败: {$e->getMessage()}\n");
        break;
    }

    if (!$rows || count($rows) === 0) {
        logInfo("第 {$batch} 批无数据，结束");
        break;
    }

    // 构造推送数据与ID集合（不去重，保持与回写一致）
    $batchValues = [];
    $idsToUpdate = [];
    foreach ($rows as $r) {
        $kw = trim((string)$r['keyword']);
        if ($kw === '') { continue; }
        $batchValues[] = $kw;
        $idsToUpdate[] = (int)$r['id'];
    }

    if (count($batchValues) === 0) {
        logInfo("第 {$batch} 批均为空，跳过");
        continue;
    }

    // 推入 Redis（rpush），分片避免参数过多
    $chunkSize = 500;
    $pushed = 0;
    try {
        for ($i = 0; $i < count($batchValues); $i += $chunkSize) {
            $chunk = array_slice($batchValues, $i, $chunkSize);
            if ($redisClient instanceof Redis) {
                $args = array_merge(['keyword_list'], $chunk);
                call_user_func_array([$redisClient, 'rPush'], $args);
            } else {
                foreach ($chunk as $val) {
                    $redisClient->rpush('keyword_list', [$val]);
                }
            }
            $pushed += count($chunk);
        }
        $totalPushed += $pushed;
        logInfo("第 {$batch} 批已推入 Redis: {$pushed} 条");
    } catch (Throwable $e) {
        fwrite(STDERR, "[ERROR] 第 {$batch} 批写入 Redis 失败: {$e->getMessage()}\n");
        break;
    }

    // 将本批写入Redis的数据在数据库中标记为 is_read=1
    if ($pushed > 0 && count($idsToUpdate) > 0) {
        try {
            $idsChunkSize = 1000;
            foreach (array_chunk($idsToUpdate, $idsChunkSize) as $idsChunk) {
                $placeholders = rtrim(str_repeat('?,', count($idsChunk)), ',');
                $sqlUpdate = "UPDATE `keyword` SET `is_read` = 1, `update_time` = CURRENT_TIMESTAMP WHERE `is_read` = 0 AND `id` IN ($placeholders)";
                $stmtUp = $pdo->prepare($sqlUpdate);
                $stmtUp->execute($idsChunk);
            }
            logInfo("第 {$batch} 批已标记 is_read=1: " . count($idsToUpdate) . " 条");
        } catch (Throwable $e) {
            fwrite(STDERR, "[WARN] 第 {$batch} 批标记 is_read 失败: {$e->getMessage()}\n");
        }
    }
}

logInfo("任务完成，共推入 Redis: {$totalPushed} 条"); 
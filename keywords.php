<?php
// keywords.php - 返回关键词列表JSON

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

    // 读取关键词，按id倒序，最多1000条
    $sql = 'SELECT `keyword` FROM `keyword` WHERE `keyword` <> "" ORDER BY `id` DESC LIMIT 1000';
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    $keywords = [];
    $seen = [];
    foreach ($rows as $r) {
        $kw = trim((string)$r['keyword']);
        if ($kw === '') { continue; }
        if (isset($seen[$kw])) { continue; }
        $seen[$kw] = true;
        $keywords[] = $kw;
    }

    echo json_encode(['code' => 0, 'msg' => 'ok', 'data' => ['keywords' => $keywords]], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['code' => 1, 'msg' => '数据库错误: ' . $e->getMessage(), 'data' => null], JSON_UNESCAPED_UNICODE);
} 
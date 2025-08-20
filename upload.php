<?php
// upload.php
// 依赖: phpoffice/phpspreadsheet
// 用法: 通过浏览器访问本文件，上传Excel后导入数据库

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

// 提升内存与执行时间配额，避免大型文件报错
ini_set('memory_limit', '512M');
set_time_limit(0);

date_default_timezone_set('Asia/Shanghai');

// 自动加载Composer依赖
$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    exit('<p style="font-family:Arial">未发现依赖，请先在项目根目录执行: <code>composer install</code></p>');
}
require $vendorAutoload;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

// 仅读取指定列与行的过滤器（保留以备需要）
class ColumnRowFilter implements IReadFilter {
    private int $startRow;
    private int $endRow;
    /** @var string[] */
    private array $columns;

    public function __construct(int $startRow = 2, int $endRow = 1048576, array $columns = ['A']) {
        $this->startRow = $startRow;
        $this->endRow = $endRow;
        $this->columns = $columns;
    }

    public function setRange(int $startRow, int $endRow, ?array $columns = null): void {
        $this->startRow = $startRow;
        $this->endRow = $endRow;
        if ($columns !== null) {
            $this->columns = $columns;
        }
    }

    public function readCell($column, $row, $worksheetName = ''): bool {
        if ($row < $this->startRow || $row > $this->endRow) {
            return false;
        }
        if (!in_array($column, $this->columns, true)) {
            return false;
        }
        return true;
    }
}

// 读取数据库配置
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    exit('<p style="font-family:Arial">缺少数据库配置文件 <code>config.php</code></p>');
}
$dbConfig = require $configPath;

// 创建PDO连接（父进程/顺序模式使用；多进程子进程会各自重连）
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $dbConfig['db_host'],
    (int)$dbConfig['db_port'],
    $dbConfig['db_name'],
    $dbConfig['db_charset']
);
try {
    $pdo = new PDO($dsn, $dbConfig['db_user'], $dbConfig['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$dbConfig['db_charset']}"
    ]);
} catch (Throwable $e) {
    exit('<p style="font-family:Arial">数据库连接失败: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

$messages = [];
$results = [];

// 工具函数：处理一个sheet（顺序模式使用）
$processSheetSequential = function(string $filePath, string $sheetName, PDO $pdo) use (&$results) {
    $reader = IOFactory::createReader(pathinfo($filePath, PATHINFO_EXTENSION) === 'xls' ? 'Xls' : 'Xlsx');
    $reader->setReadDataOnly(true);
    $reader->setLoadSheetsOnly([$sheetName]);
    $spreadsheet = $reader->load($filePath);
    $sheet = $spreadsheet->getSheet(0);

    $lastRow = $sheet->getHighestDataRow('A');
    $inserted = 0;

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO `keyword` (`type`, `keyword`, `add_time`, `update_time`) VALUES (:type, :keyword, NOW(), NOW())');
    for ($row = 2; $row <= $lastRow; $row++) {
        $value = trim((string)$sheet->getCell('A' . $row)->getFormattedValue());
        if ($value === '') { continue; }
        $stmt->execute([
            ':type' => $sheetName,
            ':keyword' => $value,
        ]);
        $inserted++;
    }
    $pdo->commit();

    $results[] = [ 'sheet' => $sheetName, 'inserted' => $inserted ];

    $spreadsheet->disconnectWorksheets();
    unset($sheet, $spreadsheet);
    if (function_exists('gc_collect_cycles')) gc_collect_cycles();
};

// 工具函数：子进程处理一个sheet（多进程模式）
$processSheetChild = function(string $filePath, string $sheetName, array $dbConfig) {
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $dbConfig['db_host'], (int)$dbConfig['db_port'], $dbConfig['db_name'], $dbConfig['db_charset']
        );
        $pdoChild = new PDO($dsn, $dbConfig['db_user'], $dbConfig['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$dbConfig['db_charset']}"
        ]);

        $reader = IOFactory::createReader(pathinfo($filePath, PATHINFO_EXTENSION) === 'xls' ? 'Xls' : 'Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheetName]);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getSheet(0);

        $lastRow = $sheet->getHighestDataRow('A');
        $pdoChild->beginTransaction();
        $stmt = $pdoChild->prepare('INSERT INTO `keyword` (`type`, `keyword`, `add_time`, `update_time`) VALUES (:type, :keyword, NOW(), NOW())');
        for ($row = 2; $row <= $lastRow; $row++) {
            $value = trim((string)$sheet->getCell('A' . $row)->getFormattedValue());
            if ($value === '') { continue; }
            $stmt->execute([
                ':type' => $sheetName,
                ':keyword' => $value,
            ]);
        }
        $pdoChild->commit();

        $spreadsheet->disconnectWorksheets();
        unset($sheet, $spreadsheet, $stmt, $pdoChild);
        if (function_exists('gc_collect_cycles')) gc_collect_cycles();
        exit(0);
    } catch (Throwable $e) {
        // 子进程失败直接退出非零码
        file_put_contents('php://stderr', "[child] sheet {$sheetName} failed: " . $e->getMessage() . "\n");
        exit(2);
    }
};

// 处理上传
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['excel']) || $_FILES['excel']['error'] !== UPLOAD_ERR_OK) {
        $messages[] = '文件上传失败，请重试。';
    } else {
        $tmpName = $_FILES['excel']['tmp_name'];
        $origName = $_FILES['excel']['name'] ?? 'upload.xlsx';
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx','xls','csv'], true)) {
            $messages[] = '仅支持上传 .xlsx / .xls / .csv 文件。';
        } else {
            try {
                // 全量导入前清空表
                $pdo->exec('TRUNCATE TABLE `keyword`');
                if ($ext === 'csv') {
                    // CSV 顺序读取：取第一列，从第2行到最后一个非空的行（中间空行跳过）
                    $type = pathinfo($origName, PATHINFO_FILENAME) . ' (CSV)';
                    $file = new SplFileObject($tmpName, 'r');
                    $file->setFlags(SplFileObject::READ_CSV | SplFileObject::DROP_NEW_LINE);
                    $rows = [];
                    $lineNum = 0;
                    $lastNonEmpty = 0;
                    foreach ($file as $row) {
                        if ($row === null) { continue; }
                        $lineNum++;
                        if ($lineNum < 2) { continue; } // 跳过首行
                        $val = '';
                        if (is_array($row) && count($row) > 0) {
                            $val = trim((string)$row[0]);
                        } else if (is_string($row)) {
                            $val = trim($row);
                        }
                        $rows[$lineNum] = $val;
                        if ($val !== '') { $lastNonEmpty = $lineNum; }
                    }
                    if ($lastNonEmpty >= 2) {
                        $pdo->beginTransaction();
                        $stmt = $pdo->prepare('INSERT INTO `keyword` (`type`, `keyword`, `add_time`, `update_time`) VALUES (:type, :keyword, NOW(), NOW())');
                        $inserted = 0;
                        for ($ln = 2; $ln <= $lastNonEmpty; $ln++) {
                            $val = $rows[$ln] ?? '';
                            if ($val === '') { continue; }
                            $stmt->execute([':type' => $type, ':keyword' => $val]);
                            $inserted++;
                        }
                        $pdo->commit();
                        $results[] = [ 'sheet' => $type, 'inserted' => $inserted ];
                    }
                } else {
                    // XLSX/XLS：按sheet并发（若支持pcntl），从A2读取到最后非空行
                    $reader = IOFactory::createReader($ext === 'xls' ? 'Xls' : 'Xlsx');
                    $reader->setReadDataOnly(true);
                    $sheetNames = $reader->listWorksheetNames($tmpName);

                    $canFork = function_exists('pcntl_fork') && stripos(PHP_OS_FAMILY, 'Windows') === false;
                    $maxChildren = 4; // 并发子进程数
                    $children = [];

                    if ($canFork) {
                        foreach ($sheetNames as $sheetName) {
                            // 控制并发
                            while (count($children) >= $maxChildren) {
                                $status = 0;
                                $pid = pcntl_wait($status);
                                if ($pid > 0) {
                                    unset($children[$pid]);
                                }
                            }
                            $pid = pcntl_fork();
                            if ($pid === -1) {
                                // fork失败，回退顺序
                                $canFork = false;
                                break;
                            } elseif ($pid === 0) {
                                // 子进程
                                $processSheetChild($tmpName, $sheetName, $dbConfig);
                                // 不达此行
                            } else {
                                // 父进程记录子进程
                                $children[$pid] = true;
                            }
                        }
                        // 等待剩余子进程
                        while (count($children) > 0) {
                            $status = 0;
                            $pid = pcntl_wait($status);
                            if ($pid > 0) {
                                unset($children[$pid]);
                            }
                        }
                        $messages[] = '多进程导入完成。';
                    }

                    if (!$canFork) {
                        // 顺序处理所有sheet
                        foreach ($sheetNames as $sheetName) {
                            $processSheetSequential($tmpName, $sheetName, $pdo);
                        }
                        $messages[] = '顺序导入完成。';
                    }
                }
            } catch (Throwable $e) {
                $messages[] = '导入失败: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Excel导入到数据库 - shenma.keyword</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f6f7fb; margin:0; padding:30px; }
        .card { max-width: 840px; margin:0 auto; background:#fff; border-radius:10px; box-shadow:0 6px 18px rgba(0,0,0,0.08); overflow:hidden; }
        .card-header { padding:18px 22px; background:linear-gradient(135deg,#667eea,#764ba2); color:#fff; }
        .card-body { padding:22px; }
        .row { margin-bottom:16px; }
        label { display:block; margin-bottom:8px; color:#333; font-weight:bold; }
        input[type="file"] { display:block; }
        .btn { background:#4CAF50; color:#fff; padding:10px 18px; border:none; border-radius:6px; cursor:pointer; font-size:14px; }
        .btn:hover { background:#45a049; }
        .tips { color:#666; font-size:13px; margin-top:8px; }
        .messages { margin:12px 0; }
        .msg { background:#f1f7ff; color:#0b6efd; padding:10px 12px; border-radius:6px; margin-bottom:8px; }
        .error { background:#fff5f5; color:#d63031; }
        table { width:100%; border-collapse:collapse; margin-top:12px; }
        th, td { border:1px solid #eee; padding:10px; text-align:left; }
        th { background:#fafafa; }
        code { background:#f4f4f4; padding:2px 6px; border-radius:4px; }
    </style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <h2>Excel 批量导入到数据库：shenma.keyword</h2>
        <div style="font-size:13px;opacity:0.9;">每个Sheet读取A2到最后一个有值的行，Sheet名作type，单元格值作keyword；支持多进程。</div>
    </div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <div class="row">
                <label for="excel">选择Excel文件 (.xlsx/.xls/.csv)</label>
                <input type="file" name="excel" id="excel" accept=".xlsx,.xls,.csv" required>
                <div class="tips">请确保数据库已存在表 <code>keyword(id, type, keyword, add_time, update_time)</code></div>
            </div>
            <div class="row">
                <button class="btn" type="submit">开始导入</button>
            </div>
        </form>

        <?php if (!empty($messages)): ?>
            <div class="messages">
                <?php foreach ($messages as $msg): ?>
                    <div class="msg<?php echo stripos($msg, '失败') !== false ? ' error' : '' ; ?>"><?php echo htmlspecialchars($msg); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($results)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Sheet名称(type)</th>
                        <th>插入记录数</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['sheet']); ?></td>
                        <td><?php echo (int)$r['inserted']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="tips" style="margin-top:16px;">
            如需创建表，可参考（根据需要调整字段类型/索引）:
            <pre><code>CREATE TABLE `keyword` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(255) NOT NULL DEFAULT '' COMMENT '类型=sheet名称',
  `keyword` varchar(255) NOT NULL DEFAULT '' COMMENT '搜索关键词',
  `add_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '添加时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_keyword` (`keyword`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</code></pre>
        </div>
    </div>
</div>
</body>
</html> 
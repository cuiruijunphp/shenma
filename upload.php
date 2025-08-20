<?php
// upload.php
// 依赖: phpoffice/phpspreadsheet
// 用法: 通过浏览器访问本文件，上传Excel后导入数据库

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

// 提升内存与执行时间配额，避免大型文件报错
ini_set('memory_limit', '512M');
set_time_limit(300);

date_default_timezone_set('Asia/Shanghai');

// 自动加载Composer依赖
$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    exit('<p style="font-family:Arial">未发现依赖，请先在项目根目录执行: <code>composer install</code></p>');
}
require $vendorAutoload;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

// 仅读取指定列与行的过滤器
class ColumnRowFilter implements IReadFilter {
    private int $startRow;
    private int $endRow;
    /** @var string[] */
    private array $columns;

    public function __construct(int $startRow = 2, int $endRow = 1001, array $columns = ['A']) {
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

// 创建PDO连接
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
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO `keyword` (`type`, `keyword`, `add_time`, `update_time`) VALUES (:type, :keyword, NOW(), NOW())');

                if ($ext === 'csv') {
                    // CSV: 作为单sheet处理，读取第2到第21行的第一列
                    $type = pathinfo($origName, PATHINFO_FILENAME) . ' (CSV)';
                    $inserted = 0;
                    $file = new SplFileObject($tmpName, 'r');
                    $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
                    $lineNum = 0;
                    foreach ($file as $row) {
                        if ($row === null) { continue; }
                        $lineNum++;
                        if ($lineNum === 1) { continue; } // 跳过第一行
                        if ($lineNum > 1001) { break; }
                        $value = '';
                        if (is_array($row) && count($row) > 0) {
                            $value = trim((string)$row[0]); // 第一列=A列
                        } else if (is_string($row)) {
                            $value = trim($row);
                        }
                        if ($value === '') { continue; }
                        $stmt->execute([
                            ':type' => $type,
                            ':keyword' => $value,
                        ]);
                        $inserted++;
                    }
                    $results[] = [ 'sheet' => $type, 'inserted' => $inserted ];
                } else {
                    // XLSX/XLS: 使用过滤器仅读取A列与A2-A21，并逐sheet加载释放
                    $reader = IOFactory::createReader($ext === 'xls' ? 'Xls' : 'Xlsx');
                    $reader->setReadDataOnly(true);
                    if (method_exists($reader, 'setReadEmptyCells')) {
                        $reader->setReadEmptyCells(false);
                    }
                    $filter = new ColumnRowFilter(2, 1001, ['A']);
                    $reader->setReadFilter($filter);

                    // 获取所有sheet名称，避免一次性加载整本表
                    $sheetNames = $reader->listWorksheetNames($tmpName);

                    foreach ($sheetNames as $sheetName) {
                        $reader->setLoadSheetsOnly([$sheetName]);
                        $spreadsheet = $reader->load($tmpName);
                        $sheet = $spreadsheet->getSheet(0);

                        $inserted = 0;
                        for ($row = 2; $row <= 1001; $row++) {
                            $cell = $sheet->getCell('A' . $row);
                            $value = trim((string)$cell->getFormattedValue());
                            if ($value === '') { continue; }
                            $stmt->execute([
                                ':type' => $sheetName,
                                ':keyword' => $value,
                            ]);
                            $inserted++;
                        }

                        $results[] = [ 'sheet' => $sheetName, 'inserted' => $inserted ];

                        // 释放内存
                        $spreadsheet->disconnectWorksheets();
                        unset($sheet, $spreadsheet);
                        gc_collect_cycles();
                    }
                }

                $pdo->commit();
                $messages[] = '导入完成。';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
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
        <div style="font-size:13px;opacity:0.9;">每个Sheet读取A2到A21（20行），Sheet名作type，单元格值作keyword</div>
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
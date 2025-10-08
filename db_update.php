<?php
// db_update.php - Upgrades the database schema for Linker Monitor
header('Content-Type: text/plain; charset=utf-8');

// 【新增】入口守卫 - 只允许通过命令行访问
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Direct access not permitted. This script should only be run from command line.');
}

require_once __DIR__ . '/config.php';

echo "灵刻监控 数据库升级脚本\n";
echo "================================\n\n";

if (!isset($db_config)) {
    die("错误: 无法加载 'config.php'。请确保该文件存在且可读。\n");
}

try {
    // Universal PDO connection
    if ($db_config['type'] === 'pgsql') {
        $cfg = $db_config['pgsql'];
        $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']}";
        $pdo = new PDO($dsn, $cfg['user'], $cfg['password']);
    } elseif ($db_config['type'] === 'mysql') {
        $cfg = $db_config['mysql'];
        $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['user'], $cfg['password']);
    } else { // sqlite
        $dsn = 'sqlite:' . $db_config['sqlite']['path'];
        $pdo = new PDO($dsn);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "成功连接到 '{$db_config['type']}' 数据库。\n\n";

} catch (Exception $e) {
    die("数据库连接失败: " . $e->getMessage() . "\n");
}

function columnExists($pdo, $tableName, $columnName, $dbType) {
    try {
        if ($dbType === 'sqlite') {
            $stmt = $pdo->prepare("PRAGMA table_info($tableName)");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
            return in_array($columnName, $columns);
        } else { // Works for both mysql and pgsql
            $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?");
            $stmt->execute([$tableName, $columnName]);
            return $stmt->fetchColumn() !== false;
        }
    } catch (Exception $e) {
        return false; // Assume it doesn't exist on error
    }
}

$db_type = $db_config['type'];
$sqls = [];

// --- `servers` table updates ---
$servers_updates = [
    'country_code' => $db_type === 'mysql' ? 'ALTER TABLE servers ADD COLUMN country_code VARCHAR(10) DEFAULT NULL;' : 'ALTER TABLE servers ADD COLUMN country_code TEXT;',
    'system' => $db_type === 'mysql' ? 'ALTER TABLE servers ADD COLUMN system TEXT;' : 'ALTER TABLE servers ADD COLUMN system TEXT;',
    'arch' => $db_type === 'mysql' ? 'ALTER TABLE servers ADD COLUMN arch VARCHAR(255) DEFAULT NULL;' : 'ALTER TABLE servers ADD COLUMN arch TEXT;',
];

foreach ($servers_updates as $column => $sql) {
    if (!columnExists($pdo, 'servers', $column, $db_type)) {
        $sqls[] = $sql;
        echo "准备向 'servers' 表添加 '{$column}' 列。\n";
    } else {
        echo "列 '{$column}' 已存在于 'servers' 表中，跳过。\n";
    }
}


// --- `server_stats` table updates ---
$stats_updates = [
    'processes' => $db_type === 'mysql' ? 'ALTER TABLE server_stats ADD COLUMN processes INT DEFAULT NULL;' : 'ALTER TABLE server_stats ADD COLUMN processes INTEGER;',
    'connections' => $db_type === 'mysql' ? 'ALTER TABLE server_stats ADD COLUMN connections INT DEFAULT NULL;' : 'ALTER TABLE server_stats ADD COLUMN connections INTEGER;',
];

foreach ($stats_updates as $column => $sql) {
    if (!columnExists($pdo, 'server_stats', $column, $db_type)) {
        $sqls[] = $sql;
        echo "准备向 'server_stats' 表添加 '{$column}' 列。\n";
    } else {
        echo "列 '{$column}' 已存在于 'server_stats' 表中，跳过。\n";
    }
}


// --- 添加性能优化索引 ---
$index_sqls = [];

// 检查并添加 server_stats 表的复合索引
try {
    if ($db_type === 'mysql') {
        $stmt = $pdo->prepare("SHOW INDEX FROM server_stats WHERE Key_name = 'server_time_idx'");
        $stmt->execute();
        $index_exists = $stmt->fetch() !== false;
    } elseif ($db_type === 'pgsql') {
        $stmt = $pdo->prepare("SELECT 1 FROM pg_indexes WHERE tablename = 'server_stats' AND indexname = 'server_time_idx'");
        $stmt->execute();
        $index_exists = $stmt->fetch() !== false;
    } else { // sqlite
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='index' AND name='server_time_idx'");
        $stmt->execute();
        $index_exists = $stmt->fetch() !== false;
    }
    
    if (!$index_exists) {
        $index_sqls[] = "CREATE INDEX server_time_idx ON server_stats (server_id, timestamp)";
        echo "准备添加 server_stats 表的性能优化索引。\n";
    } else {
        echo "索引 'server_time_idx' 已存在于 'server_stats' 表中，跳过。\n";
    }
} catch (Exception $e) {
    echo "检查索引时发生错误: " . $e->getMessage() . "\n";
}

// Execute all necessary SQLs
if (empty($sqls) && empty($index_sqls)) {
    echo "\n数据库结构已是最新，无需更新。\n";
} else {
    try {
        $pdo->beginTransaction();
        foreach ($sqls as $sql) {
            $pdo->exec($sql);
            echo "执行: $sql\n";
        }
        foreach ($index_sqls as $sql) {
            $pdo->exec($sql);
            echo "执行: $sql\n";
        }
        $pdo->commit();
        echo "\n数据库升级成功！\n";
    } catch (Exception $e) {
        $pdo->rollBack();
        die("\n升级过程中发生错误: " . $e->getMessage() . "\n");
    }
}

echo "\n升级完成。为了安全，请从服务器上删除此脚本 (update_db.php)。\n";

?>

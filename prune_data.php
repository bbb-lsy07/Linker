<?php
/**
 * prune_data.php - Cron job script to prune old server_stats records.
 * 用于清理旧的监控数据，防止数据库无限增长。
 */

// 只允许通过命令行或cron访问
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Direct access not permitted.');
}

require_once __DIR__ . '/includes/bootstrap.php';

// 定义要保留的数据天数 (例如，保留最近30天的数据)
const RETAIN_DAYS = 30;

echo "开始清理 " . RETAIN_DAYS . " 天前的监控数据...\n";

try {
    $pdo = get_pdo_connection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db_type = $db_config['type'];
    $timestamp_threshold = time() - (RETAIN_DAYS * 86400);

    if ($db_type === 'mysql') {
        // MySQL 使用 FROM_UNIXTIME
        $sql = "DELETE FROM server_stats WHERE timestamp < ?";
    } elseif ($db_type === 'pgsql') {
        // PostgreSQL 使用 to_timestamp
        $sql = "DELETE FROM server_stats WHERE timestamp < ?";
    } else { // sqlite
        // SQLite 直接比较
        $sql = "DELETE FROM server_stats WHERE timestamp < ?";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$timestamp_threshold]);
    
    $deleted_count = $stmt->rowCount();
    echo "清理完成！共删除 " . $deleted_count . " 条旧记录。\n";

} catch (Exception $e) {
    error_log("prune_data.php CRON Error: " . $e->getMessage());
    echo "发生错误: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
?>

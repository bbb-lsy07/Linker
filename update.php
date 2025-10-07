<?php
// db_fix.php - Creates the missing 'settings' table for PostgreSQL users.

header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config.php';

// Ensure this script is only run if using pgsql
if ($db_config['type'] !== 'pgsql') {
    die("此脚本仅适用于 PostgreSQL 数据库配置。您的配置是 '{$db_config['type']}'，无需运行此脚本。");
}

try {
    // Get PDO connection
    $cfg = $db_config['pgsql'];
    $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']}";
    $pdo = new PDO($dsn, $cfg['user'], $cfg['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // SQL to create the 'settings' table
    $sql = "
        CREATE TABLE IF NOT EXISTS settings (
            key VARCHAR(255) PRIMARY KEY,
            value TEXT
        );
    ";

    $pdo->exec($sql);

    echo "数据库修复成功！\n";
    echo "'settings' 表已成功创建。\n";
    echo "请立即从服务器上删除此脚本 (db_fix.php) 以确保安全。";

} catch (Exception $e) {
    http_response_code(500);
    die("数据库修复失败: " . $e->getMessage());
}
?>

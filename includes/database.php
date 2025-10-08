<?php
// 【新增】入口守卫
if (!defined('LINKER_SECURE_INCLUDE')) {
    http_response_code(403);
    die('Direct access not permitted.');
}

/**
 * 数据库连接函数
 * 提供统一的数据库连接管理
 */

function get_pdo_connection() {
    global $db_config; // 使用全局变量
    
    if (empty($db_config)) {
        throw new Exception("数据库配置 (config.php) 丢失或为空。");
    }
    
    try {
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
            $pdo->exec('PRAGMA journal_mode = WAL;');
        }
        
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception("数据库连接失败: " . $e->getMessage());
    }
}

/**
 * 生成密钥函数
 */
function generate_secret_key($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

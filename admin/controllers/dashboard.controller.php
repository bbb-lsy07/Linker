<?php
/**
 * Dashboard 控制器
 * 仅负责获取数据以供视图展示
 * 所有 POST 处理逻辑已移至 admin/api/admin.php
 */

// 初始化变量
$message = '';
$error_message = '';

try {
    $pdo = get_pdo_connection();

    // Fetch data for display
    $servers = $pdo->query("SELECT * FROM servers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    
    $settings_stmt = $pdo->query("SELECT `key`, value FROM settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $site_name = $settings['site_name'] ?? '灵刻监控';
    $telegram_bot_token = $settings['telegram_bot_token'] ?? '';
    $telegram_chat_id = $settings['telegram_chat_id'] ?? '';

} catch (Exception $e) {
    $error_message = "操作失败: " . $e->getMessage();
}

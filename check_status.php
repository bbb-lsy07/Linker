<?php
// check_status.php - Cron job script to check for offline servers and send alerts. v2.1 (Optimized)
date_default_timezone_set('UTC'); 

require_once __DIR__ . '/includes/bootstrap.php';

function send_telegram_message($token, $chat_id, $message) {
    if (empty($token) || empty($chat_id)) {
        error_log("Telegram bot token or chat ID is not configured.");
        return false;
    }
    $url = "https://api.telegram.org/bot" . $token . "/sendMessage";
    $data = ['chat_id' => $chat_id, 'text' => $message, 'parse_mode' => 'Markdown'];
    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'ignore_errors' => true
        ],
    ];
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    if ($result === FALSE) {
        error_log("Telegram API request failed.");
        return false;
    }
    
    $response_data = json_decode($result, true);
    if (!isset($response_data['ok']) || !$response_data['ok']) {
        error_log("Telegram API Error: " . ($response_data['description'] ?? 'Unknown error'));
        return false;
    }
    return true;
}

const OFFLINE_THRESHOLD = 35; // Seconds since last report to be marked as offline

try {
    $pdo = get_pdo_connection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $current_time = time();
    $offline_timestamp = $current_time - OFFLINE_THRESHOLD;

    // --- 1. [优化] 一次性将所有超时的服务器标记为离线 ---
    $offline_val = $db_config['type'] === 'pgsql' ? 'false' : 0;
    $is_online_col = 'is_online'; // 在所有支持的数据库中，列名都是 is_online
    
    $sql_mark_offline = "UPDATE server_status SET {$is_online_col} = {$offline_val} WHERE {$is_online_col} = 1 AND last_checked < ?";
    $stmt_mark_offline = $pdo->prepare($sql_mark_offline);
    $stmt_mark_offline->execute([$offline_timestamp]);

    // --- 2. [优化] 批量处理掉线和恢复通知 ---
    $settings_stmt = $pdo->query("SELECT `key`, value FROM settings WHERE `key` LIKE 'telegram_%'");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $bot_token = $settings['telegram_bot_token'] ?? '';
    $chat_id = $settings['telegram_chat_id'] ?? '';

    // --- A. 找出刚刚掉线的服务器并发送通知 ---
    // 条件：服务器状态为离线，但在 outages 表中没有未结束的记录。
    $sql_new_outages = "
        SELECT s.id, s.name FROM servers s
        JOIN server_status st ON s.id = st.id
        WHERE st.is_online = {$offline_val}
        AND NOT EXISTS (SELECT 1 FROM outages WHERE server_id = s.id AND end_time IS NULL)
    ";
    $stmt_new_outages = $pdo->query($sql_new_outages);
    $newly_offline_servers = $stmt_new_outages->fetchAll(PDO::FETCH_ASSOC);

    if (count($newly_offline_servers) > 0) {
        $insert_outage_stmt = $pdo->prepare("INSERT INTO outages (server_id, start_time, title, content) VALUES (?, ?, '服务器掉线', '服务器停止报告数据。')");
        foreach ($newly_offline_servers as $server) {
            $insert_outage_stmt->execute([$server['id'], $current_time]);
            $message = "🔴 *服务离线警告*\n\n服务器 `{$server['name']}` (`{$server['id']}`) 已停止响应。";
            send_telegram_message($bot_token, $chat_id, $message);
        }
    }

    // --- B. 找出刚刚恢复的服务器并发送通知 ---
    // 条件：服务器状态为在线，但在 outages 表中存在未结束的记录。
    $online_val_bool = $db_config['type'] === 'pgsql' ? 'true' : 1;
    $sql_recovered = "
        SELECT s.id, s.name, o.id as outage_id, o.start_time
        FROM servers s
        JOIN server_status st ON s.id = st.id
        JOIN outages o ON s.id = o.server_id
        WHERE st.is_online = {$online_val_bool} AND o.end_time IS NULL
    ";
    $stmt_recovered = $pdo->query($sql_recovered);
    $recovered_servers = $stmt_recovered->fetchAll(PDO::FETCH_ASSOC);

    if (count($recovered_servers) > 0) {
        $update_outage_stmt = $pdo->prepare("UPDATE outages SET end_time = ? WHERE id = ?");
        foreach ($recovered_servers as $server) {
            $update_outage_stmt->execute([$current_time, $server['outage_id']]);
            
            $duration = $current_time - $server['start_time'];
            $duration_str = '';
            if ($duration < 60) { $duration_str = "{$duration} 秒"; }
            elseif ($duration < 3600) { $duration_str = round($duration / 60) . " 分钟"; }
            else { $duration_str = round($duration / 3600, 1) . " 小时"; }
            
            $message = "✅ *服务恢复通知*\n\n服务器 `{$server['name']}` (`{$server['id']}`) 已恢复在线。\n持续离线时间：约 {$duration_str}。";
            send_telegram_message($bot_token, $chat_id, $message);
        }
    }

} catch (Exception $e) {
    error_log("check_status.php CRON Error: " . $e->getMessage());
    exit(1);
}

exit(0);
?>
<?php
/**
 * Admin API
 * 处理后台管理的异步请求
 */

session_start();
header('Content-Type: application/json');

// 检查用户是否已登录
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未授权访问']);
    exit;
}

// 引入通用文件
require_once __DIR__ . '/../../includes/bootstrap.php';

// 获取请求动作
$action = $_GET['action'] ?? '';

try {
    $pdo = get_pdo_connection();
    
    // CSRF 令牌验证
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        throw new Exception('无效的请求，请刷新页面后重试。');
    }
    
    switch ($action) {
        case 'delete_server':
            if (!isset($_POST['id'])) {
                throw new Exception('缺少服务器ID');
            }
            
            $pdo->beginTransaction();
            $stmt_del_server = $pdo->prepare("DELETE FROM servers WHERE id = ?");
            $stmt_del_server->execute([$_POST['id']]);
            $stmt_del_stats = $pdo->prepare("DELETE FROM server_stats WHERE server_id = ?");
            $stmt_del_stats->execute([$_POST['id']]);
            $stmt_del_status = $pdo->prepare("DELETE FROM server_status WHERE id = ?");
            $stmt_del_status->execute([$_POST['id']]);
            
            // 删除关联的掉线记录
            $stmt_del_outages = $pdo->prepare("DELETE FROM outages WHERE server_id = ?");
            $stmt_del_outages->execute([$_POST['id']]);
            
            $pdo->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => "服务器 '" . htmlspecialchars($_POST['id']) . "' 及其所有数据已成功删除！"
            ]);
            break;
            
        case 'generate_secret':
            if (!isset($_POST['generate_secret_id'])) {
                throw new Exception('缺少服务器ID');
            }
            
            $new_secret = generate_secret_key();
            $stmt = $pdo->prepare("UPDATE servers SET secret = ? WHERE id = ?");
            $stmt->execute([$new_secret, $_POST['generate_secret_id']]);
            
            echo json_encode([
                'success' => true, 
                'message' => "为服务器 '" . htmlspecialchars($_POST['generate_secret_id']) . "' 生成了新的密钥！",
                'new_secret' => $new_secret
            ]);
            break;
            
        case 'save_server':
            $id = trim($_POST['id']);
            $is_editing = !empty($_POST['is_editing']);
            
            if (!$is_editing) {
                $stmt_check = $pdo->prepare("SELECT id FROM servers WHERE id = ?");
                $stmt_check->execute([$id]);
                if ($stmt_check->fetch()) {
                     throw new Exception("服务器 ID '" . htmlspecialchars($id) . "' 已存在，请使用不同的ID。");
                }
            }
            
            $country_code = strtoupper(trim($_POST['country_code']));
            if (!empty($country_code) && !preg_match('/^[A-Z]{2}$/', $country_code)) {
                throw new Exception("国家代码必须是两位英文字母。");
            }

            $tags = !empty($_POST['tags']) ? implode(',', array_map('trim', explode(',', $_POST['tags']))) : null;
            $ip = !empty($_POST['ip']) ? trim($_POST['ip']) : null;
            
            if ($is_editing) {
                $sql = "UPDATE servers SET name = ?, ip = ?, latitude = ?, longitude = ?, intro = ?, tags = ?, country_code = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$_POST['name'], $ip, $_POST['latitude'], $_POST['longitude'], $_POST['intro'], $tags, $country_code, $id]);
                $message = "服务器 '" . htmlspecialchars($_POST['name']) . "' 已成功更新！";
            } else {
                $secret = generate_secret_key();
                $sql = "INSERT INTO servers (id, name, ip, latitude, longitude, intro, tags, country_code, secret) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$id, $_POST['name'], $ip, $_POST['latitude'], $_POST['longitude'], $_POST['intro'], $tags, $country_code, $secret]);
                $message = "服务器 '" . htmlspecialchars($_POST['name']) . "' 已成功添加！";
            }
            
            echo json_encode([
                'success' => true, 
                'message' => $message
            ]);
            break;
            
        case 'save_settings':
            $sql = ($db_config['type'] === 'pgsql') 
                 ? "INSERT INTO settings (`key`, value) VALUES (?, ?) ON CONFLICT (`key`) DO UPDATE SET value = EXCLUDED.value"
                 : "INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)";
            if($db_config['type'] === 'sqlite') {
                $sql = "INSERT OR REPLACE INTO settings (`key`, value) VALUES (?, ?)";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute(['site_name', $_POST['site_name']]);
            $stmt->execute(['telegram_bot_token', $_POST['telegram_bot_token']]);
            $stmt->execute(['telegram_chat_id', $_POST['telegram_chat_id']]);
            
            echo json_encode([
                'success' => true, 
                'message' => '通用设置已保存！'
            ]);
            break;
            
        default:
            throw new Exception('未知的操作');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
?>

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
            
        case 'get_setup_command':
            if (!isset($_POST['id'])) {
                throw new Exception('缺少服务器ID');
            }
            $server_id = $_POST['id'];

            $stmt = $pdo->prepare("SELECT secret FROM servers WHERE id = ?");
            $stmt->execute([$server_id]);
            $server = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$server) {
                throw new Exception('未找到指定的服务器。');
            }
            $secret = $server['secret'];

            // 动态生成 API 端点 URL
            $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $api_endpoint = "{$scheme}://{$host}/report.php";
            $script_url = "{$scheme}://{$host}/update.sh";

            // 生成推荐的 Systemd 命令
            // 使用 HEREDOC 语法使多行命令更清晰
            $command_systemd = <<<SHELL
sudo bash -c "
# Download the agent script
curl -sSL {$script_url} -o /usr/local/bin/linker-agent.sh
chmod +x /usr/local/bin/linker-agent.sh

# Configure the agent
sed -i 's|API_ENDPOINT=\".*\"|API_ENDPOINT=\"{$api_endpoint}\"|' /usr/local/bin/linker-agent.sh
sed -i 's|SERVER_ID=\".*\"|SERVER_ID=\"{$server_id}\"|' /usr/local/bin/linker-agent.sh
sed -i 's|SECRET=\".*\"|SECRET=\"{$secret}\"|' /usr/local/bin/linker-agent.sh

# Create systemd service file
cat > /etc/systemd/system/linker-agent.service <<EOF
[Unit]
Description=Linker Monitor Agent
After=network.target

[Service]
Type=simple
ExecStart=/usr/local/bin/linker-agent.sh
Restart=always
User=root

[Install]
WantedBy=multi-user.target
EOF

# Start and enable the service
systemctl daemon-reload
systemctl enable linker-agent.service
systemctl restart linker-agent.service

echo '✅ Linker Agent 已通过 Systemd 成功部署并启动！'
"
SHELL;

            // 生成简单的 Nohup 命令 (作为备选)
            $command_nohup = <<<SHELL
# Download, configure, and run in background
curl -sSL {$script_url} | \\
sed 's|API_ENDPOINT=\".*\"|API_ENDPOINT=\"{$api_endpoint}\"|' | \\
sed 's|SERVER_ID=\".*\"|SERVER_ID=\"{$server_id}\"|' | \\
sed 's|SECRET=\".*\"|SECRET=\"{$secret}\"|' > linker-agent.sh && \\
chmod +x linker-agent.sh && \\
nohup ./linker-agent.sh &

echo '✅ Linker Agent 已通过 Nohup 在后台启动！'
SHELL;


            echo json_encode([
                'success' => true,
                'command_systemd' => $command_systemd,
                'command_nohup' => $command_nohup
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
                $server_data = null; // 编辑模式不需要返回数据
            } else {
                $secret = generate_secret_key();
                $sql = "INSERT INTO servers (id, name, ip, latitude, longitude, intro, tags, country_code, secret) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$id, $_POST['name'], $ip, $_POST['latitude'], $_POST['longitude'], $_POST['intro'], $tags, $country_code, $secret]);
                $message = "服务器 '" . htmlspecialchars($_POST['name']) . "' 已成功添加！";
                
                // 查询刚刚插入的服务器数据并返回
                $stmt_new = $pdo->prepare("SELECT * FROM servers WHERE id = ?");
                $stmt_new->execute([$id]);
                $server_data = $stmt_new->fetch(PDO::FETCH_ASSOC);
            }
            
            echo json_encode([
                'success' => true, 
                'message' => $message,
                'server_data' => $server_data
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

<?php
// edit_server.php - Handles updating server data

// 在这里加入你的身份验证逻辑 (例如检查 session/cookie)，确保只有管理员才能访问此脚本
// if (!is_admin()) {
//     http_response_code(403);
//     echo json_encode(['error' => 'Permission denied']);
//     exit;
// }

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

// 从 api.php 借用数据库连接函数
function get_pdo_connection() {
    global $db_config;
    try {
        if ($db_config['type'] === 'pgsql') {
            $cfg = $db_config['pgsql'];
            $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']}";
            return new PDO($dsn, $cfg['user'], $cfg['password']);
        } else {
            $dsn = 'sqlite:' . $db_config['sqlite']['path'];
            $pdo = new PDO($dsn);
            $pdo->exec('PRAGMA journal_mode = WAL;');
            return $pdo;
        }
    } catch (PDOException $e) {
        throw new Exception("Database connection failed: " . $e->getMessage());
    }
}

// 确保是 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

try {
    // 获取 POST 数据
    $id = $_POST['id'] ?? '';
    $name = $_POST['name'] ?? '';
    $ip = $_POST['ip'] ?? '';
    $port = filter_var($_POST['port'] ?? 0, FILTER_VALIDATE_INT);
    $longitude = filter_var($_POST['longitude'] ?? 0, FILTER_VALIDATE_FLOAT);
    $latitude = filter_var($_POST['latitude'] ?? 0, FILTER_VALIDATE_FLOAT);
    $intro = $_POST['intro'] ?? '';
    $price_usd_yearly = filter_var($_POST['price_usd_yearly'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    $expiry_date_str = $_POST['expiry_date'] ?? '';

    // **重要**：进行严格的数据验证
    if (empty($id) || empty($name)) {
        throw new Exception('Server ID and Name cannot be empty.');
    }

    // 将日期字符串转换为 Unix 时间戳
    $expiry_timestamp = !empty($expiry_date_str) ? strtotime($expiry_date_str) : null;
    if ($expiry_timestamp === false) {
        throw new Exception('Invalid expiry date format. Please use YYYY-MM-DD.');
    }

    $pdo = get_pdo_connection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 准备 SQL UPDATE 语句 (使用预处理语句防止 SQL 注入)
    $sql = "UPDATE servers SET 
                name = :name, 
                ip = :ip, 
                port = :port, 
                longitude = :longitude, 
                latitude = :latitude, 
                intro = :intro, 
                price_usd_yearly = :price_usd_yearly, 
                expiry_date = :expiry_date 
            WHERE id = :id";
            
    $stmt = $pdo->prepare($sql);

    // 绑定参数
    $stmt->bindParam(':id', $id);
    $stmt->bindParam(':name', $name);
    $stmt->bindParam(':ip', $ip);
    $stmt->bindParam(':port', $port, PDO::PARAM_INT);
    $stmt->bindParam(':longitude', $longitude);
    $stmt->bindParam(':latitude', $latitude);
    $stmt->bindParam(':intro', $intro);
    $stmt->bindParam(':price_usd_yearly', $price_usd_yearly);
    $stmt->bindParam(':expiry_date', $expiry_timestamp, PDO::PARAM_INT);

    // 执行更新
    $stmt->execute();

    // 返回成功信息
    echo json_encode(['success' => true, 'message' => 'Server updated successfully.']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
?>
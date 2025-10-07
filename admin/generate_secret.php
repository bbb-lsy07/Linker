<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

try {
    $id = $_POST['id'] ?? '';
    if (empty($id)) {
        throw new Exception('Server ID is required.');
    }

    $new_secret = bin2hex(random_bytes(16));

    $pdo = get_pdo_connection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("UPDATE servers SET secret = ? WHERE id = ?");
    $stmt->execute([$new_secret, $id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'secret' => $new_secret]);
    } else {
        throw new Exception('Server not found or secret could not be updated.');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>

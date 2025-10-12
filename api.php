<?php
// api.php - Provides monitoring data to the frontend v2.3 (Final)
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

require_once __DIR__ . '/includes/bootstrap.php';

$action = $_GET['action'] ?? 'get_all';
$serverId = $_GET['id'] ?? null;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 1440;

try {
    $pdo = get_pdo_connection();
    $service = new MonitoringService($pdo, $db_config);

    switch ($action) {
        case 'get_history':
            if (!$serverId) {
                linker_json_response(['error' => '缺少服务器 ID'], 400);
            }
            $history = $service->getServerHistory($serverId, $limit);
            linker_json_response(['history' => $history]);
            break;

        case 'get_summary':
            linker_json_response(['summary' => $service->getSummaryOnly()]);
            break;

        case 'get_node_detail':
            if (!$serverId) {
                linker_json_response(['error' => '缺少服务器 ID'], 400);
            }
            $node = $service->getServerDetails($serverId);
            linker_json_response(['node' => $node]);
            break;

        case 'get_all':
        default:
            $payload = $service->getDashboardPayload();
            linker_json_response($payload);
            break;
    }
} catch (Throwable $e) {
    error_log('api.php error: ' . $e->getMessage());
    linker_json_response(['error' => '服务器内部错误，请稍后重试。'], 500);
}

?>


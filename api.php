<?php
// api.php - Provides monitoring data to the frontend
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
require_once __DIR__ . '/includes/bootstrap.php';

$action = $_GET['action'] ?? 'get_all';
$server_id = $_GET['id'] ?? null;

try {
    $pdo = get_pdo_connection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($action === 'get_history' && $server_id) {
        // --- Action: Get detailed history for a single server (last 24h) ---
        $sql_history = 'SELECT cpu_usage, mem_usage_percent, disk_usage_percent, load_avg, net_up_speed, net_down_speed, total_up, total_down, timestamp, processes, connections FROM server_stats WHERE server_id = ? ORDER BY timestamp DESC LIMIT 1440';
        $stmt_history = $pdo->prepare($sql_history);
        $stmt_history->execute([$server_id]);
        $history = $stmt_history->fetchAll(PDO::FETCH_ASSOC);

        $typed_history = array_map(function($record) {
            return linker_normalise_stats($record);
        }, $history);

        $ordered_history = array_reverse($typed_history);
        $insights = linker_compute_history_insights($ordered_history);

        echo json_encode([
            'history' => $ordered_history,
            'insights' => $insights,
        ]);
        exit;

    } elseif ($action === 'get_all') {
        // --- Action: Get main dashboard data (lightweight) ---
        $response = [
            'nodes' => [],
            'outages' => [],
            'site_name' => '灵刻监控',
            'summary' => []
        ];
        
        $stmt_site_name = $pdo->prepare("SELECT value FROM settings WHERE `key` = 'site_name'");
        $stmt_site_name->execute();
        if ($site_name = $stmt_site_name->fetchColumn()) {
            $response['site_name'] = $site_name;
        }

        $stmt_servers = $pdo->query("SELECT id, name, intro, tags, price_usd_yearly, latitude, longitude, country_code, system, arch, cpu_model, mem_total, disk_total FROM servers ORDER BY id ASC");
        $servers = $stmt_servers->fetchAll(PDO::FETCH_ASSOC);

        $stmt_status = $pdo->query("SELECT id, is_online, last_checked FROM server_status");
        $online_status_raw = $stmt_status->fetchAll(PDO::FETCH_ASSOC);
        $online_status = [];
        foreach ($online_status_raw as $status) {
            $online_status[$status['id']] = $status;
        }
        
        if ($db_config['type'] === 'pgsql') {
            $sql_stats = "SELECT DISTINCT ON (server_id) * FROM server_stats ORDER BY server_id, timestamp DESC";
        } else { // Works for SQLite and MySQL
            $sql_stats = "SELECT s.* FROM server_stats s JOIN (SELECT server_id, MAX(timestamp) AS max_ts FROM server_stats GROUP BY server_id) AS m ON s.server_id = m.server_id AND s.timestamp = m.max_ts";
        }
        $stmt_stats = $pdo->query($sql_stats);
        $latest_stats_raw = $stmt_stats->fetchAll(PDO::FETCH_ASSOC);
        $latest_stats = [];
        foreach($latest_stats_raw as $stat) {
            $latest_stats[$stat['server_id']] = $stat;
        }

        foreach ($servers as $node) {
            $node_id = $node['id'];
            $node['x'] = (float)($node['latitude'] ?? 0);
            $node['y'] = (float)($node['longitude'] ?? 0);
            $node['mem_total'] = linker_cast_numeric($node['mem_total'] ?? null);
            $node['disk_total'] = linker_cast_numeric($node['disk_total'] ?? null);
            $node['price_usd_yearly'] = linker_cast_numeric($node['price_usd_yearly'] ?? null);

            $latest_stat = $latest_stats[$node_id] ?? [];
            $node['stats'] = !empty($latest_stat) ? linker_normalise_stats($latest_stat) : [];

            $status_info = $online_status[$node_id] ?? ['is_online' => false, 'last_checked' => 0];
            $node['is_online'] = (bool)$status_info['is_online'];
            $node['last_checked'] = isset($status_info['last_checked']) ? (int)$status_info['last_checked'] : null;

            if (!$node['is_online'] && $status_info['last_checked'] > 0) {
                 $node['anomaly_msg'] = '服务器掉线';
                 $node['outage_duration'] = time() - $status_info['last_checked'];
            }

            if (!empty($node['price_usd_yearly'])) {
                $node['price_usd_monthly'] = round(((float)$node['price_usd_yearly']) / 12.0, 2);
            }

            $node['history'] = []; // History is no longer included in the main payload

            $response['nodes'][] = $node;
        }

        $response['outages'] = $pdo->query("SELECT * FROM outages ORDER BY start_time DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        $response['summary'] = linker_compute_summary($response['nodes']);

        echo json_encode($response);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported action']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
?>


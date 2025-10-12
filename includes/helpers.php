<?php
if (!defined('LINKER_SECURE_INCLUDE')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Safely cast a numeric string to int/float while preserving null values.
 */
function linker_cast_numeric($value)
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return strpos((string)$value, '.') === false ? (int)$value : (float)$value;
    }
    return $value;
}

/**
 * Normalise the statistics payload of a server record.
 */
function linker_normalise_stats(array $stats): array
{
    $numeric_fields = [
        'cpu_usage', 'mem_usage_percent', 'disk_usage_percent', 'load_avg',
        'net_up_speed', 'net_down_speed', 'total_up', 'total_down',
        'timestamp', 'processes', 'connections'
    ];

    foreach ($numeric_fields as $field) {
        if (array_key_exists($field, $stats)) {
            $stats[$field] = linker_cast_numeric($stats[$field]);
        }
    }

    return $stats;
}

/**
 * Determine whether the provided node is experiencing a sustained high load.
 */
function linker_is_high_load_node(array $node): bool
{
    if (empty($node['stats']) || !empty($node['is_online']) === false) {
        return false;
    }
    $stats = $node['stats'];
    $cpu = isset($stats['cpu_usage']) ? (float)$stats['cpu_usage'] : null;
    $load = isset($stats['load_avg']) ? (float)$stats['load_avg'] : null;
    return ($cpu !== null && $cpu >= 85) || ($load !== null && $load >= 2.5);
}

/**
 * Compute aggregated dashboard summary information for the supplied node list.
 */
function linker_compute_summary(array $nodes): array
{
    $total_nodes = count($nodes);
    $online_nodes = 0;
    $high_load_nodes = 0;
    $cpu_values = [];
    $mem_values = [];
    $load_values = [];
    $monthly_cost = 0.0;
    $last_updated = 0;
    $tag_counts = [];
    $region_counts = [];
    $alerts = [];

    foreach ($nodes as $node) {
        $is_online = !empty($node['is_online']);
        if ($is_online) {
            $online_nodes++;
        }

        if (!empty($node['price_usd_yearly'])) {
            $monthly_cost += (float)$node['price_usd_yearly'] / 12.0;
        }

        if (!empty($node['tags'])) {
            $tags = array_filter(array_map('trim', explode(',', (string)$node['tags'])));
            foreach ($tags as $tag) {
                $tag_counts[$tag] = ($tag_counts[$tag] ?? 0) + 1;
            }
        }

        if (!empty($node['country_code'])) {
            $code = strtoupper((string)$node['country_code']);
            $region_counts[$code] = ($region_counts[$code] ?? 0) + 1;
        }

        if (!empty($node['stats'])) {
            $stats = $node['stats'];
            if (isset($stats['cpu_usage'])) {
                $cpu_values[] = (float)$stats['cpu_usage'];
            }
            if (isset($stats['mem_usage_percent'])) {
                $mem_values[] = (float)$stats['mem_usage_percent'];
            }
            if (isset($stats['load_avg'])) {
                $load_values[] = (float)$stats['load_avg'];
            }
            if (!empty($stats['timestamp'])) {
                $last_updated = max($last_updated, (int)$stats['timestamp']);
            }
        }

        if (!empty($node['last_checked'])) {
            $last_updated = max($last_updated, (int)$node['last_checked']);
        }

        if (!$is_online) {
            $alerts[] = [
                'id' => $node['id'],
                'name' => $node['name'],
                'severity' => 'critical',
                'message' => $node['anomaly_msg'] ?? '服务器掉线',
                'duration' => isset($node['outage_duration']) ? (int)$node['outage_duration'] : null,
                'country_code' => $node['country_code'] ?? null,
                'last_checked' => $node['last_checked'] ?? null,
            ];
        } elseif (linker_is_high_load_node($node)) {
            $high_load_nodes++;
            $alerts[] = [
                'id' => $node['id'],
                'name' => $node['name'],
                'severity' => 'warning',
                'message' => '检测到高负载运行',
                'duration' => null,
                'country_code' => $node['country_code'] ?? null,
                'last_checked' => $node['last_checked'] ?? null,
                'metric' => [
                    'cpu' => $node['stats']['cpu_usage'] ?? null,
                    'load' => $node['stats']['load_avg'] ?? null,
                ],
            ];
        }
    }

    usort($alerts, function ($a, $b) {
        $severity_order = ['critical' => 0, 'warning' => 1];
        $severity_diff = ($severity_order[$a['severity']] ?? 99) <=> ($severity_order[$b['severity']] ?? 99);
        if ($severity_diff !== 0) {
            return $severity_diff;
        }
        $duration_a = $a['duration'] ?? 0;
        $duration_b = $b['duration'] ?? 0;
        return $duration_b <=> $duration_a;
    });

    arsort($tag_counts);
    $top_tags = [];
    foreach (array_slice($tag_counts, 0, 5, true) as $tag => $count) {
        $top_tags[] = ['tag' => $tag, 'count' => $count];
    }

    arsort($region_counts);
    $regions = [];
    foreach (array_slice($region_counts, 0, 5, true) as $code => $count) {
        $regions[] = ['code' => $code, 'count' => $count];
    }

    $avg_cpu = !empty($cpu_values) ? round(array_sum($cpu_values) / count($cpu_values), 1) : null;
    $avg_mem = !empty($mem_values) ? round(array_sum($mem_values) / count($mem_values), 1) : null;
    $avg_load = !empty($load_values) ? round(array_sum($load_values) / count($load_values), 2) : null;

    return [
        'total_nodes' => $total_nodes,
        'online_nodes' => $online_nodes,
        'offline_nodes' => max($total_nodes - $online_nodes, 0),
        'high_load_nodes' => $high_load_nodes,
        'avg_cpu' => $avg_cpu,
        'avg_mem' => $avg_mem,
        'avg_load' => $avg_load,
        'total_monthly_cost' => round($monthly_cost, 2),
        'last_updated' => $last_updated,
        'top_tags' => $top_tags,
        'regions' => $regions,
        'alerts' => array_slice($alerts, 0, 10),
    ];
}

/**
 * Build human friendly insights from a 24 hour history payload.
 */
function linker_compute_history_insights(array $history): array
{
    if (empty($history)) {
        return [];
    }

    $count = count($history);
    $cpu_values = [];
    $mem_values = [];
    $load_values = [];
    $net_up_values = [];
    $net_down_values = [];
    $process_values = [];
    $connection_values = [];

    foreach ($history as $row) {
        if (isset($row['cpu_usage'])) {
            $cpu_values[] = (float)$row['cpu_usage'];
        }
        if (isset($row['mem_usage_percent'])) {
            $mem_values[] = (float)$row['mem_usage_percent'];
        }
        if (isset($row['load_avg'])) {
            $load_values[] = (float)$row['load_avg'];
        }
        if (isset($row['net_up_speed'])) {
            $net_up_values[] = [($row['net_up_speed']), $row['timestamp'] ?? null];
        }
        if (isset($row['net_down_speed'])) {
            $net_down_values[] = [($row['net_down_speed']), $row['timestamp'] ?? null];
        }
        if (isset($row['processes'])) {
            $process_values[] = [($row['processes']), $row['timestamp'] ?? null];
        }
        if (isset($row['connections'])) {
            $connection_values[] = [($row['connections']), $row['timestamp'] ?? null];
        }
    }

    $insights = [];

    if (!empty($cpu_values)) {
        $insights[] = [
            'label' => '平均 CPU 使用率',
            'value' => round(array_sum($cpu_values) / count($cpu_values), 1) . '%',
            'context' => '过去 24 小时',
        ];
        $peak_cpu = max($cpu_values);
        $insights[] = [
            'label' => '峰值 CPU 使用率',
            'value' => round($peak_cpu, 1) . '%',
            'context' => '过去 24 小时',
        ];
    }

    if (!empty($mem_values)) {
        $insights[] = [
            'label' => '平均内存占用',
            'value' => round(array_sum($mem_values) / count($mem_values), 1) . '%',
            'context' => '过去 24 小时',
        ];
    }

    if (!empty($load_values)) {
        $insights[] = [
            'label' => '最大系统负载',
            'value' => round(max($load_values), 2),
            'context' => '过去 24 小时',
        ];
    }

    if (!empty($net_up_values)) {
        usort($net_up_values, fn($a, $b) => ($b[0] ?? 0) <=> ($a[0] ?? 0));
        $insights[] = [
            'label' => '上行峰值',
            'value' => round(($net_up_values[0][0] ?? 0) / 1024, 2) . ' MB/s',
            'timestamp' => $net_up_values[0][1] ?? null,
        ];
    }

    if (!empty($net_down_values)) {
        usort($net_down_values, fn($a, $b) => ($b[0] ?? 0) <=> ($a[0] ?? 0));
        $insights[] = [
            'label' => '下行峰值',
            'value' => round(($net_down_values[0][0] ?? 0) / 1024, 2) . ' MB/s',
            'timestamp' => $net_down_values[0][1] ?? null,
        ];
    }

    if (!empty($process_values)) {
        usort($process_values, fn($a, $b) => ($b[0] ?? 0) <=> ($a[0] ?? 0));
        $insights[] = [
            'label' => '最大进程数',
            'value' => (int)($process_values[0][0] ?? 0),
            'timestamp' => $process_values[0][1] ?? null,
        ];
    }

    if (!empty($connection_values)) {
        usort($connection_values, fn($a, $b) => ($b[0] ?? 0) <=> ($a[0] ?? 0));
        $insights[] = [
            'label' => '最大连接数',
            'value' => (int)($connection_values[0][0] ?? 0),
            'timestamp' => $connection_values[0][1] ?? null,
        ];
    }

    return $insights;
}

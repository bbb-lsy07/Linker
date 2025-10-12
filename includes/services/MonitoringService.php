<?php
if (!defined('LINKER_SECURE_INCLUDE')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

class MonitoringService
{
    private const STALE_THRESHOLD_SECONDS = 180;

    private PDO $pdo;
    private string $dbType;
    private array $dbConfig;
    private ?array $cachedNodes = null;
    private ?string $siteNameCache = null;
    private ?array $serverStatsColumns = null;

    public function __construct(PDO $pdo, array $dbConfig)
    {
        $this->pdo = $pdo;
        $this->dbConfig = $dbConfig;
        $this->dbType = $dbConfig['type'] ?? 'sqlite';
    }

    public function getDashboardPayload(): array
    {
        $nodes = $this->getServersWithStatus();

        return [
            'site_name' => $this->getSiteName(),
            'summary'   => $this->buildSummary($nodes),
            'nodes'     => $nodes,
            'outages'   => $this->getOutages(),
            'insights'  => $this->buildInsights($nodes),
        ];
    }

    public function getServerHistory(string $serverId, int $limit = 500): array
    {
        $limit = $this->sanitizeLimit($limit, 500, 5000);

        $optionalColumns = [
            'cpu_usage',
            'mem_usage_percent',
            'disk_usage_percent',
            'uptime',
            'load_avg',
            'net_up_speed',
            'net_down_speed',
            'total_up',
            'total_down',
            'processes',
            'connections',
        ];

        $selectColumns = $this->filterServerStatsColumns(['timestamp'], $optionalColumns);
        if (!in_array('timestamp', $selectColumns, true)) {
            return [];
        }

        $columnList = implode(', ', $selectColumns);
        $sql = 'SELECT ' . $columnList . ' FROM server_stats WHERE server_id = :server_id ORDER BY timestamp DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':server_id', $serverId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rows = $this->hydrateMissingServerStatsColumns($rows, $optionalColumns);

        $floatFields = ['cpu_usage', 'mem_usage_percent', 'disk_usage_percent', 'load_avg'];
        $intFields = ['timestamp', 'net_up_speed', 'net_down_speed', 'total_up', 'total_down', 'processes', 'connections'];

        $history = array_map(function ($row) use ($floatFields, $intFields) {
            return linker_cast_numeric($row, $floatFields, $intFields);
        }, $rows);

        return array_reverse($history);
    }

    public function getServerDetails(string $serverId): array
    {
        $node = $this->findNodeById($serverId);
        if ($node === null) {
            throw new RuntimeException('指定的服务器不存在');
        }

        $history = $this->getServerHistory($serverId, 288);
        $node['history_snapshot'] = $this->buildHistorySnapshot($history);

        $uptime = [];
        $periods = [
            '24h' => 86400,
            '7d' => 604800,
            '30d' => 2592000,
        ];
        foreach ($periods as $label => $seconds) {
            $uptime[$label] = $this->calculateUptime($serverId, $seconds, $node['last_reported'] ?? null);
        }
        $node['uptime'] = $uptime;
        $node['recent_outages'] = $this->getOutagesForServer($serverId, 5);

        return $node;
    }

    public function getSummaryOnly(): array
    {
        return $this->buildSummary($this->getServersWithStatus());
    }

    public function getInsights(): array
    {
        return $this->buildInsights($this->getServersWithStatus());
    }

    private function getSiteName(): string
    {
        if ($this->siteNameCache !== null) {
            return $this->siteNameCache;
        }

        $default = '灵刻监控';
        try {
            $stmt = $this->pdo->prepare("SELECT value FROM settings WHERE `key` = 'site_name'");
            $stmt->execute();
            $value = $stmt->fetchColumn();
            $this->siteNameCache = $value ? (string)$value : $default;
        } catch (Throwable $e) {
            $this->siteNameCache = $default;
        }

        return $this->siteNameCache;
    }

    private function getServersWithStatus(): array
    {
        if ($this->cachedNodes !== null) {
            return $this->cachedNodes;
        }

        $servers = $this->fetchServers();
        $statusMap = $this->fetchStatusMap();
        $statsMap = $this->fetchLatestStats();

        $nodes = [];
        $now = time();

        foreach ($servers as $server) {
            $node = $server;
            $node['latitude'] = $server['latitude'] !== null ? (float)$server['latitude'] : null;
            $node['longitude'] = $server['longitude'] !== null ? (float)$server['longitude'] : null;
            $node['x'] = $node['latitude'];
            $node['y'] = $node['longitude'];

            $status = $statusMap[$server['id']] ?? [];
            $rawOnline = $status['is_online'] ?? null;
            $rawLastChecked = $status['last_checked'] ?? null;
            $node['last_checked'] = $rawLastChecked !== null ? (int)$rawLastChecked : null;
            $node['is_online'] = $rawOnline !== null ? linker_boolval($rawOnline) : null;

            $latestStats = $statsMap[$server['id']] ?? [];
            if (!empty($latestStats)) {
                $latestStats = linker_cast_numeric(
                    $latestStats,
                    ['cpu_usage', 'mem_usage_percent', 'disk_usage_percent', 'load_avg'],
                    ['timestamp', 'net_up_speed', 'net_down_speed', 'total_up', 'total_down', 'processes', 'connections']
                );
            }

            $node['stats'] = $latestStats;
            $node['last_reported'] = isset($latestStats['timestamp']) ? (int)$latestStats['timestamp'] : null;
            $node['history'] = [];

            $lastSignal = $node['last_reported'] ?? $node['last_checked'];
            $node['last_seen'] = $lastSignal;
            $heartbeatFresh = $lastSignal !== null && ($now - $lastSignal) <= self::STALE_THRESHOLD_SECONDS * 2;

            if ($node['is_online'] === null) {
                $node['is_online'] = $heartbeatFresh;
            } elseif ($node['is_online'] === false && $heartbeatFresh) {
                $node['is_online'] = true;
            }

            $node['is_online'] = (bool)$node['is_online'];
            $node['is_stale'] = $this->isStale($node['last_reported']);
            $node['status_level'] = 'ok';
            $node['status_label'] = '在线';
            $node['anomaly_msg'] = null;

            if (!$node['is_online']) {
                $node['status_level'] = 'critical';
                $node['status_label'] = '离线';
                $node['anomaly_msg'] = '服务器掉线';
                if ($lastSignal !== null) {
                    $node['outage_duration'] = max(0, $now - $lastSignal);
                }
            } elseif ($node['is_stale']) {
                $node['status_level'] = 'warning';
                $node['status_label'] = '数据陈旧';
                $node['anomaly_msg'] = '数据长时间未更新';
            } elseif (!empty($latestStats) && isset($latestStats['load_avg']) && $latestStats['load_avg'] > 2.0) {
                $node['status_level'] = 'warning';
                $node['status_label'] = '负载偏高';
            }

            if ($server['tags']) {
                $tags = array_filter(array_map('trim', explode(',', $server['tags'])));
                $node['tags_array'] = array_values(array_unique($tags));
            } else {
                $node['tags_array'] = [];
            }

            foreach (['mem_total', 'disk_total', 'cpu_cores'] as $field) {
                if (isset($node[$field]) && $node[$field] !== null && $node[$field] !== '') {
                    $node[$field] = (int)$node[$field];
                }
            }

            if (isset($node['price_usd_yearly']) && $node['price_usd_yearly'] !== null && $node['price_usd_yearly'] !== '') {
                $node['price_usd_yearly'] = (float)$node['price_usd_yearly'];
            }

            if (array_key_exists('ip', $node)) {
                unset($node['ip']);
            }

            $nodes[] = $node;
        }

        return $this->cachedNodes = $nodes;
    }

    private function findNodeById(string $serverId): ?array
    {
        foreach ($this->getServersWithStatus() as $node) {
            if ((string)$node['id'] === (string)$serverId) {
                return $node;
            }
        }

        return null;
    }

    private function fetchServers(): array
    {
        $baseColumns = ['id', 'name', 'intro', 'tags', 'latitude', 'longitude', 'country_code', 'system', 'arch', 'cpu_model', 'mem_total', 'disk_total'];
        $optionalColumns = ['price_usd_yearly', 'cpu_cores', 'ip'];
        $allColumns = array_merge($baseColumns, $optionalColumns);

        $sql = 'SELECT ' . implode(', ', $allColumns) . ' FROM servers ORDER BY id ASC';

        try {
            $stmt = $this->pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $fallbackSql = 'SELECT ' . implode(', ', $baseColumns) . ' FROM servers ORDER BY id ASC';
            try {
                $stmt = $this->pdo->query($fallbackSql);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $fallbackError) {
                error_log('MonitoringService::fetchServers fallback failed: ' . $fallbackError->getMessage());
                return [];
            }

            foreach ($rows as &$row) {
                foreach ($optionalColumns as $column) {
                    if (!array_key_exists($column, $row)) {
                        $row[$column] = null;
                    }
                }
            }

            error_log('MonitoringService::fetchServers using fallback column set: ' . $e->getMessage());

            return $rows;
        }
    }

    private function fetchStatusMap(): array
    {
        try {
            $stmt = $this->pdo->query('SELECT id, is_online, last_checked FROM server_status');
            $statusRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $map = [];
            foreach ($statusRows as $row) {
                $map[$row['id']] = [
                    'is_online' => $row['is_online'],
                    'last_checked' => isset($row['last_checked']) ? (int)$row['last_checked'] : null,
                ];
            }
            return $map;
        } catch (Throwable $e) {
            error_log('MonitoringService::fetchStatusMap failed: ' . $e->getMessage());
            return [];
        }
    }

    private function fetchLatestStats(): array
    {
        $optionalColumns = [
            'cpu_usage',
            'mem_usage_percent',
            'disk_usage_percent',
            'uptime',
            'load_avg',
            'net_up_speed',
            'net_down_speed',
            'total_up',
            'total_down',
            'processes',
            'connections',
        ];

        $selectColumns = $this->filterServerStatsColumns(['server_id', 'timestamp'], $optionalColumns);
        if (!in_array('server_id', $selectColumns, true) || !in_array('timestamp', $selectColumns, true)) {
            return [];
        }

        $columnList = implode(', ', array_map(static function (string $column): string {
            return 's.' . $column;
        }, $selectColumns));

        try {
            if ($this->dbType === 'pgsql') {
                $sql = 'SELECT DISTINCT ON (s.server_id) ' . $columnList . ' '
                     . 'FROM server_stats s ORDER BY s.server_id, s.timestamp DESC';
            } else {
                $sql = 'SELECT ' . $columnList . ' FROM server_stats s '
                     . 'JOIN (SELECT server_id, MAX(timestamp) AS max_ts FROM server_stats GROUP BY server_id) m '
                     . 'ON s.server_id = m.server_id AND s.timestamp = m.max_ts';
            }

            $stmt = $this->pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $rows = $this->hydrateMissingServerStatsColumns($rows, $optionalColumns);

            $map = [];
            foreach ($rows as $row) {
                $map[$row['server_id']] = $row;
            }

            return $map;
        } catch (Throwable $e) {
            error_log('MonitoringService::fetchLatestStats failed: ' . $e->getMessage());
            return [];
        }
    }

    private function getOutages(int $limit = 500): array
    {
        $limit = max(1, min(500, $limit));
        try {
            $stmt = $this->pdo->prepare('SELECT id, server_id, title, content, start_time, end_time FROM outages ORDER BY start_time DESC LIMIT :limit');
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $outages = [];
            foreach ($rows as $row) {
                $outage = linker_cast_numeric($row, [], ['start_time', 'end_time']);
                $outage['is_active'] = empty($outage['end_time']);
                $end = $outage['end_time'] ?? time();
                $outage['duration'] = isset($outage['start_time']) ? max(0, $end - $outage['start_time']) : null;
                $outages[] = $outage;
            }

            return $outages;
        } catch (Throwable $e) {
            error_log('MonitoringService::getOutages failed: ' . $e->getMessage());
            return [];
        }
    }

    private function getOutagesForServer(string $serverId, int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));

        try {
            $stmt = $this->pdo->prepare('SELECT id, title, content, start_time, end_time FROM outages WHERE server_id = :server_id ORDER BY start_time DESC LIMIT :limit');
            $stmt->bindValue(':server_id', $serverId);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        return array_map(function (array $row): array {
            $outage = linker_cast_numeric($row, [], ['start_time', 'end_time']);
            $end = $outage['end_time'] ?? time();
            $outage['duration'] = isset($outage['start_time']) ? max(0, $end - (int)$outage['start_time']) : null;
            $outage['is_active'] = empty($outage['end_time']);
            return $outage;
        }, $rows);
    }

    private function buildHistorySnapshot(array $history): array
    {
        $samples = count($history);

        if ($samples === 0) {
            return [
                'samples' => 0,
                'window' => ['start' => null, 'end' => null, 'duration' => null],
                'metrics' => [],
                'transfer' => ['up' => null, 'down' => null],
            ];
        }

        $first = $history[0];
        $last = $history[$samples - 1];

        $metricKeys = ['cpu_usage', 'mem_usage_percent', 'disk_usage_percent', 'load_avg', 'net_up_speed', 'net_down_speed'];
        $metrics = [];

        foreach ($metricKeys as $key) {
            $values = array_filter(array_map(static function ($item) use ($key) {
                return isset($item[$key]) && is_numeric($item[$key]) ? (float)$item[$key] : null;
            }, $history), static fn($v) => $v !== null);

            if (empty($values)) {
                continue;
            }

            $metrics[$key] = [
                'avg' => round(array_sum($values) / count($values), 2),
                'max' => max($values),
                'min' => min($values),
            ];
        }

        return [
            'samples' => $samples,
            'window' => [
                'start' => $first['timestamp'] ?? null,
                'end' => $last['timestamp'] ?? null,
                'duration' => (isset($first['timestamp'], $last['timestamp'])) ? max(0, (int)$last['timestamp'] - (int)$first['timestamp']) : null,
            ],
            'metrics' => $metrics,
            'transfer' => [
                'up' => $this->calculateDelta($first['total_up'] ?? null, $last['total_up'] ?? null),
                'down' => $this->calculateDelta($first['total_down'] ?? null, $last['total_down'] ?? null),
            ],
        ];
    }

    private function calculateUptime(string $serverId, int $periodSeconds, ?int $lastReported = null): ?array
    {
        $periodSeconds = max(1, $periodSeconds);
        $end = time();
        $start = $end - $periodSeconds;

        try {
            $stmt = $this->pdo->prepare('SELECT start_time, end_time FROM outages WHERE server_id = :server_id AND (end_time IS NULL OR end_time >= :start) AND start_time <= :end ORDER BY start_time DESC');
            $stmt->bindValue(':server_id', $serverId);
            $stmt->bindValue(':start', $start, PDO::PARAM_INT);
            $stmt->bindValue(':end', $end, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return null;
        }

        $downtime = 0;
        foreach ($rows as $row) {
            $row = linker_cast_numeric($row, [], ['start_time', 'end_time']);
            if (!isset($row['start_time'])) {
                continue;
            }
            $outageStart = (int)$row['start_time'];
            $outageEnd = isset($row['end_time']) ? (int)$row['end_time'] : $end;
            $overlapStart = max($start, $outageStart);
            $overlapEnd = min($end, $outageEnd);
            if ($overlapEnd > $overlapStart) {
                $downtime += $overlapEnd - $overlapStart;
            }
        }

        $downtime = min(max(0, $downtime), $periodSeconds);
        $uptime = $periodSeconds - $downtime;

        if (($lastReported === null || $lastReported < $start) && empty($rows)) {
            return null;
        }

        return [
            'period_seconds' => $periodSeconds,
            'since' => $start,
            'uptime_seconds' => $uptime,
            'downtime_seconds' => $downtime,
            'uptime_percent' => round(($uptime / $periodSeconds) * 100, 2),
        ];
    }

    private function calculateDelta($startValue, $endValue): ?float
    {
        if ($startValue === null || $endValue === null) {
            return null;
        }

        $start = (float)$startValue;
        $end = (float)$endValue;

        if ($end < $start) {
            return null;
        }

        return $end - $start;
    }

    private function buildInsights(array $nodes): array
    {
        $issues = [
            'offline' => [],
            'stale' => [],
            'pressure' => [],
        ];

        $topCandidates = [
            'cpu' => [],
            'mem' => [],
            'load' => [],
            'disk' => [],
            'net' => [],
        ];

        $tagCounts = [];
        $costTotal = 0.0;
        $costCount = 0;

        foreach ($nodes as $node) {
            $insightNode = $this->createInsightNode($node);
            $stats = $node['stats'] ?? [];

            if (isset($node['price_usd_yearly']) && is_numeric($node['price_usd_yearly'])) {
                $costTotal += (float)$node['price_usd_yearly'];
                $costCount++;
            }

            $tags = $node['tags_array'] ?? [];
            foreach ($tags as $tag) {
                if ($tag === null || $tag === '') {
                    continue;
                }
                $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            }

            if (empty($node['is_online'])) {
                $issues['offline'][] = array_merge($insightNode, [
                    'status_level' => 'critical',
                    'status_label' => $node['status_label'] ?? '离线',
                    'detail' => $node['anomaly_msg'] ?? null,
                    'outage_duration' => isset($node['outage_duration']) ? (int)$node['outage_duration'] : null,
                    'last_checked' => isset($node['last_checked']) ? (int)$node['last_checked'] : null,
                    'last_reported' => isset($node['last_reported']) ? (int)$node['last_reported'] : null,
                ]);
                continue;
            }

            if (!empty($node['is_stale'])) {
                $issues['stale'][] = array_merge($insightNode, [
                    'status_level' => 'warning',
                    'status_label' => $node['status_label'] ?? '数据陈旧',
                    'detail' => $node['anomaly_msg'] ?? null,
                    'last_reported' => isset($node['last_reported']) ? (int)$node['last_reported'] : null,
                ]);
            }

            $pressureTriggers = [];
            if (isset($stats['cpu_usage']) && is_numeric($stats['cpu_usage']) && (float)$stats['cpu_usage'] >= 85.0) {
                $pressureTriggers[] = ['type' => 'cpu', 'value' => (float)$stats['cpu_usage']];
            }
            if (isset($stats['mem_usage_percent']) && is_numeric($stats['mem_usage_percent']) && (float)$stats['mem_usage_percent'] >= 85.0) {
                $pressureTriggers[] = ['type' => 'mem', 'value' => (float)$stats['mem_usage_percent']];
            }
            if (isset($stats['disk_usage_percent']) && is_numeric($stats['disk_usage_percent']) && (float)$stats['disk_usage_percent'] >= 90.0) {
                $pressureTriggers[] = ['type' => 'disk', 'value' => (float)$stats['disk_usage_percent']];
            }
            if (isset($stats['load_avg']) && is_numeric($stats['load_avg']) && (float)$stats['load_avg'] >= 3.0) {
                $pressureTriggers[] = ['type' => 'load', 'value' => (float)$stats['load_avg']];
            }

            if (!empty($pressureTriggers)) {
                $issues['pressure'][] = array_merge($insightNode, [
                    'status_level' => 'warning',
                    'status_label' => '性能压力',
                    'triggers' => $pressureTriggers,
                    'last_reported' => isset($node['last_reported']) ? (int)$node['last_reported'] : null,
                ]);
            }

            if (isset($stats['cpu_usage']) && is_numeric($stats['cpu_usage'])) {
                $topCandidates['cpu'][] = ['value' => (float)$stats['cpu_usage'], 'node' => $insightNode];
            }
            if (isset($stats['mem_usage_percent']) && is_numeric($stats['mem_usage_percent'])) {
                $topCandidates['mem'][] = ['value' => (float)$stats['mem_usage_percent'], 'node' => $insightNode];
            }
            if (isset($stats['disk_usage_percent']) && is_numeric($stats['disk_usage_percent'])) {
                $topCandidates['disk'][] = ['value' => (float)$stats['disk_usage_percent'], 'node' => $insightNode];
            }
            if (isset($stats['load_avg']) && is_numeric($stats['load_avg'])) {
                $topCandidates['load'][] = ['value' => (float)$stats['load_avg'], 'node' => $insightNode];
            }

            $netCombined = 0.0;
            if (isset($stats['net_up_speed']) && is_numeric($stats['net_up_speed'])) {
                $netCombined += (float)$stats['net_up_speed'];
            }
            if (isset($stats['net_down_speed']) && is_numeric($stats['net_down_speed'])) {
                $netCombined += (float)$stats['net_down_speed'];
            }
            if ($netCombined > 0) {
                $topCandidates['net'][] = ['value' => $netCombined, 'node' => $insightNode];
            }
        }

        $issueCounts = [
            'offline' => count($issues['offline']),
            'stale' => count($issues['stale']),
            'pressure' => count($issues['pressure']),
        ];

        $issues['offline'] = $this->prepareIssueList($issues['offline'], 'offline');
        $issues['stale'] = $this->prepareIssueList($issues['stale'], 'stale');
        $issues['pressure'] = $this->prepareIssueList($issues['pressure'], 'pressure');

        if (!empty($tagCounts)) {
            arsort($tagCounts);
        }

        $tagSummary = [];
        foreach ($tagCounts as $tag => $count) {
            $tagSummary[] = ['tag' => $tag, 'count' => $count];
        }

        return [
            'generated_at' => time(),
            'issue_counts' => $issueCounts,
            'issues' => $issues,
            'top_metrics' => $this->finalizeTopMetrics($topCandidates),
            'cost' => $costCount > 0 ? [
                'total_yearly' => round($costTotal, 2),
                'monthly' => round($costTotal / 12, 2),
                'average_yearly' => round($costTotal / $costCount, 2),
                'count' => $costCount,
            ] : null,
            'tags' => [
                'unique' => count($tagCounts),
                'top' => array_slice($tagSummary, 0, 10),
            ],
        ];
    }

    private function prepareIssueList(array $items, string $type): array
    {
        if (empty($items)) {
            return [];
        }

        if ($type === 'offline') {
            usort($items, static function (array $a, array $b): int {
                return ($b['outage_duration'] ?? 0) <=> ($a['outage_duration'] ?? 0);
            });
        } elseif ($type === 'stale') {
            usort($items, static function (array $a, array $b): int {
                return ($a['last_reported'] ?? PHP_INT_MAX) <=> ($b['last_reported'] ?? PHP_INT_MAX);
            });
        } else {
            usort($items, static function (array $a, array $b): int {
                $aMax = 0.0;
                if (!empty($a['triggers'])) {
                    foreach ($a['triggers'] as $trigger) {
                        if (isset($trigger['value']) && is_numeric($trigger['value'])) {
                            $aMax = max($aMax, (float)$trigger['value']);
                        }
                    }
                }

                $bMax = 0.0;
                if (!empty($b['triggers'])) {
                    foreach ($b['triggers'] as $trigger) {
                        if (isset($trigger['value']) && is_numeric($trigger['value'])) {
                            $bMax = max($bMax, (float)$trigger['value']);
                        }
                    }
                }

                return $bMax <=> $aMax;
            });
        }

        return array_values(array_slice($items, 0, 5));
    }

    private function finalizeTopMetrics(array $candidates): array
    {
        $result = [];

        foreach ($candidates as $metric => $items) {
            if (empty($items)) {
                $result[$metric] = [];
                continue;
            }

            usort($items, static fn(array $a, array $b): int => $b['value'] <=> $a['value']);
            $items = array_slice($items, 0, 5);

            $result[$metric] = array_map(static function (array $item): array {
                return [
                    'value' => round($item['value'], 2),
                    'node' => $item['node'],
                ];
            }, $items);
        }

        return $result;
    }

    private function createInsightNode(array $node): array
    {
        return [
            'id' => isset($node['id']) ? (string)$node['id'] : '',
            'name' => $node['name'] ?? ('#' . ($node['id'] ?? '?')),
            'country_code' => $node['country_code'] ?? null,
            'tags' => array_values($node['tags_array'] ?? []),
            'status_level' => $node['status_level'] ?? null,
            'status_label' => $node['status_label'] ?? null,
            'is_online' => !empty($node['is_online']),
            'last_reported' => isset($node['last_reported']) ? (int)$node['last_reported'] : null,
        ];
    }

    private function buildSummary(array $nodes): array
    {
        $total = count($nodes);
        $online = 0;
        $stale = 0;
        $cpuSum = $memSum = $loadSum = 0.0;
        $cpuCount = $memCount = $loadCount = 0;
        $lastSync = null;

        foreach ($nodes as $node) {
            if (!empty($node['is_online'])) {
                $online++;
                if (!empty($node['is_stale'])) {
                    $stale++;
                }
            }

            if (!empty($node['stats'])) {
                if (isset($node['stats']['cpu_usage'])) {
                    $cpuSum += (float)$node['stats']['cpu_usage'];
                    $cpuCount++;
                }
                if (isset($node['stats']['mem_usage_percent'])) {
                    $memSum += (float)$node['stats']['mem_usage_percent'];
                    $memCount++;
                }
                if (isset($node['stats']['load_avg'])) {
                    $loadSum += (float)$node['stats']['load_avg'];
                    $loadCount++;
                }
            }

            $latestSignal = $node['last_seen'] ?? $node['last_reported'] ?? null;
            if ($latestSignal !== null) {
                $latestSignal = (int)$latestSignal;
                $lastSync = $lastSync ? max($lastSync, $latestSignal) : $latestSignal;
            }
        }

        $offline = max(0, $total - $online);
        $summary = [
            'total_servers' => $total,
            'online' => $online,
            'offline' => $offline,
            'stale' => $stale,
            'avg_cpu' => $cpuCount > 0 ? round($cpuSum / $cpuCount, 1) : null,
            'avg_mem' => $memCount > 0 ? round($memSum / $memCount, 1) : null,
            'avg_load' => $loadCount > 0 ? round($loadSum / $loadCount, 2) : null,
            'active_alerts' => $this->getActiveAlertCount(),
            'last_sync' => $lastSync,
            'generated_at' => time(),
        ];

        return $summary;
    }

    private function getActiveAlertCount(): int
    {
        try {
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM outages WHERE end_time IS NULL');
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function sanitizeLimit(int $limit, int $default = 500, int $max = 5000): int
    {
        if ($limit <= 0) {
            $limit = $default;
        }
        return min($limit, $max);
    }

    private function filterServerStatsColumns(array $required, array $optional): array
    {
        $available = $this->getServerStatsAvailableColumns();

        $columns = [];
        foreach ($required as $column) {
            if (in_array($column, $available, true)) {
                $columns[] = $column;
            }
        }

        foreach ($optional as $column) {
            if (in_array($column, $available, true)) {
                $columns[] = $column;
            }
        }

        return array_values(array_unique($columns));
    }

    private function hydrateMissingServerStatsColumns(array $rows, array $optionalColumns): array
    {
        if (empty($rows)) {
            return $rows;
        }

        foreach ($rows as &$row) {
            foreach ($optionalColumns as $column) {
                if (!array_key_exists($column, $row)) {
                    $row[$column] = null;
                }
            }
        }
        unset($row);

        return $rows;
    }

    private function getServerStatsAvailableColumns(): array
    {
        if ($this->serverStatsColumns !== null) {
            return $this->serverStatsColumns;
        }

        try {
            switch ($this->dbType) {
                case 'mysql':
                case 'mariadb':
                    $stmt = $this->pdo->query('SHOW COLUMNS FROM server_stats');
                    $columns = array_map(static function (array $row): string {
                        return $row['Field'];
                    }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
                    break;
                case 'pgsql':
                    $stmt = $this->pdo->prepare(
                        'SELECT column_name FROM information_schema.columns '
                        . 'WHERE table_name = :table AND table_schema = ANY (current_schemas(false))'
                    );
                    $stmt->execute([':table' => 'server_stats']);
                    $columns = array_map(static function (array $row): string {
                        return $row['column_name'];
                    }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
                    break;
                case 'sqlite':
                default:
                    $stmt = $this->pdo->query('PRAGMA table_info(server_stats)');
                    $columns = array_map(static function (array $row): string {
                        return $row['name'];
                    }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
                    break;
            }
        } catch (Throwable $e) {
            $columns = [];
        }

        if (empty($columns)) {
            $columns = ['server_id', 'timestamp'];
        }

        return $this->serverStatsColumns = $columns;
    }

    private function isStale(?int $timestamp): bool
    {
        if (!$timestamp) {
            return true;
        }
        return (time() - $timestamp) > self::STALE_THRESHOLD_SECONDS;
    }
}

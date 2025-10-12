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
        ];
    }

    public function getServerHistory(string $serverId, int $limit = 1440): array
    {
        $limit = $this->sanitizeLimit($limit);

        $sql = 'SELECT cpu_usage, mem_usage_percent, disk_usage_percent, uptime, load_avg, net_up_speed, net_down_speed, total_up, '
             . 'total_down, timestamp, processes, connections '
             . 'FROM server_stats WHERE server_id = :server_id ORDER BY timestamp DESC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':server_id', $serverId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

            $status = $statusMap[$server['id']] ?? ['is_online' => false, 'last_checked' => null];
            $node['is_online'] = linker_boolval($status['is_online'] ?? false);
            $node['last_checked'] = isset($status['last_checked']) ? (int)$status['last_checked'] : null;

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

            $node['is_stale'] = $this->isStale($node['last_reported']);
            $node['status_level'] = 'ok';
            $node['status_label'] = '在线';
            $node['anomaly_msg'] = null;

            if (!$node['is_online']) {
                $node['status_level'] = 'critical';
                $node['status_label'] = '离线';
                $node['anomaly_msg'] = '服务器掉线';
                if ($node['last_checked']) {
                    $node['outage_duration'] = $now - $node['last_checked'];
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
        $sql = 'SELECT id, name, intro, tags, price_usd_yearly, latitude, longitude, country_code, system, arch, cpu_model, mem_total, '
             . 'disk_total, cpu_cores, ip FROM servers ORDER BY id ASC';
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function fetchStatusMap(): array
    {
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
    }

    private function fetchLatestStats(): array
    {
        if ($this->dbType === 'pgsql') {
            $sql = 'SELECT DISTINCT ON (server_id) server_id, cpu_usage, mem_usage_percent, disk_usage_percent, uptime, load_avg, '
                 . 'net_up_speed, net_down_speed, total_up, total_down, processes, connections, timestamp '
                 . 'FROM server_stats ORDER BY server_id, timestamp DESC';
        } else {
            $sql = 'SELECT s.server_id, s.cpu_usage, s.mem_usage_percent, s.disk_usage_percent, s.uptime, s.load_avg, s.net_up_speed, '
                 . 's.net_down_speed, s.total_up, s.total_down, s.processes, s.connections, s.timestamp '
                 . 'FROM server_stats s '
                 . 'JOIN (SELECT server_id, MAX(timestamp) AS max_ts FROM server_stats GROUP BY server_id) m '
                 . 'ON s.server_id = m.server_id AND s.timestamp = m.max_ts';
        }

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[$row['server_id']] = $row;
        }

        return $map;
    }

    private function getOutages(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
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

            if (!empty($node['last_reported'])) {
                $lastSync = $lastSync ? max($lastSync, (int)$node['last_reported']) : (int)$node['last_reported'];
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

    private function sanitizeLimit(int $limit, int $default = 1440, int $max = 10000): int
    {
        if ($limit <= 0) {
            $limit = $default;
        }
        return min($limit, $max);
    }

    private function isStale(?int $timestamp): bool
    {
        if (!$timestamp) {
            return true;
        }
        return (time() - $timestamp) > self::STALE_THRESHOLD_SECONDS;
    }
}

    document.addEventListener('DOMContentLoaded', () => {
        let monitorData = { nodes: [], outages: [], site_name: '灵刻监控', summary: {} };
        let activeButton = document.querySelector('.controls button[data-view="map"]');
        let signalInterval = null;
        let activeTags = new Set();
        let currentPage = 1;
        const itemsPerPage = 18;
        let searchQuery = '';
        let statusFilter = 'all';


        const tooltip = document.getElementById('tooltip');
        const mapContainer = document.getElementById('world-map-container');
        const controls = document.querySelector('.controls');
        const views = document.querySelectorAll('.view');
        const modalOverlay = document.getElementById('details-modal');
        const modalCloseBtn = document.getElementById('modal-close-btn');
        const lastDataRefresh = document.getElementById('last-data-refresh');
        const searchInput = document.getElementById('server-search');
        const statusFilterButtons = document.querySelectorAll('.status-filters button');
        const summaryElements = {
            total: document.getElementById('summary-total'),
            online: document.getElementById('summary-online'),
            offline: document.getElementById('summary-offline'),
            stale: document.getElementById('summary-stale'),
            avgCpu: document.getElementById('summary-avg-cpu'),
            avgMem: document.getElementById('summary-avg-mem'),
            avgLoad: document.getElementById('summary-avg-load'),
            activeAlerts: document.getElementById('summary-active-alerts'),
            lastSync: document.getElementById('summary-last-sync')
        };

        const insightsDrawer = document.getElementById('insights-drawer');
        const insightsContainer = document.getElementById('insights-grid');
        const insightsTimestamp = document.getElementById('insights-generated-at');

        if (insightsDrawer) {
            insightsDrawer.addEventListener('toggle', () => {
                insightsDrawer.dataset.userToggled = '1';
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', (event) => {
                searchQuery = event.target.value.trim().toLowerCase();
                currentPage = 1;
                generateStandalonesView(monitorData.nodes);
            });
        }

        statusFilterButtons.forEach(button => {
            button.addEventListener('click', () => {
                statusFilter = button.dataset.status || 'all';
                statusFilterButtons.forEach(btn => btn.classList.toggle('active', btn === button));
                currentPage = 1;
                generateStandalonesView(monitorData.nodes);
            });
        });


        function displayError(message) {
            const main = document.getElementById('main-content');
            let errorOverlay = document.getElementById('error-overlay');
            if (!errorOverlay) {
                errorOverlay = document.createElement('div');
                errorOverlay.id = 'error-overlay';
                errorOverlay.className = 'error-overlay';
                main.appendChild(errorOverlay);
            }
            errorOverlay.innerHTML = `<pre><strong>无法加载数据:</strong>\n${message}</pre>`;
        }

        async function fetchData() {
            try {
                const response = await fetch('./api.php');
                if (!response.ok) throw new Error(`API 返回状态 ${response.status}: ${await response.text()}`);
                const data = await response.json();
                if (data.error) throw new Error(`API 错误: ${data.error}`);
                
                monitorData = data;
                monitorData.summary = monitorData.summary || {};
                monitorData.insights = monitorData.insights || {};
                document.title = monitorData.site_name;
                document.getElementById('site-title').textContent = monitorData.site_name;
                document.getElementById('copyright-footer').innerHTML = `Copyright 2025 ${monitorData.site_name}. Powered by <a href="https://github.com/bbb-lsy07/Linker" target="_blank" style="color: #999; text-decoration: none;">Linker</a>.`;
                renderAllViews();
            } catch (error) {
                console.error("获取监控数据失败:", error);
                displayError(error.message);
            }
        }
        
        function getFlagEmoji(countryCode) {
            if (!countryCode || countryCode.length !== 2) return '';
            const codePoints = countryCode.toUpperCase().split('').map(char => 127397 + char.charCodeAt());
            return String.fromCodePoint(...codePoints);
        }

        function formatDuration(seconds) {
            if (!seconds) return `0 秒`;
            if (seconds < 60) return `${seconds} 秒`;
            if (seconds < 3600) return `${Math.round(seconds / 60)} 分钟`;
            if (seconds < 86400) return `${(seconds / 3600).toFixed(1)} 小时`;
            return `${(seconds / 86400).toFixed(1)} 天`;
        }

        function formatDateTime(timestamp) {
            if (!timestamp) return '';
            const value = Number(timestamp);
            const ms = value < 1e12 ? value * 1000 : value;
            return new Date(ms).toLocaleString(navigator.language.startsWith('zh') ? 'zh-CN' : undefined);
        }

        function formatRelativeTime(timestamp) {
            if (!timestamp) return '无记录';
            const value = Number(timestamp);
            const ms = value < 1e12 ? value * 1000 : value;
            const diffSeconds = Math.round((Date.now() - ms) / 1000);
            if (diffSeconds <= 0) return '刚刚';
            if (diffSeconds < 60) return `${diffSeconds} 秒前`;
            if (diffSeconds < 3600) return `${Math.floor(diffSeconds / 60)} 分钟前`;
            if (diffSeconds < 86400) return `${Math.floor(diffSeconds / 3600)} 小时前`;
            if (diffSeconds < 86400 * 7) return `${Math.floor(diffSeconds / 86400)} 天前`;
            return new Date(ms).toLocaleDateString(navigator.language.startsWith('zh') ? 'zh-CN' : undefined);
        }

        function formatPercent(value, fraction = 1) {
            if (value === null || value === undefined || Number.isNaN(Number(value))) {
                return '--%';
            }
            return `${Number(value).toFixed(fraction)}%`;
        }

        function formatNumber(value, fraction = 2, fallback = '--') {
            if (value === null || value === undefined || Number.isNaN(Number(value))) {
                return fallback;
            }
            return Number(value).toFixed(fraction);
        }

        function updateLastRefreshDisplay(timestamp) {
            if (!lastDataRefresh) return;
            if (!timestamp) {
                lastDataRefresh.textContent = '--';
                lastDataRefresh.removeAttribute('title');
                return;
            }
            const relative = formatRelativeTime(timestamp);
            const absolute = formatDateTime(timestamp);
            lastDataRefresh.textContent = `${relative} · ${absolute}`;
            lastDataRefresh.title = absolute;
        }

        function updateSummaryBar(summary = {}) {
            if (!summaryElements.total) return;
            const safe = {
                total_servers: summary.total_servers ?? monitorData.nodes.length,
                online: summary.online ?? 0,
                offline: summary.offline ?? Math.max(0, monitorData.nodes.length - (summary.online ?? 0)),
                stale: summary.stale ?? 0,
                avg_cpu: summary.avg_cpu ?? null,
                avg_mem: summary.avg_mem ?? null,
                avg_load: summary.avg_load ?? null,
                active_alerts: summary.active_alerts ?? 0,
                last_sync: summary.last_sync ?? null,
                generated_at: summary.generated_at ?? null
            };
            summaryElements.total.textContent = safe.total_servers;
            summaryElements.online.textContent = safe.online;
            summaryElements.offline.textContent = safe.offline;
            summaryElements.stale.textContent = safe.stale;
            summaryElements.avgCpu.textContent = formatPercent(safe.avg_cpu);
            summaryElements.avgMem.textContent = formatPercent(safe.avg_mem);
            summaryElements.avgLoad.textContent = safe.avg_load !== null && safe.avg_load !== undefined ? Number(safe.avg_load).toFixed(2) : '--';
            summaryElements.activeAlerts.textContent = safe.active_alerts;
            summaryElements.lastSync.textContent = safe.last_sync ? formatRelativeTime(safe.last_sync) : '--';
            summaryElements.lastSync.title = safe.last_sync ? formatDateTime(safe.last_sync) : '';
            updateLastRefreshDisplay(safe.last_sync || safe.generated_at);
            monitorData.summary = summary;
        }

        function renderAllViews() {
            updateSummaryBar(monitorData.summary || {});
            initializeMap(monitorData.nodes);
            generateStandalonesView(monitorData.nodes);
            generateOutagesView(monitorData.outages, monitorData.nodes);
            renderInsights(monitorData.insights || {});
            generateTagFilters(monitorData.nodes);
        }

        controls.addEventListener('click', (e) => {
            if (e.target.tagName !== 'BUTTON') return;
            const viewName = e.target.dataset.view;
            if (activeButton) activeButton.classList.remove('active');
            e.target.classList.add('active');
            activeButton = e.target;
            switchView(viewName + '-view');
        });
        
        modalCloseBtn.addEventListener('click', () => modalOverlay.classList.remove('active'));
        modalOverlay.addEventListener('click', (e) => {
            if (e.target === modalOverlay) modalOverlay.classList.remove('active');
        });

        function switchView(viewId) {
            views.forEach(view => view.classList.remove('active'));
            document.getElementById(viewId)?.classList.add('active');
        }

        function initializeMap(nodes) {
            const svg = document.getElementById('world-map-svg');
            if (!svg) return;
            svg.querySelectorAll('.map-node').forEach(n => n.remove());
            const viewBoxAttr = svg.getAttribute('viewBox');
            if(viewBoxAttr) {
                const [width, height] = viewBoxAttr.split(' ').slice(2).map(parseFloat);
                if(width > 0 && height > 0) mapContainer.style.aspectRatio = `${width} / ${height}`;
            }
            nodes.forEach(node => {
                const cy = Number(node?.x);
                const cx = Number(node?.y);
                if (!Number.isFinite(cx) || !Number.isFinite(cy)) {
                    return;
                }
                const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                circle.setAttribute('id', `map-node-${node.id}`);
                circle.classList.add('map-node');
                if (!node.is_online) {
                    circle.classList.add('anomaly');
                } else if (node.is_stale) {
                    circle.classList.add('stale');
                }
                circle.setAttribute('cx', cx);
                circle.setAttribute('cy', cy);
                circle.setAttribute('r', 5);
                svg.appendChild(circle);
                circle.addEventListener('mouseenter', () => {
                    let content = `<strong>${getFlagEmoji(node.country_code)} ${node.name}</strong>`;
                    if (node.intro) content += `<br><span class="subtitle">${node.intro}</span>`;
                    const statusParts = [];
                    if (!node.is_online) {
                        statusParts.push(node.anomaly_msg || '服务器离线');
                    } else if (node.is_stale) {
                        statusParts.push('数据陈旧');
                    } else {
                        statusParts.push('在线');
                    }
                    const lastSignal = node.last_seen || node.last_reported;
                    if (lastSignal) {
                        statusParts.push(formatRelativeTime(lastSignal));
                    }
                    if (statusParts.length) {
                        const statusClass = !node.is_online ? 'anomaly-subtitle' : 'subtitle';
                        content += `<br><span class="${statusClass}">状态: ${statusParts.join(' · ')}</span>`;
                    }
                    tooltip.innerHTML = content;
                    tooltip.style.display = 'block';
                });
                circle.addEventListener('mouseleave', () => { tooltip.style.display = 'none'; });
                circle.addEventListener('click', () => showDetailsModal(node.id));
            });
        }
        
        function formatBytes(bytes, decimals = 2) {
            if (!bytes || bytes <= 0) return '0 Bytes';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }

        function generateStandalonesView(nodes) {
            const container = document.querySelector('#standalones-view .card-grid');
            container.innerHTML = '';

            const filteredNodes = nodes.filter(node => {
                const nodeTags = Array.isArray(node.tags_array)
                    ? node.tags_array
                    : (node.tags ? node.tags.split(',').map(t => t.trim()) : []);
                const matchesTags = activeTags.size === 0 || [...activeTags].every(tag => nodeTags.includes(tag));
                const matchesStatus = statusFilter === 'all'
                    ? true
                    : (statusFilter === 'online' && node.is_online && !node.is_stale)
                        || (statusFilter === 'offline' && !node.is_online)
                        || (statusFilter === 'stale' && node.is_online && node.is_stale);
                const matchesSearch = !searchQuery || [
                    node.name,
                    node.id,
                    node.intro,
                    node.tags,
                    node.country_code,
                    node.system,
                    node.arch
                ]
                    .filter(Boolean)
                    .some(value => value.toString().toLowerCase().includes(searchQuery));
                return matchesTags && matchesStatus && matchesSearch;
            });

            const totalPages = Math.max(1, Math.ceil(filteredNodes.length / itemsPerPage));
            if (currentPage > totalPages) {
                currentPage = totalPages;
            }
            const paginatedNodes = filteredNodes.slice((currentPage - 1) * itemsPerPage, currentPage * itemsPerPage);

            paginatedNodes.forEach(node => {
                const stats = node.stats || {};
                const isOffline = !node.is_online;
                const isStale = !!node.is_stale && node.is_online;
                const loadAvg = Number(stats.load_avg ?? 0);
                const isHighLoad = !isOffline && !isStale && Number.isFinite(loadAvg) && loadAvg > 2.0;

                let cardClass = 'server-card';
                if (isOffline) {
                    cardClass += ' offline';
                } else if (isStale) {
                    cardClass += ' stale';
                } else if (isHighLoad) {
                    cardClass += ' high-load';
                }

                const card = document.createElement('div');
                card.className = cardClass;
                card.dataset.serverId = node.id;

                const clampPercent = (value) => Math.max(0, Math.min(100, Number.isFinite(value) ? value : 0));
                const cpuRaw = Number(stats.cpu_usage ?? 0);
                const memRaw = Number(stats.mem_usage_percent ?? 0);
                const diskRaw = Number(stats.disk_usage_percent ?? 0);
                const cpuPercent = clampPercent(cpuRaw);
                const memPercent = clampPercent(memRaw);
                const diskPercent = clampPercent(diskRaw);
                const uptime = isOffline ? formatDuration(node.outage_duration || 0) : (stats.uptime || '—');
                const loadDisplay = Number.isFinite(loadAvg) ? loadAvg.toFixed(2) : '--';
                const lastSignal = node.last_seen || node.last_reported;
                const lastSeen = lastSignal ? formatRelativeTime(lastSignal) : '无记录';
                const statusIconClass = isOffline ? 'down' : (isStale ? 'stale' : '');
                const statusNoteClass = isOffline ? 'danger' : ((isStale || isHighLoad) ? 'warning' : '');
                let statusLabel = '状态: ';
                if (isOffline) {
                    statusLabel += '离线';
                } else if (isStale) {
                    statusLabel += '数据陈旧';
                } else if (isHighLoad) {
                    statusLabel += '负载偏高';
                } else {
                    statusLabel += '在线';
                }
                if (lastSignal) {
                    statusLabel += ` · ${lastSeen}`;
                }

                card.innerHTML = `
                    <div class="card-header">
                        <div class="status-icon ${statusIconClass}"></div>
                        <div class="header-title">
                            <div class="name">${getFlagEmoji(node.country_code)} ${node.name}</div>
                            <div class="status-note ${statusNoteClass}">${statusLabel}</div>
                        </div>
                    </div>
                    <div class="stat-grid">
                        <div class="label">CPU</div>
                        <div class="progress-bar"><div class="progress-bar-inner progress-cpu" style="width: ${cpuPercent.toFixed(0)}%;">${cpuPercent.toFixed(0)}%</div></div>
                        <div class="label">内存</div>
                        <div class="progress-bar"><div class="progress-bar-inner progress-mem" style="width: ${memPercent.toFixed(0)}%;">${memPercent.toFixed(0)}%</div></div>
                        <div class="label">硬盘</div>
                        <div class="progress-bar"><div class="progress-bar-inner progress-disk" style="width: ${diskPercent.toFixed(0)}%;">${diskPercent.toFixed(0)}%</div></div>
                        <div class="label">网络</div>
                        <div class="value">↑ ${formatBytes(stats.net_up_speed || 0)}/s | ↓ ${formatBytes(stats.net_down_speed || 0)}/s</div>
                        <div class="label">流量</div>
                        <div class="value">↑ ${formatBytes(stats.total_up || 0)} | ↓ ${formatBytes(stats.total_down || 0)}</div>
                        <div class="label">负载</div>
                        <div class="value">${loadDisplay}</div>
                        <div class="label">${isOffline ? '掉线时长' : '在线'}</div>
                        <div class="value">${uptime}</div>
                        <div class="label">更新</div>
                        <div class="value" title="${formatDateTime(node.last_reported)}">${lastSeen}</div>
                    </div>
                `;
                container.appendChild(card);
                card.addEventListener('click', () => showDetailsModal(node.id));
            });
            renderPaginationControls(filteredNodes.length);
        }

        function renderPaginationControls(totalItems) {
            const container = document.querySelector('.pagination');
            container.innerHTML = '';
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            if (totalPages <= 1) return;

            for (let i = 1; i <= totalPages; i++) {
                const button = document.createElement('button');
                button.textContent = i;
                if (i === currentPage) button.classList.add('active');
                button.onclick = () => { currentPage = i; generateStandalonesView(monitorData.nodes); };
                container.appendChild(button);
            }
        }

        function generateTagFilters(nodes) {
            const container = document.querySelector('.tag-filters');
            const allTags = new Set();
            nodes.forEach(node => {
                const tags = Array.isArray(node.tags_array)
                    ? node.tags_array
                    : (node.tags ? node.tags.split(',').map(tag => tag.trim()) : []);
                tags.forEach(tag => {
                    if (tag) allTags.add(tag);
                });
            });

            container.innerHTML = `<strong>筛选: </strong>`;
            const allButton = document.createElement('button');
            allButton.textContent = '全部';
            allButton.className = activeTags.size === 0 ? 'active' : '';
            allButton.onclick = () => { activeTags.clear(); currentPage = 1; renderAllViews(); };
            container.appendChild(allButton);

            allTags.forEach(tag => {
                const button = document.createElement('button');
                button.textContent = tag;
                button.className = activeTags.has(tag) ? 'active' : '';
                button.onclick = () => {
                    activeTags.has(tag) ? activeTags.delete(tag) : activeTags.add(tag);
                    currentPage = 1;
                    renderAllViews();
                };
                container.appendChild(button);
            });
        }
        
        async function showDetailsModal(serverId) {
            const node = monitorData.nodes.find(n => n.id == serverId);
            if (!node) return;
            const modalBody = document.getElementById('modal-body');
            const isOffline = !node.is_online;
            const isStale = !!node.is_stale && node.is_online;
            const tagsText = Array.isArray(node.tags_array) && node.tags_array.length ? node.tags_array.join(', ') : '未设置';
            const memText = node.mem_total ? formatBytes(node.mem_total, 2) : '未设置';
            const diskText = node.disk_total ? formatBytes(node.disk_total, 2) : '未设置';
            const cpuCores = node.cpu_cores ? `${node.cpu_cores} 核` : '未设置';
            const priceText = node.price_usd_yearly ? `≈ $${Number(node.price_usd_yearly).toFixed(2)}/年` : '未标注';
            const locationText = node.country_code ? `${getFlagEmoji(node.country_code)} ${node.country_code}` : '未设置';
            const lastSignal = node.last_seen || node.last_reported;
            const lastSignalRelative = lastSignal ? formatRelativeTime(lastSignal) : '无记录';
            const lastSignalAbsolute = lastSignal ? formatDateTime(lastSignal) : '—';
            const statusTone = isOffline ? 'danger' : (isStale ? 'warning' : 'ok');
            const statusLabel = node.status_label || (isOffline ? '离线' : (isStale ? '数据陈旧' : '在线'));
            const statusBadge = `<span class="status-badge ${statusTone}">${statusLabel}</span>`;

            modalBody.innerHTML = `
                <div class="modal-header">
                    <div class="title-stack">
                        <h2>${getFlagEmoji(node.country_code)} ${node.name}${statusBadge}</h2>
                        <p class="modal-subtitle">${node.intro || '暂无简介'}</p>
                    </div>
                    <div class="modal-meta">
                        <span class="meta-item">最近心跳：${lastSignalRelative}</span>
                        <span class="meta-item">时间：${lastSignalAbsolute}</span>
                    </div>
                </div>
                <div class="modal-info-section">
                     <div class="info-grid">
                        <div class="modal-info-item"><strong>位置</strong><p>${locationText}</p></div>
                        <div class="modal-info-item"><strong>操作系统</strong><p>${node.system || '未设置'}</p></div>
                        <div class="modal-info-item"><strong>架构</strong><p>${node.arch || '未设置'}</p></div>
                        <div class="modal-info-item"><strong>CPU 型号</strong><p>${node.cpu_model || '未设置'}</p></div>
                        <div class="modal-info-item"><strong>CPU 核心</strong><p>${cpuCores}</p></div>
                        <div class="modal-info-item"><strong>内存大小</strong><p>${memText}</p></div>
                        <div class="modal-info-item"><strong>磁盘大小</strong><p>${diskText}</p></div>
                        <div class="modal-info-item"><strong>年度成本</strong><p>${priceText}</p></div>
                        <div class="modal-info-item"><strong>标签</strong><p>${tagsText}</p></div>
                        ${isOffline ? `<div class="modal-info-item"><strong>掉线时长</strong><p>${formatDuration(node.outage_duration || 0)}</p></div>` : ''}
                    </div>
                </div>
                <div class="modal-metrics" id="modal-quick-stats"></div>
                <div class="modal-history-summary" id="modal-history-summary"></div>
                <div class="modal-outages" id="modal-outages"></div>
                <div class="chart-grid">
                    <div class="chart-container"><h3>CPU 使用率 (%)</h3><div id="cpu-chart" class="chart-svg">图表加载中...</div></div>
                    <div class="chart-container"><h3>内存使用率 (%)</h3><div id="mem-chart" class="chart-svg">图表加载中...</div></div>
                    <div class="chart-container"><h3>系统负载</h3><div id="load-chart" class="chart-svg">图表加载中...</div></div>
                    <div class="chart-container"><h3>网络速度 (KB/s)</h3><div id="net-chart" class="chart-svg">图表加载中...</div></div>
                    <div class="chart-container"><h3>进程数</h3><div id="proc-chart" class="chart-svg">图表加载中...</div></div>
                    <div class="chart-container"><h3>连接数</h3><div id="conn-chart" class="chart-svg">图表加载中...</div></div>
                </div>`;
            modalOverlay.classList.add('active');

            try {
                const historyLimit = 500;
                const [detailResponse, historyResponse] = await Promise.all([
                    fetch(`./api.php?action=get_node_detail&id=${serverId}`),
                    fetch(`./api.php?action=get_history&id=${serverId}&limit=${historyLimit}`)
                ]);

                if (!detailResponse.ok) {
                    throw new Error(`加载详情失败: ${detailResponse.status}`);
                }
                if (!historyResponse.ok) {
                    throw new Error(`加载历史失败: ${historyResponse.status}`);
                }

                const detailData = await detailResponse.json();
                const historyData = await historyResponse.json();
                if (detailData.error) throw new Error(detailData.error);
                if (historyData.error) throw new Error(historyData.error);

                renderUptimeMetrics(detailData.node?.uptime);
                renderHistorySummary(detailData.node?.history_snapshot);
                renderRecentOutages(detailData.node?.recent_outages);

                const history = historyData.history || [];
                createSvgChart('cpu-chart', history.map(h => ({ x: h.timestamp, y: h.cpu_usage })), 100);
                createSvgChart('mem-chart', history.map(h => ({ x: h.timestamp, y: h.mem_usage_percent })), 100);
                createSvgChart('load-chart', history.map(h => ({ x: h.timestamp, y: h.load_avg })));
                createSvgChart('net-chart', [
                    { data: history.map(h => ({ x: h.timestamp, y: (h.net_up_speed || 0) / 1024 })), color: '#2ecc40' },
                    { data: history.map(h => ({ x: h.timestamp, y: (h.net_down_speed || 0) / 1024 })), color: '#0074d9' }
                ]);
                createSvgChart('proc-chart', history.map(h => ({ x: h.timestamp, y: h.processes })));
                createSvgChart('conn-chart', history.map(h => ({ x: h.timestamp, y: h.connections })));
            } catch (err) {
                console.error('Failed to load history:', err);
                const chartGrid = document.querySelector('.chart-grid');
                if (chartGrid) {
                    chartGrid.innerHTML = `<p style="color:red; text-align:center;">无法加载数据</p>`;
                }
                renderUptimeMetrics();
                renderHistorySummary();
                renderRecentOutages();
            }
        }

        function createSvgChart(elementId, datasets, forceMaxY) {
            const container = document.getElementById(elementId);
            if (!container) return;
            const NS = 'http://www.w3.org/2000/svg';
            const svg = document.createElementNS(NS, 'svg');
            svg.setAttribute('viewBox', '0 0 300 150');
            svg.setAttribute('preserveAspectRatio', 'none');
            container.innerHTML = '';
            container.appendChild(svg);
            
            const padding = { top: 10, right: 10, bottom: 20, left: 35 };

            // FIX: Correctly handle single vs multiple datasets
            if (Array.isArray(datasets) && datasets.length > 0 && typeof datasets[0].data === 'undefined') {
                datasets = [{ data: datasets, color: '#ffc107' }];
            }

            if (!datasets[0] || !datasets[0].data || datasets[0].data.length < 2) {
                container.innerHTML = `<div style="text-align:center; padding-top: 60px; color: #999;">无可用数据</div>`;
                return;
            }

            let allYValues = datasets.flatMap(d => d.data.map(p => p.y));
            const maxY = forceMaxY || Math.max(1, ...allYValues) * 1.2;
            const minX = datasets[0].data[0].x;
            const maxX = datasets[0].data[datasets[0].data.length - 1].x;

            for (let i = 0; i <= 4; i++) {
                const y = padding.top + i * (150 - padding.top - padding.bottom) / 4;
                const line = document.createElementNS(NS, 'line');
                line.setAttribute('x1', padding.left); line.setAttribute('y1', y);
                line.setAttribute('x2', 300 - padding.right); line.setAttribute('y2', y);
                line.setAttribute('class', 'grid-line');
                svg.appendChild(line);

                const text = document.createElementNS(NS, 'text');
                text.setAttribute('x', padding.left - 5); text.setAttribute('y', y + 3);
                text.setAttribute('text-anchor', 'end'); text.setAttribute('class', 'axis-text');
                text.textContent = (maxY * (1 - i / 4)).toFixed(forceMaxY === 100 ? 0 : 1);
                svg.appendChild(text);
            }

            datasets.forEach(dataset => {
                if(!dataset.data || dataset.data.length < 2) return;
                const path = document.createElementNS(NS, 'path');
                let d = 'M';
                dataset.data.forEach((point, i) => {
                    const x = padding.left + (point.x - minX) / (maxX - minX || 1) * (300 - padding.left - padding.right);
                    const y = (150 - padding.bottom) - (point.y / maxY) * (150 - padding.top - padding.bottom);
                    d += `${x.toFixed(2)},${y.toFixed(2)} `;
                });
                path.setAttribute('d', d);
                path.setAttribute('class', 'line');
                path.style.stroke = dataset.color;
                svg.appendChild(path);
            });
        }

        function renderUptimeMetrics(uptimeData = {}) {
            const container = document.getElementById('modal-quick-stats');
            if (!container) return;

            const periods = [
                { key: '24h', label: '24小时可用率' },
                { key: '7d', label: '7天可用率' },
                { key: '30d', label: '30天可用率' }
            ];

            container.innerHTML = '';

            periods.forEach(({ key, label }) => {
                const metric = uptimeData && uptimeData[key] ? uptimeData[key] : null;
                const card = document.createElement('div');
                card.className = 'metric-card';

                if (metric && typeof metric.uptime_percent === 'number') {
                    if (metric.uptime_percent >= 99.9) {
                        card.classList.add('ok');
                    } else if (metric.uptime_percent >= 95) {
                        card.classList.add('warning');
                    } else {
                        card.classList.add('danger');
                    }
                }

                const valueText = metric && typeof metric.uptime_percent === 'number'
                    ? `${formatNumber(metric.uptime_percent, 2)}%`
                    : '--';

                const uptimeSeconds = metric ? Math.round(metric.uptime_seconds || 0) : 0;
                const downtimeSeconds = metric ? Math.round(metric.downtime_seconds || 0) : 0;
                const subtitle = metric
                    ? `在线 ${formatDuration(uptimeSeconds)} · 故障 ${formatDuration(downtimeSeconds)}`
                    : '无可用数据';

                card.innerHTML = `
                    <div class="metric-label">${label}</div>
                    <div class="metric-value">${valueText}</div>
                    <div class="metric-subvalue">${subtitle}</div>
                `;

                container.appendChild(card);
            });
        }

        function renderHistorySummary(snapshot) {
            const container = document.getElementById('modal-history-summary');
            if (!container) return;

            container.innerHTML = '';

            if (!snapshot || !snapshot.samples) {
                const empty = document.createElement('div');
                empty.className = 'modal-section-empty';
                empty.textContent = '暂无近期性能数据。';
                container.appendChild(empty);
                return;
            }

            const header = document.createElement('h3');
            const windowDuration = snapshot.window?.duration;
            const hasDuration = windowDuration !== null && windowDuration !== undefined;
            header.textContent = hasDuration ? `近 ${formatDuration(windowDuration)} 指标摘要` : '近期指标摘要';
            container.appendChild(header);

            const metricsGrid = document.createElement('div');
            metricsGrid.className = 'history-grid';

            const configs = [
                { key: 'cpu_usage', label: 'CPU', format: (val) => formatPercent(val), peak: (val) => `峰值 ${formatPercent(val)}` },
                { key: 'mem_usage_percent', label: '内存', format: (val) => formatPercent(val), peak: (val) => `峰值 ${formatPercent(val)}` },
                { key: 'disk_usage_percent', label: '硬盘', format: (val) => formatPercent(val), peak: (val) => `峰值 ${formatPercent(val)}` },
                { key: 'load_avg', label: '负载', format: (val) => formatNumber(val, 2), peak: (val) => `峰值 ${formatNumber(val, 2)}` },
                { key: 'net_up_speed', label: '上行速度', format: (val) => `${formatBytes(val || 0)}/s`, peak: (val) => `峰值 ${formatBytes(val || 0)}/s` },
                { key: 'net_down_speed', label: '下行速度', format: (val) => `${formatBytes(val || 0)}/s`, peak: (val) => `峰值 ${formatBytes(val || 0)}/s` }
            ];

            configs.forEach(config => {
                const stats = snapshot.metrics?.[config.key];
                if (!stats) return;

                const item = document.createElement('div');
                item.className = 'history-metric';
                item.innerHTML = `
                    <div class="history-label">${config.label}</div>
                    <div class="history-value">${config.format(stats.avg)}</div>
                    <div class="history-subvalue">${config.peak(stats.max)}</div>
                `;
                metricsGrid.appendChild(item);
            });

            if (metricsGrid.children.length > 0) {
                container.appendChild(metricsGrid);
            }

            const rangeParts = [];
            const startText = snapshot.window?.start ? formatDateTime(snapshot.window.start) : '';
            const endText = snapshot.window?.end ? formatDateTime(snapshot.window.end) : '';
            if (startText) {
                rangeParts.push(startText);
            }
            if (endText) {
                rangeParts.push(endText);
            }

            const footnote = document.createElement('div');
            footnote.className = 'history-summary-footnote';
            footnote.textContent = `覆盖 ${snapshot.samples} 条记录${rangeParts.length === 2 ? ` · ${rangeParts[0]} → ${rangeParts[1]}` : ''}`;
            container.appendChild(footnote);

            if (snapshot.transfer && (snapshot.transfer.up !== null || snapshot.transfer.down !== null)) {
                const transferNote = document.createElement('div');
                transferNote.className = 'history-summary-footnote';
                const upText = snapshot.transfer.up !== null ? formatBytes(snapshot.transfer.up) : '--';
                const downText = snapshot.transfer.down !== null ? formatBytes(snapshot.transfer.down) : '--';
                transferNote.textContent = `期间累计流量 ↑ ${upText} / ↓ ${downText}`;
                container.appendChild(transferNote);
            }
        }

        function renderRecentOutages(outages = []) {
            const container = document.getElementById('modal-outages');
            if (!container) return;

            container.innerHTML = '<h3>近期告警</h3>';

            if (!outages || outages.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'modal-section-empty';
                empty.textContent = '最近没有掉线记录。';
                container.appendChild(empty);
                return;
            }

            const list = document.createElement('ul');
            list.className = 'outage-list';

            outages.forEach(outage => {
                const item = document.createElement('li');
                item.className = 'outage-item';
                if (!outage.end_time) {
                    item.classList.add('active');
                }

                const title = document.createElement('div');
                title.className = 'outage-title';
                title.textContent = outage.title || '未命名告警';

                const time = document.createElement('div');
                time.className = 'outage-time';
                const timeParts = [];
                if (outage.start_time) {
                    timeParts.push(`发生 ${formatDateTime(outage.start_time)}`);
                }
                if (outage.end_time) {
                    timeParts.push(`恢复 ${formatDateTime(outage.end_time)}`);
                }
                if (outage.duration) {
                    timeParts.push(`持续 ${formatDuration(outage.duration)}`);
                }
                time.textContent = timeParts.join(' · ') || '时间未知';

                const content = document.createElement('div');
                content.className = 'outage-content';
                content.textContent = outage.content || '无描述';

                item.appendChild(title);
                item.appendChild(time);
                item.appendChild(content);

                list.appendChild(item);
            });

            container.appendChild(list);
        }

        function generateOutagesView(outages, nodes) {
            const container = document.querySelector('#outages-view .timeline');
            container.innerHTML = '';
            if (!outages || outages.length === 0) {
                container.innerHTML = `<p>没有掉线记录。</p>`;
                return;
            }
            outages.forEach(outage => {
                const node = nodes.find(n => n.id == outage.server_id);
                const nodeName = node ? `${getFlagEmoji(node.country_code)} ${node.name}` : outage.server_id;
                const startTime = new Date(outage.start_time * 1000).toLocaleString(navigator.language.startsWith('zh') ? 'zh-CN' : undefined);
                let content = outage.content;
                if (outage.end_time) {
                    content += `<br>已恢复，持续时间约 ${formatDuration(outage.end_time - outage.start_time)}.`;
                } else if (outage.duration) {
                    content += `<br>已持续 ${formatDuration(outage.duration)}.`;
                }
                const itemHTML = `<div class="timeline-item ${!outage.end_time ? 'critical' : ''}"><div class="time">${startTime}</div><div class="title">${outage.title} - ${nodeName}</div><div class="content">${content}</div></div>`;
                container.innerHTML += itemHTML;
            });
        }

        function renderInsights(insights = {}) {
            if (!insightsContainer) return;

            const renderEmpty = (message = '暂无洞察数据。') => {
                insightsContainer.innerHTML = `<div class="insight-empty">${message}</div>`;
                if (insightsTimestamp) {
                    insightsTimestamp.textContent = '暂无数据';
                    insightsTimestamp.removeAttribute('title');
                }
                if (insightsDrawer) {
                    delete insightsDrawer.dataset.userToggled;
                    insightsDrawer.open = false;
                }
            };

            if (!insights || Object.keys(insights).length === 0) {
                renderEmpty();
                return;
            }

            if (insightsTimestamp) {
                if (insights.generated_at) {
                    insightsTimestamp.textContent = formatRelativeTime(insights.generated_at);
                    insightsTimestamp.title = formatDateTime(insights.generated_at);
                } else {
                    insightsTimestamp.textContent = '—';
                    insightsTimestamp.removeAttribute('title');
                }
            }

            insightsContainer.innerHTML = '';

            const issueCounts = insights.issue_counts || {};
            const issues = insights.issues || {};
            if (insightsDrawer && insightsDrawer.dataset.userToggled !== '1') {
                const shouldOpen = (issueCounts.offline || 0) > 0
                    || (issueCounts.stale || 0) > 0
                    || (issueCounts.pressure || 0) > 0;
                insightsDrawer.open = shouldOpen;
            }
            const categories = [
                { key: 'offline', label: '离线', className: 'danger' },
                { key: 'stale', label: '陈旧', className: 'warning' },
                { key: 'pressure', label: '性能预警', className: 'warning' }
            ];

            const describeTriggers = (triggers) => {
                if (!Array.isArray(triggers) || triggers.length === 0) return '';
                return triggers.map(trigger => {
                    const type = trigger?.type || '指标';
                    const value = Number(trigger?.value ?? 0);
                    switch (type) {
                        case 'cpu':
                            return `CPU ${formatNumber(value, 1)}%`;
                        case 'mem':
                            return `内存 ${formatNumber(value, 1)}%`;
                        case 'disk':
                            return `磁盘 ${formatNumber(value, 1)}%`;
                        case 'load':
                            return `负载 ${formatNumber(value, 2)}`;
                        default:
                            return `${type} ${formatNumber(value, 1)}`;
                    }
                }).join(' / ');
            };

            const buildIssueMeta = (item) => {
                const parts = [];
                if (item.type === 'offline') {
                    const duration = Number(item.outage_duration ?? 0);
                    if (Number.isFinite(duration) && duration > 0) {
                        parts.push(`离线 ${formatDuration(duration)}`);
                    }
                    if (item.last_checked) {
                        parts.push(`检测 ${formatRelativeTime(item.last_checked)}`);
                    } else if (item.last_reported) {
                        parts.push(`上报 ${formatRelativeTime(item.last_reported)}`);
                    }
                } else if (item.type === 'stale') {
                    if (item.last_reported) {
                        parts.push(`${formatRelativeTime(item.last_reported)} 更新`);
                    }
                } else if (item.type === 'pressure') {
                    const triggerText = describeTriggers(item.triggers);
                    if (triggerText) {
                        parts.push(triggerText);
                    }
                    if (item.last_reported) {
                        parts.push(`采样 ${formatRelativeTime(item.last_reported)}`);
                    }
                }

                if (item.detail) {
                    parts.push(item.detail);
                }

                return parts.join(' · ') || '暂无额外信息';
            };

            const focusCard = document.createElement('div');
            focusCard.className = 'insight-card';
            const focusTitle = document.createElement('div');
            focusTitle.className = 'insight-card-title';
            focusTitle.textContent = '重点关注';
            focusCard.appendChild(focusTitle);

            const countsWrap = document.createElement('div');
            countsWrap.className = 'insight-counts';
            categories.forEach(({ key, label, className }) => {
                const badge = document.createElement('span');
                badge.className = `insight-count ${className}`;
                badge.textContent = `${label} ${issueCounts[key] || 0}`;
                countsWrap.appendChild(badge);
            });
            focusCard.appendChild(countsWrap);

            const highlightList = document.createElement('div');
            highlightList.className = 'insight-list';
            const highlightItems = [];
            categories.forEach(({ key }) => {
                const list = Array.isArray(issues[key]) ? issues[key] : [];
                list.forEach(item => {
                    highlightItems.push(Object.assign({ type: key }, item));
                });
            });

            if (highlightItems.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'insight-empty';
                empty.textContent = '所有节点运行良好。';
                highlightList.appendChild(empty);
            } else {
                highlightItems.slice(0, 6).forEach(item => {
                    const row = document.createElement('div');
                    row.className = 'insight-item';
                    if (item.type === 'offline') {
                        row.classList.add('danger');
                    } else if (item.type === 'pressure') {
                        row.classList.add('warning');
                    } else {
                        row.classList.add('warning');
                    }

                    const nodeLabel = document.createElement('span');
                    nodeLabel.className = 'insight-node';
                    const flag = getFlagEmoji(item.country_code);
                    const name = item.name || item.id || '未知';
                    nodeLabel.textContent = flag ? `${flag} ${name}` : name;

                    const meta = document.createElement('span');
                    meta.className = 'insight-meta';
                    meta.textContent = buildIssueMeta(item);

                    row.appendChild(nodeLabel);
                    row.appendChild(meta);
                    highlightList.appendChild(row);
                });
            }

            focusCard.appendChild(highlightList);
            insightsContainer.appendChild(focusCard);

            const metricsCard = document.createElement('div');
            metricsCard.className = 'insight-card';
            const metricsTitle = document.createElement('div');
            metricsTitle.className = 'insight-card-title';
            metricsTitle.textContent = '性能排行';
            metricsCard.appendChild(metricsTitle);

            const metrics = insights.top_metrics || {};
            const metricConfigs = [
                { key: 'cpu', label: 'CPU 使用率', format: (value) => `${formatNumber(value, 1)}%` },
                { key: 'mem', label: '内存使用率', format: (value) => `${formatNumber(value, 1)}%` },
                { key: 'disk', label: '磁盘使用率', format: (value) => `${formatNumber(value, 1)}%` },
                { key: 'load', label: '系统负载', format: (value) => formatNumber(value, 2) },
                { key: 'net', label: '网络总速率', format: (value) => `${formatBytes(value || 0)}/s` }
            ];

            const metricSections = document.createElement('div');
            metricSections.className = 'insight-metric-sections';
            let hasMetric = false;

            metricConfigs.forEach(config => {
                const entries = Array.isArray(metrics[config.key]) ? metrics[config.key] : [];
                if (!entries.length) return;
                hasMetric = true;

                const section = document.createElement('div');
                section.className = 'insight-metric';

                const sectionTitle = document.createElement('div');
                sectionTitle.className = 'insight-metric-title';
                sectionTitle.textContent = config.label;
                section.appendChild(sectionTitle);

                const rows = document.createElement('div');
                rows.className = 'insight-metric-rows';

                entries.slice(0, 3).forEach((entry, index) => {
                    const row = document.createElement('div');
                    row.className = 'insight-metric-row';

                    const rank = document.createElement('span');
                    rank.className = 'insight-rank';
                    rank.textContent = `${index + 1}.`;

                    const nodeLabel = document.createElement('span');
                    nodeLabel.className = 'insight-node';
                    const node = entry.node || {};
                    const flag = getFlagEmoji(node.country_code);
                    const name = node.name || node.id || '未知';
                    nodeLabel.textContent = flag ? `${flag} ${name}` : name;

                    const value = document.createElement('span');
                    value.className = 'insight-value';
                    value.textContent = config.format(entry.value);

                    row.appendChild(rank);
                    row.appendChild(nodeLabel);
                    row.appendChild(value);
                    rows.appendChild(row);
                });

                section.appendChild(rows);
                metricSections.appendChild(section);
            });

            if (hasMetric) {
                metricsCard.appendChild(metricSections);
            } else {
                const empty = document.createElement('div');
                empty.className = 'insight-empty';
                empty.textContent = '暂无性能数据。';
                metricsCard.appendChild(empty);
            }

            insightsContainer.appendChild(metricsCard);

            const metaCard = document.createElement('div');
            metaCard.className = 'insight-card';
            const metaTitle = document.createElement('div');
            metaTitle.className = 'insight-card-title';
            metaTitle.textContent = '成本 & 标签';
            metaCard.appendChild(metaTitle);

            const metaBlocks = document.createElement('div');
            metaBlocks.className = 'insight-meta-blocks';

            const costBlock = document.createElement('div');
            costBlock.className = 'insight-meta-block';
            costBlock.innerHTML = '<div class="insight-meta-title">成本估算</div>';
            const costInfo = insights.cost;
            if (costInfo && Number.isFinite(Number(costInfo.total_yearly)) && (costInfo.count || 0) > 0) {
                const list = document.createElement('ul');
                list.className = 'insight-meta-list';
                list.innerHTML = `
                    <li>年化总计 $${formatNumber(costInfo.total_yearly, 2)}</li>
                    <li>月度约 $${formatNumber(costInfo.monthly, 2)}</li>
                    <li>平均每台 $${formatNumber(costInfo.average_yearly, 2)} / 年（${costInfo.count} 台）</li>
                `;
                costBlock.appendChild(list);
            } else {
                const empty = document.createElement('div');
                empty.className = 'insight-empty';
                empty.textContent = '暂无成本数据。';
                costBlock.appendChild(empty);
            }
            metaBlocks.appendChild(costBlock);

            const tagBlock = document.createElement('div');
            tagBlock.className = 'insight-meta-block';
            tagBlock.innerHTML = '<div class="insight-meta-title">高频标签</div>';
            const tags = insights.tags && Array.isArray(insights.tags.top) ? insights.tags.top : [];
            if (tags.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'insight-empty';
                empty.textContent = '暂无标签统计。';
                tagBlock.appendChild(empty);
            } else {
                const tagList = document.createElement('div');
                tagList.className = 'insight-tags';
                tags.slice(0, 10).forEach(tag => {
                    const badge = document.createElement('span');
                    badge.className = 'insight-tag';
                    badge.textContent = `${tag.tag} ×${tag.count}`;
                    tagList.appendChild(badge);
                });
                tagBlock.appendChild(tagList);
                if (insights.tags && insights.tags.unique) {
                    const footnote = document.createElement('div');
                    footnote.className = 'insight-footnote';
                    footnote.textContent = `共 ${insights.tags.unique} 个标签`;
                    tagBlock.appendChild(footnote);
                }
            }
            metaBlocks.appendChild(tagBlock);

            metaCard.appendChild(metaBlocks);
            insightsContainer.appendChild(metaCard);
        }

        function fireSignal(nodes) {
            if (!nodes) return;
            const availableNodes = nodes.filter(n => n.is_online && !n.is_stale && Number.isFinite(Number(n.x)) && Number.isFinite(Number(n.y)));
            if (availableNodes.length < 2) return;
            let startNode = availableNodes[Math.floor(Math.random() * availableNodes.length)];
            let endNode = availableNodes[Math.floor(Math.random() * availableNodes.length)];
            if(startNode.id === endNode.id) return;
            
            const signal = document.createElement('div');
            signal.className = 'signal';
            mapContainer.appendChild(signal);

            const containerRect = mapContainer.getBoundingClientRect();
            const svg = document.getElementById('world-map-svg');
            const viewBox = svg.viewBox.baseVal;
            const scaleX = containerRect.width / viewBox.width;
            const scaleY = containerRect.height / viewBox.height;
            
            const startX = (Number(startNode.y) - viewBox.x) * scaleX;
            const startY = (Number(startNode.x) - viewBox.y) * scaleY;
            const endX = (Number(endNode.y) - viewBox.x) * scaleX;
            const endY = (Number(endNode.x) - viewBox.y) * scaleY;

            const dx = endX - startX; const dy = endY - startY;
            const angle = Math.atan2(dy, dx) * 180 / Math.PI;
            
            signal.style.transform = `rotate(${angle}deg)`;
            signal.animate([
                { left: `${startX}px`, top: `${startY}px`, width: '5px', opacity: 0.8 },
                { width: `${Math.hypot(dx, dy) * 0.3}px`, opacity: 0.8, offset: 0.5 },
                { left: `${endX}px`, top: `${endY}px`, width: '5px', opacity: 0 }
            ], { duration: 1500, easing: 'ease-in-out' }).onfinish = () => signal.remove();
        }
        
        // --- Initial Load and Auto-Refresh ---
        let fetchDataInterval;

        const startIntervals = () => {
            // Stop existing intervals before starting new ones to prevent duplicates
            if (fetchDataInterval) clearInterval(fetchDataInterval);
            if (signalInterval) clearInterval(signalInterval);

            // Fetch data immediately when starting or becoming visible
            fetchData(); 
            fetchDataInterval = setInterval(fetchData, 10000);

            signalInterval = setInterval(() => {
                if (document.getElementById('map-view').classList.contains('active')) {
                    fireSignal(monitorData.nodes);
                }
            }, 800);
        };

        const stopIntervals = () => {
            clearInterval(fetchDataInterval);
            clearInterval(signalInterval);
        };

        // Listen for page visibility changes
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopIntervals(); // Stop fetching when tab is not visible
            } else {
                startIntervals(); // Start fetching when tab becomes visible
            }
        });
        
        startIntervals(); // Initial start of intervals

        mapContainer.addEventListener('mousemove', (e) => {
            const rect = mapContainer.getBoundingClientRect();
            tooltip.style.left = `${e.clientX - rect.left}px`;
            tooltip.style.top = `${e.clientY - rect.top}px`;
        });
    });

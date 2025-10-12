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
        const summaryContainer = document.querySelector('.summary-grid');
        const alertPanel = document.querySelector('.alert-panel');
        const searchInput = document.getElementById('server-search');
        const statusFilterContainer = document.querySelector('.status-filters');
        const locale = (navigator.language && navigator.language.length) ? navigator.language : 'zh-CN';
        const localeToUse = locale.startsWith('zh') ? 'zh-CN' : locale;
        const relativeFormatter = new Intl.RelativeTimeFormat(localeToUse, { numeric: 'auto' });
        const dateTimeFormatter = new Intl.DateTimeFormat(localeToUse, { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });


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

        function formatCurrency(value) {
            if (value === null || value === undefined || value === '') return '—';
            const numberValue = Number(value);
            if (Number.isNaN(numberValue)) return '—';
            return `$${numberValue.toFixed(2)}`;
        }

        function formatRelativeTime(timestamp) {
            if (!timestamp) return '未知';
            const tsMs = timestamp > 1e12 ? timestamp : timestamp * 1000;
            const diffSeconds = Math.round((tsMs - Date.now()) / 1000);
            const absSeconds = Math.abs(diffSeconds);
            const ranges = [
                { limit: 60, divisor: 1, unit: 'second' },
                { limit: 3600, divisor: 60, unit: 'minute' },
                { limit: 86400, divisor: 3600, unit: 'hour' },
                { limit: 604800, divisor: 86400, unit: 'day' },
                { limit: 2629800, divisor: 604800, unit: 'week' }
            ];
            for (const range of ranges) {
                if (absSeconds < range.limit) {
                    return relativeFormatter.format(Math.round(diffSeconds / range.divisor), range.unit);
                }
            }
            return relativeFormatter.format(Math.round(diffSeconds / 2629800), 'month');
        }

        function formatAbsoluteTime(timestamp) {
            if (!timestamp) return '';
            const tsMs = timestamp > 1e12 ? timestamp : timestamp * 1000;
            return dateTimeFormatter.format(new Date(tsMs));
        }

        function shouldFlagHighLoad(node) {
            if (!node || !node.stats || !node.is_online) return false;
            const cpu = Number(node.stats.cpu_usage || 0);
            const load = Number(node.stats.load_avg || 0);
            return cpu >= 85 || load >= 2.5;
        }

        function renderSummary(summary = {}, nodes = []) {
            if (!summaryContainer) return;
            const totalNodes = summary.total_nodes ?? nodes.length;
            const onlineNodes = summary.online_nodes ?? nodes.filter(node => node.is_online).length;
            const offlineNodes = summary.offline_nodes ?? Math.max(totalNodes - onlineNodes, 0);
            const highLoadNodes = summary.high_load_nodes ?? nodes.filter(node => shouldFlagHighLoad(node)).length;
            const regionList = (summary.regions || []).slice(0, 3).map(region => `${getFlagEmoji(region.code)}${region.code}(${region.count})`).join('、');
            const topTags = (summary.top_tags || []).slice(0, 3).map(tag => `${tag.tag}(${tag.count})`).join('、');
            const avgCpu = summary.avg_cpu != null ? Number(summary.avg_cpu).toFixed(1) + '%' : '—';
            const avgMem = summary.avg_mem != null ? Number(summary.avg_mem).toFixed(1) + '%' : '—';
            const avgLoad = summary.avg_load != null ? Number(summary.avg_load).toFixed(2) : '—';
            const lastUpdatedRelative = summary.last_updated ? formatRelativeTime(summary.last_updated) : '暂无数据';
            const lastUpdatedAbsolute = summary.last_updated ? formatAbsoluteTime(summary.last_updated) : '';

            const cards = [
                { label: '节点总数', value: totalNodes, subtitle: regionList ? `覆盖 ${regionList}` : '' },
                { label: '在线节点', value: onlineNodes, subtitle: totalNodes ? `占比 ${(onlineNodes / totalNodes * 100).toFixed(0)}%` : '' },
                { label: '离线节点', value: offlineNodes, subtitle: offlineNodes ? '请立即关注' : '全部正常', type: offlineNodes ? 'critical' : '' },
                { label: '高负载节点', value: highLoadNodes, subtitle: highLoadNodes ? '建议扩容或排查' : '运行平稳', type: highLoadNodes ? 'warning' : '' },
                { label: '平均 CPU', value: avgCpu, subtitle: `平均内存 ${avgMem} • 平均负载 ${avgLoad}` },
                { label: '预计成本', value: formatCurrency(summary.total_monthly_cost), subtitle: topTags ? `热门标签 ${topTags}` : '' },
                { label: '最近更新时间', value: lastUpdatedRelative, subtitle: lastUpdatedAbsolute }
            ];

            summaryContainer.innerHTML = cards.map(card => {
                const subtitle = card.subtitle ? `<div class="summary-subtitle">${card.subtitle}</div>` : '';
                return `<div class="summary-card ${card.type || ''}"><div class="summary-label">${card.label}</div><div class="summary-value">${card.value}</div>${subtitle}</div>`;
            }).join('');
        }

        function renderAlerts(alerts = []) {
            if (!alertPanel) return;
            if (!alerts.length) {
                alertPanel.innerHTML = '<div class="empty">所有节点运行良好。</div>';
                return;
            }

            const topAlerts = alerts.slice(0, 4);
            alertPanel.innerHTML = topAlerts.map(alert => {
                const flag = getFlagEmoji(alert.country_code);
                const title = `${flag} ${alert.name || alert.id}`;
                const metaSegments = [];
                if (alert.severity === 'critical' && alert.duration) {
                    metaSegments.push(`离线 ${formatDuration(alert.duration)}`);
                }
                if (alert.metric) {
                    const metricParts = [];
                    if (alert.metric.cpu != null) metricParts.push(`CPU ${Math.round(alert.metric.cpu)}%`);
                    if (alert.metric.load != null) metricParts.push(`负载 ${Number(alert.metric.load).toFixed(2)}`);
                    if (metricParts.length) metaSegments.push(metricParts.join(' / '));
                }
                if (alert.last_checked) {
                    metaSegments.push(formatRelativeTime(alert.last_checked));
                }
                const metaHtml = metaSegments.length ? metaSegments.join('<br>') : '&nbsp;';
                return `<div class="alert-item ${alert.severity}" data-server-id="${alert.id}"><div class="alert-text"><div class="alert-title">${title}</div><div>${alert.message || ''}</div></div><div class="alert-meta">${metaHtml}</div></div>`;
            }).join('');
        }

        function updateStatusFilterButtons() {
            if (!statusFilterContainer) return;
            statusFilterContainer.querySelectorAll('button[data-status]').forEach(button => {
                button.classList.toggle('active', button.dataset.status === statusFilter);
            });
        }

        function renderModalInsights(insights = []) {
            const container = document.getElementById('modal-insights-list');
            if (!container) return;
            if (!insights.length) {
                container.innerHTML = '<li>暂无统计数据。</li>';
                return;
            }
            container.innerHTML = insights.map(item => {
                const context = item.context ? `<span class="context">${item.context}</span>` : '';
                const time = item.timestamp ? `<span class="context">${formatAbsoluteTime(item.timestamp)}</span>` : '';
                return `<li><strong>${item.label}</strong><br>${item.value}${context}${time}</li>`;
            }).join('');
        }

        function renderAllViews() {
            if (summaryContainer) {
                renderSummary(monitorData.summary || {}, monitorData.nodes);
            }
            if (alertPanel) {
                const alerts = monitorData.summary && Array.isArray(monitorData.summary.alerts) ? monitorData.summary.alerts : [];
                renderAlerts(alerts);
            }
            updateStatusFilterButtons();
            initializeMap(monitorData.nodes);
            generateStandalonesView(monitorData.nodes);
            generateOutagesView(monitorData.outages, monitorData.nodes);
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

        if (searchInput) {
            let searchDebounce;
            searchInput.addEventListener('input', (event) => {
                const value = event.target.value.toLowerCase();
                clearTimeout(searchDebounce);
                searchDebounce = setTimeout(() => {
                    searchQuery = value.trim();
                    currentPage = 1;
                    generateStandalonesView(monitorData.nodes);
                }, 200);
            });
        }

        if (statusFilterContainer) {
            statusFilterContainer.addEventListener('click', (event) => {
                const button = event.target.closest('button[data-status]');
                if (!button) return;
                statusFilter = button.dataset.status;
                currentPage = 1;
                updateStatusFilterButtons();
                generateStandalonesView(monitorData.nodes);
            });
        }

        if (alertPanel) {
            alertPanel.addEventListener('click', (event) => {
                const item = event.target.closest('.alert-item');
                if (!item) return;
                const serverId = item.dataset.serverId;
                if (serverId) showDetailsModal(serverId);
            });
        }

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
                if (node.hasOwnProperty('x') && node.hasOwnProperty('y')) {
                    const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                    circle.setAttribute('id', `map-node-${node.id}`);
                    circle.setAttribute('class', `map-node ${!node.is_online ? 'anomaly' : ''}`);
                    circle.setAttribute('cx', node.y); circle.setAttribute('cy', node.x); circle.setAttribute('r', 5);
                    svg.appendChild(circle);
                    circle.addEventListener('mouseenter', () => {
                        let content = `<strong>${getFlagEmoji(node.country_code)} ${node.name}</strong>`;
                        if (node.intro) content += `<br><span class="subtitle">${node.intro}</span>`;
                        if (!node.is_online) content += `<br><span class="anomaly-subtitle">${node.anomaly_msg || '状态异常'}</span>`;
                        tooltip.innerHTML = content;
                        tooltip.style.display = 'block';
                    });
                    circle.addEventListener('mouseleave', () => { tooltip.style.display = 'none'; });
                    circle.addEventListener('click', () => showDetailsModal(node.id));
                }
            });
        }
        
        function formatBytes(bytes, decimals = 2) {
            if (!bytes || bytes === 0) return '0 Bytes';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }

        function generateStandalonesView(nodes) {
            const container = document.querySelector('#standalones-view .card-grid');
            if (!container) return;
            container.innerHTML = '';

            const filteredNodes = nodes.filter(node => {
                const nodeTags = node.tags ? node.tags.split(',').map(t => t.trim()) : [];
                const matchesTags = activeTags.size === 0 || [...activeTags].every(tag => nodeTags.includes(tag));
                if (!matchesTags) return false;

                const searchTarget = `${node.name || ''} ${node.intro || ''} ${node.country_code || ''} ${node.tags || ''}`.toLowerCase();
                const matchesSearch = !searchQuery || searchTarget.includes(searchQuery);
                if (!matchesSearch) return false;

                const isOffline = !node.is_online;
                const highLoad = shouldFlagHighLoad(node);

                if (statusFilter === 'online' && isOffline) return false;
                if (statusFilter === 'offline' && !isOffline) return false;
                if (statusFilter === 'highload' && !highLoad) return false;
                return true;
            });

            if (filteredNodes.length === 0) {
                container.innerHTML = '<div class="empty-state">没有符合条件的服务器。</div>';
                renderPaginationControls(0);
                return;
            }

            const totalPages = Math.max(1, Math.ceil(filteredNodes.length / itemsPerPage));
            if (currentPage > totalPages) currentPage = totalPages;

            const paginatedNodes = filteredNodes.slice((currentPage - 1) * itemsPerPage, currentPage * itemsPerPage);

            paginatedNodes.forEach(node => {
                const stats = node.stats || {};
                const isOffline = !node.is_online;
                const isHighLoad = shouldFlagHighLoad(node);

                let cardClass = 'server-card';
                if (isOffline) cardClass += ' offline';
                if (isHighLoad) cardClass += ' high-load';

                const card = document.createElement('div');
                card.className = cardClass;
                card.dataset.serverId = node.id;

                const cpu = parseFloat(stats.cpu_usage || 0);
                const mem = parseFloat(stats.mem_usage_percent || 0);
                const disk = parseFloat(stats.disk_usage_percent || 0);
                const uptime = isOffline ? formatDuration(node.outage_duration || 0) : (stats.uptime || '...');
                const introHtml = node.intro ? `<p class="card-intro">${node.intro}</p>` : '';
                const statTimestamp = stats.timestamp ? formatRelativeTime(stats.timestamp) : '';
                const statTimestampTitle = stats.timestamp ? formatAbsoluteTime(stats.timestamp) : '';
                const priceText = node.price_usd_monthly != null
                    ? `${formatCurrency(node.price_usd_monthly)}/月`
                    : (node.price_usd_yearly != null ? `${formatCurrency(node.price_usd_yearly)}/年` : '');

                const metaSegments = [];
                if (priceText) metaSegments.push(`<span>💰 ${priceText}</span>`);
                if (node.country_code) metaSegments.push(`<span>${getFlagEmoji(node.country_code)} ${node.country_code.toUpperCase()}</span>`);
                if (node.tags) metaSegments.push(`<span>🏷 ${node.tags}</span>`);
                if (isOffline) {
                    const offlineInfo = node.outage_duration ? formatDuration(node.outage_duration) : '未知';
                    const offlineTitle = node.last_checked ? formatAbsoluteTime(node.last_checked) : '';
                    metaSegments.push(`<span title="${offlineTitle}">⚠️ ${offlineInfo}</span>`);
                } else if (statTimestamp) {
                    metaSegments.push(`<span title="${statTimestampTitle}">⏱ ${statTimestamp}</span>`);
                }

                const metaHtml = metaSegments.length ? `<div class="card-meta">${metaSegments.join('')}</div>` : '';

                card.innerHTML = `
                    <div class="card-header">
                        <div class="status-icon ${isOffline ? 'down' : ''}"></div>
                        <div class="name">${getFlagEmoji(node.country_code)} ${node.name}</div>
                    </div>
                    ${introHtml}
                    <div class="stat-grid">
                        <div class="label">CPU</div>
                        <div class="progress-bar"><div class="progress-bar-inner progress-cpu" style="width: ${cpu.toFixed(0)}%;">${cpu.toFixed(0)}%</div></div>
                        <div class="label">内存</div>
                        <div class="progress-bar"><div class="progress-bar-inner progress-mem" style="width: ${mem.toFixed(0)}%;">${mem.toFixed(0)}%</div></div>
                        <div class="label">硬盘</div>
                        <div class="progress-bar"><div class="progress-bar-inner progress-disk" style="width: ${disk.toFixed(0)}%;">${disk.toFixed(0)}%</div></div>
                        <div class="label">网络</div>
                        <div class="value">↑ ${formatBytes(stats.net_up_speed || 0)}/s | ↓ ${formatBytes(stats.net_down_speed || 0)}/s</div>
                        <div class="label">流量</div>
                        <div class="value">↑ ${formatBytes(stats.total_up || 0)} | ↓ ${formatBytes(stats.total_down || 0)}</div>
                        <div class="label">负载</div>
                        <div class="value">${parseFloat(stats.load_avg || 0).toFixed(2)}</div>
                        <div class="label">${isOffline ? '掉线时长' : '在线'}</div>
                        <div class="value">${uptime}</div>
                    </div>
                    ${metaHtml}
                `;
                container.appendChild(card);
                card.addEventListener('click', () => showDetailsModal(node.id));
            });
            renderPaginationControls(filteredNodes.length);
        }

        function renderPaginationControls(totalItems) {
            const container = document.querySelector('.pagination');
            if (!container) return;
            container.innerHTML = '';
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            if (totalPages <= 1) return;
            if (currentPage > totalPages) currentPage = totalPages;

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
                if (node.tags) node.tags.split(',').forEach(tag => tag.trim() && allTags.add(tag.trim()));
            });

            container.innerHTML = `<strong>筛选: </strong>`;
            const allButton = document.createElement('button');
            allButton.textContent = '全部';
            allButton.className = activeTags.size === 0 ? 'active' : '';
            allButton.onclick = () => {
                activeTags.clear();
                currentPage = 1;
                generateStandalonesView(monitorData.nodes);
                generateTagFilters(monitorData.nodes);
            };
            container.appendChild(allButton);

            allTags.forEach(tag => {
                const button = document.createElement('button');
                button.textContent = tag;
                button.className = activeTags.has(tag) ? 'active' : '';
                button.onclick = () => {
                    activeTags.has(tag) ? activeTags.delete(tag) : activeTags.add(tag);
                    currentPage = 1;
                    generateStandalonesView(monitorData.nodes);
                    generateTagFilters(monitorData.nodes);
                };
                container.appendChild(button);
            });
        }
        
        async function showDetailsModal(serverId) {
            const node = monitorData.nodes.find(n => n.id == serverId);
            if (!node) return;
            const modalBody = document.getElementById('modal-body');
            const isOffline = !node.is_online;
            
            modalBody.innerHTML = `
                <div class="modal-header"><h2>${getFlagEmoji(node.country_code)} ${node.name}</h2></div>
                <div class="modal-info-section">
                     <div class="modal-info-item"><strong>简介:</strong><p>${node.intro || 'N/A'}</p></div>
                     <div class="info-grid">
                        <div class="modal-info-item"><strong>操作系统:</strong><p>${node.system || 'N/A'}</p></div>
                        <div class="modal-info-item"><strong>架构:</strong><p>${node.arch || 'N/A'}</p></div>
                        <div class="modal-info-item"><strong>CPU 型号:</strong><p>${node.cpu_model || 'N/A'}</p></div>
                        <div class="modal-info-item"><strong>内存大小:</strong><p>${formatBytes(node.mem_total, 2) || 'N/A'}</p></div>
                        <div class="modal-info-item"><strong>磁盘大小:</strong><p>${formatBytes(node.disk_total, 2) || 'N/A'}</p></div>
                        ${isOffline ? `<div class="modal-info-item"><strong>掉线时长:</strong><p>${formatDuration(node.outage_duration || 0)}</p></div>` : ''}
                    </div>
                </div>
                <div class="modal-insights">
                    <h3>运行摘要</h3>
                    <ul id="modal-insights-list"><li>加载中...</li></ul>
                </div>
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
                const response = await fetch(`./api.php?action=get_history&id=${serverId}`);
                const data = await response.json();
                if (data.error) throw new Error(data.error);

                const history = data.history || [];
                renderModalInsights(data.insights || []);
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
                document.querySelector('.chart-grid').innerHTML = `<p style="color:red; text-align:center;">无法加载数据</p>`;
                const insightList = document.getElementById('modal-insights-list');
                if (insightList) insightList.innerHTML = '<li>无法加载运行摘要</li>';
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
                }
                const itemHTML = `<div class="timeline-item ${!outage.end_time ? 'critical' : ''}"><div class="time">${startTime}</div><div class="title">${outage.title} - ${nodeName}</div><div class="content">${content}</div></div>`;
                container.innerHTML += itemHTML;
            });
        }

        function fireSignal(nodes) {
            if (!nodes) return;
            const availableNodes = nodes.filter(n => n.is_online && n.x && n.y);
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
            
            const startX = (startNode.y - viewBox.x) * scaleX;
            const startY = (startNode.x - viewBox.y) * scaleY;
            const endX = (endNode.y - viewBox.x) * scaleX;
            const endY = (endNode.x - viewBox.y) * scaleY;

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

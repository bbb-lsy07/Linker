    document.addEventListener('DOMContentLoaded', () => {
        let monitorData = { nodes: [], outages: [], site_name: '灵刻监控' };
        let activeButton = document.querySelector('.controls button[data-view="map"]');
        let signalInterval = null;
        let activeTags = new Set();
        let currentPage = 1;
        const itemsPerPage = 18;


        const tooltip = document.getElementById('tooltip');
        const mapContainer = document.getElementById('world-map-container');
        const controls = document.querySelector('.controls');
        const views = document.querySelectorAll('.view');
        const modalOverlay = document.getElementById('details-modal');
        const modalCloseBtn = document.getElementById('modal-close-btn');


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

        function renderAllViews() {
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
            container.innerHTML = '';
            
            const filteredNodes = nodes.filter(node => {
                const nodeTags = node.tags ? node.tags.split(',').map(t => t.trim()) : [];
                return activeTags.size === 0 || [...activeTags].every(tag => nodeTags.includes(tag));
            });

            const paginatedNodes = filteredNodes.slice((currentPage - 1) * itemsPerPage, currentPage * itemsPerPage);

            paginatedNodes.forEach(node => {
                const stats = node.stats || {};
                const isOffline = !node.is_online;
                const isHighLoad = !isOffline && stats.load_avg > 2.0;
                
                let cardClass = 'server-card';
                if (isOffline) cardClass += ' offline';
                if (isHighLoad) cardClass += ' high-load';

                const card = document.createElement('div');
                card.className = cardClass;
                card.dataset.serverId = node.id;

                const cpu = parseFloat(stats.cpu_usage || 0);
                const mem = parseFloat(stats.mem_usage_percent || 0);
                const disk = parseFloat(stats.disk_usage_percent || 0);
                const uptime = isOffline ? (formatDuration(node.outage_duration || 0)) : (stats.uptime || '...');

                card.innerHTML = `
                    <div class="card-header">
                        <div class="status-icon ${isOffline ? 'down' : ''}"></div>
                        <div class="name">${getFlagEmoji(node.country_code)} ${node.name}</div>
                    </div>
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
                if (node.tags) node.tags.split(',').forEach(tag => tag.trim() && allTags.add(tag.trim()));
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

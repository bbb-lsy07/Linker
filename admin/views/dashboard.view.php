<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员面板 - 灵刻监控</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background-color: #f0f2f5; margin: 0; padding: 2rem; color: #333; }
        .container { max-width: 1200px; margin: 0 auto; background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #dee2e6; padding-bottom: 1rem; margin-bottom: 2rem; }
        h1, h2 { color: #111; margin-top: 0; }
        h2 { border-bottom: 1px solid #eee; padding-bottom: 0.5rem; margin-top: 2rem; }
        a.logout { text-decoration: none; background: #343a40; color: #fff; padding: 0.5rem 1rem; border-radius: 5px; transition: background 0.2s; }
        a.logout:hover { background: #495057; }
        .message { background: #28a745; color: #fff; padding: 1rem; border-radius: 5px; margin-bottom: 1rem; }
        .error-message { background: #dc3545; color: #fff; padding: 1rem; border-radius: 5px; margin-bottom: 1rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; font-size: 0.9em; }
        th, td { text-align: left; padding: 0.9rem 0.7rem; border-bottom: 1px solid #dee2e6; vertical-align: middle; }
        th { background-color: #f8f9fa; font-weight: 600; }
        tr:hover { background-color: #f8f9fa; }
        form { margin: 0; }
        label { font-weight: 600; display: block; margin-bottom: 0.4rem; font-size: 0.9em; }
        input[type="text"], input[type="number"], textarea, select { width: 100%; padding: 0.6rem; border: 1px solid #ced4da; border-radius: 4px; box-sizing: border-box; transition: border-color 0.2s, box-shadow 0.2s; }
        input:focus, textarea:focus, select:focus { border-color: #80bdff; outline: 0; box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25); }
        input[readonly] { background: #e9ecef; cursor: not-allowed; }
        button { background-color: #007bff; color: #fff; border: none; padding: 0.6rem 1.2rem; border-radius: 5px; cursor: pointer; transition: background-color 0.2s; font-weight: 600; }
        button:hover { background-color: #0056b3; }
        button.delete { background-color: #dc3545; }
        button.delete:hover { background-color: #c82333; }
        button.secondary { background-color: #6c757d; }
        button.secondary:hover { background-color: #5a6268; }
        .section { margin-bottom: 3rem; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.2rem; align-items: flex-start; margin-bottom: 1.2rem; }
        details { border: 1px solid #dee2e6; padding: 1.5rem; border-radius: 5px; margin-bottom: 1rem; background: #fdfdfd; }
        summary { font-weight: 600; cursor: pointer; font-size: 1.1em; }
        .actions-cell { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; display: flex; align-items: center; justify-content: center; }
        .modal-content { background: #fff; padding: 2rem; border-radius: 8px; max-width: 90%; max-height: 90%; overflow-y: auto; position: relative; }
        .modal-close { position: absolute; top: 10px; right: 15px; background: none; border: none; font-size: 24px; cursor: pointer; color: #666; }
        .modal-close:hover { color: #000; }
        .modal-code-block { background-color: #2d2d2d; color: #f1f1f1; padding: 1rem; border-radius: 5px; margin-top: 0.5rem; white-space: pre-wrap; word-wrap: break-word; position: relative; font-family: monospace; }
        .modal-code-block .copy-btn { position: absolute; top: 10px; right: 10px; background: #555; color: #fff; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; }
        .modal-code-block .copy-btn:hover { background: #777; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>管理员面板</h1>
            <a href="logout.php" class="logout">登出</a>
        </div>
        
        <?php if ($message): ?><p class="message"><?php echo htmlspecialchars($message ?? ''); ?></p><?php endif; ?>
        <?php if ($error_message): ?><p class="error-message"><?php echo htmlspecialchars($error_message ?? ''); ?></p><?php endif; ?>

        <div class="section">
            <details>
                <summary>通用设置</summary>
                <form name="save_settings_form" action="dashboard.php" method="post" style="margin-top: 1.5rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token ?? ''); ?>">
                    <div class="form-grid">
                        <div><label for="site_name">站点名称</label><input id="site_name" type="text" name="site_name" value="<?php echo htmlspecialchars($site_name ?? ''); ?>"></div>
                    </div>
                     <p style="margin-top: 2rem;">当服务器掉线时，系统会自动发送通知。请按照部署指南获取Token和Chat ID。</p>
                    <div class="form-grid">
                        <div><label for="tg-token">Telegram Bot Token</label><input id="tg-token" type="text" name="telegram_bot_token" value="<?php echo htmlspecialchars($telegram_bot_token ?? ''); ?>" placeholder="例如: 123456:ABC-DEF..."></div>
                        <div><label for="tg-chat">Telegram Channel/User ID</label><input id="tg-chat" type="text" name="telegram_chat_id" value="<?php echo htmlspecialchars($telegram_chat_id ?? ''); ?>" placeholder="例如: -100123456789 or 12345678"></div>
                    </div>
                    <button type="submit" name="save_settings">保存设置</button>
                </form>
            </details>
        </div>

        <div class="section">
            <details id="add-edit-details">
                <summary>添加新服务器</summary>
                <form id="add-edit-form" action="dashboard.php" method="post" style="margin-top: 1.5rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token ?? ''); ?>">
                    <input type="hidden" name="is_editing" value="">
                    <div class="form-grid">
                        <div><label>服务器 ID (唯一, 英文)</label><input type="text" name="id" required></div>
                        <div><label>服务器名称</label><input type="text" name="name" required></div>
                        <div><label>国家/地区代码 (两位字母)</label><input type="text" name="country_code" placeholder="例如: CN, JP, US" maxlength="2" style="text-transform:uppercase"></div>
                        <div><label>服务器 IP 地址</label><input type="text" name="ip" placeholder="留空则不验证IP"></div>
                    </div>
                    <div class="form-grid">
                        <div><label>经度 (地图X坐标)</label><input type="number" step="any" name="longitude" placeholder="例如: 1083"></div>
                        <div><label>纬度 (地图Y坐标)</label><input type="number" step="any" name="latitude" placeholder="例如: 228"></div>
                        <div><label>标签 (逗号分隔)</label><input type="text" name="tags" placeholder="例如: 亚洲,主力,高防"></div>
                    </div>
                    <div><label>简介</label><textarea name="intro" rows="3"></textarea></div>
                    <div style="margin-top: 1.2rem;">
                        <button type="submit" name="save_server">保存服务器</button>
                        <button type="button" class="secondary" id="cancel-edit-btn" style="display: none;">取消编辑</button>
                    </div>
                </form>
            </details>
        </div>

        <div class="section">
            <h2>已管理的服务器</h2>
            <div style="overflow-x: auto;">
                <table>
                    <thead><tr><th>ID</th><th>名称</th><th>标签</th><th>密钥</th><th>操作</th></tr></thead>
                    <tbody>
                        <?php foreach ($servers as $server): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($server['id']); ?></strong></td>
                            <td><?php echo htmlspecialchars($server['name']); ?></td>
                            <td><?php echo htmlspecialchars($server['tags'] ?? ''); ?></td>
                            <td>
                                <input type="text" id="secret-<?php echo htmlspecialchars($server['id']); ?>" value="<?php echo htmlspecialchars($server['secret'] ?? ''); ?>" readonly style="width: 100%;">
                            </td>
                            <td class="actions-cell">
                                <button class="edit-btn" data-id="<?php echo htmlspecialchars($server['id']); ?>">修改</button>
                                <button class="setup-btn secondary" data-id="<?php echo htmlspecialchars($server['id']); ?>">一键部署</button>
                                <form name="generate_secret_form" action="dashboard.php" method="post" onsubmit="return confirm('确定为 \'<?php echo htmlspecialchars($server['id']); ?>\' 生成一个新的密钥吗？旧密钥将立即失效！');" style="margin:0;">
                                     <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token ?? ''); ?>">
                                     <input type="hidden" name="generate_secret_id" value="<?php echo htmlspecialchars($server['id']); ?>">
                                     <button type="submit" name="generate_secret" class="secondary">新密钥</button>
                                 </form>
                                <form name="delete_server_form" action="dashboard.php" method="post" onsubmit="return confirm('确定删除这台服务器及其所有监控数据吗？');" style="margin: 0;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token ?? ''); ?>">
                                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($server['id']); ?>">
                                    <button type="submit" name="delete_server" class="delete">删除</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- 一键部署命令弹窗 -->
    <div id="command-modal" class="modal-overlay" style="display: none;">
        <div class="modal-content">
            <button class="modal-close" onclick="document.getElementById('command-modal').style.display='none'">&times;</button>
            <h2>一键部署命令</h2>
            <p>请复制以下命令之一，在您需要被监控的服务器上以 root 用户身份执行。</p>
            
            <h3 style="margin-top: 1.5rem;">推荐 (使用 Systemd)</h3>
            <p>此方法会将探针安装为系统服务，更稳定且支持开机自启。</p>
            <div class="modal-code-block">
                <button class="copy-btn" data-target="command-systemd">复制</button>
                <pre id="command-systemd"></pre>
            </div>

            <h3 style="margin-top: 1.5rem;">备用 (使用 Nohup)</h3>
            <p>此方法使用 `nohup` 在后台运行探针，简单快捷，但重启后需手动执行。</p>
            <div class="modal-code-block">
                <button class="copy-btn" data-target="command-nohup">复制</button>
                <pre id="command-nohup"></pre>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const addEditDetails = document.getElementById('add-edit-details');
        const addEditForm = document.getElementById('add-edit-form');
        const formSummary = addEditDetails.querySelector('summary');
        const idInput = addEditForm.querySelector('input[name="id"]');
        const isEditingInput = addEditForm.querySelector('input[name="is_editing"]');
        const cancelBtn = document.getElementById('cancel-edit-btn');
        const serversData = <?php echo json_encode($servers); ?>;

        // 获取弹窗元素
        const commandModal = document.getElementById('command-modal');
        const commandSystemdPre = document.getElementById('command-systemd');
        const commandNohupPre = document.getElementById('command-nohup');

        // 显示消息的通用函数
        function showMessage(message, isError = false) {
            const messageDiv = document.createElement('div');
            messageDiv.className = isError ? 'error-message' : 'message';
            messageDiv.textContent = message;
            
            // 移除现有消息
            const existingMessage = document.querySelector('.message, .error-message');
            if (existingMessage) {
                existingMessage.remove();
            }
            
            // 在容器顶部插入新消息
            const container = document.querySelector('.container');
            container.insertBefore(messageDiv, container.firstChild);
            
            // 3秒后自动隐藏
            setTimeout(() => {
                messageDiv.remove();
            }, 3000);
        }

        // 处理删除服务器
        document.querySelectorAll('form[name="delete_server_form"]').forEach(form => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (!confirm('确定删除这台服务器及其所有监控数据吗？')) return;
                
                const formData = new FormData(form);
                try {
                    const response = await fetch('api/admin.php?action=delete_server', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    
                    if (result.success) {
                        showMessage(result.message);
                        // 移除表格行
                        form.closest('tr').remove();
                    } else {
                        showMessage(result.message, true);
                    }
                } catch (error) {
                    showMessage('请求失败，请重试', true);
                }
            });
        });

        // 处理生成新密钥
        document.querySelectorAll('form[name="generate_secret_form"]').forEach(form => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (!confirm('确定为 \'' + form.querySelector('input[name="generate_secret_id"]').value + '\' 生成一个新的密钥吗？旧密钥将立即失效！')) return;
                
                const formData = new FormData(form);
                try {
                    const response = await fetch('api/admin.php?action=generate_secret', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    
                    if (result.success) {
                        showMessage(result.message);
                        // 更新密钥显示
                        const secretInput = document.querySelector('#secret-' + form.querySelector('input[name="generate_secret_id"]').value);
                        if (secretInput) {
                            secretInput.value = result.new_secret;
                        }
                    } else {
                        showMessage(result.message, true);
                    }
                } catch (error) {
                    showMessage('请求失败，请重试', true);
                }
            });
        });

        // 处理保存服务器
        addEditForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData(addEditForm);
            try {
                const response = await fetch('api/admin.php?action=save_server', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message);
                    addEditForm.reset();
                    resetForm();
                    // 刷新页面以显示最新数据
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showMessage(result.message, true);
                }
            } catch (error) {
                showMessage('请求失败，请重试', true);
            }
        });

        // 处理保存设置
        document.querySelector('form[name="save_settings_form"]').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            try {
                const response = await fetch('api/admin.php?action=save_settings', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message);
                } else {
                    showMessage(result.message, true);
                }
            } catch (error) {
                showMessage('请求失败，请重试', true);
            }
        });

        // 处理"一键部署"按钮点击
        document.querySelectorAll('.setup-btn').forEach(button => {
            button.addEventListener('click', async function() {
                const serverId = this.dataset.id;
                const formData = new FormData();
                formData.append('id', serverId);
                formData.append('csrf_token', '<?php echo $csrf_token; ?>');
                
                try {
                    const response = await fetch('api/admin.php?action=get_setup_command', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();

                    if (result.success) {
                        commandSystemdPre.textContent = result.command_systemd;
                        commandNohupPre.textContent = result.command_nohup;
                        commandModal.style.display = 'flex';
                    } else {
                        showMessage(result.message || '生成命令失败', true);
                    }
                } catch (error) {
                    showMessage('请求失败，请检查网络或刷新页面', true);
                }
            });
        });

        // 处理弹窗内的复制按钮
        commandModal.querySelectorAll('.copy-btn').forEach(button => {
            button.addEventListener('click', function() {
                const targetId = this.dataset.target;
                const textToCopy = document.getElementById(targetId).textContent;
                navigator.clipboard.writeText(textToCopy).then(() => {
                    this.textContent = '已复制!';
                    setTimeout(() => { this.textContent = '复制'; }, 2000);
                }).catch(err => {
                    alert('复制失败: ' + err);
                });
            });
        });
        
        // 点击弹窗外部区域关闭
        commandModal.addEventListener('click', function(e) {
            if (e.target === commandModal) {
                commandModal.style.display = 'none';
            }
        });

        document.querySelectorAll('.edit-btn').forEach(button => {
            button.addEventListener('click', function() {
                const serverId = this.dataset.id;
                const server = serversData.find(s => s.id === serverId);
                if (!server) return;

                formSummary.textContent = `正在编辑: ${server.name}`;
                idInput.value = server.id;
                idInput.readOnly = true;
                isEditingInput.value = '1';
                
                addEditForm.querySelector('input[name="name"]').value = server.name;
                addEditForm.querySelector('input[name="country_code"]').value = server.country_code || '';
                addEditForm.querySelector('input[name="longitude"]').value = server.longitude || '';
                addEditForm.querySelector('input[name="latitude"]').value = server.latitude || '';
                addEditForm.querySelector('input[name="ip"]').value = server.ip || ''; 
                addEditForm.querySelector('input[name="tags"]').value = server.tags || '';
                addEditForm.querySelector('textarea[name="intro"]').value = server.intro || '';
                
                addEditDetails.open = true;
                cancelBtn.style.display = 'inline-block';
                window.scrollTo({ top: addEditDetails.offsetTop, behavior: 'smooth' });
            });
        });

        function resetForm() {
            formSummary.textContent = '添加新服务器';
            addEditForm.reset();
            idInput.readOnly = false;
            isEditingInput.value = '';
            cancelBtn.style.display = 'none';
        }
        cancelBtn.addEventListener('click', resetForm);
        addEditDetails.addEventListener('toggle', function(e) {
            if (!e.target.open) {
                resetForm();
            }
        });

    });
    </script>
</body>
</html>

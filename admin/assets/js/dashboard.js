document.addEventListener('DOMContentLoaded', function() {
    const addEditDetails = document.getElementById('add-edit-details');
    const addEditForm = document.getElementById('add-edit-form');
    const formSummary = addEditDetails.querySelector('summary');
    const idInput = addEditForm.querySelector('input[name="id"]');
    const isEditingInput = addEditForm.querySelector('input[name="is_editing"]');
    const cancelBtn = document.getElementById('cancel-edit-btn');

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

    // 为现有表格行绑定事件监听器
    document.querySelectorAll('table tbody tr').forEach(row => {
        bindRowEvents(row);
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
                
                // 异步更新UI，不再刷新页面
                const isEditing = formData.get('is_editing') === '1';
                if (isEditing) {
                    // 更新现有行
                    updateServerRow(Object.fromEntries(formData.entries()));
                } else {
                    // 添加新行到表格
                    addNewServerRow(result.server_data);
                }
                
                addEditForm.reset();
                resetForm();
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

    // 更新现有服务器行
    function updateServerRow(serverData) {
        const row = document.querySelector(`button.edit-btn[data-id="${serverData.id}"]`).closest('tr');
        if (row) {
            row.cells[0].querySelector('strong').textContent = serverData.id;
            row.cells[1].textContent = serverData.name;
            row.cells[2].textContent = serverData.tags || '';
            // 密钥不会在编辑时改变，所以不用更新
        }
    }

    // 添加新服务器行到表格
    function addNewServerRow(serverData) {
        const tbody = document.querySelector('table tbody');
        const newRow = document.createElement('tr');
        
        newRow.innerHTML = `
            <td><strong>${serverData.id}</strong></td>
            <td>${serverData.name}</td>
            <td>${serverData.tags || ''}</td>
            <td>
                <input type="text" id="secret-${serverData.id}" value="${serverData.secret}" readonly style="width: 100%;">
            </td>
            <td class="actions-cell">
                <button class="edit-btn" data-id="${serverData.id}">修改</button>
                <button class="setup-btn secondary" data-id="${serverData.id}">一键部署</button>
                <form name="generate_secret_form" action="dashboard.php" method="post" onsubmit="return confirm('确定为 '${serverData.id}' 生成一个新的密钥吗？旧密钥将立即失效！');" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="generate_secret_id" value="${serverData.id}">
                    <button type="submit" name="generate_secret" class="secondary">新密钥</button>
                </form>
                <form name="delete_server_form" action="dashboard.php" method="post" onsubmit="return confirm('确定删除这台服务器及其所有监控数据吗？');" style="margin: 0;">
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="id" value="${serverData.id}">
                    <button type="submit" name="delete_server" class="delete">删除</button>
                </form>
            </td>
        `;
        
        tbody.appendChild(newRow);
        
        // 为新行绑定事件监听器
        bindRowEvents(newRow);
        
        // 更新全局serversData数组
        serversData.push(serverData);
    }

    // 为表格行绑定事件监听器
    function bindRowEvents(row) {
        // 绑定编辑按钮
        const editBtn = row.querySelector('.edit-btn');
        if (editBtn) {
            editBtn.addEventListener('click', function() {
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
        }

        // 绑定删除表单
        const deleteForm = row.querySelector('form[name="delete_server_form"]');
        if (deleteForm) {
            deleteForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (!confirm('确定删除这台服务器及其所有监控数据吗？')) return;
                
                const formData = new FormData(deleteForm);
                try {
                    const response = await fetch('api/admin.php?action=delete_server', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    
                    if (result.success) {
                        showMessage(result.message);
                        // 移除表格行
                        row.remove();
                        // 从全局数组中移除
                        const index = serversData.findIndex(s => s.id === formData.get('id'));
                        if (index > -1) {
                            serversData.splice(index, 1);
                        }
                    } else {
                        showMessage(result.message, true);
                    }
                } catch (error) {
                    showMessage('请求失败，请重试', true);
                }
            });
        }

        // 绑定生成密钥表单
        const generateForm = row.querySelector('form[name="generate_secret_form"]');
        if (generateForm) {
            generateForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (!confirm('确定为 \'' + generateForm.querySelector('input[name="generate_secret_id"]').value + '\' 生成一个新的密钥吗？旧密钥将立即失效！')) return;
                
                const formData = new FormData(generateForm);
                try {
                    const response = await fetch('api/admin.php?action=generate_secret', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    
                    if (result.success) {
                        showMessage(result.message);
                        // 更新密钥显示
                        const secretInput = row.querySelector('input[readonly]');
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
        }

        // 绑定一键部署按钮
        const setupBtn = row.querySelector('.setup-btn');
        if (setupBtn) {
            setupBtn.addEventListener('click', async function() {
                const serverId = this.dataset.id;
                const formData = new FormData();
                formData.append('id', serverId);
                formData.append('csrf_token', csrfToken);
                
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
        }
    }

});

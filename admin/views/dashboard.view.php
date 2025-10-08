<?php
// 入口守卫
if (!defined('LINKER_SECURE_INCLUDE')) {
    http_response_code(403);
    die('Direct access not permitted.');
}

// 设置页面标题
$page_title = "服务器管理";

// 引入头部
require_once __DIR__ . '/partials/header.php';
?>

<!-- 页面核心内容开始 -->
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

<?php
// 引入尾部
require_once __DIR__ . '/partials/footer.php';
?>
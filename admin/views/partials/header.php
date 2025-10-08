<?php
if (!defined('LINKER_SECURE_INCLUDE')) {
    http_response_code(403);
    die('Direct access not permitted.');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- 可以在这里接收一个页面标题变量 -->
    <title><?php echo htmlspecialchars($page_title ?? '管理员面板'); ?> - 灵刻监控</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>管理员面板</h1>
            <a href="logout.php" class="logout">登出</a>
        </div>
        
        <?php if (!empty($message)): ?><p class="message"><?php echo htmlspecialchars($message); ?></p><?php endif; ?>
        <?php if (!empty($error_message)): ?><p class="error-message"><?php echo htmlspecialchars($error_message); ?></p><?php endif; ?>

<?php
/**
 * Dashboard 路由器
 * 负责用户认证、加载控制器和视图
 */

session_start();

// 如果 config.php 不存在，则重定向到安装程序
if (!file_exists(__DIR__ . '/../config.php')) {
    header('Location: /setup.php');
    exit;
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: index.php');
    exit;
}

// 生成 CSRF 令牌
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// 引入通用文件
require_once __DIR__ . '/../includes/bootstrap.php';

// 加载并执行控制器逻辑
require_once __DIR__ . '/controllers/dashboard.controller.php';

// 加载视图，视图可以访问控制器中定义的所有变量 ($servers, $message, etc.)
require_once __DIR__ . '/views/dashboard.view.php';
?>
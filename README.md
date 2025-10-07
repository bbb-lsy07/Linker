# 灵刻 (Linker) - 轻量级服务器监控面板

[![PHP Version](https://img.shields.io/badge/php-%3E=7.4-8892BF.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

**灵刻 (Linker)** 是一个现代化、轻量级、自托管的服务器状态监控面板。它通过一个优雅且信息丰富的界面，帮助您实时了解所有服务器的关键性能指标。

[English](./README.en.md) | **简体中文**

![灵刻监控截图](https://example.com/screenshot.png)  
*(请替换为您的项目截图)*

## ✨ 主要特性

- **实时状态监控**: 实时查看 CPU、内存、硬盘使用率、系统负载、网络速度等关键指标。
- **全球地图视图**: 在交互式世界地图上直观地展示您的服务器节点分布和在线状态。
- **历史数据图表**: 查看单个服务器过去24小时的性能历史图表，帮助分析趋势和问题。
- **掉线告警**: 当服务器离线时，可通过 Telegram Bot 自动发送通知，并在恢复时收到提醒。
- **多数据库支持**: 支持 SQLite, MySQL 和 PostgreSQL，安装过程简单灵活。
- **轻量级探针**: 客户端探针是一个简单的 Bash 脚本，资源占用极低，兼容性强。
- **多语言支持**: 内置中英文切换。
- **响应式设计**: 在桌面和移动设备上均有良好的浏览体验。

## 🛠️ 技术栈

- **后端**: PHP (无需框架)
- **数据库**: SQLite / MySQL / PostgreSQL
- **前端**: Vanilla JavaScript (ES6), HTML5, CSS3 (无前端框架)

## 🚀 快速开始

### 1. 服务器端部署 (监控面板)

#### 环境要求
- Web 服务器 (Nginx, Apache 等)
- PHP >= 7.4
- PHP 扩展: `pdo`, `pdo_sqlite` (如果使用 SQLite), `pdo_mysql` (如果使用 MySQL), `pdo_pgsql` (如果使用 PostgreSQL)。

#### 安装步骤
1.  **下载代码**:
    将项目文件上传到您的网站目录，例如 `/var/www/linker`。

2.  **配置 Web 服务器**:
    将网站的运行目录指向项目根目录。以下是一个 Nginx 配置示例：
    ```nginx
    server {
        listen 80;
        server_name your-domain.com;
        root /var/www/linker; # 指向您的项目目录
        index index.html index.php;

        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }

        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/var/run/php/php7.4-fpm.sock; # 您的 PHP-FPM 地址
        }
    }
    ```

3.  **运行安装程序**:
    打开浏览器，访问 `http://your-domain.com/setup.php`。按照页面提示完成以下操作：
    -   设置站点名称。
    -   选择并配置数据库（对于大多数轻量级应用，SQLite 是最简单的选择）。
    -   创建您的管理员账户。

4.  **安全设置**:
    安装成功后，为了安全起见，请**务必删除**项目根目录下的 `setup.php` 文件。您可以点击安装成功页面上的按钮自动删除，或手动删除。

### 2. 客户端部署 (探针)

在您**需要被监控的每一台服务器**上执行以下步骤：

1.  **登录后台添加服务器**:
    访问 `http://your-domain.com/admin`，登录后添加一台新服务器，记下自动生成的 **服务器ID** 和 **密钥(Secret)**。

2.  **配置探针脚本**:
    编辑 `update.sh` 脚本，填入您的信息：
    ```bash
    # API端点，用于接收状态报告
    API_ENDPOINT="http://your-domain.com/report.php"
    # 服务器的唯一ID (与管理员后台设置的ID匹配)
    SERVER_ID="your-server-id" # 修改为您在后台设置的ID
    # 每个服务器独立的密钥 (从管理员后台复制)
    SECRET="your-secret-key"   # 修改为您在后台获取的密钥
    ```

3.  **运行探针**:
    为脚本添加执行权限，并使用 `nohup` 或 `systemd` 等工具使其在后台持续运行。

    **使用 nohup (简单方法)**:
    ```bash
    chmod +x update.sh
    nohup ./update.sh &
    ```

    **使用 systemd (推荐方法)**:
    -   创建一个 service 文件: `sudo nano /etc/systemd/system/linker-agent.service`
    -   填入以下内容 (请修改 `ExecStart` 的路径为 `update.sh` 的实际路径):
        ```ini
        [Unit]
        Description=Linker Monitor Agent
        After=network.target

        [Service]
        Type=simple
        ExecStart=/path/to/your/update.sh
        Restart=always
        User=root # 或者其他有权限的用户

        [Install]
        WantedBy=multi-user.target
        ```
    -   启动并设置开机自启:
        ```bash
        sudo systemctl daemon-reload
        sudo systemctl start linker-agent.service
        sudo systemctl enable linker-agent.service
        ```

### 3. 设置定时任务 (Cron Job)

此步骤在**监控面板所在的服务器**上执行，用于检查服务器是否掉线并发送告警。

1.  编辑您的 crontab:
    ```bash
    crontab -e
    ```

2.  添加以下一行，使其每分钟执行一次检查脚本：
    ```crontab
    * * * * * /usr/bin/php /var/www/linker/check_status.php > /dev/null 2>&1
    ```
    *请确保上面的 PHP 和脚本路径正确。*

## ⚙️ 配置

### Telegram 告警
1.  在 Telegram 上向 [@BotFather](https://t.me/BotFather) 创建一个新的机器人，获取 **Token**。
2.  获取您的 **Chat ID**。
    -   如果是私聊，可以向 [@userinfobot](https://t.me/userinfobot) 发送消息获取。
    -   如果是频道，先将机器人设为管理员，然后将频道设为公开，发送一条消息，复制消息链接，链接中的 `c/` 后面的数字即为 Chat ID，前面加上 `-100`。
3.  登录灵刻监控后台，在"通用设置"中填入您的 Bot Token 和 Chat ID。

## 📄 许可证

本项目基于 [MIT License](https://opensource.org/licenses/MIT) 开源。

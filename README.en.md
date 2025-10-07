# Linker - Lightweight Server Monitoring Panel

[![PHP Version](https://img.shields.io/badge/php-%3E=7.4-8892BF.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

**Linker** is a modern, lightweight, self-hosted server status monitoring panel. It provides an elegant and informative interface to help you understand the key performance metrics of all your servers in real-time.

**简体中文** | [English](./README.en.md)

## ✨ Key Features

- **Real-time Status Monitoring**: View CPU, memory, disk usage, system load, network speed, and other key metrics in real-time.
- **Global Map View**: Intuitively display your server node distribution and online status on an interactive world map.
- **Historical Data Charts**: View performance history charts for individual servers over the past 24 hours to help analyze trends and issues.
- **Outage Alerts**: Automatically send notifications via Telegram Bot when servers go offline, and receive alerts when they recover.
- **Multi-Database Support**: Supports SQLite, MySQL, and PostgreSQL with simple and flexible installation process.
- **Lightweight Agent**: The client agent is a simple Bash script with extremely low resource usage and strong compatibility.
- **Multi-language Support**: Built-in Chinese and English language switching.
- **Responsive Design**: Excellent browsing experience on both desktop and mobile devices.

## 🛠️ Tech Stack

- **Backend**: PHP (No framework required)
- **Database**: SQLite / MySQL / PostgreSQL
- **Frontend**: Vanilla JavaScript (ES6), HTML5, CSS3 (No frontend framework)

## 🚀 Quick Start

### 1. Server-side Deployment (Monitoring Panel)

#### Requirements
- Web server (Nginx, Apache, etc.)
- PHP >= 7.4
- PHP extensions: `pdo`, `pdo_sqlite` (if using SQLite), `pdo_mysql` (if using MySQL), `pdo_pgsql` (if using PostgreSQL).

#### Installation Steps
1.  **Download Code**:
    Upload the project files to your website directory, for example `/var/www/linker`.

2.  **Configure Web Server**:
    Point the website's document root to the project root directory. Here's an example Nginx configuration:
    ```nginx
    server {
        listen 80;
        server_name your-domain.com;
        root /var/www/linker; # Point to your project directory
        index index.html index.php;

        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }

        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/var/run/php/php7.4-fpm.sock; # Your PHP-FPM address
        }
    }
    ```

3.  **Run Installer**:
    Open your browser and visit `http://your-domain.com/setup.php`. Follow the page instructions to complete the following:
    -   Set site name.
    -   Select and configure database (for most lightweight applications, SQLite is the simplest choice).
    -   Create your administrator account.

4.  **Security Settings**:
    After successful installation, for security reasons, please **delete** the `setup.php` file from the project root directory. You can click the button on the installation success page to delete it automatically, or delete it manually.

### 2. Client Deployment (Agent)

Execute the following steps on **each server you want to monitor**:

1.  **Login to Admin Panel and Add Server**:
    Visit `http://your-domain.com/admin`, log in and add a new server, note down the automatically generated **Server ID** and **Secret**.

2.  **Configure Agent Script**:
    Edit the `update.sh` script and fill in your information:
    ```bash
    # API endpoint for receiving status reports
    API_ENDPOINT="http://your-domain.com/report.php"
    # Unique server ID (matches the ID set in admin panel)
    SERVER_ID="your-server-id" # Change to the ID you set in the admin panel
    # Independent secret for each server (copy from admin panel)
    SECRET="your-secret-key"   # Change to the secret you obtained from admin panel
    ```

3.  **Run Agent**:
    Add execute permissions to the script and use `nohup` or `systemd` to run it continuously in the background.

    **Using nohup (Simple method)**:
    ```bash
    chmod +x update.sh
    nohup ./update.sh &
    ```

    **Using systemd (Recommended method)**:
    -   Create a service file: `sudo nano /etc/systemd/system/linker-agent.service`
    -   Fill in the following content (please modify the `ExecStart` path to the actual path of `update.sh`):
        ```ini
        [Unit]
        Description=Linker Monitor Agent
        After=network.target

        [Service]
        Type=simple
        ExecStart=/path/to/your/update.sh
        Restart=always
        User=root # or other user with permissions

        [Install]
        WantedBy=multi-user.target
        ```
    -   Start and enable auto-start:
        ```bash
        sudo systemctl daemon-reload
        sudo systemctl start linker-agent.service
        sudo systemctl enable linker-agent.service
        ```

### 3. Set Up Cron Job

This step is executed on the **server where the monitoring panel is located** to check if servers are offline and send alerts.

1.  Edit your crontab:
    ```bash
    crontab -e
    ```

2.  Add the following line to execute the check script every minute:
    ```crontab
    * * * * * /usr/bin/php /var/www/linker/check_status.php > /dev/null 2>&1
    ```
    *Please ensure the PHP and script paths above are correct.*

## ⚙️ Configuration

### Telegram Alerts
1.  Create a new bot on Telegram by messaging [@BotFather](https://t.me/BotFather) to get a **Token**.
2.  Get your **Chat ID**.
    -   For private chats, you can message [@userinfobot](https://t.me/userinfobot) to get it.
    -   For channels, first set the bot as an administrator, then make the channel public, send a message, copy the message link, the number after `c/` in the link is the Chat ID, add `-100` in front.
3.  Login to the Linker monitoring admin panel and fill in your Bot Token and Chat ID in "General Settings".

## 📄 License

This project is open source under the [MIT License](https://opensource.org/licenses/MIT).

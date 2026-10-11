免责声明：本项目使用 deepseek 完成，代码不完善，请在使用前检查，出现问题的责任不由我们承担。（本版代码未验证）。最终解释权归 czz-cmd 所有。

聊天室 · 自托管实时通讯系统

一个轻量级的自托管聊天室，支持 PC 和手机端，具备群组管理、消息撤回、敏感词过滤、禁言、超管后台等功能。

【功能特性】

用户端
- 注册 / 登录（Token 鉴权）
- 实时收发消息（WebSocket 推送）
- 消息撤回（自己 2 分钟内、群管、超管可撤回）
- 敏感词自动过滤
- 用户偏好设置（拒绝被拉入非官方群）
- 修改密码 / 注销账户
- 深色 / 浅色主题切换
- PC + 手机双端适配

群组管理
- 创建群组
- 邀请成员
- 退出群组
- 解散群组（创建者 / 群管 / 超管）
- 禁言成员（多档时长可选）
- 踢出成员
- 清空群消息

超管后台
- 数据统计（用户数 / 群组数 / 消息数 / 封禁数 / 禁言数）
- 封禁 / 解封用户
- 强制注销用户
- 跨群管理（踢人 / 禁言 / 清空 / 解散）
- 一键清空全部数据

【技术栈】

- 后端：PHP 8.1+
- 数据库：MySQL 8.0+
- WebSocket：Workerman 4.x
- 前端：原生 HTML + Tailwind CSS（CDN）
- 通信：WebSocket + HTTP REST

【项目结构】

.
├── api.php                 HTTP 后端接口
├── ws-server.php           WebSocket 服务（Workerman）
├── config.example.php      配置模板
├── config.php              实际配置（需自行创建，勿提交）
├── composer.json
├── composer.lock
├── vendor/                 Composer 依赖
├── sql/
│   └── init.sql            数据库初始化
├── public/
│   ├── index.html          PC 版前端
│   └── phone.html          手机版前端
└── README.md

【部署步骤】

1. 环境要求

- PHP >= 8.1（推荐 8.2+）
- MySQL >= 8.0
- Composer
- Nginx（或 Apache）

PHP 需要的扩展：
- pdo_mysql
- curl
- openssl
- mbstring
- zip
- json

2. 克隆项目

git clone https://github.com/czz-cmd/czztv-chat-web.git
cd czztv-chat-web

3. 安装依赖

composer install

4. 配置

复制配置模板：
cp config.example.php config.php

编辑 config.php，填入你的真实配置：
$DB_HOST = '127.0.0.1';
$DB_PORT = 3306;
$DB_NAME = 'chatroom';
$DB_USER = 'chat_user';
$DB_PASS = '你的数据库密码';

$WS_INTERNAL_SECRET = '一段足够长的随机字符串';
$WS_PUSH_URL = 'http://127.0.0.1:3001/push';

注意：WS_INTERNAL_SECRET 必须和 ws-server.php 里用的一致。

5. 建数据库

mysql -u root -p < sql/init.sql

或者在 phpMyAdmin 里导入 sql/init.sql。

6. 启动 WebSocket 服务

# 前台运行（调试用，Ctrl+C 停止）
php ws-server.php start

# 后台运行
php ws-server.php start -d

# 停止
php ws-server.php stop

# 查看状态
php ws-server.php status

期望输出：
----------------------- WORKERMAN -----------------------------
Workerman version:4.0.0          PHP version:8.2.9
------------------------ WORKERS -------------------------------
proto   user    worker    listen                   processes   status
tcp     root    none      websocket://0.0.0.0:3000 1           [OK]
tcp     root    none      http://0.0.0.0:3001      1           [OK]

7. 配置 Nginx

server {
    listen 80;
    server_name 你的域名.com;
    root /var/www/chat/public;
    index index.html;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location /ws {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
}

8. 配置 HTTPS（推荐）

sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d 你的域名.com

9. 修改前端配置

编辑 public/index.html 和 public/phone.html，改这两行：
const API_BASE = 'https://你的域名.com/api.php';
const WS_URL   = 'wss://你的域名.com/ws';

10. 测试

- 浏览器打开 https://你的域名.com
- 注册第一个账号（自动成为超管）
- 再注册第二个账号
- 两个浏览器同时登录，互相发消息
- 应该实时收到，延迟 <100ms

【使用说明】

超管权限
第一个注册的用户自动成为超管。
如果想手动指定超管，在数据库执行：
INSERT INTO admins (username) VALUES ('你的用户名');

创建群组
- 点击侧边栏「我的群组」右侧的加号按钮
- 输入群名，创建
- 创建者自动成为群管理员

邀请成员
- 点击「群成员」区域的邀请按钮
- 输入已注册的用户名，邀请

撤回消息
- 鼠标悬停消息气泡，点击撤回按钮
- 自己 2 分钟内的消息、群管、超管可撤回

后台管理
- 超管登录后，侧边栏会出现后台按钮
- 点击进入后台，可管理用户和群组

【安全说明】

- 密码使用 password_hash()（bcrypt）存储
- Token 使用 SHA-256 哈希存储，不存明文
- Token 有效期 7 天，过期需重新登录
- 修改密码 / 封号后，所有 Token 立即失效
- 敏感操作二次验证密码
- 消息内容使用 htmlspecialchars() 防 XSS
- 所有 SQL 使用预处理语句防注入

【常见问题】

WebSocket 连不上
1. 检查 ws-server.php 是否启动：php ws-server.php status
2. 检查 Nginx 是否配置了 /ws 反向代理
3. 检查防火墙是否开放 3000 端口（不需要对外开放，Nginx 内部转发即可）
4. 浏览器 F12 → Network → WS，看连接状态

消息发了但对方收不到
1. 检查 config.php 里的 WS_INTERNAL_SECRET 是否和 ws-server.php 一致
2. 手动测试推送接口：
curl -X POST http://127.0.0.1:3001/push \
  -H "Content-Type: application/json" \
  -d '{"secret":"你的密钥","group_id":"1","event":"test","payload":{"msg":"hi"}}'
期望返回 {"ok":true,"sent":N}

数据库连接失败
1. 检查 config.php 里的数据库配置
2. 检查 MySQL 用户权限：
GRANT ALL PRIVILEGES ON chatroom.* TO 'chat_user'@'localhost';
FLUSH PRIVILEGES;
3. 检查 MySQL 是否在运行

PHP 报 "Class Workerman not found"
composer install
或
composer require workerman/workerman:^4.0

【更新日志】

v1.0.0
- 初始版本
- 用户注册/登录
- 群组管理
- 消息收发
- WebSocket 实时推送
- 消息撤回
- 超管后台
- PC + 手机双端

【License】

MIT License

【贡献】

- deepseekseek
- czz-cmd

【联系】

GitHub：https://github.com/czz-cmd
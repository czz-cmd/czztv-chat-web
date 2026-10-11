<?php
/**
 * 单文件 PHP WebSocket 服务（Workerman）
 * 
 * 安装：composer require workerman/workerman
 * 启动：php ws-server.php start -d
 * 停止：php ws-server.php stop
 */

require __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Connection\TcpConnection;

// ==================== 配置 ====================
if (!file_exists(__DIR__ . '/config.php')) {
    die("config.php 不存在，请先复制 config.example.php\n");
}
require __DIR__ . '/config.php';

$WS_PORT = 3000;
$PUSH_PORT = 3001;

// ==================== WebSocket 服务 ====================
$ws_worker = new Worker("websocket://0.0.0.0:{$WS_PORT}");
$GLOBALS['channels'] = [];

$ws_worker->onConnect = function(TcpConnection $connection) {
    echo "[+] 新连接: " . $connection->getRemoteIp() . "\n";
};

$ws_worker->onMessage = function(TcpConnection $connection, $data) {
    $msg = json_decode($data, true);
    if (!$msg || !isset($msg['type'])) return;

    if ($msg['type'] === 'join') {
        $groupId = $msg['group_id'] ?? '1';
        $username = $msg['username'] ?? 'anon';
        $channelName = "group-{$groupId}";

        $connection->groupId = $groupId;
        $connection->username = $username;
        $connection->channelName = $channelName;

        if (!isset($GLOBALS['channels'][$channelName])) {
            $GLOBALS['channels'][$channelName] = [];
        }
        $GLOBALS['channels'][$channelName][$connection->id] = $connection;

        echo "[JOIN] {$username} 加入 {$channelName}，当前 " . count($GLOBALS['channels'][$channelName]) . " 人\n";

        $connection->send(json_encode([
            'type' => 'connected',
            'group_id' => $groupId,
            'username' => $username,
            'online' => count($GLOBALS['channels'][$channelName])
        ]));
        return;
    }

    if ($msg['type'] === 'ping') {
        $connection->send(json_encode(['type' => 'pong']));
        return;
    }
};

$ws_worker->onClose = function(TcpConnection $connection) {
    if (isset($connection->channelName)) {
        $channelName = $connection->channelName;
        if (isset($GLOBALS['channels'][$channelName][$connection->id])) {
            unset($GLOBALS['channels'][$channelName][$connection->id]);
        }
        echo "[-] {$connection->username} 离开 {$channelName}\n";
    }
};

// ==================== 内部 HTTP 推送接口 ====================
$http_worker = new Worker("http://0.0.0.0:{$PUSH_PORT}");

$http_worker->onMessage = function(TcpConnection $connection, $request) {
    global $WS_INTERNAL_SECRET;

    if ($request->method() !== 'POST' || $request->path() !== '/push') {
        $connection->send("HTTP/1.1 404 Not Found\r\n\r\n");
        return;
    }

    $body = $request->rawBody();
    $data = json_decode($body, true);

    if (!$data || ($data['secret'] ?? '') !== $WS_INTERNAL_SECRET) {
        $connection->send("HTTP/1.1 403 Forbidden\r\n\r\n");
        return;
    }

    $channelName = 'group-' . ($data['group_id'] ?? '1');
    $exclude = $data['exclude'] ?? null;
    $payload = json_encode([
        'type' => $data['event'] ?? 'unknown',
    ] + ($data['payload'] ?? []), JSON_UNESCAPED_UNICODE);

    $sent = 0;
    if (isset($GLOBALS['channels'][$channelName])) {
        foreach ($GLOBALS['channels'][$channelName] as $conn) {
            if ($exclude && $conn->username === $exclude) continue;
            $conn->send($payload);
            $sent++;
        }
    }

    $response = json_encode(['ok' => true, 'sent' => $sent]);
    $connection->send("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($response) . "\r\n\r\n" . $response);
};

Worker::runAll();
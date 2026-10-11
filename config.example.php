<?php
/**
 * 配置模板
 * 使用方法：复制本文件为 config.php，填入真实值
 */

// ==================== 数据库配置 ====================
$DB_HOST = '127.0.0.1';
$DB_PORT = 3306;
$DB_NAME = 'chatroom';
$DB_USER = 'chat_user';
$DB_PASS = 'YOUR_DB_PASSWORD';

// ==================== Token 配置 ====================
$TOKEN_TTL = 86400 * 7;   // Token 有效期，7 天

// ==================== 敏感词和限制 ====================
$BANNED_WORDS = ['傻逼','傻B','操你','草你','尼玛','你妈','他妈','fuck','shit','damn','垃圾','脑残','智障','滚蛋'];
$RECALL_WINDOW = 120;     // 撤回时间窗口（秒）

// ==================== WebSocket 配置 ====================
$WS_INTERNAL_SECRET = 'CHANGE_ME_TO_A_LONG_RANDOM_STRING';
$WS_PUSH_URL = 'http://127.0.0.1:3001/push';
<?php
/**
 * 聊天室后端 API
 * 依赖：MySQL + 自建 WebSocket 服务（见 ws-server.php）
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

// ==================== 加载配置 ====================
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'config.php 不存在，请复制 config.example.php']);
    exit;
}
require __DIR__ . '/config.php';

// ==================== 数据库连接 ====================
try {
    $dsn = "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4";
    $db = new PDO($dsn, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'数据库连接失败']);
    exit;
}

// ==================== 确保公共频道存在 ====================
$stmt = $db->prepare("SELECT COUNT(*) FROM `groups` WHERE id = '1'");
$stmt->execute();
if ($stmt->fetchColumn() == 0) {
    $db->prepare("INSERT INTO `groups` (id, name, creator, is_official, created_at) VALUES ('1', '公共频道', 'system', 1, ?)")
       ->execute([time()]);
}

// ==================== WebSocket 推送 ====================
function wsPush($groupId, $event, $payload, $exclude = null) {
    global $WS_INTERNAL_SECRET, $WS_PUSH_URL;
    $ch = curl_init($WS_PUSH_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'secret'   => $WS_INTERNAL_SECRET,
        'group_id' => $groupId,
        'event'    => $event,
        'payload'  => $payload,
        'exclude'  => $exclude
    ], JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_exec($ch);
    curl_close($ch);
}

// ==================== Token 工具 ====================
function issueToken($username) {
    global $db, $TOKEN_TTL;
    $plain = bin2hex(random_bytes(32));
    $hash = hash('sha256', $plain);
    $expires = time() + $TOKEN_TTL;
    $db->prepare("UPDATE users SET token_hash = ?, token_expires_at = ? WHERE username = ?")
       ->execute([$hash, $expires, $username]);
    return $plain;
}

function revokeTokens($username) {
    global $db;
    $db->prepare("UPDATE users SET token_hash = NULL, token_expires_at = 0 WHERE username = ?")
       ->execute([$username]);
}

function verifyToken($token) {
    global $db;
    if (empty($token)) return null;
    $parts = explode(' ', $token);
    if (count($parts) !== 2 || $parts[0] !== 'Bearer') return null;
    $hash = hash('sha256', $parts[1]);
    $stmt = $db->prepare("SELECT username, is_banned, token_expires_at FROM users WHERE token_hash = ?");
    $stmt->execute([$hash]);
    $user = $stmt->fetch();
    if (!$user) return null;
    if ($user['is_banned']) return null;
    if ((int)$user['token_expires_at'] < time()) return null;
    return $user['username'];
}

// ==================== 通用工具 ====================
function getUser($username) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetch();
}

function isAdmin($username) {
    global $db;
    $stmt = $db->query("SELECT COUNT(*) FROM admins");
    if ($stmt->fetchColumn() == 0) {
        $stmt = $db->query("SELECT username FROM users ORDER BY id ASC LIMIT 1");
        $first = $stmt->fetch();
        if ($first) $db->prepare("INSERT IGNORE INTO admins (username) VALUES (?)")->execute([$first['username']]);
    }
    $stmt = $db->prepare("SELECT 1 FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    return (bool)$stmt->fetchColumn();
}

function isGroupAdmin($username, $groupId) {
    global $db;
    $stmt = $db->prepare("SELECT role FROM members WHERE group_id = ? AND username = ?");
    $stmt->execute([$groupId, $username]);
    $row = $stmt->fetch();
    return $row && $row['role'] === 'admin';
}

function canManageGroup($username, $groupId) {
    return isAdmin($username) || isGroupAdmin($username, $groupId);
}

function getGroupMembers($groupId) {
    global $db;
    $stmt = $db->prepare("SELECT username, role, muted_until, joined_at FROM members WHERE group_id = ? ORDER BY joined_at ASC");
    $stmt->execute([$groupId]);
    return $stmt->fetchAll();
}

function addMemberToGroup($username, $groupId, $role = 'member') {
    global $db;
    try {
        $stmt = $db->prepare("INSERT IGNORE INTO members (group_id, username, role, muted_until, joined_at) VALUES (?, ?, ?, 0, ?)");
        $stmt->execute([$groupId, $username, $role, time()]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) { return false; }
}

function addSystemMessage($groupId, $content) {
    global $db;
    $db->prepare("INSERT INTO messages (group_id, username, content, filtered, created_at) VALUES (?, 'System', ?, 0, ?)")
       ->execute([$groupId, $content, time()]);
}

function dismissGroupById($groupId) {
    global $db;
    $db->prepare("DELETE FROM `groups` WHERE id = ?")->execute([$groupId]);
    $db->prepare("DELETE FROM members WHERE group_id = ?")->execute([$groupId]);
    $db->prepare("DELETE FROM messages WHERE group_id = ?")->execute([$groupId]);
}

function filterMessage($content) {
    global $BANNED_WORDS;
    $filtered = $content;
    $found = false;
    foreach ($BANNED_WORDS as $word) {
        if (stripos($filtered, $word) !== false) {
            $filtered = str_ireplace($word, str_repeat('*', mb_strlen($word, 'UTF-8')), $filtered);
            $found = true;
        }
    }
    return ['content'=>$filtered,'has_banned'=>$found];
}

function purgeUser($username) {
    global $db;
    $stmt = $db->prepare("SELECT group_id, role FROM members WHERE username = ?");
    $stmt->execute([$username]);
    $groups = $stmt->fetchAll();
    foreach ($groups as $g) {
        $groupId = $g['group_id'];
        if ($g['role'] === 'admin') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM members WHERE group_id = ? AND username != ? AND role = 'admin'");
            $stmt->execute([$groupId, $username]);
            if ($stmt->fetchColumn() == 0) {
                $stmt = $db->prepare("SELECT username FROM members WHERE group_id = ? AND username != ? ORDER BY joined_at ASC LIMIT 1");
                $stmt->execute([$groupId, $username]);
                $next = $stmt->fetchColumn();
                if ($next) $db->prepare("UPDATE members SET role = 'admin' WHERE group_id = ? AND username = ?")->execute([$groupId, $next]);
            }
        }
        $db->prepare("DELETE FROM members WHERE group_id = ? AND username = ?")->execute([$groupId, $username]);
        $stmt = $db->prepare("SELECT COUNT(*) FROM members WHERE group_id = ?");
        $stmt->execute([$groupId]);
        if ($stmt->fetchColumn() == 0 && $groupId !== '1') {
            dismissGroupById($groupId);
        } else if ($groupId !== '1') {
            addSystemMessage($groupId, "{$username} 已离开");
        }
    }
    $db->prepare("DELETE FROM users WHERE username = ?")->execute([$username]);
    $db->prepare("DELETE FROM admins WHERE username = ?")->execute([$username]);
}

// ==================== 路由 ====================
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_REQUEST['action'] ?? ($input['action'] ?? '');
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$username = verifyToken($token);

switch ($action) {
    case 'register': handleRegister($input); break;
    case 'login': handleLogin($input); break;
    case 'sync':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleSync($username); break;
    case 'send':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleSendMessage($username, $input); break;
    case 'recall_message':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleRecallMessage($username, $input); break;
    case 'create_group':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleCreateGroup($username, $input); break;
    case 'invite_member':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleInviteMember($username, $input); break;
    case 'get_my_groups':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleGetMyGroups($username); break;
    case 'clear_messages':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleClearMessages($username, $input); break;
    case 'clear_all_data':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleClearAllData($username); break;
    case 'kick_member':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleKickMember($username, $input); break;
    case 'leave_group':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleLeaveGroup($username, $input); break;
    case 'dismiss_group':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleDismissGroup($username, $input); break;
    case 'get_members':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleGetMembers($username, $input); break;
    case 'change_password':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleChangePassword($username, $input); break;
    case 'delete_account':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleDeleteAccount($username, $input); break;
    case 'get_all_users':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleGetAllUsers($username); break;
    case 'mute_member':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleMuteMember($username, $input); break;
    case 'unmute_member':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleUnmuteMember($username, $input); break;
    case 'get_preferences':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleGetPreferences($username); break;
    case 'update_preferences':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleUpdatePreferences($username, $input); break;
    case 'ban_user':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleBanUser($username, $input); break;
    case 'unban_user':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleUnbanUser($username, $input); break;
    case 'admin_delete_user':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleAdminDeleteUser($username, $input); break;
    case 'get_admin_stats':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleGetAdminStats($username); break;
    case 'admin_list_groups':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleAdminListGroups($username); break;
    case 'admin_manage_group':
        if (!$username) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
        handleAdminManageGroup($username, $input); break;
    default:
        echo json_encode(['status'=>'error','message'=>'Invalid action']);
}

// ==================== 注册 ====================
function handleRegister($data) {
    global $db;
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';
    if (!preg_match('/^[a-zA-Z0-9_]{3,15}$/', $username)) {
        echo json_encode(['status'=>'error','message'=>'用户名格式不正确（3-15位字母数字下划线）']); return;
    }
    if (strlen($password) < 6) {
        echo json_encode(['status'=>'error','message'=>'密码长度至少6位']); return;
    }
    $stmt = $db->prepare("SELECT 1 FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetchColumn()) {
        echo json_encode(['status'=>'error','message'=>'用户名已存在']); return;
    }
    $db->prepare("INSERT INTO users (username, password, is_banned, preferences, created_at) VALUES (?, ?, 0, ?, ?)")
       ->execute([$username, password_hash($password, PASSWORD_DEFAULT), '{"allow_group_invite":true}', time()]);
    $token = issueToken($username);
    addMemberToGroup($username, '1', 'member');
    $stmt = $db->query("SELECT COUNT(*) FROM admins");
    if ($stmt->fetchColumn() == 0) {
        $db->prepare("INSERT IGNORE INTO admins (username) VALUES (?)")->execute([$username]);
    }
    echo json_encode(['status'=>'success','token'=>$token,'username'=>$username,'is_admin'=>isAdmin($username)]);
}

// ==================== 登录 ====================
function handleLogin($data) {
    global $db;
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user) { echo json_encode(['status'=>'error','message'=>'用户不存在']); return; }
    if ($user['is_banned']) { echo json_encode(['status'=>'error','message'=>'账户已被封禁']); return; }
    if (!password_verify($password, $user['password'])) { echo json_encode(['status'=>'error','message'=>'密码错误']); return; }
    $newToken = issueToken($username);
    addMemberToGroup($username, '1', 'member');
    echo json_encode(['status'=>'success','token'=>$newToken,'username'=>$username,'is_admin'=>isAdmin($username)]);
}

// ==================== 同步 ====================
function handleSync($username) {
    global $db;
    $groupId = $_GET['group_id'] ?? '1';
    $lastMsgId = intval($_GET['last_msg_id'] ?? 0);
    $members = getGroupMembers($groupId);
    $isMember = false; $myMutedUntil = 0;
    foreach ($members as $m) {
        if ($m['username'] === $username) { $isMember = true; $myMutedUntil = (int)($m['muted_until'] ?? 0); break; }
    }
    if (!$isMember && $groupId !== '1') {
        echo json_encode(['status'=>'success','messages'=>[],'members'=>[],'is_member'=>false,'is_admin'=>isAdmin($username),'is_group_admin'=>false,'is_muted'=>false,'muted_until'=>0]);
        return;
    }
    $stmt = $db->prepare("SELECT id, username, content, filtered, created_at, is_recalled, recalled_by FROM messages WHERE group_id = ? AND id > ? ORDER BY id ASC LIMIT 200");
    $stmt->execute([$groupId, $lastMsgId]);
    $rows = $stmt->fetchAll();
    $messages = [];
    foreach ($rows as $row) {
        $messages[] = [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'content' => $row['is_recalled'] ? '' : $row['content'],
            'time' => date('H:i:s', (int)$row['created_at']),
            'timestamp' => (int)$row['created_at'],
            'isMe' => ($row['username'] === $username),
            'filtered' => (bool)$row['filtered'],
            'is_recalled' => (bool)$row['is_recalled'],
            'recalled_by' => $row['recalled_by'],
        ];
    }
    echo json_encode([
        'status'=>'success','messages'=>$messages,'members'=>$members,
        'is_member'=>$isMember,'is_admin'=>isAdmin($username),
        'is_group_admin'=>isGroupAdmin($username, $groupId),
        'is_muted'=>($myMutedUntil > time()),'muted_until'=>$myMutedUntil
    ]);
}

// ==================== 发送消息 ====================
function handleSendMessage($username, $data) {
    global $db;
    $groupId = $data['group_id'] ?? '1';
    $content = $data['content'] ?? '';
    $replyTo = intval($data['reply_to'] ?? 0);
    if (empty(trim($content))) { echo json_encode(['status'=>'error','message'=>'消息不能为空']); return; }

    $members = getGroupMembers($groupId);
    $isMember = false; $myMutedUntil = 0;
    foreach ($members as $m) {
        if ($m['username'] === $username) { $isMember = true; $myMutedUntil = (int)($m['muted_until'] ?? 0); break; }
    }
    if (!$isMember && $groupId !== '1') { echo json_encode(['status'=>'error','message'=>'您不在该群中']); return; }
    if ($myMutedUntil > time()) {
        echo json_encode(['status'=>'error','message'=>'您已被禁言，剩余 '.($myMutedUntil-time()).' 秒']); return;
    }
    $r = filterMessage($content);
    $db->prepare("INSERT INTO messages (group_id, username, content, filtered, reply_to, created_at) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$groupId, $username, htmlspecialchars($r['content'], ENT_QUOTES, 'UTF-8'), $r['has_banned']?1:0, $replyTo, time()]);
    $msgId = (int)$db->lastInsertId();

    wsPush($groupId, 'new-message', [
        'id' => $msgId,
        'username' => $username,
        'content' => $r['content'],
        'time' => date('H:i:s'),
        'filtered' => $r['has_banned'],
        'reply_to' => $replyTo
    ], $username);

    echo $r['has_banned']
        ? json_encode(['status'=>'success','warning'=>'消息包含敏感词，已被过滤','id'=>$msgId])
        : json_encode(['status'=>'success','id'=>$msgId]);
}

// ==================== 撤回消息 ====================
function handleRecallMessage($username, $data) {
    global $db, $RECALL_WINDOW;
    $msgId = intval($data['msg_id'] ?? 0);
    if ($msgId <= 0) { echo json_encode(['status'=>'error','message'=>'消息ID无效']); return; }

    $stmt = $db->prepare("SELECT id, group_id, username, created_at, is_recalled FROM messages WHERE id = ?");
    $stmt->execute([$msgId]);
    $msg = $stmt->fetch();
    if (!$msg) { echo json_encode(['status'=>'error','message'=>'消息不存在']); return; }
    if ($msg['is_recalled']) { echo json_encode(['status'=>'error','message'=>'消息已被撤回']); return; }

    $isMine = ($msg['username'] === $username);
    $isSuper = isAdmin($username);
    $isGroupAdminFlag = isGroupAdmin($username, $msg['group_id']);

    if (!$isSuper && !$isGroupAdminFlag) {
        if (!$isMine) { echo json_encode(['status'=>'error','message'=>'无权撤回他人消息']); return; }
        if (time() - (int)$msg['created_at'] > $RECALL_WINDOW) {
            echo json_encode(['status'=>'error','message'=>'超过'.($RECALL_WINDOW/60).'分钟，无法撤回']); return;
        }
    }

    $db->prepare("UPDATE messages SET is_recalled = 1, recalled_at = ?, recalled_by = ? WHERE id = ?")
       ->execute([time(), $username, $msgId]);

    wsPush($msg['group_id'], 'message-recalled', ['id' => $msgId]);

    $operator = $isSuper ? '超管' : ($isGroupAdminFlag ? '群管' : '本人');
    addSystemMessage($msg['group_id'], "{$msg['username']} 的消息被 {$operator} {$username} 撤回");

    echo json_encode(['status'=>'success','message'=>'已撤回']);
}

// ==================== 创建群组 ====================
function handleCreateGroup($username, $data) {
    global $db;
    $name = trim($data['name'] ?? '新群组');
    if (empty($name)) $name = '新群组';
    $groupId = uniqid('grp_');
    $db->prepare("INSERT INTO `groups` (id, name, creator, is_official, created_at) VALUES (?, ?, ?, 0, ?)")
       ->execute([$groupId, $name, $username, time()]);
    $db->prepare("INSERT INTO members (group_id, username, role, muted_until, joined_at) VALUES (?, ?, 'admin', 0, ?)")
       ->execute([$groupId, $username, time()]);
    echo json_encode(['status'=>'success','group_id'=>$groupId,'name'=>$name]);
}

// ==================== 邀请成员 ====================
function handleInviteMember($inviter, $data) {
    global $db;
    $groupId = $data['group_id'] ?? '1';
    $targetUser = $data['target_user'] ?? '';
    if (empty($targetUser)) { echo json_encode(['status'=>'error','message'=>'用户名不能为空']); return; }
    $stmt = $db->prepare("SELECT is_official FROM `groups` WHERE id = ?");
    $stmt->execute([$groupId]);
    $isOfficial = (bool)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT 1 FROM members WHERE group_id = ? AND username = ?");
    $stmt->execute([$groupId, $inviter]);
    if (!$stmt->fetchColumn()) { echo json_encode(['status'=>'error','message'=>'您不在该群中']); return; }
    $target = getUser($targetUser);
    if (!$target) { echo json_encode(['status'=>'error','message'=>'用户不存在']); return; }
    if (!$isOfficial) {
        $prefs = json_decode($target['preferences'] ?? '{}', true) ?: [];
        if (isset($prefs['allow_group_invite']) && !$prefs['allow_group_invite']) {
            echo json_encode(['status'=>'error','message'=>'对方设置了拒绝非官方群邀请']); return;
        }
    }
    $stmt = $db->prepare("SELECT 1 FROM members WHERE group_id = ? AND username = ?");
    $stmt->execute([$groupId, $targetUser]);
    if ($stmt->fetchColumn()) { echo json_encode(['status'=>'error','message'=>'该用户已在群内']); return; }
    $db->prepare("INSERT INTO members (group_id, username, role, muted_until, joined_at) VALUES (?, ?, 'member', 0, ?)")
       ->execute([$groupId, $targetUser, time()]);
    addSystemMessage($groupId, "{$targetUser} 被 {$inviter} 邀请入群");
    echo json_encode(['status'=>'success']);
}

// ==================== 获取我的群组 ====================
function handleGetMyGroups($username) {
    global $db;
    $stmt = $db->prepare("SELECT g.id, g.name, g.creator, g.is_official, g.created_at, m.role AS my_role, m.muted_until FROM `groups` g JOIN members m ON m.group_id = g.id WHERE m.username = ? ORDER BY g.is_official DESC, g.created_at ASC");
    $stmt->execute([$username]);
    $rows = $stmt->fetchAll();
    $myGroups = [];
    foreach ($rows as $row) {
        $myGroups[] = [
            'id' => $row['id'],'name' => $row['name'],'creator' => $row['creator'],
            'is_official' => (bool)$row['is_official'],
            'is_creator' => ($row['creator'] === $username),
            'my_role' => $row['my_role'],
            'is_muted' => ((int)$row['muted_until'] > time())
        ];
    }
    if (empty($myGroups)) {
        addMemberToGroup($username, '1', 'member');
        $myGroups[] = ['id'=>'1','name'=>'公共频道','creator'=>'system','is_official'=>true,'is_creator'=>false,'my_role'=>'member','is_muted'=>false];
    }
    echo json_encode(['status'=>'success','groups'=>$myGroups]);
}

// ==================== 获取群成员 ====================
function handleGetMembers($username, $data) {
    $groupId = $data['group_id'] ?? '1';
    $members = getGroupMembers($groupId);
    $isMember = false;
    foreach ($members as $m) { if ($m['username'] === $username) { $isMember = true; break; } }
    if (!$isMember && $groupId !== '1') { echo json_encode(['status'=>'error','message'=>'您不在该群中']); return; }
    echo json_encode(['status'=>'success','members'=>$members,'is_group_admin'=>isGroupAdmin($username, $groupId)]);
}

// ==================== 清空当前群消息 ====================
function handleClearMessages($username, $data) {
    global $db;
    $groupId = $data['group_id'] ?? '1';
    if (!canManageGroup($username, $groupId)) {
        echo json_encode(['status'=>'error','message'=>'没有权限']); return;
    }
    $db->prepare("DELETE FROM messages WHERE group_id = ?")->execute([$groupId]);
    addSystemMessage($groupId, "管理员 {$username} 清空了本群聊天记录");
    wsPush($groupId, 'messages-cleared', ['by' => $username]);
    echo json_encode(['status'=>'success','message'=>'聊天记录已清空']);
}

// ==================== 清空全部数据 ====================
function handleClearAllData($username) {
    global $db;
    if (!isAdmin($username)) { echo json_encode(['status'=>'error','message'=>'只有超级管理员可以执行']); return; }
    $db->exec("DELETE FROM messages");
    $db->exec("DELETE FROM members");
    $db->exec("DELETE FROM `groups` WHERE id != '1'");
    $stmt = $db->prepare("SELECT COUNT(*) FROM `groups` WHERE id = '1'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $db->prepare("INSERT INTO `groups` (id, name, creator, is_official, created_at) VALUES ('1', '公共频道', 'system', 1, ?)")->execute([time()]);
    }
    $db->prepare("INSERT IGNORE INTO members (group_id, username, role, muted_until, joined_at) VALUES ('1', ?, 'admin', 0, ?)")->execute([$username, time()]);
    echo json_encode(['status'=>'success','message'=>'全部数据已清空']);
}

// ==================== 踢出成员 ====================
function handleKickMember($username, $data) {
    global $db;
    $groupId = $data['group_id'] ?? '1';
    $targetUser = $data['target_user'] ?? '';
    if (empty($targetUser)) { echo json_encode(['status'=>'error','message'=>'用户名不能为空']); return; }
    if (!canManageGroup($username, $groupId)) {
        echo json_encode(['status'=>'error','message'=>'没有权限踢出成员']); return;
    }
    if ($targetUser === $username) { echo json_encode(['status'=>'error','message'=>'不能踢出自己']); return; }
    $stmt = $db->prepare("SELECT role FROM members WHERE group_id = ? AND username = ?");
    $stmt->execute([$groupId, $targetUser]);
    $targetRole = $stmt->fetchColumn();
    if (!$targetRole) { echo json_encode(['status'=>'error','message'=>'该用户不在群内']); return; }
    if ($targetRole === 'admin' && !isAdmin($username)) {
        echo json_encode(['status'=>'error','message'=>'不能踢出管理员']); return;
    }
    $db->prepare("DELETE FROM members WHERE group_id = ? AND username = ?")->execute([$groupId, $targetUser]);
    addSystemMessage($groupId, "{$targetUser} 被 {$username} 移出群聊");
    wsPush($groupId, 'member-kicked', ['target' => $targetUser, 'by' => $username]);
    echo json_encode(['status'=>'success','message'=>'已踢出成员']);
}

// ==================== 退出群聊 ====================
function handleLeaveGroup($username, $data) {
    global $db;
    $groupId = $data['group_id'] ?? '1';
    if ($groupId === '1') { echo json_encode(['status'=>'error','message'=>'不能退出公共频道']); return; }
    $stmt = $db->prepare("SELECT role FROM members WHERE group_id = ? AND username = ?");
    $stmt->execute([$groupId, $username]);
    $myRole = $stmt->fetchColumn();
    if (!$myRole) { echo json_encode(['status'=>'error','message'=>'您不在该群中']); return; }
    $stmt = $db->prepare("SELECT COUNT(*) FROM members WHERE group_id = ?");
    $stmt->execute([$groupId]);
    $total = $stmt->fetchColumn();
    if ($myRole === 'admin' && $total > 1) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM members WHERE group_id = ? AND role = 'admin' AND username != ?");
        $stmt->execute([$groupId, $username]);
        if ($stmt->fetchColumn() == 0) {
            $stmt = $db->prepare("SELECT username FROM members WHERE group_id = ? AND username != ? ORDER BY joined_at ASC LIMIT 1");
            $stmt->execute([$groupId, $username]);
            $next = $stmt->fetchColumn();
            if ($next) $db->prepare("UPDATE members SET role = 'admin' WHERE group_id = ? AND username = ?")->execute([$groupId, $next]);
        }
    }
    $db->prepare("DELETE FROM members WHERE group_id = ? AND username = ?")->execute([$groupId, $username]);
    $stmt = $db->prepare("SELECT COUNT(*) FROM members WHERE group_id = ?");
    $stmt->execute([$groupId]);
    if ($stmt->fetchColumn() == 0) {
        dismissGroupById($groupId);
        echo json_encode(['status'=>'success','message'=>'群聊已空，自动解散']); return;
    }
    addSystemMessage($groupId, "{$username} 退出了群聊");
    echo json_encode(['status'=>'success','message'=>'已退出群聊']);
}

// ==================== 解散群组 ====================
function handleDismissGroup($username, $data) {
    global $db;
    $groupId = $data['group_id'] ?? '1';
    if ($groupId === '1') { echo json_encode(['status'=>'error','message'=>'不能解散公共频道']); return; }
    $stmt = $db->prepare("SELECT creator FROM `groups` WHERE id = ?");
    $stmt->execute([$groupId]);
    $creator = $stmt->fetchColumn();
    if ($creator === false) { echo json_encode(['status'=>'error','message'=>'群组不存在']); return; }
    if (!canManageGroup($username, $groupId) && $creator !== $username) {
        echo json_encode(['status'=>'error','message'=>'没有权限解散该群']); return;
    }
    dismissGroupById($groupId);
    wsPush($groupId, 'group-dismissed', ['by' => $username]);
    echo json_encode(['status'=>'success','message'=>'群组已解散']);
}

// ==================== 修改密码 ====================
function handleChangePassword($username, $data) {
    global $db;
    $old = $data['old_password'] ?? '';
    $new = $data['new_password'] ?? '';
    if (strlen($new) < 6) { echo json_encode(['status'=>'error','message'=>'新密码长度至少6位']); return; }
    $user = getUser($username);
    if (!$user) { echo json_encode(['status'=>'error','message'=>'用户不存在']); return; }
    if (!password_verify($old, $user['password'])) { echo json_encode(['status'=>'error','message'=>'原密码错误']); return; }
    $db->prepare("UPDATE users SET password = ? WHERE username = ?")
       ->execute([password_hash($new, PASSWORD_DEFAULT), $username]);
    revokeTokens($username);
    echo json_encode(['status'=>'success','message'=>'密码修改成功，请重新登录']);
}

// ==================== 注销账户 ====================
function handleDeleteAccount($username, $data) {
    $password = $data['password'] ?? '';
    $user = getUser($username);
    if (!$user) { echo json_encode(['status'=>'error','message'=>'用户不存在']); return; }
    if (!password_verify($password, $user['password'])) { echo json_encode(['status'=>'error','message'=>'密码错误']); return; }
    purgeUser($username);
    echo json_encode(['status'=>'success','message'=>'账户已注销']);
}

// ==================== 获取所有用户 ====================
function handleGetAllUsers($username) {
    global $db;
    $stmt = $db->query("SELECT username, created_at, is_banned FROM users ORDER BY id ASC");
    $rows = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'username' => $row['username'],
            'created_at' => (int)$row['created_at'],
            'is_admin' => isAdmin($row['username']),
            'is_banned' => (bool)$row['is_banned']
        ];
    }
    echo json_encode(['status'=>'success','users'=>$result,'total'=>count($result)]);
}

// ==================== 禁言 ====================
function handleMuteMember($username, $data) {
    global $db;
    $groupId = $data['group_id'] ?? '1';
    $targetUser = $data['target_user'] ?? '';
    $duration = intval($data['duration'] ?? 300);
    if (empty($targetUser)) { echo json_encode(['status'=>'error','message'=>'用户名不能为空']); return; }
    if ($targetUser === $username) { echo json_encode(['status'=>'error','message'=>'不能禁言自己']); return; }
    if (!canManageGroup($username, $groupId)) {
        echo json_encode(['status'=>'error','message'=>'没有权限禁言成员']); return;
    }
    $stmt = $db->prepare("SELECT role FROM members WHERE group_id = ? AND username = ?");
    $stmt->execute([$groupId, $targetUser]);
    $targetRole = $stmt->fetchColumn();
    if (!$targetRole) { echo json_encode(['status'=>'error','message'=>'该用户不在群内']); return; }
    if ($targetRole === 'admin' && !isAdmin($username)) {
        echo json_encode(['status'=>'error','message'=>'不能禁言管理员']); return;
    }
    $mutedUntil = time() + $duration;
    $db->prepare("UPDATE members SET muted_until = ? WHERE group_id = ? AND username = ?")->execute([$mutedUntil, $groupId, $targetUser]);
    $txt = $duration >= 3600 ? round($duration/3600,1).'小时' : ($duration >= 60 ? round($duration/60).'分钟' : $duration.'秒');
    addSystemMessage($groupId, "{$targetUser} 被 {$username} 禁言 {$txt}");
    wsPush($groupId, 'member-muted', ['target'=>$targetUser,'by'=>$username,'duration'=>$duration]);
    echo json_encode(['status'=>'success','message'=>"已禁言 {$targetUser} {$txt}"]);
}

// ==================== 解除禁言 ====================
function handleUnmuteMember($username, $data) {
    global $db;
    $groupId = $data['group_id'] ?? '1';
    $targetUser = $data['target_user'] ?? '';
    if (empty($targetUser)) { echo json_encode(['status'=>'error','message'=>'用户名不能为空']); return; }
    if (!canManageGroup($username, $groupId)) {
        echo json_encode(['status'=>'error','message'=>'没有权限解除禁言']); return;
    }
    $stmt = $db->prepare("SELECT 1 FROM members WHERE group_id = ? AND username = ?");
    $stmt->execute([$groupId, $targetUser]);
    if (!$stmt->fetchColumn()) { echo json_encode(['status'=>'error','message'=>'该用户不在群内']); return; }
    $db->prepare("UPDATE members SET muted_until = 0 WHERE group_id = ? AND username = ?")->execute([$groupId, $targetUser]);
    addSystemMessage($groupId, "{$targetUser} 已被 {$username} 解除禁言");
    echo json_encode(['status'=>'success','message'=>'已解除禁言']);
}

// ==================== 偏好设置 ====================
function handleGetPreferences($username) {
    $user = getUser($username);
    if (!$user) { echo json_encode(['status'=>'error','message'=>'用户不存在']); return; }
    $prefs = json_decode($user['preferences'] ?? '{}', true) ?: [];
    if (!isset($prefs['allow_group_invite'])) $prefs['allow_group_invite'] = true;
    echo json_encode(['status'=>'success','preferences'=>$prefs]);
}

function handleUpdatePreferences($username, $data) {
    global $db;
    $user = getUser($username);
    if (!$user) { echo json_encode(['status'=>'error','message'=>'用户不存在']); return; }
    $prefs = json_decode($user['preferences'] ?? '{}', true) ?: [];
    if (!isset($prefs['allow_group_invite'])) $prefs['allow_group_invite'] = true;
    if (isset($data['allow_group_invite'])) $prefs['allow_group_invite'] = (bool)$data['allow_group_invite'];
    $db->prepare("UPDATE users SET preferences = ? WHERE username = ?")->execute([json_encode($prefs, JSON_UNESCAPED_UNICODE), $username]);
    echo json_encode(['status'=>'success','preferences'=>$prefs,'message'=>'偏好设置已保存']);
}

// ==================== 后台：封号 ====================
function handleBanUser($operator, $data) {
    global $db;
    if (!isAdmin($operator)) { echo json_encode(['status'=>'error','message'=>'只有超级管理员可以封号']); return; }
    $target = trim($data['target_user'] ?? '');
    if (empty($target)) { echo json_encode(['status'=>'error','message'=>'用户名不能为空']); return; }
    if ($target === $operator) { echo json_encode(['status'=>'error','message'=>'不能封禁自己']); return; }
    $user = getUser($target);
    if (!$user) { echo json_encode(['status'=>'error','message'=>'用户不存在']); return; }
    if (isAdmin($target)) { echo json_encode(['status'=>'error','message'=>'不能封禁管理员']); return; }
    $db->prepare("UPDATE users SET is_banned = 1 WHERE username = ?")->execute([$target]);
    revokeTokens($target);
    echo json_encode(['status'=>'success','message'=>"已封禁 {$target}"]);
}

// ==================== 后台：解封 ====================
function handleUnbanUser($operator, $data) {
    global $db;
    if (!isAdmin($operator)) { echo json_encode(['status'=>'error','message'=>'只有超级管理员可以解封']); return; }
    $target = trim($data['target_user'] ?? '');
    if (empty($target)) { echo json_encode(['status'=>'error','message'=>'用户名不能为空']); return; }
    $db->prepare("UPDATE users SET is_banned = 0 WHERE username = ?")->execute([$target]);
    echo json_encode(['status'=>'success','message'=>"已解封 {$target}"]);
}

// ==================== 后台：强制注销 ====================
function handleAdminDeleteUser($operator, $data) {
    global $db;
    if (!isAdmin($operator)) { echo json_encode(['status'=>'error','message'=>'只有超级管理员可以注销账户']); return; }
    $target = trim($data['target_user'] ?? '');
    if (empty($target)) { echo json_encode(['status'=>'error','message'=>'用户名不能为空']); return; }
    if ($target === $operator) { echo json_encode(['status'=>'error','message'=>'不能注销自己']); return; }
    $user = getUser($target);
    if (!$user) { echo json_encode(['status'=>'error','message'=>'用户不存在']); return; }
    if (isAdmin($target)) { echo json_encode(['status'=>'error','message'=>'不能注销管理员']); return; }
    purgeUser($target);
    echo json_encode(['status'=>'success','message'=>"已注销 {$target}"]);
}

// ==================== 后台：统计 ====================
function handleGetAdminStats($operator) {
    global $db;
    if (!isAdmin($operator)) { echo json_encode(['status'=>'error','message'=>'只有超级管理员可以查看']); return; }
    $stmt = $db->query("SELECT COUNT(*) AS c FROM users");
    $totalUsers = (int)$stmt->fetch()['c'];
    $stmt = $db->query("SELECT COUNT(*) AS c FROM users WHERE is_banned = 1");
    $bannedUsers = (int)$stmt->fetch()['c'];
    $stmt = $db->query("SELECT COUNT(*) AS c FROM `groups`");
    $totalGroups = (int)$stmt->fetch()['c'];
    $stmt = $db->query("SELECT COUNT(*) AS c FROM messages WHERE is_recalled = 0");
    $totalMessages = (int)$stmt->fetch()['c'];
    $stmt = $db->query("SELECT COUNT(*) AS c FROM messages WHERE is_recalled = 1");
    $recalledMessages = (int)$stmt->fetch()['c'];
    $stmt = $db->query("SELECT COUNT(*) AS c FROM members WHERE muted_until > UNIX_TIMESTAMP()");
    $mutedMembers = (int)$stmt->fetch()['c'];
    echo json_encode([
        'status' => 'success',
        'stats' => [
            'total_users' => $totalUsers,
            'banned_users' => $bannedUsers,
            'total_groups' => $totalGroups,
            'total_messages' => $totalMessages,
            'recalled_messages' => $recalledMessages,
            'muted_members' => $mutedMembers
        ]
    ]);
}

// ==================== 超管：群聊列表 ====================
function handleAdminListGroups($operator) {
    global $db;
    if (!isAdmin($operator)) { echo json_encode(['status'=>'error','message'=>'只有超级管理员可以查看']); return; }
    $stmt = $db->query("SELECT g.id, g.name, g.creator, g.is_official, g.created_at,
                               (SELECT COUNT(*) FROM members WHERE group_id = g.id) AS member_count,
                               (SELECT COUNT(*) FROM messages WHERE group_id = g.id AND is_recalled = 0) AS message_count
                        FROM `groups` g ORDER BY g.is_official DESC, g.created_at ASC");
    $groups = $stmt->fetchAll();
    foreach ($groups as &$g) {
        $g['is_official'] = (bool)$g['is_official'];
        $g['member_count'] = (int)$g['member_count'];
        $g['message_count'] = (int)$g['message_count'];
    }
    echo json_encode(['status'=>'success','groups'=>$groups]);
}

// ==================== 超管：群聊管理 ====================
function handleAdminManageGroup($operator, $data) {
    global $db;
    if (!isAdmin($operator)) { echo json_encode(['status'=>'error','message'=>'只有超级管理员可以操作']); return; }
    $groupId = $data['group_id'] ?? '';
    $op = $data['op'] ?? '';
    if (empty($groupId) || empty($op)) { echo json_encode(['status'=>'error','message'=>'参数缺失']); return; }

    $stmt = $db->prepare("SELECT id FROM `groups` WHERE id = ?");
    $stmt->execute([$groupId]);
    if (!$stmt->fetchColumn()) { echo json_encode(['status'=>'error','message'=>'群组不存在']); return; }

    switch ($op) {
        case 'kick':
            $target = trim($data['target_user'] ?? '');
            if (empty($target)) { echo json_encode(['status'=>'error','message'=>'用户名不能为空']); return; }
            if ($target === $operator) { echo json_encode(['status'=>'error','message'=>'不能踢出自己']); return; }
            $db->prepare("DELETE FROM members WHERE group_id = ? AND username = ?")->execute([$groupId, $target]);
            addSystemMessage($groupId, "{$target} 被超管 {$operator} 移出群聊");
            wsPush($groupId, 'member-kicked', ['target'=>$target,'by'=>$operator]);
            echo json_encode(['status'=>'success','message'=>"已踢出 {$target}"]); return;

        case 'mute':
            $target = trim($data['target_user'] ?? '');
            $duration = intval($data['duration'] ?? 300);
            if (empty($target)) { echo json_encode(['status'=>'error','message'=>'用户名不能为空']); return; }
            if ($target === $operator) { echo json_encode(['status'=>'error','message'=>'不能禁言自己']); return; }
            $mutedUntil = time() + $duration;
            $stmt = $db->prepare("UPDATE members SET muted_until = ? WHERE group_id = ? AND username = ?");
            $stmt->execute([$mutedUntil, $groupId, $target]);
            if ($stmt->rowCount() == 0) { echo json_encode(['status'=>'error','message'=>'该用户不在群内']); return; }
            $txt = $duration >= 3600 ? round($duration/3600,1).'小时' : ($duration >= 60 ? round($duration/60).'分钟' : $duration.'秒');
            addSystemMessage($groupId, "{$target} 被超管 {$operator} 禁言 {$txt}");
            wsPush($groupId, 'member-muted', ['target'=>$target,'by'=>$operator,'duration'=>$duration]);
            echo json_encode(['status'=>'success','message'=>"已禁言 {$target} {$txt}"]); return;

        case 'clear':
            $db->prepare("DELETE FROM messages WHERE group_id = ?")->execute([$groupId]);
            addSystemMessage($groupId, "超管 {$operator} 清空了本群聊天记录");
            wsPush($groupId, 'messages-cleared', ['by'=>$operator]);
            echo json_encode(['status'=>'success','message'=>'已清空该群消息']); return;

        case 'dismiss':
            if ($groupId === '1') { echo json_encode(['status'=>'error','message'=>'不能解散公共频道']); return; }
            dismissGroupById($groupId);
            wsPush($groupId, 'group-dismissed', ['by'=>$operator]);
            echo json_encode(['status'=>'success','message'=>'群组已解散']); return;

        default:
            echo json_encode(['status'=>'error','message'=>'不支持的操作']);
    }
}
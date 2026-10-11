SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(32) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    token VARCHAR(64) DEFAULT NULL,
    token_hash VARCHAR(64) DEFAULT NULL,
    token_expires_at INT UNSIGNED DEFAULT 0,
    is_banned TINYINT(1) DEFAULT 0,
    preferences TEXT,
    created_at INT UNSIGNED,
    INDEX idx_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
    username VARCHAR(32) PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `groups` (
    id VARCHAR(64) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    creator VARCHAR(32),
    is_official TINYINT(1) DEFAULT 0,
    created_at INT UNSIGNED,
    INDEX idx_official (is_official)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id VARCHAR(64) NOT NULL,
    username VARCHAR(32) NOT NULL,
    role ENUM('admin','member') DEFAULT 'member',
    muted_until INT UNSIGNED DEFAULT 0,
    joined_at INT UNSIGNED,
    UNIQUE KEY uniq_group_user (group_id, username),
    INDEX idx_group (group_id),
    INDEX idx_user (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id VARCHAR(64) NOT NULL,
    username VARCHAR(32) NOT NULL,
    content TEXT NOT NULL,
    filtered TINYINT(1) DEFAULT 0,
    is_recalled TINYINT(1) DEFAULT 0,
    recalled_at INT UNSIGNED DEFAULT 0,
    recalled_by VARCHAR(32) DEFAULT NULL,
    reply_to BIGINT UNSIGNED DEFAULT 0,
    created_at INT UNSIGNED,
    INDEX idx_group_msg (group_id, id),
    INDEX idx_msg_recalled (group_id, is_recalled, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `groups` (id, name, creator, is_official, created_at)
VALUES ('1', '公共频道', 'system', 1, UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE name = name;
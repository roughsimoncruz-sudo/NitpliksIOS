CREATE DATABASE IF NOT EXISTS nitpliks_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE nitpliks_db;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(30) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    admin_role ENUM('owner', 'moderator', 'analyst') NOT NULL DEFAULT 'owner',
    suspended TINYINT(1) NOT NULL DEFAULT 0,
    avatar_url VARCHAR(500) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY users_username_unique (username),
    UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS featured_content (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title_id INT UNSIGNED NOT NULL,
    media_type ENUM('movie', 'tv') NOT NULL,
    title VARCHAR(255) NOT NULL,
    image_url VARCHAR(500) NOT NULL DEFAULT '',
    note VARCHAR(255) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY featured_title_unique (title_id, media_type),
    CONSTRAINT featured_created_by_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS content_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reporter_id INT UNSIGNED NULL,
    target_type ENUM('review', 'comment', 'title') NOT NULL,
    target_key VARCHAR(120) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    status ENUM('open', 'resolved', 'dismissed') NOT NULL DEFAULT 'open',
    resolved_by INT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY reports_status_created (status, created_at),
    CONSTRAINT reports_reporter_fk FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT reports_resolved_by_fk FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id INT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    target_type VARCHAR(40) NOT NULL DEFAULT '',
    target_key VARCHAR(120) NOT NULL DEFAULT '',
    details VARCHAR(500) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY admin_audit_created (created_at),
    CONSTRAINT admin_audit_admin_fk FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_library (
    user_id INT UNSIGNED NOT NULL,
    collection ENUM('watchlist', 'reviews', 'progress') NOT NULL,
    item_key VARCHAR(100) NOT NULL,
    payload JSON NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    share_token CHAR(48) NULL,
    PRIMARY KEY (user_id, collection, item_key),
    CONSTRAINT user_library_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_collections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    is_public TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY user_collection_name (user_id, name),
    UNIQUE KEY user_collection_share_token (share_token),
    CONSTRAINT user_collections_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_collection_items (
    collection_id INT UNSIGNED NOT NULL,
    item_key VARCHAR(100) NOT NULL,
    payload JSON NOT NULL,
    added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (collection_id, item_key),
    CONSTRAINT collection_items_collection_fk FOREIGN KEY (collection_id) REFERENCES user_collections(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    message VARCHAR(500) NOT NULL,
    link VARCHAR(500) NOT NULL DEFAULT '',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY notifications_user_created (user_id, created_at),
    CONSTRAINT notifications_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS visitor_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,
    device_type VARCHAR(20) NOT NULL,
    country_code CHAR(2) NOT NULL DEFAULT '',
    city VARCHAR(100) NOT NULL DEFAULT '',
    region VARCHAR(100) NOT NULL DEFAULT '',
    user_agent VARCHAR(1000) NOT NULL,
    page_url VARCHAR(500) NOT NULL DEFAULT '',
    referrer VARCHAR(500) NOT NULL DEFAULT '',
    visited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY visitor_logs_visited_at (visited_at),
    KEY visitor_logs_ip (ip_address)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(190) NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    first_attempt_at DATETIME NOT NULL,
    locked_until DATETIME NULL,
    PRIMARY KEY (ip_address, email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS security_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type VARCHAR(40) NOT NULL,
    email VARCHAR(190) NOT NULL DEFAULT '',
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY security_events_created (created_at),
    KEY security_events_type (event_type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY password_reset_token_hash (token_hash),
    KEY password_reset_user (user_id, expires_at),
    CONSTRAINT password_reset_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ip_bans (
    ip_address VARCHAR(45) NOT NULL,
    reason VARCHAR(255) NOT NULL DEFAULT '',
    banned_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ip_address),
    KEY ip_bans_created (created_at),
    CONSTRAINT ip_bans_user_fk FOREIGN KEY (banned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

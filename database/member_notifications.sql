-- MEMBER NOTIFICATIONS (member app settings, push, email and SMS)
-- Back up the database first, then run this file once. It is safe to re-run.
-- Then schedule cron/member_notifications.php every 10-15 minutes, for example:
-- */10 * * * * /usr/bin/php /path/to/cron/member_notifications.php

-- What each member wants, per category. A missing row means the defaults in includes/member-notify.php.
CREATE TABLE IF NOT EXISTS member_notification_prefs (
    member_id INT NOT NULL,
    category VARCHAR(30) NOT NULL,
    push TINYINT(1) NOT NULL DEFAULT 1,
    email TINYINT(1) NOT NULL DEFAULT 1,
    sms TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (member_id, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phones signed in to the member app that can receive push notifications (Firebase Cloud Messaging tokens).
CREATE TABLE IF NOT EXISTS member_push_devices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    user_id INT NOT NULL,
    api_token_id INT UNSIGNED NOT NULL,
    fcm_token VARCHAR(255) NOT NULL,
    platform VARCHAR(20) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NULL,
    UNIQUE KEY uq_member_push_token (fcm_token),
    INDEX idx_member_push_member (member_id),
    INDEX idx_member_push_api_token (api_token_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per notification per member: the in-app list and the delivery queue.
-- Channel status: pending, sent, failed, skipped (no phone/email/device) or off (member turned it off).
CREATE TABLE IF NOT EXISTS member_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    category VARCHAR(30) NOT NULL,
    ref_key VARCHAR(100) NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    push_status VARCHAR(10) NOT NULL DEFAULT 'pending',
    email_status VARCHAR(10) NOT NULL DEFAULT 'pending',
    sms_status VARCHAR(10) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    delivered_at DATETIME NULL,
    read_at DATETIME NULL,
    UNIQUE KEY uq_member_notification_ref (member_id, ref_key),
    INDEX idx_member_notifications_member (member_id, created_at),
    INDEX idx_member_notifications_pending (delivered_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Church-wide items (announcements, events, programs, tips, reminders) already sent to members.
CREATE TABLE IF NOT EXISTS member_notification_sources (
    ref_key VARCHAR(100) NOT NULL PRIMARY KEY,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Small key/value store: last payment/pledge ids seen, cached push access token.
CREATE TABLE IF NOT EXISTS member_notification_state (
    name VARCHAR(60) NOT NULL PRIMARY KEY,
    value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

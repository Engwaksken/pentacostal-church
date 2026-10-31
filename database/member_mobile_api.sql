-- MEMBER MOBILE APP API (api/member/)
-- Members sign in with their membership number or pledge number and their existing portal password.
-- Back up the database first, then run this file once. It is safe to re-run.

-- Sign-in tokens issued to the mobile app. Only a SHA-256 hash of each token is stored.
CREATE TABLE IF NOT EXISTS member_api_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    member_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    device_name VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_member_api_token_hash (token_hash),
    INDEX idx_member_api_tokens_user (user_id),
    INDEX idx_member_api_tokens_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed sign-in attempts, used to lock out repeated guessing per identifier and per IP address.
CREATE TABLE IF NOT EXISTS member_api_login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(80) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    INDEX idx_member_api_attempts_identifier (identifier, attempted_at),
    INDEX idx_member_api_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password reset codes for members who forgot their password. Only a hash of each code is stored.
-- A row is written for every request (user_id is NULL when the number matched no member) so requests can be limited.
CREATE TABLE IF NOT EXISTS member_api_password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(80) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_id INT NULL,
    code_hash VARCHAR(255) NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    INDEX idx_member_api_resets_identifier (identifier, created_at),
    INDEX idx_member_api_resets_ip (ip_address, created_at),
    INDEX idx_member_api_resets_user (user_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

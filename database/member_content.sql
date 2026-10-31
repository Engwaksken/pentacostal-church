-- MEMBER CONTENT: DEPARTMENT TIPS + BIBLE VERSES + ORDER OF SERVICE + CHURCH HYMNS
-- Back up the database first, then run this file once. It is safe to re-run.
--
-- Access is granted through Users > Permissions (role or user level). Admins have every permission.
-- Member accounts receive fixed read-only access in includes/access.php and cannot be granted manage rights.
--
-- No Bible text or hymn lyrics are seeded. Staff enter content themselves and must only enter text the
-- church may reproduce (public domain, licensed, or used with permission).

-- ============================================================
-- HEALTH & EDUCATION DEPARTMENT TIPS
-- ============================================================

CREATE TABLE IF NOT EXISTS department_tips (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department ENUM('health','education') NOT NULL,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    status ENUM('Draft','Published') NOT NULL DEFAULT 'Draft',
    published_at DATETIME NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tips_department_status (department, status),
    INDEX idx_tips_published (published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BIBLE VERSES (member welcome dashboard)
-- ============================================================

CREATE TABLE IF NOT EXISTS bible_verses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(120) NOT NULL,
    verse_text TEXT NOT NULL,
    translation VARCHAR(80) NOT NULL,
    display_date DATE NULL,
    status ENUM('Draft','Published') NOT NULL DEFAULT 'Draft',
    published_at DATETIME NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_verses_status_date (status, display_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- HYMNS
-- ============================================================

CREATE TABLE IF NOT EXISTS church_hymns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hymn_number VARCHAR(20) NULL,
    title VARCHAR(200) NOT NULL,
    hymn_book VARCHAR(150) NULL,
    language VARCHAR(80) NULL,
    author VARCHAR(180) NULL,
    tune VARCHAR(180) NULL,
    theme VARCHAR(120) NULL,
    scripture_reference VARCHAR(180) NULL,
    lyrics MEDIUMTEXT NULL,
    copyright_status ENUM('Not Entered','Public Domain','Licensed','Permission Granted') NOT NULL DEFAULT 'Not Entered',
    copyright_notice VARCHAR(255) NULL,
    study_notes TEXT NULL,
    status ENUM('Draft','Published') NOT NULL DEFAULT 'Draft',
    published_at DATETIME NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_hymns_status (status),
    INDEX idx_hymns_number (hymn_number),
    INDEX idx_hymns_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ORDER OF SERVICE
-- ============================================================

CREATE TABLE IF NOT EXISTS service_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    service_type VARCHAR(120) NULL,
    service_date DATE NOT NULL,
    service_time TIME NULL,
    notes TEXT NULL,
    status ENUM('Draft','Published') NOT NULL DEFAULT 'Draft',
    published_at DATETIME NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_service_orders_status_date (status, service_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_order_id INT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    item_title VARCHAR(200) NOT NULL,
    led_by VARCHAR(180) NULL,
    details TEXT NULL,
    hymn_id INT UNSIGNED NULL,
    INDEX idx_soi_order (service_order_id, sort_order),
    INDEX idx_soi_hymn (hymn_id),
    CONSTRAINT fk_soi_service_order
        FOREIGN KEY (service_order_id) REFERENCES service_orders(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PERMISSIONS
-- ============================================================

INSERT INTO permissions(permission_key,permission_name,module_name) VALUES
('health_tips.manage','Create, edit, publish, unpublish and delete Health Department tips','Department Tips'),
('education_tips.manage','Create, edit, publish, unpublish and delete Education Department tips','Department Tips'),
('member.tips.view','View published Health and Education Department tips','Department Tips'),
('bible_verses.manage','Add, edit, publish and delete dashboard Bible verses','Worship Resources'),
('order_of_service.manage','Add, edit, publish and delete Service Programs','Worship Resources'),
('hymns.manage','Add, edit, publish and delete hymns','Worship Resources'),
('member.worship.view','View published Service Programs and church songs','Worship Resources')
ON DUPLICATE KEY UPDATE permission_name=VALUES(permission_name),module_name=VALUES(module_name);

-- Optional example: give a dedicated leader role its department's rights (adjust role names to your users).
-- INSERT INTO role_permissions(role,permission_id,allowed)
--   SELECT 'health_leader', id, 1 FROM permissions WHERE permission_key IN ('dashboard.view','health_tips.manage','member.tips.view')
--   ON DUPLICATE KEY UPDATE allowed=VALUES(allowed);

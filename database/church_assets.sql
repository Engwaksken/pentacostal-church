-- CHURCH ASSETS (church_assets/)
-- Register church property (furniture, instruments, sound and media, vehicles, land and buildings...), who looks after it
-- and where it is, its cost and current value, its maintenance and repairs, and items lent out and returned.
-- Back up the database first, then run this file once. It is safe to re-run.

CREATE TABLE IF NOT EXISTS church_assets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_no VARCHAR(40) NOT NULL,
    name VARCHAR(200) NOT NULL,
    category VARCHAR(60) NOT NULL DEFAULT 'Other',
    description TEXT NULL,
    serial_no VARCHAR(120) NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    location VARCHAR(200) NULL,
    custodian_member_id INT NULL,
    acquisition_type VARCHAR(20) NOT NULL DEFAULT 'Purchased',  -- Purchased, Donated, Built, Transferred
    source_name VARCHAR(200) NULL,                               -- supplier or donor
    acquired_date DATE NULL,
    purchase_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    current_value DECIMAL(14,2) NOT NULL DEFAULT 0,
    warranty_until DATE NULL,
    item_condition VARCHAR(20) NOT NULL DEFAULT 'Good',         -- New, Good, Fair, Poor, Damaged
    status VARCHAR(20) NOT NULL DEFAULT 'In Use',               -- In Use, In Store, Under Repair, Lent Out, Lost, Disposed
    notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_church_assets_no (asset_no),
    INDEX idx_church_assets_category (category),
    INDEX idx_church_assets_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Servicing, repairs and inspections, with what they cost and when the next one is due.
CREATE TABLE IF NOT EXISTS church_asset_maintenance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    maintenance_date DATE NOT NULL,
    maintenance_type VARCHAR(30) NOT NULL DEFAULT 'Service',
    description VARCHAR(255) NULL,
    cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    done_by VARCHAR(150) NULL,
    next_due_date DATE NULL,
    recorded_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_church_asset_maint_asset (asset_id),
    INDEX idx_church_asset_maint_due (next_due_date),
    CONSTRAINT fk_church_asset_maint_asset FOREIGN KEY (asset_id) REFERENCES church_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Items lent to a member or to someone outside the church, and when they came back.
CREATE TABLE IF NOT EXISTS church_asset_loans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    borrower_member_id INT NULL,
    borrower_name VARCHAR(200) NULL,
    borrower_phone VARCHAR(40) NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    loan_date DATE NOT NULL,
    due_date DATE NULL,
    returned_date DATE NULL,
    return_condition VARCHAR(20) NULL,
    purpose VARCHAR(255) NULL,
    recorded_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_church_asset_loans_asset (asset_id),
    INDEX idx_church_asset_loans_open (returned_date),
    CONSTRAINT fk_church_asset_loans_asset FOREIGN KEY (asset_id) REFERENCES church_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions(permission_key,permission_name,module_name) VALUES
('church_assets.view','Register church assets and record their maintenance and loans','Finance & Giving')
ON DUPLICATE KEY UPDATE permission_name=VALUES(permission_name),module_name=VALUES(module_name);

-- Roles that can manage Church Projects get Church Assets too. Adjust in Users > Permissions afterwards if needed.
INSERT INTO role_permissions(role,permission_id,allowed)
  SELECT rp.role, ca.id, rp.allowed
  FROM role_permissions rp
  JOIN permissions p ON p.id=rp.permission_id AND p.permission_key='projects.view'
  JOIN permissions ca ON ca.permission_key='church_assets.view'
  ON DUPLICATE KEY UPDATE allowed=VALUES(allowed);

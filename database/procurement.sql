-- PROCUREMENT (procurement/)
-- Department leaders send purchase requests to the procurement officer and assistant(s), who approve or reject
-- them with a comment and then track the approved ones through ordering and delivery.
-- Back up the database first, then run this file once. It is safe to re-run.

-- Members assigned to procurement: one or more Officers and Assistants.
CREATE TABLE IF NOT EXISTS procurement_team (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'Assistant',   -- Officer, Assistant
    status VARCHAR(20) NOT NULL DEFAULT 'Active',    -- Active, Inactive
    assigned_by INT NULL,                            -- users.id
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_procurement_team_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A request from a department.
CREATE TABLE IF NOT EXISTS procurement_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_no VARCHAR(40) NOT NULL,
    department_id INT NOT NULL,
    requested_by_member_id INT NULL,
    requested_by_user_id INT NULL,
    title VARCHAR(200) NOT NULL,
    justification TEXT NULL,
    priority VARCHAR(20) NOT NULL DEFAULT 'Normal',  -- Low, Normal, High, Urgent
    needed_by DATE NULL,
    estimated_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'Pending',   -- Pending, Approved, Rejected, Ordered, Delivered, Cancelled
    decision_comment TEXT NULL,
    decided_by_member_id INT NULL,
    decided_by_user_id INT NULL,
    decided_at DATETIME NULL,
    supplier VARCHAR(200) NULL,
    actual_cost DECIMAL(14,2) NULL,
    ordered_at DATE NULL,
    delivered_at DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_procurement_requests_no (request_no),
    INDEX idx_procurement_requests_dept (department_id),
    INDEX idx_procurement_requests_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The items on a request.
CREATE TABLE IF NOT EXISTS procurement_request_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    item_name VARCHAR(200) NOT NULL,
    specification VARCHAR(255) NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
    unit VARCHAR(30) NULL,
    unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
    INDEX idx_procurement_items_request (request_id),
    CONSTRAINT fk_procurement_items_request FOREIGN KEY (request_id) REFERENCES procurement_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every step and comment on a request (submitted, approved, rejected, ordered, delivered, cancelled, comment).
CREATE TABLE IF NOT EXISTS procurement_request_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    action VARCHAR(30) NOT NULL,
    comment TEXT NULL,
    member_id INT NULL,
    user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_procurement_log_request (request_id),
    CONSTRAINT fk_procurement_log_request FOREIGN KEY (request_id) REFERENCES procurement_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff permission to see all requests and assign the procurement team (admins always can).
INSERT INTO permissions(permission_key,permission_name,module_name) VALUES
('procurement.manage','Assign the procurement team and manage all procurement requests','Finance & Giving')
ON DUPLICATE KEY UPDATE permission_name=VALUES(permission_name),module_name=VALUES(module_name);

-- Roles that can manage Church Assets get Procurement too. Adjust in Users > Permissions afterwards if needed.
INSERT INTO role_permissions(role,permission_id,allowed)
  SELECT rp.role, pm.id, rp.allowed
  FROM role_permissions rp
  JOIN permissions p ON p.id=rp.permission_id AND p.permission_key='church_assets.view'
  JOIN permissions pm ON pm.permission_key='procurement.manage'
  ON DUPLICATE KEY UPDATE allowed=VALUES(allowed);

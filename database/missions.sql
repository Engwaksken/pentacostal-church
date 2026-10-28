-- MISSIONS & OUTREACH (missions/)
-- Plan and report on missions and outreaches: dates, destination, leader, team, money raised and spent, and results
-- (souls won, rededications, people reached). Back up the database first, then run this file once. It is safe to re-run.

CREATE TABLE IF NOT EXISTS missions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mission_no VARCHAR(40) NOT NULL,
    title VARCHAR(200) NOT NULL,
    mission_type VARCHAR(60) NOT NULL DEFAULT 'Evangelism',
    destination VARCHAR(200) NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    leader_member_id INT NULL,
    budget DECIMAL(14,2) NOT NULL DEFAULT 0,
    objectives TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Planned',  -- Planned, Ongoing, Completed, Cancelled
    souls_won INT UNSIGNED NOT NULL DEFAULT 0,
    rededications INT UNSIGNED NOT NULL DEFAULT 0,
    people_reached INT UNSIGNED NOT NULL DEFAULT 0,
    report TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_missions_no (mission_no),
    INDEX idx_missions_start (start_date),
    INDEX idx_missions_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mission_team (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mission_id INT UNSIGNED NOT NULL,
    member_id INT NOT NULL,
    role VARCHAR(80) NOT NULL DEFAULT 'Team Member',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mission_team_member (mission_id, member_id),
    CONSTRAINT fk_mission_team_mission FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Money raised for a mission (Income) and money spent on it (Expense).
CREATE TABLE IF NOT EXISTS mission_finance (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mission_id INT UNSIGNED NOT NULL,
    entry_type VARCHAR(10) NOT NULL,  -- Income or Expense
    category VARCHAR(80) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    entry_date DATE NOT NULL,
    description VARCHAR(255) NULL,
    recorded_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mission_finance_mission (mission_id),
    CONSTRAINT fk_mission_finance_mission FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions(permission_key,permission_name,module_name) VALUES
('missions.view','Plan missions and outreaches and record their team, finances and results','Church Life')
ON DUPLICATE KEY UPDATE permission_name=VALUES(permission_name),module_name=VALUES(module_name);

-- Roles that can manage Events get Missions too. Adjust in Users > Permissions afterwards if needed.
INSERT INTO role_permissions(role,permission_id,allowed)
  SELECT rp.role, ms.id, rp.allowed
  FROM role_permissions rp
  JOIN permissions e ON e.id=rp.permission_id AND e.permission_key='events.view'
  JOIN permissions ms ON ms.permission_key='missions.view'
  ON DUPLICATE KEY UPDATE allowed=VALUES(allowed);

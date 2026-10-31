<?php
declare(strict_types=1);
function ce_install(mysqli $conn): void {
$conn->set_charset('utf8mb4');
$sqls=[
"CREATE TABLE IF NOT EXISTS church_annual_themes(id INT AUTO_INCREMENT PRIMARY KEY,theme_year YEAR NOT NULL UNIQUE,english_theme TEXT NOT NULL,local_language_name VARCHAR(80) NULL,local_theme TEXT NOT NULL,scripture_reference VARCHAR(180) NULL,explanation LONGTEXT NULL,audience VARCHAR(100) NOT NULL DEFAULT 'All Members',is_active TINYINT(1) NOT NULL DEFAULT 1,created_by INT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS church_harvests(id INT AUTO_INCREMENT PRIMARY KEY,harvest_year YEAR NOT NULL,harvest_name VARCHAR(180) NOT NULL,harvest_category ENUM('Sunday School','General') NOT NULL,target_amount DECIMAL(15,2) NOT NULL DEFAULT 0,amount_collected DECIMAL(15,2) NOT NULL DEFAULT 0,harvest_date DATE NULL,notes TEXT NULL,audience VARCHAR(100) NOT NULL DEFAULT 'All Members',status ENUM('Planning','Open','Completed','Cancelled') NOT NULL DEFAULT 'Open',created_by INT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(harvest_year),INDEX(harvest_category),INDEX(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS church_theme_departments(theme_id INT NOT NULL,department_id INT NOT NULL,PRIMARY KEY(theme_id,department_id),INDEX(department_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS church_harvest_departments(harvest_id INT NOT NULL,department_id INT NOT NULL,PRIMARY KEY(harvest_id,department_id),INDEX(department_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];foreach($sqls as $sql){if(!$conn->query($sql))throw new RuntimeException($conn->error);} }

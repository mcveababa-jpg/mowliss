-- MoWLiSS database schema
-- Mirrors the tables/columns that db.php's ensure_database_schema() creates at runtime.
-- Providing them here means the app starts with the schema already in place instead of
-- building it on the first request.

CREATE DATABASE IF NOT EXISTS `mowliss` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `mowliss`;

CREATE TABLE IF NOT EXISTS `students` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` VARCHAR(100) NOT NULL,
    `first_name` VARCHAR(150) NULL,
    `last_name` VARCHAR(150) NULL,
    `program` VARCHAR(150) NULL,
    `department` VARCHAR(150) NULL,
    `phone_number` VARCHAR(25) NULL,
    `date_of_birth` DATE NULL,
    `password_hash` VARCHAR(255) NULL,
    `remember_token` VARCHAR(255) NULL,
    `terms_agreed` TINYINT(1) NOT NULL DEFAULT 0,
    `is_approved` TINYINT(1) NOT NULL DEFAULT 0,
    `approved_by` VARCHAR(150) NULL,
    `approved_at` TIMESTAMP NULL,
    `account_status` ENUM('pending','approved','waiting','declined') NOT NULL DEFAULT 'pending',
    `is_online` TINYINT(1) NOT NULL DEFAULT 0,
    `last_seen` TIMESTAMP NULL,
    `device_status` ENUM('healthy','lost','locked','offline') NOT NULL DEFAULT 'healthy',
    `location_lat` DECIMAL(10,7) NULL,
    `location_lng` DECIMAL(10,7) NULL,
    `location_label` VARCHAR(255) NULL,
    `last_location_update` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `staff` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `worker_reg_no` VARCHAR(100) NOT NULL,
    `full_name` VARCHAR(150) NULL,
    `department` VARCHAR(150) NULL,
    `compound` VARCHAR(150) NULL,
    `phone_number` VARCHAR(25) NULL,
    `date_of_birth` DATE NULL,
    `password_hash` VARCHAR(255) NULL,
    `remember_token` VARCHAR(255) NULL,
    `terms_agreed` TINYINT(1) NOT NULL DEFAULT 0,
    `is_approved` TINYINT(1) NOT NULL DEFAULT 0,
    `approved_by` VARCHAR(150) NULL,
    `approved_at` TIMESTAMP NULL,
    `account_status` ENUM('pending','approved','waiting','declined') NOT NULL DEFAULT 'pending',
    `is_online` TINYINT(1) NOT NULL DEFAULT 0,
    `last_seen` TIMESTAMP NULL,
    `device_status` ENUM('healthy','lost','locked','offline') NOT NULL DEFAULT 'healthy',
    `location_lat` DECIMAL(10,7) NULL,
    `location_lng` DECIMAL(10,7) NULL,
    `location_label` VARCHAR(255) NULL,
    `last_location_update` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_worker_reg_no` (`worker_reg_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `foremen` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `foreman_reg_no` VARCHAR(100) NOT NULL,
    `first_name` VARCHAR(150) NULL,
    `last_name` VARCHAR(150) NULL,
    `department` VARCHAR(150) NULL,
    `phone_number` VARCHAR(25) NULL,
    `date_of_birth` DATE NULL,
    `password_hash` VARCHAR(255) NULL,
    `remember_token` VARCHAR(255) NULL,
    `terms_agreed` TINYINT(1) NOT NULL DEFAULT 0,
    `is_approved` TINYINT(1) NOT NULL DEFAULT 0,
    `approved_by` VARCHAR(150) NULL,
    `approved_at` TIMESTAMP NULL,
    `account_status` ENUM('pending','approved','waiting','declined') NOT NULL DEFAULT 'pending',
    `is_online` TINYINT(1) NOT NULL DEFAULT 0,
    `last_seen` TIMESTAMP NULL,
    `device_status` ENUM('healthy','lost','locked','offline') NOT NULL DEFAULT 'healthy',
    `location_lat` DECIMAL(10,7) NULL,
    `location_lng` DECIMAL(10,7) NULL,
    `location_label` VARCHAR(255) NULL,
    `last_location_update` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_foreman_reg_no` (`foreman_reg_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `admins` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id` VARCHAR(100) NOT NULL,
    `password_hash` VARCHAR(255) NULL,
    `full_name` VARCHAR(150) NULL,
    `failed_login_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until` DATETIME NULL,
    `remember_token` VARCHAR(255) NULL,
    `terms_agreed` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `login_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_status` ENUM('student','staff','foreman','admin') NOT NULL,
    `identifier` VARCHAR(100) NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `success` TINYINT(1) NOT NULL DEFAULT 0,
    `failure_reason` VARCHAR(255) NULL,
    `attempted_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `password_reset_requests` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_status` ENUM('student','staff','foreman','admin') NOT NULL,
    `user_identifier` VARCHAR(100) NOT NULL,
    `phone_number` VARCHAR(25) NULL,
    `reset_code` VARCHAR(10) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `app_controls` (
    `action_name` VARCHAR(100) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`action_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `app_controls` (`action_name`, `enabled`) VALUES
    ('website_blocker', 0),
    ('remote_lock', 0),
    ('live_location', 0),
    ('url_scanner_activation', 0),
    ('app_turn_off', 0),
    ('app_turn_on', 1),
    ('app_delete', 0),
    ('app_killswitch', 0);

<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
    Connection settings come from environment variables when set (see docker-compose.yml),
    and fall back to XAMPP defaults for local (non-Docker) development:
    Host: 127.0.0.1
    Username: root
    Password: empty
*/
$DB_HOST = env('DB_HOST', '127.0.0.1');
$DB_NAME = env('DB_NAME', 'mowliss');
$DB_USER = env('DB_USER', 'root');
$DB_PASS = env('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    ensure_database_schema();
} catch (PDOException $e) {
    error_log('MoWLiSS Database Connection Error: ' . $e->getMessage());
    http_response_code(500);
    die('Database connection failed. Make sure the MySQL server is running and db.php settings are correct.');
}

function ensure_database_schema(): void
{
    global $pdo;

    $tables = [
        'students' => ['student_id', 'first_name', 'last_name', 'program', 'department', 'phone_number', 'date_of_birth', 'password_hash', 'remember_token', 'terms_agreed', 'is_approved', 'approved_by', 'approved_at', 'account_status', 'is_online', 'last_seen', 'device_status', 'location_lat', 'location_lng', 'location_label', 'last_location_update', 'created_at', 'updated_at'],
        'staff' => ['worker_reg_no', 'full_name', 'department', 'compound', 'phone_number', 'date_of_birth', 'password_hash', 'remember_token', 'terms_agreed', 'is_approved', 'approved_by', 'approved_at', 'account_status', 'is_online', 'last_seen', 'device_status', 'location_lat', 'location_lng', 'location_label', 'last_location_update', 'created_at', 'updated_at'],
        'foremen' => ['foreman_reg_no', 'first_name', 'last_name', 'department', 'phone_number', 'date_of_birth', 'password_hash', 'remember_token', 'terms_agreed', 'is_approved', 'approved_by', 'approved_at', 'account_status', 'is_online', 'last_seen', 'device_status', 'location_lat', 'location_lng', 'location_label', 'last_location_update', 'created_at', 'updated_at'],
        'admins' => ['admin_id', 'password_hash', 'full_name', 'failed_login_attempts', 'locked_until', 'remember_token', 'terms_agreed', 'created_at', 'updated_at'],
        'login_logs' => ['user_status', 'identifier', 'ip_address', 'user_agent', 'success', 'failure_reason', 'attempted_at'],
        'password_reset_requests' => ['user_status', 'user_identifier', 'phone_number', 'reset_code', 'expires_at', 'used_at', 'created_at'],
    ];

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `password_reset_requests` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `user_status` ENUM('student','staff','foreman','admin') NOT NULL,
            `user_identifier` VARCHAR(100) NOT NULL,
            `phone_number` VARCHAR(25) NULL,
            `reset_code` VARCHAR(10) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `used_at` DATETIME NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `app_controls` (
            `action_name` VARCHAR(100) NOT NULL,
            `enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`action_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `enrollment_codes` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `owner_role` ENUM('student','staff','foreman') NOT NULL,
            `owner_id` INT NOT NULL,
            `code` VARCHAR(12) NOT NULL,
            `status` ENUM('pending','used','expired','revoked') NOT NULL DEFAULT 'pending',
            `expires_at` DATETIME NOT NULL,
            `used_at` DATETIME NULL,
            `used_by_device_uuid` VARCHAR(36) NULL,
            `created_ip` VARCHAR(45) NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_code` (`code`),
            KEY `idx_owner` (`owner_role`, `owner_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `devices` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `device_uuid` VARCHAR(36) NOT NULL,
            `owner_role` ENUM('student','staff','foreman') NOT NULL,
            `owner_id` INT NOT NULL,
            `device_name` VARCHAR(150) NULL,
            `os_info` VARCHAR(150) NULL,
            `agent_version` VARCHAR(50) NULL,
            `token_selector` VARCHAR(24) NOT NULL,
            `token_hash` VARCHAR(255) NOT NULL,
            `status` ENUM('active','locked','killed','deleted') NOT NULL DEFAULT 'active',
            `enrolled_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `last_poll_at` TIMESTAMP NULL,
            `last_ip` VARCHAR(45) NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_device_uuid` (`device_uuid`),
            UNIQUE KEY `uq_token_selector` (`token_selector`),
            KEY `idx_owner` (`owner_role`, `owner_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `device_controls` (
            `device_id` INT NOT NULL,
            `control_name` ENUM('website_blocker','url_scanner_activation','live_location','agent_enabled') NOT NULL,
            `enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `updated_by_admin_id` INT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`device_id`, `control_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `device_commands` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `device_id` INT NOT NULL,
            `command_name` ENUM('remote_lock','app_delete','app_killswitch') NOT NULL,
            `status` ENUM('pending','delivered','received','completed','failed','expired') NOT NULL DEFAULT 'pending',
            `issued_by_admin_id` INT NOT NULL,
            `issued_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `delivered_at` TIMESTAMP NULL,
            `received_at` TIMESTAMP NULL,
            `completed_at` TIMESTAMP NULL,
            `expires_at` DATETIME NULL,
            `result_message` VARCHAR(255) NULL,
            PRIMARY KEY (`id`),
            KEY `idx_device_status` (`device_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `device_events` (
            `id` BIGINT NOT NULL AUTO_INCREMENT,
            `device_id` INT NULL,
            `owner_role` ENUM('student','staff','foreman') NULL,
            `owner_id` INT NULL,
            `actor_type` ENUM('admin','device','system') NOT NULL,
            `actor_id` VARCHAR(100) NULL,
            `event_type` VARCHAR(60) NOT NULL,
            `event_detail` TEXT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_device` (`device_id`),
            KEY `idx_owner` (`owner_role`, `owner_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Chatroom reporting/ticketing tables - previously only ever created lazily by whichever
    // page happened to run first (submit_report.php, chat_submit.php, admin_dashboard.php),
    // which silently relied on a database that already had them from prior use. A genuinely
    // fresh database needs them created centrally like everything else.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `chat_reports` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `room` VARCHAR(100) NOT NULL,
            `submitter_id` INT NOT NULL,
            `submitter_role` VARCHAR(50) NOT NULL,
            `message` TEXT NOT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Subject + origin let admin-initiated tickets (see admin_compose.php) carry a
    // subject line and be distinguished from tickets opened by the user themselves.
    // MySQL (unlike MariaDB) has no "ADD COLUMN IF NOT EXISTS", so check first.
    try {
        $chatReportsColumns = array_map(
            static fn (array $column): string => strtolower((string)$column['Field']),
            $pdo->query("SHOW COLUMNS FROM `chat_reports`")->fetchAll()
        );

        if (!in_array('subject', $chatReportsColumns, true)) {
            $pdo->exec("ALTER TABLE chat_reports ADD COLUMN subject VARCHAR(150) NULL DEFAULT NULL");
        }

        if (!in_array('origin', $chatReportsColumns, true)) {
            $pdo->exec("ALTER TABLE chat_reports ADD COLUMN origin ENUM('user','admin') NOT NULL DEFAULT 'user'");
        }
    } catch (PDOException $e) {
        // ignore; the columns will simply be missing and admin_tickets.php falls back gracefully
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `chat_messages` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `conversation_key` VARCHAR(100) NOT NULL,
            `sender_role` VARCHAR(50) NOT NULL,
            `sender_id` INT NOT NULL,
            `message` TEXT NOT NULL,
            `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
            `is_read` TINYINT(1) NOT NULL DEFAULT 0,
            `report_id` INT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `ticket_actions` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `report_id` INT NOT NULL,
            `admin_id` INT NOT NULL,
            `action` VARCHAR(50) NOT NULL,
            `note` TEXT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $defaultControls = [
        'website_blocker' => 0,
        'remote_lock' => 0,
        'live_location' => 0,
        'url_scanner_activation' => 0,
        'app_turn_off' => 0,
        'app_turn_on' => 1,
        'app_delete' => 0,
        'app_killswitch' => 0,
    ];

    foreach ($defaultControls as $name => $enabled) {
        $checkStmt = $pdo->prepare('SELECT 1 FROM app_controls WHERE action_name = :action_name LIMIT 1');
        $checkStmt->execute(['action_name' => $name]);

        if ($checkStmt->fetch() === false) {
            $pdo->prepare('INSERT INTO app_controls (action_name, enabled) VALUES (:action_name, :enabled)')
                ->execute(['action_name' => $name, 'enabled' => $enabled]);
        }
    }

    foreach ($tables as $table => $requiredColumns) {
        // If the table doesn't exist, create a minimal base table so ALTER TABLE can add columns safely
        try {
            $existsStmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
            $exists = (bool)$existsStmt->fetchColumn();
        } catch (PDOException $e) {
            $exists = false;
        }

        if (!$exists) {
            // create a minimal table
            try {
                $pdo->exec("CREATE TABLE `{$table}` ( `id` INT AUTO_INCREMENT PRIMARY KEY ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (PDOException $e) {
                // ignore creation errors; attempt to continue
            }
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
            $columns = array_map(static fn (array $column): string => strtolower((string)$column['Field']), $stmt->fetchAll());
        } catch (PDOException $e) {
            // if SHOW COLUMNS fails for any reason, ensure we have a fallback empty column list
            $columns = [];
        }

        foreach ($requiredColumns as $column) {
            if (in_array(strtolower($column), $columns, true)) {
                continue;
            }

            $columnDefinition = match ($column) {
                'student_id', 'worker_reg_no', 'foreman_reg_no', 'admin_id', 'identifier' => 'VARCHAR(100) NOT NULL',
                'password_hash', 'remember_token' => 'VARCHAR(255) NULL',
                'full_name', 'first_name', 'last_name', 'department', 'program', 'compound' => 'VARCHAR(150) NULL',
                'phone_number' => 'VARCHAR(25) NULL',
                'date_of_birth' => 'DATE NULL',
                'terms_agreed' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'is_approved' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'approved_by' => 'VARCHAR(150) NULL',
                'approved_at' => 'TIMESTAMP NULL',
                'account_status' => "ENUM('pending','approved','waiting','declined') NOT NULL DEFAULT 'pending'",
                'is_online' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'last_seen' => 'TIMESTAMP NULL',
                'device_status' => "ENUM('healthy','lost','locked','offline') NOT NULL DEFAULT 'healthy'",
                'location_lat' => 'DECIMAL(10,7) NULL',
                'location_lng' => 'DECIMAL(10,7) NULL',
                'location_label' => 'VARCHAR(255) NULL',
                'last_location_update' => 'TIMESTAMP NULL',
                'created_at', 'updated_at', 'attempted_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
                'failed_login_attempts' => 'INT UNSIGNED NOT NULL DEFAULT 0',
                'locked_until' => 'DATETIME NULL',
                'user_status' => "ENUM('student','staff','foreman','admin') NOT NULL",
                'user_agent' => 'TEXT NULL',
                'failure_reason' => 'VARCHAR(255) NULL',
                'success' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'ip_address' => 'VARCHAR(45) NULL',
                'user_identifier' => 'VARCHAR(100) NOT NULL',
                'phone_number' => 'VARCHAR(25) NULL',
                'reset_code' => 'VARCHAR(10) NOT NULL',
                'expires_at' => 'DATETIME NOT NULL',
                'used_at' => 'DATETIME NULL',
                'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
                default => 'TEXT NULL',
            };

            try {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$columnDefinition}");
            } catch (PDOException $e) {
                // ignore individual column add failures and continue
            }

            if (in_array($table, ['students', 'staff', 'foremen'], true) && $column === 'is_approved') {
                try {
                    $pdo->exec("UPDATE `{$table}` SET `is_approved` = 1 WHERE `is_approved` IS NULL OR `is_approved` = 0");
                } catch (PDOException $e) {
                    // ignore
                }
            }

            if (in_array($table, ['students', 'staff', 'foremen'], true) && $column === 'account_status') {
                try {
                    $pdo->exec("UPDATE `{$table}` SET `account_status` = CASE WHEN `is_approved` = 1 THEN 'approved' ELSE 'pending' END WHERE `account_status` IS NULL OR `account_status` = ''");
                } catch (PDOException $e) {
                    // ignore
                }
            }
        }
    }
}

function get_pdo(): PDO
{
    global $pdo;

    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available.');
    }

    return $pdo;
}

function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header("Location: {$url}");
    exit;
}

/**
 * Verifies the device bearer token (selector:validator, same pattern as login.php's
 * remember-me cookie) from the Authorization header and returns the matching devices row.
 * Calls json_response() with a 401 and does not return if the token is missing/invalid.
 */
function authenticate_device(PDO $pdo): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }

    if (!preg_match('/^Bearer\s+([0-9a-f]+):([0-9a-f]+)$/i', trim($header), $matches)) {
        json_response(['error' => 'missing_token'], 401);
    }

    [, $selector, $validator] = $matches;

    $stmt = $pdo->prepare('SELECT * FROM devices WHERE token_selector = :selector LIMIT 1');
    $stmt->execute(['selector' => $selector]);
    $device = $stmt->fetch();

    if ($device === false || !hash_equals((string)$device['token_hash'], hash('sha256', $validator))) {
        json_response(['error' => 'invalid_token'], 401);
    }

    if ($device['status'] === 'deleted') {
        json_response(['status' => 'retired'], 410);
    }

    return $device;
}
<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'method_not_allowed'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);

if (!is_array($payload)) {
    json_response(['error' => 'invalid_json'], 400);
}

$code = strtoupper(trim((string)($payload['code'] ?? '')));
$deviceName = trim((string)($payload['device_name'] ?? ''));
$osInfo = trim((string)($payload['os_info'] ?? ''));
$agentVersion = trim((string)($payload['agent_version'] ?? ''));
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

if ($code === '') {
    json_response(['error' => 'code_required'], 400);
}

// Rate-limit failed enrollment attempts per IP, mirroring login.php's brute-force guard.
$failCountStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM device_events
     WHERE actor_type = 'system' AND event_type = 'enroll_failed' AND actor_id = :ip
       AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
);
$failCountStmt->execute(['ip' => $ip]);
if ((int)$failCountStmt->fetchColumn() >= 5) {
    json_response(['error' => 'too_many_attempts'], 429);
}

function logEnrollFailure(PDO $pdo, string $ip, string $reason): void
{
    $pdo->prepare(
        "INSERT INTO device_events (actor_type, actor_id, event_type, event_detail)
         VALUES ('system', :ip, 'enroll_failed', :detail)"
    )->execute(['ip' => $ip, 'detail' => json_encode(['reason' => $reason])]);
}

$codeStmt = $pdo->prepare("SELECT * FROM enrollment_codes WHERE code = :code AND status = 'pending' LIMIT 1");
$codeStmt->execute(['code' => $code]);
$codeRow = $codeStmt->fetch();

if ($codeRow === false) {
    logEnrollFailure($pdo, $ip, 'unknown_or_used_code');
    json_response(['error' => 'invalid_code'], 400);
}

if (strtotime((string)$codeRow['expires_at']) < time()) {
    $pdo->prepare("UPDATE enrollment_codes SET status = 'expired' WHERE id = :id")->execute(['id' => $codeRow['id']]);
    logEnrollFailure($pdo, $ip, 'expired_code');
    json_response(['error' => 'code_expired'], 400);
}

$ownerRole = (string)$codeRow['owner_role'];
$ownerId = (int)$codeRow['owner_id'];

// A single active device per account (MVP scope) - refuse if one already exists.
$existingStmt = $pdo->prepare("SELECT id FROM devices WHERE owner_role = :r AND owner_id = :id AND status != 'deleted' LIMIT 1");
$existingStmt->execute(['r' => $ownerRole, 'id' => $ownerId]);
if ($existingStmt->fetch() !== false) {
    json_response(['error' => 'device_already_enrolled'], 409);
}

$roleTableMap = ['student' => 'students', 'staff' => 'staff', 'foreman' => 'foremen'];
$table = $roleTableMap[$ownerRole];
$ownerStmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = :id LIMIT 1");
$ownerStmt->execute(['id' => $ownerId]);
$owner = $ownerStmt->fetch();

if ($owner === false) {
    json_response(['error' => 'owner_not_found'], 404);
}

$deviceUuid = sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    random_int(0, 0xffff), random_int(0, 0xffff),
    random_int(0, 0xffff),
    random_int(0, 0x0fff) | 0x4000,
    random_int(0, 0x3fff) | 0x8000,
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
);

$selector = bin2hex(random_bytes(12));
$validator = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $validator);

$pdo->beginTransaction();
try {
    $pdo->prepare(
        "INSERT INTO devices (device_uuid, owner_role, owner_id, device_name, os_info, agent_version, token_selector, token_hash, status)
         VALUES (:uuid, :role, :owner_id, :name, :os, :ver, :selector, :hash, 'active')"
    )->execute([
        'uuid' => $deviceUuid,
        'role' => $ownerRole,
        'owner_id' => $ownerId,
        'name' => $deviceName !== '' ? $deviceName : 'Unnamed device',
        'os' => $osInfo,
        'ver' => $agentVersion,
        'selector' => $selector,
        'hash' => $tokenHash,
    ]);

    $deviceId = (int)$pdo->lastInsertId();

    $defaultControls = [
        'website_blocker' => 0,
        'url_scanner_activation' => 0,
        'live_location' => 0,
        'agent_enabled' => 1,
    ];
    $controlStmt = $pdo->prepare(
        "INSERT INTO device_controls (device_id, control_name, enabled) VALUES (:device_id, :name, :enabled)"
    );
    foreach ($defaultControls as $name => $enabled) {
        $controlStmt->execute(['device_id' => $deviceId, 'name' => $name, 'enabled' => $enabled]);
    }

    $pdo->prepare(
        "UPDATE enrollment_codes SET status = 'used', used_at = NOW(), used_by_device_uuid = :uuid WHERE id = :id"
    )->execute(['uuid' => $deviceUuid, 'id' => $codeRow['id']]);

    $pdo->prepare(
        "INSERT INTO device_events (device_id, owner_role, owner_id, actor_type, actor_id, event_type, event_detail)
         VALUES (:device_id, :role, :owner_id, 'device', :uuid, 'device_enrolled', :detail)"
    )->execute([
        'device_id' => $deviceId,
        'role' => $ownerRole,
        'owner_id' => $ownerId,
        'uuid' => $deviceUuid,
        'detail' => json_encode(['device_name' => $deviceName, 'os_info' => $osInfo, 'ip' => $ip]),
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('MoWLiSS device_enroll.php error: ' . $e->getMessage());
    json_response(['error' => 'enrollment_failed'], 500);
}

$ownerDisplayName = trim((string)($owner['full_name'] ?? '') . ' ' . (string)($owner['first_name'] ?? '') . ' ' . (string)($owner['last_name'] ?? ''));
if ($ownerDisplayName === '') {
    $ownerDisplayName = 'User';
}

json_response([
    'device_uuid' => $deviceUuid,
    'token' => $selector . ':' . $validator,
    'owner_display_name' => $ownerDisplayName,
    'poll_interval_seconds' => 30,
]);

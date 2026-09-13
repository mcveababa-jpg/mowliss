<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'method_not_allowed'], 405);
}

$device = authenticate_device($pdo);
$deviceId = (int)$device['id'];
$ownerRole = (string)$device['owner_role'];
$ownerId = (int)$device['owner_id'];

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    $payload = [];
}

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

// 1. Refresh device metadata.
$pdo->prepare(
    "UPDATE devices SET last_poll_at = NOW(), last_ip = :ip,
        device_name = COALESCE(NULLIF(:name, ''), device_name),
        os_info = COALESCE(NULLIF(:os, ''), os_info),
        agent_version = COALESCE(NULLIF(:ver, ''), agent_version)
     WHERE id = :id"
)->execute([
    'ip' => $ip,
    'name' => trim((string)($payload['device_name'] ?? '')),
    'os' => trim((string)($payload['os_info'] ?? '')),
    'ver' => trim((string)($payload['agent_version'] ?? '')),
    'id' => $deviceId,
]);

// 2. Process acknowledgements for non-destructive commands (e.g. remote_lock) sent inline with the poll.
$acks = is_array($payload['acks'] ?? null) ? $payload['acks'] : [];
foreach ($acks as $ack) {
    if (!is_array($ack) || !isset($ack['command_id'])) {
        continue;
    }
    $commandId = (int)$ack['command_id'];
    $status = in_array($ack['status'] ?? '', ['received', 'completed', 'failed'], true) ? $ack['status'] : 'completed';
    $resultMessage = trim((string)($ack['result_message'] ?? ''));

    $timestampColumn = $status === 'received' ? 'received_at' : 'completed_at';
    $pdo->prepare(
        "UPDATE device_commands SET status = :status, {$timestampColumn} = NOW(), result_message = :msg
         WHERE id = :id AND device_id = :device_id"
    )->execute([
        'status' => $status,
        'msg' => $resultMessage,
        'id' => $commandId,
        'device_id' => $deviceId,
    ]);
}

// 3. Current persistent control state.
$controlStmt = $pdo->prepare("SELECT control_name, enabled FROM device_controls WHERE device_id = :id");
$controlStmt->execute(['id' => $deviceId]);
$controls = [];
foreach ($controlStmt->fetchAll() as $row) {
    $controls[(string)$row['control_name']] = (bool)$row['enabled'];
}

// 4. Mirror location into the owner's role-table columns (this is what admin_control_room.php's
//    existing Leaflet map query already reads - no changes needed there).
$roleTableMap = ['student' => 'students', 'staff' => 'staff', 'foreman' => 'foremen'];
$table = $roleTableMap[$ownerRole];

$location = is_array($payload['location'] ?? null) ? $payload['location'] : null;
if (($controls['live_location'] ?? false) && $location !== null && isset($location['lat'], $location['lng'])) {
    $pdo->prepare(
        "UPDATE {$table} SET location_lat = :lat, location_lng = :lng, location_label = :label, last_location_update = NOW()
         WHERE id = :id"
    )->execute([
        'lat' => (float)$location['lat'],
        'lng' => (float)$location['lng'],
        'label' => trim((string)($location['label'] ?? '')) . ' (approximate, IP-based)',
        'id' => $ownerId,
    ]);
}

// 5. Mirror online/device-status into the role table (existing ENUM: healthy/lost/locked/offline).
$deviceStatusMap = ['active' => 'healthy', 'locked' => 'locked', 'killed' => 'lost'];
$mirroredStatus = $deviceStatusMap[(string)$device['status']] ?? 'healthy';
$pdo->prepare(
    "UPDATE {$table} SET is_online = 1, last_seen = NOW(), device_status = :status WHERE id = :id"
)->execute(['status' => $mirroredStatus, 'id' => $ownerId]);

// 6. Deliver pending one-shot commands.
$pendingStmt = $pdo->prepare(
    "SELECT * FROM device_commands WHERE device_id = :id AND status = 'pending' ORDER BY issued_at ASC"
);
$pendingStmt->execute(['id' => $deviceId]);
$pendingCommands = $pendingStmt->fetchAll();

$commandsOut = [];
foreach ($pendingCommands as $cmd) {
    $pdo->prepare("UPDATE device_commands SET status = 'delivered', delivered_at = NOW() WHERE id = :id")
        ->execute(['id' => $cmd['id']]);

    $commandsOut[] = [
        'command_id' => (int)$cmd['id'],
        'command_name' => (string)$cmd['command_name'],
        'issued_at' => (string)$cmd['issued_at'],
    ];

    $pdo->prepare(
        "INSERT INTO device_events (device_id, owner_role, owner_id, actor_type, actor_id, event_type, event_detail)
         VALUES (:device_id, :role, :owner_id, 'system', :uuid, 'command_delivered', :detail)"
    )->execute([
        'device_id' => $deviceId,
        'role' => $ownerRole,
        'owner_id' => $ownerId,
        'uuid' => (string)$device['device_uuid'],
        'detail' => json_encode(['command_name' => $cmd['command_name'], 'command_id' => (int)$cmd['id']]),
    ]);
}

json_response([
    'server_time' => date('c'),
    'poll_interval_seconds' => 30,
    'device_status' => (string)$device['status'],
    'controls' => $controls,
    'commands' => $commandsOut,
]);

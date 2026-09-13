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
    json_response(['error' => 'invalid_json'], 400);
}

$commandId = (int)($payload['command_id'] ?? 0);
$status = (string)($payload['status'] ?? '');
$resultMessage = trim((string)($payload['result_message'] ?? ''));

if ($commandId <= 0 || !in_array($status, ['received', 'completed', 'failed'], true)) {
    json_response(['error' => 'invalid_request'], 400);
}

$cmdStmt = $pdo->prepare("SELECT * FROM device_commands WHERE id = :id AND device_id = :device_id LIMIT 1");
$cmdStmt->execute(['id' => $commandId, 'device_id' => $deviceId]);
$command = $cmdStmt->fetch();

if ($command === false) {
    json_response(['error' => 'command_not_found'], 404);
}

$timestampColumn = $status === 'received' ? 'received_at' : 'completed_at';
$pdo->prepare(
    "UPDATE device_commands SET status = :status, {$timestampColumn} = NOW(), result_message = :msg WHERE id = :id"
)->execute(['status' => $status, 'msg' => $resultMessage, 'id' => $commandId]);

// app_delete completing means the device is permanently leaving management.
if ((string)$command['command_name'] === 'app_delete' && $status === 'completed') {
    $pdo->prepare("UPDATE devices SET status = 'deleted' WHERE id = :id")->execute(['id' => $deviceId]);
}

$pdo->prepare(
    "INSERT INTO device_events (device_id, owner_role, owner_id, actor_type, actor_id, event_type, event_detail)
     VALUES (:device_id, :role, :owner_id, 'device', :uuid, :event_type, :detail)"
)->execute([
    'device_id' => $deviceId,
    'role' => $ownerRole,
    'owner_id' => $ownerId,
    'uuid' => (string)$device['device_uuid'],
    'event_type' => 'command_' . $status,
    'detail' => json_encode([
        'command_id' => $commandId,
        'command_name' => $command['command_name'],
        'result_message' => $resultMessage,
    ]),
]);

json_response(['ok' => true]);

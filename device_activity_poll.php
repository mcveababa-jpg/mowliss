<?php
require_once __DIR__ . '/db.php';

if (empty($_SESSION['authenticated']) || ($_SESSION['user_status'] ?? '') !== 'admin') {
    json_response(['error' => 'forbidden'], 403);
}

$ownerRoleMap = ['students' => 'student', 'staff' => 'staff', 'foremen' => 'foreman'];
$role = (string)($_GET['role'] ?? '');
$userId = (int)($_GET['user_id'] ?? 0);

if (!isset($ownerRoleMap[$role]) || $userId <= 0) {
    json_response(['error' => 'invalid_request'], 400);
}

$ownerRole = $ownerRoleMap[$role];
$deviceStmt = $pdo->prepare("SELECT * FROM devices WHERE owner_role = :r AND owner_id = :id AND status != 'deleted' ORDER BY enrolled_at DESC LIMIT 1");
$deviceStmt->execute(['r' => $ownerRole, 'id' => $userId]);
$device = $deviceStmt->fetch();

if (!$device) {
    json_response(['no_device' => true]);
}

$deviceId = (int)$device['id'];
$oneShotCommands = ['remote_lock', 'app_delete', 'app_killswitch'];

$controls = [];
$ctrlStmt = $pdo->prepare("SELECT control_name, enabled FROM device_controls WHERE device_id = :id");
$ctrlStmt->execute(['id' => $deviceId]);
foreach ($ctrlStmt->fetchAll() as $row) {
    $controls[(string)$row['control_name']] = (int)$row['enabled'];
}
$controls['app_turn_on'] = (int)($controls['agent_enabled'] ?? 1);
$controls['app_turn_off'] = $controls['app_turn_on'] ? 0 : 1;

$commands = [];
foreach ($oneShotCommands as $cmdName) {
    $cmdStmt = $pdo->prepare("SELECT status FROM device_commands WHERE device_id = :id AND command_name = :name ORDER BY issued_at DESC LIMIT 1");
    $cmdStmt->execute(['id' => $deviceId, 'name' => $cmdName]);
    $latest = $cmdStmt->fetch();
    $commands[$cmdName] = $latest ? (string)$latest['status'] : 'none';
}

$afterId = (int)($_GET['after_id'] ?? 0);
$eventsStmt = $pdo->prepare("SELECT * FROM device_events WHERE device_id = :id AND id > :after ORDER BY id DESC LIMIT 30");
$eventsStmt->execute(['id' => $deviceId, 'after' => $afterId]);

$events = [];
foreach ($eventsStmt->fetchAll() as $row) {
    $events[] = [
        'id' => (int)$row['id'],
        'message' => describe_device_event($row),
        'created_at' => (string)$row['created_at'],
    ];
}

$onlineThresholdSeconds = 90;
$online = $device['last_poll_at'] && (strtotime((string)$device['last_poll_at']) > time() - $onlineThresholdSeconds);

json_response([
    'online' => (bool)$online,
    'last_poll_at' => (string)($device['last_poll_at'] ?? ''),
    'device_status' => (string)$device['status'],
    'controls' => $controls,
    'commands' => $commands,
    'events' => $events,
]);

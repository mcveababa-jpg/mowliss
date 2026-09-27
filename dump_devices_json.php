<?php
require_once __DIR__ . '/db.php';

if (empty($_SESSION['authenticated']) || ($_SESSION['user_status'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'forbidden']);
    exit;
}

// Driven by the `devices` table (the actual record of who has an agent enrolled),
// not by the role tables' location columns directly. Querying location columns alone
// was wrong in both directions: an account with no device at all could still show a
// pin if a location had ever been written to its row by some other means (false
// information - a "device" that doesn't exist), while a genuinely enrolled device
// that simply hadn't reported a location fix yet was invisible (a real device
// silently missing). Every currently-enrolled (non-deleted) device is now always
// included, with lat/lng only populated when a real fix is on file - the frontend
// is responsible for showing "no location yet" rather than fabricating a position.
$roleMap = ['student' => 'students', 'staff' => 'staff', 'foreman' => 'foremen'];
// devices.owner_role is singular ('student'/'staff'/'foreman'); the frontend's role
// keys (and dashboard URLs) use the plural table names - this is the same mapping
// $roleMap already provides, just read in the other direction for output.
$roleKeyOut = $roleMap;

$allDevices = [];
foreach ($roleMap as $ownerRole => $tableName) {
    // SELECT r.* because students/foremen use first_name+last_name while staff uses
    // full_name - a fixed column list would break on whichever table doesn't have it.
    $stmt = $pdo->prepare(
        "SELECT d.id AS device_id, d.device_name, d.status AS device_reg_status, d.last_poll_at,
                r.*
         FROM devices d
         JOIN `{$tableName}` r ON r.id = d.owner_id
         WHERE d.owner_role = :owner_role AND d.status != 'deleted'"
    );
    $stmt->execute(['owner_role' => $ownerRole]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $r) {
        $display = trim((string)($r['full_name'] ?? '') . ' ' . (string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
        if ($display === '') {
            $display = sprintf('%s-%s', $ownerRole, (string)($r['id'] ?? ''));
        }

        $lat = ($r['location_lat'] ?? '') !== '' ? (float)$r['location_lat'] : null;
        $lng = ($r['location_lng'] ?? '') !== '' ? (float)$r['location_lng'] : null;

        $allDevices[] = [
            'role' => $roleKeyOut[$ownerRole],
            'id' => (int)$r['id'],
            'name' => $display,
            'device_name' => (string)($r['device_name'] ?? ''),
            'label' => (string)($r['location_label'] ?? ''),
            'device_status' => (string)($r['device_status'] ?? 'healthy'),
            'registration_status' => (string)($r['device_reg_status'] ?? ''),
            'last_poll_at' => $r['last_poll_at'],
            'has_location' => $lat !== null && $lng !== null,
            'lat' => $lat,
            'lng' => $lng,
        ];
    }
}

echo json_encode($allDevices, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . "\n";

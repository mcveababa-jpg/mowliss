<?php
// Add sample coordinates to first student, staff, and foreman (if they exist)
chdir(__DIR__);
require_once __DIR__ . '/db.php';

$examples = [
    ['role'=>'students','lat'=>-33.8688,'lng'=>151.2093,'label'=>'Sydney Sample'],
    ['role'=>'staff','lat'=>-27.4698,'lng'=>153.0251,'label'=>'Brisbane Sample'],
    ['role'=>'foremen','lat'=>-9.4438,'lng'=>147.1803,'label'=>'Port Moresby Sample'],
];

$results = [];
foreach ($examples as $ex) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM {$ex['role']} LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) {
            $results[] = "No rows in {$ex['role']}";
            continue;
        }
        $id = (int)$row['id'];
        $update = $pdo->prepare(
            "UPDATE {$ex['role']} SET location_lat = :lat, location_lng = :lng, location_label = :label, last_location_update = NOW(), device_status = 'healthy' WHERE id = :id"
        );
        $update->execute(['lat'=>$ex['lat'],'lng'=>$ex['lng'],'label'=>$ex['label'],'id'=>$id]);
        $results[] = "Updated {$ex['role']} id={$id} -> {$ex['label']} ({$ex['lat']},{$ex['lng']})";
    } catch (Throwable $e) {
        $results[] = "Error for {$ex['role']}: " . $e->getMessage();
    }
}

foreach ($results as $r) {
    echo $r . "\n";
}

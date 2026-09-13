<?php
require_once __DIR__ . '/db.php';

if (empty($_SESSION['authenticated']) || ($_SESSION['user_status'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$allDevices = [];
foreach(['students'=>'students','staff'=>'staff','foremen'=>'foremen'] as $roleName=>$tableName){
    $stmt=$pdo->prepare("SELECT id, first_name, last_name, full_name, location_lat, location_lng, location_label, device_status FROM {$tableName} WHERE location_lat IS NOT NULL AND location_lat != '' AND location_lng IS NOT NULL AND location_lng != ''");
    $stmt->execute();
    $rows=$stmt->fetchAll();
    foreach($rows as $r){
        $display = trim((string)($r['full_name'] ?? '') . ' ' . (string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
        if ($display === '') { $display = sprintf('%s-%s', $roleName, (string)($r['id'] ?? '')); }
        $lat = isset($r['location_lat']) ? (float)$r['location_lat'] : null;
        $lng = isset($r['location_lng']) ? (float)$r['location_lng'] : null;
        if ($lat !== null && $lng !== null) {
            $allDevices[] = ['role'=>$roleName,'id'=>(int)$r['id'],'name'=>$display,'label'=>(string)($r['location_label'] ?? ''),'device_status'=>(string)($r['device_status'] ?? ''),'lat'=>$lat,'lng'=>$lng];
        }
    }
}
echo json_encode($allDevices, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . "\n";

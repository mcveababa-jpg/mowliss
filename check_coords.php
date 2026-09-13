<?php
require_once __DIR__ . '/db.php';

if (empty($_SESSION['authenticated']) || ($_SESSION['user_status'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

$roles=['students','staff','foremen'];
foreach($roles as $r){
    $stmt=$pdo->prepare("SELECT id, location_lat, location_lng, location_label FROM {$r} WHERE location_lat IS NOT NULL AND location_lat != '' AND location_lng IS NOT NULL AND location_lng != ''");
    $stmt->execute();
    $rows=$stmt->fetchAll();
    echo "Role: {$r} -> " . count($rows) . " rows with coords\n";
    foreach($rows as $row){
        echo json_encode($row) . "\n";
    }
}

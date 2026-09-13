<?php
// Test renderer for admin_control_room.php
chdir(__DIR__);
require_once __DIR__ . '/db.php';

// Ensure session is available and set admin authentication
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION['authenticated'] = true;
$_SESSION['user_status'] = 'admin';

// Find an existing admin id
try {
    $row = $pdo->query('SELECT id FROM admins LIMIT 1')->fetch();
    if (!$row) {
        echo "No admin user found in database. Please create one and try again.\n";
        exit(1);
    }
    $_SESSION['user_id'] = (int)$row['id'];
} catch (Throwable $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}

// Capture the included page output
ob_start();
include __DIR__ . '/admin_control_room.php';
$html = ob_get_clean();

$outPath = __DIR__ . DIRECTORY_SEPARATOR . 'admin_control_room_rendered.html';
file_put_contents($outPath, $html);

// Make sure the file is written
if (file_exists($outPath)) {
    echo "Rendered admin control room written to: " . $outPath . "\n";
} else {
    echo "Failed to write rendered file.\n";
}

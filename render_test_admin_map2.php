<?php
// Improved test renderer for admin_control_room.php
chdir(__DIR__);
require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION['authenticated'] = true;
$_SESSION['user_status'] = 'admin';

try {
    $adminRow = $pdo->query('SELECT id FROM admins LIMIT 1')->fetch();
    if (!$adminRow) {
        echo "No admin user found in database. Please create one and try again.\n";
        exit(1);
    }
    $_SESSION['user_id'] = (int)$adminRow['id'];

    // Try to find any device-enabled user with coordinates
    $found = false;
    $roleOrder = ['students','staff','foremen'];
    foreach ($roleOrder as $role) {
        $stmt = $pdo->prepare("SELECT id, location_lat, location_lng FROM {$role} WHERE location_lat IS NOT NULL AND location_lat != '' AND location_lng IS NOT NULL AND location_lng != '' LIMIT 1");
        $stmt->execute();
        $r = $stmt->fetch();
        if ($r) {
            $_GET['role'] = $role;
            $_GET['user_id'] = (int)$r['id'];
            $found = true;
            break;
        }
    }

    // If none have coordinates, pick any user so page doesn't redirect; admin can still see map (may be empty)
    if (!$found) {
        foreach ($roleOrder as $role) {
            $stmt = $pdo->prepare("SELECT id FROM {$role} LIMIT 1");
            $stmt->execute();
            $r = $stmt->fetch();
            if ($r) {
                $_GET['role'] = $role;
                $_GET['user_id'] = (int)$r['id'];
                $found = true;
                break;
            }
        }
    }

    if (!$found) {
        echo "No users found in students/staff/foremen tables. Please add a test user and try again.\n";
        exit(1);
    }

} catch (Throwable $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}

ob_start();
include __DIR__ . '/admin_control_room.php';
$html = ob_get_clean();

$outPath = __DIR__ . DIRECTORY_SEPARATOR . 'admin_control_room_rendered.html';
file_put_contents($outPath, $html);
if (file_exists($outPath)) {
    echo "Rendered admin control room written to: " . $outPath . "\n";
} else {
    echo "Failed to write rendered file.\n";
}

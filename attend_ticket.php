<?php
require_once __DIR__ . '/db.php';

if (empty($_SESSION['authenticated']) || empty($_SESSION['user_status']) || empty($_SESSION['user_id'])) {
    redirect('login.php');
}

if ($_SESSION['user_status'] !== 'admin') {
    redirect('dashboard.php');
}

$reportId = (int)($_GET['id'] ?? 0);
$action = $_GET['act'] ?? 'attend';

if ($reportId <= 0) {
    $_SESSION['flash_message'] = 'Invalid ticket id.';
    redirect('admin_dashboard.php');
}

// ensure table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS ticket_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    admin_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// load the report to find submitter role/id for the conversation redirect
$rstmt = $pdo->prepare('SELECT * FROM chat_reports WHERE id = :id LIMIT 1');
$rstmt->execute(['id' => $reportId]);
$reportRow = $rstmt->fetch();
if (!$reportRow) {
    $_SESSION['flash_message'] = 'Ticket not found.';
    redirect('admin_dashboard.php');
}

// normalize role to the plural roleKey used across the app
$origRole = strtolower((string)($reportRow['submitter_role'] ?? ''));
$roleKey = 'students';
if (in_array($origRole, ['students', 'student', 'student'])) $roleKey = 'students';
elseif ($origRole === 'staff') $roleKey = 'staff';
elseif (in_array($origRole, ['foremen', 'foreman', 'foremen'])) $roleKey = 'foremen';
else $roleKey = $origRole; // fallback to whatever was stored

$conversation = urlencode($roleKey . ':' . (int)$reportRow['submitter_id']);

if ($action === 'attend' || $action === 'attended') {
    // check latest action
    $stmt = $pdo->prepare('SELECT action FROM ticket_actions WHERE report_id = :rid ORDER BY id DESC LIMIT 1');
    $stmt->execute(['rid' => $reportId]);
    $last = $stmt->fetch();
    if ($last && strtolower($last['action']) === 'attended') {
        $_SESSION['flash_message'] = 'Ticket already attended.';
    } else {
        $ins = $pdo->prepare('INSERT INTO ticket_actions (report_id, admin_id, action) VALUES (:rid, :aid, :action)');
        $ins->execute(['rid' => $reportId, 'aid' => (int)$_SESSION['user_id'], 'action' => 'attended']);
        $_SESSION['flash_message'] = 'Ticket marked as attended.';
    }
    redirect('admin_view_chat.php?conv=' . $conversation . '&report=' . (int)$reportId);
}

if ($action === 'waiting' || $action === 'wait') {
    $ins = $pdo->prepare('INSERT INTO ticket_actions (report_id, admin_id, action) VALUES (:rid, :aid, :action)');
    $ins->execute(['rid' => $reportId, 'aid' => (int)$_SESSION['user_id'], 'action' => 'waiting']);
    $_SESSION['flash_message'] = 'Ticket set to waiting.';
    redirect('admin_view_chat.php?conv=' . $conversation . '&report=' . (int)$reportId);
}

if ($action === 'resolve' || $action === 'resolved') {
    $ins = $pdo->prepare('INSERT INTO ticket_actions (report_id, admin_id, action) VALUES (:rid, :aid, :action)');
    $ins->execute(['rid' => $reportId, 'aid' => (int)$_SESSION['user_id'], 'action' => 'resolved']);
    $_SESSION['flash_message'] = 'Ticket marked as resolved.';
    redirect('admin_view_chat.php?conv=' . $conversation . '&report=' . (int)$reportId);
}

// fallback
redirect('admin_dashboard.php');

<?php
require_once __DIR__ . '/db.php';

if (empty($_SESSION['authenticated']) || empty($_SESSION['user_status']) || empty($_SESSION['user_id'])) {
    redirect('login.php');
}

// Only admins may change status
if ($_SESSION['user_status'] !== 'admin') {
    $_SESSION['flash_message'] = 'Only administrators can change ticket status.';
    redirect($_SERVER['HTTP_REFERER'] ?? 'admin_dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin_dashboard.php');
}

$reportId = isset($_POST['report_id']) ? (int)$_POST['report_id'] : 0;
action:
$action = isset($_POST['action']) ? strtolower(trim((string)$_POST['action'])) : '';
$allowed = ['attended', 'waiting', 'resolved'];
if (!in_array($action, $allowed, true)) {
    $_SESSION['flash_message'] = 'Invalid status selected.';
    redirect($_SERVER['HTTP_REFERER'] ?? 'admin_dashboard.php');
}

if ($reportId <= 0) {
    $_SESSION['flash_message'] = 'Invalid report id.';
    redirect($_SERVER['HTTP_REFERER'] ?? 'admin_dashboard.php');
}

// ensure ticket_actions table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS ticket_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    admin_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$ins = $pdo->prepare('INSERT INTO ticket_actions (report_id, admin_id, action) VALUES (:rid, :aid, :action)');
$ins->execute(['rid' => $reportId, 'aid' => (int)$_SESSION['user_id'], 'action' => $action]);

$_SESSION['flash_message'] = 'Ticket status updated.';

// redirect back to referrer or to the ticket conversation if requested
$back = $_SERVER['HTTP_REFERER'] ?? null;
if ($back) {
    redirect($back);
}
redirect('admin_dashboard.php');

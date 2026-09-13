<?php
require_once __DIR__ . '/db.php';

if (empty($_SESSION['authenticated']) || empty($_SESSION['user_status']) || empty($_SESSION['user_id'])) {
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('dashboard.php');
}

// Prevent admins from creating user tickets — tickets are for users to contact admin only.
if (isset($_SESSION['user_status']) && $_SESSION['user_status'] === 'admin') {
    $_SESSION['flash_message'] = 'Admins are not allowed to submit user tickets via this form.';
    redirect($_SERVER['HTTP_REFERER'] ?? 'admin_chatroom_report.php');
}

$room = trim((string)($_POST['room'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

if ($room === '' || $message === '') {
    $_SESSION['flash_message'] = 'Room and message are required to submit a report.';
    redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
}

// Create table if needed (safe to run multiple times)
$pdo->exec("CREATE TABLE IF NOT EXISTS chat_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room VARCHAR(100) NOT NULL,
    submitter_id INT NOT NULL,
    submitter_role VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// insert the report and get its id
$stmt = $pdo->prepare("INSERT INTO chat_reports (room, submitter_id, submitter_role, message) VALUES (:room, :sid, :srole, :message)");
$stmt->execute([
    'room' => $room,
    'sid' => (int)$_SESSION['user_id'],
    'srole' => (string)$_SESSION['user_status'],
    'message' => $message,
]);

$reportId = (int)$pdo->lastInsertId();

// Ensure chat_messages table exists and has a report_id column so tickets and chat messages are linked
$pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_key VARCHAR(100) NOT NULL,
    sender_role VARCHAR(50) NOT NULL,
    sender_id INT NOT NULL,
    message TEXT NOT NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    report_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// In some MySQL versions the table may exist without the report_id column; attempt to add it safely
try {
    $pdo->exec("ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS report_id INT NULL");
} catch (PDOException $e) {
    // ignore errors (column may already exist on older MySQL)
}

// create an initial chat message that links to this report so admin sees the ticket inside conversations
// normalize role to plural key used by conversations
$userRoleRaw = (string)$_SESSION['user_status'];
$roleKey = 'students';
if (in_array($userRoleRaw, ['students','student'], true)) $roleKey = 'students';
elseif ($userRoleRaw === 'staff') $roleKey = 'staff';
elseif (in_array($userRoleRaw, ['foremen','foreman'], true)) $roleKey = 'foremen';
else $roleKey = $userRoleRaw;

$convKey = $roleKey . ':' . (int)$_SESSION['user_id'];
$initStmt = $pdo->prepare('INSERT INTO chat_messages (conversation_key, sender_role, sender_id, message, is_admin, report_id) VALUES (:ck, :srole, :sid, :msg, 0, :rid)');
$initStmt->execute([
    'ck' => $convKey,
    'srole' => $userRoleRaw,
    'sid' => (int)$_SESSION['user_id'],
    'msg' => $message,
    'rid' => $reportId,
]);

$_SESSION['flash_message'] = 'Report submitted successfully.';

// Redirect back to the referring report page if available
$referer = $_SERVER['HTTP_REFERER'] ?? null;
if ($referer) {
    redirect($referer);
}
redirect('dashboard.php');

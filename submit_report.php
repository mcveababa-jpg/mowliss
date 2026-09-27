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
$subject = trim((string)($_POST['subject'] ?? ''));
$category = trim((string)($_POST['category'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

if ($room === '' || $subject === '' || $message === '') {
    $_SESSION['flash_message'] = 'Subject and message are required to submit a report.';
    redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
}

// db.php's ensure_database_schema() already creates chat_reports (with subject/category
// columns) on every request, so no ad-hoc CREATE/ALTER is needed here.

// insert the report and get its id
$stmt = $pdo->prepare("INSERT INTO chat_reports (room, submitter_id, submitter_role, subject, category, message) VALUES (:room, :sid, :srole, :subject, :category, :message)");
$stmt->execute([
    'room' => $room,
    'sid' => (int)$_SESSION['user_id'],
    'srole' => (string)$_SESSION['user_status'],
    'subject' => $subject,
    'category' => $category !== '' ? $category : null,
    'message' => $message,
]);

$reportId = (int)$pdo->lastInsertId();

// chat_messages (including its report_id column) is guaranteed to exist already -
// db.php's ensure_database_schema() creates it on every request.

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

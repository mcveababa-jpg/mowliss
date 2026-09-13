<?php
require_once __DIR__ . '/db.php';

if (empty($_SESSION['authenticated']) || empty($_SESSION['user_status']) || empty($_SESSION['user_id'])) {
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('dashboard.php');
}

$conv = trim((string)($_POST['conversation_key'] ?? ''));
$msg = trim((string)($_POST['message'] ?? ''));

if ($conv === '' || $msg === '') {
    $_SESSION['flash_message'] = 'Message cannot be empty.';
    redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
}

// ensure table exists (add report_id so messages can be tied to tickets)
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

// ensure report_id column exists on older schemas
try {
    $pdo->exec("ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS report_id INT NULL");
} catch (PDOException $e) {
    // ignore if the server doesn't support IF NOT EXISTS or the column already exists
}

$isAdmin = ($_SESSION['user_status'] === 'admin') ? 1 : 0;
$senderRole = (string)$_SESSION['user_status'];
$senderId = (int)$_SESSION['user_id'];

$reportId = isset($_POST['report_id']) && $_POST['report_id'] !== '' ? (int)$_POST['report_id'] : null;

// If this is a user message (not admin) and no report_id provided, try to reuse an open ticket for this user or create a new one
if (!$isAdmin) {
    // ensure chat_reports exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room VARCHAR(100) NOT NULL,
        submitter_id INT NOT NULL,
        submitter_role VARCHAR(50) NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($reportId === null) {
        $reuseId = null;
        // find the most recent report for this submitter
        $rStmt = $pdo->prepare('SELECT id FROM chat_reports WHERE submitter_role = :role AND submitter_id = :sid ORDER BY id DESC LIMIT 1');
        $rStmt->execute(['role' => $senderRole, 'sid' => $senderId]);
        $lastReportId = $rStmt->fetchColumn();
        if ($lastReportId) {
            // check if it has a resolve action
            $checkAct = $pdo->prepare("SELECT 1 FROM ticket_actions WHERE report_id = :rid AND action = 'resolve' LIMIT 1");
            try {
                $checkAct->execute(['rid' => (int)$lastReportId]);
                $resolved = (bool)$checkAct->fetchColumn();
            } catch (PDOException $e) {
                // ticket_actions table may not exist yet; treat as unresolved
                $resolved = false;
            }

            if (!$resolved) {
                $reuseId = (int)$lastReportId;
            }
        }

        if ($reuseId === null) {
            // create a new report linked to this message
            $ir = $pdo->prepare('INSERT INTO chat_reports (room, submitter_id, submitter_role, message) VALUES (:room, :sid, :srole, :message)');
            $ir->execute([
                'room' => 'direct_admin',
                'sid' => $senderId,
                'srole' => $senderRole,
                'message' => $msg,
            ]);
            $reuseId = (int)$pdo->lastInsertId();
        }

        $reportId = $reuseId;
    }
}

$stmt = $pdo->prepare('INSERT INTO chat_messages (conversation_key, sender_role, sender_id, message, is_admin, report_id) VALUES (:ck, :srole, :sid, :msg, :is_admin, :rid)');
$stmt->execute([
    'ck' => $conv,
    'srole' => $senderRole,
    'sid' => $senderId,
    'msg' => $msg,
    'is_admin' => $isAdmin,
    'rid' => $reportId,
]);

// redirect back to the appropriate view
if ($isAdmin) {
    $_SESSION['flash_message'] = 'Reply sent.';
    // if this reply belongs to a ticket, send admin back to that ticketed conversation
    $redir = 'admin_view_chat.php?conv=' . urlencode($conv);
    if ($reportId !== null) $redir .= '&report=' . (int)$reportId;
    redirect($redir);
}

$_SESSION['flash_message'] = 'Message sent to admin.';
redirect('admin_chat_user.php');

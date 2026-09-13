<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_header.php';

if (empty($_SESSION['authenticated']) || empty($_SESSION['user_status']) || empty($_SESSION['user_id'])) {
    redirect('login.php');
}

if ($_SESSION['user_status'] === 'admin') {
    redirect('admin_chatroom_report.php');
}

// normalize role to plural key used by conversations (students/staff/foremen)
$userRoleRaw = $_SESSION['user_status'];
$userId = (int)$_SESSION['user_id'];
$roleKey = 'students';
if (in_array($userRoleRaw, ['students','student'], true)) $roleKey = 'students';
elseif ($userRoleRaw === 'staff') $roleKey = 'staff';
elseif (in_array($userRoleRaw, ['foremen','foreman'], true)) $roleKey = 'foremen';
else $roleKey = $userRoleRaw;
$convKey = $roleKey . ':' . $userId;

// ensure table
$pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_key VARCHAR(100) NOT NULL,
    sender_role VARCHAR(50) NOT NULL,
    sender_id INT NOT NULL,
    message TEXT NOT NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// load messages
$stmt = $pdo->prepare('SELECT * FROM chat_messages WHERE conversation_key = :ck ORDER BY id ASC');
$stmt->execute(['ck' => $convKey]);
$messages = $stmt->fetchAll();

// mark admin messages as read
$pdo->prepare('UPDATE chat_messages SET is_read = 1 WHERE conversation_key = :ck AND is_admin = 1')->execute(['ck' => $convKey]);

$flash = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

// find latest report for this user to show status
$latestReport = null;
$reportStatus = 'New';
$reportIdForUI = 0;
$userRoleRaw = $_SESSION['user_status'];
$roleKeySearch = $roleKey; // from earlier normalization
try {
    $rStmt = $pdo->prepare('SELECT * FROM chat_reports WHERE submitter_id = :sid AND (submitter_role = :r1 OR submitter_role = :r2) ORDER BY id DESC LIMIT 1');
    $rStmt->execute(['sid' => $userId, 'r1' => $userRoleRaw, 'r2' => $roleKeySearch]);
    $latestReport = $rStmt->fetch();
    if ($latestReport) {
        $reportIdForUI = (int)$latestReport['id'];
        // get latest ticket action
        $aStmt = $pdo->prepare('SELECT action FROM ticket_actions WHERE report_id = :rid ORDER BY id DESC LIMIT 1');
        try {
            $aStmt->execute(['rid' => $reportIdForUI]);
            $la = $aStmt->fetchColumn();
            if ($la) $reportStatus = ucfirst((string)$la);
        } catch (PDOException $e) {
            // ignore
        }
    }
} catch (Exception $e) {
    // ignore
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Chat Admin | MoWLiSS</title>
    <link rel="stylesheet" href="style.css?v=6">
</head>
<body>
<?php render_site_header(); ?>
<div class="container report-shell">
    <div class="report-header">
        <div>
            <h1>Chat Admin</h1>
            <p class="small">Send messages directly to the site administrators.</p>
        </div>
        <div class="form-actions">
            <a class="btn inline-btn" href="dashboard.php">Back to Dashboard</a>
            <a class="btn inline-btn" href="logout.php">Logout</a>
        </div>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert-success"><?= e($flash) ?></div>
    <?php endif; ?>

    <div class="dashboard-card">
        <h2>Your Conversation with Admin</h2>

        <div style="margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <div class="small">Ticket Status: </div>
            <div>
                <form method="POST" action="update_ticket_status.php">
                    <input type="hidden" name="report_id" value="<?= (int)$reportIdForUI ?>">
                    <select name="action" onchange="/* disabled for user view */" style="padding:6px 10px;border-radius:8px;border:1px solid rgba(51,52,143,0.3);background:#f5f5fa;color:#1B1C5E;" disabled>
                        <option value="attended" <?= strtolower($reportStatus) === 'attended' ? 'selected' : '' ?>>Attended</option>
                        <option value="waiting" <?= strtolower($reportStatus) === 'waiting' ? 'selected' : '' ?>>Waiting</option>
                        <option value="resolved" <?= strtolower($reportStatus) === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                    </select>
                </form>
            </div>
        </div>

        <div class="chat-thread">
            <?php if (empty($messages)): ?>
                <div class="chat-empty">No messages yet. Send one to start a conversation with admin.</div>
            <?php else: ?>
                <?php foreach ($messages as $m): ?>
                    <div class="chat-message <?= (int)$m['is_admin'] === 1 ? 'from-admin' : 'from-user' ?>">
                        <div class="msg-meta"><?= e((int)$m['sender_id']) ?> — <?= e((string)$m['created_at']) ?></div>
                        <div class="msg-body"><?= nl2br(e((string)$m['message'])) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <form method="POST" action="chat_submit.php">
            <input type="hidden" name="conversation_key" value="<?= e($convKey) ?>">
            <div class="form-group">
                <label for="txt">Message</label>
                <textarea id="txt" name="message" rows="4" required></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn">Send</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
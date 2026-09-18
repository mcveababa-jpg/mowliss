<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_header.php';

if (empty($_SESSION['authenticated']) || empty($_SESSION['user_status']) || empty($_SESSION['user_id'])) {
    redirect('login.php');
}
if ($_SESSION['user_status'] !== 'admin') {
    redirect('dashboard.php');
}

$conv = trim((string)($_GET['conv'] ?? ''));
if ($conv === '') {
    redirect('admin_dashboard.php');
}

// optional report id (ticket) to show and to attach replies to
$reportId = isset($_GET['report']) ? (int)$_GET['report'] : 0;

// load messages (include messages linked to the report as well)
$stmt = $pdo->prepare('SELECT * FROM chat_messages WHERE conversation_key = :ck ORDER BY id ASC');
$stmt->execute(['ck' => $conv]);
$messages = $stmt->fetchAll();

// mark as read
$pdo->prepare('UPDATE chat_messages SET is_read = 1 WHERE conversation_key = :ck')->execute(['ck' => $conv]);

// load report details if provided
$reportRow = null;
$submitterDisplay = null;
if ($reportId > 0) {
    $rStmt = $pdo->prepare('SELECT * FROM chat_reports WHERE id = :id LIMIT 1');
    $rStmt->execute(['id' => $reportId]);
    $reportRow = $rStmt->fetch();

    // load ticket actions/audit for display
    $actions = [];
    if ($reportRow) {
        $aStmt = $pdo->prepare('SELECT * FROM ticket_actions WHERE report_id = :rid ORDER BY id ASC');
        try {
            $aStmt->execute(['rid' => $reportId]);
            $actions = $aStmt->fetchAll();
        } catch (PDOException $e) {
            // ignore if table missing
            $actions = [];
        }

        // attempt to load submitter display name
        $origRole = strtolower((string)($reportRow['submitter_role'] ?? ''));
        $roleKey = 'students';
        if (in_array($origRole, ['students','student'], true)) $roleKey = 'students';
        elseif ($origRole === 'staff') $roleKey = 'staff';
        elseif (in_array($origRole, ['foremen','foreman'], true)) $roleKey = 'foremen';
        else $roleKey = $origRole;

        try {
            $uStmt = $pdo->prepare("SELECT * FROM {$roleKey} WHERE id = :id LIMIT 1");
            $uStmt->execute(['id' => (int)$reportRow['submitter_id']]);
            $uRow = $uStmt->fetch();
            if ($uRow) {
                // build a reasonable display name
                if (in_array($roleKey, ['students','foremen'], true)) {
                    $submitterDisplay = trim((($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? '')));
                } elseif ($roleKey === 'staff') {
                    $submitterDisplay = trim((string)($uRow['full_name'] ?? ''));
                } else {
                    $submitterDisplay = trim((string)($uRow['full_name'] ?? $uRow['id'] ?? 'User'));
                }
                if ($submitterDisplay === '') $submitterDisplay = (string)$reportRow['submitter_id'];
                $submitterDisplay .= ' (' . $roleKey . ':' . (int)$reportRow['submitter_id'] . ')';
            }
        } catch (Exception $e) {
            // ignore
        }
    }
}

// parse conversation key
list($role, $uid) = explode(':', $conv) + [null, null];
$displayUser = htmlspecialchars((string)$conv, ENT_QUOTES, 'UTF-8');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Conversation <?= e($conv) ?> | Admin</title>
    <link rel="stylesheet" href="style.css?v=7">
</head>
<body>
<?php render_site_header(); ?>
<div class="container report-shell">
    <div class="report-header">
        <div>
            <h1>Conversation: <?= e($displayUser) ?></h1>
            <p class="small">Messages exchanged between admin and <?= e($role) ?> (ID: <?= e($uid) ?>)</p>
            <?php if (!empty($reportRow)): ?>
                            <div class="ticket-summary">
                                <div style="display:flex;align-items:center;gap:12px;justify-content:space-between;">
                                    <div>
                                        <strong style="color:#1B1C5E;">Ticket #<?= (int)$reportRow['id'] ?></strong>
                                        <?php if (!empty($submitterDisplay)): ?>
                                            <div class="small" style="margin-top:6px;color:#5a5a5a;">From: <?= e($submitterDisplay) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <!-- Admin can change status here -->
                                        <?php
                                            // determine latest action for this report
                                            $latestAction = '';
                                            if (!empty($actions)) {
                                                $la = end($actions);
                                                $latestAction = strtolower((string)$la['action']);
                                            }
                                        ?>
                                        <form method="POST" action="update_ticket_status.php">
                                            <input type="hidden" name="report_id" value="<?= (int)$reportRow['id'] ?>">
                                            <select name="action" onchange="this.form.submit()" style="padding:6px 10px;border-radius:8px;border:1px solid rgba(51,52,143,0.3);background:#f5f5fa;color:#1B1C5E;">
                                                <option value="attended" <?= $latestAction === 'attended' ? 'selected' : '' ?>>Attended</option>
                                                <option value="waiting" <?= $latestAction === 'waiting' ? 'selected' : '' ?>>Waiting</option>
                                                <option value="resolved" <?= $latestAction === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                                            </select>
                                        </form>
                                    </div>
                                </div>

                                <div style="margin-top:6px;">Message: <?= e(mb_strimwidth((string)$reportRow['message'], 0, 240, '...')) ?></div>
                                <div class="small" style="margin-top:6px;color:#5a5a5a;">Submitted: <?= e((string)$reportRow['created_at']) ?></div>

                                <?php if (!empty($actions)): ?>
                                    <div style="margin-top:8px;padding-top:6px;border-top:1px dashed rgba(51,52,143,0.25);">
                                        <strong>Ticket History</strong>
                                        <ul style="margin:6px 0 0 16px;">
                                            <?php foreach ($actions as $act): ?>
                                                <li class="small" style="color:#5a5a5a;"><?= e((string)$act['action']) ?> by <?= e((string)$act['admin_id']) ?> on <?= e((string)$act['created_at']) ?><?php if (!empty($act['note'])): ?> — <?= e(mb_strimwidth((string)$act['note'],0,200,'...')) ?><?php endif; ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
        </div>
        <div class="form-actions">
            <a class="btn inline-btn" href="admin_dashboard.php">Back to Admin</a>
            <a class="btn inline-btn" href="logout.php">Logout</a>
        </div>
    </div>

    <div class="dashboard-card">
        <div class="chat-thread">
            <?php if (empty($messages)): ?>
                <div class="chat-empty">No messages in this conversation.</div>
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
            <input type="hidden" name="conversation_key" value="<?= e($conv) ?>">
            <?php if (!empty($reportId) && $reportId > 0): ?>
                <input type="hidden" name="report_id" value="<?= (int)$reportId ?>">
            <?php endif; ?>
            <div class="form-group">
                <label for="reply">Reply</label>
                <textarea id="reply" name="message" rows="4" required></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn">Send Reply</button>
            </div>
        </form>
    </div>
</div>
<script src="assets/js/auto-refresh.js"></script>
<script>mowlissAutoRefresh(20000);</script>
</body>
</html>
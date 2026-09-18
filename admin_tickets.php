<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_header.php';

if (empty($_SESSION['authenticated']) || empty($_SESSION['user_status']) || empty($_SESSION['user_id'])) {
    redirect('login.php');
}

if ($_SESSION['user_status'] !== 'admin') {
    redirect('dashboard.php');
}

function userDisplayName(string $role, array $row): string
{
    if (in_array($role, ['students', 'foremen'], true)) {
        return trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
    }

    if ($role === 'staff') {
        return trim((string)($row['full_name'] ?? ''));
    }

    return trim((string)($row['full_name'] ?? 'User'));
}

$categories = [
    'students' => ['label' => 'Student Chat', 'role' => 'student'],
    'staff' => ['label' => 'Employee Chat', 'role' => 'staff'],
    'foremen' => ['label' => 'Foreman Chat', 'role' => 'foreman'],
];

$category = strtolower((string)($_GET['category'] ?? 'students'));
if (!isset($categories[$category])) {
    $category = 'students';
}

$ticketId = isset($_GET['ticket']) ? (int)$_GET['ticket'] : 0;

// Load all chat_reports and group into tickets per category.
$ticketsByCategory = ['students' => [], 'staff' => [], 'foremen' => []];
$roleToCategory = ['student' => 'students', 'staff' => 'staff', 'foreman' => 'foremen'];

$reports = $pdo->query('SELECT * FROM chat_reports ORDER BY id DESC LIMIT 300')->fetchAll();

foreach ($reports as $r) {
    $origRole = strtolower((string)($r['submitter_role'] ?? ''));
    if (!isset($roleToCategory[$origRole])) {
        continue;
    }

    $cat = $roleToCategory[$origRole];
    $reportId = (int)$r['id'];
    $submitterId = (int)$r['submitter_id'];
    $convKey = $cat . ':' . $submitterId;

    $attStmt = $pdo->prepare('SELECT action FROM ticket_actions WHERE report_id = :rid ORDER BY id DESC LIMIT 1');
    $attStmt->execute(['rid' => $reportId]);
    $lastAction = strtolower((string)$attStmt->fetchColumn());
    $status = match ($lastAction) {
        'attended' => 'Attended',
        'resolved' => 'Resolved',
        'waiting' => 'Waiting',
        default => 'New',
    };

    $submitterName = (string)$submitterId;
    try {
        $uStmt = $pdo->prepare("SELECT * FROM {$cat} WHERE id = :id LIMIT 1");
        $uStmt->execute(['id' => $submitterId]);
        $uRow = $uStmt->fetch();
        if ($uRow) {
            $name = userDisplayName($cat, $uRow);
            $submitterName = ($name !== '' ? $name : (string)$submitterId) . ' (ID: ' . $submitterId . ')';
        }
    } catch (Exception $e) {
        // leave submitterName as the raw id
    }

    $subjectText = trim((string)($r['subject'] ?? ''));
    if ($subjectText === '') {
        $subjectText = mb_strimwidth((string)$r['message'], 0, 60, '...');
    }

    $ticketsByCategory[$cat][] = [
        'id' => $reportId,
        'conv' => $convKey,
        'submitter_id' => $submitterId,
        'submitter_name' => $submitterName,
        'subject' => $subjectText,
        'message' => $r['message'],
        'created_at' => $r['created_at'],
        'status' => $status,
        'origin' => (string)($r['origin'] ?? 'user'),
    ];
}

// Unread tickets = conversations with at least one un-viewed message from the user side.
$unreadRows = $pdo->query("SELECT conversation_key FROM chat_messages WHERE is_admin = 0 AND is_read = 0 GROUP BY conversation_key")->fetchAll();
$unreadConvKeys = array_column($unreadRows, 'conversation_key');

$unreadCountByCategory = ['students' => 0, 'staff' => 0, 'foremen' => 0];
foreach ($unreadConvKeys as $ck) {
    $prefix = explode(':', $ck)[0] ?? '';
    if (isset($unreadCountByCategory[$prefix])) {
        $unreadCountByCategory[$prefix]++;
    }
}

$activeTickets = $ticketsByCategory[$category];

// Load full conversation if a ticket is selected.
$selectedTicket = null;
$conversationMessages = [];
$ticketActions = [];

if ($ticketId > 0) {
    foreach ($activeTickets as $t) {
        if ($t['id'] === $ticketId) {
            $selectedTicket = $t;
            break;
        }
    }

    if ($selectedTicket !== null) {
        $conv = $selectedTicket['conv'];

        $msgStmt = $pdo->prepare('SELECT * FROM chat_messages WHERE conversation_key = :ck ORDER BY id ASC');
        $msgStmt->execute(['ck' => $conv]);
        $conversationMessages = $msgStmt->fetchAll();

        // mark as read now that admin is viewing it
        $pdo->prepare('UPDATE chat_messages SET is_read = 1 WHERE conversation_key = :ck')->execute(['ck' => $conv]);

        $aStmt = $pdo->prepare('SELECT * FROM ticket_actions WHERE report_id = :rid ORDER BY id ASC');
        try {
            $aStmt->execute(['rid' => $ticketId]);
            $ticketActions = $aStmt->fetchAll();
        } catch (PDOException $e) {
            $ticketActions = [];
        }
    }
}

$flash = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

$returnTo = 'admin_tickets.php?category=' . urlencode($category) . ($ticketId > 0 ? '&ticket=' . $ticketId : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Chat &amp; Tickets | MoWLiSS</title>
    <link rel="stylesheet" href="style.css?v=8">
</head>
<body>
<?php render_site_header(); ?>
<div class="container report-shell">
    <div class="report-header">
        <div>
            <h1>Admin Chat &amp; Tickets</h1>
            <p class="small">Respond to enquiries from students, staff, and foremen.</p>
        </div>
        <div class="form-actions">
            <a class="btn inline-btn" href="admin_dashboard.php">Back to Admin</a>
            <a class="btn inline-btn" href="logout.php">Logout</a>
        </div>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert-success"><?= e($flash) ?></div>
    <?php endif; ?>

    <div class="ticket-hub">
        <nav class="ticket-sidebar">
            <?php foreach ($categories as $key => $meta): ?>
                <a class="cat-link <?= $key === $category ? 'active' : '' ?>" href="admin_tickets.php?category=<?= urlencode($key) ?>">
                    <span><?= e($meta['label']) ?></span>
                    <?php if ($unreadCountByCategory[$key] > 0): ?>
                        <span class="cat-badge"><?= (int)$unreadCountByCategory[$key] ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="ticket-list-panel">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                <h3 style="margin:0;"><?= e($categories[$category]['label']) ?> Tickets</h3>
                <a class="btn small-btn" href="admin_compose.php?category=<?= urlencode($category) ?>">+ Compose</a>
            </div>
            <?php if (empty($activeTickets)): ?>
                <div class="chat-empty">No tickets in this category yet.</div>
            <?php else: ?>
                <?php foreach ($activeTickets as $t): ?>
                    <?php $isUnread = in_array($t['conv'], $unreadConvKeys, true); ?>
                    <a class="ticket-card <?= $t['id'] === $ticketId ? 'active' : '' ?>" href="admin_tickets.php?category=<?= urlencode($category) ?>&ticket=<?= (int)$t['id'] ?>">
                        <div class="ticket-card-top">
                            <span class="ticket-number">#<?= (int)$t['id'] ?></span>
                            <span class="ticket-tag ticket-tag-<?= e($category) ?>"><?= e($categories[$category]['label']) ?></span>
                            <?php if ($t['origin'] === 'admin'): ?><span class="ticket-tag" style="background:var(--gold);color:var(--navy-dark);">Sent by You</span><?php endif; ?>
                            <?php if ($isUnread): ?><span class="ticket-unread-dot" title="New message"></span><?php endif; ?>
                        </div>
                        <div class="ticket-subject"><?= e($t['subject']) ?></div>
                        <div class="ticket-meta">
                            <span><?= e($t['submitter_name']) ?></span>
                            <span class="status-badge <?= strtolower($t['status']) === 'new' ? 'pending' : 'approved' ?>"><?= e($t['status']) ?></span>
                        </div>
                        <div class="ticket-meta small"><?= e((string)$t['created_at']) ?></div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="ticket-conversation-panel">
            <?php if ($selectedTicket === null): ?>
                <div class="ticket-empty-state">
                    <p>Select a ticket from the list to view the conversation and reply.</p>
                </div>
            <?php else: ?>
                <div class="ticket-summary">
                    <div style="display:flex;align-items:center;gap:12px;justify-content:space-between;flex-wrap:wrap;">
                        <div>
                            <strong style="color:var(--navy-dark);">Ticket #<?= (int)$selectedTicket['id'] ?></strong>
                            <div class="small" style="margin-top:6px;color:var(--text-muted);">From: <?= e($selectedTicket['submitter_name']) ?></div>
                        </div>
                        <div>
                            <?php
                                $latestAction = '';
                                if (!empty($ticketActions)) {
                                    $la = end($ticketActions);
                                    $latestAction = strtolower((string)$la['action']);
                                }
                            ?>
                            <form method="POST" action="update_ticket_status.php">
                                <input type="hidden" name="report_id" value="<?= (int)$selectedTicket['id'] ?>">
                                <select name="action" onchange="this.form.submit()" style="padding:6px 10px;border-radius:8px;border:1px solid rgba(51,52,143,0.3);background:#f5f5fa;color:#1B1C5E;">
                                    <option value="attended" <?= $latestAction === 'attended' ? 'selected' : '' ?>>Attended</option>
                                    <option value="waiting" <?= $latestAction === 'waiting' ? 'selected' : '' ?>>Waiting</option>
                                    <option value="resolved" <?= $latestAction === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                                </select>
                            </form>
                        </div>
                    </div>
                    <div style="margin-top:6px;">Subject: <?= e($selectedTicket['subject']) ?></div>
                    <div class="small" style="margin-top:6px;color:var(--text-muted);">Submitted: <?= e((string)$selectedTicket['created_at']) ?></div>
                </div>

                <div class="chat-thread" style="margin-top:16px;">
                    <?php if (empty($conversationMessages)): ?>
                        <div class="chat-empty">No messages yet.</div>
                    <?php else: ?>
                        <?php foreach ($conversationMessages as $m): ?>
                            <div class="chat-message <?= (int)$m['is_admin'] === 1 ? 'from-admin' : 'from-user' ?>">
                                <div class="msg-meta"><?= (int)$m['is_admin'] === 1 ? 'Admin' : e($selectedTicket['submitter_name']) ?> &mdash; <?= e((string)$m['created_at']) ?></div>
                                <div class="msg-body"><?= nl2br(e((string)$m['message'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <form method="POST" action="chat_submit.php" style="margin-top:16px;">
                    <input type="hidden" name="conversation_key" value="<?= e($selectedTicket['conv']) ?>">
                    <input type="hidden" name="report_id" value="<?= (int)$selectedTicket['id'] ?>">
                    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                    <div class="form-group">
                        <label for="reply">Reply</label>
                        <textarea id="reply" name="message" rows="4" required placeholder="Type your reply here..."></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn">Send Reply</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>

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

$category = strtolower((string)($_GET['category'] ?? $_POST['category'] ?? 'students'));
if (!isset($categories[$category])) {
    $category = 'students';
}

$errors = [];
$subjectValue = '';
$messageValue = '';
$selectedRecipient = isset($_POST['recipient_id']) ? (int)$_POST['recipient_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subjectValue = trim((string)($_POST['subject'] ?? ''));
    $messageValue = trim((string)($_POST['message'] ?? ''));

    if ($selectedRecipient <= 0) {
        $errors[] = 'Please select a recipient.';
    }

    if ($subjectValue === '') {
        $errors[] = 'Subject is required.';
    }

    if ($messageValue === '') {
        $errors[] = 'Message is required.';
    }

    if (empty($errors)) {
        $recipientStmt = $pdo->prepare("SELECT id FROM {$category} WHERE id = :id LIMIT 1");
        $recipientStmt->execute(['id' => $selectedRecipient]);

        if ($recipientStmt->fetchColumn() === false) {
            $errors[] = 'Selected recipient could not be found.';
        }
    }

    if (empty($errors)) {
        $singularRole = $categories[$category]['role'];
        $convKey = $category . ':' . $selectedRecipient;

        $ins = $pdo->prepare(
            'INSERT INTO chat_reports (room, submitter_id, submitter_role, message, subject, origin)
             VALUES (:room, :sid, :srole, :message, :subject, :origin)'
        );
        $ins->execute([
            'room' => 'admin_initiated',
            'sid' => $selectedRecipient,
            'srole' => $singularRole,
            'message' => $messageValue,
            'subject' => $subjectValue,
            'origin' => 'admin',
        ]);
        $newReportId = (int)$pdo->lastInsertId();

        $msgIns = $pdo->prepare(
            'INSERT INTO chat_messages (conversation_key, sender_role, sender_id, message, is_admin, report_id)
             VALUES (:ck, :srole, :sid, :msg, 1, :rid)'
        );
        $msgIns->execute([
            'ck' => $convKey,
            'srole' => 'admin',
            'sid' => (int)$_SESSION['user_id'],
            'msg' => $messageValue,
            'rid' => $newReportId,
        ]);

        $_SESSION['flash_message'] = 'Message sent.';
        redirect('admin_tickets.php?category=' . urlencode($category) . '&ticket=' . $newReportId);
    }
}

// Load recipients for the current category.
$recipients = [];
try {
    $rStmt = $pdo->query("SELECT * FROM {$category} ORDER BY id ASC");
    foreach ($rStmt->fetchAll() as $row) {
        $name = userDisplayName($category, $row);
        $status = strtolower((string)($row['account_status'] ?? ''));
        $recipients[] = [
            'id' => (int)$row['id'],
            'name' => ($name !== '' ? $name : 'User') . ' (ID: ' . (int)$row['id'] . ')' . ($status !== '' ? ' — ' . ucfirst($status) : ''),
        ];
    }
} catch (Exception $e) {
    $recipients = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compose Message | MoWLiSS Admin</title>
    <link rel="stylesheet" href="style.css?v=8">
</head>
<body>
<?php render_site_header(); ?>
<div class="container report-shell">
    <div class="report-header">
        <div>
            <h1>Compose Message</h1>
            <p class="small">Start a new conversation with a student, staff, or foreman account.</p>
        </div>
        <div class="form-actions">
            <a class="btn inline-btn" href="admin_tickets.php?category=<?= urlencode($category) ?>">Back to Tickets</a>
            <a class="btn inline-btn" href="logout.php">Logout</a>
        </div>
    </div>

    <div class="ticket-hub">
        <nav class="ticket-sidebar">
            <?php foreach ($categories as $key => $meta): ?>
                <a class="cat-link <?= $key === $category ? 'active' : '' ?>" href="admin_compose.php?category=<?= urlencode($key) ?>">
                    <span><?= e($meta['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="ticket-conversation-panel" style="grid-column: span 2;">
            <h3 style="color:var(--navy-dark);margin-top:0;">New Message &mdash; <?= e($categories[$category]['label']) ?></h3>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div>
            <?php endif; ?>

            <?php if (empty($recipients)): ?>
                <div class="chat-empty">No users found in this category yet.</div>
            <?php else: ?>
                <form method="POST" action="admin_compose.php">
                    <input type="hidden" name="category" value="<?= e($category) ?>">

                    <div class="form-group">
                        <label for="recipient_id">Send To</label>
                        <select id="recipient_id" name="recipient_id" required>
                            <option value="">-- Select a user --</option>
                            <?php foreach ($recipients as $r): ?>
                                <option value="<?= (int)$r['id'] ?>" <?= $selectedRecipient === $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="subject">Subject</label>
                        <input type="text" id="subject" name="subject" maxlength="150" required value="<?= e($subjectValue) ?>" placeholder="e.g. Update on your device enrollment">
                    </div>

                    <div class="form-group">
                        <label for="message">Message</label>
                        <textarea id="message" name="message" rows="6" required placeholder="Type your message here..."><?= e($messageValue) ?></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn" style="display:inline-block;width:auto;">Send Message</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>

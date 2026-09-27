<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_header.php';

if (empty($_SESSION['authenticated']) || empty($_SESSION['user_status']) || empty($_SESSION['user_id'])) {
    redirect('login.php');
}

$validStatuses = ['student', 'staff', 'foreman'];
if (!in_array($_SESSION['user_status'], $validStatuses, true)) {
    redirect('dashboard.php');
}

$backPageMap = [
    'student' => 'student_dashboard.php',
    'staff' => 'staff_dashboard.php',
    'foreman' => 'foreman_dashboard.php',
];
$backPage = $backPageMap[$_SESSION['user_status']] ?? 'dashboard.php';

$reportTitle = 'RPNGC Chatroom Report';
$reportCode = 'RPNGC-FRM-2026';
$roomKey = 'RPNGC';
$categoryOptions = [
    'Site Safety Issue',
    'Incident Report',
    'Vehicle / Equipment',
    'Emergency',
    'Other',
];

function ticket_status_label(PDO $pdo, int $reportId): string
{
    $stmt = $pdo->prepare('SELECT action FROM ticket_actions WHERE report_id = :rid ORDER BY id DESC LIMIT 1');
    $stmt->execute(['rid' => $reportId]);
    return match (strtolower((string)$stmt->fetchColumn())) {
        'attended' => 'Attended',
        'resolved' => 'Resolved',
        'waiting' => 'Waiting',
        default => 'New',
    };
}

function status_badge_class(string $status): string
{
    return match ($status) {
        'Resolved' => 'approved',
        'Attended' => 'attended',
        default => 'pending',
    };
}

// Every number below is computed from real chat_reports/ticket_actions rows for this
// room - never a hardcoded placeholder standing in for real activity.
$roomReportsStmt = $pdo->prepare('SELECT id, submitter_role, submitter_id FROM chat_reports WHERE room = :room');
$roomReportsStmt->execute(['room' => $roomKey]);
$roomReportRows = $roomReportsStmt->fetchAll();

$totalCount = count($roomReportRows);
$resolvedCount = 0;
$pendingCount = 0;
$activeSubmitters = [];

foreach ($roomReportRows as $rr) {
    $status = ticket_status_label($pdo, (int)$rr['id']);
    if ($status === 'Resolved') {
        $resolvedCount++;
    } else {
        $pendingCount++;
        $activeSubmitters[$rr['submitter_role'] . ':' . $rr['submitter_id']] = true;
    }
}

$stats = [
    ['label' => 'Active Reporters', 'value' => (string)count($activeSubmitters)],
    ['label' => 'Total Reports', 'value' => (string)$totalCount],
    ['label' => 'Resolved', 'value' => (string)$resolvedCount],
    ['label' => 'Pending', 'value' => (string)$pendingCount],
];

// The current user's own report history to this room specifically - what they sent,
// when, what type, and its current status - so they can see exactly what they last
// reported and whether it's been looked at, instead of submitting into a void.
$historyStmt = $pdo->prepare(
    'SELECT id, subject, category, message, created_at
     FROM chat_reports
     WHERE room = :room AND submitter_role = :role AND submitter_id = :uid
     ORDER BY id DESC
     LIMIT 20'
);
$historyStmt->execute([
    'room' => $roomKey,
    'role' => (string)$_SESSION['user_status'],
    'uid' => (int)$_SESSION['user_id'],
]);
$myHistory = $historyStmt->fetchAll();
foreach ($myHistory as &$h) {
    $h['status'] = ticket_status_label($pdo, (int)$h['id']);
}
unset($h);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/img/favicon.png?v=1">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($reportTitle) ?> | MoWLiSS</title>
    <link rel="stylesheet" href="style.css?v=8">
</head>
<body>
<?php render_site_header(); ?>
    <div class="container report-shell">
        <div class="report-header">
            <div class="room-title-row">
                <img src="assets/img/rpngc_logo.png?v=1" alt="RPNGC logo" class="room-logo">
                <div>
                    <h1><?= e($reportTitle) ?></h1>
                    <p class="small">Report ID: <?= e($reportCode) ?></p>
                </div>
            </div>
            <div class="form-actions">
                <a class="btn inline-btn" href="<?= e($backPage) ?>">Back to Dashboard</a>
                <a class="btn inline-btn" href="logout.php">Logout</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['flash_message'])): ?>
            <div class="alert alert-success"><?= e($_SESSION['flash_message']) ?></div>
            <?php unset($_SESSION['flash_message']); ?>
        <?php endif; ?>

        <div class="report-grid">
            <?php foreach ($stats as $stat): ?>
                <div class="report-card">
                    <h3><?= e($stat['label']) ?></h3>
                    <div class="metric-value"><?= e($stat['value']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="dashboard-card">
            <h2>Your Report History</h2>
            <?php if (empty($myHistory)): ?>
                <p class="history-empty">You haven't reported anything to RPNGC yet. Use the form below to submit your first report.</p>
            <?php else: ?>
                <ul class="history-list">
                    <?php foreach ($myHistory as $h): ?>
                        <li class="history-item">
                            <div class="history-item-top">
                                <span class="history-item-subject"><?= e((string)$h['subject']) ?></span>
                                <span class="status-badge <?= e(status_badge_class($h['status'])) ?>"><?= e($h['status']) ?></span>
                            </div>
                            <div class="history-item-meta small">
                                Last reported <?= e((string)$h['created_at']) ?>
                                <?php if (!empty($h['category'])): ?> &middot; <?= e((string)$h['category']) ?><?php endif; ?>
                            </div>
                            <div class="history-item-message"><?= e((string)$h['message']) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="dashboard-card">
            <h2>Submit a Report</h2>
            <form class="report-form" method="POST" action="submit_report.php">
                <input type="hidden" name="room" value="<?= e($roomKey) ?>">
                <div class="form-group">
                    <label for="report-subject">Subject</label>
                    <input id="report-subject" type="text" name="subject" placeholder="Short summary of the issue" required>
                </div>
                <div class="form-group">
                    <label for="report-category">Message Type</label>
                    <select id="report-category" name="category">
                        <?php foreach ($categoryOptions as $opt): ?>
                            <option value="<?= e($opt) ?>"><?= e($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="report-message">Issue Details</label>
                    <textarea id="report-message" name="message" rows="5" placeholder="Describe site issue, daily activity, or safety concern..." required></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn inline-btn">Submit Report</button>
                </div>
            </form>
        </div>
    </div>

    <footer class="footer-app">
        <div class="container">
            <div>
                <h3>Download the MoWLiSS App</h3>
                <p class="small">Download the app for field-friendly reporting and monitoring.</p>
            </div>
            <div class="download-grid">
                <a class="download-btn" href="downloads/mowliss-mobile-app.html" download>Download App</a>
                <a class="download-btn secondary" href="downloads/mowliss-mobile-app.html">View App Info</a>
            </div>
        </div>
    </footer>
</body>
</html>

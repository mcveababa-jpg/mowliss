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

$reportTitle = 'DICT Chatroom Report';
$reportCode = 'DICT-STAFF-2026';
$stats = [
    ['label' => 'Live Chatrooms', 'value' => '9'],
    ['label' => 'Escalations', 'value' => '5'],
    ['label' => 'Open Cases', 'value' => '3'],
    ['label' => 'Mitigated', 'value' => '4'],
];
$items = [
    ['label' => 'Admin Updates', 'value' => 'Updated'],
    ['label' => 'Department Notices', 'value' => 'Shared'],
    ['label' => 'User Reports', 'value' => 'Reviewed'],
    ['label' => 'Security Audit', 'value' => 'Complete'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/img/favicon.png?v=1">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($reportTitle) ?> | MoWLiSS</title>
    <link rel="stylesheet" href="style.css?v=7">
</head>
<body>
<?php render_site_header(); ?>
    <div class="container report-shell">
        <div class="report-header">
            <div class="room-title-row">
                <img src="assets/img/dict_logo.png?v=1" alt="DICT logo" class="room-logo">
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
            <h2>Chatroom Summary</h2>
            <ul class="report-list">
                <?php foreach ($items as $item): ?>
                    <li>
                        <span><?= e($item['label']) ?></span>
                        <strong><?= e($item['value']) ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="dashboard-card">
            <h2>Submit a Report</h2>
            <form class="report-form" method="POST" action="submit_report.php">
                <input type="hidden" name="room" value="DICT">
                <div class="form-group">
                    <label for="room-name">Room / Chat ID</label>
                    <input id="room-name" type="text" value="DICT Room 02" readonly>
                </div>
                <div class="form-group">
                    <label for="report-message">Issue Details</label>
                    <textarea id="report-message" name="message" rows="5" placeholder="Describe staff concern, room update, or threat report..."></textarea>
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
                <p class="small">Use the app to keep staff updates and reports mobile-friendly.</p>
            </div>
            <div class="download-grid">
                <a class="download-btn" href="downloads/mowliss-mobile-app.html" download>Download App</a>
                <a class="download-btn secondary" href="downloads/mowliss-mobile-app.html">View App Info</a>
            </div>
        </div>
    </footer>
</body>
</html>

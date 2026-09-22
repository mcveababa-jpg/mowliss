<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_header.php';

if (empty($_SESSION['authenticated']) || empty($_SESSION['user_status']) || empty($_SESSION['user_id'])) {
    redirect('login.php');
}

$validStatuses = ['student', 'staff', 'foreman', 'admin'];
if (!in_array($_SESSION['user_status'], $validStatuses, true)) {
    redirect('dashboard.php');
}

$backPageMap = [
    'student' => 'student_dashboard.php',
    'staff' => 'staff_dashboard.php',
    'foreman' => 'foreman_dashboard.php',
    'admin' => 'admin_dashboard.php',
];
$backPage = $backPageMap[$_SESSION['user_status']] ?? 'dashboard.php';

$reportTitle = 'ADMIN Chatroom Report';
$reportCode = 'ADMIN-2026';
$stats = [
    ['label' => 'Active Rooms', 'value' => '4'],
    ['label' => 'Flagged Issues', 'value' => '1'],
    ['label' => 'Resolved', 'value' => '0'],
    ['label' => 'Pending', 'value' => '1'],
];
$items = [
    ['label' => 'Admin Alerts', 'value' => 'Open'],
    ['label' => 'Policy Notices', 'value' => 'Review'],
    ['label' => 'System Health', 'value' => 'Good'],
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
            <div>
                <h1><?= e($reportTitle) ?></h1>
                <p class="small">Report ID: <?= e($reportCode) ?></p>
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
            <h2>Admin Chatroom (Organization Links)</h2>
            <p class="small">This chatroom area is reserved for organization-level channels (NICTA, DICT, RPNGC). Ticketing for admin communication with users is handled from user-submitted reports. Admins should not submit user tickets here.</p>
            <ul>
                <li>NICTA (organization) — linked later</li>
                <li>DICT (organization) — linked later</li>
                <li>RPNGC (organization) — linked later</li>
            </ul>
        </div>
    </div>

    <footer class="footer-app">
        <div class="container">
            <div>
                <h3>Download the MoWLiSS App</h3>
                <p class="small">Get quick access to chatroom reporting and monitoring from any device.</p>
            </div>
            <div class="download-grid">
                <a class="download-btn" href="downloads/mowliss-mobile-app.html" download>Download App</a>
                <a class="download-btn secondary" href="downloads/mowliss-mobile-app.html">View App Info</a>
            </div>
        </div>
    </footer>
</body>
</html>

<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_header.php';

if (empty($_SESSION['authenticated']) || empty($_SESSION['user_status']) || empty($_SESSION['user_id'])) {
    redirect('login.php');
}

if ($_SESSION['user_status'] !== 'student') {
    redirect('dashboard.php');
}

$stmt = $pdo->prepare(
    "SELECT *
     FROM students
     WHERE id = :id
     LIMIT 1"
);

$stmt->execute(['id' => (int)$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    redirect('logout.php');
}

$details = [
    'Student ID' => $user['student_id'] ?? '',
    'First Name' => $user['first_name'] ?? '',
    'Last Name' => $user['last_name'] ?? '',
    'Program' => $user['program'] ?? '',
    'Department' => $user['department'] ?? '',
    'Phone Number' => $user['phone_number'] ?? 'Not provided',
    'Date of Birth' => $user['date_of_birth'] ?? 'Not provided',
    'Account Created' => $user['created_at'] ?? '',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/img/favicon.png?v=1">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | MoWLiSS</title>
    <link rel="stylesheet" href="style.css?v=7">
</head>
<body>
<?php render_site_header(); ?>
<div class="container">
    <div class="dashboard-top">
        <div>
            <h1>MoWLiSS Dashboard</h1>
            <p class="small">Student Portal</p>
        </div>

        <div>
            <a class="btn" style="display:inline-block; width:auto; text-decoration:none;" href="logout.php">Logout</a>
        </div>
    </div>

    <div class="dashboard-card">
        <h2>Welcome, <?= e($_SESSION['user_name'] ?? 'Student') ?></h2>
        <p class="small">You are logged in as Student.</p>
    </div>

    <?php require_once __DIR__ . '/dashboard_chatrooms.php'; render_dashboard_chatrooms('student'); ?>

    <div class="dashboard-card">
        <h2>Student Account Details</h2>
  
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Field</th>
                        <th>Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($details as $label => $value): ?>
                        <tr>
                            <th><?= e($label) ?></th>
                            <td><?= e((string)$value) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php require_once __DIR__ . '/dashboard_chatrooms.php'; render_dashboard_app_footer(); ?>
</div>
<script src="assets/js/auto-refresh.js"></script>
<script>mowlissAutoRefresh(20000);</script>
</body>
</html>

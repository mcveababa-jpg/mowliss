<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_header.php';

if (
    empty($_SESSION['authenticated'])
    || empty($_SESSION['user_status'])
    || empty($_SESSION['user_id'])
) {
    redirect('login.php');
}

$status = $_SESSION['user_status'];

$redirectMap = [
    'student' => 'student_dashboard.php',
    'staff' => 'staff_dashboard.php',
    'foreman' => 'foreman_dashboard.php',
    'admin' => 'admin_dashboard.php',
];

if (isset($redirectMap[$status])) {
    redirect($redirectMap[$status]);
}

$userStatusMap = [
    'student' => [
        'table' => 'students',
        'id_column' => 'student_id',
        'title' => 'Student Portal'
    ],
    'staff' => [
        'table' => 'staff',
        'id_column' => 'worker_reg_no',
        'title' => 'Staff Portal'
    ],
    'foreman' => [
        'table' => 'foremen',
        'id_column' => 'foreman_reg_no',
        'title' => 'Foreman Portal'
    ],
    'admin' => [
        'table' => 'admins',
        'id_column' => 'admin_id',
        'title' => 'ICT Admin Portal'
    ],
];

if (!isset($userStatusMap[$status])) {
    redirect('logout.php');
}

$config = $userStatusMap[$status];

$stmt = $pdo->prepare(
    "SELECT *
     FROM {$config['table']}
     WHERE id = :id
     LIMIT 1"
);

$stmt->execute(['id' => (int)$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    redirect('logout.php');
}

$details = [];

if ($status === 'student') {
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
}

if ($status === 'staff') {
    $details = [
        'Worker Reg No.' => $user['worker_reg_no'] ?? '',
        'Full Name' => $user['full_name'] ?? '',
        'Department' => $user['department'] ?? '',
        'Compound' => $user['compound'] ?? 'Not provided',
        'Phone Number' => $user['phone_number'] ?? 'Not provided',
        'Date of Birth' => $user['date_of_birth'] ?? 'Not provided',
        'Account Created' => $user['created_at'] ?? '',
    ];
}

if ($status === 'foreman') {
    $details = [
        'Foreman Reg No.' => $user['foreman_reg_no'] ?? '',
        'First Name' => $user['first_name'] ?? '',
        'Last Name' => $user['last_name'] ?? '',
        'Department' => $user['department'] ?? '',
        'Phone Number' => $user['phone_number'] ?? 'Not provided',
        'Date of Birth' => $user['date_of_birth'] ?? 'Not provided',
        'Account Created' => $user['created_at'] ?? '',
    ];
}

if ($status === 'admin') {
    $details = [
        'Admin ID' => $user['admin_id'] ?? '',
        'Full Name' => $user['full_name'] ?? 'Not provided',
        'Account Created' => $user['created_at'] ?? '',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | MoWLiSS</title>
    <link rel="stylesheet" href="style.css?v=7">
</head>
<body>
<?php render_site_header(); ?>
<div class="container">
    <div class="dashboard-top">
        <div>
            <h1>MoWLiSS Dashboard</h1>
            <p class="small"><?= e($config['title']) ?></p>
        </div>

        <div>
            <a class="btn" style="display:inline-block; width:auto; text-decoration:none;" href="logout.php">Logout</a>
        </div>
    </div>

    <div class="dashboard-card">
        <h2>Welcome, <?= e($_SESSION['user_name'] ?? 'User') ?></h2>
        <p class="small">
            You are logged in as <?= e(ucfirst($status)) ?>.
        </p>
    </div>

    <div class="dashboard-card">
        <h2>Account Details</h2>

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
</div>
</body>
</html>
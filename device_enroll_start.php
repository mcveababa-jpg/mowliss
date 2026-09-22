<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_header.php';

if (empty($_SESSION['authenticated']) || empty($_SESSION['user_status']) || empty($_SESSION['user_id'])) {
    redirect('login.php');
}

$ownerRole = (string)$_SESSION['user_status'];
$ownerId = (int)$_SESSION['user_id'];

$eligibleRoles = ['student', 'staff', 'foreman'];
if (!in_array($ownerRole, $eligibleRoles, true)) {
    redirect('dashboard.php');
}

function generate_enrollment_code(): string
{
    // Unambiguous charset: no 0/O, 1/I/L, to keep manual entry on the desktop agent error-free.
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $csrf = (string)($_POST['csrf_token'] ?? '');

    if (empty($_SESSION['device_enroll_csrf']) || !hash_equals($_SESSION['device_enroll_csrf'], $csrf)) {
        $_SESSION['flash_message'] = 'Security check failed, please try again.';
        redirect('device_enroll_start.php');
    }

    if ($action === 'generate') {
        $deviceStmt = $pdo->prepare("SELECT id FROM devices WHERE owner_role = :r AND owner_id = :id AND status != 'deleted' LIMIT 1");
        $deviceStmt->execute(['r' => $ownerRole, 'id' => $ownerId]);

        if ($deviceStmt->fetch() === false) {
            $pdo->prepare(
                "UPDATE enrollment_codes SET status = 'expired'
                 WHERE owner_role = :r AND owner_id = :id AND status = 'pending'"
            )->execute(['r' => $ownerRole, 'id' => $ownerId]);

            $code = generate_enrollment_code();
            $stmt = $pdo->prepare(
                "INSERT INTO enrollment_codes (owner_role, owner_id, code, status, expires_at, created_ip)
                 VALUES (:r, :id, :code, 'pending', DATE_ADD(NOW(), INTERVAL 10 MINUTE), :ip)"
            );
            $stmt->execute([
                'r' => $ownerRole,
                'id' => $ownerId,
                'code' => $code,
                'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            ]);

            $pdo->prepare(
                "INSERT INTO device_events (owner_role, owner_id, actor_type, actor_id, event_type, event_detail)
                 VALUES (:r, :id, 'system', :actor, 'enrollment_code_generated', :detail)"
            )->execute([
                'r' => $ownerRole,
                'id' => $ownerId,
                'actor' => (string)$ownerId,
                'detail' => json_encode(['expires_in_minutes' => 10]),
            ]);
        }
    }

    redirect('device_enroll_start.php');
}

if (empty($_SESSION['device_enroll_csrf'])) {
    $_SESSION['device_enroll_csrf'] = bin2hex(random_bytes(16));
}

$roleTableMap = ['student' => 'students', 'staff' => 'staff', 'foreman' => 'foremen'];
$table = $roleTableMap[$ownerRole];

$deviceStmt = $pdo->prepare("SELECT * FROM devices WHERE owner_role = :r AND owner_id = :id AND status != 'deleted' ORDER BY enrolled_at DESC LIMIT 1");
$deviceStmt->execute(['r' => $ownerRole, 'id' => $ownerId]);
$device = $deviceStmt->fetch();

$codeStmt = $pdo->prepare("SELECT * FROM enrollment_codes WHERE owner_role = :r AND owner_id = :id AND status = 'pending' AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
$codeStmt->execute(['r' => $ownerRole, 'id' => $ownerId]);
$pendingCode = $codeStmt->fetch();

$flashMessage = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/img/favicon.png?v=1">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enroll a Device | MoWLiSS</title>
    <link rel="stylesheet" href="style.css?v=7">
</head>
<body>
<?php render_site_header(); ?>
<div class="container">
    <div class="dashboard-top">
        <div>
            <h1>Enroll a Device</h1>
            <p class="small">MoWLiSS Agent</p>
        </div>
        <div>
            <a class="btn" style="display:inline-block; width:auto; text-decoration:none;" href="dashboard.php">Back to Dashboard</a>
        </div>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="alert alert-success"><?= e($flashMessage) ?></div>
    <?php endif; ?>

    <div class="dashboard-card">
        <h2>How this works</h2>
        <p>Enrolling links the MoWLiSS Agent installed on your device to your account. Once enrolled, the agent shows a
        permanent tray icon so you always know it's active, keeps its own activity log you can open at any time, and can
        only be removed by an administrator (never silently). Only <strong>your own logged-in account</strong> can
        generate a code below, so nobody else can enroll a device against your account without you being signed in.</p>
    </div>

    <?php if ($device): ?>
        <div class="dashboard-card">
            <h2>Device Already Enrolled</h2>
            <table>
                <tbody>
                    <tr><th>Device Name</th><td><?= e((string)($device['device_name'] ?? 'Unknown')) ?></td></tr>
                    <tr><th>Status</th><td><span class="status-badge <?= $device['status'] === 'active' ? 'approved' : 'pending' ?>"><?= e((string)$device['status']) ?></span></td></tr>
                    <tr><th>Enrolled At</th><td><?= e((string)$device['enrolled_at']) ?></td></tr>
                    <tr><th>Last Seen</th><td><?= e((string)($device['last_poll_at'] ?? 'Never')) ?></td></tr>
                </tbody>
            </table>
            <p class="small">This account already has an enrolled device. Only an administrator can remove it (App Delete)
            before a new one can be enrolled.</p>
        </div>
    <?php elseif ($pendingCode): ?>
        <div class="dashboard-card">
            <h2>Your Enrollment Code</h2>
            <p style="font-size:2rem; letter-spacing:4px; font-weight:bold;"><?= e((string)$pendingCode['code']) ?></p>
            <p class="small">Enter this code in the MoWLiSS Agent desktop app within 10 minutes (expires
            <?= e((string)$pendingCode['expires_at']) ?>). Generating a new code will invalidate this one.</p>
            <form method="POST" class="inline-form">
                <input type="hidden" name="action" value="generate">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['device_enroll_csrf']) ?>">
                <button type="submit" class="btn">Generate a New Code</button>
            </form>
        </div>
    <?php else: ?>
        <div class="dashboard-card">
            <h2>Get Started</h2>
            <form method="POST">
                <input type="hidden" name="action" value="generate">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['device_enroll_csrf']) ?>">
                <button type="submit" class="btn">Generate Enrollment Code</button>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>

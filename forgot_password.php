<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_header.php';
require_once __DIR__ . '/sms.php';

$userStatusMap = [
    'student' => ['table' => 'students', 'id_column' => 'student_id', 'label' => 'Student'],
    'staff' => ['table' => 'staff', 'id_column' => 'worker_reg_no', 'label' => 'Staff'],
    'foreman' => ['table' => 'foremen', 'id_column' => 'foreman_reg_no', 'label' => 'Foreman'],
    'admin' => ['table' => 'admins', 'id_column' => 'admin_id', 'label' => 'Admin'],
];

function resetPasswordLookup(PDO $pdo, string $status, string $identifier): ?array
{
    global $userStatusMap;

    if (!isset($userStatusMap[$status])) {
        return null;
    }

    $config = $userStatusMap[$status];
    $stmt = $pdo->prepare("SELECT * FROM {$config['table']} WHERE {$config['id_column']} = :identifier LIMIT 1");
    $stmt->execute(['identifier' => $identifier]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

function normalizePhone(string $value): string
{
    return preg_replace('/\s+/', '', trim($value)) ?? '';
}

$status = trim((string)($_POST['user_status'] ?? $_GET['status'] ?? ''));
$identifier = trim((string)($_POST['identifier'] ?? ''));
$phoneInput = trim((string)($_POST['phone_number'] ?? ''));
$resetCode = trim((string)($_POST['reset_code'] ?? ''));
$message = '';
$messageType = 'info';
$showCodeStep = false;
$generatedCode = '';
$matchedAccount = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'request_reset';

    if ($action === 'request_reset') {
        $status = trim((string)($_POST['user_status'] ?? ''));
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $phoneInput = trim((string)($_POST['phone_number'] ?? ''));

        if (!isset($userStatusMap[$status])) {
            $message = 'Please choose a valid user status.';
            $messageType = 'error';
        } elseif ($identifier === '') {
            $message = 'Please enter your ID or registration number.';
            $messageType = 'error';
        } elseif ($phoneInput === '') {
            $message = 'Please enter the phone number linked to your account.';
            $messageType = 'error';
        } else {
            $account = resetPasswordLookup($pdo, $status, $identifier);
            $storedPhone = normalizePhone((string)($account['phone_number'] ?? ''));
            $inputPhone = normalizePhone((string)$phoneInput);

            if (!$account || $storedPhone === '' || strtolower($storedPhone) !== strtolower($inputPhone)) {
                $message = 'We could not match that account with the phone number on file. Please check your details or contact ICT support.';
                $messageType = 'error';
            } else {
                $generatedCode = (string)random_int(100000, 999999);
                $smsResult = sendPasswordResetSms($storedPhone, $generatedCode);

                if (!$smsResult['success']) {
                    $message = $smsResult['error'] ?? 'We could not send the reset code. Please try again or contact ICT support.';
                    $messageType = 'error';
                } else {
                    $expiresAt = date('Y-m-d H:i:s', time() + 900);

                    $stmt = $pdo->prepare(
                        'INSERT INTO password_reset_requests (user_status, user_identifier, phone_number, reset_code, expires_at, created_at)
                         VALUES (:user_status, :user_identifier, :phone_number, :reset_code, :expires_at, NOW())'
                    );
                    $stmt->execute([
                        'user_status' => $status,
                        'user_identifier' => $identifier,
                        'phone_number' => $phoneInput,
                        'reset_code' => $generatedCode,
                        'expires_at' => $expiresAt,
                    ]);

                    $showCodeStep = true;
                    $message = 'We found your record and sent a 6-digit code by SMS to the phone number on file. Enter it below to finish resetting your password. This code expires in 15 minutes.';
                    $messageType = 'success';
                    $matchedAccount = $account;
                }
            }
        }
    }

    if ($action === 'finalize_reset') {
        $status = trim((string)($_POST['user_status'] ?? ''));
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $resetCode = trim((string)($_POST['reset_code'] ?? ''));
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if (!isset($userStatusMap[$status])) {
            $message = 'Please select a valid user status.';
            $messageType = 'error';
        } elseif ($identifier === '') {
            $message = 'The ID number is required.';
            $messageType = 'error';
        } elseif ($resetCode === '') {
            $message = 'Please enter the 6-digit reset code.';
            $messageType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $message = 'Your new password must be at least 8 characters long.';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'The new password and confirmation do not match.';
            $messageType = 'error';
        } else {
            $stmt = $pdo->prepare(
                'SELECT * FROM password_reset_requests
                 WHERE user_status = :user_status
                   AND user_identifier = :user_identifier
                   AND reset_code = :reset_code
                   AND used_at IS NULL
                   AND expires_at > NOW()
                 ORDER BY created_at DESC
                 LIMIT 1'
            );
            $stmt->execute([
                'user_status' => $status,
                'user_identifier' => $identifier,
                'reset_code' => $resetCode,
            ]);
            $resetRequest = $stmt->fetch();

            if (!$resetRequest) {
                $message = 'The reset code is invalid or has expired. Please request a new one.';
                $messageType = 'error';
            } else {
                $config = $userStatusMap[$status];
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);

                $update = $pdo->prepare(
                    "UPDATE {$config['table']}
                     SET password_hash = :password_hash,
                         remember_token = NULL,
                         updated_at = NOW()
                     WHERE {$config['id_column']} = :identifier"
                );
                $update->execute([
                    'password_hash' => $hash,
                    'identifier' => $identifier,
                ]);

                $markUsed = $pdo->prepare('UPDATE password_reset_requests SET used_at = NOW() WHERE id = :id');
                $markUsed->execute(['id' => (int)$resetRequest['id']]);

                $message = 'Password reset successful. You can now log in with your new password.';
                $messageType = 'success';
                $showCodeStep = false;
                $status = '';
                $identifier = '';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | MoWLiSS</title>
    <link rel="stylesheet" href="style.css?v=7">
</head>
<body>
<?php render_site_header(); ?>
<header class="topbar">
    <div class="container">
        <a href="login.html">Back to Login</a>
        <a href="help.php">Need help?</a>
    </div>
</header>

<div class="auth-wrapper">
    <div class="auth-card wide">
        <div class="brand">
            <h1>Reset Your Password</h1>
            <p>Use your registered ID and phone number to reset your password securely.</p>
        </div>

        <?php if ($message !== ''): ?>
            <div class="message message-<?= htmlspecialchars((string)$messageType, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($showCodeStep): ?>
            <div class="support-box">
                <strong>Check your phone</strong>
                <p>We texted a 6-digit reset code to the phone number on file. It expires in 15 minutes.</p>
            </div>
        <?php endif; ?>

        <?php if ($messageType === 'success' && $showCodeStep === false && strpos($message, 'Password reset successful') !== false): ?>
            <div class="links">
                <a href="login.html">Go to Login</a>
            </div>
        <?php else: ?>
            <?php if (!$showCodeStep): ?>
                <form method="POST" action="forgot_password.php" class="stacked-form">
                    <input type="hidden" name="action" value="request_reset">

                    <div class="form-group">
                        <label for="user_status">User Status *</label>
                        <select id="user_status" name="user_status" required>
                            <option value="">-- Select Your Status --</option>
                            <option value="student" <?= $status === 'student' ? 'selected' : '' ?>>Student</option>
                            <option value="staff" <?= $status === 'staff' ? 'selected' : '' ?>>Staff</option>
                            <option value="foreman" <?= $status === 'foreman' ? 'selected' : '' ?>>Foreman</option>
                            <option value="admin" <?= $status === 'admin' ? 'selected' : '' ?>>Admin</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="identifier">ID / Registration Number *</label>
                        <input type="text" id="identifier" name="identifier" value="<?= htmlspecialchars($identifier, ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="phone_number">Phone Number on File *</label>
                        <input type="text" id="phone_number" name="phone_number" value="<?= htmlspecialchars($phoneInput, ENT_QUOTES, 'UTF-8') ?>" placeholder="Use the phone number recorded in your account" required>
                    </div>

                    <button type="submit" class="btn">Request Reset Code</button>
                </form>
            <?php else: ?>
                <form method="POST" action="forgot_password.php" class="stacked-form">
                    <input type="hidden" name="action" value="finalize_reset">
                    <input type="hidden" name="user_status" value="<?= htmlspecialchars((string)$status, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="identifier" value="<?= htmlspecialchars((string)$identifier, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="form-group">
                        <label for="reset_code">Enter 6-digit code *</label>
                        <input type="text" id="reset_code" name="reset_code" value="<?= htmlspecialchars($resetCode, ENT_QUOTES, 'UTF-8') ?>" maxlength="6" inputmode="numeric" required>
                    </div>

                    <div class="form-group">
                        <label for="new_password">New Password *</label>
                        <input type="password" id="new_password" name="new_password" minlength="8" required>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" minlength="8" required>
                    </div>

                    <button type="submit" class="btn">Save New Password</button>
                </form>
            <?php endif; ?>

            <div class="links">
                <a href="help.php">Help & Support</a>
                <a href="login.html">Back to Login</a>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>

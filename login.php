<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_header.php';

function is_ajax_request(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function get_dashboard_redirect(string $status): string
{
    $redirectMap = [
        'student' => 'student_dashboard.php',
        'staff' => 'staff_dashboard.php',
        'foreman' => 'foreman_dashboard.php',
        'admin' => 'admin_dashboard.php',
    ];

    return $redirectMap[$status] ?? 'dashboard.php';
}

$userStatusMap = [
    'student' => [
        'table' => 'students',
        'id_column' => 'student_id',
        'label' => 'Student ID No.'
    ],
    'staff' => [
        'table' => 'staff',
        'id_column' => 'worker_reg_no',
        'label' => 'Worker Reg No.'
    ],
    'foreman' => [
        'table' => 'foremen',
        'id_column' => 'foreman_reg_no',
        'label' => 'Foreman Reg No.'
    ],
    'admin' => [
        'table' => 'admins',
        'id_column' => 'admin_id',
        'label' => 'Admin ID'
    ],
];

function logAttempt(PDO $pdo, string $status, string $identifier, bool $success, string $reason = ''): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO login_logs
            (user_status, identifier, ip_address, user_agent, success, failure_reason)
         VALUES
            (:user_status, :identifier, :ip_address, :user_agent, :success, :failure_reason)"
    );

    $stmt->execute([
        'user_status' => $status,
        'identifier' => $identifier,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'CLI',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'success' => $success ? 1 : 0,
        'failure_reason' => $reason,
    ]);
}

function getDisplayName(string $status, array $user): string
{
    if ($status === 'student' || $status === 'foreman') {
        return trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    }

    if ($status === 'staff') {
        return trim((string)($user['full_name'] ?? ''));
    }

    if ($status === 'admin') {
        return trim((string)($user['full_name'] ?? $user['admin_id'] ?? 'Administrator'));
    }

    return 'User';
}

if (!empty($_SESSION['authenticated'])) {
    $redirectTo = get_dashboard_redirect((string)($_SESSION['user_status'] ?? ''));

    if (is_ajax_request()) {
        json_response([
            'success' => true,
            'message' => 'Already logged in.',
            'redirect' => $redirectTo,
        ]);
    }

    redirect($redirectTo);
}

/*
    Persistent login using Remember Me.
    Uses remember_token column.
*/
if (!empty($_COOKIE['mowliss_remember'])) {
    $decodedCookie = base64_decode($_COOKIE['mowliss_remember'], true);

    if ($decodedCookie !== false) {
        $rememberData = json_decode($decodedCookie, true);

        if (
            is_array($rememberData)
            && isset($rememberData['status'], $rememberData['selector'], $rememberData['validator'])
            && isset($userStatusMap[$rememberData['status']])
        ) {
            $cookieStatus = $rememberData['status'];
            $cookieSelector = (string)$rememberData['selector'];
            $cookieValidator = (string)$rememberData['validator'];

            $storedToken = $cookieSelector . ':' . hash('sha256', $cookieValidator);
            $config = $userStatusMap[$cookieStatus];

            $stmt = $pdo->prepare(
                "SELECT *
                 FROM {$config['table']}
                 WHERE remember_token = :remember_token
                 LIMIT 1"
            );

            $stmt->execute(['remember_token' => $storedToken]);
            $rememberedUser = $stmt->fetch();

            if ($rememberedUser) {
                if ($cookieStatus === 'admin' && !empty($rememberedUser['locked_until']) && strtotime((string)$rememberedUser['locked_until']) > time()) {
                    setcookie('mowliss_remember', '', time() - 3600, '/');
                } elseif (isset($rememberedUser['terms_agreed']) && (int)$rememberedUser['terms_agreed'] !== 1) {
                    setcookie('mowliss_remember', '', time() - 3600, '/');
                } else {
                    session_regenerate_id(true);

                    $_SESSION = [];
                    $_SESSION['authenticated'] = true;
                    $_SESSION['user_status'] = $cookieStatus;
                    $_SESSION['user_id'] = (int)$rememberedUser['id'];
                    $_SESSION['identifier'] = $rememberedUser[$config['id_column']];
                    $_SESSION['user_name'] = getDisplayName($cookieStatus, $rememberedUser);

                    $redirectTo = get_dashboard_redirect($cookieStatus);

                    if (is_ajax_request()) {
                        json_response([
                            'success' => true,
                            'message' => 'Welcome back.',
                            'redirect' => $redirectTo,
                        ]);
                    }

                    redirect($redirectTo);
                }
            } else {
                setcookie('mowliss_remember', '', time() - 3600, '/');
            }
        }
    }
}

$error = '';
$oldStatus = $_POST['user_status'] ?? '';
$oldIdentifier = trim((string)($_POST['identifier'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = $_POST['user_status'] ?? '';
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $rememberMe = isset($_POST['remember_me']);

    if (!isset($userStatusMap[$status])) {
        $error = 'Please select your user status.';
    } elseif ($identifier === '') {
        $error = 'Please enter your ID/registration number.';
    } elseif ($password === '') {
        $error = 'Please enter your password.';
    } else {
        $config = $userStatusMap[$status];
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM login_logs
             WHERE user_status = :user_status
               AND identifier = :identifier
               AND ip_address = :ip_address
               AND success = 0
               AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)"
        );

        $stmt->execute([
            'user_status' => $status,
            'identifier' => $identifier,
            'ip_address' => $ip,
        ]);

        $recentFailures = (int)$stmt->fetchColumn();

        if ($recentFailures >= 5) {
            $error = 'Too many failed login attempts. Please try again after 15 minutes.';
        } else {
            $stmt = $pdo->prepare(
                "SELECT *
                 FROM {$config['table']}
                 WHERE {$config['id_column']} = :identifier
                 LIMIT 1"
            );

            $stmt->execute(['identifier' => $identifier]);
            $user = $stmt->fetch();

            if (!$user) {
                logAttempt($pdo, $status, $identifier, false, 'Account not found');
                $error = 'Invalid ID or password.';
            } elseif (isset($user['terms_agreed']) && (int)$user['terms_agreed'] !== 1) {
                logAttempt($pdo, $status, $identifier, false, 'Terms not agreed');
                $error = 'This account has not accepted the Terms & Conditions.';
            } elseif ($status === 'admin' && !empty($user['locked_until']) && strtotime((string)$user['locked_until']) > time()) {
                logAttempt($pdo, $status, $identifier, false, 'Admin account locked');
                $error = 'Admin account is temporarily locked due to too many failed attempts.';
            } elseif (in_array($status, ['student', 'staff', 'foreman'], true)) {
                $accountState = strtolower((string)($user['account_status'] ?? ''));
                $isApproved = (bool)((int)($user['is_approved'] ?? 0));

                if ($accountState === 'declined') {
                    logAttempt($pdo, $status, $identifier, false, 'Account declined');
                    $error = 'This account has been declined by the admin.';
                } elseif ($accountState === 'waiting') {
                    logAttempt($pdo, $status, $identifier, false, 'Account waiting review');
                    $error = 'This account is waiting for admin review.';
                } elseif (!$isApproved && ($accountState === '' || $accountState === 'pending')) {
                    logAttempt($pdo, $status, $identifier, false, 'Account pending approval');
                    $error = 'Your account is pending admin approval.';
                }
            }

            if (empty($error) && empty($user['password_hash'])) {
                logAttempt($pdo, $status, $identifier, false, 'No password set');
                $error = 'No password is set for this account. Please register first.';
            }

            if (empty($error) && !password_verify($password, (string)$user['password_hash'])) {
                logAttempt($pdo, $status, $identifier, false, 'Invalid password');

                if ($status === 'admin') {
                    $attempts = (int)($user['failed_login_attempts'] ?? 0) + 1;
                    $lockedUntil = null;

                    if ($attempts >= 5) {
                        $lockedUntil = date('Y-m-d H:i:s', time() + 900);
                    }

                    $update = $pdo->prepare(
                        "UPDATE admins
                         SET failed_login_attempts = :failed_login_attempts,
                             locked_until = :locked_until
                         WHERE id = :id"
                    );

                    $update->execute([
                        'failed_login_attempts' => $attempts,
                        'locked_until' => $lockedUntil,
                        'id' => (int)$user['id'],
                    ]);
                }

                $error = 'Invalid ID or password.';
            }

            if (empty($error)) {
                logAttempt($pdo, $status, $identifier, true);

                if ($status === 'admin') {
                    $reset = $pdo->prepare(
                        "UPDATE admins
                         SET failed_login_attempts = 0,
                             locked_until = NULL
                         WHERE id = :id"
                    );

                    $reset->execute(['id' => (int)$user['id']]);
                } else {
                    $markOnline = $pdo->prepare(
                        "UPDATE {$config['table']}
                         SET is_online = 1,
                             last_seen = NOW(),
                             device_status = 'healthy'
                         WHERE {$config['id_column']} = :identifier"
                    );
                    $markOnline->execute(['identifier' => $identifier]);
                }

                session_regenerate_id(true);

                $_SESSION = [];
                $_SESSION['authenticated'] = true;
                $_SESSION['user_status'] = $status;
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['identifier'] = $user[$config['id_column']];
                $_SESSION['user_name'] = getDisplayName($status, $user);

                if ($rememberMe) {
                    $selector = bin2hex(random_bytes(12));
                    $validator = bin2hex(random_bytes(32));
                    $storedToken = $selector . ':' . hash('sha256', $validator);

                    $updateToken = $pdo->prepare(
                        "UPDATE {$config['table']}
                         SET remember_token = :remember_token
                         WHERE {$config['id_column']} = :identifier"
                    );

                    $updateToken->execute([
                        'remember_token' => $storedToken,
                        'identifier' => $identifier,
                    ]);

                    $cookieValue = base64_encode(json_encode([
                        'status' => $status,
                        'selector' => $selector,
                        'validator' => $validator,
                    ]));

                    setcookie(
                        'mowliss_remember',
                        $cookieValue,
                        time() + (30 * 24 * 60 * 60),
                        '/',
                        '',
                        false,
                        true
                    );
                } else {
                    $clearToken = $pdo->prepare(
                        "UPDATE {$config['table']}
                         SET remember_token = NULL
                         WHERE {$config['id_column']} = :identifier"
                    );

                    $clearToken->execute(['identifier' => $identifier]);
                }

                $redirectTo = get_dashboard_redirect($status);

                if (is_ajax_request()) {
                    json_response([
                        'success' => true,
                        'message' => 'Login successful.',
                        'redirect' => $redirectTo,
                    ]);
                }

                redirect($redirectTo);
            }
        }
    }
}

$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/img/favicon.png?v=1">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | MoWLiSS - Mobile Web Link Safety Scanner</title>
    <link rel="stylesheet" href="style.css?v=7">
</head>
<body>
<?php render_site_header(); ?>
<div class="auth-wrapper">
    <div class="auth-card">
        <div class="brand">
            <h1>MoWLiSS</h1>
            <p>
                Mobile Web Link Safety Scanner — Protecting the Western Pacific University community
                from phishing and cyber threats.
            </p>

            <div class="badges">
                <span class="badge">Real-time link scanning</span>
                <span class="badge">AI-powered threat detection</span>
                <span class="badge">Instant security alerts</span>
                <span class="badge">SOC escalation system</span>
            </div>
        </div>

        <?php if (!empty($flashSuccess)): ?>
            <div class="alert alert-success"><?= e($flashSuccess) ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST" autocomplete="off">
            <div class="form-group">
                <label for="user_status">User Status *</label>
                <select name="user_status" id="user_status" required>
                    <option value="">-- Select Your Status --</option>
                    <option value="student" <?= $oldStatus === 'student' ? 'selected' : '' ?>>🎓 WPU Registered Student</option>
                    <option value="staff" <?= $oldStatus === 'staff' ? 'selected' : '' ?>>👔 University Staff / Employee</option>
                    <option value="foreman" <?= $oldStatus === 'foreman' ? 'selected' : '' ?>>👷 University Foreman</option>
                    <option value="admin" <?= $oldStatus === 'admin' ? 'selected' : '' ?>>🛡️ WPU ICT Administrator</option>
                </select>
            </div>

            <div class="form-group">
                <label for="identifier" id="identifierLabel">ID Number *</label>
                <input
                    type="text"
                    name="identifier"
                    id="identifier"
                    value="<?= e($oldIdentifier) ?>"
                    placeholder="Enter your ID number"
                    required
                >
            </div>

            <div class="form-group">
                <label for="password">Password *</label>
                <div class="password-wrapper">
                    <input
                        type="password"
                        name="password"
                        id="password"
                        placeholder="Enter your password"
                        required
                    >
                    <button type="button" class="toggle-password" id="togglePassword" aria-label="Show password">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12S5 4 12 4s11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </button>
                </div>
            </div>

            <div class="checkbox-row">
                <input type="checkbox" name="remember_me" id="remember_me" value="1">
                <label for="remember_me">Remember me</label>
            </div>

            <button type="submit" class="btn">Login to Portal</button>
        </form>

        <div class="links">
            <a href="login.html?tab=register">Create an account</a><br>
            <a href="index.html">Back to Home</a>
        </div>
    </div>
</div>

<script>
    const labels = {
        student: "Student ID No.",
        staff: "Worker Reg No.",
        foreman: "Foreman Reg No.",
        admin: "Admin ID"
    };

    const statusSelect = document.getElementById("user_status");
    const identifierLabel = document.getElementById("identifierLabel");
    const identifierInput = document.getElementById("identifier");

    function updateIdentifierLabel() {
        const status = statusSelect.value;

        if (labels[status]) {
            identifierLabel.textContent = labels[status] + " *";
            identifierInput.placeholder = "Enter your " + labels[status];
        } else {
            identifierLabel.textContent = "ID Number *";
            identifierInput.placeholder = "Enter your ID number";
        }
    }

    statusSelect.addEventListener("change", updateIdentifierLabel);
    updateIdentifierLabel();

    const togglePassword = document.getElementById("togglePassword");
    const passwordInput = document.getElementById("password");

    const eyeIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12S5 4 12 4s11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
    const eyeOffIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';

    togglePassword.addEventListener("click", () => {
        const isHidden = passwordInput.type === "password";
        passwordInput.type = isHidden ? "text" : "password";
        togglePassword.innerHTML = isHidden ? eyeOffIcon : eyeIcon;
        togglePassword.setAttribute("aria-label", isHidden ? "Hide password" : "Show password");
    });
</script>
</body>
</html>
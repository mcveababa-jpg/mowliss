<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'success' => false,
        'message' => 'Invalid request method.'
    ], 405);
}

try {
    $pdo = get_pdo();
} catch (RuntimeException $e) {
    json_response([
        'success' => false,
        'message' => 'Database unavailable. Please try again later.'
    ], 500);
}

$userStatusMap = [
    'student' => [
        'table' => 'students',
        'id_column' => 'student_id',
    ],
    'staff' => [
        'table' => 'staff',
        'id_column' => 'worker_reg_no',
    ],
    'foreman' => [
        'table' => 'foremen',
        'id_column' => 'foreman_reg_no',
    ],
    'admin' => [
        'table' => 'admins',
        'id_column' => 'admin_id',
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
    json_response([
        'success' => true,
        'message' => 'Already logged in.',
        'redirect' => 'dashboard.php'
    ]);
}

$status = trim((string)($_POST['user_status'] ?? ''));
$identifier = trim((string)($_POST['identifier'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$rememberMe = !empty($_POST['remember_me']);

if (!isset($userStatusMap[$status])) {
    json_response([
        'success' => false,
        'message' => 'Please select a valid user status.'
    ], 422);
}

if ($identifier === '') {
    json_response([
        'success' => false,
        'message' => 'Please enter your ID/registration number.'
    ], 422);
}

if ($password === '') {
    json_response([
        'success' => false,
        'message' => 'Please enter your password.'
    ], 422);
}

$config = $userStatusMap[$status];
$ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';

/*
    Basic brute-force protection.
*/
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
    json_response([
        'success' => false,
        'message' => 'Too many failed login attempts. Please try again after 15 minutes.'
    ], 429);
}

$stmt = $pdo->prepare(
    "SELECT *
     FROM {$config['table']}
     WHERE {$config['id_column']} = :identifier
     LIMIT 1"
);

$stmt->execute([
    'identifier' => $identifier
]);

$user = $stmt->fetch();

if (!$user) {
    logAttempt($pdo, $status, $identifier, false, 'Account not found');

    json_response([
        'success' => false,
        'message' => 'Invalid ID or password.'
    ], 401);
}

if (isset($user['terms_agreed']) && (int)$user['terms_agreed'] !== 1) {
    logAttempt($pdo, $status, $identifier, false, 'Terms not agreed');

    json_response([
        'success' => false,
        'message' => 'This account has not accepted the Terms & Conditions.'
    ], 403);
}

if ($status === 'admin' && !empty($user['locked_until']) && strtotime((string)$user['locked_until']) > time()) {
    logAttempt($pdo, $status, $identifier, false, 'Admin account locked');

    json_response([
        'success' => false,
        'message' => 'Admin account is temporarily locked due to too many failed attempts.'
    ], 423);
}

if (empty($user['password_hash'])) {
    logAttempt($pdo, $status, $identifier, false, 'No password set');

    json_response([
        'success' => false,
        'message' => 'No password is set for this account. Please register first.'
    ], 403);
}

if (!password_verify($password, (string)$user['password_hash'])) {
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

    json_response([
        'success' => false,
        'message' => 'Invalid ID or password.'
    ], 401);
}

logAttempt($pdo, $status, $identifier, true);

if ($status === 'admin') {
    $reset = $pdo->prepare(
        "UPDATE admins
         SET failed_login_attempts = 0,
             locked_until = NULL
         WHERE id = :id"
    );

    $reset->execute([
        'id' => (int)$user['id']
    ]);
}

session_regenerate_id(true);

$_SESSION = [];
$_SESSION['authenticated'] = true;
$_SESSION['user_status'] = $status;
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['identifier'] = $user[$config['id_column']];
$_SESSION['user_name'] = getDisplayName($status, $user);

/*
    Remember Me token.
*/
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

    $clearToken->execute([
        'identifier' => $identifier
    ]);
}

json_response([
    'success' => true,
    'message' => 'Login successful.',
    'redirect' => 'dashboard.php'
]);
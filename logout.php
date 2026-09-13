<?php
require_once __DIR__ . '/db.php';

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

/*
    Clear remember token from database if present.
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
            $status = $rememberData['status'];
            $selector = (string)$rememberData['selector'];
            $validator = (string)$rememberData['validator'];

            $storedToken = $selector . ':' . hash('sha256', $validator);
            $config = $userStatusMap[$status];

            try {
                $stmt = $pdo->prepare(
                    "UPDATE {$config['table']}
                     SET remember_token = NULL
                     WHERE remember_token = :remember_token"
                );

                $stmt->execute(['remember_token' => $storedToken]);
            } catch (PDOException $e) {
                error_log('MoWLiSS Logout Token Clear Error: ' . $e->getMessage());
            }
        }
    }

    setcookie('mowliss_remember', '', time() - 3600, '/');
}

$status = $_SESSION['user_status'] ?? '';
$userId = $_SESSION['user_id'] ?? null;

if ($status && $userId && isset($userStatusMap[$status])) {
    $table = $userStatusMap[$status]['table'];
    $column = $userStatusMap[$status]['id_column'];

    if ($status !== 'admin') {
        $stmt = $pdo->prepare(
            "UPDATE {$table}
             SET is_online = 0,
                 last_seen = NOW(),
                 device_status = 'offline'
             WHERE id = :id"
        );
        $stmt->execute(['id' => (int)$userId]);
    }
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

redirect('login.php');
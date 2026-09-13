<?php
chdir(__DIR__);
require_once __DIR__ . '/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// use admin for test
$_SESSION['authenticated'] = true;
$_SESSION['user_status'] = 'admin';
$row = $pdo->query('SELECT id FROM admins LIMIT 1')->fetch();
if (!$row) { echo "No admin user in DB\n"; exit(1); }
$_SESSION['user_id'] = (int)$row['id'];

// Simulate POST
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['room'] = 'NICTA';
$_POST['message'] = 'Automated test report submitted at ' . date('c');
// include handler
include __DIR__ . '/submit_report.php';

// If submit_report redirected, it would have exited. If it returns, check DB
$last = $pdo->query('SELECT * FROM chat_reports ORDER BY id DESC LIMIT 1')->fetch();
if ($last) {
    echo "Inserted report: " . json_encode($last) . "\n";
} else {
    echo "No report found after submit.\n";
}

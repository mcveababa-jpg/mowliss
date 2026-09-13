<?php
// check_db_runtime.php
try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=mowliss;charset=utf8mb4','root','', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    echo 'CONNECT FAILED: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
$tables = ['students','staff','foremen','admins','chat_reports','chat_messages','ticket_actions','app_controls'];
foreach ($tables as $t) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        echo "{$t}: {$count}\n";
    } catch (Exception $e) {
        echo "{$t}: MISSING or error (" . $e->getMessage() . ")\n";
    }
}

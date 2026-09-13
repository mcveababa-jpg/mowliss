<?php
require_once __DIR__ . '/db.php';
$rows = $pdo->query('SELECT id, room, submitter_role, message, created_at FROM chat_reports ORDER BY id DESC LIMIT 10')->fetchAll();
foreach ($rows as $r) {
    echo sprintf("%s | %s | %s | %s\n", $r['created_at'], $r['room'], $r['submitter_role'], substr($r['message'],0,120));
}
if (empty($rows)) echo "No reports found\n";

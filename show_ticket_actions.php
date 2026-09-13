<?php
require_once __DIR__ . '/db.php';
$rows = $pdo->query('SELECT * FROM ticket_actions ORDER BY id DESC LIMIT 20')->fetchAll();
if (empty($rows)) { echo "No ticket_actions\n"; exit; }
foreach ($rows as $r) {
    echo sprintf("%s | report:%s admin:%s action:%s note:%s\n", $r['created_at'], $r['report_id'], $r['admin_id'], $r['action'], substr($r['note'] ?? '',0,80));
}

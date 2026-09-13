<?php
chdir(__DIR__);
require_once __DIR__ . '/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION['authenticated']=true;$_SESSION['user_status']='admin';
$row=$pdo->query('SELECT id FROM admins LIMIT 1')->fetch();
if (!$row) { echo "No admin\n"; exit(1); }
$_SESSION['user_id']=(int)$row['id'];
// pick a report
$r=$pdo->query('SELECT id FROM chat_reports ORDER BY id DESC LIMIT 1')->fetch();
if (!$r) { echo "No reports\n"; exit(1); }
$id=(int)$r['id'];
// simulate GET
$_GET['id']=$id; $_GET['act']='attend';
include __DIR__ . '/attend_ticket.php';
// check ticket_actions
$rows=$pdo->prepare('SELECT * FROM ticket_actions WHERE report_id=:rid ORDER BY id DESC');$rows->execute(['rid'=>$id]);
foreach($rows->fetchAll() as $rr) echo json_encode($rr)."\n";

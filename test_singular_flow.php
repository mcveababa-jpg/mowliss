<?php
require_once __DIR__ . '/db.php';

echo "Starting singular role ticket flow test...\n";

$userRoleRaw = 'student';
$uid = 888777;
$roleKey = in_array($userRoleRaw, ['students','student'], true) ? 'students' : ($userRoleRaw === 'staff' ? 'staff' : (in_array($userRoleRaw, ['foremen','foreman'], true) ? 'foremen' : $userRoleRaw));
$conv = $roleKey . ':' . $uid;
$msg1 = 'Singular role test message ' . date('c');

// cleanup
$pdo->prepare('DELETE FROM chat_messages WHERE conversation_key = :ck')->execute(['ck' => $conv]);
$pdo->prepare('DELETE FROM chat_reports WHERE submitter_role = :role AND submitter_id = :sid')->execute(['role' => $userRoleRaw, 'sid' => $uid]);
$pdo->prepare('DELETE FROM ticket_actions WHERE report_id NOT IN (SELECT id FROM chat_reports)')->execute();

// simulate submit_report.php behavior: create chat_reports then initial chat_messages with normalized convKey
$pdo->exec("CREATE TABLE IF NOT EXISTS chat_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room VARCHAR(100) NOT NULL,
    submitter_id INT NOT NULL,
    submitter_role VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$ins = $pdo->prepare('INSERT INTO chat_reports (room, submitter_id, submitter_role, message) VALUES (:room, :sid, :srole, :message)');
$ins->execute(['room'=>'test_room','sid'=>$uid,'srole'=>$userRoleRaw,'message'=>$msg1]);
$reportId = (int)$pdo->lastInsertId();
echo "Created report id: $reportId\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_key VARCHAR(100) NOT NULL,
    sender_role VARCHAR(50) NOT NULL,
    sender_id INT NOT NULL,
    message TEXT NOT NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    report_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$init = $pdo->prepare('INSERT INTO chat_messages (conversation_key, sender_role, sender_id, message, is_admin, report_id) VALUES (:ck, :srole, :sid, :msg, 0, :rid)');
$init->execute(['ck'=>$conv,'srole'=>$userRoleRaw,'sid'=>$uid,'msg'=>$msg1,'rid'=>$reportId]);
echo "Inserted initial message under conv $conv.\n";

// Admin replies to conv
$adminId = 1;
$reply = 'Admin reply to singular role at ' . date('c');
$ins2 = $pdo->prepare('INSERT INTO chat_messages (conversation_key, sender_role, sender_id, message, is_admin, report_id) VALUES (:ck, :srole, :sid, :msg, 1, :rid)');
$ins2->execute(['ck'=>$conv,'srole'=>'admin','sid'=>$adminId,'msg'=>$reply,'rid'=>$reportId]);
echo "Admin reply inserted.\n";

// Fetch messages using plural conv
$sel = $pdo->prepare('SELECT id, sender_role, is_admin, report_id, message FROM chat_messages WHERE conversation_key = :ck ORDER BY id ASC');
$sel->execute(['ck'=>$conv]);
$rows = $sel->fetchAll();
echo "Messages found for $conv: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo " - id={$r['id']} role={$r['sender_role']} is_admin={$r['is_admin']} report_id={$r['report_id']} msg=".substr($r['message'],0,80)."\n";
}

// Also check for singular conv variant (should not exist)
$singularConv = 'student:' . $uid;
$sel2 = $pdo->prepare('SELECT COUNT(*) FROM chat_messages WHERE conversation_key = :ck');
$sel2->execute(['ck'=>$singularConv]);
$countSing = (int)$sel2->fetchColumn();
echo "Messages under $singularConv: $countSing\n";

// cleanup
$pdo->prepare('DELETE FROM chat_messages WHERE conversation_key = :ck')->execute(['ck'=>$conv]);
$pdo->prepare('DELETE FROM ticket_actions WHERE report_id = :rid')->execute(['rid'=>$reportId]);
$pdo->prepare('DELETE FROM chat_reports WHERE id = :rid')->execute(['rid'=>$reportId]);

echo "Test complete.\n";

<?php
// test_ticket_flow.php
// Simulate user -> ticket -> admin reply flow and verify messages are linked to same ticket
require_once __DIR__ . '/db.php';

echo "Starting ticket flow test...\n";

$role = 'students';
$uid = 9999999; // high id used for test
$conv = $role . ':' . $uid;
$room = 'test_direct_admin';
$msg1 = 'Test ticket message from user at ' . date('c');
$adminId = 1;

// cleanup previous test data for id
$del1 = $pdo->prepare('DELETE FROM chat_messages WHERE conversation_key = :ck');
$del1->execute(['ck' => $conv]);
$del2 = $pdo->prepare('DELETE FROM chat_reports WHERE submitter_role = :role AND submitter_id = :sid AND room = :room');
$del2->execute(['role' => $role, 'sid' => $uid, 'room' => $room]);
$del3 = $pdo->prepare('DELETE ta FROM ticket_actions ta JOIN chat_reports cr ON cr.id = ta.report_id WHERE cr.submitter_role = :role AND cr.submitter_id = :sid AND cr.room = :room');
// Some DBs may error if join delete unsupported; ignore failures
try { $del3->execute(['role'=>$role,'sid'=>$uid,'room'=>$room]); } catch (Exception $e) { /* ignore */ }

// create report
$stmt = $pdo->prepare('INSERT INTO chat_reports (room, submitter_id, submitter_role, message) VALUES (:room, :sid, :srole, :message)');
$stmt->execute([
    'room' => $room,
    'sid' => $uid,
    'srole' => $role,
    'message' => $msg1,
]);
$reportId = (int)$pdo->lastInsertId();
if ($reportId <= 0) {
    echo "Failed to create report.\n";
    exit(1);
}
echo "Created report ID: {$reportId}\n";

// ensure chat_messages table exists with report_id
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

// Insert initial chat message linked to report
$ins = $pdo->prepare('INSERT INTO chat_messages (conversation_key, sender_role, sender_id, message, is_admin, is_read, report_id) VALUES (:ck, :srole, :sid, :msg, 0, 0, :rid)');
$ins->execute([
    'ck' => $conv,
    'srole' => $role,
    'sid' => $uid,
    'msg' => $msg1,
    'rid' => $reportId,
]);
echo "Inserted initial user message into chat_messages.\n";

// ensure ticket_actions table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS ticket_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    admin_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Admin attends the ticket
$insAct = $pdo->prepare('INSERT INTO ticket_actions (report_id, admin_id, action) VALUES (:rid, :aid, :action)');
$insAct->execute(['rid' => $reportId, 'aid' => $adminId, 'action' => 'attended']);
echo "Admin attended ticket (ticket_actions inserted).\n";

// Admin replies and links to same report
$reply = 'Admin reply at ' . date('c');
$ins2 = $pdo->prepare('INSERT INTO chat_messages (conversation_key, sender_role, sender_id, message, is_admin, is_read, report_id) VALUES (:ck, :srole, :sid, :msg, 1, 0, :rid)');
$ins2->execute([
    'ck' => $conv,
    'srole' => 'admin',
    'sid' => $adminId,
    'msg' => $reply,
    'rid' => $reportId,
]);
echo "Admin reply inserted into chat_messages.\n";

// Fetch messages for conversation
$sel = $pdo->prepare('SELECT id, sender_role, sender_id, is_admin, report_id, message, created_at FROM chat_messages WHERE conversation_key = :ck ORDER BY id ASC');
$sel->execute(['ck' => $conv]);
$messages = $sel->fetchAll();

echo "Messages for conversation {$conv}:\n";
$allLinked = true;
foreach ($messages as $m) {
    $id = $m['id'];
    $srole = $m['sender_role'];
    $isAdmin = (int)$m['is_admin'];
    $rid = $m['report_id'];
    $text = $m['message'];
    $time = $m['created_at'];
    echo " - #{$id} [role={$srole}] [is_admin={$isAdmin}] [report_id={$rid}] at {$time}\n   message: " . substr($text,0,160) . "\n";
    if ($rid !== $reportId) $allLinked = false;
}

if ($allLinked && count($messages) >= 2) {
    echo "SUCCESS: All messages are linked to report ID {$reportId}.\n";
} else {
    echo "FAIL: Messages not consistently linked to the report or insufficient messages.\n";
}

// Cleanup test rows
try {
    $pdo->prepare('DELETE FROM chat_messages WHERE conversation_key = :ck')->execute(['ck' => $conv]);
    $pdo->prepare('DELETE FROM ticket_actions WHERE report_id = :rid')->execute(['rid' => $reportId]);
    $pdo->prepare('DELETE FROM chat_reports WHERE id = :rid')->execute(['rid' => $reportId]);
    echo "Cleaned up test rows.\n";
} catch (Exception $e) {
    echo "Warning: cleanup failed: " . $e->getMessage() . "\n";
}

echo "Test complete.\n";

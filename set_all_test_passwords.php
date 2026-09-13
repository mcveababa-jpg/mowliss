<?php
// Set known test passwords for all user tables and print credentials
chdir(__DIR__);
require_once __DIR__ . '/db.php';

$defaults = [
    'students' => ['id_col' => 'student_id', 'password' => 'StudentPass1!'],
    'staff' => ['id_col' => 'worker_reg_no', 'password' => 'StaffPass1!'],
    'foremen' => ['id_col' => 'foreman_reg_no', 'password' => 'ForemanPass1!'],
    'admins' => ['id_col' => 'admin_id', 'password' => 'AdminPass123!'],
];

foreach ($defaults as $table => $cfg) {
    try {
        $rows = $pdo->query("SELECT id, {$cfg['id_col']} as identifier FROM {$table}")->fetchAll();
    } catch (Throwable $e) {
        echo "Skipping {$table} (error: " . $e->getMessage() . ")\n";
        continue;
    }

    if (empty($rows)) {
        echo "No rows in {$table}\n";
        continue;
    }

    $pw = $cfg['password'];
    $hash = password_hash($pw, PASSWORD_DEFAULT);
    $upd = $pdo->prepare("UPDATE {$table} SET password_hash = :hash WHERE id = :id");

    foreach ($rows as $r) {
        $upd->execute(['hash' => $hash, 'id' => (int)$r['id']]);
        echo sprintf("%s -> %s : %s\n", $table, $r['identifier'] ?? '(no identifier)', $pw);
    }
}

echo "Done. All listed accounts updated with the test passwords.\n";

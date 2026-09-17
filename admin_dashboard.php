<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_header.php';

function postString(string $key): string
{
    $value = $_POST[$key] ?? '';

    if (is_array($value)) {
       $value = end($value);
       if ($value === false) {
           $value = '';
       }
    }

    return trim((string)$value);
}

function userDisplayName(string $role, array $row): string
{
    if (in_array($role, ['students', 'foremen'], true)) {
       return trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
    }

    if ($role === 'staff') {
       return trim((string)($row['full_name'] ?? ''));
    }

    return trim((string)($row['full_name'] ?? $row['admin_id'] ?? 'User'));
}

function normalizeAccountState(array $row): string
{
    $status = strtolower((string)($row['account_status'] ?? ''));

    if ($status !== '') {
       return $status;
    }

    return (int)($row['is_approved'] ?? 0) === 1 ? 'approved' : 'pending';
}

function formatLocation(array $row): string
{
    $lat = isset($row['location_lat']) && $row['location_lat'] !== null && $row['location_lat'] !== '' ? (float)$row['location_lat'] : null;
    $lng = isset($row['location_lng']) && $row['location_lng'] !== null && $row['location_lng'] !== '' ? (float)$row['location_lng'] : null;

    if ($lat !== null && $lng !== null) {
       return sprintf('%.5f, %.5f', $lat, $lng);
    }

    return 'No location yet';
}

if (empty($_SESSION['authenticated']) || empty($_SESSION['user_status']) || empty($_SESSION['user_id'])) {
    redirect('login.php');
}

if ($_SESSION['user_status'] !== 'admin') {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = postString('action');

    if ($action === 'approve_user' || $action === 'wait_user' || $action === 'decline_user') {
       $role = postString('role');
       $userId = (int)postString('user_id');
       $adminName = $_SESSION['user_name'] ?? 'System Admin';
       $roleMap = [
           'students' => 'students',
           'staff' => 'staff',
           'foremen' => 'foremen',
       ];

       if (isset($roleMap[$role]) && $userId > 0) {
           $state = 'pending';
           $approvedFlag = 0;

           if ($action === 'approve_user') {
               $state = 'approved';
               $approvedFlag = 1;
           }

           if ($action === 'wait_user') {
               $state = 'waiting';
           }

           if ($action === 'decline_user') {
               $state = 'declined';
           }

           $stmt = $pdo->prepare(
               "UPDATE {$roleMap[$role]}
                SET account_status = :account_status,
                    is_approved = :is_approved,
                    approved_by = :approved_by,
                    approved_at = NOW()
                WHERE id = :id"
           );

           $stmt->execute([
               'account_status' => $state,
               'is_approved' => $approvedFlag,
               'approved_by' => $adminName,
               'id' => $userId,
           ]);

           $_SESSION['flash_message'] = match ($action) {
               'approve_user' => 'User account approved successfully.',
               'wait_user' => 'User account moved to waiting status.',
               'decline_user' => 'User account declined and blocked from access.',
               default => 'User account updated.'
           };
       }

       redirect('admin_dashboard.php');
    }

    if ($action === 'update_device_location') {
       $role = postString('role');
       $userId = (int)postString('user_id');
       $lat = postString('latitude');
       $lng = postString('longitude');
       $label = postString('location_label');
       $roleMap = [
           'students' => 'students',
           'staff' => 'staff',
           'foremen' => 'foremen',
       ];

       if (isset($roleMap[$role]) && $userId > 0 && $lat !== '' && $lng !== '') {
           $stmt = $pdo->prepare(
               "UPDATE {$roleMap[$role]}
                SET location_lat = :lat,
                    location_lng = :lng,
                    location_label = :location_label,
                    last_location_update = NOW(),
                    device_status = 'lost'
                WHERE id = :id"
           );

           $stmt->execute([
               'lat' => $lat,
               'lng' => $lng,
               'location_label' => $label !== '' ? $label : 'Location updated',
               'id' => $userId,
           ]);

           $_SESSION['flash_message'] = 'Device location updated for the selected user.';
       }

       redirect('admin_dashboard.php');
    }

    if ($action === 'control_action') {
       $controlName = postString('control_name');
       $enabled = (int)postString('enabled');

       if ($controlName !== '') {
           $stmt = $pdo->prepare(
               "INSERT INTO app_controls (action_name, enabled)
                VALUES (:action_name, :enabled)
                ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = NOW()"
           );

           $stmt->execute([
               'action_name' => $controlName,
               'enabled' => $enabled,
           ]);

           $_SESSION['flash_message'] = 'Control room command updated.';
       }

       redirect('admin_dashboard.php');
    }
}

$stmt = $pdo->prepare(
    "SELECT *
     FROM admins
     WHERE id = :id
     LIMIT 1"
);

$stmt->execute(['id' => (int)$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    redirect('logout.php');
}

$details = [
    'Admin ID' => $user['admin_id'] ?? '',
    'Full Name' => $user['full_name'] ?? 'Not provided',
    'Account Created' => $user['created_at'] ?? '',
];

$flashMessage = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

$roleDefinitions = [
    'students' => ['label' => 'Student Accounts', 'id_column' => 'student_id'],
    'staff' => ['label' => 'Staff Accounts', 'id_column' => 'worker_reg_no'],
    'foremen' => ['label' => 'Foreman Accounts', 'id_column' => 'foreman_reg_no'],
];

$usersByCategory = [];
$selectedMapUser = null;

foreach ($roleDefinitions as $role => $config) {
    $rows = $pdo->query("SELECT * FROM {$role} ORDER BY id DESC")->fetchAll();
    $group = [];

    foreach ($rows as $row) {
       $accountState = normalizeAccountState($row);
       $isOnline = (int)($row['is_online'] ?? 0) === 1;
       $lastSeen = $row['last_seen'] ?? 'Never';
       $locationValue = formatLocation($row);

       $entry = [
           'role' => $role,
           'label' => $config['label'],
           'identifier' => (string)($row[$config['id_column']] ?? ''),
           'display_name' => userDisplayName($role, $row),
           'account_state' => $accountState,
           'is_online' => $isOnline,
           'status_label' => $isOnline ? 'Online' : 'Offline',
           'last_seen' => $lastSeen,
           'location_value' => $locationValue,
           'location_lat' => $row['location_lat'] ?? null,
           'location_lng' => $row['location_lng'] ?? null,
           'device_status' => (string)($row['device_status'] ?? 'healthy'),
           'id' => (int)($row['id'] ?? 0),
       ];

       $group[] = $entry;

       if ($selectedMapUser === null && !empty($row['location_lat']) && !empty($row['location_lng'])) {
           $selectedMapUser = $entry;
       }
    }

    $usersByCategory[$role] = $group;
}

if ($selectedMapUser === null) {
    $selectedMapUser = [
       'display_name' => 'No device tracked',
       'location_value' => 'No location yet',
       'location_lat' => null,
       'location_lng' => null,
       'status_label' => 'Offline',
       'device_status' => 'offline',
    ];
}

$controlActions = [
    'website_blocker' => 'Website Blocker',
    'remote_lock' => 'Remote Lock',
    'live_location' => 'Live Location',
    'url_scanner_activation' => 'URL Scanner Activation',
    'app_turn_off' => 'App Turn Off',
    'app_turn_on' => 'App Turn On',
    'app_delete' => 'App Delete',
    'app_killswitch' => 'App Kill Switch',
];

$controlStates = [];
foreach ($pdo->query('SELECT action_name, enabled FROM app_controls')->fetchAll() as $row) {
    $controlStates[(string)$row['action_name']] = (int)($row['enabled'] ?? 0);
}

// Ensure ticket_actions table exists to record admin actions on tickets
$pdo->exec("CREATE TABLE IF NOT EXISTS ticket_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    admin_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Load recent chat reports and group them by submitter_role (only user roles — student/staff/foreman)
$ticketsByRole = [
    'students' => [],
    'staff' => [],
    'foremen' => [],
];
$reports = $pdo->query('SELECT * FROM chat_reports ORDER BY id DESC LIMIT 200')->fetchAll();

$roleMap = [
    'student' => 'students',
    'staff' => 'staff',
    'foreman' => 'foremen',
];

foreach ($reports as $r) {
    $origRole = strtolower((string)($r['submitter_role'] ?? ''));
    if (!isset($roleMap[$origRole])) {
        // skip reports submitted by admins or unknown roles — ticketing is for users only
        continue;
    }

    $roleKey = $roleMap[$origRole];
    $reportId = (int)$r['id'];

    // load ticket actions (latest first)
    $attStmt = $pdo->prepare('SELECT action, admin_id, created_at FROM ticket_actions WHERE report_id = :rid ORDER BY id DESC');
    $attStmt->execute(['rid' => $reportId]);
    $actions = $attStmt->fetchAll();

    $status = 'New';
    $attendedBy = null;
    if (!empty($actions)) {
        $last = $actions[0];
        // normalize action words
        $actionWord = strtolower((string)$last['action']);
        if ($actionWord === 'attended') $status = 'Attended';
        elseif ($actionWord === 'resolved') $status = 'Resolved';
        elseif ($actionWord === 'waiting' || $actionWord === 'wait') $status = 'Waiting';
        else $status = ucfirst($actionWord);

        $attendedBy = (int)$last['admin_id'];
    }

    // fetch submitter display name from its role table if available
    $submitterName = (string)$r['submitter_id'];
    $submitterId = (int)$r['submitter_id'];
    try {
        $uStmt = $pdo->prepare("SELECT * FROM {$roleKey} WHERE id = :id LIMIT 1");
        $uStmt->execute(['id' => $submitterId]);
        $uRow = $uStmt->fetch();
        if ($uRow) {
            $submitterName = userDisplayName($roleKey, $uRow) . ' (' . ((string)($uRow['id'] ?? $submitterId)) . ')';
        }
    } catch (Exception $e) {
        // ignore and leave submitterName as id
        $submitterName = (string)$submitterId;
    }

    $ticketsByRole[$roleKey][] = [
        'id' => $reportId,
        'room' => $r['room'] ?? '',
        'submitter_id' => $submitterId,
        'submitter_role' => $r['submitter_role'] ?? '',
        'submitter_name' => $submitterName,
        'message' => $r['message'] ?? '',
        'created_at' => $r['created_at'] ?? '',
        'status' => $status,
        'attended_by' => $attendedBy,
    ];
}

$mapSource = null;
if (!empty($selectedMapUser['location_lat']) && !empty($selectedMapUser['location_lng'])) {
    $lat = (float)$selectedMapUser['location_lat'];
    $lng = (float)$selectedMapUser['location_lng'];
    $bbox = sprintf('%s,%s,%s,%s', $lng - 0.01, $lat - 0.01, $lng + 0.01, $lat + 0.01);
    $mapSource = 'https://www.openstreetmap.org/export/embed.html?bbox=' . $bbox . '&layer=mapnik&marker=' . $lat . ',' . $lng;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | MoWLiSS</title>
    <link rel="stylesheet" href="style.css?v=7">
</head>
<body>
<?php render_site_header(); ?>
<div class="container">
    <div class="dashboard-top">
       <div>
           <h1>MoWLiSS Dashboard</h1>
           <p class="small">ICT Admin Portal</p>
       </div>
       <div>
           <a class="btn" style="display:inline-block; width:auto; text-decoration:none;" href="logout.php">Logout</a>
       </div>
    </div>

    <div class="dashboard-card">
       <h2>Welcome, <?= e($_SESSION['user_name'] ?? 'Administrator') ?></h2>
       <p class="small">You are logged in as Administrator.</p>
    </div>

    <?php if ($flashMessage !== ''): ?>
       <div class="alert alert-success"><?= e($flashMessage) ?></div>
    <?php endif; ?>

    <div class="dashboard-card">
       <h2>Admin Account Details</h2>
       <div class="table-responsive">
           <table>
               <thead>
                   <tr>
                       <th>Field</th>
                       <th>Value</th>
                   </tr>
               </thead>
               <tbody>
                   <?php foreach ($details as $label => $value): ?>
                       <tr>
                           <th><?= e($label) ?></th>
                           <td><?= e((string)$value) ?></td>
                       </tr>
                   <?php endforeach; ?>
               </tbody>
           </table>
       </div>
    </div>

    <div class="dashboard-card">
       <h2>All Users</h2>
       <div class="category-stack">
           <?php foreach ($roleDefinitions as $role => $config): ?>
               <?php $rows = $usersByCategory[$role] ?? []; ?>
               <div class="user-category">
                   <h3><?= e($config['label']) ?></h3>
                   <div class="table-responsive">
                       <table>
                           <thead>
                               <tr>
                                   <th>Name</th>
                                   <th>ID</th>
                                   <th>Connection</th>
                                   <th>Account</th>
                                   <th>Location</th>
                                   <th>Action</th>
                               </tr>
                           </thead>
                           <tbody>
                               <?php foreach ($rows as $row): ?>
                                   <tr>
                                       <td><?= e($row['display_name']) ?></td>
                                       <td><?= e($row['identifier']) ?></td>
                                       <td><span class="status-badge <?= $row['is_online'] ? 'approved' : 'pending' ?>"><?= e($row['status_label']) ?></span></td>
                                       <td><span class="status-badge <?= match ($row['account_state']) { 'approved' => 'approved', 'waiting' => 'pending', 'declined' => 'pending', default => 'pending' } ?>"><?= ucfirst(e($row['account_state'])) ?></span></td>
                                       <td><?= e($row['location_value']) ?></td>
                                       <td>
                                           <div class="inline-actions">
                                               <form method="POST" class="inline-form">
                                                   <input type="hidden" name="action" value="approve_user">
                                                   <input type="hidden" name="role" value="<?= e($row['role']) ?>">
                                                   <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                                                   <button type="submit" class="btn small-btn btn-success">Approve</button>
                                               </form>
                                               <form method="POST" class="inline-form">
                                                   <input type="hidden" name="action" value="wait_user">
                                                   <input type="hidden" name="role" value="<?= e($row['role']) ?>">
                                                   <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                                                   <button type="submit" class="btn small-btn btn-wait">Wait</button>
                                               </form>
                                               <form method="POST" class="inline-form">
                                                   <input type="hidden" name="action" value="decline_user">
                                                   <input type="hidden" name="role" value="<?= e($row['role']) ?>">
                                                   <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                                                   <button type="submit" class="btn small-btn btn-decline">Decline</button>
                                               </form>
                                               <a class="btn small-btn btn-map" href="admin_control_room.php?role=<?= e($row['role']) ?>&user_id=<?= (int)$row['id'] ?>">Control Room</a>
                                               <form method="POST" class="inline-form map-form">
                                                   <input type="hidden" name="action" value="update_device_location">
                                                   <input type="hidden" name="role" value="<?= e($row['role']) ?>">
                                                   <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                                                   <input type="text" name="latitude" value="<?= e((string)($row['location_lat'] ?? '')) ?>" placeholder="Lat" class="mini-input">
                                                   <input type="text" name="longitude" value="<?= e((string)($row['location_lng'] ?? '')) ?>" placeholder="Lng" class="mini-input">
                                                   <input type="text" name="location_label" value="<?= e((string)($row['location_value'] ?? '')) ?>" placeholder="Location label" class="mini-input">
                                                   <button type="submit" class="btn small-btn btn-map">Map</button>
                                               </form>
                                           </div>
                                       </td>
                                   </tr>
                               <?php endforeach; ?>
                           </tbody>
                       </table>
                   </div>
               </div>
           <?php endforeach; ?>
       </div>
    </div>

   <div class="dashboard-card">
       <h2>Chatroom Tickets</h2>
       <div class="category-stack">
           <?php foreach (['students' => 'Student Tickets', 'staff' => 'Staff Tickets', 'foremen' => 'Foreman Tickets'] as $roleKey => $label): ?>
               <div class="user-category">
                   <h3><?= e($label) ?></h3>
                   <div class="table-responsive">
                       <table>
                           <thead>
                               <tr>
                                   <th>Ticket #</th>
                                   <th>Submitter</th>
                                   <th>Message</th>
                                   <th>Created</th>
                                   <th>Status</th>
                               </tr>
                           </thead>
                           <tbody>
                               <?php $tickets = $ticketsByRole[$roleKey] ?? []; ?>
                               <?php if (empty($tickets)): ?>
                                   <tr><td colspan="5">No tickets in this category.</td></tr>
                               <?php else: ?>
                                   <?php foreach ($tickets as $t): ?>
                                       <tr>
                                           <td><a class="ticket-link" href="admin_view_chat.php?conv=<?= urlencode($roleKey . ':' . $t['submitter_id']) ?>&report=<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
                                           <td><?= e((string)($t['submitter_name'] ?? $t['submitter_id'])) ?></td>
                                           <td><?= e(mb_strimwidth($t['message'], 0, 80, '...')) ?></td>
                                           <td><?= e((string)$t['created_at']) ?></td>
                                           <td>
                                               <?php
                                                   $statusClass = 'pending';
                                                   if (strtolower($t['status']) === 'attended') $statusClass = 'approved';
                                                   if (strtolower($t['status']) === 'resolved') $statusClass = 'approved';
                                                   if (strtolower($t['status']) === 'waiting') $statusClass = 'pending';
                                               ?>
                                               <form method="POST" action="update_ticket_status.php" style="display:inline-block;">
                                                   <input type="hidden" name="report_id" value="<?= (int)$t['id'] ?>">
                                                   <select name="action" onchange="this.form.submit()" style="padding:6px 10px;border-radius:8px;border:1px solid rgba(51,52,143,0.3);background:#f5f5fa;color:#1B1C5E;">
                                                       <option value="attended" <?= strtolower($t['status']) === 'attended' ? 'selected' : '' ?>>Attended</option>
                                                       <option value="waiting" <?= strtolower($t['status']) === 'waiting' ? 'selected' : '' ?>>Waiting</option>
                                                       <option value="resolved" <?= strtolower($t['status']) === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                                                   </select>
                                               </form>
                                           </td>
                                       </tr>
                                   <?php endforeach; ?>
                               <?php endif; ?>
                           </tbody>
                       </table>
                   </div>

                   <!-- Conversations for admin chat (per-role) -->
                   <div class="table-responsive" style="margin-top:12px;">
                       <h4>Conversations</h4>
                       <table>
                           <thead>
                               <tr><th>Conversation</th><th>Last Message</th><th>Updated</th><th>Action</th></tr>
                           </thead>
                           <tbody>
                               <?php
                                   // load last messages for conversations belonging to roleKey
                                   $convStmt = $pdo->prepare("SELECT conversation_key, message, created_at FROM chat_messages WHERE conversation_key LIKE :prefix ORDER BY id DESC");
                                   $prefix = $roleKey . ':%';
                                   $convStmt->execute(['prefix' => $prefix]);
                                   $seen = [];
                                   foreach ($convStmt->fetchAll() as $c) {
                                       $ck = $c['conversation_key'];
                                       if (isset($seen[$ck])) continue;
                                       $seen[$ck] = true;
                                       $lastMsg = mb_strimwidth($c['message'], 0, 80, '...');
                                       $when = $c['created_at'];
                                       echo '<tr><td>' . e($ck) . '</td><td>' . e($lastMsg) . '</td><td>' . e($when) . '</td><td><a class="btn small-btn" href="admin_view_chat.php?conv=' . urlencode($ck) . '">Open</a></td></tr>';
                                   }
                                   if (empty($seen)) echo '<tr><td colspan="4">No conversations</td></tr>';
                               ?>
                           </tbody>
                       </table>
                   </div>

               </div>
           <?php endforeach; ?>
       </div>
   </div>

</div>
</body>
</html>

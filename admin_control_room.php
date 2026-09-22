<?php
require_once __DIR__ . '/config.php';
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

if (empty($_SESSION['authenticated']) || empty($_SESSION['user_status']) || empty($_SESSION['user_id'])) {
    redirect('login.php');
}

if ($_SESSION['user_status'] !== 'admin') {
    redirect('dashboard.php');
}

$ownerRoleMap = ['students' => 'student', 'staff' => 'staff', 'foremen' => 'foreman'];
$persistentControls = ['website_blocker', 'url_scanner_activation', 'live_location'];
$oneShotCommands = ['remote_lock', 'app_delete', 'app_killswitch'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = postString('action');

    if ($action === 'control_action') {
        $controlName = postString('control_name');
        $enabled = (int)postString('enabled');
        $postRole = postString('role');
        $postUserId = (int)postString('user_id');

        if ($controlName !== '' && isset($ownerRoleMap[$postRole]) && $postUserId > 0) {
            $ownerRole = $ownerRoleMap[$postRole];
            $deviceStmt = $pdo->prepare("SELECT id FROM devices WHERE owner_role = :r AND owner_id = :id AND status != 'deleted' LIMIT 1");
            $deviceStmt->execute(['r' => $ownerRole, 'id' => $postUserId]);
            $deviceRow = $deviceStmt->fetch();

            if ($deviceRow === false) {
                $_SESSION['flash_message'] = 'No device enrolled for this account - nothing to control.';
            } else {
                $deviceId = (int)$deviceRow['id'];

                if (in_array($controlName, $persistentControls, true)) {
                    $pdo->prepare(
                        "INSERT INTO device_controls (device_id, control_name, enabled, updated_by_admin_id)
                         VALUES (:device_id, :name, :enabled, :admin_id)
                         ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by_admin_id = VALUES(updated_by_admin_id), updated_at = NOW()"
                    )->execute([
                        'device_id' => $deviceId,
                        'name' => $controlName,
                        'enabled' => $enabled,
                        'admin_id' => $_SESSION['user_id'],
                    ]);
                } elseif ($controlName === 'app_turn_off' || $controlName === 'app_turn_on') {
                    $agentEnabled = $controlName === 'app_turn_on' ? 1 : 0;
                    $pdo->prepare(
                        "INSERT INTO device_controls (device_id, control_name, enabled, updated_by_admin_id)
                         VALUES (:device_id, 'agent_enabled', :enabled, :admin_id)
                         ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by_admin_id = VALUES(updated_by_admin_id), updated_at = NOW()"
                    )->execute([
                        'device_id' => $deviceId,
                        'enabled' => $agentEnabled,
                        'admin_id' => $_SESSION['user_id'],
                    ]);
                } elseif (in_array($controlName, $oneShotCommands, true)) {
                    if ($enabled === 1) {
                        $pdo->prepare(
                            "INSERT INTO device_commands (device_id, command_name, status, issued_by_admin_id)
                             VALUES (:device_id, :name, 'pending', :admin_id)"
                        )->execute(['device_id' => $deviceId, 'name' => $controlName, 'admin_id' => $_SESSION['user_id']]);

                        if ($controlName === 'app_killswitch') {
                            $pdo->prepare("UPDATE devices SET status = 'killed' WHERE id = :id")->execute(['id' => $deviceId]);
                        }
                    } else {
                        $pdo->prepare(
                            "UPDATE device_commands SET status = 'expired' WHERE device_id = :device_id AND command_name = :name AND status = 'pending'"
                        )->execute(['device_id' => $deviceId, 'name' => $controlName]);

                        if ($controlName === 'app_killswitch') {
                            $pdo->prepare("UPDATE devices SET status = 'active' WHERE id = :id AND status = 'killed'")->execute(['id' => $deviceId]);
                        }
                    }
                }

                $pdo->prepare(
                    "INSERT INTO device_events (device_id, owner_role, owner_id, actor_type, actor_id, event_type, event_detail)
                     VALUES (:device_id, :role, :owner_id, 'admin', :admin_id, 'control_changed', :detail)"
                )->execute([
                    'device_id' => $deviceId,
                    'role' => $ownerRole,
                    'owner_id' => $postUserId,
                    'admin_id' => (string)$_SESSION['user_id'],
                    'detail' => json_encode(['control_name' => $controlName, 'enabled' => $enabled]),
                ]);

                $_SESSION['flash_message'] = 'Control room command updated.';
            }
        }
    }

    redirect('admin_control_room.php?role=' . urlencode(postString('role')) . '&user_id=' . urlencode(postString('user_id')));
}

$role = $_GET['role'] ?? 'students';
$userId = (int)($_GET['user_id'] ?? 0);
$roleMap = [
    'students' => ['table' => 'students', 'id_column' => 'student_id', 'label' => 'Student'],
    'staff' => ['table' => 'staff', 'id_column' => 'worker_reg_no', 'label' => 'Staff'],
    'foremen' => ['table' => 'foremen', 'id_column' => 'foreman_reg_no', 'label' => 'Foreman'],
];

if (!isset($roleMap[$role]) || $userId <= 0) {
    redirect('admin_dashboard.php');
}

$table = $roleMap[$role]['table'];
$stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    redirect('admin_dashboard.php');
}

$displayName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
if ($role === 'staff') {
    $displayName = trim((string)($user['full_name'] ?? ''));
}

if ($displayName === '') {
    $displayName = (string)($user[$roleMap[$role]['id_column']] ?? 'User');
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

$ownerRole = $ownerRoleMap[$role];
$deviceStmt = $pdo->prepare("SELECT * FROM devices WHERE owner_role = :r AND owner_id = :id AND status != 'deleted' ORDER BY enrolled_at DESC LIMIT 1");
$deviceStmt->execute(['r' => $ownerRole, 'id' => $userId]);
$device = $deviceStmt->fetch();

$controlStates = [];
$commandStates = [];

if ($device) {
    $deviceId = (int)$device['id'];

    $ctrlStmt = $pdo->prepare("SELECT control_name, enabled FROM device_controls WHERE device_id = :id");
    $ctrlStmt->execute(['id' => $deviceId]);
    foreach ($ctrlStmt->fetchAll() as $row) {
        $controlStates[(string)$row['control_name']] = (int)$row['enabled'];
    }
    // app_turn_off / app_turn_on both reflect the single agent_enabled toggle.
    $controlStates['app_turn_on'] = (int)($controlStates['agent_enabled'] ?? 1);
    $controlStates['app_turn_off'] = $controlStates['app_turn_on'] ? 0 : 1;

    foreach ($oneShotCommands as $cmdName) {
        $cmdStmt = $pdo->prepare(
            "SELECT status FROM device_commands WHERE device_id = :id AND command_name = :name ORDER BY issued_at DESC LIMIT 1"
        );
        $cmdStmt->execute(['id' => $deviceId, 'name' => $cmdName]);
        $latest = $cmdStmt->fetch();
        $isPending = $latest && in_array($latest['status'], ['pending', 'delivered', 'received'], true);
        $controlStates[$cmdName] = $isPending ? 1 : 0;
        $commandStates[$cmdName] = $latest ? (string)$latest['status'] : 'none';
    }

    $deviceOnline = $device['last_poll_at'] && (strtotime((string)$device['last_poll_at']) > time() - 90);

    $recentEventsStmt = $pdo->prepare("SELECT * FROM device_events WHERE device_id = :id ORDER BY id DESC LIMIT 20");
    $recentEventsStmt->execute(['id' => $deviceId]);
    $recentEvents = $recentEventsStmt->fetchAll();
}

$recentEvents = $recentEvents ?? [];

$flashMessage = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

// Build a list of all devices (students, staff, foremen) that have coordinates so the admin map can show them all.
$allDevices = [];
$roleTables = [
    'students' => 'students',
    'staff' => 'staff',
    'foremen' => 'foremen',
];

foreach ($roleTables as $roleName => $tableName) {
    try {
    // Select all columns and handle missing name fields in PHP to avoid column-not-found errors
    $stmt = $pdo->prepare("SELECT * FROM {$tableName} WHERE location_lat IS NOT NULL AND location_lat != '' AND location_lng IS NOT NULL AND location_lng != ''");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    foreach ($rows as $r) {
            $display = trim((string)($r['full_name'] ?? '') . ' ' . (string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
            if ($display === '') {
                $display = sprintf('%s-%s', $roleName, (string)($r['id'] ?? ''));
            }

            $lat = isset($r['location_lat']) ? (float)$r['location_lat'] : null;
            $lng = isset($r['location_lng']) ? (float)$r['location_lng'] : null;

            if ($lat !== null && $lng !== null) {
                $allDevices[] = [
                    'role' => $roleName,
                    'id' => (int)$r['id'],
                    'name' => $display,
                    'label' => (string)($r['location_label'] ?? ''),
                    'device_status' => (string)($r['device_status'] ?? ''),
                    'lat' => $lat,
                    'lng' => $lng,
                ];
            }
        }
    } catch (Throwable $ex) {
        // ignore per-table failures but continue
    }
}

$allDevicesJson = json_encode($allDevices, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="assets/img/favicon.png?v=1">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control Room | MoWLiSS</title>
    <link rel="stylesheet" href="style.css?v=7">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
</head>
<body>
<?php render_site_header(); ?>
<div class="container">
    <div class="dashboard-top">
        <div>
            <h1>MoWLiSS Dashboard</h1>
            <p class="small">Control Room</p>
        </div>
        <div>
            <a class="btn" style="display:inline-block; width:auto; text-decoration:none;" href="admin_dashboard.php">Back to Admin</a>
        </div>
    </div>

    <?php if ($flashMessage !== ''): ?>
        <div class="alert alert-success"><?= e($flashMessage) ?></div>
    <?php endif; ?>

    <div class="dashboard-card">
        <h2><?= e($displayName) ?> — <?= e($roleMap[$role]['label']) ?> Account</h2>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Field</th>
                        <th>Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><th>Account Type</th><td><?= e($roleMap[$role]['label']) ?></td></tr>
                    <tr><th>Account ID</th><td><?= e((string)($user[$roleMap[$role]['id_column']] ?? '')) ?></td></tr>
                    <?php if ($device): ?>
                        <tr><th>Enrolled Device</th><td><?= e((string)($device['device_name'] ?? 'Unknown')) ?></td></tr>
                        <tr><th>Online Status</th><td><span id="online-status-badge" class="status-badge <?= $deviceOnline ? 'approved' : 'pending' ?>"><?= $deviceOnline ? 'Online' : 'Offline' ?></span></td></tr>
                    <?php else: ?>
                        <tr><th>Enrolled Device</th><td><em>None</em></td></tr>
                    <?php endif; ?>
                    <tr><th>Device Status</th><td id="device-status-cell"><?= e((string)($user['device_status'] ?? 'healthy')) ?></td></tr>
                    <tr><th>Last Seen</th><td><?= e((string)($user['last_seen'] ?? 'Never')) ?></td></tr>
                    <tr><th>Location</th><td><?= e((string)($user['location_label'] ?? 'No location yet')) ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="dashboard-card">
        <h2>App Controls</h2>
        <p class="small">Status updates from the device appear within 2 seconds - no need to refresh.</p>
        <?php if (!$device): ?>
            <p>No device enrolled for this account yet - nothing to control. The account holder can enroll a device
            from their own dashboard once logged in.</p>
        <?php else: ?>
        <div class="control-grid">
            <?php foreach ($controlActions as $key => $label): ?>
                <?php
                    $enabled = (int)($controlStates[$key] ?? 0);
                    $isCommand = isset($commandStates[$key]);
                    $badgeText = $isCommand ? strtoupper($commandStates[$key]) : ($enabled ? 'ON' : 'OFF');
                ?>
                <form method="POST" class="admin-control-form">
                    <input type="hidden" name="action" value="control_action">
                    <input type="hidden" name="role" value="<?= e($role) ?>">
                    <input type="hidden" name="user_id" value="<?= (int)$userId ?>">
                    <input type="hidden" name="control_name" value="<?= e($key) ?>">
                    <input type="hidden" name="enabled" value="<?= $enabled ? 0 : 1 ?>">
                    <button type="submit" class="control-btn <?= $enabled ? 'on' : 'off' ?>"><?= e($label) ?><?= $isCommand ? ($enabled ? ' (cancel)' : ' (issue)') : '' ?></button>
                    <span id="badge-<?= e($key) ?>" data-control="<?= e($key) ?>" data-is-command="<?= $isCommand ? '1' : '0' ?>" class="status-badge <?= $enabled ? 'approved' : 'pending' ?>"><?= e($badgeText) ?></span>
                </form>
            <?php endforeach; ?>
        </div>

        <h3 style="margin-top:20px;color:var(--navy-dark);">Recent Activity</h3>
        <p class="small">What's happening on this device, most recent first.</p>
        <ul id="activity-feed" class="activity-feed" data-last-event-id="<?= (int)($recentEvents[0]['id'] ?? 0) ?>">
            <?php if (empty($recentEvents)): ?>
                <li class="chat-empty">No activity yet.</li>
            <?php else: ?>
                <?php foreach ($recentEvents as $ev): ?>
                    <li data-event-id="<?= (int)$ev['id'] ?>"><span class="activity-time"><?= e((string)$ev['created_at']) ?></span> &mdash; <?= e(describe_device_event($ev)) ?></li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
        <?php endif; ?>
    </div>

    <div class="dashboard-card">
        <h2>Live Devices Map</h2>
        <p class="small">Auto-refreshes every 2 seconds while this page is open. <span id="map-last-updated"></span></p>
        <div id="admin-map" style="width:100%; height:480px; border-radius:12px; overflow:hidden;"></div>
        <script>
            (function(){
                const initialDevices = <?= $allDevicesJson ?> || [];

                const mapEl = document.getElementById('admin-map');
                const lastUpdatedEl = document.getElementById('map-last-updated');
                const map = L.map(mapEl).setView([0,0], 2);

                const tileUrl = <?= json_encode(map_tile_url(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
                const tileAttribution = <?= json_encode(map_attribution(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

                L.tileLayer(tileUrl, {
                    maxZoom: 19,
                    attribution: tileAttribution
                }).addTo(map);

                function escapeHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

                const markers = {}; // key "role:id" -> L.marker
                let hasFitBoundsOnce = false;

                function popupHtml(d) {
                    return '<strong>' + escapeHtml(d.name) + '</strong><br/>' +
                           'Role: ' + escapeHtml(d.role) + '<br/>' +
                           'Status: ' + escapeHtml(d.device_status) + '<br/>' +
                           (d.label ? ('Location: ' + escapeHtml(d.label)) : '');
                }

                function renderDevices(devices) {
                    if (!Array.isArray(devices)) return;

                    if (devices.length === 0 && Object.keys(markers).length === 0) {
                        mapEl.querySelectorAll('.map-placeholder').forEach(el => el.remove());
                        const placeholder = document.createElement('div');
                        placeholder.className = 'map-placeholder';
                        placeholder.textContent = 'No live locations available for any devices yet.';
                        mapEl.appendChild(placeholder);
                        return;
                    }

                    const seenKeys = new Set();
                    const bounds = [];

                    devices.forEach(d => {
                        const lat = parseFloat(d.lat);
                        const lng = parseFloat(d.lng);
                        if (isNaN(lat) || isNaN(lng)) return;

                        const key = d.role + ':' + d.id;
                        seenKeys.add(key);
                        bounds.push([lat, lng]);

                        if (markers[key]) {
                            markers[key].setLatLng([lat, lng]);
                            markers[key].setPopupContent(popupHtml(d));
                        } else {
                            const marker = L.marker([lat, lng]).addTo(map);
                            marker.bindPopup(popupHtml(d));
                            markers[key] = marker;
                        }
                    });

                    // Remove markers for devices that no longer report a location
                    // (e.g. live_location was turned off or the device was removed).
                    Object.keys(markers).forEach(key => {
                        if (!seenKeys.has(key)) {
                            map.removeLayer(markers[key]);
                            delete markers[key];
                        }
                    });

                    if (!hasFitBoundsOnce && bounds.length) {
                        map.fitBounds(bounds, {padding: [50,50]});
                        hasFitBoundsOnce = true;
                    }

                    if (lastUpdatedEl) {
                        lastUpdatedEl.textContent = 'Last updated: ' + new Date().toLocaleTimeString();
                    }
                }

                function refreshFromServer() {
                    fetch('dump_devices_json.php', { credentials: 'same-origin' })
                        .then(r => r.ok ? r.json() : Promise.reject(r.status))
                        .then(renderDevices)
                        .catch(() => { /* keep showing last-known positions if a poll fails */ });
                }

                renderDevices(initialDevices);
                setInterval(refreshFromServer, 2000);
            })();
        </script>
    </div>
</div>
<?php if ($device): ?>
<script>
(function(){
    const role = <?= json_encode($role) ?>;
    const userId = <?= (int)$userId ?>;
    const feedEl = document.getElementById('activity-feed');
    let lastEventId = parseInt(feedEl ? feedEl.dataset.lastEventId : '0', 10) || 0;
    const commandNames = new Set(<?= json_encode(array_keys($commandStates)) ?>);

    function applyControlsAndCommands(data) {
        Object.keys(data.controls || {}).forEach(key => {
            if (commandNames.has(key)) return; // handled below instead
            const badge = document.getElementById('badge-' + key);
            if (!badge) return;
            const enabled = !!data.controls[key];
            badge.textContent = enabled ? 'ON' : 'OFF';
            badge.classList.toggle('approved', enabled);
            badge.classList.toggle('pending', !enabled);
        });

        Object.keys(data.commands || {}).forEach(key => {
            const badge = document.getElementById('badge-' + key);
            if (!badge) return;
            const status = data.commands[key] || 'none';
            badge.textContent = status.toUpperCase();
            const isActive = ['pending', 'delivered', 'received'].includes(status);
            badge.classList.toggle('approved', isActive);
            badge.classList.toggle('pending', !isActive);
        });
    }

    function applyDeviceStatus(data) {
        const onlineBadge = document.getElementById('online-status-badge');
        if (onlineBadge) {
            onlineBadge.textContent = data.online ? 'Online' : 'Offline';
            onlineBadge.classList.toggle('approved', !!data.online);
            onlineBadge.classList.toggle('pending', !data.online);
        }
        const statusCell = document.getElementById('device-status-cell');
        if (statusCell && data.device_status) {
            statusCell.textContent = data.device_status;
        }
    }

    function appendEvents(events) {
        if (!feedEl || !events || events.length === 0) return;

        const emptyMsg = feedEl.querySelector('.chat-empty');
        if (emptyMsg) emptyMsg.remove();

        events.forEach(ev => {
            const li = document.createElement('li');
            li.dataset.eventId = ev.id;
            li.className = 'activity-new';
            const timeSpan = document.createElement('span');
            timeSpan.className = 'activity-time';
            timeSpan.textContent = ev.created_at;
            li.appendChild(timeSpan);
            li.appendChild(document.createTextNode(' — ' + ev.message));
            feedEl.insertBefore(li, feedEl.firstChild);
            if (ev.id > lastEventId) lastEventId = ev.id;
        });

        while (feedEl.children.length > 30) {
            feedEl.removeChild(feedEl.lastChild);
        }
    }

    function poll() {
        const url = 'device_activity_poll.php?role=' + encodeURIComponent(role) + '&user_id=' + userId + '&after_id=' + lastEventId;
        fetch(url, { credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : Promise.reject(r.status))
            .then(data => {
                if (data.no_device) return;
                applyControlsAndCommands(data);
                applyDeviceStatus(data);
                appendEvents(data.events);
            })
            .catch(() => { /* keep showing last-known state if a poll fails */ });
    }

    setInterval(poll, 2000);
})();
</script>
<?php endif; ?>
</body>
</html>

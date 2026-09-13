<?php
function render_dashboard_chatrooms(string $role): void
{
    $rooms = [
        'student' => [
            [ 'name' => 'NICTA', 'status' => 'Open', 'link' => 'nicta_chatroom_report.php' ],
            [ 'name' => 'DICT', 'status' => 'Open', 'link' => 'dict_chatroom_report.php' ],
            [ 'name' => 'RPNGC', 'status' => 'Open', 'link' => 'rcpng_chatroom_report.php' ],
            [ 'name' => 'ADMIN', 'status' => 'Open', 'link' => 'admin_chat_user.php' ],
        ],
        'staff' => [
            [ 'name' => 'NICTA', 'status' => 'Open', 'link' => 'nicta_chatroom_report.php' ],
            [ 'name' => 'DICT', 'status' => 'Open', 'link' => 'dict_chatroom_report.php' ],
            [ 'name' => 'RPNGC', 'status' => 'Open', 'link' => 'rcpng_chatroom_report.php' ],
            [ 'name' => 'ADMIN', 'status' => 'Open', 'link' => 'admin_chat_user.php' ],
        ],
        'foreman' => [
            [ 'name' => 'NICTA', 'status' => 'Open', 'link' => 'nicta_chatroom_report.php' ],
            [ 'name' => 'DICT', 'status' => 'Open', 'link' => 'dict_chatroom_report.php' ],
            [ 'name' => 'RPNGC', 'status' => 'Open', 'link' => 'rcpng_chatroom_report.php' ],
            [ 'name' => 'ADMIN', 'status' => 'Open', 'link' => 'admin_chat_user.php' ],
        ],
    ];

    $list = $rooms[$role] ?? $rooms['student'];
    ?>
    <div class="dashboard-card">
        <h2>Chatroom Reports</h2>

        <details class="chatroom-menu">
            <summary>Chatrooms</summary>
            <div class="chatroom-menu-list">
                <?php foreach ($list as $room): ?>
                    <a class="chatroom-menu-item" href="<?= e($room['link']) ?>">
                        <span><?= e($room['name']) ?></span>
                        <em><?= e($room['status']) ?></em>
                    </a>
                <?php endforeach; ?>
            </div>
        </details>
    </div>
    <?php
}

function render_dashboard_app_footer(): void
{
    ?>
    <footer class="footer-app">
        <div class="container">
            <div>
                <h3>MoWLiSS Agent (Windows Desktop App)</h3>
                <p class="small">Download the agent, then enroll it to your account so it shows up here and in the
                control room. It always shows a visible tray icon and keeps a local activity log while enrolled.</p>
            </div>
            <div class="download-grid">
                <a class="download-btn" href="downloads/mowliss-mobile-app.html" download>Download Agent</a>
                <a class="download-btn secondary" href="downloads/mowliss-mobile-app.html">View App Info</a>
                <a class="download-btn secondary" href="device_enroll_start.php">Enroll a Device</a>
            </div>
        </div>
    </footer>
    <?php
}

<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/site_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help & Support | MoWLiSS</title>
    <link rel="stylesheet" href="style.css?v=6">
</head>
<body>
<?php render_site_header(); ?>
<header class="topbar">
    <div class="container">
        <a href="login.html">Back to Login</a>
    </div>
</header>

<div class="auth-wrapper">
    <div class="auth-card wide">
        <div class="brand">
            <h1>Help & Support</h1>
            <p>Quick guidance for common access and account issues in MoWLiSS.</p>
        </div>

        <div class="support-box">
            <h3>Common questions</h3>
            <ul>
                <li><strong>I forgot my password:</strong> Use the <a href="forgot_password.php">Forgot Password</a> page and verify your ID and registered phone number.</li>
                <li><strong>My account is pending approval:</strong> Wait for the admin to approve your account. You can sign in only after approval.</li>
                <li><strong>I cannot log in:</strong> Check your user status, ID number, and password. If the problem continues, contact the ICT administrator.</li>
                <li><strong>My account is locked:</strong> Too many failed sign-ins can lock the account temporarily. Try again later or use password reset.</li>
            </ul>
        </div>

        <div class="support-box">
            <h3>Reset instructions</h3>
            <ol>
                <li>Select your account type.</li>
                <li>Enter your ID or registration number.</li>
                <li>Enter the phone number saved in your account.</li>
                <li>Use the generated 6-digit reset code to create a new password.</li>
            </ol>
        </div>

        <div class="support-box">
            <h3>Need direct support?</h3>
            <p>Please contact the ICT administrator or your department office to update the phone number linked to your account if the recorded number is no longer valid.</p>
        </div>

        <div class="links">
            <a href="forgot_password.php">Reset password</a>
            <a href="login.html">Return to login</a>
        </div>
    </div>
</div>
</body>
</html>

<?php
// Email delivery via Brevo's transactional email REST API.
// Configure via environment variables (see .env):
//   BREVO_API_KEY, BREVO_SENDER_EMAIL

function isValidEmail(string $email): bool
{
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * @return array{success: bool, error?: string}
 */
function sendEmailViaBrevo(string $toEmail, string $subject, string $textBody): array
{
    $apiKey = env('BREVO_API_KEY', '');
    $senderEmail = env('BREVO_SENDER_EMAIL', '');

    if ($apiKey === '' || $senderEmail === '') {
        error_log('Email not sent: Brevo credentials are not configured (BREVO_API_KEY / BREVO_SENDER_EMAIL).');
        return ['success' => false, 'error' => 'Email delivery is not configured yet. Please contact ICT support.'];
    }

    $payload = [
        'sender' => ['email' => $senderEmail, 'name' => 'MoWLiSS'],
        'to' => [['email' => $toEmail]],
        'subject' => $subject,
        'textContent' => $textBody,
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('Email send failed (cURL error): ' . $curlError);
        return ['success' => false, 'error' => 'Could not reach the email provider. Please try again shortly.'];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("Email send failed (HTTP {$httpCode}): {$response}");
        return ['success' => false, 'error' => 'The email provider rejected the message. Please try again or contact ICT support.'];
    }

    return ['success' => true];
}

/**
 * @return array{success: bool, error?: string}
 */
function sendPasswordResetEmail(string $rawEmail, string $code): array
{
    $email = trim($rawEmail);

    if (!isValidEmail($email)) {
        return ['success' => false, 'error' => 'The email on file is not valid. Please contact ICT support.'];
    }

    $subject = 'MoWLiSS password reset code';
    $body = "Your MoWLiSS password reset code is: {$code}\n\nIt expires in 15 minutes. Do not share this code with anyone.";

    return sendEmailViaBrevo($email, $subject, $body);
}

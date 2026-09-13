<?php
// SMS delivery for PNG mobile numbers via Twilio's REST API.
// Configure via environment variables (see .env.example):
//   TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_FROM_NUMBER

function normalizePngPhoneForSms(string $rawPhone): ?string
{
    $digits = preg_replace('/[^\d+]/', '', trim($rawPhone)) ?? '';

    if ($digits === '') {
        return null;
    }

    if (str_starts_with($digits, '+675')) {
        $digits = substr($digits, 4);
    } elseif (str_starts_with($digits, '675')) {
        $digits = substr($digits, 3);
    } elseif (str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    } elseif (str_starts_with($digits, '+')) {
        // Already has a different country code — not a PNG number we can normalize.
        return null;
    }

    if (!preg_match('/^\d{8}$/', $digits)) {
        return null;
    }

    return '+675' . $digits;
}

/**
 * @return array{success: bool, error?: string}
 */
function sendSmsViaTwilio(string $toE164, string $message): array
{
    $accountSid = getenv('TWILIO_ACCOUNT_SID') ?: '';
    $authToken = getenv('TWILIO_AUTH_TOKEN') ?: '';
    $fromNumber = getenv('TWILIO_FROM_NUMBER') ?: '';

    if ($accountSid === '' || $authToken === '' || $fromNumber === '') {
        error_log('SMS not sent: Twilio credentials are not configured (TWILIO_ACCOUNT_SID / TWILIO_AUTH_TOKEN / TWILIO_FROM_NUMBER).');
        return ['success' => false, 'error' => 'SMS delivery is not configured yet. Please contact ICT support.'];
    }

    $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'To' => $toE164,
            'From' => $fromNumber,
            'Body' => $message,
        ]),
        CURLOPT_USERPWD => "{$accountSid}:{$authToken}",
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('SMS send failed (cURL error): ' . $curlError);
        return ['success' => false, 'error' => 'Could not reach the SMS provider. Please try again shortly.'];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("SMS send failed (HTTP {$httpCode}): {$response}");
        return ['success' => false, 'error' => 'The SMS provider rejected the message. Please try again or contact ICT support.'];
    }

    return ['success' => true];
}

/**
 * @return array{success: bool, error?: string}
 */
function sendPasswordResetSms(string $rawPhone, string $code): array
{
    $toE164 = normalizePngPhoneForSms($rawPhone);

    if ($toE164 === null) {
        return ['success' => false, 'error' => 'The phone number on file is not a valid PNG mobile number. Please contact ICT support.'];
    }

    $message = "MoWLiSS password reset code: {$code}. It expires in 15 minutes. Do not share this code.";

    return sendSmsViaTwilio($toE164, $message);
}

<?php
declare(strict_types=1);


require_once __DIR__ . '/config.php';

// =========================================
// SEND PICKUP OTP EMAIL
// =========================================

function sendPickupOtpEmail(
    string $recipientEmail,
    string $customerName,
    string $orderId,
    string $otp
): array {

    $safeName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');
    $safeOrder = htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8');
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

    $subject = 'Pickup OTP - Order ' . $orderId;

    $html = <<<HTML
<!doctype html>
<html>
<body style="font-family:Arial,sans-serif;background:#f4f7f6;padding:24px;">
<div style="max-width:560px;margin:auto;background:white;padding:28px;border-radius:14px;">

<h2>Smart Food Delivery Locker</h2>

<p>Hello {$safeName},</p>

<p>Your food order has been registered.</p>

<p><strong>Order ID:</strong> {$safeOrder}</p>

<p>Your pickup OTP is:</p>

<div style="font-size:34px;font-weight:bold;letter-spacing:8px;padding:14px 0;">
{$safeOtp}
</div>

<p>Enter your Order ID and OTP at the locker after delivery.</p>

<p><strong>Do not share your OTP with the rider.</strong></p>

<p style="color:#667085;font-size:13px;">
A newly resent OTP replaces the previous OTP.
</p>

</div>
</body>
</html>
HTML;

    return resendEmail($recipientEmail, $subject, $html);
}

// =========================================
// SEND RECEIVED ORDER CONFIRMATION EMAIL
// =========================================

function sendReceivedOrderEmail(
    string $recipientEmail,
    string $customerName,
    string $orderId,
    string $confirmUrl
): array {

    $safeName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');
    $safeOrder = htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8');

    $subject = 'Confirm Received Order - ' . $orderId;

    $html = <<<HTML
<!doctype html>
<html>
<body style="font-family:Arial,sans-serif;background:#f4f7f6;padding:24px;">
<div style="max-width:560px;margin:auto;background:white;padding:28px;border-radius:14px;">

<h2>Smart Food Delivery Locker</h2>

<p>Hello {$safeName},</p>

<p>Your locker for <strong>Order {$safeOrder}</strong> is now open.</p>

<p>Please take your food and close the physical locker door before confirming.</p>

<p style="text-align:center;padding:16px 0;">
<a href="{$safeUrl}"
style="display:inline-block;background:#16794b;color:white;text-decoration:none;font-size:18px;font-weight:bold;padding:14px 32px;border-radius:10px;">
Received Order
</a>
</p>

<p>If the button doesn't work, use this link:</p>

<p><a href="{$safeUrl}">{$safeUrl}</a></p>

</div>
</body>
</html>
HTML;

    return resendEmail($recipientEmail, $subject, $html);
}

// =========================================
// SEND EMAIL THROUGH RESEND HTTPS API
// =========================================

function resendEmail(
    string $to,
    string $subject,
    string $html
): array {

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Invalid recipient email.'];
    }

    $apiKey = getenv('RESEND_API_KEY') ?: '';
    $fromEmail = getenv('RESEND_FROM_EMAIL') ?: '';

    if ($apiKey === '' || $fromEmail === '') {
        return [false, 'Resend is not configured.'];
    }

    if (!function_exists('curl_init')) {
        return [false, 'PHP cURL extension is missing.'];
    }

    $payload = json_encode([
        'from' => 'Smart Food Delivery Locker <' . $fromEmail . '>',
        'to' => [$to],
        'subject' => $subject,
        'html' => $html,
    ]);

    if ($payload === false) {
        return [false, 'Unable to encode email request.'];
    }

    $ch = curl_init('https://api.resend.com/emails');

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($result === false) {
        return [false, 'Email API connection failed: ' . $curlError];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $response = json_decode($result, true);
        $message = is_array($response)
            ? ($response['message'] ?? 'Email rejected')
            : 'Email rejected';

        return [false, 'Resend HTTP ' . $httpCode . ': ' . $message];
    }

    return [true, 'Email accepted by Resend.'];
}

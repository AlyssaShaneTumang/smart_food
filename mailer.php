<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Small Gmail SMTP sender for this school prototype.
 * No Composer package is required.
 *
 * Gmail requirements:
 * 1. Enable 2-Step Verification on the sender Google account.
 * 2. Create a Google App Password.
 * 3. Put the Gmail address and App Password in config.php.
 */
function sendPickupOtpEmail(
    string $recipientEmail,
    string $customerName,
    string $orderId,
    string $otp
): array {
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Invalid customer email address.'];
    }

    if (!gmailConfigured()) {
        return [false, 'Configure Gmail credentials in web/config.php first.'];
    }

    $subject = 'Your Smart Food Locker Pickup OTP - Order ' . $orderId;

    $safeName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');
    $safeOrder = htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8');
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<!doctype html>
<html>
<body style="font-family:Arial,sans-serif;background:#f4f7f6;padding:24px;color:#17202a;">
    <div style="max-width:560px;margin:auto;background:#ffffff;padding:28px;border-radius:14px;">
        <h2 style="margin-top:0;">Smart Food Delivery Locker</h2>
        <p>Hello {$safeName},</p>
        <p>Your food order has been registered.</p>
        <p><strong>Order ID:</strong> {$safeOrder}</p>
        <p style="font-size:18px;">Your pickup OTP is:</p>
        <div style="font-size:34px;font-weight:bold;letter-spacing:8px;padding:14px 0;">{$safeOtp}</div>
        <p>Enter your Order ID and this OTP at the locker when your order is ready for pickup.</p>
        <p><strong>Do not share this OTP with the rider.</strong></p>
        <p style="color:#667085;font-size:13px;">A newly resent OTP replaces the previous OTP.</p>
    </div>
</body>
</html>
HTML;

    return smtpSendMail($recipientEmail, $subject, $html);
}

/**
 * Sent after the customer opens the locker with their OTP.
 * The locker stays open until the customer taps "Received Order".
 */
function sendReceivedOrderEmail(
    string $recipientEmail,
    string $customerName,
    string $orderId,
    string $confirmUrl
): array {
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Invalid customer email address.'];
    }

    if (!gmailConfigured()) {
        return [false, 'Configure Gmail credentials in web/config.php first.'];
    }

    $subject = 'Locker Open - Tap "Received Order" to close - Order ' . $orderId;

    $safeName = htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8');
    $safeOrder = htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<!doctype html>
<html>
<body style="font-family:Arial,sans-serif;background:#f4f7f6;padding:24px;color:#17202a;">
    <div style="max-width:560px;margin:auto;background:#ffffff;padding:28px;border-radius:14px;">
        <h2 style="margin-top:0;">Smart Food Delivery Locker</h2>
        <p>Hello {$safeName},</p>
        <p>The locker for <strong>Order {$safeOrder}</strong> is now <strong>OPEN</strong>.</p>
        <p>Please take your food, then tap the button below. The locker will close automatically.</p>
        <p style="text-align:center;padding:16px 0;">
            <a href="{$safeUrl}"
               style="display:inline-block;background:#16794b;color:#ffffff;text-decoration:none;font-size:18px;font-weight:bold;padding:14px 32px;border-radius:10px;">
                Received Order
            </a>
        </p>
        <p style="color:#667085;font-size:13px;">If the button does not work, open this link:<br>{$safeUrl}</p>
    </div>
</body>
</html>
HTML;

    return smtpSendMail($recipientEmail, $subject, $html);
}

function gmailConfigured(): bool
{
    $email = trim(GMAIL_ADDRESS);
    $password = preg_replace('/\s+/', '', GMAIL_APP_PASSWORD);

    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && strlen($password) === 16
        && $email !== 'YOUR_GMAIL@gmail.com'
        && $password !== 'YOUR_16_CHARACTER_APP_PASSWORD';
}

function smtpSendMail(string $to, string $subject, string $html): array
{
    $socket = @stream_socket_client(
        'tcp://smtp.gmail.com:587',
        $errorNumber,
        $errorString,
        15
    );

    if (!$socket) {
        return [false, "SMTP connection failed: {$errorString} ({$errorNumber})"];
    }

    stream_set_timeout($socket, 15);

    try {
        smtpExpect($socket, [220]);
        smtpCommand($socket, 'EHLO localhost', [250]);
        smtpCommand($socket, 'STARTTLS', [220]);

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Could not enable TLS encryption.');
        }

        smtpCommand($socket, 'EHLO localhost', [250]);
        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode(GMAIL_ADDRESS), [334]);
        smtpCommand($socket, base64_encode(str_replace(' ', '', GMAIL_APP_PASSWORD)), [235]);
        smtpCommand($socket, 'MAIL FROM:<' . GMAIL_ADDRESS . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $fromName = '=?UTF-8?B?' . base64_encode(GMAIL_FROM_NAME) . '?=';

        $headers = [
            'From: ' . $fromName . ' <' . GMAIL_ADDRESS . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        // SMTP uses CRLF and ends DATA with a line containing only a dot.
        $body = str_replace(["\r\n", "\r"], "\n", $html);
        $body = str_replace("\n", "\r\n", $body);
        $body = preg_replace('/(?m)^\./', '..', $body);

        $message = implode("\r\n", $headers)
            . "\r\n\r\n"
            . $body
            . "\r\n.";

        smtpCommand($socket, $message, [250]);
        smtpCommand($socket, 'QUIT', [221]);

        fclose($socket);
        return [true, 'Email sent successfully.'];
    } catch (Throwable $e) {
        fclose($socket);
        return [false, $e->getMessage()];
    }
}

function smtpCommand($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    return smtpExpect($socket, $expectedCodes);
}

function smtpExpect($socket, array $expectedCodes): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;

        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    $code = (int) substr($response, 0, 3);

    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP error: ' . trim($response));
    }

    return $response;
}

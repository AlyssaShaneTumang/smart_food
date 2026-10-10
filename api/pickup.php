<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../mailer.php';

deviceAuth();

// Accept JSON from ESP32 and form POST from other clients
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') !== false) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        jsonOut([
            'ok' => false,
            'error' => 'Invalid JSON request.'
        ], 400);
    }
} else {
    $data = $_POST;
}

$orderId = trim((string) ($data['order_id'] ?? ''));
$pin = trim((string) ($data['pin'] ?? ''));

if ($orderId === '' || $pin === '') {
    jsonOut(
        [
            'ok' => false,
            'error' => 'Missing Order ID or OTP.',
        ],
        422
    );
}

$pdo = db();
$pdo->beginTransaction();

$statement = $pdo->prepare(
    'SELECT order_id, customer_name, customer_email, pin_hash, locker_id, status
     FROM orders
     WHERE order_id = ?
     FOR UPDATE'
);
$statement->execute([$orderId]);
$order = $statement->fetch();

$valid = $order
    && $order['status'] === 'DELIVERED'
    && password_verify($pin, $order['pin_hash']);

if (!$valid) {
    $pdo->rollBack();

    jsonOut(
        [
            'ok' => false,
            'error' => 'Invalid Order ID or OTP.',
        ],
        403
    );
}

// One-time token for the "Received Order" email button.
// Only its hash is stored, like the OTP.
$token = bin2hex(random_bytes(32));

$pdo->prepare(
    "UPDATE orders
     SET status = 'OPENED',
         opened_at = NOW(),
         confirm_token_hash = ?
     WHERE order_id = ?"
)->execute([hash('sha256', $token), $orderId]);

// The ESP32 keeps the servo open until door returns to CLOSED.
$pdo->prepare(
    "UPDATE lockers
     SET door = 'OPEN'
     WHERE id = ?"
)->execute([(int) $order['locker_id']]);

$pdo->prepare(
    "INSERT INTO events (order_id, event_type, details)
     VALUES (?, 'OPENED', 'Customer opened the locker with OTP')"
)->execute([$orderId]);

$pdo->commit();

$confirmUrl = rtrim(APP_BASE_URL, '/') . '/confirm.php?'
    . http_build_query([
        'order' => $orderId,
        'token' => $token,
    ]);

[$sent, $emailMessage] = sendReceivedOrderEmail(
    $order['customer_email'],
    $order['customer_name'],
    $orderId,
    $confirmUrl
);

$pdo->prepare(
    "INSERT INTO events (order_id, event_type, details)
     VALUES (?, ?, ?)"
)->execute([
    $orderId,
    $sent ? 'CONFIRM_EMAIL_SENT' : 'CONFIRM_EMAIL_FAILED',
    $sent ? '"Received Order" email sent' : mb_substr($emailMessage, 0, 255),
]);

jsonOut([
    'ok' => true,
    'locker_id' => (int) $order['locker_id'],
    'email_sent' => $sent,
]);

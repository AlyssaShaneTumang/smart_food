<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

deviceAuth();

// Accept both JSON and form POST requests
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') !== false) {
    $data = json_decode(file_get_contents('php://input'), true);
    $orderId = trim((string) ($data['order_id'] ?? ''));
} else {
    $orderId = trim((string) ($_POST['order_id'] ?? ''));
}

if ($orderId === '') {
    jsonOut(
        [
            'ok' => false,
            'error' => 'Missing Order ID.',
        ],
        422
    );
}

$pdo = db();
$pdo->beginTransaction();

try {
    $orderStatement = $pdo->prepare(
        'SELECT *
         FROM orders
         WHERE order_id = ?
         FOR UPDATE'
    );
    $orderStatement->execute([$orderId]);
    $order = $orderStatement->fetch();

    if (!$order || $order['status'] !== 'WAITING_DELIVERY') {
        throw new RuntimeException('Order is not ready for delivery.');
    }

    // The single locker was reserved for this order when it was created.
    $lockerStatement = $pdo->prepare(
        'SELECT *
         FROM lockers
         WHERE id = ?
         FOR UPDATE'
    );
    $lockerStatement->execute([LOCKER_ID]);
    $locker = $lockerStatement->fetch();

    if (
        !$locker
        || $locker['status'] !== 'RESERVED'
        || (int) $order['locker_id'] !== LOCKER_ID
    ) {
        throw new RuntimeException('Locker is not reserved for this order.');
    }

    $pdo->prepare(
        "UPDATE orders
         SET status = 'DELIVERED',
             delivered_at = NOW()
         WHERE order_id = ?"
    )->execute([$orderId]);

    $pdo->prepare(
        "UPDATE lockers
         SET status = 'OCCUPIED'
         WHERE id = ?"
    )->execute([LOCKER_ID]);

    $pdo->prepare(
        "INSERT INTO events (order_id, event_type, details)
         VALUES (?, 'DELIVERED', 'Food placed in the locker')"
    )->execute([$orderId]);

    $pdo->commit();

    jsonOut([
        'ok' => true,
        'locker_id' => LOCKER_ID,
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();

    jsonOut(
        [
            'ok' => false,
            'error' => $e->getMessage(),
        ],
        409
    );
}

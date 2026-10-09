<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/locker_actions.php';

$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create_order';

    if ($action === 'create_order') {
        createOrder();
    } elseif ($action === 'resend_otp') {
        resendOtp();
    } elseif ($action === 'cancel_order') {
        cancelOrder();
    } elseif ($action === 'close_locker') {
        closeLocker();
    }
}

function createOrder(): void
{
    global $message, $messageType;

    $orderId = trim($_POST['order_id'] ?? '');
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerEmail = trim($_POST['customer_email'] ?? '');

    if ($orderId === '' || $customerName === '' || $customerEmail === '') {
        $message = 'Please complete all order fields.';
        $messageType = 'error';
        return;
    }

    if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid Gmail/email address.';
        $messageType = 'error';
        return;
    }

    $otp = (string) random_int(1000, 9999);
    $pdo = db();

    try {
        $pdo->beginTransaction();

        // Only one locker: a new order can be placed only when it is free.
        $lockerStatement = $pdo->prepare(
            'SELECT status
             FROM lockers
             WHERE id = ?
             FOR UPDATE'
        );
        $lockerStatement->execute([LOCKER_ID]);
        $lockerStatus = $lockerStatement->fetchColumn();

        if ($lockerStatus !== 'AVAILABLE') {
            $pdo->rollBack();
            $message = 'The locker is currently in use. Wait until the current order is received.';
            $messageType = 'error';
            return;
        }

        $statement = $pdo->prepare(
            'INSERT INTO orders (
                order_id,
                customer_name,
                customer_email,
                pin_hash,
                locker_id
            ) VALUES (?, ?, ?, ?, ?)'
        );

        $statement->execute([
            $orderId,
            $customerName,
            $customerEmail,
            password_hash($otp, PASSWORD_DEFAULT),
            LOCKER_ID,
        ]);

        $pdo->prepare(
            "UPDATE lockers
             SET status = 'RESERVED'
             WHERE id = ?"
        )->execute([LOCKER_ID]);

        $pdo->prepare(
            "INSERT INTO events (order_id, event_type, details)
             VALUES (?, 'CREATED', 'Order registered')"
        )->execute([$orderId]);

        $pdo->commit();

        [$sent, $emailMessage] = sendPickupOtpEmail(
            $customerEmail,
            $customerName,
            $orderId,
            $otp
        );

        if ($sent) {
            $pdo->prepare(
                'UPDATE orders
                 SET otp_sent_at = NOW()
                 WHERE order_id = ?'
            )->execute([$orderId]);

            $pdo->prepare(
                "INSERT INTO events (order_id, event_type, details)
                 VALUES (?, 'OTP_SENT', 'Pickup OTP sent by Gmail')"
            )->execute([$orderId]);

            $message = 'Order created and pickup OTP sent to ' . $customerEmail . '.';
            $messageType = 'success';
        } else {
            $message = 'Order created, but Gmail could not send the OTP. ' . $emailMessage
                . ' Configure Gmail, then use Resend OTP.';
            $messageType = 'warning';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $message = 'Could not create order: ' . $e->getMessage();
        $messageType = 'error';
    }
}

function resendOtp(): void
{
    global $message, $messageType;

    $orderId = trim($_POST['order_id'] ?? '');
    $pdo = db();

    $statement = $pdo->prepare(
        'SELECT order_id, customer_name, customer_email, status
         FROM orders
         WHERE order_id = ?'
    );
    $statement->execute([$orderId]);
    $order = $statement->fetch();

    if (!$order) {
        $message = 'Order not found.';
        $messageType = 'error';
        return;
    }

    if (!in_array($order['status'], ['WAITING_DELIVERY', 'DELIVERED'], true)) {
        $message = 'OTP can only be resent before the customer opens the locker.';
        $messageType = 'error';
        return;
    }

    $newOtp = (string) random_int(1000, 9999);

    [$sent, $emailMessage] = sendPickupOtpEmail(
        $order['customer_email'],
        $order['customer_name'],
        $order['order_id'],
        $newOtp
    );

    if (!$sent) {
        $message = 'OTP was not changed because Gmail failed: ' . $emailMessage;
        $messageType = 'error';
        return;
    }

    $pdo->prepare(
        'UPDATE orders
         SET pin_hash = ?, otp_sent_at = NOW()
         WHERE order_id = ?'
    )->execute([
        password_hash($newOtp, PASSWORD_DEFAULT),
        $orderId,
    ]);

    $pdo->prepare(
        "INSERT INTO events (order_id, event_type, details)
         VALUES (?, 'OTP_RESENT', 'A new pickup OTP was sent by Gmail')"
    )->execute([$orderId]);

    $message = 'A new OTP was sent to ' . $order['customer_email']
        . '. The previous OTP is no longer valid.';
    $messageType = 'success';
}

function cancelOrder(): void
{
    global $message, $messageType;

    $orderId = trim($_POST['order_id'] ?? '');
    $pdo = db();
    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'SELECT status, locker_id
         FROM orders
         WHERE order_id = ?
         FOR UPDATE'
    );
    $statement->execute([$orderId]);
    $order = $statement->fetch();

    // Once food is inside the locker the order can no longer be cancelled.
    if (!$order || $order['status'] !== 'WAITING_DELIVERY') {
        $pdo->rollBack();
        $message = 'Only orders still waiting for delivery can be cancelled.';
        $messageType = 'error';
        return;
    }

    $pdo->prepare(
        "UPDATE orders
         SET status = 'CANCELLED'
         WHERE order_id = ?"
    )->execute([$orderId]);

    $pdo->prepare(
        "UPDATE lockers
         SET status = 'AVAILABLE'
         WHERE id = ?"
    )->execute([(int) $order['locker_id']]);

    $pdo->prepare(
        "INSERT INTO events (order_id, event_type, details)
         VALUES (?, 'CANCELLED', 'Order cancelled, locker released')"
    )->execute([$orderId]);

    $pdo->commit();

    $message = 'Order ' . $orderId . ' cancelled. The locker is available again.';
    $messageType = 'success';
}

function closeLocker(): void
{
    global $message, $messageType;

    $orderId = trim($_POST['order_id'] ?? '');

    if (completePickup($orderId, 'Locker closed manually from dashboard')) {
        $message = 'Order ' . $orderId . ' marked as received. The locker is closing.';
        $messageType = 'success';
    } else {
        $message = 'The locker is not open for this order.';
        $messageType = 'error';
    }
}

$orders = db()->query(
    'SELECT
        order_id,
        customer_name,
        customer_email,
        locker_id,
        status,
        otp_sent_at,
        created_at
     FROM orders
     ORDER BY id DESC
     LIMIT 50'
)->fetchAll();

$lockerStatement = db()->prepare(
    'SELECT id, name, status, door
     FROM lockers
     WHERE id = ?'
);
$lockerStatement->execute([LOCKER_ID]);
$locker = $lockerStatement->fetch();
$lockerAvailable = $locker && $locker['status'] === 'AVAILABLE';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Smart Food Delivery Locker</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <main class="container">
        <header class="page-header">
            <div>
                <p class="eyebrow">Restaurant Dashboard</p>
                <h1>Smart Food Delivery Locker</h1>
                <p class="subtitle">ESP32 locker management with Gmail pickup OTP</p>
            </div>
            <span class="badge">ESP32 + PHP + MySQL</span>
        </header>

        <?php if ($message !== ''): ?>
            <div class="notice notice-<?= h($messageType) ?>">
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <section class="grid grid-main">
            <article class="card">
                <h2>Create New Order</h2>
                <p class="muted">
                    The pickup OTP is generated automatically and emailed directly
                    to the customer. It is never shown to the rider.
                </p>

                <?php if (!$lockerAvailable): ?>
                    <div class="notice notice-warning">
                        The locker is in use. A new order can be placed once the
                        current customer taps "Received Order".
                    </div>
                <?php endif; ?>

                <form method="post" class="form-stack">
                    <input type="hidden" name="action" value="create_order">

                    <label>
                        Order ID
                        <input
                            type="text"
                            name="order_id"
                            required
                            placeholder="Example: 1001"
                            autocomplete="off"
                        >
                    </label>

                    <label>
                        Customer Name
                        <input
                            type="text"
                            name="customer_name"
                            required
                            placeholder="Juan Dela Cruz"
                        >
                    </label>

                    <label>
                        Customer Gmail / Email
                        <input
                            type="email"
                            name="customer_email"
                            required
                            placeholder="customer@gmail.com"
                        >
                    </label>

                    <button type="submit" <?= $lockerAvailable ? '' : 'disabled' ?>>
                        Create Order &amp; Send OTP
                    </button>
                </form>
            </article>

            <article class="card">
                <h2>System Flow</h2>
                <ol class="steps">
                    <li>Restaurant creates the order. The locker becomes unavailable.</li>
                    <li>Gmail sends a random 4-digit OTP directly to the customer.</li>
                    <li>Rider enters the Order ID, places the food, and the locker locks.</li>
                    <li>Customer enters Order ID + OTP. The servo opens the locker and it stays open.</li>
                    <li>Gmail sends a "Received Order" button to the customer.</li>
                    <li>Customer taps "Received Order". The locker closes and becomes available.</li>
                </ol>

                <div class="security-note">
                    <strong>Privacy:</strong>
                    The rider API never returns the customer's OTP.
                </div>
            </article>
        </section>

        <section class="card">
            <div class="section-heading">
                <div>
                    <h2>Locker Status</h2>
                    <p class="muted">Current state of the locker.</p>
                </div>
            </div>

            <?php if ($locker): ?>
                <div class="locker-card">
                    <strong><?= h($locker['name']) ?></strong>
                    <span class="locker-badges">
                        <span class="status status-<?= strtolower(h($locker['status'])) ?>">
                            <?= h($locker['status']) ?>
                        </span>
                        <span class="status status-<?= strtolower(h($locker['door'])) ?>">
                            DOOR <?= h($locker['door']) ?>
                        </span>
                    </span>
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="section-heading">
                <div>
                    <h2>Orders</h2>
                    <p class="muted">OTP values are intentionally not displayed.</p>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Email</th>
                            <th>Locker</th>
                            <th>Status</th>
                            <th>OTP Email</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><?= h($order['order_id']) ?></td>
                                <td><?= h($order['customer_name']) ?></td>
                                <td><?= h($order['customer_email']) ?></td>
                                <td>
                                    <?= $order['locker_id']
                                        ? '#' . (int) $order['locker_id']
                                        : '—' ?>
                                </td>
                                <td>
                                    <span class="status">
                                        <?= h($order['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= $order['otp_sent_at'] ? 'Sent ✓' : 'Not sent' ?>
                                </td>
                                <td>
                                    <?php if (in_array($order['status'], ['WAITING_DELIVERY', 'DELIVERED'], true)): ?>
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="action" value="resend_otp">
                                            <input
                                                type="hidden"
                                                name="order_id"
                                                value="<?= h($order['order_id']) ?>"
                                            >
                                            <button type="submit" class="button-secondary">
                                                Resend OTP
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($order['status'] === 'WAITING_DELIVERY'): ?>
                                        <form
                                            method="post"
                                            class="inline-form"
                                            onsubmit="return confirm('Cancel this order and free the locker?');"
                                        >
                                            <input type="hidden" name="action" value="cancel_order">
                                            <input
                                                type="hidden"
                                                name="order_id"
                                                value="<?= h($order['order_id']) ?>"
                                            >
                                            <button type="submit" class="button-secondary">
                                                Cancel
                                            </button>
                                        </form>
                                    <?php elseif ($order['status'] === 'OPENED'): ?>
                                        <form
                                            method="post"
                                            class="inline-form"
                                            onsubmit="return confirm('Mark as received and close the locker?');"
                                        >
                                            <input type="hidden" name="action" value="close_locker">
                                            <input
                                                type="hidden"
                                                name="order_id"
                                                value="<?= h($order['order_id']) ?>"
                                            >
                                            <button type="submit" class="button-secondary">
                                                Close Locker
                                            </button>
                                        </form>
                                    <?php elseif (in_array($order['status'], ['CLAIMED', 'CANCELLED'], true)): ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>

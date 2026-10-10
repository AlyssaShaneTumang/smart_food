<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/locker_actions.php';

// Opened from the "Received Order" button in the customer's email.
// The locker closes only on the POST below, so email link scanners
// that pre-open links cannot close the locker on their own.

$orderId = trim($_REQUEST['order'] ?? '');
$token = trim($_REQUEST['token'] ?? '');

$statement = db()->prepare(
    'SELECT order_id, customer_name, status, confirm_token_hash
     FROM orders
     WHERE order_id = ?'
);
$statement->execute([$orderId]);
$order = $statement->fetch();

$tokenValid = $order
    && $token !== ''
    && $order['confirm_token_hash'] !== null
    && hash_equals($order['confirm_token_hash'], hash('sha256', $token));

if ($tokenValid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (completePickup($orderId, 'Customer tapped "Received Order" - locker closed')) {
        $state = 'closed';
    } else {
        $state = 'invalid';
    }
} elseif ($tokenValid && $order['status'] === 'OPENED') {
    $state = 'ask';
} elseif ($order && $order['status'] === 'CLAIMED') {
    $state = 'already';
} else {
    $state = 'invalid';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Received Order</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <main class="container container-narrow">
        <article class="card confirm-card">
            <p class="eyebrow">Smart Food Delivery Locker</p>

            <?php if ($state === 'ask'): ?>
                <h1>Order <?= h($orderId) ?></h1>
                <p class="muted">
                    Hello <?= h($order['customer_name']) ?>, the locker is open.
                    Take your food, then tap the button to close the locker.
                </p>
                <form method="post" class="form-stack">
                    <input type="hidden" name="order" value="<?= h($orderId) ?>">
                    <input type="hidden" name="token" value="<?= h($token) ?>">
                    <button type="submit" class="button-large">Received Order</button>
                </form>
            <?php elseif ($state === 'closed'): ?>
                <h1>Thank you!</h1>
                <div class="notice notice-success">
                    Order <?= h($orderId) ?> received. The locker is now closing.
                </div>
            <?php elseif ($state === 'already'): ?>
                <h1>Already received</h1>
                <div class="notice notice-info">
                    Order <?= h($orderId) ?> was already received and the locker is closed.
                </div>
            <?php else: ?>
                <h1>Link not valid</h1>
                <div class="notice notice-error">
                    This "Received Order" link is invalid or has expired.
                </div>
            <?php endif; ?>
        </article>
    </main>
</body>
</html>

<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Customer has their food: mark the order CLAIMED and tell the
 * ESP32 to close the locker (door = CLOSED, status = AVAILABLE).
 *
 * Returns false if the order is not currently OPENED.
 */
function completePickup(string $orderId, string $details): bool
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare(
            'SELECT status, locker_id
             FROM orders
             WHERE order_id = ?
             FOR UPDATE'
        );
        $statement->execute([$orderId]);
        $order = $statement->fetch();

        if (!$order || $order['status'] !== 'OPENED') {
            $pdo->rollBack();
            return false;
        }

        $pdo->prepare(
            "UPDATE orders
             SET status = 'CLAIMED',
                 claimed_at = NOW(),
                 confirm_token_hash = NULL
             WHERE order_id = ?"
        )->execute([$orderId]);

        $pdo->prepare(
            "UPDATE lockers
             SET status = 'AVAILABLE',
                 door = 'CLOSED'
             WHERE id = ?"
        )->execute([(int) $order['locker_id']]);

        $pdo->prepare(
            "INSERT INTO events (order_id, event_type, details)
             VALUES (?, 'CLAIMED', ?)"
        )->execute([$orderId, $details]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

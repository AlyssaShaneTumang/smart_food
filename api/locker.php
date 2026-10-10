<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

// Polled by the ESP32 while the locker is open.
// When door becomes CLOSED (customer tapped "Received Order"),
// the ESP32 moves the servo back to the locked position.
deviceAuth();

$statement = db()->prepare(
    'SELECT status, door
     FROM lockers
     WHERE id = ?'
);
$statement->execute([LOCKER_ID]);
$locker = $statement->fetch();

if (!$locker) {
    jsonOut(
        [
            'ok' => false,
            'error' => 'Locker not found.',
        ],
        404
    );
}

jsonOut([
    'ok' => true,
    'status' => $locker['status'],
    'door' => $locker['door'],
]);

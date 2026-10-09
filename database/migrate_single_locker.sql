-- ============================================================
-- Upgrade an existing smart_food_locker database to the
-- single-locker + "Received Order" email flow.
-- Run once on a database created from the older schema.sql.
-- ============================================================
USE smart_food_locker;

ALTER TABLE lockers
    ADD COLUMN door ENUM('CLOSED', 'OPEN') NOT NULL DEFAULT 'CLOSED';

ALTER TABLE orders
    MODIFY status ENUM(
        'WAITING_DELIVERY',
        'DELIVERED',
        'OPENED',
        'CLAIMED',
        'CANCELLED'
    ) NOT NULL DEFAULT 'WAITING_DELIVERY',
    ADD COLUMN confirm_token_hash CHAR(64) DEFAULT NULL,
    ADD COLUMN opened_at DATETIME DEFAULT NULL;

-- Only Locker 1 exists now.
UPDATE orders SET locker_id = 1 WHERE locker_id IN (2, 3);
DELETE FROM lockers WHERE id <> 1;

-- Active orders now always hold Locker 1.
UPDATE orders
SET locker_id = 1
WHERE status IN ('WAITING_DELIVERY', 'DELIVERED');

UPDATE lockers
SET door = 'CLOSED',
    status = CASE
        WHEN EXISTS (SELECT 1 FROM orders WHERE status = 'DELIVERED') THEN 'OCCUPIED'
        WHEN EXISTS (SELECT 1 FROM orders WHERE status = 'WAITING_DELIVERY') THEN 'RESERVED'
        ELSE 'AVAILABLE'
    END
WHERE id = 1;

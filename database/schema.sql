-- ============================================================
-- Smart Food Delivery Locker Database
-- ============================================================

CREATE DATABASE IF NOT EXISTS smart_food_locker
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE smart_food_locker;

-- ============================================================
-- Locker
-- This prototype has ONE locker (id = 1).
-- status: AVAILABLE -> RESERVED (order placed) -> OCCUPIED (food inside)
-- door:   the servo position the ESP32 should hold.
-- ============================================================
CREATE TABLE IF NOT EXISTS lockers (
    id INT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    status ENUM('AVAILABLE', 'RESERVED', 'OCCUPIED')
        NOT NULL DEFAULT 'AVAILABLE',
    door ENUM('CLOSED', 'OPEN') NOT NULL DEFAULT 'CLOSED'
);

INSERT INTO lockers (id, name, status)
VALUES
    (1, 'Locker 1', 'AVAILABLE')
ON DUPLICATE KEY UPDATE
    name = VALUES(name);

-- ============================================================
-- Customer Orders
-- The raw OTP is NEVER stored. Only its password_hash is saved.
-- ============================================================
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id VARCHAR(40) NOT NULL UNIQUE,
    customer_name VARCHAR(100) NOT NULL,
    customer_email VARCHAR(190) NOT NULL,
    pin_hash VARCHAR(255) NOT NULL,
    locker_id INT DEFAULT NULL,
    status ENUM(
        'WAITING_DELIVERY',
        'DELIVERED',
        'OPENED',
        'CLAIMED',
        'CANCELLED'
    ) NOT NULL DEFAULT 'WAITING_DELIVERY',
    otp_sent_at DATETIME DEFAULT NULL,
    -- SHA-256 of the one-time "Received Order" email link token.
    confirm_token_hash CHAR(64) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME DEFAULT NULL,
    opened_at DATETIME DEFAULT NULL,
    claimed_at DATETIME DEFAULT NULL,

    CONSTRAINT fk_orders_locker
        FOREIGN KEY (locker_id)
        REFERENCES lockers(id)
        ON DELETE SET NULL
);

-- ============================================================
-- Audit / Activity Events
-- ============================================================
CREATE TABLE IF NOT EXISTS events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id VARCHAR(40),
    event_type VARCHAR(50),
    details VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

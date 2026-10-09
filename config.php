<?php

declare(strict_types=1);

// ============================================================
// Database Configuration
// ============================================================
const DB_HOST = '127.0.0.1';
const DB_NAME = 'smart_food_locker';
const DB_USER = 'root';
const DB_PASS = '';

// ============================================================
// ESP32 API Security
// IMPORTANT: Use the exact same key in smart_food_locker.ino.
// ============================================================
const DEVICE_API_KEY = 'smarfoodlocker123';

// ============================================================
// Locker
// This prototype has a single locker compartment.
// ============================================================
const LOCKER_ID = 1;

// ============================================================
// Public Website URL
// Used for the "Received Order" button in the customer's email.
// Use the laptop's LAN IPv4 address (NOT localhost) so the
// customer's phone on the same Wi-Fi can open the link.
// ============================================================
const APP_BASE_URL = 'http://192.168.100.5/smart_food_locker/web';

// ============================================================
// Gmail SMTP Configuration
// Use a Google App Password, NOT your normal Gmail password.
// Example sender: smartfoodlocker.demo@gmail.com
// ============================================================
const GMAIL_ADDRESS = 'venvengueco@gmail.com';
const GMAIL_APP_PASSWORD = 'jycm ifoe zwqt bnql';
const GMAIL_FROM_NAME = 'Smart Food Delivery Locker';

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST
            . ';dbname=' . DB_NAME
            . ';charset=utf8mb4';

        $pdo = new PDO(
            $dsn,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    return $pdo;
}

function jsonOut(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function deviceAuth(): void
{
    $key = $_SERVER['HTTP_X_API_KEY']
        ?? ($_POST['api_key'] ?? '');

    if (!hash_equals(DEVICE_API_KEY, (string) $key)) {
        jsonOut(
            [
                'ok' => false,
                'error' => 'Unauthorized',
            ],
            401
        );
    }
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}


<?php



// ============================================================
// DATABASE CONFIGURATION - RAILWAY MYSQL
// ============================================================

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'smart_food_locker');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// ============================================================
// ESP32 API SECURITY
// Must match the API key in your ESP32 sketch.
// ============================================================

define('DEVICE_API_KEY', getenv('DEVICE_API_KEY') ?: '');

// ============================================================
// LOCKER CONFIGURATION
// ============================================================

const LOCKER_ID = 1;

// ============================================================
// PUBLIC WEBSITE URL - RAILWAY
// ============================================================

define(
    'APP_BASE_URL',
    getenv('APP_BASE_URL')
        ?: 'https://smartfood-production-1d4b.up.railway.app'
);

// ============================================================
// GMAIL CONFIGURATION
// ============================================================

define('GMAIL_ADDRESS', getenv('GMAIL_ADDRESS') ?: '');
define('GMAIL_APP_PASSWORD', getenv('GMAIL_APP_PASSWORD') ?: '');
define(
    'GMAIL_FROM_NAME',
    getenv('GMAIL_FROM_NAME') ?: 'Smart Food Delivery Locker'
);

// ============================================================
// MYSQL CONNECTION
// ============================================================

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {

        $dsn = 'mysql:host=' . DB_HOST
            . ';port=' . DB_PORT
            . ';dbname=' . DB_NAME
            . ';charset=utf8mb4';

        $pdo = new PDO(
            $dsn,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    return $pdo;
}

// ============================================================
// JSON RESPONSE
// ============================================================

function jsonOut(array $data, int $code = 200): void
{
    http_response_code($code);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode($data);

    exit;
}

// ============================================================
// ESP32 API AUTHENTICATION
// ============================================================

function deviceAuth(): void
{
    $configuredKey = DEVICE_API_KEY;

    if ($configuredKey === '') {
        jsonOut([
            'ok' => false,
            'error' => 'Device API not configured.',
        ], 503);
    }

    $key = $_SERVER['HTTP_X_API_KEY']
        ?? ($_POST['api_key'] ?? '');

    if (!hash_equals($configuredKey, (string) $key)) {
        jsonOut([
            'ok' => false,
            'error' => 'Unauthorized',
        ], 401);
    }
}

// ============================================================
// HTML ESCAPE HELPER
// ============================================================

function h(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

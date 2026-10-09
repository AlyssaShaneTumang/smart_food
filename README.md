# Smart Food Delivery Locker - ESP32 + Gmail OTP

This is a school-project prototype that combines an ESP32 smart locker with a PHP/MySQL restaurant dashboard.

## Main Flow

The prototype has **one locker**.

1. Restaurant creates an order on the website. The locker becomes **RESERVED** (unavailable), so no other order can be placed until this one is finished.
2. Website generates a random 4-digit pickup OTP and sends it to the customer's Gmail/email.
3. Rider selects **Delivery Mode** on the ESP32 and enters the Order ID. The locker opens for 5 seconds to place the food, then locks (**OCCUPIED**).
4. The rider never sees or receives the customer's OTP.
5. Customer selects **Pickup Mode** and enters Order ID + OTP. The servo opens the locker and **keeps it open**.
6. The website emails the customer a **"Received Order"** button.
7. Customer takes the food and taps **Received Order**. The order becomes CLAIMED, the ESP32 closes the servo, and the locker returns to AVAILABLE.

The dashboard also has **Cancel** (for orders still waiting for delivery) and **Close Locker** (if the customer never taps the email button).

## Project Structure

```text
smart_food_locker/
├── database/
│   └── schema.sql
├── esp32/
│   └── smart_food_locker.ino
└── web/
    ├── api/
    │   ├── deliver.php
    │   ├── locker.php
    │   └── pickup.php
    ├── assets/
    │   └── style.css
    ├── config.php
    ├── confirm.php
    ├── index.php
    ├── locker_actions.php
    └── mailer.php
```

All source files are expanded and formatted for easier reading/editing.

## 1. Laragon / MySQL Setup

1. Start **Apache** and **MySQL** in Laragon.
2. Copy the `web` folder into Laragon's `www` directory and rename it to `smart_food_locker`.
3. Open phpMyAdmin or HeidiSQL.
4. Import `database/schema.sql`.
5. Visit:
   `http://localhost/smart_food_locker/`

If you previously imported the older 3-locker version, either drop the database and import `schema.sql` again, or run `database/migrate_single_locker.sql` once to upgrade it in place.

## 2. Gmail OTP Setup

Use a dedicated Gmail account for the project/demo if possible.

1. Enable **2-Step Verification** on the Google account.
2. Create a **Google App Password** for the project.
3. Open `web/config.php`.
4. Set:

```php
const GMAIL_ADDRESS = 'yourproject@gmail.com';
const GMAIL_APP_PASSWORD = 'your-app-password';
```

Do NOT use the normal Gmail account password.

Also set the website address used by the **Received Order** button:

```php
const APP_BASE_URL = 'http://192.168.1.100/smart_food_locker';
```

Use the laptop's LAN IPv4 address, not `localhost`, and make sure the customer's phone is on the same Wi-Fi. Opening the button shows a confirm page; the locker closes when the customer taps **Received Order** there.

The App Password is only kept on the PHP server. It is never sent to the ESP32 or browser.

## 3. ESP32 Configuration

Open `esp32/smart_food_locker.ino` and change:

```cpp
const char* WIFI_SSID = "YOUR_WIFI";
const char* WIFI_PASSWORD = "YOUR_PASSWORD";
const char* BASE_URL = "http://YOUR_LAPTOP_IP/smart_food_locker/api";
const char* API_KEY = "CHANGE-ME-LOCKER-KEY";
```

`API_KEY` must match `DEVICE_API_KEY` in `web/config.php`.

Do not use `localhost` in the ESP32 BASE_URL. Use the laptop's LAN IPv4 address, for example `192.168.1.100`.

## 4. ESP32 Libraries

Install these libraries in Arduino IDE:

- LiquidCrystal I2C
- Keypad
- ESP32Servo
- ArduinoJson

`WiFi`, `HTTPClient`, and `Wire` come with the ESP32 Arduino core.

## 5. Prototype Wiring Used by the Code

### 20x4 I2C LCD
- SDA -> GPIO 21
- SCL -> GPIO 22
- VCC -> appropriate module supply
- GND -> GND

### 4x4 Keypad
Rows:
- R1 -> GPIO 13
- R2 -> GPIO 12
- R3 -> GPIO 14
- R4 -> GPIO 27

Columns:
- C1 -> GPIO 26
- C2 -> GPIO 25
- C3 -> GPIO 33
- C4 -> GPIO 32

### Servo Lock
- Servo signal -> GPIO 18

Use an external 5V supply for the servo. Connect the external supply GND and ESP32 GND together. Do not power the servo directly from the ESP32 3.3V pin.

While the locker is open for pickup, the ESP32 asks `api/locker.php` every 2 seconds whether the door should close. If the ESP32 restarts during a pickup, it reopens and keeps waiting.

## Security Notes

- OTP is stored in MySQL only as a password hash.
- The dashboard does not display the OTP after generation.
- The rider-facing ESP32 delivery request does not receive the OTP.
- Resend OTP generates a **new** OTP and invalidates the previous one.
- The "Received Order" link uses a one-time random token; only its SHA-256 hash is stored.
- Gmail uses an App Password instead of the normal Gmail password.
- For a real commercial system, add HTTPS, user authentication, CSRF protection, OTP expiration, attempt limits, door sensors, and server-side authorization roles.

## Important Prototype Note

This version is designed for a classroom/local-network demonstration. Gmail SMTP requires the laptop/server to have internet access even if the ESP32 and laptop communicate over the local Wi-Fi network.

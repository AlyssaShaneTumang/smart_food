#include <WiFi.h>
#include <HTTPClient.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <Keypad.h>
#include <ESP32Servo.h>
#include <ArduinoJson.h>

// ============================================================
// Wi-Fi and Web API Configuration
// ============================================================
const char* WIFI_SSID = "YOUR_WIFI";
const char* WIFI_PASSWORD = "YOUR_PASSWORD";

// Replace 192.168.1.100 with your laptop's LAN IPv4 address.
const char* BASE_URL =
    "http://192.168.1.100/smart_food_locker/api";

const char* API_KEY = "CHANGE-ME-LOCKER-KEY";

// ============================================================
// LCD Configuration
// ============================================================
LiquidCrystal_I2C lcd(0x27, 20, 4);

// ============================================================
// Keypad Configuration
// ============================================================
const byte ROWS = 4;
const byte COLS = 4;

char keys[ROWS][COLS] = {
    {'1', '2', '3', 'A'},
    {'4', '5', '6', 'B'},
    {'7', '8', '9', 'C'},
    {'*', '0', '#', 'D'}
};

byte rowPins[ROWS] = {13, 12, 14, 27};
byte colPins[COLS] = {26, 25, 33, 32};

Keypad keypad = Keypad(
    makeKeymap(keys),
    rowPins,
    colPins,
    ROWS,
    COLS
);

// ============================================================
// Locker Servo Configuration (single locker)
// ============================================================
Servo lock;

const int SERVO_PIN = 18;
const int LOCKED_POSITION = 0;
const int OPEN_POSITION = 90;

// How often to ask the server if "Received Order" was tapped.
const unsigned long POLL_INTERVAL_MS = 2000;

// ============================================================
// Read Numeric Input from Keypad
// # = Confirm
// * = Clear
// ============================================================
String readInput(const String& title, bool secret = false)
{
    String value = "";

    lcd.clear();
    lcd.print(title);
    lcd.setCursor(0, 1);
    lcd.print("#=OK  *=Clear");

    while (true) {
        char key = keypad.getKey();

        if (!key) {
            continue;
        }

        if (key == '#' && value.length() > 0) {
            return value;
        }

        if (key == '*') {
            value = "";
            lcd.setCursor(0, 2);
            lcd.print("                    ");
            continue;
        }

        if (key >= '0' && key <= '9' && value.length() < 16) {
            value += key;

            lcd.setCursor(0, 2);

            for (int i = 0; i < value.length(); i++) {
                lcd.print(secret ? '*' : value[i]);
            }
        }
    }
}

// ============================================================
// Send POST Request to PHP API
// ============================================================
String postRequest(const String& endpoint, const String& body)
{
    if (WiFi.status() != WL_CONNECTED) {
        return "";
    }

    HTTPClient http;

    http.begin(String(BASE_URL) + endpoint);
    http.addHeader(
        "Content-Type",
        "application/x-www-form-urlencoded"
    );
    http.addHeader("X-API-Key", API_KEY);

    // pickup.php sends the "Received Order" Gmail before replying.
    http.setTimeout(20000);

    int httpCode = http.POST(body);
    String response = http.getString();

    http.end();

    if (httpCode > 0) {
        return response;
    }

    return "";
}

// ============================================================
// Open the Locker Temporarily (rider places the food)
// ============================================================
void openLockerTemporarily()
{
    lock.write(OPEN_POSITION);

    lcd.clear();
    lcd.print("Locker OPEN");
    lcd.setCursor(0, 1);
    lcd.print("Place the food");

    delay(5000);

    lock.write(LOCKED_POSITION);
}

// ============================================================
// Ask the server whether the door should be OPEN or CLOSED.
// Returns "" if the server cannot be reached.
// ============================================================
String fetchDoorState()
{
    String response = postRequest("/locker.php", "");

    DynamicJsonDocument json(256);
    DeserializationError error = deserializeJson(json, response);

    if (error || !json["ok"].as<bool>()) {
        return "";
    }

    return json["door"].as<String>();
}

// ============================================================
// Keep the Locker Open Until the Customer Taps
// "Received Order" in their Gmail.
// ============================================================
void holdOpenUntilReceived()
{
    lock.write(OPEN_POSITION);

    lcd.clear();
    lcd.print("Locker OPEN");
    lcd.setCursor(0, 1);
    lcd.print("Take your food, then");
    lcd.setCursor(0, 2);
    lcd.print("tap Received Order");
    lcd.setCursor(0, 3);
    lcd.print("in your Gmail");

    while (true) {
        delay(POLL_INTERVAL_MS);

        // Keep waiting on network errors so the door never
        // closes on the customer by mistake.
        if (fetchDoorState() == "CLOSED") {
            break;
        }
    }

    lock.write(LOCKED_POSITION);
}

// ============================================================
// DELIVERY MODE
// Rider only knows the Order ID.
// Rider never receives the customer's PIN.
// ============================================================
void deliveryMode()
{
    String orderId = readInput("DELIVERY: Order ID");

    lcd.clear();
    lcd.print("Checking...");

    String response = postRequest(
        "/deliver.php",
        "order_id=" + orderId
    );

    DynamicJsonDocument json(512);
    DeserializationError error = deserializeJson(json, response);

    if (error || !json["ok"].as<bool>()) {
        lcd.clear();
        lcd.print("Delivery denied");
        lcd.setCursor(0, 1);
        lcd.print(json["error"] | "Server error");
        delay(2500);
        return;
    }

    openLockerTemporarily();

    lcd.clear();
    lcd.print("Food stored");
    lcd.setCursor(0, 1);
    lcd.print("Order " + orderId);
    delay(2000);
}

// ============================================================
// PICKUP MODE
// Customer enters Order ID + private PIN.
// The locker opens and stays open until the customer taps
// "Received Order" in the email sent by the server.
// ============================================================
void pickupMode()
{
    String orderId = readInput("PICKUP: Order ID");
    String pin = readInput("Enter PIN", true);

    lcd.clear();
    lcd.print("Verifying...");

    String response = postRequest(
        "/pickup.php",
        "order_id=" + orderId + "&pin=" + pin
    );

    DynamicJsonDocument json(512);
    DeserializationError error = deserializeJson(json, response);

    if (error || !json["ok"].as<bool>()) {
        lcd.clear();
        lcd.print("ACCESS DENIED");
        lcd.setCursor(0, 1);
        lcd.print("Check ID / PIN");
        delay(2500);
        return;
    }

    holdOpenUntilReceived();

    lcd.clear();
    lcd.print("Pickup complete");
    lcd.setCursor(0, 1);
    lcd.print("Thank you!");
    delay(2000);
}

// ============================================================
// Setup
// ============================================================
void setup()
{
    Serial.begin(115200);

    lcd.init();
    lcd.backlight();

    lock.attach(SERVO_PIN);
    lock.write(LOCKED_POSITION);

    lcd.print("Connecting WiFi");

    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

    int attempts = 0;

    while (
        WiFi.status() != WL_CONNECTED &&
        attempts++ < 30
    ) {
        delay(500);
        lcd.print(".");
    }

    lcd.clear();

    if (WiFi.status() == WL_CONNECTED) {
        lcd.print("Locker Online");
    } else {
        lcd.print("WiFi Failed");
    }

    delay(1500);

    // If the ESP32 restarted while a customer was picking up,
    // reopen and keep waiting for "Received Order".
    if (fetchDoorState() == "OPEN") {
        holdOpenUntilReceived();
    }
}

// ============================================================
// Main Menu
// A = Delivery Mode
// B = Pickup Mode
// ============================================================
void loop()
{
    lcd.clear();
    lcd.print("A: DELIVERY");
    lcd.setCursor(0, 1);
    lcd.print("B: PICKUP");
    lcd.setCursor(0, 3);
    lcd.print("Select mode...");

    char key = 0;

    while (key != 'A' && key != 'B') {
        key = keypad.getKey();
    }

    if (key == 'A') {
        deliveryMode();
    } else {
        pickupMode();
    }
}

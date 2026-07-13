#include <WiFi.h>
#include <HTTPClient.h>
#include <HardwareSerial.h>
#include <Wire.h>
#include <LiquidCrystal_PCF8574.h>

// ======== PINES DISPLAY I2C (EDITABLES) ========
static const int DISPLAY_SDA_PIN = 32;
static const int DISPLAY_SCL_PIN = 33;

// ======== WIFI ========
const char* ssid = "jardin";
const char* password = "Luna2014";

// ======== ENDPOINT FINAL ========
const char* endpoint = "http://hacceso.hacedores.com/api/qr.php";
const char* messagingEndpoint = "http://hacceso.hacedores.com/api/messaging_alert.php";
const char* monitorCommandsEndpoint = "http://hacceso.hacedores.com/api/device/monitor_commands.php";
const char* apiKey = "4YtYUPP1bh_4ZUAJtT1GB9TTOGkPwzvVsvnZPAa0LrI";
const char* deviceId = "recepcion-01";

// ======== UART SCANNER ========
HardwareSerial QRSerial(2);
static const int QR_RX_PIN = 13; // ESP32 RX <- TX scanner
static const int QR_TX_PIN = 14; // ESP32 TX -> RX scanner
static const int MESSAGING_BUTTON_PIN = 27; // Boton entre GPIO27 y GND

// ======== DISPLAY LCD 16x2 I2C ========
static const uint8_t LCD_I2C_ADDRESS = 0x27;
static const uint8_t LCD_COLS = 16;
static const uint8_t LCD_ROWS = 2;
LiquidCrystal_PCF8574 lcd(LCD_I2C_ADDRESS);

const unsigned long resultDisplayMs = 60000; // mantener resultado 60s
const unsigned long messagingDisplayMs = 30000;
const unsigned long descendingDisplayMs = 30000;
unsigned long lastDisplayEventAt = 0;
unsigned long displayHoldDurationMs = resultDisplayMs;
bool isShowingResult = false;

String qrBuffer = "";
String lastQR = "";
unsigned long lastQRTime = 0;
const unsigned long dedupWindowMs = 5000;   // evita doble lectura inmediata
const unsigned long frameTimeoutMs = 120;   // para scanners sin \n/\r
const unsigned long buttonDebounceMs = 50;
const unsigned long messagingCooldownMs = 30000;
bool lastButtonReading = HIGH;
bool stableButtonState = HIGH;
unsigned long lastButtonChangeAt = 0;
unsigned long lastMessagingAlertAt = 0;
bool messagingAlertPending = false;
String lastMonitorCommandId = "";
unsigned long lastMonitorPollAt = 0;
const unsigned long monitorPollIntervalMs = 1000;

String trimToLCD(const String& text) {
  String out = text;
  out.trim();
  if (out.length() > LCD_COLS) out = out.substring(0, LCD_COLS);
  while (out.length() < LCD_COLS) out += " ";
  return out;
}

void lcdPrint2Lines(
  const String& line1,
  const String& line2,
  bool markAsResult = false,
  unsigned long holdDurationMs = resultDisplayMs
) {
  lcd.clear();
  lcd.setCursor(0, 0);
  lcd.print(trimToLCD(line1));
  lcd.setCursor(0, 1);
  lcd.print(trimToLCD(line2));
  if (markAsResult) {
    isShowingResult = true;
    lastDisplayEventAt = millis();
    displayHoldDurationMs = holdDurationMs;
  }
}

String extractJsonString(const String& json, const String& key) {
  String token = "\"" + key + "\"";
  int keyPos = json.indexOf(token);
  if (keyPos < 0) return "";
  int colonPos = json.indexOf(':', keyPos + token.length());
  if (colonPos < 0) return "";
  int firstQuote = json.indexOf('"', colonPos + 1);
  if (firstQuote < 0) return "";

  int i = firstQuote + 1;
  String value = "";
  bool escaped = false;
  while (i < (int)json.length()) {
    char c = json[i++];
    if (escaped) {
      value += c;
      escaped = false;
    } else if (c == '\\') {
      escaped = true;
    } else if (c == '"') {
      break;
    } else {
      value += c;
    }
  }
  return value;
}

void showIdleDisplay() {
  lcdPrint2Lines("Hacceso Activo", "Esperando QR");
  isShowingResult = false;
}

void showMessagingAlertOnLCD() {
  lcdPrint2Lines("Avisando", "Mensajeria", true, messagingDisplayMs);
}

void showApiResultOnLCD(const String& responseBody) {
  String result = extractJsonString(responseBody, "result");
  String visitorName = extractJsonString(responseBody, "visitor_name");

  if (result == "OK_FIRST") {
    lcdPrint2Lines("ACCESO PERMITIDO", visitorName == "" ? "Bienvenido" : visitorName, true);
  } else if (result == "OK_REDISPLAY") {
    lcdPrint2Lines("ACCESO REINGRESO", visitorName == "" ? "OK (60 seg)" : visitorName, true);
  } else if (result == "INEXISTENT") {
    lcdPrint2Lines("CODIGO INVALIDO", "No registrado", true);
  } else if (result == "EXPIRED") {
    lcdPrint2Lines("CODIGO VENCIDO", "No permitido", true);
  } else if (result == "USED") {
    lcdPrint2Lines("CODIGO YA USADO", "No permitido", true);
  } else if (result == "REVOKED") {
    lcdPrint2Lines("CODIGO REVOCADO", "No permitido", true);
  } else {
    lcdPrint2Lines("RESPUESTA API", "No reconocida", true);
  }
}

void logMsg(const String& msg) {
  Serial.print("[");
  Serial.print(millis());
  Serial.print(" ms] ");
  Serial.println(msg);
}

bool connectWiFi(uint32_t timeoutMs = 20000) {
  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid, password);
  lcdPrint2Lines("Conectando WiFi", "...");

  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && (millis() - start) < timeoutMs) {
    delay(500);
    Serial.print(".");
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    logMsg("WiFi conectado");
    Serial.print("IP: "); Serial.println(WiFi.localIP());
    lcdPrint2Lines("WiFi conectado", WiFi.localIP().toString());
    delay(1200);
    showIdleDisplay();
    return true;
  }

  logMsg("Error: no conecto WiFi");
  lcdPrint2Lines("WiFi sin enlace", "Reintentando...");
  return false;
}

bool isQRValid(const String& s) {
  if (s.length() < 3 || s.length() > 300) return false;

  for (size_t i = 0; i < s.length(); i++) {
    char c = s[i];
    if ((unsigned char)c < 32 && c != '\t') return false;
  }
  return true;
}

bool sendQR(const String& qrText) {
  if (WiFi.status() != WL_CONNECTED) {
    if (!connectWiFi(10000)) return false;
  }

  HTTPClient http;
  http.begin(endpoint);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("User-Agent", "ESP32-QR-Client/1.0");
  http.addHeader("X-API-Key", apiKey);
  http.setConnectTimeout(8000);
  http.setTimeout(8000);

  String safe = qrText;
  safe.replace("\\", "\\\\");
  safe.replace("\"", "\\\"");

  String safeDevice = String(deviceId);
  safeDevice.replace("\\", "\\\\");
  safeDevice.replace("\"", "\\\"");

  String safeApiKey = String(apiKey);
  safeApiKey.replace("\\", "\\\\");
  safeApiKey.replace("\"", "\\\"");

  String body = "{\"qr\":\"" + safe + "\",\"device_id\":\"" + safeDevice + "\",\"device\":\"" + safeDevice + "\",\"api_key\":\"" + safeApiKey + "\"}";
  int code = http.POST(body);

  bool ok = false;
  if (code >= 200 && code < 300) {
    Serial.print("OK HTTP "); Serial.println(code);
    String response = http.getString();
    Serial.println(response);
    showApiResultOnLCD(response);
    ok = true;
  } else if (code > 0) {
    Serial.print("ERROR HTTP "); Serial.println(code);
    String response = http.getString();
    Serial.println(response);
    lcdPrint2Lines("Error servidor", "HTTP " + String(code), true);
  } else {
    Serial.print("ERROR transporte: ");
    Serial.println(http.errorToString(code));
    lcdPrint2Lines("Sin conexion API", "Revise internet", true);
  }

  http.end();
  return ok;
}

void showDescendingAlertOnLCD() {
  lcdPrint2Lines("Vamos bajando", "", true, descendingDisplayMs);
}

bool sendMessagingAlert() {
  if (WiFi.status() != WL_CONNECTED) {
    if (!connectWiFi(10000)) {
      showMessagingAlertOnLCD();
      return false;
    }
  }

  HTTPClient http;
  http.begin(messagingEndpoint);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("User-Agent", "ESP32-QR-Client/1.0");
  http.addHeader("X-API-Key", apiKey);
  http.setConnectTimeout(8000);
  http.setTimeout(8000);

  String safeDevice = String(deviceId);
  safeDevice.replace("\\", "\\\\");
  safeDevice.replace("\"", "\\\"");

  String body = "{\"device_id\":\"" + safeDevice + "\"}";
  int code = http.POST(body);
  bool ok = code >= 200 && code < 300;

  if (ok) {
    Serial.println("Alerta de Mensajeria enviada");
  } else {
    Serial.print("Error alerta Mensajeria HTTP ");
    Serial.println(code);
  }

  http.end();
  return ok;
}

void pollMessagingButton() {
  const unsigned long now = millis();
  const bool reading = digitalRead(MESSAGING_BUTTON_PIN);

  if (reading != lastButtonReading) {
    lastButtonChangeAt = now;
    lastButtonReading = reading;
  }

  if ((now - lastButtonChangeAt) < buttonDebounceMs || reading == stableButtonState) {
    return;
  }

  stableButtonState = reading;
  if (stableButtonState != LOW) {
    return;
  }

  if (lastMessagingAlertAt != 0 && (now - lastMessagingAlertAt) < messagingCooldownMs) {
    return;
  }

  lastMessagingAlertAt = now;
  showMessagingAlertOnLCD();
  messagingAlertPending = true;
}

void serviceMessagingAlert() {
  if (!messagingAlertPending) {
    return;
  }

  messagingAlertPending = false;
  sendMessagingAlert();
}

void pollMonitorCommands() {
  const unsigned long now = millis();
  if ((now - lastMonitorPollAt) < monitorPollIntervalMs) {
    return;
  }
  lastMonitorPollAt = now;

  if (WiFi.status() != WL_CONNECTED) {
    return;
  }

  String url = String(monitorCommandsEndpoint) + "?device_id=" + String(deviceId);
  if (lastMonitorCommandId.length() > 0) {
    url += "&after_id=" + lastMonitorCommandId;
  }

  HTTPClient http;
  http.begin(url);
  http.addHeader("User-Agent", "ESP32-QR-Client/1.0");
  http.addHeader("X-API-Key", apiKey);
  http.setConnectTimeout(3000);
  http.setTimeout(3000);

  int code = http.GET();
  if (code >= 200 && code < 300) {
    String response = http.getString();
    String alertId = extractJsonString(response, "alert_id");
    if (alertId.length() > 0 && alertId != lastMonitorCommandId) {
      lastMonitorCommandId = alertId;
      String alertType = extractJsonString(response, "alert_type");
      if (alertType == "DESCENDING") {
        showDescendingAlertOnLCD();
        logMsg("Orden recibida: Vamos bajando");
      }
    }
  } else if (code > 0) {
    Serial.print("Error consulta comandos HTTP ");
    Serial.println(code);
  }

  http.end();
}

void processQR(const String& qrText) {
  if (!isQRValid(qrText)) {
    logMsg("QR invalido");
    return;
  }

  unsigned long now = millis();
  if (qrText == lastQR && (now - lastQRTime) < dedupWindowMs) {
    logMsg("QR duplicado ignorado");
    return;
  }

  lastQR = qrText;
  lastQRTime = now;

  Serial.print("QR: ");
  Serial.println(qrText);

  bool sent = sendQR(qrText);
  if (sent) logMsg("QR enviado");
  else logMsg("Fallo al enviar QR");
}

void setup() {
  Serial.begin(115200);
  delay(700);
  Wire.begin(DISPLAY_SDA_PIN, DISPLAY_SCL_PIN);
  lcd.begin(LCD_COLS, LCD_ROWS);
  lcd.setBacklight(255);
  lcdPrint2Lines("Hacceso", "Iniciando...");

  Serial.println("\n=== ESP32 QR PRODUCCION ===");
  Serial.print("Endpoint: "); Serial.println(endpoint);
  Serial.print("Device ID: "); Serial.println(deviceId);
  Serial.print("API key configurada: "); Serial.println(strlen(apiKey) > 0 ? "si" : "no");
  QRSerial.begin(115200, SERIAL_8N1, QR_RX_PIN, QR_TX_PIN); // si falla prueba 9600 o 57600
  Serial.println("UART QR lista: RX GPIO13, TX GPIO14, 115200 baud");
  pinMode(MESSAGING_BUTTON_PIN, INPUT_PULLUP);
  connectWiFi();
}

void loop() {
  static unsigned long lastByteAt = 0;

  while (QRSerial.available()) {
    char c = (char)QRSerial.read();
    lastByteAt = millis();

    if (c == '\n' || c == '\r') {
      if (qrBuffer.length() > 0) {
        Serial.print("QR recibido por UART, longitud: ");
        Serial.println(qrBuffer.length());
        processQR(qrBuffer);
        qrBuffer = "";
      }
    } else {
      qrBuffer += c;
      if (qrBuffer.length() > 512) {
        qrBuffer = "";
        logMsg("Buffer limpiado por longitud");
      }
    }
  }

  // Para scanners que no envian \n/\r
  if (qrBuffer.length() > 0 && (millis() - lastByteAt) > frameTimeoutMs) {
    Serial.print("QR recibido por UART sin terminador, longitud: ");
    Serial.println(qrBuffer.length());
    processQR(qrBuffer);
    qrBuffer = "";
  }

  pollMessagingButton();
  serviceMessagingAlert();
  pollMonitorCommands();

  if (isShowingResult && (millis() - lastDisplayEventAt) >= displayHoldDurationMs) {
    showIdleDisplay();
  }
}

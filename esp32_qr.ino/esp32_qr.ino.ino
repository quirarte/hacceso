#include <WiFi.h>
#include <HTTPClient.h>
#include <HardwareSerial.h>

// ======== WIFI ========
const char* ssid = "jardin";
const char* password = "Luna2014";

// ======== ENDPOINT FINAL ========
const char* endpoint = "http://hacceso.hacedores.com/api/qr.php";

// ======== UART SCANNER ========
HardwareSerial QRSerial(2);
static const int QR_RX_PIN = 13; // ESP32 RX <- TX scanner
static const int QR_TX_PIN = 14; // ESP32 TX -> RX scanner

String qrBuffer = "";
String lastQR = "";
unsigned long lastQRTime = 0;
const unsigned long dedupWindowMs = 3000;   // evita doble lectura inmediata
const unsigned long frameTimeoutMs = 120;   // para scanners sin \n/\r

void logMsg(const String& msg) {
  Serial.print("[");
  Serial.print(millis());
  Serial.print(" ms] ");
  Serial.println(msg);
}

bool connectWiFi(uint32_t timeoutMs = 20000) {
  WiFi.mode(WIFI_STA);
  WiFi.begin(ssid, password);

  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && (millis() - start) < timeoutMs) {
    delay(500);
    Serial.print(".");
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    logMsg("WiFi conectado");
    Serial.print("IP: "); Serial.println(WiFi.localIP());
    return true;
  }

  logMsg("Error: no conecto WiFi");
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
  http.setConnectTimeout(8000);
  http.setTimeout(8000);

  String safe = qrText;
  safe.replace("\\", "\\\\");
  safe.replace("\"", "\\\"");

  String body = "{\"qr\":\"" + safe + "\",\"device\":\"esp32-devkitc-v4\"}";
  int code = http.POST(body);

  bool ok = false;
  if (code >= 200 && code < 300) {
    Serial.print("OK HTTP "); Serial.println(code);
    String response = http.getString();
    Serial.println(response);
    ok = true;
  } else if (code > 0) {
    Serial.print("ERROR HTTP "); Serial.println(code);
    Serial.println(http.getString());
  } else {
    Serial.print("ERROR transporte: ");
    Serial.println(http.errorToString(code));
  }

  http.end();
  return ok;
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

  Serial.println("\n=== ESP32 QR PRODUCCION ===");
  QRSerial.begin(115200, SERIAL_8N1, QR_RX_PIN, QR_TX_PIN); // si falla prueba 9600 o 57600
  connectWiFi();
}

void loop() {
  static unsigned long lastByteAt = 0;

  while (QRSerial.available()) {
    char c = (char)QRSerial.read();
    lastByteAt = millis();

    if (c == '\n' || c == '\r') {
      if (qrBuffer.length() > 0) {
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
    processQR(qrBuffer);
    qrBuffer = "";
  }
}
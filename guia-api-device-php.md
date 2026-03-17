# Bloque técnico — API dispositivo (PHP simple)

## 1) Estructura creada

```text
api/
    device/
      health.php
      validate.php
src/
  bootstrap.php
  config.php
```

## 2) Variables de entorno mínimas

```bash
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_NAME=tu_bd
export DB_USER=tu_usuario
export DB_PASS=tu_password
export APP_TIMEZONE=America/Mexico_City
export DB_TIMEZONE_SQL="SET time_zone = '-06:00'"
```

## 3) Pruebas con cURL

### Health

```bash
curl -i https://hacceso.hacedores.com/api/device/health.php
```

### Validate (ejemplo)

```bash
curl -i -X POST https://hacceso.hacedores.com/api/device/validate.php \
  -H 'Content-Type: application/json' \
  -H 'X-API-Key: TU_API_KEY' \
  -d '{"device_id":"recepcion-01","code_id":"ABC123"}'
```

## 4) Prueba rápida en Postman

1. Crear request `POST` a `/api/device/validate.php`.
2. Header `Content-Type: application/json`.
3. Header `X-API-Key: <tu llave en claro>`.
4. Body raw JSON:

```json
{
  "device_id": "recepcion-01",
  "code_id": "ABC123"
}
```

## 5) Integración inicial ESP32 (Arduino)

```cpp
#include <WiFi.h>
#include <HTTPClient.h>

const char* WIFI_SSID = "...";
const char* WIFI_PASS = "...";
const char* API_URL = "https://hacceso.hacedores.com/api/device/validate.php";
const char* DEVICE_ID = "recepcion-01";
const char* API_KEY = "TU_API_KEY";

void validarCodigo(const String& codeId) {
  if (WiFi.status() != WL_CONNECTED) return;

  HTTPClient http;
  http.begin(API_URL);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", API_KEY);

  String body = "{\"device_id\":\"" + String(DEVICE_ID) + "\",\"code_id\":\"" + codeId + "\"}";
  int status = http.POST(body);

  if (status > 0) {
    String response = http.getString();
    Serial.println(response); // parsear JSON para mostrar OK/NO OK en pantalla
  }

  http.end();
}
```

## 6) Nota de concurrencia

`validate.php` usa transacción MySQL y `SELECT ... FOR UPDATE` sobre `invites`, para asegurar transición atómica de `ACTIVE` a `USED`.

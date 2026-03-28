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


## 1.1 Soporte de rutas sin `.php`

Se agregó `api/device/.htaccess` para mapear:

- `/api/device/health` -> `health.php`
- `/api/device/validate` -> `validate.php`

Así el ESP32 puede consumir rutas limpias sin extensión.

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

## 3) ¿De dónde obtengo el `API_KEY`? (sin línea de comandos)

Si no tienes acceso SSH/terminal, puedes generarla desde navegador con un archivo PHP temporal en `public_html`.

### 3.1 Crear archivo temporal `gen_device_key.php`

Crea este archivo en la raíz pública del hosting y ábrelo en el navegador.

```php
<?php

declare(strict_types=1);

$key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$hash = password_hash($key, PASSWORD_BCRYPT);

header('Content-Type: text/plain; charset=utf-8');
echo "API_KEY (guardar en ESP32)" . PHP_EOL;
echo $key . PHP_EOL . PHP_EOL;
echo "API_KEY_HASH (guardar en devices.api_key_hash)" . PHP_EOL;
echo $hash . PHP_EOL;
```

URL ejemplo:
- `https://hacceso.hacedores.com/gen_device_key.php`

### 3.2 Qué hacer con el resultado

1. Copia `API_KEY` y guárdala en el firmware ESP32 para enviarla en header `X-API-Key`.
2. Copia `API_KEY_HASH` y guárdala en MySQL (`devices.api_key_hash`).

SQL ejemplo:

```sql
INSERT INTO devices (device_id, api_key_hash, label, is_enabled)
VALUES ('recepcion-01', 'AQUI_HASH_BCRYPT', 'Recepción principal', 1)
ON DUPLICATE KEY UPDATE
  api_key_hash = VALUES(api_key_hash),
  label = VALUES(label),
  is_enabled = VALUES(is_enabled);
```

### 3.3 Seguridad (obligatorio)

Después de generar/copiar la llave:
1. Elimina `gen_device_key.php` del servidor.
2. No guardes la `API_KEY` en texto plano dentro de la base de datos.
3. Si la llave se expone, genera una nueva y actualiza `devices.api_key_hash`.

> Nota: `validate.php` valida con `password_verify(X-API-Key, api_key_hash)`.

## 4) Pruebas con cURL

### Health

```bash
curl -i https://hacceso.hacedores.com/api/device/health
```

### Validate (ejemplo)

```bash
curl -i -X POST https://hacceso.hacedores.com/api/device/validate \
  -H 'Content-Type: application/json' \
  -H 'X-API-Key: TU_API_KEY' \
  -d '{"device_id":"recepcion-01","code_id":"ABC123"}'
```

## 5) Prueba rápida en Postman

1. Crear request `POST` a `/api/device/validate`.
2. Header `Content-Type: application/json`.
3. Header `X-API-Key: <tu llave en claro>`.
4. Body raw JSON:

```json
{
  "device_id": "recepcion-01",
  "code_id": "ABC123"
}
```

## 6) Integración inicial ESP32 (Arduino)

```cpp
#include <WiFi.h>
#include <HTTPClient.h>

const char* WIFI_SSID = "...";
const char* WIFI_PASS = "...";
const char* API_URL = "https://hacceso.hacedores.com/api/device/validate";
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

## 7) Nota de concurrencia

`validate.php` usa transacción MySQL y `SELECT ... FOR UPDATE` sobre `invites`, para asegurar transición atómica de `ACTIVE` a `USED`.


## 8) Siguiente paso implementado: emitir pases (admin)

Se agregó endpoint para crear pases desde panel/admin:

- `POST /api/admin/invites_create.php`
- Header requerido: `X-Issuer-Key`

Body JSON:

```json
{
  "visitor_name": "Ada Lovelace",
  "valid_from": "2026-03-28T10:00:00-06:00",
  "valid_to": "2026-03-28T18:00:00-06:00",
  "companions_expected": 1,
  "visitor_phone": "+5215512345678",
  "visitor_email": "ada@example.com"
}
```

Respuesta exitosa (`201`):

```json
{
  "id": "uuid",
  "code_id": "token_qr",
  "status": "ACTIVE",
  "visitor_name": "Ada Lovelace",
  "companions_expected": 1,
  "valid_from": "2026-03-28T16:00:00+00:00",
  "valid_to": "2026-03-29T00:00:00+00:00",
  "issued_by_employee_uid": "emp-uid",
  "issued_at": "2026-03-28T15:43:00+00:00"
}
```

Notas:
- El endpoint valida `X-Issuer-Key` contra hashes `bcrypt` en `issuer_keys.api_key_hash`.
- Genera `code_id` aleatorio seguro (base64url) para el QR.
- Inserta el pase con `status = ACTIVE`, `issued_at`, `created_at` y `updated_at` desde servidor.

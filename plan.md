# plan.md — Sistema de Pases de Acceso con QR (Hacedores)

[No verificado] Este documento consolida definiciones, requerimientos y especificaciones acordadas. No puedo verificar inventario de hardware, comportamiento exacto del buzzer del lector en tu unidad, ni tu infraestructura de red o despliegue real en Supabase y Vercel sin pruebas en sitio.

## 1. Objetivo

Diseñar, construir e implementar un sistema de control de acceso para el edificio del makerspace, basado en pases temporales emitidos por Hacedores, entregados al visitante como código QR y validados en recepción mediante un dispositivo físico conectado a Internet.

## 2. Actores

- **Emisor (staff Hacedores)**: crea y emite pases desde el panel.
- **Visitante**: recibe el QR y lo presenta en recepción.
- **Vigilante/recepción**: observa el display trasero del dispositivo y autoriza el acceso.
- **Equipo piso 8**: monitorea en tiempo real quién está por llegar tras un escaneo aprobado.
- **Dispositivo**: escanea QR, consulta servidor, muestra resultado al vigilante.
- **Servidor (Supabase)**: almacena pases y eventos, aplica reglas, registra uso.
- **Base de datos de empleados (Hacedores)**: fuente de verdad para datos del empleado, identificada por UID.

## 3. Requerimientos funcionales

### 3.1 Emisión de pases (panel web)
El sistema debe permitir que el emisor capture o produzca los siguientes datos:

1) **Nombre del visitante autorizado**  
2) **Rango de vigencia**: fecha y hora de inicio (`valid_from`) y fecha y hora de fin (`valid_to`)  
3) **Acompañantes esperados**: número de acompañantes que vienen con el visitante (informativo)  
4) **UID del empleado emisor**: se registra el UID, el nombre se obtiene por cruce con la base de empleados  
5) **Timestamp de emisión** (`issued_at`) generado por servidor  
6) **Teléfono del visitante** (opcional)  
7) **Email del visitante** (opcional)

Al emitir el pase:
- El sistema genera un **código QR**.
- El QR contiene únicamente un **token aleatorio** `code_id` (no contiene nombre ni vigencias).
- El sistema genera una **imagen del QR** para enviar al visitante, ver sección 9.4.

### 3.2 Envío del QR al visitante
- El emisor envía la imagen del QR por WhatsApp o email (manual).
- Automatización de envío se considera opcional para una etapa posterior.

### 3.3 Validación en recepción (dispositivo)
Flujo:
1) El visitante inserta el celular con el QR visible en un **dock oscuro** del dispositivo.
2) El dispositivo **lee** el QR mediante un lector 2D conectado por UART.
3) El dispositivo consulta la nube para validar el `code_id`.
4) El dispositivo despliega en el **display trasero**:
   - Si OK: nombre del visitante y acompañantes esperados
   - Si error: mensaje específico según el caso
5) El servidor registra el evento y marca el pase como usado según reglas.

### 3.4 Mensajes de error (display trasero)
- **"código inexistente"** si no existe el `code_id`
- **"código vencido"** si está fuera de vigencia
- **"código ya utilizado"** si ya pasó la ventana de redisplay
- **"código revocado"** si fue revocado (si se implementa)

### 3.5 Ventana de reescaneo (5 minutos desde el primer OK)
Regla final:
- Tras el **primer OK** (`OK_FIRST`), el pase cambia a estado **USED**.
- El mismo código puede escanearse varias veces dentro de una ventana de **5 minutos contados desde el primer OK**.
- Dentro de esa ventana, el vigilante debe seguir viendo un resultado OK (`OK_REDISPLAY`).
- Pasados los 5 minutos, el resultado debe ser **"código ya utilizado"**.

### 3.6 Privacidad del visitante y retroalimentación frontal
- El visitante **no debe ver información del pase**.
- El display para el vigilante está en la parte posterior del dispositivo.
- En el frente, el visitante solo recibe:
  - **1 LED verde**
  - **1 beep**

Regla final:
- El beep y el LED deben ser **exactamente iguales** para todos los resultados.
- Se activan **solo cuando hay lectura exitosa del QR** (cuando el lector entrega un `code_id`).

## 4. Requerimientos del display trasero

### 4.1 Estado por defecto (reposo)
En reposo, el display trasero muestra:
- **"Activo"** si el dispositivo está conectado y las pruebas de conectividad y funcionamiento son exitosas.
- **"Inactivo"** si las pruebas fallan repetidamente.

### 4.2 Estado de resultado del pase
- Tras un escaneo, el display muestra el resultado por máximo **60 segundos**.
- Si se escanea otro código antes de cumplir el minuto, el display debe **cambiar inmediatamente** al nuevo resultado.
- Si se cumple el minuto sin escaneos, vuelve a reposo ("Activo" o "Inactivo").

## 5. Conectividad y salud del dispositivo

### 5.1 Chequeo Activo o Inactivo
- Se usará un ping lógico por HTTPS hacia:
  - `GET /device/health`
- Criterio recomendado:
  - Intervalo: 10 s
  - Fallos consecutivos para "Inactivo": 3
  - Un éxito vuelve a "Activo"

## 6. Arquitectura del sistema

### 6.1 Componentes
- **Supabase Postgres**: base de datos principal del sistema de pases
- **Supabase Edge Functions (TypeScript)**:
  - `POST /device/validate`
  - `GET /device/health`
  - Endpoints admin para crear, listar y revocar pases (ver sección 8.3)
- **Panel web admin**: hospedado en **Vercel**, desarrollado en **TypeScript** (Next.js o Vite)
- **Página de monitoreo en tiempo real**: parte del panel, ver sección 9.5
- **Dispositivo**: ESP32 + lector QR por UART + LCD 1602 I2C + LED frontal
- **Base de empleados**:
  - Tabla o fuente `employees` con UID como llave primaria
  - El sistema de pases guarda solo el UID y cruza para mostrar nombres

### 6.2 Seguridad y control de acceso (sin subsistema de usuarios)
Se acordó no implementar un módulo completo de usuarios dentro del sistema de pases.

En su lugar:
- **API key por emisor** asociada a un **employee_uid**.
- **API key por dispositivo** para validar pases desde el hardware.

Reglas:
- El panel envía `X-Issuer-Key` en operaciones admin.
- El servidor valida la key y obtiene el `employee_uid` emisor.
- El servidor guarda `issued_by_employee_uid` en el pase.
- El nombre del empleado se obtiene por cruce con la tabla `employees`.
- El dispositivo envía `X-API-Key` (device key) en validaciones.
- Todo por HTTPS.

## 7. Modelo de datos (Supabase Postgres)

### 7.1 Tabla `invites`
Campos:
- `id` UUID (PK)
- `code_id` TEXT UNIQUE (token aleatorio, lo que va en el QR)
- `visitor_name` TEXT
- `visitor_phone` TEXT NULL (opcional)
- `visitor_email` TEXT NULL (opcional)
- `companions_expected` INT
- `valid_from` TIMESTAMPTZ
- `valid_to` TIMESTAMPTZ

Emisión:
- `issued_by_employee_uid` UUID o TEXT (según el UID real)
- `issued_at` TIMESTAMPTZ (servidor)

Uso:
- `status` TEXT (valores: `ACTIVE`, `USED`, `REVOKED`)
- `used_at` TIMESTAMPTZ NULL
- `redisplay_until` TIMESTAMPTZ NULL (se fija como `used_at + 5 minutes`)

Notas:
- "vencido" se calcula como `now > valid_to` (no requiere campo `EXPIRED`).

### 7.2 Tabla `scan_events`
- `id` UUID (PK)
- `code_id` TEXT
- `device_id` TEXT
- `scanned_at` TIMESTAMPTZ
- `result` TEXT (valores sugeridos):
  - `OK_FIRST`
  - `OK_REDISPLAY`
  - `INEXISTENT`
  - `EXPIRED`
  - `REVOKED`
  - `USED`
Opcional:
- `latency_ms` INT
- `error_detail` TEXT

### 7.3 Tabla `devices`
- `device_id` TEXT UNIQUE
- `api_key_hash` TEXT
- `label` TEXT
- `is_enabled` BOOLEAN
- `created_at` TIMESTAMPTZ

### 7.4 Tabla `issuer_keys` (API keys por emisor)
- `issuer_key_id` UUID (PK)
- `employee_uid` UUID o TEXT (FK lógica a `employees.uid`)
- `api_key_hash` TEXT
- `is_enabled` BOOLEAN
- `created_at` TIMESTAMPTZ

### 7.5 Tabla `employees` (fuente de verdad)
Campos mínimos para cruce:
- `uid`
- `display_name` (o equivalente)
- `is_active` (opcional)

No puedo verificar esto. La estructura exacta depende de tu base de empleados.

## 8. API (Supabase Edge Functions)

### 8.1 `GET /device/health`
Propósito:
- Determinar estado "Activo" o "Inactivo" del dispositivo.

Respuesta:
- HTTP 200 con JSON simple `{ "ok": true }` si está operativo.

### 8.2 `POST /device/validate`
Entrada (JSON):
- `device_id`
- `code_id`

Headers:
- `X-API-Key`: API key del dispositivo

Salida (JSON):
- `result`: `OK_FIRST`, `OK_REDISPLAY`, `INEXISTENT`, `EXPIRED`, `REVOKED`, `USED`
- Si OK:
  - `visitor_name`
  - `companions_expected`

Lógica (atómica):
1) Si `code_id` no existe: `INEXISTENT`, registrar `scan_events`
2) Si `status == REVOKED`: `REVOKED`, registrar `scan_events`
3) Si `now < valid_from` o `now > valid_to`: `EXPIRED`, registrar `scan_events`
4) Si `status == ACTIVE` y dentro de vigencia:
   - `OK_FIRST`
   - en la misma transacción:
     - `status = USED`
     - `used_at = now`
     - `redisplay_until = now + 5 minutes`
   - registrar `scan_events` como `OK_FIRST`
5) Si `status == USED`:
   - si `now <= redisplay_until`: `OK_REDISPLAY`, registrar evento, no modificar tiempos
   - si `now > redisplay_until`: `USED`, registrar evento

### 8.3 Endpoints admin (panel en Vercel)
Autenticación:
- Header `X-Issuer-Key` (API key del emisor)

#### `POST /admin/invites/create`
Entrada:
- `visitor_name`
- `companions_expected`
- `valid_from`
- `valid_to`
- `visitor_phone` (opcional)
- `visitor_email` (opcional)

Acción:
- Validar `X-Issuer-Key` contra `issuer_keys` (hash)
- Obtener `employee_uid` asociado
- Crear registro en `invites`:
  - `code_id` aleatorio
  - `status = ACTIVE`
  - `issued_by_employee_uid = employee_uid`
  - `issued_at = now()` (servidor)

Salida:
- `invite_id`
- `code_id`
- `issued_by_employee_uid`
- `issued_at`

#### `GET /admin/invites`
- Lista pases con filtros.
- Para mostrar el nombre del emisor en UI, se cruza `issued_by_employee_uid` con `employees`.

#### `GET /admin/events/recent` (para monitoreo)
- Devuelve los últimos N eventos de escaneo y permite filtrar por rango de tiempo.
- Alternativa: usar suscripción en tiempo real, ver sección 9.5.

#### `POST /admin/invites/{invite_id}/revoke` (opcional)
- Revoca el pase (`status = REVOKED`).

## 9. Panel web (Vercel)

### 9.1 Hospedaje
- Panel hospedado en Vercel.
- Consume Edge Functions de Supabase por HTTPS.

### 9.2 Acceso y registro del emisor
- Cada empleado usa su API key.
- El panel envía `X-Issuer-Key`.
- El servidor determina y registra `issued_by_employee_uid`.

### 9.3 UI principal
- Crear pase
- Mostrar QR y permitir descarga
- Listados con filtros: vigentes, vencidos, utilizados, revocados
- Detalle de pase: datos, `used_at`, `redisplay_until`, eventos
- Revocar (opcional)

### 9.4 Generación de imagen QR (con texto)
El módulo que genera la imagen del QR debe incluir texto debajo del código:

Líneas obligatorias:
1) `Escanea este QR en la recepción`
2) `para acceder al piso 8 de Hacedores.`
3) `Vence: [Fecha y hora de vencimiento del pase]`

Notas:
- La fecha y hora deben corresponder a `valid_to` del pase.
- El QR en sí debe contener solo `code_id`.

Lugar de generación:
- Puede generarse en el panel (frontend) o en un endpoint del servidor, siempre que:
  - el contenido del QR sea `code_id`
  - el texto inferior refleje el vencimiento real del pase

### 9.5 Página de monitoreo en tiempo real
Debe existir una opción en el panel para abrir una página de monitoreo en tiempo real con dos columnas:

Columna izquierda, "Pases vigentes, visitantes esperados":
- Lista de pases vigentes, ordenados por `valid_to` ascendente o por creación reciente.
- Cada ítem muestra al menos:
  - `visitor_name`
  - `companions_expected`
  - `valid_to`
  - un estado visual (vigente, por vencer pronto, etc.)

Columna derecha, "Escaneos en tiempo real":
- Muestra en tiempo real los eventos que suceden en el scanner.
- Cada evento muestra al menos:
  - timestamp `scanned_at`
  - resultado (`OK_FIRST`, `OK_REDISPLAY`, `EXPIRED`, etc.)
  - `visitor_name` si el pase existe, para que el equipo del piso 8 identifique al visitante.

Al producirse un pase aprobado:
- Si llega un evento `OK_FIRST` o `OK_REDISPLAY`, debe aparecer de manera muy visible:
  - el nombre del visitante que está subiendo por elevador
  - y opcionalmente un aviso tipo "En camino al piso 8"

Implementación sugerida:
- Usar suscripción en tiempo real a inserciones en `scan_events`, o usar polling de baja latencia contra `/admin/events/recent`.
- La lógica de "pases vigentes" se calcula con `status=ACTIVE` y ahora dentro de vigencia.

## 10. Hardware del dispositivo (recepción)

### 10.1 Componentes acordados
- ESP32 DevKitC V4, módulo ESP32 WROOM 32D
- Lector M5Stack Unit QRCode en UART
- Display trasero LCD 1602 I2C (PCF8574)
- LED verde frontal
- Dock oscuro, posición fija, corriente fija
- Buzzer preferentemente interno del lector si cumple la regla

### 10.2 Alimentación y niveles lógicos
- Riel de 5V común (fuente con margen).
- El ESP32 usa lógica 3.3V en GPIOs.
- No puedo verificar el nivel del TX del lector en tu unidad, confirmar y si fuera 5V, adaptar nivel hacia RX del ESP32.

### 10.3 Conexiones lógicas recomendadas
UART lector (UART2):
- RX2 ESP32: GPIO16
- TX2 ESP32: GPIO17 (opcional)

I2C LCD:
- SDA: GPIO21
- SCL: GPIO22

LED frontal:
- GPIO seguro + resistencia + LED a GND.

## 11. Firmware ESP32 (Arduino IDE)

### 11.1 Arquitectura
- Lectura UART, cuando se decodifica un QR:
  - LED ON por tiempo fijo
  - beep del lector, si es consistente
- Validación HTTPS contra `/device/validate`
- Display trasero:
  - reposo Activo o Inactivo (según health)
  - resultado por 60 s, reemplazo inmediato por nuevo escaneo

## 12. Entregables

- Esquema de BD en Supabase, incluyendo soporte para `visitor_phone` y `visitor_email`.
- Página de monitoreo en tiempo real (2 columnas) dentro del panel en Vercel.
- Generación de imagen QR con texto inferior y fecha de vencimiento.
- Edge Functions y firmware según especificación.

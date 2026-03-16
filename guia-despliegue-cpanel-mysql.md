# Guía práctica: migración a hosting clásico (cPanel + MySQL)

Esta guía propone una ruta sencilla para implementar el sistema de pases QR sin Vercel/Supabase, usando un hosting tradicional con cPanel y base de datos MySQL.

## 1) Objetivo

Publicar una primera versión funcional del sistema de control de acceso con:
- panel web de emisión de pases,
- API de validación para el dispositivo,
- monitoreo básico de escaneos,
- almacenamiento en MySQL.

## 2) Arquitectura propuesta (simple y mantenible)

- **Frontend + Backend en el mismo hosting cPanel**
  - Opción recomendada para curva de aprendizaje baja: PHP + Laravel o PHP puro estructurado.
- **Base de datos MySQL** (la del propio hosting).
- **API HTTPS** bajo tu dominio, por ejemplo:
  - `GET /api/device/health`
  - `POST /api/device/validate`
  - `POST /api/admin/invites/create`
  - `GET /api/admin/invites`
  - `GET /api/admin/events/recent`
  - `POST /api/admin/invites/{id}/revoke`

## 3) Mapeo de Supabase/Postgres a MySQL

Crear tablas equivalentes:

- `invites`
  - `id` CHAR(36) PK (UUID)
  - `code_id` VARCHAR(128) UNIQUE
  - `visitor_name` VARCHAR(255)
  - `visitor_phone` VARCHAR(30) NULL
  - `visitor_email` VARCHAR(255) NULL
  - `companions_expected` INT NOT NULL DEFAULT 0
  - `valid_from` DATETIME NOT NULL
  - `valid_to` DATETIME NOT NULL
  - `issued_by_employee_uid` VARCHAR(64) NOT NULL
  - `issued_at` DATETIME NOT NULL
  - `status` ENUM('ACTIVE','USED','REVOKED') NOT NULL DEFAULT 'ACTIVE'
  - `used_at` DATETIME NULL
  - `redisplay_until` DATETIME NULL

- `scan_events`
  - `id` CHAR(36) PK
  - `code_id` VARCHAR(128)
  - `device_id` VARCHAR(64)
  - `scanned_at` DATETIME NOT NULL
  - `result` ENUM('OK_FIRST','OK_REDISPLAY','INEXISTENT','EXPIRED','REVOKED','USED') NOT NULL
  - `latency_ms` INT NULL
  - `error_detail` TEXT NULL

- `devices`
  - `device_id` VARCHAR(64) UNIQUE
  - `api_key_hash` VARCHAR(255)
  - `label` VARCHAR(255)
  - `is_enabled` TINYINT(1) NOT NULL DEFAULT 1
  - `created_at` DATETIME NOT NULL

- `issuer_keys`
  - `issuer_key_id` CHAR(36) PK
  - `employee_uid` VARCHAR(64) NOT NULL
  - `api_key_hash` VARCHAR(255) NOT NULL
  - `is_enabled` TINYINT(1) NOT NULL DEFAULT 1
  - `created_at` DATETIME NOT NULL

- `employees` (fuente existente)
  - `uid`, `display_name`, `is_active` (mínimo)

## 4) Lógica crítica de negocio (igual a la especificación)

En `POST /api/device/validate` debes ejecutar la lógica de forma atómica:

1. Si no existe `code_id` -> `INEXISTENT`.
2. Si está `REVOKED` -> `REVOKED`.
3. Si ahora está fuera de `valid_from/valid_to` -> `EXPIRED`.
4. Si está `ACTIVE` y vigente:
   - responder `OK_FIRST`,
   - actualizar en una sola transacción:
     - `status='USED'`,
     - `used_at=NOW()`,
     - `redisplay_until=NOW() + INTERVAL 5 MINUTE`.
5. Si está `USED`:
   - si `NOW() <= redisplay_until` -> `OK_REDISPLAY`,
   - si `NOW() > redisplay_until` -> `USED`.

Siempre registrar `scan_events`.

## 5) Seguridad mínima recomendada

- Forzar **HTTPS** (certificado SSL activo en cPanel).
- No guardar API keys en texto plano; guardar hash (bcrypt/argon2).
- Validar `X-API-Key` para dispositivo y `X-Issuer-Key` para panel.
- Limitar CORS solo a tu dominio.
- Agregar rate limiting básico por IP y por `device_id`.
- Hacer backups automáticos diarios de MySQL desde cPanel.

## 6) Despliegue paso a paso en cPanel

1. Crear base de datos y usuario MySQL en cPanel.
2. Ejecutar migraciones SQL para crear tablas.
3. Subir código del panel/API (`public_html` o subdominio dedicado tipo `acceso.tudominio.com`).
4. Configurar variables de entorno en archivo de configuración (fuera de `public_html` cuando sea posible).
5. Configurar cron jobs:
   - limpieza/archivado de eventos antiguos,
   - pruebas de salud opcionales,
   - respaldos adicionales si aplica.
6. Probar endpoints con Postman/cURL antes de conectar el ESP32.
7. Configurar firmware del dispositivo apuntando al nuevo dominio HTTPS.

## 7) Plan por fases (recomendado)

### Fase 1 — MVP operativo
- Emisión de pase.
- Generación de QR (contenido: solo `code_id`).
- Validación en dispositivo.
- Mensajes de resultado en display.

### Fase 2 — Operación diaria
- Listado de pases.
- Revocación.
- Monitoreo de eventos recientes.

### Fase 3 — Mejoras
- Avisos al piso 8 en tiempo real (polling corto o websocket si el hosting lo permite).
- Automatización de envío por WhatsApp/email.
- Dashboard con métricas.

## 8) Riesgos y mitigaciones

- **Hosting compartido lento** en horas pico:
  - Mitigación: cachear listados y optimizar índices (`code_id`, `status`, `valid_to`, `scanned_at`).
- **Concurrencia en escaneos simultáneos**:
  - Mitigación: usar transacciones y `SELECT ... FOR UPDATE` en validación.
- **Mantenimiento manual**:
  - Mitigación: checklist mensual de SSL, backups y rotación de API keys.

## 9) Criterios de aceptación para considerar “listo”

- El dispositivo recibe respuesta en < 1s en red estable.
- Se respetan exactamente los estados: `OK_FIRST`, `OK_REDISPLAY`, `INEXISTENT`, `EXPIRED`, `REVOKED`, `USED`.
- La ventana de 5 minutos funciona desde el primer OK.
- El panel puede crear pases y mostrar eventos recientes.
- Existen backups restaurables de base de datos.

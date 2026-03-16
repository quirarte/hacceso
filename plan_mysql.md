# plan_mysql.md — Sistema de Pases de Acceso con QR (Arquitectura PHP + MySQL)

[No verificado] Este plan define una implementación completa orientada a hosting clásico (cPanel + MySQL + PHP). No puedo verificar hardware real, rendimiento del hosting compartido ni comportamiento exacto del lector QR sin pruebas en sitio.

## 1. Objetivo

Diseñar, construir e implementar un sistema de control de acceso para el edificio del makerspace, basado en pases temporales con QR, operando **sin Vercel/Supabase** y usando una arquitectura tradicional:

- Backend API en **PHP**
- Base de datos **MySQL**
- Panel web en el mismo hosting
- Dispositivo ESP32 validando por HTTPS contra el backend

## 2. Actores

- **Emisor (staff Hacedores):** crea y emite pases desde panel web.
- **Visitante:** recibe QR y lo presenta en recepción.
- **Vigilante/recepción:** valida acceso leyendo el display trasero del dispositivo.
- **Equipo piso 8:** monitorea escaneos en tiempo casi real.
- **Dispositivo (ESP32 + lector):** escanea QR y consulta API.
- **Servidor PHP:** aplica reglas de negocio y registra eventos.
- **MySQL:** almacena pases, llaves, dispositivos y escaneos.
- **Base de empleados:** fuente de verdad del personal por UID.

## 3. Requerimientos funcionales

### 3.1 Emisión de pases (panel web)

El emisor captura:

1) `visitor_name` (nombre visitante)
2) `valid_from` (inicio vigencia)
3) `valid_to` (fin vigencia)
4) `companions_expected` (acompañantes esperados)
5) `visitor_phone` (opcional)
6) `visitor_email` (opcional)

El sistema:
- autentica con `X-Issuer-Key` (o sesión autenticada equivalente),
- determina `issued_by_employee_uid`,
- genera `code_id` aleatorio,
- registra `issued_at` desde servidor,
- crea imagen QR para compartir.

### 3.2 Envío del QR al visitante

- Envío manual por WhatsApp/email desde staff.
- Automatización (email/WhatsApp API) queda para fase posterior.

### 3.3 Validación en recepción (dispositivo)

Flujo:
1. Visitante coloca QR en dock.
2. Lector decodifica `code_id` por UART.
3. ESP32 hace `POST /api/device/validate` por HTTPS.
4. Display trasero muestra resultado.
5. API registra `scan_events` y aplica estado del pase.

### 3.4 Mensajes esperados (display trasero)

- `código inexistente`
- `código vencido`
- `código ya utilizado`
- `código revocado`
- En OK: nombre visitante y acompañantes esperados.

### 3.5 Ventana de reescaneo (5 minutos)

- Primer OK: `OK_FIRST`, pase pasa a `USED`.
- Durante 5 minutos desde `used_at`: `OK_REDISPLAY`.
- Después de `redisplay_until`: resultado `USED`.

### 3.6 Privacidad frontal

- Visitante no ve datos personales.
- Frente: solo LED verde + beep único e indistinguible para cualquier resultado decodificado.

## 4. Requerimientos de UI del dispositivo

### 4.1 Reposo

- Mostrar `Activo` cuando health checks son exitosos.
- Mostrar `Inactivo` cuando fallan repetidamente.

### 4.2 Resultado temporal

- Mostrar resultado del último escaneo hasta 60 segundos.
- Si llega otro escaneo, reemplazar inmediatamente.
- Tras 60 segundos sin eventos, volver a reposo.

## 5. Arquitectura técnica PHP + MySQL

## 5.1 Opción recomendada de stack

- **PHP 8.2+**
- **Laravel 11** (recomendado por estructura, validación y migraciones), o PHP nativo estructurado si se busca máxima simplicidad.
- **MySQL 8**
- **Apache/Nginx** gestionado por cPanel.

## 5.2 Componentes

1. **API PHP**
   - Endpoints admin y device.
2. **Panel web PHP**
   - Formulario crear pase, listados, detalle y monitoreo.
3. **MySQL**
   - tablas de negocio + índices.
4. **Firmware ESP32**
   - cliente HTTPS para validación y health.

## 5.3 Estructura sugerida (Laravel)

- `app/Http/Controllers/Admin/*`
- `app/Http/Controllers/Device/*`
- `app/Services/InviteValidationService.php`
- `app/Models/*`
- `database/migrations/*`
- `resources/views/*` o frontend separado simple dentro del mismo hosting.

## 6. Modelo de datos (MySQL)

## 6.1 Tabla `invites`

- `id` CHAR(36) PK
- `code_id` VARCHAR(128) UNIQUE NOT NULL
- `visitor_name` VARCHAR(255) NOT NULL
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
- `created_at` DATETIME NOT NULL
- `updated_at` DATETIME NOT NULL

Índices:
- `UNIQUE(code_id)`
- `INDEX(status, valid_to)`
- `INDEX(issued_by_employee_uid, issued_at)`

## 6.2 Tabla `scan_events`

- `id` CHAR(36) PK
- `code_id` VARCHAR(128) NOT NULL
- `device_id` VARCHAR(64) NOT NULL
- `scanned_at` DATETIME NOT NULL
- `result` ENUM('OK_FIRST','OK_REDISPLAY','INEXISTENT','EXPIRED','REVOKED','USED') NOT NULL
- `visitor_name_snapshot` VARCHAR(255) NULL
- `latency_ms` INT NULL
- `error_detail` TEXT NULL

Índices:
- `INDEX(scanned_at)`
- `INDEX(device_id, scanned_at)`
- `INDEX(code_id, scanned_at)`

## 6.3 Tabla `devices`

- `device_id` VARCHAR(64) PK
- `api_key_hash` VARCHAR(255) NOT NULL
- `label` VARCHAR(255) NOT NULL
- `is_enabled` TINYINT(1) NOT NULL DEFAULT 1
- `created_at` DATETIME NOT NULL
- `updated_at` DATETIME NOT NULL

## 6.4 Tabla `issuer_keys`

- `issuer_key_id` CHAR(36) PK
- `employee_uid` VARCHAR(64) NOT NULL
- `api_key_hash` VARCHAR(255) NOT NULL
- `is_enabled` TINYINT(1) NOT NULL DEFAULT 1
- `created_at` DATETIME NOT NULL
- `updated_at` DATETIME NOT NULL

Índices:
- `INDEX(employee_uid, is_enabled)`

## 6.5 Tabla `employees` (fuente de verdad)

Mínimo:
- `uid` (PK lógica)
- `display_name`
- `is_active`

Nota: puede estar en otra BD; si está externa, se sincroniza por job.

## 7. API HTTP

## 7.1 Device endpoints

### `GET /api/device/health`

Respuesta 200:
```json
{ "ok": true }
```

### `POST /api/device/validate`
Headers:
- `X-API-Key: <device_key>`

Body:
```json
{
  "device_id": "recepcion-01",
  "code_id": "abc123..."
}
```

Respuesta:
```json
{
  "result": "OK_FIRST",
  "visitor_name": "Nombre",
  "companions_expected": 2
}
```

## 7.2 Admin endpoints

### `POST /api/admin/invites/create`
Headers: `X-Issuer-Key`

### `GET /api/admin/invites`
Lista con filtros por estado, fecha, emisor.

### `GET /api/admin/events/recent`
Últimos N eventos para monitoreo.

### `POST /api/admin/invites/{id}/revoke`
Marca pase como `REVOKED`.

## 8. Lógica transaccional crítica

En `device/validate`, usar transacción SQL y bloqueo pesimista:

- `SELECT ... FROM invites WHERE code_id = ? FOR UPDATE`
- Evaluar reglas en orden:
  1) inexistente -> `INEXISTENT`
  2) `REVOKED` -> `REVOKED`
  3) fuera de vigencia -> `EXPIRED`
  4) `ACTIVE` en vigencia -> actualizar a `USED` + tiempos -> `OK_FIRST`
  5) `USED` dentro de ventana -> `OK_REDISPLAY`
  6) `USED` fuera de ventana -> `USED`
- Insertar `scan_events` en todos los casos.
- Commit.

Objetivo: evitar doble consumo inconsistente ante escaneos simultáneos.

## 9. Panel web (PHP)

## 9.1 Módulos mínimos

- Crear pase.
- Generar y descargar imagen QR.
- Listado de pases (filtros por estado).
- Detalle de pase + historial de eventos.
- Revocar pase.
- Monitoreo (visitantes esperados + escaneos recientes).

## 9.2 Requisitos de imagen QR

- El QR contiene **solo `code_id`**.
- Debajo del QR incluir:
  - “Escanea este QR en la recepción”
  - “para acceder al piso 8 de Hacedores.”
  - “Vence: [fecha-hora valid_to]”

## 9.3 Monitoreo en tiempo real (aproximado)

Como primera versión en hosting clásico:
- polling cada 3–5 segundos a `GET /api/admin/events/recent`.
- columna izquierda: pases vigentes.
- columna derecha: escaneos recientes.
- destacar visualmente eventos `OK_FIRST` y `OK_REDISPLAY`.

## 10. Seguridad

- HTTPS obligatorio.
- API keys hasheadas con Argon2id/bcrypt.
- Rotación periódica de keys de dispositivo y emisores.
- Validación estricta de inputs (longitudes, formatos, fechas).
- Protección CSRF en panel web con sesión.
- Rate limiting:
  - device validate por `device_id` + IP.
  - admin endpoints por usuario/key.
- CORS restringido al dominio del panel.
- Logs con datos mínimos (sin exponer keys).

## 11. Infraestructura en cPanel

## 11.1 Despliegue

1. Crear DB y usuario MySQL.
2. Subir código a `public_html` o subdominio `acceso.tudominio.com`.
3. Configurar variables sensibles en `.env` fuera de público si el proveedor lo permite.
4. Ejecutar migraciones.
5. Configurar cron jobs de mantenimiento.
6. Activar SSL y redirección forzada a HTTPS.

## 11.2 Cron jobs recomendados

- Limpieza/archivo de `scan_events` antiguos.
- Verificación de consistencia de datos.
- Respaldo complementario y verificación de restauración.

## 12. Firmware ESP32 (integración)

- Health check cada 10s.
- Marcar inactivo con 3 fallas consecutivas.
- En lectura de QR:
  - LED + beep uniforme.
  - llamada HTTPS con timeout corto (ej. 1.5 s).
- Manejo de errores de red:
  - mostrar “Inactivo” si no hay conectividad persistente.

## 13. Plan de implementación por sprints

## Sprint 1 (base técnica)
- Esquema MySQL + migraciones.
- Endpoints `health` y `validate` con lógica completa.
- Registro de eventos.

## Sprint 2 (operación admin)
- Crear/listar/revocar pases.
- Generación de QR.
- Filtros y detalle.

## Sprint 3 (monitoreo y hardening)
- Pantalla de monitoreo con polling.
- Rate limiting, rotación de keys.
- Pruebas de concurrencia y carga básica.

## 14. Pruebas y criterios de aceptación

## 14.1 Casos funcionales

- Código inexistente -> `INEXISTENT`.
- Código revocado -> `REVOKED`.
- Código fuera de vigencia -> `EXPIRED`.
- Primer uso válido -> `OK_FIRST`.
- Reuso dentro de 5 min -> `OK_REDISPLAY`.
- Reuso después de 5 min -> `USED`.

## 14.2 Rendimiento objetivo

- `device/validate` p95 < 1000 ms en red estable.

## 14.3 Operación

- Backups diarios verificables.
- Monitoreo muestra eventos en < 5 s de retraso.
- Rotación de llaves documentada.

## 15. Riesgos y mitigaciones

- **Hosting compartido saturado:** optimizar índices y queries; escalar a VPS si p95 sube.
- **Desalineación de hora servidor/dispositivo:** usar hora servidor como única fuente de verdad.
- **Errores por concurrencia:** `FOR UPDATE` + pruebas simultáneas.
- **Fuga de credenciales:** rotación + hashing + variables fuera de webroot.

## 16. Entregables

- `plan_mysql.md` como especificación maestra para arquitectura PHP + MySQL.
- Script de migraciones SQL o migraciones Laravel.
- Catálogo de endpoints y contrato JSON.
- Guía de despliegue en cPanel y checklist de operación.

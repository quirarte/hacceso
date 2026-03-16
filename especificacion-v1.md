# Especificación final v1 — Sistema de Pases de Acceso con QR (Hacedores)

Este documento consolida las decisiones finales del MVP y completa vacíos operativos del `plan.md` para iniciar implementación sin ambigüedades.

## 1) Alcance del MVP

Incluye en MVP:
- Revocación de pases.
- Monitoreo en tiempo real.
- Firmware ESP32 integrado al flujo completo.

Fuera de MVP:
- Automatización de envío por WhatsApp/email.
- Multiambiente (por ahora solo producción).

## 2) Stack y arquitectura

- Panel web: **Next.js** + **TypeScript estricto**.
- UI library: **ninguna** (CSS propio).
- Backend: Supabase Postgres + Supabase Edge Functions (TypeScript).
- Tiempo real: **Supabase Realtime** sobre inserciones en `scan_events`.
- Entorno activo: **prod**.

## 3) Convenciones de fecha y zona horaria

- Transporte API: **ISO-8601 en UTC**.
- Zona de negocio para visualización: **America/Mexico_City**.
- Formato visible (UI y texto del QR): **dd/MM/YYYY HH:mm**.
- Regla de vigencia:
  - `valid_to` es **inclusivo**.
  - Se permite `valid_from` en pasado.

## 4) Seguridad y llaves API

### 4.1 Modelo de autenticación
- Operaciones admin: header `X-Issuer-Key`.
- Validación de dispositivo: header `X-API-Key`.

### 4.2 Generación y almacenamiento de llaves
- Las llaves se generan internamente con CSPRNG (mínimo 32 bytes aleatorios).
- El valor en claro se muestra solo una vez al crearla.
- En base de datos se guarda únicamente hash.
- Algoritmo de hash recomendado: **Argon2id** (fallback: bcrypt si no aplica).

### 4.3 Caducidad y rotación
- Las llaves **no expiran automáticamente** en MVP.
- Rotación: **manual** por administración cuando sea necesario.

### 4.4 CORS
- Permitido exclusivamente el dominio del panel en producción.
- No usar `*` en `Access-Control-Allow-Origin`.
- Headers permitidos mínimos: `Content-Type`, `X-Issuer-Key`.

### 4.5 Acceso a datos y RLS
- El panel no accede tablas de Supabase directamente.
- Operaciones pasan por Edge Functions con service role.
- Mantener políticas que bloqueen acceso público directo a tablas del sistema.

## 5) Modelo de datos (confirmado para MVP)

## 5.1 `employees`
- `uid`: **text**.
- Campo de nombre: **`user_name`**.
- Fuente en misma base de Supabase.

## 5.2 `invites`
Campos mínimos:
- `id` UUID PK
- `code_id` TEXT UNIQUE (32 chars base62)
- `visitor_name` TEXT
- `visitor_phone` TEXT NULL
- `visitor_email` TEXT NULL
- `companions_expected` INT
- `valid_from` TIMESTAMPTZ
- `valid_to` TIMESTAMPTZ
- `issued_by_employee_uid` TEXT
- `issued_at` TIMESTAMPTZ
- `status` TEXT CHECK IN (`ACTIVE`,`USED`,`REVOKED`)
- `used_at` TIMESTAMPTZ NULL
- `redisplay_until` TIMESTAMPTZ NULL

## 5.3 `scan_events`
Campos mínimos:
- `id` UUID PK
- `code_id` TEXT
- `device_id` TEXT
- `scanned_at` TIMESTAMPTZ
- `result` TEXT CHECK IN (`OK_FIRST`,`OK_REDISPLAY`,`INEXISTENT`,`EXPIRED`,`REVOKED`,`USED`)
- `latency_ms` INT NULL
- `error_detail` TEXT NULL

Retención: **indefinida**.

## 5.4 `devices`
- `device_id` TEXT UNIQUE
- `api_key_hash` TEXT
- `label` TEXT
- `is_enabled` BOOLEAN
- `created_at` TIMESTAMPTZ

## 5.5 `issuer_keys`
- `issuer_key_id` UUID PK
- `employee_uid` TEXT
- `api_key_hash` TEXT
- `is_enabled` BOOLEAN
- `created_at` TIMESTAMPTZ

## 6) Contratos API (v1)

## 6.1 Convención de error estándar
Todas las respuestas de error deben usar:

```json
{
  "error": {
    "code": "INVITE_NOT_FOUND",
    "message": "Pase no encontrado",
    "details": null,
    "request_id": "uuid"
  }
}
```

## 6.2 `GET /device/health`
- 200: `{ "ok": true }`.
- Se usa para estado Activo/Inactivo del dispositivo.

## 6.3 `POST /device/validate`
Entrada JSON:
- `device_id`
- `code_id`

Header:
- `X-API-Key`

Salida JSON:
- `result`: `OK_FIRST|OK_REDISPLAY|INEXISTENT|EXPIRED|REVOKED|USED`
- En OK: `visitor_name`, `companions_expected`

Reglas:
1. INEXISTENT si no existe.
2. REVOKED si `status=REVOKED`.
3. EXPIRED si fuera de vigencia (`valid_to` inclusivo).
4. ACTIVE en vigencia => OK_FIRST y transición atómica a USED (`used_at`, `redisplay_until=used_at+5m`).
5. USED dentro de ventana => OK_REDISPLAY; fuera => USED.

## 6.4 `POST /admin/invites/create`
Header:
- `X-Issuer-Key`

Body:
- `visitor_name`
- `companions_expected`
- `valid_from`
- `valid_to`
- `visitor_phone` opcional
- `visitor_email` opcional

Acciones:
- Validar key contra `issuer_keys`.
- Resolver `employee_uid`.
- Crear invite con `code_id` base62 de 32 chars.

Respuesta:
- `invite_id`, `code_id`, `issued_by_employee_uid`, `issued_at`.

## 6.5 `GET /admin/invites`
Paginación:
- Cursor-based (`cursor`, `limit`).

Campos mínimos por item:
- `id`, `code_id`, `visitor_name`, `companions_expected`
- `valid_from`, `valid_to`
- `status`, `used_at`, `redisplay_until`
- `issued_by_employee_uid`, `issued_at`
- `visitor_phone`, `visitor_email`
- `issued_by_user_name` (join con `employees.user_name`)

## 6.6 `GET /admin/events/recent`
- Devuelve eventos recientes con filtros por tiempo y límite.
- Se complementa con suscripción realtime para interfaz viva.

## 6.7 `POST /admin/invites/{invite_id}/revoke`
- Cambia `status` a `REVOKED`.
- Si ya está revocado, responder idempotente (200 con estado actual).

## 7) Panel web (Next.js)

Secciones mínimas:
1. Crear pase.
2. Vista de QR (visible) + descarga PNG.
3. Lista de pases con filtros (vigentes/vencidos/usados/revocados).
4. Detalle de pase (incluye eventos y metadatos de uso).
5. Revocación de pase.
6. Monitoreo en tiempo real (2 columnas):
   - Izquierda: vigentes esperados.
   - Derecha: escaneos en vivo.

UX copy:
- Sistema en español.
- Para aprobación visible (`OK_FIRST`/`OK_REDISPLAY`): mostrar aviso destacado
  - Título sugerido: **“En camino al piso 8”**
  - Subtítulo: nombre del visitante.

## 8) QR: contenido y render

- Contenido del QR: solo `code_id`.
- Formato imagen: **PNG**.
- Debe mostrarse en pantalla y ser descargable.
- Texto inferior obligatorio:
  1) `Escanea este QR en la recepción`
  2) `para acceder al piso 8 de Hacedores.`
  3) `Vence: [dd/MM/YYYY HH:mm]` en zona `America/Mexico_City`

## 9) Firmware ESP32 (MVP)

Hardware confirmado:
- Lector entrega TX a 5V => usar adaptación de nivel hacia RX ESP32.

Comportamiento:
- Leer QR por UART.
- Emitir mismo LED/beep para toda lectura válida de QR (sin revelar resultado).
- Consultar `/device/validate` por HTTPS.
- Display trasero:
  - Reposo: Activo/Inactivo según `GET /device/health`.
  - Resultado: máximo 60s, reemplazo inmediato en nuevo escaneo.

Red y resiliencia:
- Timeout HTTP recomendado: **3s**.
- Reintentos: **2** (3 intentos totales).
- Backoff: 300 ms y 700 ms.
- Si no hay respuesta: mostrar **“No hay internet”**.

## 10) Operación y despliegue

- Solo ambiente `prod` en fase inicial.
- URLs y claves externas deben vivir en variables de configuración/entorno.
- Administración de secretos y rotación manual: responsable **admin**.

## 11) SLA inicial recomendado

Para `/device/validate`:
- p95 < **800 ms**
- p99 < **1500 ms**
- Disponibilidad mensual objetivo: **99.5%**

## 12) Definition of Done (DoD) y demo obligatoria

Escenarios mínimos de aceptación:
1. Crear pase con fechas y QR PNG visible/descargable.
2. Escaneo OK_FIRST con transición a USED.
3. Escaneo OK_REDISPLAY dentro de 5 minutos.
4. Escaneo USED al pasar ventana de redisplay.
5. INEXISTENT con código inexistente.
6. EXPIRED fuera de vigencia.
7. REVOKED tras revocación.
8. Monitoreo realtime recibe eventos en vivo.
9. Lista de vigentes muestra estado correcto.
10. Sin internet en dispositivo => “No hay internet”.
11. Health check conmuta Activo/Inactivo.
12. Registro completo de `scan_events`.

## 13) Pendientes operativos no bloqueantes

1. Definir dominio final de producción para CORS.
2. Definir URL final de Vercel como variable de configuración.
3. Confirmar disponibilidad de Argon2id en runtime de Edge Functions.
4. Definir procedimiento admin para alta/baja de dispositivos y llaves emisor.

## 14) Guía de alta en Supabase (para compartir accesos y rutas)

Objetivo: que el administrador prepare el proyecto y comparta únicamente los datos necesarios para integrar backend/panel sin exponer secretos sensibles en el repositorio.

### 14.1 Crear proyecto y región
1. Entrar a Supabase Dashboard.
2. `New project`.
3. Definir:
   - `Organization`
   - `Project name`: sugerido `hacedores-access-prod`
   - `Database Password`: generar y guardar en gestor de secretos
   - `Region`: la más cercana a Ciudad de México
4. Esperar provisionamiento completo.

### 14.2 Activar extensiones SQL recomendadas
En `SQL Editor`, ejecutar:

```sql
create extension if not exists pgcrypto;
```

### 14.3 Crear esquema base (tablas MVP)
En `SQL Editor`, ejecutar script de creación de tablas e índices cuando se inicie implementación.

Nota: este documento define el contrato; la migración SQL final se versionará en el repositorio.

### 14.4 Obtener rutas/endpoints a compartir
Desde `Project Settings` copiar y compartir estos valores:
- `Project URL` (ej: `https://<project-ref>.supabase.co`)
- `Project reference` (`<project-ref>`)
- URL de funciones Edge:
  - Base: `https://<project-ref>.supabase.co/functions/v1`
  - Salud: `https://<project-ref>.supabase.co/functions/v1/device-health`
  - Validación: `https://<project-ref>.supabase.co/functions/v1/device-validate`
  - Admin create invite: `https://<project-ref>.supabase.co/functions/v1/admin-invites-create`

### 14.5 Llaves y secretos (qué sí compartir y qué no)
Compartir conmigo para integración de configuración:
- `NEXT_PUBLIC_SUPABASE_URL`
- `NEXT_PUBLIC_SUPABASE_ANON_KEY`
- Dominio final del panel (para CORS)

No pegar en archivos versionados ni chats públicos:
- `service_role key`
- `database password`
- `issuer/device api keys` en claro

### 14.6 Checklist para habilitar Realtime
1. Ir a `Database` → `Replication`.
2. Habilitar replicación para tabla `scan_events`.
3. Confirmar que la suscripción de inserciones esté activa.

### 14.7 Plantilla de datos que me debes pasar para completar este MD

| Campo | Valor real |
|---|---|
| Project URL | https://faatolsezlylgsnncdnb.supabase.co |
| Project reference | faatolsezlylgsnncdnb |
| NEXT_PUBLIC_SUPABASE_URL | https://faatolsezlylgsnncdnb.supabase.co |
| NEXT_PUBLIC_SUPABASE_ANON_KEY | PENDIENTE |
| Dominio panel (CORS) | PENDIENTE |
| Edge base URL | PENDIENTE |
| URL `device-health` | PENDIENTE |
| URL `device-validate` | PENDIENTE |
| URL `admin-invites-create` | PENDIENTE |

Con esa tabla llena, actualizo este archivo con tus rutas reales y lo dejo listo como fuente oficial de configuración.


## 15) Integración con Vercel (datos que necesito de tu cuenta)

Como el frontend vivirá en Vercel, necesito estos datos para cerrar configuración de despliegue y CORS.

### 15.1 Pasos en Vercel
1. Entrar a `vercel.com` y crear/importar el proyecto del frontend.
2. En `Project Settings` → `General`, copiar:
   - `Project Name`
   - `Framework Preset` (debe quedar Next.js)
3. En `Project Settings` → `Domains`, copiar:
   - URL de producción asignada por Vercel (ej. `https://tu-proyecto.vercel.app`)
   - Dominio personalizado, si ya existe
4. En `Project Settings` → `Environment Variables`, cargar (sin compartir secretos privados):
   - `NEXT_PUBLIC_SUPABASE_URL`
   - `NEXT_PUBLIC_SUPABASE_ANON_KEY`

### 15.2 Datos que sí debes compartirme
- URL de producción de Vercel (`https://...vercel.app` o dominio propio).
- Nombre del proyecto en Vercel.
- Confirmación de que variables públicas quedaron cargadas en `Production`:
  - `NEXT_PUBLIC_SUPABASE_URL`
  - `NEXT_PUBLIC_SUPABASE_ANON_KEY`

### 15.3 Datos que NO debes compartir
- Tokens personales de Vercel.
- `SUPABASE_SERVICE_ROLE_KEY`.
- Passwords de base de datos.

### 15.4 Plantilla rápida para responderme

| Campo | Valor real |
|---|---|
| Vercel project name | PENDIENTE |
| Vercel production URL | PENDIENTE |
| Dominio personalizado (si aplica) | PENDIENTE |
| `NEXT_PUBLIC_SUPABASE_URL` en Production | OK/PENDIENTE |
| `NEXT_PUBLIC_SUPABASE_ANON_KEY` en Production | OK/PENDIENTE |
| CORS final permitido | PENDIENTE |

Con esa información, actualizo este documento con valores finales y dejo cerrada la configuración de infraestructura para iniciar implementación.

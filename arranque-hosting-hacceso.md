# Arranque rápido en hosting tradicional (hacceso.hacedores.com)

Guía práctica para dejar listo el entorno inicial en cPanel con PHP simple + MySQL.

## 1) Datos confirmados

- Subdominio: `hacceso.hacedores.com`
- Stack: **PHP simple** (sin framework)
- Dispositivo inicial: 1 (`recepcion-01` sugerido)
- Zona horaria: `America/Mexico_City`
- Cron jobs: habilitados
- Base de datos: pendiente de crear

## 2) Sugerencia de URLs finales

Usa estas rutas para empezar:

- Health: `https://hacceso.hacedores.com/api/device/health`
- Validate: `https://hacceso.hacedores.com/api/device/validate`

## 3) Crear base de datos y usuario en cPanel

1. Entra a: `https://hacceso.hacedores.com/cpanel`
2. Abre **MySQL® Databases**.
3. Crea una base de datos, por ejemplo: `hacedo_hacceso`.
4. Crea un usuario MySQL, por ejemplo: `hacedo_hacceso_u`.
5. Asigna contraseña robusta y guárdala.
6. En **Add User To Database**, vincula usuario + BD.
7. Otorga privilegio **ALL PRIVILEGES**.

> Nota: en muchos hostings cPanel se antepone automáticamente un prefijo al nombre.

## 4) Crear tablas fácilmente desde phpMyAdmin (automático con SQL)

1. Abre **phpMyAdmin** desde cPanel.
2. Selecciona la BD creada.
3. Ve a la pestaña **SQL**.
4. Pega y ejecuta este script completo:

```sql
SET NAMES utf8mb4;
SET time_zone = '-06:00';

CREATE TABLE IF NOT EXISTS employees (
  uid VARCHAR(64) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (uid),
  KEY idx_employees_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invites (
  id CHAR(36) NOT NULL,
  code_id VARCHAR(128) NOT NULL,
  visitor_name VARCHAR(255) NOT NULL,
  visitor_phone VARCHAR(30) NULL,
  visitor_email VARCHAR(255) NULL,
  companions_expected INT NOT NULL DEFAULT 0,
  valid_from DATETIME NOT NULL,
  valid_to DATETIME NOT NULL,
  issued_by_employee_uid VARCHAR(64) NOT NULL,
  issued_at DATETIME NOT NULL,
  status ENUM('ACTIVE','USED','REVOKED') NOT NULL DEFAULT 'ACTIVE',
  used_at DATETIME NULL,
  redisplay_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invites_code_id (code_id),
  KEY idx_invites_status_valid_to (status, valid_to),
  KEY idx_invites_issuer_issued_at (issued_by_employee_uid, issued_at),
  CONSTRAINT fk_invites_employee
    FOREIGN KEY (issued_by_employee_uid) REFERENCES employees(uid)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devices (
  device_id VARCHAR(64) NOT NULL,
  api_key_hash VARCHAR(255) NOT NULL,
  label VARCHAR(255) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS issuer_keys (
  issuer_key_id CHAR(36) NOT NULL,
  employee_uid VARCHAR(64) NOT NULL,
  api_key_hash VARCHAR(255) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (issuer_key_id),
  KEY idx_issuer_keys_employee_enabled (employee_uid, is_enabled),
  CONSTRAINT fk_issuer_keys_employee
    FOREIGN KEY (employee_uid) REFERENCES employees(uid)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scan_events (
  id CHAR(36) NOT NULL,
  code_id VARCHAR(128) NOT NULL,
  device_id VARCHAR(64) NOT NULL,
  scanned_at DATETIME NOT NULL,
  result ENUM('OK_FIRST','OK_REDISPLAY','INEXISTENT','EXPIRED','REVOKED','USED') NOT NULL,
  visitor_name_snapshot VARCHAR(255) NULL,
  latency_ms INT NULL,
  error_detail TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_scan_events_scanned_at (scanned_at),
  KEY idx_scan_events_device_scanned_at (device_id, scanned_at),
  KEY idx_scan_events_code_scanned_at (code_id, scanned_at),
  CONSTRAINT fk_scan_events_device
    FOREIGN KEY (device_id) REFERENCES devices(device_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 5) Cargas iniciales mínimas (obligatorio)

Después de crear tablas, ejecuta estos inserts de ejemplo (ajústalos a tus datos reales):

```sql
INSERT INTO employees (uid, display_name, is_active)
VALUES ('emp_admin_01', 'Admin Hacedores', 1)
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), is_active=VALUES(is_active);

INSERT INTO devices (device_id, api_key_hash, label, is_enabled)
VALUES ('recepcion-01', 'PENDIENTE_HASH_REAL', 'Recepción principal', 1)
ON DUPLICATE KEY UPDATE label=VALUES(label), is_enabled=VALUES(is_enabled);
```

> Importante: `api_key_hash` debe ser bcrypt/argon2 generado en backend, **no texto plano**.

## 6) ¿Cómo verificar que SSL está activo?

Haz estas validaciones:

### Opción A — navegador
1. Abre `https://hacceso.hacedores.com`
2. Verifica candado en barra del navegador.
3. Abre detalles del certificado y confirma que no esté expirado.

### Opción B — redirección HTTP->HTTPS
Ejecuta:

```bash
curl -I http://hacceso.hacedores.com
```

Debe regresar `301` o `302` redirigiendo a `https://...`.

### Opción C — handshake TLS
Ejecuta:

```bash
openssl s_client -connect hacceso.hacedores.com:443 -servername hacceso.hacedores.com </dev/null
```

Debes ver certificado presentado correctamente y sin errores críticos de verificación.

## 7) Configuración base de PHP para zona horaria

En tu bootstrap/config inicial de PHP define:

```php
date_default_timezone_set('America/Mexico_City');
```

Y en MySQL, al conectar, envía:

```sql
SET time_zone = '-06:00';
```

(En horario de verano se recomienda manejar todo en UTC en backend y solo convertir para UI.)

## 8) Cron jobs sugeridos

Como tienes libertad de frecuencia, arranca con:

1. Limpieza de eventos antiguos (diario 02:30)
   - `30 2 * * * php /home/USUARIO/public_html/cron/prune_scan_events.php`
2. Backup lógico (diario 03:00)
   - `0 3 * * * /usr/bin/mysqldump -uUSER -p'PASS' DBNAME > /home/USUARIO/backups/hacceso_$(date +\%F).sql`
3. Health interno opcional (cada 5 min)
   - `*/5 * * * * php /home/USUARIO/public_html/cron/health_ping.php`

## 9) Siguiente paso recomendado

Con esto listo, el siguiente bloque técnico es:

1. Crear estructura de carpetas PHP simple (`public/api/...`, `src/...`).
2. Implementar `GET /api/device/health`.
3. Implementar `POST /api/device/validate` con transacción y `SELECT ... FOR UPDATE`.
4. Probar con Postman/cURL y luego integrar ESP32.


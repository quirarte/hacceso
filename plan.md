# plan.md — Análisis funcional y lógico del sistema de pases de acceso con QR

## 1) Propósito del sistema

Definir de forma integral la lógica de un sistema de control de acceso para visitantes mediante pases temporales representados por códigos QR, cubriendo:

- Emisión de pases.
- Validación en punto de acceso.
- Gestión operativa (listado, revocación, monitoreo).
- Registro auditable de eventos.
- Comportamiento del dispositivo físico de recepción (incluyendo **ESP** y lector QR **M5Stack**).

> Este documento es **agnóstico de plataforma y lenguaje**. Describe capacidades, reglas de negocio, contratos lógicos y consideraciones de operación.

---

## 2) Objetivos funcionales

1. Permitir que personal autorizado emita pases de visitante con vigencia temporal.
2. Entregar un QR que no exponga datos sensibles.
3. Validar el pase en recepción con respuesta inmediata y homogénea para el visitante.
4. Mostrar información útil al personal de vigilancia sin revelar estado al visitante.
5. Informar en tiempo real al equipo de destino sobre visitantes en camino.
6. Mantener trazabilidad completa de escaneos y decisiones del sistema.
7. Facilitar operación diaria: consulta de pases, estados, historial y revocación.

---

## 3) Actores y responsabilidades

- **Emisor interno**: crea pases y, cuando aplica, los revoca.
- **Visitante**: presenta QR en recepción.
- **Recepción/vigilancia**: observa resultado detallado en pantalla trasera y decide el acceso físico.
- **Equipo destino**: monitorea en tiempo real visitantes próximos a llegar.
- **Dispositivo de recepción**: lee QR, consulta validación, emite señales y muestra resultado.
- **Servicio de validación**: aplica reglas de negocio de vigencia/estado y registra eventos.
- **Fuente de identidad interna de empleados**: permite asociar emisor por UID con su nombre de despliegue.

---

## 4) Datos de negocio definidos

### 4.1 Datos de un pase

- Identificador interno del pase.
- `code_id` aleatorio único (contenido del QR).
- Nombre del visitante.
- Teléfono del visitante (opcional).
- Correo del visitante (opcional).
- Acompañantes esperados (número entero, informativo para recepción).
- Inicio de vigencia (`valid_from`).
- Fin de vigencia (`valid_to`).
- UID de empleado emisor (`issued_by_employee_uid`).
- Fecha/hora de emisión (`issued_at`, asignada por servidor).
- Estado del pase (`ACTIVE`, `USED`, `REVOKED`).
- Primera fecha/hora de uso (`used_at`, cuando aplica).
- Límite de redisplay (`redisplay_until`, cuando aplica).

### 4.2 Datos de evento de escaneo

- Identificador interno del evento.
- `code_id` escaneado.
- Identificador de dispositivo (`device_id`).
- Fecha/hora del escaneo (`scanned_at`).
- Resultado lógico (`OK_FIRST`, `OK_REDISPLAY`, `INEXISTENT`, `EXPIRED`, `REVOKED`, `USED`).
- Latencia de validación (opcional).
- Detalle técnico de error (opcional).

### 4.3 Datos de dispositivo

- `device_id` único.
- Credencial de autenticación almacenada como hash.
- Etiqueta descriptiva.
- Indicador de habilitación.
- Fecha de alta.

### 4.4 Datos de llave de emisor

- Identificador de llave.
- UID de empleado asociado.
- Hash de llave.
- Indicador de habilitación.
- Fecha de alta.

### 4.5 Datos de empleado (fuente interna)

Campos mínimos:

- UID.
- Nombre de despliegue.
- Indicador activo/inactivo (si existe en la fuente).

---

## 5) Flujo funcional de extremo a extremo

## 5.1 Emisión del pase

1. Emisor captura datos del visitante y vigencia.
2. Servicio valida consistencia de datos.
3. Servicio identifica al emisor por su credencial.
4. Servicio crea pase con estado `ACTIVE`.
5. Servicio genera `code_id` aleatorio único.
6. Se construye QR cuyo contenido es **solo** `code_id`.
7. Se genera imagen de QR con texto operativo de uso y vencimiento.
8. Emisor comparte imagen al visitante por el canal que defina la operación.

## 5.2 Presentación y lectura de QR en recepción

1. Visitante coloca el QR en el dock del equipo.
2. Lector QR captura y entrega `code_id` al ESP por interfaz serial.
3. ESP dispara señal frontal estandarizada (LED + beep) al detectar lectura válida de QR.
4. ESP solicita validación al servicio remoto con `device_id` y `code_id`.
5. Servicio responde resultado lógico.
6. ESP actualiza pantalla trasera con resultado correspondiente.
7. Servicio registra el evento de escaneo.

## 5.3 Reglas de decisión en validación

Orden recomendado de evaluación:

1. Si no existe `code_id` -> `INEXISTENT`.
2. Si pase está revocado -> `REVOKED`.
3. Si fuera de vigencia -> `EXPIRED`.
4. Si está `ACTIVE` y en vigencia -> `OK_FIRST`, y transición atómica a `USED` con:
   - `used_at = ahora`
   - `redisplay_until = used_at + 5 minutos`
5. Si está `USED`:
   - Si `ahora <= redisplay_until` -> `OK_REDISPLAY`
   - Si `ahora > redisplay_until` -> `USED`

### 5.3.1 Regla crítica: ventana de redisplay (5 minutos)

- El primer aprobado fija una ventana de 5 minutos.
- Durante esa ventana, reescaneos del mismo código deben seguir marcando aprobado (`OK_REDISPLAY`).
- Fuera de esa ventana, el mismo código debe marcar `USED`.

---

## 6) Comportamiento de interfaz y retroalimentación

### 6.1 Principio de privacidad frente al visitante

El visitante no debe inferir si su pase fue aprobado o rechazado mediante señales frontales.

### 6.2 Señal frontal (visitante)

- Siempre el mismo patrón para cualquier resultado de validación.
- Activación únicamente cuando hubo lectura válida de QR.
- No diferenciar colores, duración ni número de beeps por tipo de resultado.

### 6.3 Pantalla trasera (vigilancia)

#### Reposo

- Mostrar `Activo` cuando la salud de conexión es correcta.
- Mostrar `Inactivo` cuando hay fallas repetidas de salud.

#### Resultado de escaneo

- Mostrar resultado detallado por hasta 60 segundos.
- Si llega un nuevo escaneo antes de 60 segundos, reemplazar inmediatamente.
- Al expirar ese tiempo sin nuevos escaneos, volver a reposo.

#### Mensajería mínima

- `código inexistente`
- `código vencido`
- `código ya utilizado`
- `código revocado`
- En aprobados: nombre del visitante y acompañantes esperados.

---

## 7) Monitoreo en tiempo real

Debe existir una vista operacional en dos bloques:

1. **Visitantes esperados vigentes**:
   - nombre,
   - acompañantes esperados,
   - vencimiento,
   - estado visual de urgencia (por ejemplo: por vencer pronto).

2. **Escaneos en vivo**:
   - fecha/hora,
   - resultado,
   - nombre de visitante cuando aplique.

Regla de visibilidad destacada:

- Cuando el resultado sea `OK_FIRST` o `OK_REDISPLAY`, mostrar de forma prominente al visitante “en camino”.

---

## 8) Funciones administrativas definidas

1. Crear pase.
2. Listar pases con filtros.
3. Ver detalle de pase y eventos asociados.
4. Revocar pase (idempotente: repetir revocación no debe romper flujo).
5. Consultar eventos recientes para operación y monitoreo.
6. Consultar salud para estado operativo del dispositivo.

---

## 9) Contratos lógicos de interfaces de servicio

### 9.1 Salud de dispositivo

- Entrada: solicitud simple de verificación.
- Salida esperada: confirmación de servicio operativo (`ok=true`).
- Uso: determinar estado `Activo/Inactivo` en reposo del dispositivo.

### 9.2 Validación de pase

Entrada mínima:

- `device_id`
- `code_id`
- credencial del dispositivo

Salida mínima:

- `result`
- si aprobado: `visitor_name`, `companions_expected`

### 9.3 Creación de pase

Entrada mínima:

- datos de visitante,
- vigencia,
- opcionales de contacto,
- credencial de emisor.

Salida mínima:

- identificador de pase,
- `code_id`,
- UID del emisor resuelto,
- fecha/hora de emisión.

### 9.4 Listado de pases

- Debe soportar filtros por estado temporal y estado lógico.
- Debe incluir datos base y datos de emisión.
- Debe permitir enriquecer nombre del emisor vía UID.

### 9.5 Eventos recientes

- Debe retornar escaneos más recientes con filtros de tiempo.
- Debe servir tanto para refresco periódico como para actualización en vivo.

### 9.6 Revocación

- Cambia estado a `REVOKED`.
- Si ya lo estaba, responde de forma consistente sin error funcional.

---

## 10) Reglas de seguridad y control

1. Separación de credenciales por rol:
   - credenciales de emisor,
   - credenciales de dispositivo.
2. Almacenamiento de credenciales solo como hash.
3. Generación de credenciales con entropía criptográfica suficiente.
4. Transmisión cifrada extremo a extremo.
5. Restricción de origen para operaciones administrativas.
6. Sin exposición pública directa de tablas de negocio.
7. Registro auditable para toda validación y acción crítica.

---

## 11) Reglas de tiempo y vigencia

1. Transporte de fecha/hora en formato estandarizado.
2. Visualización en zona horaria de negocio definida por operación.
3. `valid_to` se considera inclusivo.
4. `valid_from` puede estar en pasado.
5. Toda decisión de vigencia se hace con reloj del servidor.

---

## 12) Requisitos del QR y su representación

1. El contenido codificado debe ser exclusivamente `code_id`.
2. Debe existir versión visual descargable/compartible.
3. Texto operativo mínimo visible bajo el QR:
   - instrucción de escaneo en recepción,
   - contexto de acceso al destino,
   - fecha/hora de vencimiento real del pase.

---

## 13) Dispositivo físico: ESP + lector QR M5Stack

## 13.1 Componentes contemplados

- Controlador **ESP**.
- Lector QR **M5Stack** por interfaz serial.
- Pantalla trasera para vigilancia.
- Indicador luminoso frontal.
- Señal acústica frontal (o del lector si cumple uniformidad).
- Dock oscuro de lectura para estabilidad de escaneo.

## 13.2 Comportamiento lógico del firmware

1. Inicializar conectividad y periféricos.
2. Ejecutar ciclo periódico de salud para estado en reposo.
3. Escuchar continuamente tramas de lector QR.
4. Al detectar `code_id` válido:
   - disparar señal frontal uniforme,
   - invocar validación remota,
   - mostrar resultado trasero,
   - aplicar temporizador de retorno a reposo.
5. Si falla conectividad en validación:
   - mostrar mensaje operativo (por ejemplo, “No hay internet”),
   - mantener ciclo de recuperación.

## 13.3 Resiliencia recomendada

- Timeout corto por solicitud.
- Reintentos limitados con backoff incremental.
- Estado degradado claramente visible para operación.

---

## 14) Estados y transiciones del pase

### Estados

- `ACTIVE`
- `USED`
- `REVOKED`

### Transiciones

- `ACTIVE -> USED` al primer aprobado en vigencia.
- `ACTIVE -> REVOKED` por acción administrativa.
- `USED -> REVOKED` permitido solo si la política operativa lo requiere (opcional, definir explícitamente).
- No existe transición de regreso a `ACTIVE`.

---

## 15) Casos funcionales que el sistema debe resolver

1. Alta de pase válido y QR utilizable.
2. Escaneo exitoso inicial (`OK_FIRST`).
3. Reescaneo dentro de ventana (`OK_REDISPLAY`).
4. Reescaneo fuera de ventana (`USED`).
5. Código inexistente (`INEXISTENT`).
6. Pase fuera de vigencia (`EXPIRED`).
7. Pase revocado (`REVOKED`).
8. Monitoreo en vivo de escaneos aprobados y rechazados.
9. Conmutación de estado de salud del dispositivo (`Activo/Inactivo`).
10. Manejo de pérdida de conectividad sin bloquear operación local de lectura.

---

## 16) Consideraciones operativas

1. Mantener retención de eventos suficiente para auditoría.
2. Definir responsable de administración de llaves y rotación manual.
3. Definir protocolo de soporte para fallas de conectividad en recepción.
4. Establecer métricas objetivo de latencia/disponibilidad de validación.
5. Documentar procedimiento de alta/baja de dispositivos.
6. Asegurar pruebas periódicas del ciclo completo (emisión -> escaneo -> monitoreo).

---

## 17) Definición de completitud funcional

Se considera completo cuando, como mínimo, se verifica:

1. Emisión correcta con QR y vencimiento visible.
2. Aplicación exacta de reglas de estado y vigencia.
3. Registro de todos los eventos de escaneo.
4. Visualización trasera correcta por resultado.
5. Señal frontal uniforme sin filtrar resultado.
6. Monitoreo en tiempo real de visitantes y escaneos.
7. Revocación operativa e idempotente.
8. Funcionamiento de salud `Activo/Inactivo` en dispositivo.
9. Integración efectiva de **ESP + lector QR M5Stack** en flujo real.


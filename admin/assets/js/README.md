# qrcode.min.js

Este directorio debe contener `qrcode.min.js` (librería `qrcode@1.5.4`) para generar los QR localmente en el navegador, sirviendo el archivo desde tu propio servidor.

## Ruta esperada por la app

- `/admin/assets/js/qrcode.min.js`

## ¿De dónde obtenerlo?

El archivo minificado normalmente no vive en el repo fuente de GitHub; se distribuye en el paquete de npm.

### Opción 1: descargar el paquete npm y extraer `build/qrcode.min.js`

1. Descarga: `https://registry.npmjs.org/qrcode/-/qrcode-1.5.4.tgz`
2. Extrae el `.tgz`.
3. Copia `package/build/qrcode.min.js` a este directorio como `qrcode.min.js`.

### Opción 2: usar un mirror CDN del mismo paquete

- `https://cdn.jsdelivr.net/npm/qrcode@1.5.4/build/qrcode.min.js`
- `https://unpkg.com/qrcode@1.5.4/build/qrcode.min.js`

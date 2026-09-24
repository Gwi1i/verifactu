# Contexto del proyecto para Claude Code

Este fichero resume el estado del proyecto y las decisiones tomadas para que
cualquier sesión (en cualquier PC) pueda continuar el trabajo sin la conversación original.

## Qué es

Herramienta y plugin para el formato VeriFactu (RD 1007/2023, Orden HAC/1177/2024) de la AEAT:

- **Validador web** (`index.html`): 100% en el navegador. Comprueba el formato de un registro de
  facturación, calcula la huella SHA-256 encadenada (documento técnico AEAT v0.1.2) y genera la URL
  del código QR. Publicado en https://gwi1i.github.io/verifactu/ (GitHub Pages, rama `main`, raíz).
- **Plugin de WooCommerce** `gwii-invoice-hash-for-woocommerce/` (GPLv2, versión 1.1.2): calcula la
  huella encadenada y la URL del QR en cada pedido completado. Aprobado en WordPress.org el
  23/09/2026, slug `gwii-invoice-hash-for-woocommerce`.
- **Gumroad** https://gwindor7.gumroad.com/l/tnvuko: producto publicado (29 €) que vende soporte y
  actualizaciones, no una licencia. Contiene el zip del plugin y la guía de soporte.

## Estructura

| Ruta | Contenido |
| --- | --- |
| `index.html`, `robots.txt`, `sitemap.xml`, `vercel.json`, `gumroad_cover.jpg` | Sitio publicado (raíz del repo = GitHub Pages) |
| `gwii-invoice-hash-for-woocommerce/` | Código fuente del plugin (php, readme.txt, LICENSE, INSTRUCCIONES_Y_SOPORTE.txt) |
| `gwii-invoice-hash-for-woocommerce.zip` | Plugin empaquetado que descarga la landing y que está en Gumroad |
| `tests/test_plugin.php` | 131 pruebas del plugin con stubs de WordPress/WooCommerce (`php tests/test_plugin.php`) |
| `test_verifactu_spec.py` | Vectores AEAT, URL del QR y dígito de control de NIF en Python |
| `svn-deploy/` | Estructura `trunk/` + `assets/` y script `publicar_wporg.ps1` para el SVN de WordPress.org |
| `wp-org-assets/` | Banners e iconos para WordPress.org (sin sellos ni "certificado") |
| `ESTRATEGIA_POSICIONAMIENTO.md` | Playbook de marketing |
| `aegis-auditor/` | Proyecto ajeno (auditor de contratos Solidity). Ignorado por git a propósito |

## Decisiones y hechos que no se deducen del código

- **URL del QR**: `https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR` con `nif`,
  `numserie`, `fecha` (DD-MM-AAAA) e `importe`. La huella NO va en el QR. La versión 1.0.0 usaba
  una URL inventada; ya corregida.
- **Nombre**: el plugin se llamaba "VeriFactu Express". WordPress.org lo rechazó por implicar
  afiliación con AEAT/WooCommerce. Nombre definitivo: "Gwii Invoice Hash for WooCommerce".
  No volver a usar "oficial", "certificado por la AEAT" ni sellos en textos o imágenes.
- **Alcance honesto**: el plugin NO genera ni firma el XML, NO remite a la AEAT, NO lleva registro
  de eventos ni declaración responsable, NO cubre reembolsos parciales. Todos los textos lo dicen.
- **Prefijos**: opciones `gwiih_*` (migración automática desde `verifactu_*` de 1.0.x); las claves
  de metadatos de pedido siguen siendo `_verifactu_*` porque están documentadas para integraciones.
- **Text domain** = slug: `gwii-invoice-hash-for-woocommerce`. Interfaz en español a propósito;
  readme y cabeceras en inglés porque lo exige WordPress.org.
- **Plazos VeriFactu** (RDL 15/2025): 1 de enero de 2027 (Impuesto sobre Sociedades) y 1 de julio
  de 2027 (resto).
- **Cuentas**: WordPress.org usuario `gwii`; GitHub `Gwi1i/verifactu`; Gumroad `gwindor7`.

## Pendiente

1. ~~Publicar en el SVN de WordPress.org~~ Hecho el 24/09/2026: trunk + assets (r3711853) y
   `tags/1.1.0` (r3711855). Versión 1.1.1 (bloqueo alternativo para SQLite): r3711874 + `tags/1.1.1` (r3711875). Versión 1.1.2 (aviso de zona horaria): r3711933 + `tags/1.1.2` (r3711934). Página pública: https://wordpress.org/plugins/gwii-invoice-hash-for-woocommerce/
2. Cada versión nueva: subir el zip también a Gumroad (pestaña Content), actualizar `Stable tag`,
   `Version`, changelog y `svn-deploy/trunk`, y publicar con
   `powershell -ExecutionPolicy Bypass -File svn-deploy/publicar_wporg.ps1` (cambiar `$version`).
   El commit SVN pide la contraseña de SVN: la escribe siempre el usuario en su terminal.
3. ~~Probar en un WordPress real con HPOS~~ Hecho el 24/09/2026 en WordPress Playground (SQLite): destapó el fallo de GET_LOCK corregido en 1.1.1. Falta probarlo en un hosting con MySQL.

## Cómo trabajar

- Cambios en el plugin: editar `gwii-invoice-hash-for-woocommerce/`, pasar `php tests/test_plugin.php`,
  reconstruir el zip (carpeta `gwii-invoice-hash-for-woocommerce/` + `languages/index.php`) y copiar
  el php/readme a `svn-deploy/trunk/`.
- Cambios en el validador: `index.html` es un único fichero; probar sirviéndolo por localhost
  (Web Crypto no funciona en `data:`).
- Commits: el usuario pide explícitamente commit y push. Identidad configurada en el repo: `gwii`.
- Herramientas en Windows: `winget install PHP.PHP.8.3`, `Python.Python.3.12` (con Pillow: `pip install pillow`;
  los scripts que generan banners, iconos y portada de Gumroad están en `tools/`), `TortoiseSVN.TortoiseSVN --custom "ADDLOCAL=ALL"`.

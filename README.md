# 🛡️ VeriFactu Linter: huella SHA-256 y código QR (AEAT v0.1.2)

> Herramienta de código abierto y 100% client-side para comprobar el formato de un registro de facturación, calcular su huella SHA-256 encadenada y generar la URL del código QR según la **Orden HAC/1177/2024** y el **RD 1007/2023**.

🌐 **Herramienta web:** [https://gwi1i.github.io/verifactu/](https://gwi1i.github.io/verifactu/)

---

## Qué hace

* **Cálculo de huella** con la cadena canónica de 8 campos del documento técnico AEAT v0.1.2 (27/08/2024):
  `IDEmisorFactura&NumSerieFactura&FechaExpedicionFactura&TipoFactura&CuotaTotal&ImporteTotal&Huella&FechaHoraHusoGenRegistro`
* **Comprobaciones de formato:** dígito de control de NIF, NIE y CIF, fecha `DD-MM-AAAA`, marca temporal ISO 8601 con huso horario, importes con dos decimales, huella anterior de 64 hex y referencia obligatoria en rectificativas (R1 a R5).
* **URL del código QR** según el capítulo VIII de la Orden HAC/1177/2024:
  `https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=...&numserie=...&fecha=...&importe=...`
* **Todo en el navegador** mediante Web Crypto API. Ningún dato sale de tu equipo.

## Qué NO hace

* No genera el XML del registro de facturación ni lo firma.
* No remite nada a la AEAT ni comprueba que el registro haya sido remitido.
* No lleva registro de eventos ni emite la declaración responsable del sistema informático de facturación.

Es una utilidad técnica de comprobación, no un sistema de facturación adaptado al RD 1007/2023.

---

## Vectores de prueba de la AEAT

El cálculo coincide con los tres ejemplos del apartado 6 del documento técnico v0.1.2:

| Caso | Descripción | Huella esperada |
| :--- | :--- | :--- |
| 1 | Primer registro de alta (sin huella anterior) | `3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60` |
| 2 | Registro de alta encadenado | `F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97` |
| 3 | Registro de anulación encadenado | `177547C0D57AC74748561D054A9CEC14B4C4EA23D1BEFD6F2E69E3A388F90C68` |

Para ejecutar los tests en local:

```bash
python test_verifactu_spec.py
```

El plugin tiene su propio banco de pruebas en PHP (sin necesidad de WordPress: simula opciones, pedidos, bloqueo y zona horaria) que cubre vectores AEAT, URL del QR, NIF, alta, anulación, idempotencia, bloqueo fallido y salida HTML:

```bash
php tests/test_plugin.php
```

---

## Plugin para WooCommerce: Gwii Invoice Hash

En `gwii-invoice-hash-for-woocommerce/` (y empaquetado en `gwii-invoice-hash-for-woocommerce.zip`) hay un plugin GPLv2, desarrollado de forma independiente y sin afiliación con la AEAT ni con WooCommerce, que:

* Calcula la huella SHA-256 encadenada de cada pedido al pasar a **Completado**, con bloqueo en base de datos para que dos pedidos simultáneos no rompan la cadena.
* Asigna un **número de factura correlativo** independiente del ID del pedido.
* Usa la **zona horaria de WordPress** para la marca temporal (`+01:00` / `+02:00` en España).
* Genera un **registro de anulación** encadenado si un pedido registrado se cancela o se reembolsa por completo.
* Construye la **URL del QR** con los cuatro parámetros oficiales.
* Es compatible con **HPOS** y con WooCommerce PDF Invoices & Packing Slips.

Metadatos guardados en cada pedido: `_verifactu_num_serie`, `_verifactu_hash`, `_verifactu_hash_anterior`, `_verifactu_canonical`, `_verifactu_fecha_expedicion`, `_verifactu_fecha_hora_gen`, `_verifactu_qr_url` y, si procede, `_verifactu_anulacion_hash`.

**Limitaciones:** no genera ni remite el registro XML a la AEAT, no firma, no cubre reembolsos parciales (rectificativas) y no incrusta la imagen del QR en el PDF.

### Instalación

1. Instálalo desde el directorio oficial: [wordpress.org/plugins/gwii-invoice-hash-for-woocommerce](https://wordpress.org/plugins/gwii-invoice-hash-for-woocommerce/) (Plugins > Añadir nuevo > buscar "Gwii Invoice Hash"), o descarga [gwii-invoice-hash-for-woocommerce.zip](https://gwi1i.github.io/verifactu/gwii-invoice-hash-for-woocommerce.zip).
2. En WordPress: **Plugins > Añadir nuevo > Subir plugin**.
3. Actívalo y configura el NIF emisor en **WooCommerce > Huella VeriFactu**.

### Licencia y soporte

El plugin es **GPLv2** y puede usarse libremente, también en tiendas comerciales. Si quieres soporte y actualizaciones cuando cambie la normativa, hay un pago único de 29€: 👉 [Soporte y actualizaciones](https://gwindor7.gumroad.com/l/tnvuko)

---

## Plazos

Según el Real Decreto-ley 15/2025, la obligación de usar sistemas de facturación adaptados entra en vigor el **1 de enero de 2027** para contribuyentes del Impuesto sobre Sociedades y el **1 de julio de 2027** para el resto.

## Licencia

[GNU General Public License v2.0](LICENSE). El código se proporciona tal cual, sin garantía.

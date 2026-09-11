# 🛡️ VeriFactu Linter & Diagnostic Tool (AEAT v0.1.2)

> **Herramienta comunitaria de código abierto y 100% client-side** para la validación de registros de facturación, cálculo canónico de huella SHA-256 y generación de código QR oficial bajo la **Orden HAC/1177/2024** y el **RD 1007/2023 (Ley Antifraude)** de la Agencia Tributaria (AEAT).

🌐 **Acceso a la herramienta web en vivo:**  
👉 **[https://gwi1i.github.io/verifactu/](https://gwi1i.github.io/verifactu/)**

---

## 🚀 Características Principales

* **🔒 100% Client-Side (Privacidad Total):** Todo el procesamiento criptográfico y la validación se ejecutan en la memoria RAM del navegador mediante la [Web Crypto API](https://developer.mozilla.org/en-US/docs/Web/API/Web_Crypto_API). **Ningún dato fiscal, NIF ni importe viaja a servidores externos.**
* **🏛️ Certificado contra Vectores Oficiales AEAT:** El algoritmo de hashing ha sido contrastado bit a bit contra los vectores de prueba oficiales del documento técnico *AEAT v0.1.2 (27/08/2024)* (Casos 1, 2 y 3).
* **⛓️ Encadenamiento Canónico Oficial (8 Campos):**
  Implementa el orden estricto de concatenación canónica fijado por la Orden HAC/1177/2024:
  `IDEmisorFactura|NumSerieFactura|FechaExpedicionFactura|TipoFactura|CuotaTotal|ImporteTotal|Huella|FechaHoraHusoGenRegistro`
* **🚨 Detector de Errores Comunes de Hacienda:**
  * **Error 1109:** Algoritmo de control y checksum de NIF / NIE / CIF de la AEAT.
  * **Error 1154:** Validación de referencia obligatoria en facturas rectificativas (R1 a R5).
  * **Error de Huso Horario:** Formato estricto ISO 8601 (`YYYY-MM-DDThh:mm:ss+TZD`).
* **📱 Generador de Códigos QR Reglamentarios:** Construye la URL oficial para el cotejo directo en la Sede Electrónica de la Agencia Tributaria.
* **🛒 Plugin WooCommerce Incluido:** Incluye en el repositorio el paquete `verifactu-express.zip` listo para instalar en tiendas online WordPress.

---

## 🏛️ Verificación con Vectores Oficiales de la AEAT

El cálculo determinista SHA-256 coincide exactamente con los ejemplos oficiales publicados por la Agencia Tributaria:

| Caso de Prueba AEAT | Descripción | Huella SHA-256 Oficial Esperada | Estado |
| :--- | :--- | :--- | :---: |
| **Caso 1** | Primer Registro de Alta (Sin huella anterior) | `3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60` | **100% OK ✅** |
| **Caso 2** | Registro de Alta Encadenado | `F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97` | **100% OK ✅** |
| **Caso 3** | Registro de Anulación Encadenado | `177547C0D57AC74748561D054A9CEC14B4C4EA23D1BEFD6F2E69E3A388F90C68` | **100% OK ✅** |

---

## 📦 Plugin WooCommerce: VeriFactu Express

En este repositorio se incluye el plugin **VeriFactu Express for WooCommerce** (`verifactu-express.zip`):
* Compatible con WooCommerce moderno y **HPOS** *(High-Performance Order Storage)*.
* Guarda automáticamente la huella SHA-256 encadenada al completarse cada pedido.
* Añade la caja de verificación en el panel de administración y el enlace oficial de cotejo para el cliente.

### Instalación:
1. Descarga el archivo [verifactu-express.zip](https://gwi1i.github.io/verifactu/verifactu-express.zip).
2. En tu WordPress, ve a **Plugins > Añadir nuevo > Subir plugin**.
3. Actívalo y configura tu NIF emisor en **WooCommerce > VeriFactu AEAT**.

### Modalidades de Licencia:
* **Versión Community (GPLv2):** Descarga gratuita del `.zip` en este repositorio para entornos de prueba.
* **Licencia Comercial Pro:** Soporte de actualizaciones para futuras revisiones de la AEAT y uso comercial garantizado: 👉 **[Comprar Licencia Comercial Pro (29€)](https://gwindor7.gumroad.com/l/tnvuko)**

---

## 🧪 Ejecutar Tests Técnicos en Local

Puedes verificar los algoritmos en tu propia máquina ejecutando el test suite en Python:

```bash
python test_verifactu_spec.py
```

---

## 📄 Licencia

Este proyecto está bajo licencia [GNU General Public License v2.0 (GPLv2)](LICENSE).
El código se proporciona **TAL CUAL (AS-IS)** para fines de testing, desarrollo y cumplimiento técnico.

# 🚀 Guía Rápida de Despliegue Público (100% Gratuito)

La raíz de este repositorio contiene todo lo necesario para poner la herramienta online en un servidor público con certificado SSL (HTTPS) gratuito en menos de 1 minuto.

---

### Opción 1: Netlify Drop (La más rápida, sin comandos, 30 segundos)
1. Entra en tu navegador a: **[https://app.netlify.com/drop](https://app.netlify.com/drop)**
2. Arrastra la carpeta del repositorio directamente a la ventana del navegador.
3. ¡Listo! Te dará una URL pública del tipo `https://verifactu-linter.netlify.app` funcionando al instante con descarga del plugin incluida.

---

### Opción 2: Vercel (Rendimiento ultra-rápido)
1. Entra en **[https://vercel.com/new](https://vercel.com/new)**.
2. Si tienes instalada la herramienta de Vercel en la terminal, basta con ejecutar en esta carpeta:
   ```bash
   npx vercel
   ```
3. Sigue las 3 preguntas en pantalla (pulsar Enter a todo) y tu web estará online en segundos en `https://tu-proyecto.vercel.app`.

---

### Opción 3: GitHub Pages
1. Crea un repositorio público en GitHub (por ejemplo, `verifactu-linter`).
2. Sube los archivos de la raíz (`index.html`, `gwii-invoice-hash-for-woocommerce.zip`, `robots.txt`, `sitemap.xml`).
3. En GitHub, ve a **Settings > Pages > Branch: main > Save**.
4. En 60 segundos tu herramienta estará disponible en: `https://tu-usuario.github.io/verifactu-linter`.

---

### Archivos incluidos en este paquete:
* **`index.html`**: La aplicación web del Validador y Linter VeriFactu (contrastado con los vectores oficiales de la AEAT v0.1.2).
* **`gwii-invoice-hash-for-woocommerce.zip`**: El plugin completo de WooCommerce listo para ser descargado por los usuarios desde el banner superior.
* **`vercel.json`**: Cabeceras de seguridad y configuración para servir el archivo zip correctamente.
* **`robots.txt`** y **`sitemap.xml`**: Para que Googlebot indexe la página en los primeros puestos de búsqueda de VeriFactu.

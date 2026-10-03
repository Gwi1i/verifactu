---
title: "Implementando la huella SHA-256 de VeriFactu: 6 detalles que rompen el hash (y uno que rompe la cadena)"
published: true
# Publicado el 03/10/2026: https://dev.to/a_molina_b9d7a17c33863229/implementando-la-huella-sha-256-de-verifactu-6-detalles-que-rompen-el-hash-y-uno-que-rompe-la-1d82
description: "Lo que aprendí implementando la huella encadenada de VeriFactu para WooCommerce: el formato exacto, los ejemplos de la AEAT como tests y los fallos que no salen en la documentación."
tags: javascript, php, spanish, webdev
---

Desde el 1 de enero de 2027 las sociedades en España tienen que facturar con un software adaptado a **VeriFactu**, y desde el 1 de julio de 2027 el resto, autónomos incluidos. Una de las piezas técnicas del reglamento es la **huella**: un SHA-256 de cada registro de facturación que incluye la huella del registro anterior, de modo que los registros quedan encadenados y cualquier manipulación posterior se nota.

Sobre el papel es trivial: concatenar unos campos y aplicar SHA-256. En la práctica, la primera vez que lo implementé no me cuadró con los ejemplos de la AEAT, y después me encontré un fallo de concurrencia que ningún test unitario había detectado. Te cuento lo que aprendí.

> Aviso: soy el autor de [Gwii Invoice Hash](https://wordpress.org/plugins/gwii-invoice-hash-for-woocommerce/), un plugin gratuito (GPLv2) para WooCommerce que calcula esta huella. No es un sistema VeriFactu completo, y lo explico más abajo.

## La cadena que hay que firmar

Para un registro de alta (la factura) son 8 campos, siempre en este orden, como `nombre=valor` separados por `&`:

```text
IDEmisorFactura=89890001K&NumSerieFactura=12345678/G33&FechaExpedicionFactura=01-01-2024&TipoFactura=F1&CuotaTotal=12.35&ImporteTotal=123.45&Huella=&FechaHoraHusoGenRegistro=2024-01-01T19:20:30+01:00
```

Se pasa a bytes en UTF-8, se aplica SHA-256 y el resultado va en hexadecimal **en mayúsculas**. Ese es el caso 1 del documento técnico de la AEAT, y el resultado tiene que ser:

```text
3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60
```

La implementación mínima, con Web Crypto (funciona en el navegador y en Node.js 18+):

```js
async function huellaAlta(r) {
  const cadena = [
    ['IDEmisorFactura', r.nif],
    ['NumSerieFactura', r.numSerie],
    ['FechaExpedicionFactura', r.fecha],
    ['TipoFactura', r.tipo],
    ['CuotaTotal', r.cuota],
    ['ImporteTotal', r.importe],
    ['Huella', r.huellaAnterior ?? ''],
    ['FechaHoraHusoGenRegistro', r.fechaHora],
  ].map(([k, v]) => `${k}=${String(v).trim()}`).join('&');

  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(cadena));
  return [...new Uint8Array(digest)]
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('')
    .toUpperCase();
}
```

## Los ejemplos de la AEAT son tus tests

El documento técnico trae tres casos encadenados: un primer alta, un segundo alta que lleva la huella del primero y una anulación del segundo. Antes de escribir una sola línea más, conviértelos en tests:

```js
const caso1 = {
  nif: '89890001K', numSerie: '12345678/G33', fecha: '01-01-2024', tipo: 'F1',
  cuota: '12.35', importe: '123.45', huellaAnterior: '',
  fechaHora: '2024-01-01T19:20:30+01:00',
};
const h1 = await huellaAlta(caso1);
console.assert(h1 === '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60');

const caso2 = {
  ...caso1, numSerie: '12345679/G34', huellaAnterior: h1,
  fechaHora: '2024-01-01T19:20:35+01:00',
};
console.assert(await huellaAlta(caso2) === 'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97');
```

En el plugin tengo 131 tests, y estos tres casos son la base de todos: si algún cambio los rompe, sé que he roto el formato.

## 6 detalles que rompen el hash

SHA-256 no perdona: un carácter distinto cambia la huella entera. Todos estos ejemplos parten del caso 1 y cambian **solo un detalle**:

| Cambio | Huella resultante |
| --- | --- |
| Ninguno (correcto) | `3C464DAF…F12F60` |
| `ImporteTotal=123.40` | `A6E02E87…830372` |
| `ImporteTotal=123.4` | `435A16D5…13F1AE` |
| `ImporteTotal=123,40` | `3B1369D7…61D00C` |
| Misma hora en UTC: `18:20:30+00:00` | `84389B4E…F1C5D3` |

1. **El importe es texto, no un número.** `123.4` y `123.40` valen lo mismo, pero dan huellas distintas. Formatea siempre con punto y dos decimales (`toFixed(2)`, `number_format($x, 2, '.', '')`) y usa exactamente ese texto en el XML del registro.
2. **La fecha de expedición va como `DD-MM-AAAA`.** Tu base de datos casi seguro la guarda como `AAAA-MM-DD`.
3. **El huso horario forma parte del texto.** Es el que más me costó. El mismo instante en `+01:00` y en UTC da huellas distintas. Si tu servidor trabaja en UTC (WordPress, por ejemplo, viene así en una instalación nueva), tienes que formatear la hora en la zona de España (Europe/Madrid) y con el desfase explícito.
4. **El campo `Huella` va siempre, aunque esté vacío.** En el primer registro la cadena lleva `&Huella=&`. Omitirlo es un error clásico.
5. **No codifiques nada como URL.** La serie `12345678/G33` va con su barra. La codificación (`%2F`) es solo para la URL del código QR, que es otra cosa y no lleva la huella.
6. **Mayúsculas.** Casi todas las librerías devuelven el hexadecimal en minúsculas. Y como la huella se encadena, una huella en minúsculas contamina todos los registros siguientes.

## El fallo que rompe la cadena: la concurrencia

Este no lo encuentra ningún test unitario. Para calcular la huella de un registro necesitas la huella del **último** registro. Si dos pedidos se completan a la vez, los dos leen la misma "última huella" y la cadena se bifurca: dos registros apuntan al mismo anterior.

La solución obvia es un bloqueo mientras lees la última huella, calculas y guardas la nueva. En MySQL/MariaDB, `GET_LOCK()` hace justo eso:

```php
$ok = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $nombre, 10));
```

`GET_LOCK` devuelve `'1'` si consigue el bloqueo y `'0'` si se agota el tiempo. Funcionaba perfectamente en los tests. Hasta que probé el plugin en **WordPress Playground**, que usa SQLite con una capa que traduce el SQL de MySQL: la huella no se generaba nunca. La razón es que el traductor no conoce `GET_LOCK` y la consulta devolvía la cadena `'1=1'`, que no es `'1'`, así que el plugin entendía que no había conseguido el bloqueo.

La corrección fue tratar como "no soportado" todo lo que no sea `'1'` o `'0'`, y usar en ese caso un bloqueo portable: insertar una fila con clave única en la tabla de opciones.

```php
// INSERT IGNORE sobre una clave única: solo un proceso consigue insertar la fila.
$insertado = $wpdb->query($wpdb->prepare(
    "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')",
    'gwiih_chain_lock',
    time()
));
// 1 = bloqueo conseguido; 0 = lo tiene otro proceso. Se libera con un DELETE.
```

Dos detalles importantes. Primero, no sirve `add_option()` de WordPress, porque usa `ON DUPLICATE KEY UPDATE` y sobrescribe la fila de otro proceso en vez de fallar. Segundo, el bloqueo necesita caducar (yo uso 60 segundos), por si un proceso muere sin liberarlo.

La lección: **prueba en la base de datos real de tus usuarios, no solo en la tuya**. Playground es gratis y es justo el entorno que usa el botón "Test it on Playground" de WordPress.org.

## Lo que la huella no es

Calcular bien la huella es necesario, pero no suficiente para cumplir VeriFactu. Un sistema adaptado también tiene que generar el registro en el XML de la AEAT, remitirlo (modalidad VERI\*FACTU) o firmarlo y llevar un registro de eventos (modalidad no VERI\*FACTU), poner el código QR en la factura y contar con la declaración responsable del fabricante. Mi plugin hace la huella, la URL del QR y la numeración, y no hace el resto. Lo digo porque hay mucho producto que se anuncia como "compatible con VeriFactu" y conviene saber qué cubre cada uno.

## Recursos

- **Validador en el navegador**: calcula la huella y la URL del QR de cualquier registro y marca los errores de formato. Trae cargados los casos de la AEAT y no envía datos a ningún servidor: [gwi1i.github.io/verifactu](https://gwi1i.github.io/verifactu/)
- **Guía paso a paso de la huella**, con el caso de anulación y código en PHP y Python: [Cómo se calcula la huella SHA-256 de VeriFactu](https://gwi1i.github.io/verifactu/guias/huella-verifactu-sha256.html)
- **Errores típicos** cuando la huella no coincide: [La huella VeriFactu no coincide](https://gwi1i.github.io/verifactu/guias/huella-verifactu-no-coincide.html)
- **Código del plugin** (GPLv2): [github.com/Gwi1i/verifactu](https://github.com/Gwi1i/verifactu)

Si estás implementando VeriFactu y te has encontrado otro detalle que rompe la huella, cuéntamelo en los comentarios y lo añado a la guía.

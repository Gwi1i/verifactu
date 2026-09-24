=== Gwii Invoice Hash for WooCommerce ===
Contributors: gwii
Donate link: https://gwi1i.github.io/verifactu/
Tags: verifactu, aeat, invoice, hash, woocommerce
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Computes the SHA-256 chained invoice hash and the AEAT QR verification URL (Spanish VeriFactu format) for each completed WooCommerce order.

== Description ==

**Gwii Invoice Hash for WooCommerce** is a small utility for Spanish online shops. When an order is completed it computes, inside your own WordPress server, the SHA-256 chained invoice hash ("huella") and the QR verification URL of the Spanish Tax Agency (AEAT), following the AEAT technical document v0.1.2 and chapter VIII of Orden HAC/1177/2024 (the "VeriFactu" invoicing regulation).

This plugin is developed independently by an individual developer. It is not affiliated with, endorsed by or certified by the Agencia Estatal de Administración Tributaria (AEAT), Automattic or WooCommerce. "VeriFactu" refers to the AEAT regulation the plugin implements; "WooCommerce" refers to the e-commerce plugin it extends.

### What it does

* **SHA-256 chained hash** built from the 8-field canonical string defined in the specification. The calculation matches the three official test vectors published by the AEAT.
* **Sequential invoice numbering**, independent from the WooCommerce order ID.
* **Database lock** (MySQL `GET_LOCK`) so that two orders completed at the same time cannot break the chain.
* **Timestamps in the WordPress time zone** (+01:00 / +02:00 in Spain).
* **Cancellation record** chained to the previous hash when a registered order is cancelled or fully refunded.
* **QR verification URL** with the four official parameters: `nif`, `numserie`, `fecha` and `importe`.
* **HPOS compatible** and integrates with WooCommerce PDF Invoices & Packing Slips (adds text rows with the invoice number, hash and URL).
* **No external services:** no API keys, no data sent to third parties.

### What it does NOT do

* It does not generate or sign the XML invoice record.
* It does not submit records to the AEAT.
* It does not keep the event log nor provide the manufacturer's responsible declaration required by RD 1007/2023.
* It does not handle partial refunds (corrective invoices).
* It does not embed the QR image in the PDF (only the URL and the hash as text).

On its own it does not turn a shop into an invoicing system compliant with RD 1007/2023. It is a calculation building block you can use as a base or to verify another implementation.

The admin interface is currently in Spanish, as the regulation only applies to businesses in Spain.

== Installation ==

1. Upload the `gwii-invoice-hash-for-woocommerce` folder to `/wp-content/plugins/`, or upload the `.zip` from **Plugins > Add New > Upload Plugin**.
2. Activate the plugin. WooCommerce must be installed and active.
3. Go to **WooCommerce > Huella VeriFactu**.
4. Enter the issuer tax ID (NIF, checked with its control digit), the invoice series prefix and the default invoice type, then save.
5. Every order that changes to **Completed** will get its invoice number, chained hash and QR URL, stored as order meta and shown in the order edit screen.

== Frequently Asked Questions ==

= Does this plugin make my shop compliant with Orden HAC/1177/2024? =
No. It implements the hash calculation and the QR URL exactly as defined by the regulation, and the calculation is checked against the official AEAT test vectors. But the regulation also requires generating the XML record, signing it or submitting it to the AEAT, keeping an event log and having a responsible declaration from the software manufacturer. None of that is done by this plugin.

= When is the hash generated? =
When the order changes to the Completed status. The invoice date and the timestamp are taken at that moment, in the time zone configured in WordPress.

= What happens if I cancel or refund a registered order? =
A chained cancellation record is generated and stored in the order. Partial refunds do not generate any record.

= Is it compatible with PDF invoice plugins? =
Yes. The data is stored as order meta (`_verifactu_num_serie`, `_verifactu_hash`, `_verifactu_qr_url`, among others) and is automatically added as text rows in WooCommerce PDF Invoices & Packing Slips.

= I was using version 1.0.x under the previous name. Will my chain continue? =
Yes. On activation the plugin copies the settings and the last hash saved by version 1.0.x, so the chain continues without gaps.

== Changelog ==

= 1.1.0 =
* Renamed to Gwii Invoice Hash for WooCommerce. Option names now use the `gwiih_` prefix; previous settings and the last hash are migrated automatically.
* Fixed the QR URL: it now uses https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR with the `nif`, `numserie`, `fecha` and `importe` parameters.
* Timestamps use the WordPress time zone instead of UTC.
* Database lock (GET_LOCK) protects the hash chain against concurrent orders.
* Sequential invoice numbering independent from the order ID.
* Cancellation record when a registered order is cancelled or fully refunded.
* Tax ID control digit is validated when saving settings.
* Fixed the order meta box when HPOS is enabled.
* Added the `Requires Plugins: woocommerce` header.
* Rewritten descriptions: the plugin no longer presents itself as a complete compliance solution.

= 1.0.0 =
* Initial release.

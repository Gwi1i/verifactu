<?php
/**
 * Pruebas del plugin Gwii Invoice Hash sin WordPress real.
 *
 * Simula las funciones de WordPress y WooCommerce que usa el plugin (opciones,
 * pedidos, bloqueo de base de datos, zona horaria) y comprueba el comportamiento
 * completo: vectores de la AEAT, URL del QR, NIF, alta, anulación, idempotencia,
 * bloqueo fallido y salida HTML.
 *
 * Ejecutar:  php tests/test_plugin.php
 */

declare(strict_types=1);

/* ------------------------------------------------------------------ */
/*  Stubs de WordPress                                                 */
/* ------------------------------------------------------------------ */

define('ABSPATH', __DIR__ . '/');
date_default_timezone_set('UTC'); // WordPress fija PHP en UTC.

$GLOBALS['wp_options'] = array();
$GLOBALS['wp_hooks']   = array();
$GLOBALS['wp_settings_errors'] = array();
$GLOBALS['wp_timezone'] = 'Europe/Madrid';
$GLOBALS['wp_now'] = '2026-09-18 12:00:00'; // hora local de la tienda
$GLOBALS['wc_orders'] = array();
$GLOBALS['wc_hpos'] = false;

function add_action($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['wp_hooks'][$hook][] = $cb; }
function __($t, $d = null) { return $t; }
function esc_html__($t, $d = null) { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); }
function esc_html_e($t, $d = null) { echo esc_html__($t); }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES, 'UTF-8'); }
function wp_kses_post($t) { return $t; }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function get_option($k, $default = false) { return array_key_exists($k, $GLOBALS['wp_options']) ? $GLOBALS['wp_options'][$k] : $default; }
function update_option($k, $v, $autoload = null) { $GLOBALS['wp_options'][$k] = $v; return true; }
function add_settings_error($s, $c, $m, $t = 'error') { $GLOBALS['wp_settings_errors'][] = $m; }
function settings_errors($s = '') {}
function admin_url($p = '') { return 'https://tienda.test/wp-admin/' . $p; }
function current_user_can($c) { return true; }
function add_submenu_page(...$a) {}
function register_setting(...$a) {}
function add_meta_box($id, $title, $cb, $screen, $ctx, $prio) { $GLOBALS['wp_meta_box_screen'] = $screen; }
function submit_button($t) { echo '<button>' . esc_html($t) . '</button>'; }
function settings_fields($g) {}
function do_settings_sections($g) {}
function selected($a, $b) { echo $a === $b ? 'selected' : ''; }
function current_datetime() { return new DateTimeImmutable($GLOBALS['wp_now'], new DateTimeZone($GLOBALS['wp_timezone'])); }
function wc_get_page_screen_id($t) { return 'woocommerce_page_wc-orders'; }
function wc_get_order($id) { return isset($GLOBALS['wc_orders'][$id]) ? $GLOBALS['wc_orders'][$id] : false; }
if (!function_exists('mb_substr')) { function mb_substr($s, $a, $b = null) { return $b === null ? substr($s, $a) : substr($s, $a, $b); } }

class WC_Order {
    public $id; private $meta = array(); public $notes = array(); private $total; private $tax; public $saves = 0;
    public function __construct($id, $total, $tax) { $this->id = $id; $this->total = $total; $this->tax = $tax; }
    public function get_id() { return $this->id; }
    public function get_total() { return $this->total; }
    public function get_total_tax() { return $this->tax; }
    public function get_meta($k) { return isset($this->meta[$k]) ? $this->meta[$k] : ''; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function save() { $this->saves++; }
    public function add_order_note($n) { $this->notes[] = $n; }
}

class FakeWPDB {
    public $prefix = 'wp_'; public $options = 'wp_options'; public $lock_result = '1'; public $locks = 0; public $releases = 0; public $last_lock_name = '';
    // Bloqueo por fila (SQLite): valor de la fila gwiih_chain_lock o null si no existe.
    public $row_lock = null; public $row_inserts = 0; public $row_deletes = 0;
    public function prepare($q, ...$args) { return vsprintf(str_replace(array('%s', '%d'), array("'%s'", '%d'), $q), $args); }
    public function get_var($q) { if (strpos($q, 'GET_LOCK') !== false) { $this->locks++; preg_match("/GET_LOCK\('([^']+)'/", $q, $m); $this->last_lock_name = $m[1]; return $this->lock_result; } return null; }
    public function query($q) {
        if (strpos($q, 'RELEASE_LOCK') !== false) { $this->releases++; return 1; }
        if (strpos($q, 'INSERT IGNORE') !== false) { $this->row_inserts++; if ($this->row_lock !== null) { return 0; } preg_match("/VALUES \('[^']+', (\d+)/", $q, $m); $this->row_lock = (int) $m[1]; return 1; }
        if (strpos($q, 'DELETE') !== false) {
            if (preg_match('/option_value < (-?\d+)/', $q, $m)) { if ($this->row_lock !== null && $this->row_lock < (int) $m[1]) { $this->row_lock = null; return 1; } return 0; }
            $this->row_deletes++; $had = $this->row_lock !== null; $this->row_lock = null; return $had ? 1 : 0;
        }
        return 1;
    }
}
$wpdb = new FakeWPDB();

/* ------------------------------------------------------------------ */
/*  Mini framework de aserciones                                       */
/* ------------------------------------------------------------------ */

$TESTS = 0; $FAILS = 0;
function ok($cond, $msg) {
    global $TESTS, $FAILS; $TESTS++;
    if ($cond) { echo "  ok   $msg\n"; } else { $FAILS++; echo "  FAIL $msg\n"; }
}
function eq($a, $b, $msg) { ok($a === $b, $msg . ($a === $b ? '' : "\n         esperado: " . var_export($b, true) . "\n         obtenido: " . var_export($a, true))); }
function section($t) { echo "\n== $t ==\n"; }

/* ------------------------------------------------------------------ */
/*  Carga del plugin                                                   */
/* ------------------------------------------------------------------ */

require __DIR__ . '/../gwii-invoice-hash-for-woocommerce/gwii-invoice-hash-for-woocommerce.php';
$plugin = $GLOBALS['wp_hooks']['woocommerce_order_status_completed'][0][0];
ok($plugin instanceof Gwii_Invoice_Hash, 'el plugin se instancia y registra el hook de pedido completado');
ok(isset($GLOBALS['wp_hooks']['woocommerce_order_status_cancelled']), 'registra el hook de cancelación');
ok(isset($GLOBALS['wp_hooks']['woocommerce_order_status_refunded']), 'registra el hook de reembolso');

/* ------------------------------------------------------------------ */

section('Vectores de prueba AEAT v0.1.2');
$c1 = array('IDEmisorFactura' => '89890001K', 'NumSerieFactura' => '12345678/G33', 'FechaExpedicionFactura' => '01-01-2024',
    'TipoFactura' => 'F1', 'CuotaTotal' => '12.35', 'ImporteTotal' => '123.45', 'Huella' => '', 'FechaHoraHusoGenRegistro' => '2024-01-01T19:20:30+01:00');
$r1 = Gwii_Invoice_Hash::compute_hash_alta($c1);
eq($r1['hash'], '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60', 'caso 1: primer registro de alta');
eq($r1['canonical_string'], 'IDEmisorFactura=89890001K&NumSerieFactura=12345678/G33&FechaExpedicionFactura=01-01-2024&TipoFactura=F1&CuotaTotal=12.35&ImporteTotal=123.45&Huella=&FechaHoraHusoGenRegistro=2024-01-01T19:20:30+01:00', 'caso 1: cadena canónica');

$c2 = $c1; $c2['NumSerieFactura'] = '12345679/G34'; $c2['Huella'] = $r1['hash']; $c2['FechaHoraHusoGenRegistro'] = '2024-01-01T19:20:35+01:00';
$r2 = Gwii_Invoice_Hash::compute_hash_alta($c2);
eq($r2['hash'], 'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97', 'caso 2: alta encadenada');

$r3 = Gwii_Invoice_Hash::compute_hash_anulacion(array('IDEmisorFacturaAnulada' => '89890001K', 'NumSerieFacturaAnulada' => '12345679/G34',
    'FechaExpedicionFacturaAnulada' => '01-01-2024', 'Huella' => $r2['hash'], 'FechaHoraHusoGenRegistro' => '2024-01-01T19:20:40+01:00'));
eq($r3['hash'], '177547C0D57AC74748561D054A9CEC14B4C4EA23D1BEFD6F2E69E3A388F90C68', 'caso 3: anulación encadenada');

$ct = $c1; $ct['NumSerieFactura'] = '   12345678/G33  ';
eq(Gwii_Invoice_Hash::compute_hash_alta($ct)['hash'], $r1['hash'], 'los espacios al inicio y final se eliminan');
$cs = $c1; unset($cs['Huella']);
eq(Gwii_Invoice_Hash::compute_hash_alta($cs)['hash'], $r1['hash'], 'huella ausente equivale a huella vacía');

/* ------------------------------------------------------------------ */

section('URL del código QR (Orden HAC/1177/2024, cap. VIII)');
eq(Gwii_Invoice_Hash::generate_qr_url('89890001K', '12345678/G33', '01-01-2024', '123.45'),
   'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=89890001K&numserie=12345678%2FG33&fecha=01-01-2024&importe=123.45', 'URL con los 4 parámetros');
eq(Gwii_Invoice_Hash::generate_qr_url('B12345674', 'F2026-000001', '18-09-2026', '10'),
   'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=B12345674&numserie=F2026-000001&fecha=18-09-2026&importe=10.00', 'importe con dos decimales');
ok(strpos(Gwii_Invoice_Hash::generate_qr_url('B12345674', 'X', '18-09-2026', '1'), 'hash=') === false, 'la huella no forma parte del QR');
eq(Gwii_Invoice_Hash::format_importe('1234.5'), '1234.50', 'format_importe: dos decimales');
eq(Gwii_Invoice_Hash::format_importe(0), '0.00', 'format_importe: cero');

/* ------------------------------------------------------------------ */

section('Dígito de control de NIF / NIE / CIF');
foreach (array('89890001K', '12345678Z', 'X1234567L', 'Y1234567X', 'Z1234567R', 'B12345674', 'A58818501', 'P1234567D', 'Q2826000H', 'N0032484H', 'b12345674', ' 12345678Z ') as $n) {
    ok(Gwii_Invoice_Hash::validar_nif($n), "válido: '$n'");
}
foreach (array('99999999Z', '12345678A', 'B12345670', 'X1234567A', 'A5881850X', '1234', '', 'P12345674', 'ABCDEFGHI') as $n) {
    ok(!Gwii_Invoice_Hash::validar_nif($n), "inválido: '$n'");
}

/* ------------------------------------------------------------------ */

section('Saneado de ajustes');
$GLOBALS['wp_options']['gwiih_nif_emisor'] = 'B12345674';
eq($plugin->sanitize_nif_option('99999999Z'), 'B12345674', 'NIF inválido: se conserva el anterior');
ok(count($GLOBALS['wp_settings_errors']) === 1, 'NIF inválido: se registra un error de ajustes');
eq($plugin->sanitize_nif_option(' 12345678z '), '12345678Z', 'NIF válido: se guarda en mayúsculas y sin espacios');
eq($plugin->sanitize_nif_option(''), '', 'NIF vacío se admite (plugin sin configurar)');
eq($plugin->sanitize_tipo_option('R1'), 'F1', 'tipo no permitido vuelve a F1');
eq($plugin->sanitize_tipo_option('F2'), 'F2', 'tipo F2 permitido');
eq(strlen($plugin->sanitize_prefijo_option(str_repeat('A', 80))), 54, 'prefijo limitado a 54 caracteres');

/* ------------------------------------------------------------------ */

section('Registro de alta al completar pedidos');
$GLOBALS['wp_options'] = array('gwiih_nif_emisor' => 'B12345674', 'gwiih_serie_prefijo' => 'F2026-', 'gwiih_modo_registro' => 'F1');
$GLOBALS['wc_orders'][101] = new WC_Order(101, '123.45', '12.35');
$GLOBALS['wc_orders'][205] = new WC_Order(205, '50', '8.68');

$plugin->process_order_alta(101);
$o1 = $GLOBALS['wc_orders'][101];
eq($o1->get_meta('_verifactu_num_serie'), 'F2026-000001', 'primer pedido: número correlativo 000001 (no el ID 101)');
eq($o1->get_meta('_verifactu_fecha_expedicion'), '18-09-2026', 'fecha de expedición del momento del registro');
eq($o1->get_meta('_verifactu_fecha_hora_gen'), '2026-09-18T12:00:00+02:00', 'marca temporal con huso de Madrid (+02:00), no UTC');
eq($o1->get_meta('_verifactu_cuota_total'), '12.35', 'cuota con dos decimales');
eq($o1->get_meta('_verifactu_importe_total'), '123.45', 'importe con dos decimales');
eq($o1->get_meta('_verifactu_hash_anterior'), '', 'primer registro: huella anterior vacía');
$esperado1 = Gwii_Invoice_Hash::compute_hash_alta(array('IDEmisorFactura' => 'B12345674', 'NumSerieFactura' => 'F2026-000001', 'FechaExpedicionFactura' => '18-09-2026',
    'TipoFactura' => 'F1', 'CuotaTotal' => '12.35', 'ImporteTotal' => '123.45', 'Huella' => '', 'FechaHoraHusoGenRegistro' => '2026-09-18T12:00:00+02:00'));
eq($o1->get_meta('_verifactu_hash'), $esperado1['hash'], 'la huella guardada coincide con el cálculo independiente');
eq($o1->get_meta('_verifactu_canonical'), $esperado1['canonical_string'], 'se guarda la cadena canónica');
eq($o1->get_meta('_verifactu_qr_url'), 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=B12345674&numserie=F2026-000001&fecha=18-09-2026&importe=123.45', 'URL del QR guardada');
eq(get_option('gwiih_last_hash'), $esperado1['hash'], 'última huella actualizada en opciones');
eq(get_option('gwiih_next_number'), 2, 'el contador avanza a 2');
eq($o1->saves, 1, 'el pedido se guarda una vez');
eq($wpdb->locks, 1, 'se pidió el bloqueo');
eq($wpdb->releases, 1, 'se liberó el bloqueo');
eq($wpdb->last_lock_name, 'wp_gwiih_chain', 'nombre del bloqueo con prefijo de tabla');
ok(count($o1->notes) === 1 && strpos($o1->notes[0], 'F2026-000001') !== false, 'nota de pedido con el número asignado');

$GLOBALS['wp_now'] = '2026-09-18 12:00:07';
$plugin->process_order_alta(205);
$o2 = $GLOBALS['wc_orders'][205];
eq($o2->get_meta('_verifactu_num_serie'), 'F2026-000002', 'segundo pedido: número 000002');
eq($o2->get_meta('_verifactu_hash_anterior'), $esperado1['hash'], 'segundo pedido encadena con la huella del primero');
eq($o2->get_meta('_verifactu_importe_total'), '50.00', 'importe entero formateado a 50.00');
$esperado2 = Gwii_Invoice_Hash::compute_hash_alta(array('IDEmisorFactura' => 'B12345674', 'NumSerieFactura' => 'F2026-000002', 'FechaExpedicionFactura' => '18-09-2026',
    'TipoFactura' => 'F1', 'CuotaTotal' => '8.68', 'ImporteTotal' => '50.00', 'Huella' => $esperado1['hash'], 'FechaHoraHusoGenRegistro' => '2026-09-18T12:00:07+02:00'));
eq($o2->get_meta('_verifactu_hash'), $esperado2['hash'], 'huella del segundo pedido correcta');
eq(get_option('gwiih_last_hash'), $esperado2['hash'], 'última huella = segundo pedido');

$hash_antes = $o1->get_meta('_verifactu_hash'); $saves_antes = $o1->saves; $locks_antes = $wpdb->locks;
$plugin->process_order_alta(101);
eq($o1->get_meta('_verifactu_hash'), $hash_antes, 'idempotente: reprocesar no cambia la huella');
eq($o1->saves, $saves_antes, 'idempotente: no vuelve a guardar');
eq($wpdb->locks, $locks_antes, 'idempotente: ni siquiera pide el bloqueo');
eq(get_option('gwiih_next_number'), 3, 'idempotente: el contador no avanza');

$plugin->process_order_alta(999);
ok(true, 'pedido inexistente no provoca error');

/* ------------------------------------------------------------------ */

section('Casos de fallo');
$GLOBALS['wc_orders'][300] = new WC_Order(300, '10', '1.74');
$wpdb->lock_result = '0';
$plugin->process_order_alta(300);
$o3 = $GLOBALS['wc_orders'][300];
eq($o3->get_meta('_verifactu_hash'), '', 'bloqueo no obtenido: no se genera huella');
eq(get_option('gwiih_next_number'), 3, 'bloqueo no obtenido: el contador no avanza');
ok(count($o3->notes) === 1 && strpos($o3->notes[0], 'bloqueo') !== false, 'bloqueo no obtenido: nota explicativa en el pedido');
$wpdb->lock_result = '1';

$GLOBALS['wc_orders'][301] = new WC_Order(301, '10', '1.74');
$nif_guardado = $GLOBALS['wp_options']['gwiih_nif_emisor'];
$GLOBALS['wp_options']['gwiih_nif_emisor'] = '';
$plugin->process_order_alta(301);
$o4 = $GLOBALS['wc_orders'][301];
eq($o4->get_meta('_verifactu_hash'), '', 'sin NIF configurado: no se genera huella');
ok(count($o4->notes) === 1 && strpos($o4->notes[0], 'NIF') !== false, 'sin NIF configurado: nota explicativa en el pedido');
$GLOBALS['wp_options']['gwiih_nif_emisor'] = $nif_guardado;

/* ------------------------------------------------------------------ */

section('Registro de anulación');
$GLOBALS['wp_now'] = '2026-09-19 09:30:00';
$plugin->process_order_anulacion(101);
$esperado_anul = Gwii_Invoice_Hash::compute_hash_anulacion(array('IDEmisorFacturaAnulada' => 'B12345674', 'NumSerieFacturaAnulada' => 'F2026-000001',
    'FechaExpedicionFacturaAnulada' => '18-09-2026', 'Huella' => $esperado2['hash'], 'FechaHoraHusoGenRegistro' => '2026-09-19T09:30:00+02:00'));
eq($o1->get_meta('_verifactu_anulacion_hash'), $esperado_anul['hash'], 'anulación encadenada con la última huella (la del pedido 2)');
eq($o1->get_meta('_verifactu_anulacion_hash_anterior'), $esperado2['hash'], 'se guarda la huella anterior de la anulación');
eq($o1->get_meta('_verifactu_anulacion_fecha_hora'), '2026-09-19T09:30:00+02:00', 'marca temporal de la anulación');
eq(get_option('gwiih_last_hash'), $esperado_anul['hash'], 'la anulación pasa a ser la última huella de la cadena');
eq($o1->get_meta('_verifactu_hash'), $esperado1['hash'], 'la huella de alta original no se toca');

$saves_antes = $o1->saves;
$plugin->process_order_anulacion(101);
eq($o1->saves, $saves_antes, 'anulación idempotente');

$plugin->process_order_anulacion(301);
eq($GLOBALS['wc_orders'][301]->get_meta('_verifactu_anulacion_hash'), '', 'no se anula un pedido que nunca tuvo alta');

// Un alta posterior encadena con la anulación.
$GLOBALS['wc_orders'][400] = new WC_Order(400, '20', '3.47');
$plugin->process_order_alta(400);
eq($GLOBALS['wc_orders'][400]->get_meta('_verifactu_hash_anterior'), $esperado_anul['hash'], 'el siguiente alta encadena con la anulación');
eq($GLOBALS['wc_orders'][400]->get_meta('_verifactu_num_serie'), 'F2026-000003', 'numeración continúa en 000003');

/* ------------------------------------------------------------------ */

section('Zona horaria de invierno');
$GLOBALS['wp_now'] = '2027-01-15 10:00:00';
$GLOBALS['wc_orders'][500] = new WC_Order(500, '20', '3.47');
$plugin->process_order_alta(500);
eq($GLOBALS['wc_orders'][500]->get_meta('_verifactu_fecha_hora_gen'), '2027-01-15T10:00:00+01:00', 'en enero el huso es +01:00');

/* ------------------------------------------------------------------ */

section('Salida HTML');
ob_start(); $plugin->render_order_meta_box($o1); $html = ob_get_clean();
ok(strpos($html, 'F2026-000001') !== false, 'meta box muestra el número');
ok(strpos($html, $esperado1['hash']) !== false, 'meta box muestra la huella de alta');
ok(strpos($html, $esperado_anul['hash']) !== false, 'meta box muestra la huella de anulación');
ok(strpos($html, 'TIKE-CONT/ValidarQR') !== false, 'meta box enlaza a la URL del QR');

ob_start(); $plugin->render_order_meta_box($GLOBALS['wc_orders'][301]); $html = ob_get_clean();
ok(strpos($html, 'Sin registro') !== false, 'meta box de pedido sin registro');

ob_start(); $plugin->display_customer_verification(101); $html = ob_get_clean();
ok(strpos($html, $esperado1['hash']) !== false, 'página de gracias muestra la huella');
ok(stripos($html, 'verificada ante') === false, 'página de gracias no afirma que la factura esté verificada');
ok(strpos($html, 'sede.agenciatributaria.gob.es/Sede/verifactu') === false, 'página de gracias no usa la URL antigua');

ob_start(); $plugin->display_customer_verification(301); $html = ob_get_clean();
eq($html, '', 'página de gracias no imprime nada sin registro');

ob_start(); $plugin->inject_pdf_invoice_metadata('invoice', $o1); $html = ob_get_clean();
ok(substr_count($html, '<tr') === 3, 'PDF: tres filas (número, huella, URL)');
ok(strpos($html, 'F2026-000001') !== false && strpos($html, $esperado1['hash']) !== false, 'PDF: número y huella');

ob_start(); $plugin->render_settings_page(); $html = ob_get_clean();
ok(strpos($html, 'No genera el XML') !== false, 'ajustes: aviso de alcance');
ok(strpos($html, 'Siguiente: F2026-000005') !== false, 'ajustes: muestra el siguiente número');
ok(strpos($html, get_option('gwiih_last_hash')) !== false, 'ajustes: muestra la última huella');

ob_start(); $plugin->maybe_show_config_notice(); $html = ob_get_clean();
eq($html, '', 'sin aviso cuando el NIF está configurado');
$GLOBALS['wp_options']['gwiih_nif_emisor'] = '';
ob_start(); $plugin->maybe_show_config_notice(); $html = ob_get_clean();
ok(strpos($html, 'notice-warning') !== false, 'aviso cuando falta el NIF');
$GLOBALS['wp_options']['gwiih_nif_emisor'] = 'B12345674';

/* ------------------------------------------------------------------ */

section('Bloqueo por fila cuando no hay GET_LOCK (SQLite / Playground)');
$wpdb->lock_result = null;
$GLOBALS['wp_now'] = '2027-01-15 10:05:00';
$GLOBALS['wc_orders'][600] = new WC_Order(600, '121', '21');
$prev = get_option('gwiih_last_hash'); $ins = $wpdb->row_inserts; $dels = $wpdb->row_deletes; $rel = $wpdb->releases;
$wpdb->lock_result = '1=1'; // Respuesta real del traductor SQLite de Playground.
$plugin->process_order_alta(600);
$o6 = $GLOBALS['wc_orders'][600];
eq($o6->get_meta('_verifactu_num_serie'), 'F2026-000005', 'sin GET_LOCK: se genera el registro igualmente');
eq($o6->get_meta('_verifactu_hash_anterior'), $prev, 'sin GET_LOCK: encadena con la huella anterior');
eq($wpdb->row_inserts, $ins + 1, 'sin GET_LOCK: inserta la fila de bloqueo');
eq($wpdb->row_deletes, $dels + 1, 'sin GET_LOCK: borra la fila al terminar');
eq($wpdb->releases, $rel, 'sin GET_LOCK: no llama a RELEASE_LOCK');
eq($wpdb->row_lock, null, 'sin GET_LOCK: la fila de bloqueo no queda huérfana');

$plugin->process_order_anulacion(600);
ok($o6->get_meta('_verifactu_anulacion_hash') !== '', 'sin GET_LOCK: también genera la anulación');
eq($wpdb->row_lock, null, 'sin GET_LOCK: la anulación libera la fila');

// Bloqueo abandonado hace más de LOCK_STALE segundos: se recupera.
$wpdb->row_lock = time() - 120;
$GLOBALS['wc_orders'][601] = new WC_Order(601, '10', '1.74');
$plugin->process_order_alta(601);
eq($GLOBALS['wc_orders'][601]->get_meta('_verifactu_num_serie'), 'F2026-000006', 'bloqueo abandonado: se recupera y se registra');

// Bloqueo en uso por otro proceso: espera LOCK_TIMEOUT segundos y desiste sin tocar la cadena.
$wpdb->row_lock = time() + 3600;
$GLOBALS['wc_orders'][602] = new WC_Order(602, '10', '1.74');
$t0 = microtime(true);
$plugin->process_order_alta(602);
$o602 = $GLOBALS['wc_orders'][602];
eq($o602->get_meta('_verifactu_hash'), '', 'bloqueo ocupado: no se genera huella');
ok(microtime(true) - $t0 >= Gwii_Invoice_Hash::LOCK_TIMEOUT - 0.5, 'bloqueo ocupado: espera antes de desistir');
ok($wpdb->row_lock !== null, 'bloqueo ocupado: no borra el bloqueo ajeno');
ok(count($o602->notes) === 1 && strpos($o602->notes[0], 'bloqueo') !== false, 'bloqueo ocupado: nota explicativa');
$wpdb->row_lock = null;
$wpdb->lock_result = '1';

/* ------------------------------------------------------------------ */

section('Pantalla de la caja de metadatos con y sin HPOS');
$plugin->add_order_meta_box();
eq($GLOBALS['wp_meta_box_screen'], 'shop_order', 'sin HPOS: pantalla shop_order');
eval('namespace Automattic\WooCommerce\Utilities; class OrderUtil { public static function custom_orders_table_usage_is_enabled() { return true; } }');
$plugin->add_order_meta_box();
eq($GLOBALS['wp_meta_box_screen'], 'woocommerce_page_wc-orders', 'con HPOS: pantalla de wc_get_page_screen_id');

/* ------------------------------------------------------------------ */

section('Migración de opciones desde 1.0.x');
$GLOBALS['wp_options'] = array('verifactu_nif_emisor' => 'B12345674', 'verifactu_last_hash' => str_repeat('A', 64), 'verifactu_serie_prefijo' => 'F2025-');
$plugin->maybe_migrate_options();
eq(get_option('gwiih_nif_emisor'), 'B12345674', 'migra el NIF');
eq(get_option('gwiih_last_hash'), str_repeat('A', 64), 'migra la última huella (la cadena continúa)');
eq(get_option('gwiih_serie_prefijo'), 'F2025-', 'migra el prefijo');
eq(get_option('gwiih_modo_registro'), false, 'no inventa opciones que no existían');
$GLOBALS['wp_options']['verifactu_nif_emisor'] = 'X0000000T';
$plugin->maybe_migrate_options();
eq(get_option('gwiih_nif_emisor'), 'B12345674', 'no sobreescribe una opción nueva ya existente');

echo "\n$TESTS pruebas, $FAILS fallos\n";
exit($FAILS === 0 ? 0 : 1);

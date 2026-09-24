<?php
/**
 * Plugin Name: Gwii Invoice Hash for WooCommerce
 * Plugin URI: https://gwi1i.github.io/verifactu/
 * Description: Computes the SHA-256 chained invoice hash and the AEAT QR verification URL (Spanish VeriFactu format, Orden HAC/1177/2024) for each completed WooCommerce order. Calculation utility only: it does not submit records to the AEAT. Not affiliated with AEAT or WooCommerce.
 * Version: 1.1.0
 * Author: gwii
 * Author URI: https://profiles.wordpress.org/gwii/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gwii-invoice-hash-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 5.0
 * WC tested up to: 9.3
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

/**
 * Clase principal del plugin.
 *
 * Alcance: cálculo de la huella (hash) y del encadenamiento de registros de
 * facturación conforme al documento técnico AEAT v0.1.2 y generación de la URL
 * del código QR (capítulo VIII de la Orden HAC/1177/2024).
 *
 * Fuera de alcance: generación del XML del registro, firma electrónica,
 * remisión a la AEAT, registro de eventos y declaración responsable.
 */
class Gwii_Invoice_Hash {

    const VERSION = '1.1.0';

    /** URL de cotejo del código QR fijada en la Orden HAC/1177/2024 (entorno de producción). */
    const QR_BASE_URL = 'https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR';

    /** Segundos máximos de espera para obtener el bloqueo de la cadena. */
    const LOCK_TIMEOUT = 10;

    public function __construct() {
        add_action('plugins_loaded', array($this, 'maybe_migrate_options'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'maybe_show_config_notice'));

        // Compatibilidad con WooCommerce HPOS (High-Performance Order Storage).
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));

        // Registro de alta al completar el pedido.
        add_action('woocommerce_order_status_completed', array($this, 'process_order_alta'), 10, 1);

        // Registro de anulación al cancelar o reembolsar íntegramente un pedido ya registrado.
        add_action('woocommerce_order_status_cancelled', array($this, 'process_order_anulacion'), 10, 1);
        add_action('woocommerce_order_status_refunded', array($this, 'process_order_anulacion'), 10, 1);

        // Caja de metadatos en la edición del pedido.
        add_action('add_meta_boxes', array($this, 'add_order_meta_box'));

        // Información de cotejo en la página de pedido recibido.
        add_action('woocommerce_thankyou', array($this, 'display_customer_verification'), 20, 1);

        // Integración con WooCommerce PDF Invoices & Packing Slips.
        add_action('wpo_wcpdf_after_order_data', array($this, 'inject_pdf_invoice_metadata'), 10, 2);
    }

    /* ------------------------------------------------------------------ */
    /*  Compatibilidad y administración                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Copia las opciones guardadas por las versiones 1.0.x (prefijo verifactu_) al prefijo actual.
     */
    public function maybe_migrate_options() {
        $map = array(
            'verifactu_nif_emisor'    => 'gwiih_nif_emisor',
            'verifactu_serie_prefijo' => 'gwiih_serie_prefijo',
            'verifactu_modo_registro' => 'gwiih_modo_registro',
            'verifactu_last_hash'     => 'gwiih_last_hash',
        );
        foreach ($map as $old => $new) {
            if (get_option($new, false) === false) {
                $value = get_option($old, false);
                if ($value !== false) {
                    update_option($new, $value, false);
                }
            }
        }
    }

    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Huella VeriFactu (Gwii Invoice Hash)', 'gwii-invoice-hash-for-woocommerce'),
            __('Huella VeriFactu', 'gwii-invoice-hash-for-woocommerce'),
            'manage_woocommerce',
            'gwiih-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('gwiih_options_group', 'gwiih_nif_emisor', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_nif_option'),
            'default'           => '',
        ));

        register_setting('gwiih_options_group', 'gwiih_serie_prefijo', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_prefijo_option'),
            'default'           => 'F2026-',
        ));

        register_setting('gwiih_options_group', 'gwiih_modo_registro', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_tipo_option'),
            'default'           => 'F1',
        ));
    }

    /**
     * Valida el NIF al guardar. Si no supera el dígito de control se conserva el valor anterior.
     */
    public function sanitize_nif_option($value) {
        $value = strtoupper(sanitize_text_field($value));
        if ($value !== '' && !self::validar_nif($value)) {
            add_settings_error(
                'gwiih_nif_emisor',
                'gwiih_nif_invalido',
                sprintf(
                    /* translators: %s: NIF introducido */
                    __('El NIF "%s" no supera el dígito de control. No se ha guardado.', 'gwii-invoice-hash-for-woocommerce'),
                    $value
                )
            );
            return get_option('gwiih_nif_emisor', '');
        }
        return $value;
    }

    public function sanitize_prefijo_option($value) {
        $value = sanitize_text_field($value);
        // NumSerieFactura admite hasta 60 caracteres; reservamos 6 para el número correlativo.
        return mb_substr($value, 0, 54);
    }

    public function sanitize_tipo_option($value) {
        return in_array($value, array('F1', 'F2'), true) ? $value : 'F1';
    }

    /**
     * Aviso en el panel si el plugin está activo pero sin NIF configurado.
     */
    public function maybe_show_config_notice() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (get_option('gwiih_nif_emisor', '') !== '') {
            return;
        }
        $url = admin_url('admin.php?page=gwiih-settings');
        echo '<div class="notice notice-warning"><p>';
        echo wp_kses_post(sprintf(
            /* translators: %s: URL de la página de ajustes */
            __('<strong>Gwii Invoice Hash:</strong> no se generarán huellas hasta que configures el NIF emisor en <a href="%s">WooCommerce &rsaquo; Huella VeriFactu</a>.', 'gwii-invoice-hash-for-woocommerce'),
            esc_url($url)
        ));
        echo '</p></div>';
    }

    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $last_hash   = get_option('gwiih_last_hash', '');
        $next_number = (int) get_option('gwiih_next_number', 1);
        $prefijo     = get_option('gwiih_serie_prefijo', 'F2026-');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Gwii Invoice Hash: huella y encadenamiento', 'gwii-invoice-hash-for-woocommerce'); ?></h1>

            <div class="notice notice-info inline" style="max-width:700px; margin:16px 0;">
                <p><strong><?php esc_html_e('Alcance del plugin', 'gwii-invoice-hash-for-woocommerce'); ?></strong></p>
                <p><?php esc_html_e('Este plugin calcula la huella SHA-256 encadenada de cada pedido completado y la URL del código QR de cotejo según la Orden HAC/1177/2024, y las guarda en los metadatos del pedido.', 'gwii-invoice-hash-for-woocommerce'); ?></p>
                <p><?php esc_html_e('No genera el XML del registro de facturación, no lo firma, no lo remite a la AEAT ni lleva el registro de eventos. Por sí solo no convierte la tienda en un sistema de facturación adaptado al RD 1007/2023.', 'gwii-invoice-hash-for-woocommerce'); ?></p>
            </div>

            <?php settings_errors('gwiih_nif_emisor'); ?>

            <form method="post" action="options.php" style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px; max-width:700px;">
                <?php settings_fields('gwiih_options_group'); ?>
                <?php do_settings_sections('gwiih_options_group'); ?>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('NIF del emisor', 'gwii-invoice-hash-for-woocommerce'); ?></th>
                        <td>
                            <input type="text" name="gwiih_nif_emisor" value="<?php echo esc_attr(get_option('gwiih_nif_emisor')); ?>" class="regular-text" placeholder="B12345674" maxlength="9" required />
                            <p class="description"><?php esc_html_e('NIF de la empresa o autónomo titular de la tienda. Se comprueba el dígito de control al guardar.', 'gwii-invoice-hash-for-woocommerce'); ?></p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Prefijo de la serie', 'gwii-invoice-hash-for-woocommerce'); ?></th>
                        <td>
                            <input type="text" name="gwiih_serie_prefijo" value="<?php echo esc_attr($prefijo); ?>" class="regular-text" />
                            <p class="description">
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: %s: ejemplo de número de factura */
                                    __('Los números se asignan de forma correlativa e independiente del ID del pedido. Siguiente: %s', 'gwii-invoice-hash-for-woocommerce'),
                                    $prefijo . str_pad((string) $next_number, 6, '0', STR_PAD_LEFT)
                                ));
                                ?>
                            </p>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row"><?php esc_html_e('Tipo de factura por defecto', 'gwii-invoice-hash-for-woocommerce'); ?></th>
                        <td>
                            <select name="gwiih_modo_registro">
                                <option value="F1" <?php selected(get_option('gwiih_modo_registro', 'F1'), 'F1'); ?>><?php esc_html_e('F1 - Factura completa', 'gwii-invoice-hash-for-woocommerce'); ?></option>
                                <option value="F2" <?php selected(get_option('gwiih_modo_registro', 'F1'), 'F2'); ?>><?php esc_html_e('F2 - Factura simplificada', 'gwii-invoice-hash-for-woocommerce'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Guardar ajustes', 'gwii-invoice-hash-for-woocommerce')); ?>
            </form>

            <div style="margin-top:20px; background:#fff; padding:15px; border:1px solid #ccd0d4; border-radius:8px; max-width:700px;">
                <h3><?php esc_html_e('Estado del encadenamiento', 'gwii-invoice-hash-for-woocommerce'); ?></h3>
                <p><strong><?php esc_html_e('Última huella registrada:', 'gwii-invoice-hash-for-woocommerce'); ?></strong></p>
                <code style="display:block; padding:10px; background:#f0f0f1; word-break:break-all;">
                    <?php echo $last_hash ? esc_html($last_hash) : esc_html__('Sin registros previos. El primer registro se encadena con huella vacía.', 'gwii-invoice-hash-for-woocommerce'); ?>
                </code>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /*  Algoritmo (AEAT v0.1.2)                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Cadena canónica y huella de un registro de alta.
     * Los valores se recortan (trim) y se concatenan en el orden fijado por la especificación.
     */
    public static function compute_hash_alta(array $c) {
        $canonical = 'IDEmisorFactura=' . trim($c['IDEmisorFactura'])
            . '&NumSerieFactura=' . trim($c['NumSerieFactura'])
            . '&FechaExpedicionFactura=' . trim($c['FechaExpedicionFactura'])
            . '&TipoFactura=' . trim($c['TipoFactura'])
            . '&CuotaTotal=' . trim($c['CuotaTotal'])
            . '&ImporteTotal=' . trim($c['ImporteTotal'])
            . '&Huella=' . trim(isset($c['Huella']) ? $c['Huella'] : '')
            . '&FechaHoraHusoGenRegistro=' . trim($c['FechaHoraHusoGenRegistro']);

        return array(
            'canonical_string' => $canonical,
            'hash'             => strtoupper(hash('sha256', $canonical)),
        );
    }

    /**
     * Cadena canónica y huella de un registro de anulación.
     */
    public static function compute_hash_anulacion(array $c) {
        $canonical = 'IDEmisorFacturaAnulada=' . trim($c['IDEmisorFacturaAnulada'])
            . '&NumSerieFacturaAnulada=' . trim($c['NumSerieFacturaAnulada'])
            . '&FechaExpedicionFacturaAnulada=' . trim($c['FechaExpedicionFacturaAnulada'])
            . '&Huella=' . trim(isset($c['Huella']) ? $c['Huella'] : '')
            . '&FechaHoraHusoGenRegistro=' . trim($c['FechaHoraHusoGenRegistro']);

        return array(
            'canonical_string' => $canonical,
            'hash'             => strtoupper(hash('sha256', $canonical)),
        );
    }

    /**
     * URL del código QR según el capítulo VIII de la Orden HAC/1177/2024.
     * Parámetros: nif, numserie, fecha (DD-MM-AAAA) e importe (punto decimal, 2 decimales).
     */
    public static function generate_qr_url($nif, $num_serie, $fecha_exp, $importe) {
        $params = array(
            'nif'      => trim($nif),
            'numserie' => trim($num_serie),
            'fecha'    => trim($fecha_exp),
            'importe'  => self::format_importe($importe),
        );
        return self::QR_BASE_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Importe con dos decimales y punto como separador.
     */
    public static function format_importe($valor) {
        return number_format((float) $valor, 2, '.', '');
    }

    /**
     * Validación del dígito de control de NIF (DNI, NIE, K/L/M) y CIF.
     */
    public static function validar_nif($nif) {
        $nif = strtoupper(trim((string) $nif));
        if (!preg_match('/^[A-Z0-9]{9}$/', $nif)) {
            return false;
        }
        $letras = 'TRWAGMYFPDXBNJZSQVHLCKE';

        // DNI: 8 dígitos + letra.
        if (preg_match('/^[0-9]{8}[A-Z]$/', $nif)) {
            return $nif[8] === $letras[((int) substr($nif, 0, 8)) % 23];
        }

        // NIE: X/Y/Z + 7 dígitos + letra.
        if (preg_match('/^[XYZ][0-9]{7}[A-Z]$/', $nif)) {
            $prefijo = array('X' => '0', 'Y' => '1', 'Z' => '2');
            return $nif[8] === $letras[((int) ($prefijo[$nif[0]] . substr($nif, 1, 7))) % 23];
        }

        // NIF especiales K, L, M: letra + 7 dígitos + letra de control.
        if (preg_match('/^[KLM][0-9]{7}[A-Z]$/', $nif)) {
            return $nif[8] === $letras[((int) substr($nif, 1, 7)) % 23];
        }

        // CIF: letra de forma jurídica + 7 dígitos + control (dígito o letra según tipo).
        if (preg_match('/^[ABCDEFGHJNPQRSUVW][0-9]{7}[0-9A-J]$/', $nif)) {
            $digitos = substr($nif, 1, 7);
            $suma    = 0;
            for ($i = 0; $i < 7; $i++) {
                $d = (int) $digitos[$i];
                if ($i % 2 === 0) {
                    $x     = $d * 2;
                    $suma += intdiv($x, 10) + ($x % 10);
                } else {
                    $suma += $d;
                }
            }
            $control       = (10 - ($suma % 10)) % 10;
            $letra_control = substr('JABCDEFGHI', $control, 1);
            $tipo          = $nif[0];
            $c             = $nif[8];

            if (strpos('PQRSNW', $tipo) !== false) {
                return $c === $letra_control;
            }
            if (strpos('ABEH', $tipo) !== false) {
                return $c === (string) $control;
            }
            return $c === (string) $control || $c === $letra_control;
        }

        return false;
    }

    /* ------------------------------------------------------------------ */
    /*  Bloqueo de la cadena                                               */
    /* ------------------------------------------------------------------ */

    private function lock_name() {
        global $wpdb;
        return $wpdb->prefix . 'gwiih_chain';
    }

    /**
     * Bloqueo a nivel de base de datos para que dos pedidos simultáneos no rompan el encadenamiento.
     */
    private function acquire_lock() {
        global $wpdb;
        // Bloqueo de servidor MySQL/MariaDB: no existe API de WordPress para esto y no debe cachearse.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $this->lock_name(), self::LOCK_TIMEOUT));
        return (string) $result === '1';
    }

    private function release_lock() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->lock_name()));
    }

    /**
     * Fecha y hora actuales en la zona horaria configurada en WordPress.
     * No se usa date(), porque WordPress fija PHP en UTC y el huso saldría +00:00.
     */
    private function now() {
        return current_datetime();
    }

    /* ------------------------------------------------------------------ */
    /*  Procesamiento de pedidos                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Genera el registro de alta cuando el pedido pasa a 'completed'.
     */
    public function process_order_alta($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if ($order->get_meta('_verifactu_hash')) {
            return; // Ya registrado.
        }

        $nif_emisor = get_option('gwiih_nif_emisor', '');
        if ($nif_emisor === '') {
            $order->add_order_note(__('VeriFactu: no se ha generado la huella porque el NIF emisor no está configurado.', 'gwii-invoice-hash-for-woocommerce'));
            return;
        }

        if (!$this->acquire_lock()) {
            $order->add_order_note(__('VeriFactu: no se pudo obtener el bloqueo de la cadena. Vuelve a completar el pedido para generar la huella.', 'gwii-invoice-hash-for-woocommerce'));
            return;
        }

        try {
            // Releer por si otro proceso lo registró mientras esperábamos el bloqueo.
            $order = wc_get_order($order_id);
            if (!$order || $order->get_meta('_verifactu_hash')) {
                return;
            }

            $prefijo = get_option('gwiih_serie_prefijo', 'F2026-');
            $tipo    = get_option('gwiih_modo_registro', 'F1');
            $numero  = (int) get_option('gwiih_next_number', 1);
            if ($numero < 1) {
                $numero = 1;
            }
            $num_serie = $prefijo . str_pad((string) $numero, 6, '0', STR_PAD_LEFT);

            // La factura se expide en el momento en que se genera el registro.
            $ahora      = $this->now();
            $fecha_exp  = $ahora->format('d-m-Y');
            $ts_iso     = $ahora->format('Y-m-d\TH:i:sP');
            $importe    = self::format_importe($order->get_total());
            $cuota      = self::format_importe($order->get_total_tax());
            $prev_hash  = get_option('gwiih_last_hash', '');

            $campos = array(
                'IDEmisorFactura'          => $nif_emisor,
                'NumSerieFactura'          => $num_serie,
                'FechaExpedicionFactura'   => $fecha_exp,
                'TipoFactura'              => $tipo,
                'CuotaTotal'               => $cuota,
                'ImporteTotal'             => $importe,
                'Huella'                   => $prev_hash,
                'FechaHoraHusoGenRegistro' => $ts_iso,
            );

            $result = self::compute_hash_alta($campos);
            $qr_url = self::generate_qr_url($nif_emisor, $num_serie, $fecha_exp, $importe);

            $order->update_meta_data('_verifactu_num_serie', $num_serie);
            $order->update_meta_data('_verifactu_tipo', $tipo);
            $order->update_meta_data('_verifactu_fecha_expedicion', $fecha_exp);
            $order->update_meta_data('_verifactu_fecha_hora_gen', $ts_iso);
            $order->update_meta_data('_verifactu_cuota_total', $cuota);
            $order->update_meta_data('_verifactu_importe_total', $importe);
            $order->update_meta_data('_verifactu_hash_anterior', $prev_hash);
            $order->update_meta_data('_verifactu_hash', $result['hash']);
            $order->update_meta_data('_verifactu_canonical', $result['canonical_string']);
            $order->update_meta_data('_verifactu_qr_url', $qr_url);
            $order->save();

            update_option('gwiih_last_hash', $result['hash'], false);
            update_option('gwiih_next_number', $numero + 1, false);

            $order->add_order_note(sprintf(
                /* translators: 1: número de factura, 2: huella */
                __('VeriFactu: registro de alta %1$s generado. Huella %2$s', 'gwii-invoice-hash-for-woocommerce'),
                $num_serie,
                $result['hash']
            ));
        } finally {
            $this->release_lock();
        }
    }

    /**
     * Genera el registro de anulación cuando un pedido ya registrado se cancela o reembolsa por completo.
     * Los reembolsos parciales (facturas rectificativas) no están cubiertos.
     */
    public function process_order_anulacion($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $hash_alta = $order->get_meta('_verifactu_hash');
        if (!$hash_alta || $order->get_meta('_verifactu_anulacion_hash')) {
            return;
        }

        $nif_emisor = get_option('gwiih_nif_emisor', '');
        if ($nif_emisor === '') {
            return;
        }

        if (!$this->acquire_lock()) {
            $order->add_order_note(__('VeriFactu: no se pudo obtener el bloqueo de la cadena para el registro de anulación.', 'gwii-invoice-hash-for-woocommerce'));
            return;
        }

        try {
            $order = wc_get_order($order_id);
            if (!$order || $order->get_meta('_verifactu_anulacion_hash')) {
                return;
            }

            $ahora     = $this->now();
            $ts_iso    = $ahora->format('Y-m-d\TH:i:sP');
            $prev_hash = get_option('gwiih_last_hash', '');

            $campos = array(
                'IDEmisorFacturaAnulada'        => $nif_emisor,
                'NumSerieFacturaAnulada'        => $order->get_meta('_verifactu_num_serie'),
                'FechaExpedicionFacturaAnulada' => $order->get_meta('_verifactu_fecha_expedicion'),
                'Huella'                        => $prev_hash,
                'FechaHoraHusoGenRegistro'      => $ts_iso,
            );

            $result = self::compute_hash_anulacion($campos);

            $order->update_meta_data('_verifactu_anulacion_fecha_hora', $ts_iso);
            $order->update_meta_data('_verifactu_anulacion_hash_anterior', $prev_hash);
            $order->update_meta_data('_verifactu_anulacion_hash', $result['hash']);
            $order->update_meta_data('_verifactu_anulacion_canonical', $result['canonical_string']);
            $order->save();

            update_option('gwiih_last_hash', $result['hash'], false);

            $order->add_order_note(sprintf(
                /* translators: %s: huella del registro de anulación */
                __('VeriFactu: registro de anulación generado. Huella %s', 'gwii-invoice-hash-for-woocommerce'),
                $result['hash']
            ));
        } finally {
            $this->release_lock();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Presentación                                                       */
    /* ------------------------------------------------------------------ */

    public function add_order_meta_box() {
        $hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $screen = $hpos ? wc_get_page_screen_id('shop-order') : 'shop_order';

        add_meta_box(
            'gwiih_order_box',
            __('Registro VeriFactu (huella)', 'gwii-invoice-hash-for-woocommerce'),
            array($this, 'render_order_meta_box'),
            $screen,
            'side',
            'default'
        );
    }

    public function render_order_meta_box($post_or_order) {
        $order = is_a($post_or_order, 'WC_Order') ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order) {
            return;
        }

        $hash = $order->get_meta('_verifactu_hash');
        if (!$hash) {
            echo '<p style="color:#666;"><em>' . esc_html__('Sin registro. Se genera al completar el pedido.', 'gwii-invoice-hash-for-woocommerce') . '</em></p>';
            return;
        }

        $num    = $order->get_meta('_verifactu_num_serie');
        $fecha  = $order->get_meta('_verifactu_fecha_expedicion');
        $ts     = $order->get_meta('_verifactu_fecha_hora_gen');
        $qr_url = $order->get_meta('_verifactu_qr_url');
        $anul   = $order->get_meta('_verifactu_anulacion_hash');

        echo '<p><strong>' . esc_html__('Factura:', 'gwii-invoice-hash-for-woocommerce') . '</strong> <code>' . esc_html($num) . '</code></p>';
        echo '<p><strong>' . esc_html__('Expedida:', 'gwii-invoice-hash-for-woocommerce') . '</strong> ' . esc_html($fecha) . '<br><small>' . esc_html($ts) . '</small></p>';
        echo '<p><strong>' . esc_html__('Huella SHA-256:', 'gwii-invoice-hash-for-woocommerce') . '</strong><br><code style="font-size:10px; word-break:break-all;">' . esc_html($hash) . '</code></p>';
        if ($qr_url) {
            echo '<p><a href="' . esc_url($qr_url) . '" class="button button-small" target="_blank" rel="noopener">' . esc_html__('Abrir URL de cotejo (AEAT)', 'gwii-invoice-hash-for-woocommerce') . '</a></p>';
        }
        if ($anul) {
            echo '<p><strong>' . esc_html__('Anulación:', 'gwii-invoice-hash-for-woocommerce') . '</strong> ' . esc_html($order->get_meta('_verifactu_anulacion_fecha_hora')) . '<br><code style="font-size:10px; word-break:break-all;">' . esc_html($anul) . '</code></p>';
        }
    }

    /**
     * Bloque informativo en la página de pedido recibido.
     * No afirma que la factura esté verificada: el cotejo en la Sede depende de que el registro se haya remitido.
     */
    public function display_customer_verification($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $hash   = $order->get_meta('_verifactu_hash');
        $qr_url = $order->get_meta('_verifactu_qr_url');
        $num    = $order->get_meta('_verifactu_num_serie');

        if (!$hash || !$qr_url) {
            return;
        }

        echo '<div style="margin: 25px 0; padding: 16px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc;">';
        echo '<h4 style="margin: 0 0 8px 0; color: #0f172a;">' . esc_html__('Registro de facturación', 'gwii-invoice-hash-for-woocommerce') . '</h4>';
        echo '<p style="font-size: 13px; color: #475569; margin-bottom: 8px;">' . esc_html(sprintf(
            /* translators: %s: número de factura */
            __('Factura %s. Este pedido tiene asociada una huella SHA-256 encadenada.', 'gwii-invoice-hash-for-woocommerce'),
            $num
        )) . '</p>';
        echo '<p style="font-size: 11px; font-family: monospace; color: #64748b; margin-bottom: 12px; word-break: break-all;">' . esc_html($hash) . '</p>';
        echo '<a href="' . esc_url($qr_url) . '" target="_blank" rel="noopener" style="display: inline-block; padding: 8px 14px; background: #0284c7; color: #fff; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 600;">' . esc_html__('Cotejar en la Sede de la AEAT', 'gwii-invoice-hash-for-woocommerce') . '</a>';
        echo '</div>';
    }

    /**
     * Filas adicionales en la factura PDF de WooCommerce PDF Invoices & Packing Slips.
     */
    public function inject_pdf_invoice_metadata($document_type, $order) {
        if (!$order) {
            return;
        }
        $hash   = $order->get_meta('_verifactu_hash');
        $qr_url = $order->get_meta('_verifactu_qr_url');
        $num    = $order->get_meta('_verifactu_num_serie');

        if (!$hash) {
            return;
        }

        echo '<tr class="verifactu-num-row">';
        echo '<th style="font-size: 8pt; color: #475569; text-align: left; padding: 4px 0;">' . esc_html__('Factura VeriFactu:', 'gwii-invoice-hash-for-woocommerce') . '</th>';
        echo '<td style="font-size: 8pt; color: #0f172a; padding: 4px 0;">' . esc_html($num) . '</td>';
        echo '</tr>';
        echo '<tr class="verifactu-hash-row">';
        echo '<th style="font-size: 8pt; color: #475569; text-align: left; padding: 4px 0;">' . esc_html__('Huella SHA-256:', 'gwii-invoice-hash-for-woocommerce') . '</th>';
        echo '<td style="font-family: monospace; font-size: 7.5pt; color: #0f172a; word-break: break-all; padding: 4px 0;">' . esc_html($hash) . '</td>';
        echo '</tr>';
        if ($qr_url) {
            echo '<tr class="verifactu-qr-row">';
            echo '<th style="font-size: 8pt; color: #475569; text-align: left; padding: 4px 0;">' . esc_html__('URL de cotejo:', 'gwii-invoice-hash-for-woocommerce') . '</th>';
            echo '<td style="font-size: 7.5pt; padding: 4px 0; word-break: break-all;"><a href="' . esc_url($qr_url) . '" style="color: #0284c7;">' . esc_html($qr_url) . '</a></td>';
            echo '</tr>';
        }
    }
}

new Gwii_Invoice_Hash();

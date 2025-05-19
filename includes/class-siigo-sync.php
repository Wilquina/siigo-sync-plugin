<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Siigo_Sync {

    public static function init() {
        // Settings & admin
        add_action( 'admin_menu',    [ __CLASS__, 'add_settings_page' ] );
        add_action( 'admin_init',    [ __CLASS__, 'register_settings' ] );

        // WooCommerce hooks
        add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'sync_invoice' ],    10, 1 );
        add_action( 'woocommerce_order_status_completed',  [ __CLASS__, 'sync_inventory' ], 10, 1 );
        add_action( 'woocommerce_order_status_refunded',   [ __CLASS__, 'sync_inventory' ], 10, 1 );

        // AJAX manual sync
        add_action( 'wp_ajax_siigo_inventory_sync', [ __CLASS__, 'manual_inventory_sync' ] );
        add_action( 'wp_ajax_siigo_invoice_resync', [ __CLASS__, 'manual_invoice_resync' ] );
    }

    public static function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            'Siigo Sync',
            'Siigo Sync',
            'manage_woocommerce',
            'siigo-sync',
            [ __CLASS__, 'settings_page_html' ]
        );
    }

    public static function register_settings() {
        register_setting( 'siigo_sync', 'siigo_sync_settings', [ __CLASS__, 'sanitize' ] );
        add_settings_section( 'siigo_api', 'Siigo API Settings', '__return_false', 'siigo_sync' );
        add_settings_field( 'client_id',     'Client ID',     [ __CLASS__, 'field_client_id' ],     'siigo_sync', 'siigo_api' );
        add_settings_field( 'client_secret', 'Client Secret', [ __CLASS__, 'field_client_secret' ], 'siigo_sync', 'siigo_api' );
        add_settings_field( 'base_url',      'API Base URL',  [ __CLASS__, 'field_base_url' ],      'siigo_sync', 'siigo_api' );
    }

    public static function sanitize( $in ) {
        return [
            'client_id'     => sanitize_text_field( $in['client_id'] ?? '' ),
            'client_secret' => sanitize_text_field( $in['client_secret'] ?? '' ),
            'base_url'      => esc_url_raw( $in['base_url'] ?? '' ),
        ];
    }

    public static function field_client_id() {
        $o = get_option( 'siigo_sync_settings', [] );
        printf(
            '<input name="siigo_sync_settings[client_id]" value="%s" class="regular-text">',
            esc_attr( $o['client_id'] ?? '' )
        );
    }

    public static function field_client_secret() {
        $o = get_option( 'siigo_sync_settings', [] );
        printf(
            '<input type="password" name="siigo_sync_settings[client_secret]" value="%s" class="regular-text">',
            esc_attr( $o['client_secret'] ?? '' )
        );
    }

    public static function field_base_url() {
        $o = get_option( 'siigo_sync_settings', [] );
        printf(
            '<input name="siigo_sync_settings[base_url]" value="%s" class="regular-text" placeholder="https://api.siigo.com">',
            esc_attr( $o['base_url'] ?? '' )
        );
    }

    public static function settings_page_html() {
        ?>
        <div class="wrap">
          <h1>Siigo Sync Settings</h1>
          <form method="post" action="options.php">
            <?php
            settings_fields( 'siigo_sync' );
            do_settings_sections( 'siigo_sync' );
            submit_button();
            ?>
          </form>
          <h2>Manual Sync</h2>
          <button id="siigo-inv-sync" class="button button-primary">Sync Inventory</button>
          <button id="siigo-inv-resync" class="button">Resync Invoices</button>
          <div id="siigo-sync-status" style="margin-top:1em;"></div>
        </div>
        <script>
        (function($){
          $('#siigo-inv-sync').click(function(){
            $('#siigo-sync-status').text('Syncing inventory…');
            $.post( ajaxurl, { action:'siigo_inventory_sync' }, function(r){
              $('#siigo-sync-status').text( r.success ? r.data : r.data );
            });
          });
          $('#siigo-inv-resync').click(function(){
            $('#siigo-sync-status').text('Resyncing invoices…');
            $.post( ajaxurl, { action:'siigo_invoice_resync' }, function(r){
              $('#siigo-sync-status').text( r.success ? r.data : r.data );
            });
          });
        })(jQuery);
        </script>
        <?php
    }

    /** Order → Siigo invoice */
    public static function sync_invoice( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $payload = [
            'customer' => [
                'name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
            ],
            'items' => [],
        ];
        foreach ( $order->get_items() as $item ) {
            $prod = $item->get_product();
            $payload['items'][] = [
                'code'     => $prod->get_sku(),
                'quantity' => $item->get_quantity(),
                'price'    => $item->get_total(),
            ];
        }

        try {
            $api      = new Siigo_API();
            $resp     = $api->create_invoice( $payload );
            $invoice_id = $resp['id'] ?? '';
            if ( $invoice_id ) {
                update_post_meta( $order_id, '_siigo_invoice_id', $invoice_id );
            }
        } catch ( Exception $e ) {
            error_log( 'Siigo invoice error: ' . $e->getMessage() );
        }
    }

    /** Sync stock up or down on complete/refund */
    public static function sync_inventory( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        foreach ( $order->get_items() as $item ) {
            $prod     = $item->get_product();
            $delta    = ( 'refunded' === $order->get_status() ) ? +$item->get_quantity() : -$item->get_quantity();
            $new_stock = max( 0, $prod->get_stock_quantity() + $delta );

            try {
                $api = new Siigo_API();
                $api->update_inventory( $prod->get_sku(), $new_stock );
                $prod->set_stock_quantity( $new_stock );
                $prod->save();
            } catch ( Exception $e ) {
                error_log( 'Siigo inventory error: ' . $e->getMessage() );
            }
        }
    }

    /** AJAX: manual inventory sync */
    public static function manual_inventory_sync() {
        try {
            $api      = new Siigo_API();
            $products = wc_get_products( [ 'limit' => -1 ] );
            foreach ( $products as $p ) {
                $api->update_inventory( $p->get_sku(), $p->get_stock_quantity() );
            }
            wp_send_json_success( 'Inventory synced.' );
        } catch ( Exception $e ) {
            wp_send_json_error( 'Error: ' . $e->getMessage() );
        }
    }

    /** AJAX: manual invoice resync */
    public static function manual_invoice_resync() {
        try {
            $orders = wc_get_orders([
                'meta_key' => '_siigo_invoice_id',
                'limit'    => -1,
            ]);
            foreach ( $orders as $order ) {
                self::sync_invoice( $order->get_id() );
            }
            wp_send_json_success( 'Invoices re-synced.' );
        } catch ( Exception $e ) {
            wp_send_json_error( 'Error: ' . $e->getMessage() );
        }
    }
}

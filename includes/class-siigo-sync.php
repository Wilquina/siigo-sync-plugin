<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Siigo_Sync {

    public static function init() {
        // Admin menu & settings
        add_action( 'admin_menu', [ __CLASS__, 'add_admin_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );

        // Auto-sync on order completion
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'auto_update_inventory' ], 10, 1 );

        // AJAX handlers for manual sync
        add_action( 'wp_ajax_siigo_sync_inventory',           [ __CLASS__, 'manual_sync_inventory' ] );
        add_action( 'wp_ajax_siigo_sync_products_from_siigo', [ __CLASS__, 'manual_sync_products_from_siigo' ] );
        add_action( 'wp_ajax_siigo_sync_products_to_siigo',   [ __CLASS__, 'manual_sync_products_to_siigo' ] );
    }

    public static function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Siigo Sync',
            'Siigo Sync',
            'manage_woocommerce',
            'siigo-sync',
            [ __CLASS__, 'settings_page' ]
        );
    }

    public static function register_settings() {
        register_setting( 'siigo_sync', 'siigo_sync_settings', [ __CLASS__, 'sanitize_settings' ] );
        add_settings_section( 'credentials', 'Siigo API Credentials', '__return_false', 'siigo_sync' );
        add_settings_field( 'client_id',     'Client ID',     [ __CLASS__, 'field_client_id' ],     'siigo_sync', 'credentials' );
        add_settings_field( 'client_secret', 'Client Secret', [ __CLASS__, 'field_client_secret' ], 'siigo_sync', 'credentials' );
    }

    public static function sanitize_settings( $in ) {
        return [
            'client_id'     => sanitize_text_field( $in['client_id']     ?? '' ),
            'client_secret' => sanitize_text_field( $in['client_secret'] ?? '' ),
        ];
    }

    public static function field_client_id() {
        $opts = get_option( 'siigo_sync_settings', [] );
        printf(
            '<input name="siigo_sync_settings[client_id]" value="%s" class="regular-text"/>',
            esc_attr( $opts['client_id'] ?? '' )
        );
    }

    public static function field_client_secret() {
        $opts = get_option( 'siigo_sync_settings', [] );
        printf(
            '<input type="password" name="siigo_sync_settings[client_secret]" value="%s" class="regular-text"/>',
            esc_attr( $opts['client_secret'] ?? '' )
        );
    }

    public static function settings_page() { ?>
        <div class="wrap">
            <h1>Siigo Sync</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'siigo_sync' );
                do_settings_sections( 'siigo_sync' );
                submit_button();
                ?>
            </form>

            <h2>Manual Sync</h2>
            <button id="siigo-sync-inventory" class="button button-primary">Sync Inventory</button>
            <button id="siigo-sync-from"      class="button button-secondary">Sync Products from Siigo</button>
            <button id="siigo-sync-to"        class="button button-secondary">Sync Products to Siigo</button>
            <div id="siigo-sync-result" style="margin-top:1em;"></div>
        </div>
        <script>
        (function($){
            $('#siigo-sync-inventory').on('click', function(){
                $('#siigo-sync-result').text('Syncing inventory…');
                $.post(ajaxurl, {action:'siigo_sync_inventory'}, function(r){
                    $('#siigo-sync-result').text(r.success ? r.data : r.data);
                });
            });
            $('#siigo-sync-from').on('click', function(){
                $('#siigo-sync-result').text('Syncing products from Siigo…');
                $.post(ajaxurl, {action:'siigo_sync_products_from_siigo'}, function(r){
                    $('#siigo-sync-result').text(r.success ? r.data : r.data);
                });
            });
            $('#siigo-sync-to').on('click', function(){
                $('#siigo-sync-result').text('Syncing products to Siigo…');
                $.post(ajaxurl, {action:'siigo_sync_products_to_siigo'}, function(r){
                    $('#siigo-sync-result').text(r.success ? r.data : r.data);
                });
            });
        })(jQuery);
        </script>
    <?php }

    // Auto-update Siigo inventory when order completes
    public static function auto_update_inventory( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $api = new Siigo_API();
        foreach ( $order->get_items() as $item ) {
            $prod = $item->get_product();
            if ( ! $prod || ! $prod->get_sku() ) {
                continue;
            }
            try {
                $sp = $api->get_product_by_code( $prod->get_sku() );
                if ( $sp && isset( $sp['id'] ) ) {
                    $api->update_inventory( $sp['id'], $prod->get_stock_quantity() );
                }
            } catch ( Exception $e ) {
                error_log( 'Siigo auto-inventory error: ' . $e->getMessage() );
            }
        }
    }

    // Manual: push all WC stock ? Siigo
    public static function manual_sync_inventory() {
        try {
            $api      = new Siigo_API();
            $products = wc_get_products( [ 'limit' => -1 ] );
            foreach ( $products as $p ) {
                if ( ! $p->get_sku() ) {
                    continue;
                }
                $sp = $api->get_product_by_code( $p->get_sku() );
                if ( $sp && isset( $sp['id'] ) ) {
                    $api->update_inventory( $sp['id'], $p->get_stock_quantity() );
                }
            }
            wp_send_json_success( 'Inventory synchronized.' );
        } catch ( Exception $e ) {
            wp_send_json_error( 'Error syncing inventory: ' + $e->getMessage() );
        }
    }

    // Manual: pull missing products from Siigo ? WC
    public static function manual_sync_products_from_siigo() {
        try {
            $api     = new Siigo_API();
            $sprods  = $api->get_products();
            $created = 0;
            foreach ( $sprods['results'] as $sp ) {
                if ( empty( $sp['code'] ) ) {
                    continue;
                }
                if ( ! wc_get_product_id_by_sku( $sp['code'] ) ) {
                    $wc = new WC_Product_Simple();
                    $wc->set_name(       $sp['name']        ?? '' );
                    $wc->set_sku(        $sp['code']        );
                    $wc->set_regular_price( $sp['price_sales'] ?? 0 );
                    $wc->set_stock_quantity( $sp['stock']      ?? 0 );
                    $wc->set_status(     'publish' );
                    $wc->save();
                    $created++;
                }
            }
            wp_send_json_success( sprintf( '%d products created.', $created ) );
        } catch ( Exception $e ) {
            wp_send_json_error( 'Error syncing from Siigo: ' + $e->getMessage() );
        }
    }

    // Manual: push missing WC products ? Siigo
    public static function manual_sync_products_to_siigo() {
        try {
            $api        = new Siigo_API();
            $sprods     = $api->get_products();
            $siigoCodes = wp_list_pluck( $sprods, 'code' );
            $wcprods    = wc_get_products( [ 'limit' => -1 ] );
            $created    = 0;

            foreach ( $wcprods as $p ) {
                $sku = $p->get_sku();
                if ( ! $sku || in_array( $sku, $siigoCodes, true ) ) {
                    continue;
                }
                $payload = [
                    'code'        => $sku,
                    'name'        => $p->get_name(),
                    'price_sales' => $p->get_price(),
                    'stock'       => $p->get_stock_quantity(),
                ];
                $api->create_product( $payload );
                $created++;
            }
            wp_send_json_success( sprintf( '%d products pushed to Siigo.', $created ) );
        } catch ( Exception $e ) {
            wp_send_json_error( 'Error syncing to Siigo: ' + $e->getMessage() );
        }
    }
}

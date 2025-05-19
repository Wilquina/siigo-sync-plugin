<?php
/**
 * Plugin Name: Siigo Sync
 * Description: Two-way sync of WooCommerce products & inventory with Siigo.
 * Version:     1.0.0
 * Author:      William Quinones
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SIIGO_SYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIIGO_API_BASE_URL',     'https://api.siigo.com' );
define( 'SIIGO_PARTNER_ID',       'WooCommerce' );

require_once SIIGO_SYNC_PLUGIN_DIR . 'includes/class-siigo-api.php';
require_once SIIGO_SYNC_PLUGIN_DIR . 'includes/class-siigo-sync.php';

add_action( 'plugins_loaded', [ 'Siigo_Sync', 'init' ] );

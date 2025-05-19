<?php
/**
 * Plugin Name: Siigo Sync
 * Description: Two-way sync of WooCommerce inventory & invoices with Siigo.
 * Version:     1.0.0
 * Author:      Your Name
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SIIGO_SYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIIGO_SYNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include required classes
require_once SIIGO_SYNC_PLUGIN_DIR . 'includes/class-siigo-api.php';
require_once SIIGO_SYNC_PLUGIN_DIR . 'includes/class-siigo-sync.php';

// Kick off initialization
add_action( 'plugins_loaded', array( 'Siigo_Sync', 'init' ) );

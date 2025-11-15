<?php
/**
 * Plugin Name: Foam Orders Dashboard (Receiver) — Grade Filter (Fixed)
 * Description: Receiver dashboard + orders + REST ingest. Full foam names + Grade filter (by name). Bugfix for PHP array append.
 * Version: 1.0.7
 * Author: Team Foam Superstore
 * Text Domain: fod-receiver
 */

if (!defined('ABSPATH')) exit;


define('FODR_VERSION', '1.0.7');
define('FODR_DIR', plugin_dir_path(__FILE__));

function fodr_cap(){
    if ( current_user_can('manage_woocommerce') || class_exists('WooCommerce') ) return 'manage_woocommerce';
    return 'manage_options';
}

register_activation_hook(__FILE__, function(){
    global $wpdb;
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $orders = $wpdb->prefix.'foam_orders';
    $items  = $wpdb->prefix.'foam_order_items';
    dbDelta("
    CREATE TABLE {$orders} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      order_id BIGINT UNSIGNED NOT NULL,
      order_number VARCHAR(190) NOT NULL,
      status VARCHAR(50) NOT NULL,
      customer_name VARCHAR(190) NULL,
      email VARCHAR(190) NULL,
      phone VARCHAR(50) NULL,
      total DECIMAL(18,2) NOT NULL DEFAULT 0,
      currency VARCHAR(10) NOT NULL DEFAULT 'GBP',
      shipping_method VARCHAR(190) NULL,
      priority VARCHAR(50) NULL,
      date_created DATETIME NULL,
      date_modified DATETIME NULL,
      grades VARCHAR(255) NULL,
      depths VARCHAR(255) NULL,
      job_desc TEXT NULL,
      source VARCHAR(100) NULL,
      billing_address TEXT NULL,
      shipping_address TEXT NULL,
      customer_notes TEXT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY order_id_unique (order_id),
      KEY status_idx (status),
      KEY date_mod_idx (date_modified),
      KEY email_idx (email),
      KEY phone_idx (phone),
      KEY source_idx (source)
    ) {$charset};
    CREATE TABLE {$items} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      order_id BIGINT UNSIGNED NOT NULL,
      product_id BIGINT UNSIGNED NULL,
      sku VARCHAR(190) NULL,
      name TEXT NULL,
      qty INT NOT NULL DEFAULT 0,
      line_total DECIMAL(18,2) NOT NULL DEFAULT 0,
      meta_json LONGTEXT NULL,
      PRIMARY KEY (id),
      KEY order_id_idx (order_id),
      KEY product_id_idx (product_id)
    ) {$charset};
    ");
    
    // Add new columns if they don't exist
    $columns = $wpdb->get_col("DESCRIBE {$orders}");
    if (!in_array('source', $columns)) {
        $wpdb->query("ALTER TABLE {$orders} ADD COLUMN source VARCHAR(100) NULL AFTER job_desc");
    }
    if (!in_array('billing_address', $columns)) {
        $wpdb->query("ALTER TABLE {$orders} ADD COLUMN billing_address TEXT NULL AFTER source");
    }
    if (!in_array('shipping_address', $columns)) {
        $wpdb->query("ALTER TABLE {$orders} ADD COLUMN shipping_address TEXT NULL AFTER billing_address");
    }
    if (!in_array('customer_notes', $columns)) {
        $wpdb->query("ALTER TABLE {$orders} ADD COLUMN customer_notes TEXT NULL AFTER shipping_address");
    }
});
register_activation_hook(__FILE__, ['FODR_Batches_Common','activate']);

require_once FODR_DIR.'includes/class-dashboard.php';
require_once FODR_DIR.'includes/class-orders.php';
require_once FODR_DIR.'includes/class-receiver.php';
require_once FODR_DIR.'includes/class-settings.php';
require_once FODR_DIR.'includes/batches/class-batches-common.php';
require_once FODR_DIR.'includes/batches/class-batches-list.php';
require_once FODR_DIR.'includes/batches/class-batch-detail.php';
require_once FODR_DIR.'includes/waste/class-waste.php';
require_once FODR_DIR.'includes/planner/class-auto-planner.php';
require_once FODR_DIR.'includes/planner/auto-planner-actions.php';
require_once FODR_DIR.'includes/dispatch/class-ready-dispatch.php';
require_once FODR_DIR.'includes/dispatch/class-out-for-delivery.php';
require_once FODR_DIR.'includes/dispatch/class-completed-orders.php';


add_action('plugins_loaded', function(){
    FODR_Dashboard::init();
    FODR_Orders::init();
    FODR_Receiver::init();
    FODR_Settings::init();
    FODR_Batches_List::init();
    FODR_Batch_Detail::init();
    FODR_Waste::init();
    FODR_Ready_Dispatch::init();
    FODR_Out_For_Delivery::init();
    FODR_Completed_Orders::init();
});
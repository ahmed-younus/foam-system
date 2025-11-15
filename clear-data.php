<?php
/**
 * Clear All Data - Danger Zone
 * This file permanently deletes all data from the system
 */

require_once 'config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_request();

    try {
        $db = get_db_connection();

        // Truncate all tables
        $tables = [
            'foam_orders',
            'foam_order_items',
            'foam_order_parts',
            'foam_batches',
            'foam_waste',
            'foam_allocations'
        ];

        foreach ($tables as $table) {
            $db->exec("TRUNCATE TABLE $table");
        }

        // Reset API secret to default
        $db->prepare("UPDATE foam_settings SET setting_value = ? WHERE setting_key = 'api_secret'")->execute([API_SECRET]);

        $_SESSION['success_message'] = 'All data has been cleared successfully!';
        redirect('settings.php');

    } catch (PDOException $e) {
        $_SESSION['error_message'] = 'Error clearing data: ' . $e->getMessage();
        redirect('settings.php');
    }
}

redirect('settings.php');

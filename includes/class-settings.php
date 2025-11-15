<?php
if (!defined('ABSPATH')) exit;

class FODR_Settings {
  public static function init(){ 
    add_action('admin_menu', [__CLASS__, 'menu']); 
    add_action('admin_post_fodr_create_backup', [__CLASS__, 'handle_create_backup']);
    add_action('admin_post_fodr_restore_backup', [__CLASS__, 'handle_restore_backup']);
    add_action('admin_post_fodr_remove_data', [__CLASS__, 'handle_remove_data']);
    add_action('admin_post_fodr_migrate_database', [__CLASS__, 'handle_migrate_database']);
  }

  public static function menu(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    add_submenu_page('fodr-dashboard', __('Settings','fod-receiver'), __('Settings','fod-receiver'),
      $cap, 'fodr-settings', [__CLASS__, 'render']);
  }

  public static function render(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    if ( ! current_user_can($cap) ) { wp_die(__('Sorry, you are not allowed to access this page.')); }
    
    $saved = false;
    if ( isset($_POST['fodr_save']) && check_admin_referer('fodr_settings') ) {
      update_option('fod_shared_secret', sanitize_text_field($_POST['fod_shared_secret'] ?? ''));
      $saved = true;
    }

    // Show messages
    if (isset($_GET['msg'])) {
      if ($_GET['msg'] === 'backup_created') {
        echo '<div class="notice notice-success"><p>✅ Backup created successfully!</p></div>';
      } elseif ($_GET['msg'] === 'restore_success') {
        $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
        echo '<div class="notice notice-success"><p>✅ Data restored successfully! '.$count.' records restored.</p></div>';
      } elseif ($_GET['msg'] === 'data_removed') {
        echo '<div class="notice notice-success"><p>🗑️ All plugin data has been removed successfully!</p></div>';
      } elseif ($_GET['msg'] === 'migration_success') {
        echo '<div class="notice notice-success"><p>🔧 Database migration completed successfully!</p></div>';
      } elseif ($_GET['msg'] === 'migration_not_needed') {
        echo '<div class="notice notice-info"><p>ℹ️ Database is already up to date!</p></div>';
      } elseif ($_GET['msg'] === 'error') {
        $error = isset($_GET['error']) ? sanitize_text_field($_GET['error']) : 'Unknown error';
        echo '<div class="notice notice-error"><p>❌ Error: '.esc_html($error).'</p></div>';
      }
    }
    
    if ($saved) echo '<div class="notice notice-success"><p>✅ Settings saved.</p></div>';

    $secret = defined('FOD_SHARED_SECRET') && FOD_SHARED_SECRET ? '*** defined in wp-config.php ***' : get_option('fod_shared_secret','');
    $endpoint = esc_url_raw( site_url('/wp-json/fod/v1/ingest') );
    
    echo '<div class="wrap fodr-settings-page">';
    echo '<style>
      .wrap.fodr-settings-page { 
        max-width: none !important; 
        width: calc(100vw - 180px) !important; 
        margin: 10px !important;
        padding-right: 20px !important;
      }
      #wpcontent { padding-left: 0 !important; }
      .fodr-settings-page .card { 
        width: 100% !important; 
        box-sizing: border-box !important; 
        max-width: none !important;
        margin-bottom: 20px !important;
      }
      body.folded .fodr-settings-page { width: calc(100vw - 56px) !important; }
      .danger-zone { background: #ffebee; border-left: 4px solid #f44336; }
      .backup-zone { background: #e8f5e8; border-left: 4px solid #4caf50; }
      .restore-zone { background: #fff3e0; border-left: 4px solid #ff9800; }
      .migration-zone { background: #e3f2fd; border-left: 4px solid #2196f3; }
    </style>';
    
    echo '<h1>⚙️ Foam Orders — Settings</h1>';

    // Basic Settings
    self::render_basic_settings($endpoint, $secret);

    // Database Migration Section
    self::render_migration_section();

    // Database Statistics
    self::render_database_stats();

    // 2-column layout for backup operations
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px;">';
    
    // Left column: Backup & Restore
    echo '<div>';
    self::render_backup_section();
    self::render_restore_section();
    echo '</div>';
    
    // Right column: Data Management
    echo '<div>';
    self::render_data_management();
    echo '</div>';
    
    echo '</div>'; // end grid
    
    echo '</div>'; // end wrap
  }

  private static function render_basic_settings($endpoint, $secret) {
    echo '<div class="card" style="padding:20px;">';
    echo '<h2 style="margin:0 0 15px;color:#333;">📡 API Configuration</h2>';
    
    echo '<p><strong>Receiver Endpoint:</strong></p>';
    echo '<code style="background:#f5f5f5;padding:8px;border-radius:4px;font-size:13px;display:block;margin-bottom:15px;">'.$endpoint.'</code>';
    
    echo '<form method="post">';
    wp_nonce_field('fodr_settings');
    echo '<table class="form-table" style="margin:0;">';
    echo '<tr><th style="width:150px;"><label for="fod_shared_secret">Shared Secret</label></th><td>';
    if (defined('FOD_SHARED_SECRET') && FOD_SHARED_SECRET){
        echo '<input type="text" id="fod_shared_secret" value="'.$secret.'" class="regular-text" disabled> ';
        echo '<p class="description">Secret is set in wp-config.php (FOD_SHARED_SECRET). To change, edit wp-config.php.</p>';
    } else {
        echo '<input type="text" id="fod_shared_secret" name="fod_shared_secret" value="'.esc_attr($secret).'" class="regular-text">';
        echo '<p class="description">Used to authenticate API requests from external systems.</p>';
    }
    echo '</td></tr></table>';
    echo '<p><button class="button button-primary" name="fodr_save" value="1">💾 Save Settings</button></p>';
    echo '</form>';
    echo '</div>';
  }

  private static function render_migration_section() {
    echo '<div class="card migration-zone" style="padding:20px;">';
    echo '<h2 style="margin:0 0 15px;color:#1976d2;">🔧 Database Migration</h2>';
    
    echo '<p style="margin-bottom:15px;">Update the database schema to support new features like source tracking, billing/shipping addresses, and customer notes.</p>';
    
    // Check if migration is needed
    global $wpdb;
    $orders_table = $wpdb->prefix.'foam_orders';
    $columns = $wpdb->get_col("DESCRIBE {$orders_table}");
    
    $missing_columns = [];
    $required_columns = ['source', 'billing_address', 'shipping_address', 'customer_notes'];
    
    foreach ($required_columns as $col) {
      if (!in_array($col, $columns)) {
        $missing_columns[] = $col;
      }
    }
    
    if (!empty($missing_columns)) {
      echo '<div style="background:#ffecb3;padding:12px;border-radius:6px;margin-bottom:15px;">';
      echo '<h4 style="margin:0 0 8px;color:#f57f17;">⚠️ Migration Required</h4>';
      echo '<p style="margin:0;font-size:14px;">Missing columns: <strong>'.implode(', ', $missing_columns).'</strong></p>';
      echo '</div>';
      
      echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
      wp_nonce_field('fodr_migrate_database');
      echo '<input type="hidden" name="action" value="fodr_migrate_database">';
      echo '<button class="button button-primary button-large" style="background:#2196f3;border-color:#2196f3;" onclick="return confirm(\'Run database migration? This will add new columns to support enhanced features.\')">🚀 Run Migration</button>';
      echo '</form>';
    } else {
      echo '<div style="background:#e8f5e8;padding:12px;border-radius:6px;margin-bottom:15px;">';
      echo '<h4 style="margin:0 0 8px;color:#2e7d32;">✅ Database Up to Date</h4>';
      echo '<p style="margin:0;font-size:14px;">All required columns are present.</p>';
      echo '</div>';
    }
    
    echo '<div style="margin-top:15px;padding:10px;background:#f0f8ff;border-radius:4px;font-size:13px;">';
    echo '<strong>💡 What migration adds:</strong><br>';
    echo '• <strong>Source:</strong> Track which website/store each order came from<br>';
    echo '• <strong>Billing Address:</strong> Complete customer billing information<br>';
    echo '• <strong>Shipping Address:</strong> Complete shipping/delivery information<br>';
    echo '• <strong>Customer Notes:</strong> Special instructions from customers';
    echo '</div>';
    
    echo '</div>';
  }

  private static function render_database_stats() {
    global $wpdb;
    
    $tables = [
      'foam_orders' => 'Orders',
      'foam_order_items' => 'Order Items', 
      'foam_order_parts' => 'Order Parts',
      'foam_batches' => 'Batches',
      'foam_waste' => 'Waste Inventory',
      'foam_allocations' => 'Allocations'
    ];
    
    echo '<div class="card" style="padding:20px;">';
    echo '<h2 style="margin:0 0 15px;color:#333;">📊 Database Statistics</h2>';
    
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;">';
    
    $total_records = 0;
    foreach ($tables as $table => $label) {
      $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}$table");
      $total_records += $count;
      
      echo '<div style="background:#f8f9fa;padding:10px;border-radius:6px;text-align:center;">';
      echo '<div style="font-size:18px;font-weight:bold;color:#0073aa;">'.$count.'</div>';
      echo '<div style="font-size:12px;color:#666;">'.$label.'</div>';
      echo '</div>';
    }
    
    echo '</div>';
    
    // Enhanced stats with source breakdown
    $source_stats = $wpdb->get_results("
      SELECT source, COUNT(*) as count 
      FROM {$wpdb->prefix}foam_orders 
      WHERE source IS NOT NULL AND source != '' 
      GROUP BY source 
      ORDER BY count DESC
    ", ARRAY_A);
    
    if ($source_stats) {
      echo '<div style="margin-top:15px;">';
      echo '<h3 style="margin:0 0 10px;font-size:14px;color:#333;">📈 Orders by Source</h3>';
      echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;">';
      
      foreach ($source_stats as $stat) {
        echo '<div style="background:#e3f2fd;padding:8px;border-radius:4px;text-align:center;">';
        echo '<div style="font-size:16px;font-weight:bold;color:#1976d2;">'.$stat['count'].'</div>';
        echo '<div style="font-size:11px;color:#666;">'.esc_html($stat['source']).'</div>';
        echo '</div>';
      }
      
      echo '</div>';
      echo '</div>';
    }
    
    echo '<div style="margin-top:15px;padding:12px;background:#e3f2fd;border-radius:6px;text-align:center;">';
    echo '<strong>Total Records: '.$total_records.'</strong>';
    echo '</div>';
    
    echo '</div>';
  }

  private static function render_backup_section() {
    echo '<div class="card backup-zone" style="padding:20px;">';
    echo '<h2 style="margin:0 0 15px;color:#2e7d32;">💾 Create Backup</h2>';
    
    echo '<p style="margin-bottom:15px;">Create a complete backup of all Foam Orders plugin data including orders, batches, waste inventory, and settings.</p>';
    
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    wp_nonce_field('fodr_create_backup');
    echo '<input type="hidden" name="action" value="fodr_create_backup">';
    
    echo '<div style="margin-bottom:15px;">';
    echo '<label style="display:flex;align-items:center;margin-bottom:8px;">';
    echo '<input type="radio" name="backup_format" value="json" checked style="margin-right:8px;"> ';
    echo '<strong>JSON Format</strong> <span style="color:#666;margin-left:8px;">(Recommended - Human readable)</span>';
    echo '</label>';
    echo '<label style="display:flex;align-items:center;">';
    echo '<input type="radio" name="backup_format" value="sql" style="margin-right:8px;"> ';
    echo '<strong>SQL Format</strong> <span style="color:#666;margin-left:8px;">(Database dump)</span>';
    echo '</label>';
    echo '</div>';
    
    echo '<button class="button button-primary button-large" style="background:#4caf50;border-color:#4caf50;" onclick="return confirm(\'Create backup of all plugin data?\')">📦 Create & Download Backup</button>';
    
    echo '</form>';
    
    echo '<div style="margin-top:15px;padding:10px;background:#f0f8ff;border-radius:4px;font-size:13px;">';
    echo '<strong>💡 Tip:</strong> Backups include all orders, batches, waste data, settings, and new features like source tracking and address information.';
    echo '</div>';
    
    echo '</div>';
  }

  private static function render_restore_section() {
    echo '<div class="card restore-zone" style="padding:20px;">';
    echo '<h2 style="margin:0 0 15px;color:#f57f17;">📥 Restore from Backup</h2>';
    
    echo '<p style="margin-bottom:15px;"><strong>⚠️ Warning:</strong> This will replace ALL current data with data from the backup file.</p>';
    
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data">';
    wp_nonce_field('fodr_restore_backup');
    echo '<input type="hidden" name="action" value="fodr_restore_backup">';
    
    echo '<div style="margin-bottom:15px;">';
    echo '<label style="font-weight:bold;display:block;margin-bottom:5px;">Select Backup File:</label>';
    echo '<input type="file" name="backup_file" accept=".json,.sql" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;">';
    echo '<p style="font-size:12px;color:#666;margin:5px 0 0;">Accepts .json or .sql files created by this plugin</p>';
    echo '</div>';
    
    echo '<label style="display:flex;align-items:center;margin-bottom:15px;">';
    echo '<input type="checkbox" name="confirm_restore" value="1" required style="margin-right:8px;">';
    echo '<span>I understand this will replace all current data</span>';
    echo '</label>';
    
    echo '<button class="button button-secondary button-large" onclick="return confirm(\'Are you sure? This will replace ALL current data with backup data!\')" style="background:#ff9800;color:white;border-color:#ff9800;">⚠️ Restore from Backup</button>';
    
    echo '</form>';
    echo '</div>';
  }

  private static function render_data_management() {
    echo '<div class="card danger-zone" style="padding:20px;">';
    echo '<h2 style="margin:0 0 15px;color:#d32f2f;">🗑️ Data Management</h2>';
    
    echo '<div style="background:#ffcdd2;padding:15px;border-radius:6px;margin-bottom:15px;">';
    echo '<h4 style="margin:0 0 10px;color:#c62828;">⚠️ Danger Zone</h4>';
    echo '<p style="margin:0;font-size:14px;">The following actions are irreversible. Please create a backup before proceeding.</p>';
    echo '</div>';
    
    // Remove all data
    echo '<div style="border:1px solid #f44336;border-radius:6px;padding:15px;margin-bottom:15px;">';
    echo '<h4 style="margin:0 0 10px;color:#d32f2f;">🗂️ Remove All Plugin Data</h4>';
    echo '<p style="margin:0 0 10px;font-size:14px;">This will permanently delete:</p>';
    echo '<ul style="margin:0 0 15px;font-size:13px;color:#666;">';
    echo '<li>All orders and order items</li>';
    echo '<li>All batches and parts</li>';
    echo '<li>Waste inventory</li>';
    echo '<li>All allocations</li>';
    echo '<li>Plugin settings</li>';
    echo '</ul>';
    
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    wp_nonce_field('fodr_remove_data');
    echo '<input type="hidden" name="action" value="fodr_remove_data">';
    
    echo '<label style="display:flex;align-items:center;margin-bottom:10px;">';
    echo '<input type="checkbox" name="confirm_removal" value="1" required style="margin-right:8px;">';
    echo '<span>I understand this action cannot be undone</span>';
    echo '</label>';
    
    echo '<input type="text" name="confirm_text" placeholder="Type REMOVE to confirm" required style="width:200px;padding:8px;margin-right:10px;border:1px solid #ddd;border-radius:4px;">';
    echo '<button class="button button-secondary" onclick="return confirm(\'Last warning: This will delete ALL plugin data permanently!\')" style="background:#f44336;color:white;border-color:#f44336;">💀 Remove All Data</button>';
    
    echo '</form>';
    echo '</div>';
    
    // Useful info
    echo '<div style="background:#e8f5e8;padding:15px;border-radius:6px;">';
    echo '<h4 style="margin:0 0 10px;color:#2e7d32;">✅ Safe Operations</h4>';
    echo '<p style="margin:0;font-size:13px;">To safely reset specific data:</p>';
    echo '<ul style="margin:5px 0 0;font-size:13px;">';
    echo '<li>Use filters in Orders page to bulk delete specific orders</li>';
    echo '<li>Clear individual batches from Batches page</li>';
    echo '<li>Manage waste inventory from Waste page</li>';
    echo '</ul>';
    echo '</div>';
    
    echo '</div>';
  }

  // NEW: Handler for database migration
  public static function handle_migrate_database(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_migrate_database');

    try {
      global $wpdb;
      $orders_table = $wpdb->prefix.'foam_orders';
      
      // Check current columns
      $columns = $wpdb->get_col("DESCRIBE {$orders_table}");
      
      $migrations_run = 0;
      
      // Add source column
      if (!in_array('source', $columns)) {
        $wpdb->query("ALTER TABLE {$orders_table} ADD COLUMN source VARCHAR(100) NULL");
        $migrations_run++;
      }
      
      // Add billing_address column
      if (!in_array('billing_address', $columns)) {
        $wpdb->query("ALTER TABLE {$orders_table} ADD COLUMN billing_address TEXT NULL");
        $migrations_run++;
      }
      
      // Add shipping_address column
      if (!in_array('shipping_address', $columns)) {
        $wpdb->query("ALTER TABLE {$orders_table} ADD COLUMN shipping_address TEXT NULL");
        $migrations_run++;
      }
      
      // Add customer_notes column
      if (!in_array('customer_notes', $columns)) {
        $wpdb->query("ALTER TABLE {$orders_table} ADD COLUMN customer_notes TEXT NULL");
        $migrations_run++;
      }
      
      if ($migrations_run > 0) {
        wp_redirect(admin_url('admin.php?page=fodr-settings&msg=migration_success&columns='.$migrations_run)); 
      } else {
        wp_redirect(admin_url('admin.php?page=fodr-settings&msg=migration_not_needed')); 
      }
      exit;
      
    } catch (Exception $e) {
      wp_redirect(admin_url('admin.php?page=fodr-settings&msg=error&error='.urlencode($e->getMessage()))); 
      exit;
    }
  }

  // Handler: Create Backup (updated to include new columns)
  public static function handle_create_backup(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_create_backup');

    $format = sanitize_text_field($_POST['backup_format'] ?? 'json');
    
    try {
      $backup_data = self::generate_backup_data();
      $filename = 'foam-orders-backup-' . date('Y-m-d-H-i-s');
      
      if ($format === 'json') {
        $content = json_encode($backup_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $filename .= '.json';
        $mime_type = 'application/json';
      } else {
        $content = self::generate_sql_backup($backup_data);
        $filename .= '.sql';
        $mime_type = 'application/sql';
      }
      
      // Send download headers
      header('Content-Type: ' . $mime_type);
      header('Content-Disposition: attachment; filename="' . $filename . '"');
      header('Content-Length: ' . strlen($content));
      header('Cache-Control: must-revalidate');
      header('Pragma: no-cache');
      header('Expires: 0');
      
      echo $content;
      exit;
      
    } catch (Exception $e) {
      wp_redirect(admin_url('admin.php?page=fodr-settings&msg=error&error='.urlencode($e->getMessage()))); 
      exit;
    }
  }

  // Handler: Restore Backup
  public static function handle_restore_backup(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_restore_backup');

    if (!isset($_POST['confirm_restore']) || $_POST['confirm_restore'] !== '1') {
      wp_redirect(admin_url('admin.php?page=fodr-settings&msg=error&error=Confirmation required')); 
      exit;
    }

    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
      wp_redirect(admin_url('admin.php?page=fodr-settings&msg=error&error=No file uploaded')); 
      exit;
    }

    $file = $_FILES['backup_file'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($extension, ['json', 'sql'])) {
      wp_redirect(admin_url('admin.php?page=fodr-settings&msg=error&error=Invalid file format')); 
      exit;
    }

    try {
      $content = file_get_contents($file['tmp_name']);
      
      if ($extension === 'json') {
        $backup_data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
          throw new Exception('Invalid JSON format');
        }
        $count = self::restore_from_json($backup_data);
      } else {
        $count = self::restore_from_sql($content);
      }
      
      wp_redirect(admin_url('admin.php?page=fodr-settings&msg=restore_success&count='.$count)); 
      exit;
      
    } catch (Exception $e) {
      wp_redirect(admin_url('admin.php?page=fodr-settings&msg=error&error='.urlencode($e->getMessage()))); 
      exit;
    }
  }

  // Handler: Remove All Data
  public static function handle_remove_data(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_remove_data');

    if (!isset($_POST['confirm_removal']) || $_POST['confirm_removal'] !== '1') {
      wp_redirect(admin_url('admin.php?page=fodr-settings&msg=error&error=Confirmation checkbox required')); 
      exit;
    }

    if (sanitize_text_field($_POST['confirm_text'] ?? '') !== 'REMOVE') {
      wp_redirect(admin_url('admin.php?page=fodr-settings&msg=error&error=Must type REMOVE to confirm')); 
      exit;
    }

    try {
      global $wpdb;
      
      // Truncate all plugin tables
      $tables = [
        'foam_orders',
        'foam_order_items', 
        'foam_order_parts',
        'foam_batches',
        'foam_waste',
        'foam_allocations'
      ];
      
      foreach ($tables as $table) {
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}$table");
      }
      
      // Remove plugin options
      delete_option('fod_shared_secret');
      
      wp_redirect(admin_url('admin.php?page=fodr-settings&msg=data_removed')); 
      exit;
      
    } catch (Exception $e) {
      wp_redirect(admin_url('admin.php?page=fodr-settings&msg=error&error='.urlencode($e->getMessage()))); 
      exit;
    }
  }

  // Backup generation functions
  private static function generate_backup_data() {
    global $wpdb;
    
    $tables = [
      'foam_orders',
      'foam_order_items', 
      'foam_order_parts',
      'foam_batches',
      'foam_waste',
      'foam_allocations'
    ];
    
    $backup = [
      'version' => FODR_VERSION,
      'created_at' => current_time('mysql'),
      'site_url' => get_site_url(),
      'tables' => []
    ];
    
    foreach ($tables as $table) {
      $data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}$table", ARRAY_A);
      $backup['tables'][$table] = $data ?: [];
    }
    
    // Include settings
    $backup['settings'] = [
      'fod_shared_secret' => get_option('fod_shared_secret', '')
    ];
    
    return $backup;
  }

  private static function generate_sql_backup($backup_data) {
    global $wpdb;
    
    $sql = "-- Foam Orders Plugin Backup\n";
    $sql .= "-- Created: " . $backup_data['created_at'] . "\n";
    $sql .= "-- Version: " . $backup_data['version'] . "\n\n";
    
    foreach ($backup_data['tables'] as $table => $rows) {
      if (empty($rows)) continue;
      
      $sql .= "-- Table: $table\n";
      $sql .= "TRUNCATE TABLE {$wpdb->prefix}$table;\n";
      
      foreach ($rows as $row) {
        $values = [];
        foreach ($row as $value) {
          $values[] = $value === null ? 'NULL' : "'" . esc_sql($value) . "'";
        }
        $sql .= "INSERT INTO {$wpdb->prefix}$table VALUES (" . implode(', ', $values) . ");\n";
      }
      
      $sql .= "\n";
    }
    
    return $sql;
  }

  private static function restore_from_json($backup_data) {
    global $wpdb;
    
    if (!isset($backup_data['tables'])) {
      throw new Exception('Invalid backup format');
    }
    
    $total_restored = 0;
    
    foreach ($backup_data['tables'] as $table => $rows) {
      // Truncate existing data
      $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}$table");
      
      // Insert backup data
      foreach ($rows as $row) {
        $wpdb->insert($wpdb->prefix . $table, $row);
        $total_restored++;
      }
    }
    
    // Restore settings
    if (isset($backup_data['settings'])) {
      foreach ($backup_data['settings'] as $key => $value) {
        update_option($key, $value);
      }
    }
    
    return $total_restored;
  }

  private static function restore_from_sql($sql_content) {
    global $wpdb;
    
    // Execute SQL commands
    $commands = explode(";\n", $sql_content);
    $executed = 0;
    
    foreach ($commands as $command) {
      $command = trim($command);
      if (empty($command) || strpos($command, '--') === 0) continue;
      
      $wpdb->query($command);
      $executed++;
    }
    
    return $executed;
  }
}
?>
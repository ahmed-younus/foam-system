<?php
if (!defined('ABSPATH')) exit;

class FODR_Ready_Dispatch {

  public static function init(){
    add_action('admin_menu', [__CLASS__, 'menu']);
    add_action('admin_post_fodr_mark_dispatched', [__CLASS__, 'handle_mark_dispatched']);
    add_action('admin_post_fodr_bulk_dispatch', [__CLASS__, 'handle_bulk_dispatch']);
    add_action('admin_post_fodr_mark_all_dispatched', [__CLASS__, 'handle_mark_all_dispatched']);
  }

  public static function menu(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    add_submenu_page(
      'fodr-dashboard', // Parent slug (Foam Orders)
      __('Ready to Dispatch','fod-receiver'), 
      __('Ready to Dispatch','fod-receiver'),
      $cap, 
      'fodr-ready-dispatch', 
      [__CLASS__, 'render']
    );
  }

  public static function render(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    if (!current_user_can($cap)) wp_die(__('Not allowed','fod-receiver'));

    echo '<div class="wrap fodr-dispatch-page">';
    echo '<style>
      .wrap.fodr-dispatch-page { 
        max-width: none !important; 
        width: calc(100vw - 180px) !important; 
        margin: 10px !important;
        padding-right: 20px !important;
      }
      #wpcontent {
        padding-left: 0 !important;
      }
      .fodr-dispatch-page .card { 
        width: 100% !important; 
        box-sizing: border-box !important; 
        max-width: none !important;
        margin-bottom: 20px !important;
      }
      .fodr-dispatch-page table {
        width: 100% !important;
        max-width: none !important;
      }
      body.folded .fodr-dispatch-page {
        width: calc(100vw - 56px) !important;
      }
      .progress-bar {
        background: #e0e0e0;
        border-radius: 10px;
        height: 8px;
        overflow: hidden;
      }
      .progress-fill {
        height: 100%;
        transition: width 0.3s ease;
        border-radius: 10px;
      }
      @media (max-width: 960px) {
        .wrap.fodr-dispatch-page {
          width: calc(100% - 10px) !important;
          margin: 5px !important;
        }
      }
    </style>';

    echo '<h1>📦 Ready to Dispatch</h1>';

    // Show success messages
    if (isset($_GET['msg'])) {
      if ($_GET['msg'] === 'order_dispatched') {
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        echo '<div class="notice notice-success"><p>📦 Order #'.$order_id.' has been dispatched and moved to Out for Delivery!</p></div>';
      } elseif ($_GET['msg'] === 'bulk_dispatched') {
        $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
        echo '<div class="notice notice-success"><p>🚚 Successfully dispatched '.$count.' orders! They have been moved to Out for Delivery.</p></div>';
      } elseif ($_GET['msg'] === 'all_dispatched') {
        $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
        echo '<div class="notice notice-success"><p>🎉 All '.$count.' ready orders have been dispatched!</p></div>';
      }
    }

    // Search functionality
    self::render_search_bar();

    // Quick stats
    self::render_quick_stats();

    // Main content - Ready to Dispatch section
    echo '<div style="margin-bottom:30px;">';
    self::render_ready_to_dispatch_section();
    echo '</div>';

    // Pending section
    echo '<div>';
    self::render_pending_section();
    echo '</div>';

    echo '</div>'; // end wrap
  }

  private static function render_search_bar() {
    $search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    
    echo '<div class="card" style="padding:15px;margin-bottom:20px;">';
    echo '<form method="get" style="display:flex;gap:10px;align-items:center;">';
    echo '<input type="hidden" name="page" value="fodr-ready-dispatch">';
    echo '<label style="font-weight:bold;">🔍 Search Orders:</label>';
    echo '<input type="text" name="search" value="'.esc_attr($search_term).'" placeholder="Search by Order#, Customer Name, Email..." style="flex:1;padding:8px;border:1px solid #ddd;border-radius:4px;">';
    echo '<button class="button button-primary">Search</button>';
    if ($search_term) {
      echo '<a href="'.admin_url('admin.php?page=fodr-ready-dispatch').'" class="button">Clear</a>';
    }
    echo '</form>';
    echo '</div>';
  }

  private static function render_quick_stats() {
    global $wpdb;
    
    $stats = self::get_dispatch_stats();
    
    echo '<div style="display:flex;gap:15px;margin-bottom:20px;">';
    
    echo '<div style="background:#e8f5e8;padding:12px;border-radius:6px;text-align:center;min-width:120px;">';
    echo '<div style="font-size:24px;font-weight:bold;color:#2e7d32;">'.$stats['ready_count'].'</div>';
    echo '<div style="font-size:12px;color:#666;">Ready to Dispatch</div>';
    echo '</div>';
    
    echo '<div style="background:#fff3e0;padding:12px;border-radius:6px;text-align:center;min-width:120px;">';
    echo '<div style="font-size:24px;font-weight:bold;color:#f57f17;">'.$stats['pending_count'].'</div>';
    echo '<div style="font-size:12px;color:#666;">Pending Orders</div>';
    echo '</div>';
    
    echo '<div style="background:#e3f2fd;padding:12px;border-radius:6px;text-align:center;min-width:120px;">';
    echo '<div style="font-size:24px;font-weight:bold;color:#1976d2;">'.number_format($stats['total_value'], 0).'</div>';
    echo '<div style="font-size:12px;color:#666;">Total Value (£)</div>';
    echo '</div>';
    
    echo '</div>';
  }

  private static function render_ready_to_dispatch_section() {
    echo '<div class="card" style="padding:20px;">';
    
    // Header with bulk actions
    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">';
    echo '<h2 style="margin:0;color:#2e7d32;border-bottom:3px solid #4caf50;padding-bottom:8px;display:flex;align-items:center;">';
    echo '<span style="background:#4caf50;color:white;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;margin-right:10px;font-size:14px;">✓</span>';
    echo 'Ready to Dispatch (100% Complete)';
    echo '</h2>';
    
    $ready_orders = self::get_ready_to_dispatch_orders();
    
    // Mark All Dispatched button (only show if there are orders)
    if (!empty($ready_orders)) {
      echo '<form method="post" action="'.admin_url('admin-post.php').'" style="display:inline;">';
      wp_nonce_field('fodr_mark_all_dispatched');
      echo '<input type="hidden" name="action" value="fodr_mark_all_dispatched">';
      echo '<button class="button button-primary" onclick="return confirm(\'Mark all ready orders as dispatched? This will move '.count($ready_orders).' orders to Out for Delivery.\')" style="background:#9c27b0;border-color:#9c27b0;">';
      echo '📦 Mark All Dispatched ('.count($ready_orders).')';
      echo '</button>';
      echo '</form>';
    }
    
    echo '</div>';

    if ($ready_orders) {
      // Bulk dispatch form
      echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" id="bulk-dispatch-form">';
      wp_nonce_field('fodr_bulk_dispatch');
      echo '<input type="hidden" name="action" value="fodr_bulk_dispatch">';
      
      // Bulk action bar
      echo '<div style="background:#f0f8ff;padding:10px;border-radius:6px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;">';
      echo '<div style="display:flex;gap:10px;align-items:center;">';
      echo '<input type="checkbox" id="select-all-ready" onchange="toggleAllCheckboxes(\'ready-order-\', this.checked)">';
      echo '<label for="select-all-ready" style="font-weight:bold;">Select All</label>';
      echo '<button type="submit" class="button button-primary" onclick="return confirm(\'Dispatch selected orders?\')" style="background:#9c27b0;border-color:#9c27b0;">📦 Dispatch Selected</button>';
      echo '</div>';
      echo '<div><span id="selected-count-ready">0</span> orders selected</div>';
      echo '</div>';

      echo '<div style="overflow-x:auto;">';
      echo '<table class="wp-list-table widefat striped" style="font-size:13px;">';
      echo '<thead style="background:#f0f8f0;"><tr>';
      echo '<td class="manage-column check-column" style="width:40px;">Select</td>';
      echo '<th style="padding:12px;width:80px;">Order #</th>';
      echo '<th style="padding:12px;">Customer</th>';
      echo '<th style="padding:12px;width:100px;">Value</th>';
      echo '<th style="padding:12px;width:80px;">Parts</th>';
      echo '<th style="padding:12px;width:100px;">Completion</th>';
      echo '<th style="padding:12px;width:100px;">Created</th>';
      echo '<th style="padding:12px;width:150px;">Actions</th>';
      echo '</tr></thead><tbody>';

      foreach ($ready_orders as $order) {
        self::render_order_row($order, true);
      }

      echo '</tbody></table></div>';
      echo '</form>';
    } else {
      echo '<div style="text-align:center;padding:40px;color:#666;background:#f8f9fa;border-radius:6px;">';
      echo '<div style="font-size:48px;margin-bottom:10px;">📦</div>';
      echo '<p style="margin:0;font-size:16px;">No orders ready to dispatch</p>';
      echo '<p style="margin:5px 0 0;font-size:14px;">Orders will appear here when all parts are completed</p>';
      echo '</div>';
    }

    echo '</div>';
  }

  private static function render_pending_section() {
    echo '<div class="card" style="padding:20px;">';
    echo '<h2 style="margin:0 0 15px;color:#f57f17;border-bottom:3px solid #ff9800;padding-bottom:8px;display:flex;align-items:center;">';
    echo '<span style="background:#ff9800;color:white;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;margin-right:10px;font-size:14px;">⏳</span>';
    echo 'Pending Orders (Partially Complete)';
    echo '</h2>';

    $pending_orders = self::get_pending_orders();
    
    if ($pending_orders) {
      echo '<div style="overflow-x:auto;">';
      echo '<table class="wp-list-table widefat striped" style="font-size:13px;">';
      echo '<thead style="background:#fff8f0;"><tr>';
      echo '<th style="padding:12px;width:80px;">Order #</th>';
      echo '<th style="padding:12px;">Customer</th>';
      echo '<th style="padding:12px;width:100px;">Value</th>';
      echo '<th style="padding:12px;width:80px;">Parts</th>';
      echo '<th style="padding:12px;width:120px;">Progress</th>';
      echo '<th style="padding:12px;width:100px;">Created</th>';
      echo '<th style="padding:12px;width:150px;">Actions</th>';
      echo '</tr></thead><tbody>';

      foreach ($pending_orders as $order) {
        self::render_order_row($order, false);
      }

      echo '</tbody></table></div>';
    } else {
      echo '<div style="text-align:center;padding:40px;color:#666;background:#f8f9fa;border-radius:6px;">';
      echo '<div style="font-size:48px;margin-bottom:10px;">⏳</div>';
      echo '<p style="margin:0;font-size:16px;">No pending orders</p>';
      echo '<p style="margin:5px 0 0;font-size:14px;">Orders with partial completion will appear here</p>';
      echo '</div>';
    }

    echo '</div>';
  }

  private static function render_order_row($order, $is_ready = true) {
    $order_link = admin_url('admin.php?page=fodr-orders&view=order&order_id='.$order['order_id']);
    $progress_pct = $order['parts_total'] ? floor(($order['parts_done'] / $order['parts_total']) * 100) : 0;
    
    echo '<tr style="'.($is_ready ? 'background:#f8fff8;' : '').'">';
    
    // Checkbox (only for ready orders)
    if ($is_ready) {
      echo '<td class="check-column" style="padding:10px;">';
      echo '<input type="checkbox" name="order_ids[]" value="'.intval($order['order_id']).'" class="ready-order-checkbox" onchange="updateSelectedCount()">';
      echo '</td>';
    }
    
    // Order number
    echo '<td style="padding:10px;">';
    echo '<a href="'.esc_url($order_link).'" style="font-weight:bold;color:#0073aa;text-decoration:none;">#'.$order['order_id'].'</a>';
    echo '</td>';
    
    // Customer
    echo '<td style="padding:10px;">';
    echo '<div style="font-weight:bold;">'.esc_html($order['customer_name'] ?: 'Unknown').'</div>';
    echo '<div style="font-size:11px;color:#666;">'.esc_html($order['email']).'</div>';
    echo '</td>';
    
    // Value
    echo '<td style="padding:10px;text-align:right;">';
    echo '<strong style="color:#2e7d32;">£'.number_format((float)$order['total'], 2).'</strong>';
    echo '</td>';
    
    // Parts count
    echo '<td style="padding:10px;text-align:center;">';
    echo '<span style="background:#e3f2fd;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:bold;">';
    echo $order['parts_done'].'/'.$order['parts_total'];
    echo '</span>';
    echo '</td>';
    
    // Progress/Completion
    echo '<td style="padding:10px;">';
    if ($is_ready) {
      echo '<div style="display:flex;align-items:center;gap:8px;">';
      echo '<span style="color:#4caf50;font-weight:bold;font-size:14px;">100%</span>';
      echo '<div style="width:50px;height:6px;background:#4caf50;border-radius:3px;"></div>';
      echo '</div>';
    } else {
      echo '<div style="display:flex;align-items:center;gap:8px;">';
      echo '<span style="font-weight:bold;font-size:12px;">'.$progress_pct.'%</span>';
      echo '<div class="progress-bar" style="width:60px;">';
      echo '<div class="progress-fill" style="width:'.$progress_pct.'%;background:linear-gradient(90deg,#ff9800,#ffb74d);"></div>';
      echo '</div>';
      echo '</div>';
    }
    echo '</td>';
    
    // Created date
    echo '<td style="padding:10px;font-size:12px;">'.date_i18n('M j, Y', strtotime($order['date_created'])).'</td>';
    
    // Actions
    echo '<td style="padding:10px;">';
    echo '<div style="display:flex;gap:5px;flex-wrap:wrap;">';
    
    // View Details always available
    echo '<a href="'.esc_url($order_link).'" class="button button-small" title="View order details">👁 Details</a>';
    
    if ($is_ready) {
      // Individual Dispatch button
      echo '<form method="post" action="'.admin_url('admin-post.php').'" style="display:inline;">';
      wp_nonce_field('fodr_mark_dispatched');
      echo '<input type="hidden" name="action" value="fodr_mark_dispatched">';
      echo '<input type="hidden" name="order_id" value="'.intval($order['order_id']).'">';
      echo '<button class="button button-small" style="background:#9c27b0;color:white;border-color:#9c27b0;" onclick="return confirm(\'Mark order #'.$order['order_id'].' as dispatched?\')" title="Mark as dispatched">📦 Dispatch</button>';
      echo '</form>';
    }
    
    echo '</div>';
    echo '</td>';
    
    echo '</tr>';
  }

  // Data fetching methods
  private static function get_dispatch_stats() {
    global $wpdb;
    $orders_tbl = $wpdb->prefix.'foam_orders';
    
    $search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    $where = "WHERE parts_total > 0";
    $args = [];
    
    if ($search_term) {
      $like = '%'.$wpdb->esc_like($search_term).'%';
      $where .= " AND (order_number LIKE %s OR customer_name LIKE %s OR email LIKE %s OR CAST(order_id AS CHAR) LIKE %s)";
      $args = [$like, $like, $like, $like];
    }
    
    $ready_count = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $orders_tbl $where AND parts_done >= parts_total AND parts_total > 0", 
      $args
    ));
    
    $pending_count = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $orders_tbl $where AND parts_done > 0 AND parts_done < parts_total", 
      $args
    ));
    
    $total_value = (float)$wpdb->get_var($wpdb->prepare(
      "SELECT SUM(total) FROM $orders_tbl $where AND (parts_done >= parts_total OR (parts_done > 0 AND parts_done < parts_total))", 
      $args
    ));
    
    return [
      'ready_count' => $ready_count,
      'pending_count' => $pending_count,
      'total_value' => $total_value ?: 0
    ];
  }

  private static function get_ready_to_dispatch_orders() {
    global $wpdb;
    $orders_tbl = $wpdb->prefix.'foam_orders';
    
    $search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    $where = "WHERE parts_done >= parts_total AND parts_total > 0";
    $args = [];
    
    if ($search_term) {
      $like = '%'.$wpdb->esc_like($search_term).'%';
      $where .= " AND (order_number LIKE %s OR customer_name LIKE %s OR email LIKE %s OR CAST(order_id AS CHAR) LIKE %s)";
      $args = [$like, $like, $like, $like];
    }
    
    return $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM $orders_tbl $where ORDER BY date_created DESC LIMIT 50",
      $args
    ), ARRAY_A);
  }

  private static function get_pending_orders() {
    global $wpdb;
    $orders_tbl = $wpdb->prefix.'foam_orders';
    
    $search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    $where = "WHERE parts_done > 0 AND parts_done < parts_total";
    $args = [];
    
    if ($search_term) {
      $like = '%'.$wpdb->esc_like($search_term).'%';
      $where .= " AND (order_number LIKE %s OR customer_name LIKE %s OR email LIKE %s OR CAST(order_id AS CHAR) LIKE %s)";
      $args = [$like, $like, $like, $like];
    }
    
    return $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM $orders_tbl $where ORDER BY progress_pct DESC, date_created DESC LIMIT 50",
      $args
    ), ARRAY_A);
  }

  // Action handlers
  public static function handle_mark_dispatched(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_mark_dispatched');

    global $wpdb;
    $order_id = intval($_POST['order_id'] ?? 0);
    
    if (!$order_id) {
      wp_redirect(admin_url('admin.php?page=fodr-ready-dispatch')); 
      exit;
    }

    $orders_tbl = $wpdb->prefix.'foam_orders';
    
    // Update order status to out-for-delivery
    $wpdb->update($orders_tbl, [
      'status' => 'out-for-delivery',
      'date_modified' => current_time('mysql')
    ], ['order_id' => $order_id]);

    wp_redirect(admin_url('admin.php?page=fodr-ready-dispatch&msg=order_dispatched&order_id='.$order_id)); 
    exit;
  }

  public static function handle_bulk_dispatch(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_bulk_dispatch');

    global $wpdb;
    $order_ids = array_map('intval', (array)($_POST['order_ids'] ?? []));
    
    if (empty($order_ids)) {
      wp_redirect(admin_url('admin.php?page=fodr-ready-dispatch')); 
      exit;
    }

    $orders_tbl = $wpdb->prefix.'foam_orders';
    $order_ids_string = implode(',', $order_ids);
    
    // Update selected orders to out-for-delivery status
    $wpdb->query("
      UPDATE $orders_tbl 
      SET status = 'out-for-delivery', 
          date_modified = '".current_time('mysql')."'
      WHERE order_id IN ($order_ids_string)
    ");

    wp_redirect(admin_url('admin.php?page=fodr-ready-dispatch&msg=bulk_dispatched&count='.count($order_ids))); 
    exit;
  }

  public static function handle_mark_all_dispatched(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_mark_all_dispatched');

    global $wpdb;
    $orders_tbl = $wpdb->prefix.'foam_orders';
    
    // Get all ready orders
    $ready_orders = self::get_ready_to_dispatch_orders();
    $order_ids = array_column($ready_orders, 'order_id');
    
    if (!empty($order_ids)) {
      $order_ids_string = implode(',', array_map('intval', $order_ids));
      
      // Update all ready orders to out-for-delivery status
      $wpdb->query("
        UPDATE $orders_tbl 
        SET status = 'out-for-delivery', 
            date_modified = '".current_time('mysql')."'
        WHERE order_id IN ($order_ids_string)
      ");
      
      wp_redirect(admin_url('admin.php?page=fodr-ready-dispatch&msg=all_dispatched&count='.count($order_ids))); 
      exit;
    }

    wp_redirect(admin_url('admin.php?page=fodr-ready-dispatch')); 
    exit;
  }
}

// JavaScript for checkbox functionality
add_action('admin_footer', function(){
  if (isset($_GET['page']) && $_GET['page'] === 'fodr-ready-dispatch') {
    echo '<script>
    function toggleAllCheckboxes(className, checked) {
      const checkboxes = document.getElementsByClassName(className + "checkbox");
      for (let i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = checked;
      }
      updateSelectedCount();
    }
    
    function updateSelectedCount() {
      const readyChecked = document.querySelectorAll(".ready-order-checkbox:checked").length;
      document.getElementById("selected-count-ready").textContent = readyChecked;
      
      // Update "Select All" checkbox state
      const totalReady = document.querySelectorAll(".ready-order-checkbox").length;
      const selectAllReady = document.getElementById("select-all-ready");
      if (selectAllReady) {
        selectAllReady.checked = (readyChecked === totalReady && totalReady > 0);
        selectAllReady.indeterminate = (readyChecked > 0 && readyChecked < totalReady);
      }
    }
    
    // Initialize count on page load
    document.addEventListener("DOMContentLoaded", function() {
      updateSelectedCount();
    });
    </script>';
  }
});
?>
<?php
if (!defined('ABSPATH')) exit;

class FODR_Out_For_Delivery {

  public static function init(){
    add_action('admin_menu', [__CLASS__, 'menu']);
    add_action('admin_post_fodr_mark_delivered', [__CLASS__, 'handle_mark_delivered']);
    add_action('admin_post_fodr_mark_all_delivered', [__CLASS__, 'handle_mark_all_delivered']);
    add_action('admin_post_fodr_bulk_complete', [__CLASS__, 'handle_bulk_complete']);
    add_action('admin_post_fodr_undo_delivery', [__CLASS__, 'handle_undo_delivery']);
    add_action('admin_post_fodr_undo_all_delivery', [__CLASS__, 'handle_undo_all_delivery']);
    add_action('admin_post_fodr_bulk_undo', [__CLASS__, 'handle_bulk_undo']);
  }

  public static function menu(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    add_submenu_page(
      'fodr-dashboard', // Parent slug (Foam Orders)
      __('Out for Delivery','fod-receiver'), 
      __('Out for Delivery','fod-receiver'),
      $cap, 
      'fodr-out-for-delivery', 
      [__CLASS__, 'render']
    );
  }

  public static function render(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    if (!current_user_can($cap)) wp_die(__('Not allowed','fod-receiver'));

    echo '<div class="wrap fodr-delivery-page">';
    echo '<style>
      .wrap.fodr-delivery-page { 
        max-width: none !important; 
        width: calc(100vw - 180px) !important; 
        margin: 10px !important;
        padding-right: 20px !important;
      }
      #wpcontent {
        padding-left: 0 !important;
      }
      .fodr-delivery-page .card { 
        width: 100% !important; 
        box-sizing: border-box !important; 
        max-width: none !important;
        margin-bottom: 20px !important;
      }
      .fodr-delivery-page table {
        width: 100% !important;
        max-width: none !important;
      }
      body.folded .fodr-delivery-page {
        width: calc(100vw - 56px) !important;
      }
      @media (max-width: 960px) {
        .wrap.fodr-delivery-page {
          width: calc(100% - 10px) !important;
          margin: 5px !important;
        }
      }
    </style>';

    echo '<h1>🚚 Out for Delivery</h1>';

    // Show success messages
    if (isset($_GET['msg'])) {
      if ($_GET['msg'] === 'order_delivered') {
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        echo '<div class="notice notice-success"><p>✅ Order #'.$order_id.' has been completed and moved to Completed Orders!</p></div>';
      } elseif ($_GET['msg'] === 'all_delivered') {
        $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
        echo '<div class="notice notice-success"><p>🎉 Successfully completed '.$count.' orders! They have been moved to Completed Orders.</p></div>';
      } elseif ($_GET['msg'] === 'bulk_completed') {
        $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
        echo '<div class="notice notice-success"><p>✅ Successfully completed '.$count.' selected orders!</p></div>';
      } elseif ($_GET['msg'] === 'order_undone') {
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        echo '<div class="notice notice-success"><p>↶ Order #'.$order_id.' has been moved back to Ready to Dispatch!</p></div>';
      } elseif ($_GET['msg'] === 'all_undone') {
        $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
        echo '<div class="notice notice-success"><p>↶ Successfully moved '.$count.' orders back to Ready to Dispatch!</p></div>';
      } elseif ($_GET['msg'] === 'bulk_undone') {
        $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
        echo '<div class="notice notice-success"><p>↶ Successfully moved '.$count.' selected orders back to Ready to Dispatch!</p></div>';
      }
    }

    // Search functionality
    self::render_search_bar();

    // Quick stats
    self::render_quick_stats();

    // Main content - Out for Delivery orders
    self::render_delivery_orders_section();

    echo '</div>'; // end wrap
  }

  private static function render_search_bar() {
    $search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    
    echo '<div class="card" style="padding:15px;margin-bottom:20px;">';
    echo '<form method="get" style="display:flex;gap:10px;align-items:center;">';
    echo '<input type="hidden" name="page" value="fodr-out-for-delivery">';
    echo '<label style="font-weight:bold;">🔍 Search Orders:</label>';
    echo '<input type="text" name="search" value="'.esc_attr($search_term).'" placeholder="Search by Order#, Customer Name, Email..." style="flex:1;padding:8px;border:1px solid #ddd;border-radius:4px;">';
    echo '<button class="button button-primary">Search</button>';
    if ($search_term) {
      echo '<a href="'.admin_url('admin.php?page=fodr-out-for-delivery').'" class="button">Clear</a>';
    }
    echo '</form>';
    echo '</div>';
  }

  private static function render_quick_stats() {
    global $wpdb;
    
    $stats = self::get_delivery_stats();
    
    echo '<div style="display:flex;gap:15px;margin-bottom:20px;">';
    
    echo '<div style="background:#e3f2fd;padding:12px;border-radius:6px;text-align:center;min-width:120px;">';
    echo '<div style="font-size:24px;font-weight:bold;color:#1976d2;">'.$stats['out_for_delivery_count'].'</div>';
    echo '<div style="font-size:12px;color:#666;">Out for Delivery</div>';
    echo '</div>';
    
    echo '<div style="background:#fff3e0;padding:12px;border-radius:6px;text-align:center;min-width:120px;">';
    echo '<div style="font-size:24px;font-weight:bold;color:#f57f17;">'.number_format($stats['total_value'], 0).'</div>';
    echo '<div style="font-size:12px;color:#666;">Total Value (£)</div>';
    echo '</div>';
    
    echo '<div style="background:#f3e5f5;padding:12px;border-radius:6px;text-align:center;min-width:120px;">';
    echo '<div style="font-size:24px;font-weight:bold;color:#7b1fa2;">'.$stats['avg_delivery_time'].'</div>';
    echo '<div style="font-size:12px;color:#666;">Avg Days in Transit</div>';
    echo '</div>';
    
    echo '</div>';
  }

  private static function render_delivery_orders_section() {
    echo '<div class="card" style="padding:20px;">';
    
    // Header with Mark All Completed button and Undo All button
    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">';
    echo '<h2 style="margin:0;color:#1976d2;border-bottom:3px solid #2196f3;padding-bottom:8px;display:flex;align-items:center;">';
    echo '<span style="background:#2196f3;color:white;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;margin-right:10px;font-size:14px;">🚚</span>';
    echo 'Orders Out for Delivery';
    echo '</h2>';
    
    $delivery_orders = self::get_out_for_delivery_orders();
    
    // Action buttons (only show if there are orders)
    if (!empty($delivery_orders)) {
      echo '<div style="display:flex;gap:10px;">';
      
      // Mark All Completed button
      echo '<form method="post" action="'.admin_url('admin-post.php').'" style="display:inline;">';
      wp_nonce_field('fodr_mark_all_delivered');
      echo '<input type="hidden" name="action" value="fodr_mark_all_delivered">';
      echo '<button class="button button-primary" onclick="return confirm(\'Mark all orders as completed? This will move '.count($delivery_orders).' orders to Completed Orders.\')" style="background:#4caf50;border-color:#4caf50;">';
      echo '✅ Mark All Completed ('.count($delivery_orders).')';
      echo '</button>';
      echo '</form>';
      
      // Undo All Delivery button
      echo '<form method="post" action="'.admin_url('admin-post.php').'" style="display:inline;">';
      wp_nonce_field('fodr_undo_all_delivery');
      echo '<input type="hidden" name="action" value="fodr_undo_all_delivery">';
      echo '<button class="button" onclick="return confirm(\'Move all orders back to Ready to Dispatch? This will undo dispatch for '.count($delivery_orders).' orders.\')" style="background:#ff9800;color:white;border-color:#ff9800;">';
      echo '↶ Undo All Delivery ('.count($delivery_orders).')';
      echo '</button>';
      echo '</form>';
      
      echo '</div>';
    }
    
    echo '</div>';

    if ($delivery_orders) {
      // Bulk completion form
      echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" id="bulk-complete-form">';
      wp_nonce_field('fodr_bulk_complete');
      echo '<input type="hidden" name="action" value="fodr_bulk_complete">';
      
      // Bulk action bar
      echo '<div style="background:#f0f8f0;padding:10px;border-radius:6px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;">';
      echo '<div style="display:flex;gap:10px;align-items:center;">';
      echo '<input type="checkbox" id="select-all-delivery" onchange="toggleAllCheckboxes(\'delivery-order-\', this.checked)">';
      echo '<label for="select-all-delivery" style="font-weight:bold;">Select All</label>';
      echo '<button type="submit" class="button button-primary" onclick="return confirm(\'Complete selected orders?\')" style="background:#4caf50;border-color:#4caf50;">✅ Complete Selected</button>';
      echo '</div>';
      echo '<div><span id="selected-count-delivery">0</span> orders selected</div>';
      echo '</div>';

      echo '<div style="overflow-x:auto;">';
      echo '<table class="wp-list-table widefat striped" style="font-size:13px;">';
      echo '<thead style="background:#e3f2fd;"><tr>';
      echo '<td class="manage-column check-column" style="width:40px;">Select</td>';
      echo '<th style="padding:12px;width:80px;">Order #</th>';
      echo '<th style="padding:12px;">Customer</th>';
      echo '<th style="padding:12px;width:100px;">Value</th>';
      echo '<th style="padding:12px;width:80px;">Parts</th>';
      echo '<th style="padding:12px;width:100px;">Dispatched</th>';
      echo '<th style="padding:12px;width:100px;">Days in Transit</th>';
      echo '<th style="padding:12px;width:150px;">Actions</th>';
      echo '</tr></thead><tbody>';

      foreach ($delivery_orders as $order) {
        self::render_delivery_order_row($order);
      }

      echo '</tbody></table></div>';
      echo '</form>';
    } else {
      echo '<div style="text-align:center;padding:40px;color:#666;background:#f8f9fa;border-radius:6px;">';
      echo '<div style="font-size:48px;margin-bottom:10px;">🚚</div>';
      echo '<p style="margin:0;font-size:16px;">No orders out for delivery</p>';
      echo '<p style="margin:5px 0 0;font-size:14px;">Orders will appear here when dispatched</p>';
      echo '</div>';
    }

    echo '</div>';
  }

  private static function render_delivery_order_row($order) {
    $order_link = admin_url('admin.php?page=fodr-orders&view=order&order_id='.$order['order_id']);
    $days_in_transit = self::calculate_days_in_transit($order['date_modified']);
    
    echo '<tr style="background:#f0f8ff;">';
    
    // Checkbox
    echo '<td class="check-column" style="padding:10px;">';
    echo '<input type="checkbox" name="order_ids[]" value="'.intval($order['order_id']).'" class="delivery-order-checkbox" onchange="updateSelectedCount()">';
    echo '</td>';
    
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
    echo '<span style="background:#e8f5e8;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:bold;color:#2e7d32;">';
    echo $order['parts_done'].'/'.$order['parts_total'].' ✓';
    echo '</span>';
    echo '</td>';
    
    // Dispatched date
    echo '<td style="padding:10px;font-size:12px;">'.date_i18n('M j, Y', strtotime($order['date_modified'])).'</td>';
    
    // Days in transit
    echo '<td style="padding:10px;text-align:center;">';
    if ($days_in_transit <= 1) {
      echo '<span style="background:#e8f5e8;color:#2e7d32;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:bold;">'.$days_in_transit.' day</span>';
    } elseif ($days_in_transit <= 3) {
      echo '<span style="background:#fff3e0;color:#f57f17;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:bold;">'.$days_in_transit.' days</span>';
    } else {
      echo '<span style="background:#ffebee;color:#c62828;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:bold;">'.$days_in_transit.' days</span>';
    }
    echo '</td>';
    
    // Actions
    echo '<td style="padding:10px;">';
    echo '<div style="display:flex;gap:5px;flex-wrap:wrap;">';
    
    // View Details
    echo '<a href="'.esc_url($order_link).'" class="button button-small" title="View order details">👁 Details</a>';
    
    // Individual Complete button
    echo '<form method="post" action="'.admin_url('admin-post.php').'" style="display:inline;">';
    wp_nonce_field('fodr_mark_delivered');
    echo '<input type="hidden" name="action" value="fodr_mark_delivered">';
    echo '<input type="hidden" name="order_id" value="'.intval($order['order_id']).'">';
    echo '<button class="button button-primary button-small" onclick="return confirm(\'Mark order #'.$order['order_id'].' as completed?\')" style="background:#4caf50;border-color:#4caf50;" title="Mark as completed">✅ Complete</button>';
    echo '</form>';
    
    // Individual Undo button
    echo '<form method="post" action="'.admin_url('admin-post.php').'" style="display:inline;">';
    wp_nonce_field('fodr_undo_delivery');
    echo '<input type="hidden" name="action" value="fodr_undo_delivery">';
    echo '<input type="hidden" name="order_id" value="'.intval($order['order_id']).'">';
    echo '<button class="button button-small" onclick="return confirm(\'Move order #'.$order['order_id'].' back to Ready to Dispatch?\')" style="background:#ff9800;color:white;border-color:#ff9800;" title="Undo delivery">↶ Undo</button>';
    echo '</form>';
    
    echo '</div>';
    echo '</td>';
    
    echo '</tr>';
  }

  // Helper methods
  private static function calculate_days_in_transit($dispatch_date) {
    $dispatch_time = strtotime($dispatch_date);
    $current_time = current_time('timestamp');
    $diff_days = floor(($current_time - $dispatch_time) / (60 * 60 * 24));
    return max(0, $diff_days);
  }

  // Data fetching methods
  private static function get_delivery_stats() {
    global $wpdb;
    $orders_tbl = $wpdb->prefix.'foam_orders';
    
    $search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    $where = "WHERE status = 'out-for-delivery'";
    $args = [];
    
    if ($search_term) {
      $like = '%'.$wpdb->esc_like($search_term).'%';
      $where .= " AND (order_number LIKE %s OR customer_name LIKE %s OR email LIKE %s OR CAST(order_id AS CHAR) LIKE %s)";
      $args = [$like, $like, $like, $like];
    }
    
    $out_for_delivery_count = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $orders_tbl $where", 
      $args
    ));
    
    $total_value = (float)$wpdb->get_var($wpdb->prepare(
      "SELECT SUM(total) FROM $orders_tbl $where", 
      $args
    ));
    
    // Calculate average delivery time
    $avg_days = $wpdb->get_var($wpdb->prepare(
      "SELECT AVG(DATEDIFF(NOW(), date_modified)) FROM $orders_tbl $where", 
      $args
    ));
    
    return [
      'out_for_delivery_count' => $out_for_delivery_count,
      'total_value' => $total_value ?: 0,
      'avg_delivery_time' => $avg_days ? round($avg_days, 1) : 0
    ];
  }

  private static function get_out_for_delivery_orders() {
    global $wpdb;
    $orders_tbl = $wpdb->prefix.'foam_orders';
    
    $search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    $where = "WHERE status = 'out-for-delivery'";
    $args = [];
    
    if ($search_term) {
      $like = '%'.$wpdb->esc_like($search_term).'%';
      $where .= " AND (order_number LIKE %s OR customer_name LIKE %s OR email LIKE %s OR CAST(order_id AS CHAR) LIKE %s)";
      $args = [$like, $like, $like, $like];
    }
    
    return $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM $orders_tbl $where ORDER BY date_modified DESC LIMIT 100",
      $args
    ), ARRAY_A);
  }

  // Action handlers
  public static function handle_mark_delivered(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_mark_delivered');

    global $wpdb;
    $order_id = intval($_POST['order_id'] ?? 0);
    
    if (!$order_id) {
      wp_redirect(admin_url('admin.php?page=fodr-out-for-delivery')); 
      exit;
    }

    $orders_tbl = $wpdb->prefix.'foam_orders';
    
    // Update order status to completed
    $wpdb->update($orders_tbl, [
      'status' => 'completed',
      'date_modified' => current_time('mysql')
    ], ['order_id' => $order_id]);

    wp_redirect(admin_url('admin.php?page=fodr-out-for-delivery&msg=order_delivered&order_id='.$order_id)); 
    exit;
  }

  // Mark all orders as completed
  public static function handle_mark_all_delivered(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_mark_all_delivered');

    global $wpdb;
    $orders_tbl = $wpdb->prefix.'foam_orders';
    
    // Get all out for delivery orders
    $delivery_orders = self::get_out_for_delivery_orders();
    $order_ids = array_column($delivery_orders, 'order_id');
    
    if (!empty($order_ids)) {
      $order_ids_string = implode(',', array_map('intval', $order_ids));
      
      // Update all orders to completed status
      $wpdb->query("
        UPDATE $orders_tbl 
        SET status = 'completed', 
            date_modified = '".current_time('mysql')."'
        WHERE order_id IN ($order_ids_string)
      ");
      
      wp_redirect(admin_url('admin.php?page=fodr-out-for-delivery&msg=all_delivered&count='.count($order_ids))); 
      exit;
    }

    wp_redirect(admin_url('admin.php?page=fodr-out-for-delivery')); 
    exit;
  }

  // NEW: Bulk complete handler
  public static function handle_bulk_complete(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_bulk_complete');

    global $wpdb;
    $order_ids = array_map('intval', (array)($_POST['order_ids'] ?? []));
    
    if (empty($order_ids)) {
      wp_redirect(admin_url('admin.php?page=fodr-out-for-delivery')); 
      exit;
    }

    $orders_tbl = $wpdb->prefix.'foam_orders';
    $order_ids_string = implode(',', $order_ids);
    
    // Update selected orders to completed status
    $wpdb->query("
      UPDATE $orders_tbl 
      SET status = 'completed', 
          date_modified = '".current_time('mysql')."'
      WHERE order_id IN ($order_ids_string)
    ");

    wp_redirect(admin_url('admin.php?page=fodr-out-for-delivery&msg=bulk_completed&count='.count($order_ids))); 
    exit;
  }

  // NEW: Individual undo handler
  public static function handle_undo_delivery(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_undo_delivery');

    global $wpdb;
    $order_id = intval($_POST['order_id'] ?? 0);
    
    if (!$order_id) {
      wp_redirect(admin_url('admin.php?page=fodr-out-for-delivery')); 
      exit;
    }

    $orders_tbl = $wpdb->prefix.'foam_orders';
    
    // Move order back to ready-to-dispatch status (using processing status)
    $wpdb->update($orders_tbl, [
      'status' => 'processing',
      'date_modified' => current_time('mysql')
    ], ['order_id' => $order_id]);

    wp_redirect(admin_url('admin.php?page=fodr-out-for-delivery&msg=order_undone&order_id='.$order_id)); 
    exit;
  }

  // NEW: Undo all delivery handler
  public static function handle_undo_all_delivery(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_undo_all_delivery');

    global $wpdb;
    $orders_tbl = $wpdb->prefix.'foam_orders';
    
    // Get all out for delivery orders
    $delivery_orders = self::get_out_for_delivery_orders();
    $order_ids = array_column($delivery_orders, 'order_id');
    
    if (!empty($order_ids)) {
      $order_ids_string = implode(',', array_map('intval', $order_ids));
      
      // Update all orders back to processing status (ready to dispatch)
      $wpdb->query("
        UPDATE $orders_tbl 
        SET status = 'processing', 
            date_modified = '".current_time('mysql')."'
        WHERE order_id IN ($order_ids_string)
      ");
      
      wp_redirect(admin_url('admin.php?page=fodr-out-for-delivery&msg=all_undone&count='.count($order_ids))); 
      exit;
    }

    wp_redirect(admin_url('admin.php?page=fodr-out-for-delivery')); 
    exit;
  }

  // NEW: Bulk undo handler
  public static function handle_bulk_undo(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_bulk_undo');

    global $wpdb;
    $order_ids = array_map('intval', (array)($_POST['order_ids'] ?? []));
    
    if (empty($order_ids)) {
      wp_redirect(admin_url('admin.php?page=fodr-out-for-delivery')); 
      exit;
    }

    $orders_tbl = $wpdb->prefix.'foam_orders';
    $order_ids_string = implode(',', $order_ids);
    
    // Update selected orders back to processing status (ready to dispatch)
    $wpdb->query("
      UPDATE $orders_tbl 
      SET status = 'processing', 
          date_modified = '".current_time('mysql')."'
      WHERE order_id IN ($order_ids_string)
    ");

    wp_redirect(admin_url('admin.php?page=fodr-out-for-delivery&msg=bulk_undone&count='.count($order_ids))); 
    exit;
  }
}

// JavaScript for checkbox functionality
add_action('admin_footer', function(){
  if (isset($_GET['page']) && $_GET['page'] === 'fodr-out-for-delivery') {
    echo '<script>
    function toggleAllCheckboxes(className, checked, formType) {
      const checkboxes = document.getElementsByClassName(className + "checkbox");
      for (let i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = checked;
      }
      
      // Also sync the undo mirror checkboxes
      const undoMirrorCheckboxes = document.getElementsByClassName("undo-mirror-checkbox");
      for (let i = 0; i < undoMirrorCheckboxes.length; i++) {
        undoMirrorCheckboxes[i].checked = checked;
      }
      
      updateSelectedCount();
    }
    
    function updateSelectedCount() {
      const deliveryChecked = document.querySelectorAll(".delivery-order-checkbox:checked").length;
      document.getElementById("selected-count-delivery").textContent = deliveryChecked;
      
      // Update undo count (same checkboxes, different form)
      const undoCountElement = document.getElementById("selected-count-undo");
      if (undoCountElement) {
        undoCountElement.textContent = deliveryChecked;
      }
      
      // Update "Select All" checkbox states
      const totalDelivery = document.querySelectorAll(".delivery-order-checkbox").length;
      
      const selectAllDelivery = document.getElementById("select-all-delivery");
      if (selectAllDelivery) {
        selectAllDelivery.checked = (deliveryChecked === totalDelivery && totalDelivery > 0);
        selectAllDelivery.indeterminate = (deliveryChecked > 0 && deliveryChecked < totalDelivery);
      }
      
      const selectAllUndo = document.getElementById("select-all-undo");
      if (selectAllUndo) {
        selectAllUndo.checked = (deliveryChecked === totalDelivery && totalDelivery > 0);
        selectAllUndo.indeterminate = (deliveryChecked > 0 && deliveryChecked < totalDelivery);
      }
      
      // Sync undo mirror checkboxes with main checkboxes
      const mainCheckboxes = document.querySelectorAll(".delivery-order-checkbox");
      const undoMirrorCheckboxes = document.querySelectorAll(".undo-mirror-checkbox");
      
      mainCheckboxes.forEach((mainCheckbox, index) => {
        if (undoMirrorCheckboxes[index]) {
          undoMirrorCheckboxes[index].checked = mainCheckbox.checked;
        }
      });
    }
    
    // Initialize count on page load
    document.addEventListener("DOMContentLoaded", function() {
      updateSelectedCount();
      
      // Sync both select-all checkboxes
      const selectAllDelivery = document.getElementById("select-all-delivery");
      const selectAllUndo = document.getElementById("select-all-undo");
      
      if (selectAllDelivery && selectAllUndo) {
        selectAllDelivery.addEventListener("change", function() {
          selectAllUndo.checked = this.checked;
          toggleAllCheckboxes("delivery-order-", this.checked);
        });
        
        selectAllUndo.addEventListener("change", function() {
          selectAllDelivery.checked = this.checked;
          toggleAllCheckboxes("delivery-order-", this.checked);
        });
      }
      
      // Add change event listeners to main checkboxes to sync with mirrors
      const mainCheckboxes = document.querySelectorAll(".delivery-order-checkbox");
      mainCheckboxes.forEach(checkbox => {
        checkbox.addEventListener("change", updateSelectedCount);
      });
    });
    </script>';
  }
});
?>
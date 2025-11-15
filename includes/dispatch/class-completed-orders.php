<?php
if (!defined('ABSPATH')) exit;

class FODR_Completed_Orders {

  public static function init(){
    add_action('admin_menu', [__CLASS__, 'menu']);
  }

  public static function menu(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    add_submenu_page(
      'fodr-dashboard', 
      __('Completed Orders','fod-receiver'), 
      __('Completed Orders','fod-receiver'),
      $cap, 
      'fodr-completed-orders', 
      [__CLASS__, 'render']
    );
  }

  public static function render(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    if (!current_user_can($cap)) wp_die(__('Not allowed','fod-receiver'));

    echo '<div class="wrap fodr-completed-orders-page">';
    echo '<style>
      .wrap.fodr-completed-orders-page { 
        max-width: none !important; 
        width: calc(100vw - 180px) !important; 
        margin: 10px !important;
        padding-right: 20px !important;
      }
      #wpcontent { padding-left: 0 !important; }
      .fodr-completed-orders-page .card { 
        width: 100% !important; 
        box-sizing: border-box !important; 
        max-width: none !important;
        margin-bottom: 20px !important;
      }
      .fodr-completed-orders-page table { width: 100% !important; max-width: none !important; }
      body.folded .fodr-completed-orders-page { width: calc(100vw - 56px) !important; }
      @media (max-width: 960px) {
        .wrap.fodr-completed-orders-page { width: calc(100% - 10px) !important; margin: 5px !important; }
      }
    </style>';

    echo '<h1>✅ Completed Orders</h1>';

    // Search functionality
    self::render_search_bar();

    // Quick stats
    self::render_quick_stats();

    // Main content
    self::render_completed_orders_table();

    echo '</div>';
  }

  private static function render_search_bar() {
    $search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
    
    echo '<div class="card" style="padding:15px;margin-bottom:20px;">';
    echo '<form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">';
    echo '<input type="hidden" name="page" value="fodr-completed-orders">';
    
    echo '<label style="font-weight:bold;">🔍 Search:</label>';
    echo '<input type="text" name="search" value="'.esc_attr($search_term).'" placeholder="Order#, Customer, Email..." style="flex:1;min-width:200px;padding:8px;border:1px solid #ddd;border-radius:4px;">';
    
    echo '<label style="font-weight:bold;margin-left:15px;">📅 Date Range:</label>';
    echo '<input type="date" name="date_from" value="'.esc_attr($date_from).'" style="padding:8px;border:1px solid #ddd;border-radius:4px;">';
    echo '<span style="margin:0 5px;">to</span>';
    echo '<input type="date" name="date_to" value="'.esc_attr($date_to).'" style="padding:8px;border:1px solid #ddd;border-radius:4px;">';
    
    echo '<button class="button button-primary">Search</button>';
    if ($search_term || $date_from || $date_to) {
      echo '<a href="'.admin_url('admin.php?page=fodr-completed-orders').'" class="button">Clear</a>';
    }
    echo '</form>';
    echo '</div>';
  }

  private static function render_quick_stats() {
    global $wpdb;
    $orders_tbl = $wpdb->prefix.'foam_orders';
    
    // Get basic stats
    $total_completed = (int)$wpdb->get_var("SELECT COUNT(*) FROM $orders_tbl WHERE status = 'completed'");
    $total_value = (float)$wpdb->get_var("SELECT SUM(total) FROM $orders_tbl WHERE status = 'completed'");
    $this_month = (int)$wpdb->get_var("
      SELECT COUNT(*) FROM $orders_tbl 
      WHERE status = 'completed' 
      AND YEAR(date_modified) = YEAR(CURDATE()) 
      AND MONTH(date_modified) = MONTH(CURDATE())
    ");
    
    $avg_order_value = $total_completed > 0 ? $total_value / $total_completed : 0;
    $avg_days = $wpdb->get_var("SELECT AVG(DATEDIFF(date_modified, date_created)) FROM $orders_tbl WHERE status = 'completed'");
    
    echo '<div style="display:flex;gap:15px;margin-bottom:20px;flex-wrap:wrap;">';
    
    echo '<div style="background:#e8f5e8;padding:12px;border-radius:6px;text-align:center;min-width:120px;">';
    echo '<div style="font-size:24px;font-weight:bold;color:#2e7d32;">'.$total_completed.'</div>';
    echo '<div style="font-size:12px;color:#666;">Total Completed</div>';
    echo '</div>';
    
    echo '<div style="background:#e3f2fd;padding:12px;border-radius:6px;text-align:center;min-width:120px;">';
    echo '<div style="font-size:24px;font-weight:bold;color:#1976d2;">£'.number_format($total_value, 0).'</div>';
    echo '<div style="font-size:12px;color:#666;">Total Value</div>';
    echo '</div>';
    
    echo '<div style="background:#fff3e0;padding:12px;border-radius:6px;text-align:center;min-width:120px;">';
    echo '<div style="font-size:24px;font-weight:bold;color:#f57f17;">'.$this_month.'</div>';
    echo '<div style="font-size:12px;color:#666;">This Month</div>';
    echo '</div>';
    
    echo '<div style="background:#f3e5f5;padding:12px;border-radius:6px;text-align:center;min-width:120px;">';
    echo '<div style="font-size:24px;font-weight:bold;color:#7b1fa2;">£'.number_format($avg_order_value, 0).'</div>';
    echo '<div style="font-size:12px;color:#666;">Avg Order Value</div>';
    echo '</div>';
    
    if ($avg_days) {
      echo '<div style="background:#e0f2f1;padding:12px;border-radius:6px;text-align:center;min-width:120px;">';
      echo '<div style="font-size:24px;font-weight:bold;color:#00796b;">'.round($avg_days, 1).'</div>';
      echo '<div style="font-size:12px;color:#666;">Avg Days to Complete</div>';
      echo '</div>';
    }
    
    echo '</div>';
  }

  private static function render_completed_orders_table() {
    echo '<div class="card" style="padding:20px;">';
    
    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">';
    echo '<h2 style="margin:0;color:#2e7d32;border-bottom:3px solid #4caf50;padding-bottom:8px;display:flex;align-items:center;">';
    echo '<span style="background:#4caf50;color:white;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;margin-right:10px;font-size:14px;">✅</span>';
    echo 'Completed Orders';
    echo '</h2>';
    
    echo '<div>';
    echo '<button class="button" onclick="alert(\'Export functionality will be implemented here\')" title="Export to CSV">📊 Export</button>';
    echo '</div>';
    echo '</div>';

    $completed_orders = self::get_completed_orders();
    
    if ($completed_orders) {
      $showing = count($completed_orders);
      $total = self::get_completed_orders_count();
      
      echo '<div style="margin-bottom:10px;color:#666;font-size:13px;">';
      echo 'Showing '.$showing.' of '.$total.' completed orders';
      echo '</div>';
      
      echo '<div style="overflow-x:auto;">';
      echo '<table class="wp-list-table widefat striped" style="font-size:13px;">';
      echo '<thead style="background:#f0f8f0;"><tr>';
      echo '<th style="padding:12px;width:80px;">Order #</th>';
      echo '<th style="padding:12px;">Customer</th>';
      echo '<th style="padding:12px;width:100px;">Value</th>';
      echo '<th style="padding:12px;width:80px;">Parts</th>';
      echo '<th style="padding:12px;width:100px;">Created</th>';
      echo '<th style="padding:12px;width:100px;">Completed</th>';
      echo '<th style="padding:12px;width:80px;">Duration</th>';
      echo '<th style="padding:12px;width:120px;">Actions</th>';
      echo '</tr></thead><tbody>';

      foreach ($completed_orders as $order) {
        self::render_order_row($order);
      }

      echo '</tbody></table></div>';
      
      // Simple pagination if needed
      if ($total > 100) {
        echo '<div style="margin-top:15px;padding:10px;background:#f8f9fa;border-radius:6px;text-align:center;font-size:12px;color:#666;">';
        echo 'Showing latest 100 orders. Use date filters to see more specific results.';
        echo '</div>';
      }
    } else {
      echo '<div style="text-align:center;padding:40px;color:#666;background:#f8f9fa;border-radius:6px;">';
      echo '<div style="font-size:48px;margin-bottom:10px;">✅</div>';
      echo '<p style="margin:0;font-size:16px;">No completed orders found</p>';
      echo '<p style="margin:5px 0 0;font-size:14px;">Orders will appear here when marked as completed</p>';
      echo '</div>';
    }

    echo '</div>';
  }

  private static function render_order_row($order) {
    $order_link = admin_url('admin.php?page=fodr-orders&view=order&order_id='.$order['order_id']);
    
    // Calculate duration
    $created_time = strtotime($order['date_created']);
    $completed_time = strtotime($order['date_modified']);
    $duration_days = floor(($completed_time - $created_time) / (60 * 60 * 24));
    
    echo '<tr style="background:#f8fff8;">';
    
    // Order number
    echo '<td style="padding:10px;">';
    echo '<a href="'.esc_url($order_link).'" style="font-weight:bold;color:#0073aa;text-decoration:none;">#'.$order['order_id'].'</a>';
    echo '</td>';
    
    // Customer
    echo '<td style="padding:10px;">';
    echo '<div style="font-weight:bold;">'.esc_html($order['customer_name'] ?: 'Unknown').'</div>';
    echo '<div style="font-size:11px;color:#666;">'.esc_html($order['email']).'</div>';
    if ($order['phone']) {
      echo '<div style="font-size:11px;color:#666;">'.esc_html($order['phone']).'</div>';
    }
    echo '</td>';
    
    // Value
    echo '<td style="padding:10px;text-align:right;">';
    echo '<strong style="color:#2e7d32;">£'.number_format((float)$order['total'], 2).'</strong>';
    if ($order['currency'] !== 'GBP') {
      echo '<div style="font-size:11px;color:#666;">'.esc_html($order['currency']).'</div>';
    }
    echo '</td>';
    
    // Parts
    echo '<td style="padding:10px;text-align:center;">';
    $parts_done = (int)($order['parts_done'] ?: 0);
    $parts_total = (int)($order['parts_total'] ?: 0);
    
    if ($parts_total > 0) {
      echo '<span style="background:#e8f5e8;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:bold;color:#2e7d32;">';
      echo $parts_done.'/'.$parts_total.' ✓';
      echo '</span>';
    } else {
      echo '<span style="background:#f5f5f5;padding:3px 8px;border-radius:4px;font-size:11px;color:#666;">No Parts</span>';
    }
    echo '</td>';
    
    // Created date
    echo '<td style="padding:10px;font-size:12px;">';
    echo date_i18n('M j, Y', strtotime($order['date_created']));
    echo '</td>';
    
    // Completed date
    echo '<td style="padding:10px;font-size:12px;">';
    echo date_i18n('M j, Y', strtotime($order['date_modified']));
    echo '</td>';
    
    // Duration
    echo '<td style="padding:10px;text-align:center;">';
    if ($duration_days == 0) {
      echo '<span style="background:#e8f5e8;color:#2e7d32;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:bold;">Same day</span>';
    } elseif ($duration_days <= 3) {
      echo '<span style="background:#e8f5e8;color:#2e7d32;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:bold;">'.$duration_days.' days</span>';
    } elseif ($duration_days <= 7) {
      echo '<span style="background:#fff3e0;color:#f57f17;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:bold;">'.$duration_days.' days</span>';
    } else {
      echo '<span style="background:#ffebee;color:#c62828;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:bold;">'.$duration_days.' days</span>';
    }
    echo '</td>';
    
    // Actions
    echo '<td style="padding:10px;">';
    echo '<div style="display:flex;gap:5px;flex-wrap:wrap;">';
    echo '<a href="'.esc_url($order_link).'" class="button button-small" title="View order details">👁 Details</a>';
    echo '<button class="button button-small" onclick="alert(\'Receipt download coming soon\')" title="Download receipt">📄 Receipt</button>';
    echo '</div>';
    echo '</td>';
    
    echo '</tr>';
  }

  // Simple data fetching
  private static function get_completed_orders() {
    global $wpdb;
    $orders_tbl = $wpdb->prefix.'foam_orders';
    
    $search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
    
    $where = "WHERE status = 'completed'";
    $args = [];
    
    if ($search_term) {
      $like = '%'.$wpdb->esc_like($search_term).'%';
      $where .= " AND (order_number LIKE %s OR customer_name LIKE %s OR email LIKE %s OR CAST(order_id AS CHAR) LIKE %s)";
      $args = [$like, $like, $like, $like];
    }
    
    if ($date_from) {
      $where .= " AND DATE(date_modified) >= %s";
      $args[] = $date_from;
    }
    
    if ($date_to) {
      $where .= " AND DATE(date_modified) <= %s";
      $args[] = $date_to;
    }
    
    return $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM $orders_tbl $where ORDER BY date_modified DESC LIMIT 100",
      $args
    ), ARRAY_A);
  }

  private static function get_completed_orders_count() {
    global $wpdb;
    $orders_tbl = $wpdb->prefix.'foam_orders';
    
    $search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
    
    $where = "WHERE status = 'completed'";
    $args = [];
    
    if ($search_term) {
      $like = '%'.$wpdb->esc_like($search_term).'%';
      $where .= " AND (order_number LIKE %s OR customer_name LIKE %s OR email LIKE %s OR CAST(order_id AS CHAR) LIKE %s)";
      $args = [$like, $like, $like, $like];
    }
    
    if ($date_from) {
      $where .= " AND DATE(date_modified) >= %s";
      $args[] = $date_from;
    }
    
    if ($date_to) {
      $where .= " AND DATE(date_modified) <= %s";
      $args[] = $date_to;
    }
    
    return (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $orders_tbl $where", 
      $args
    ));
  }
}
?>
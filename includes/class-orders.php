<?php
if (!defined('ABSPATH')) exit;

class FODR_Orders {
  public static function init(){
    add_action('admin_menu', [__CLASS__, 'menu']);
    add_action('admin_post_fodr_delete_orders', [__CLASS__, 'handle_bulk_delete']);
  }

  public static function menu(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    add_submenu_page('fodr-dashboard', __('Orders','fod-receiver'), __('Orders','fod-receiver'),
      $cap, 'fodr-orders', [__CLASS__, 'render']);
  }

  public static function render(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    if (!current_user_can($cap)) { wp_die(__('Sorry, you are not allowed to access this page.')); }

    // Check if we're viewing a single order
    if (isset($_GET['view']) && $_GET['view'] === 'order') {
      self::render_single_order();
      return;
    }

    global $wpdb;
    $tbl = $wpdb->prefix.'foam_orders';

    // Filters
    $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $s      = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $grade  = isset($_GET['grade']) ? sanitize_text_field($_GET['grade']) : '';
    $source = isset($_GET['source']) ? sanitize_text_field($_GET['source']) : '';

    // WHERE
    $where = '1=1'; $args = [];
    if ($status){ $where .= ' AND status=%s'; $args[] = $status; }
    if ($s){
      $like = '%'.$wpdb->esc_like($s).'%';
      $where .= ' AND (order_number LIKE %s OR customer_name LIKE %s OR email LIKE %s)';
      $args[] = $like; $args[] = $like; $args[] = $like;
    }
    if ($grade){
      $likeGrade = '%'.$wpdb->esc_like($grade).'%';
      $where .= ' AND grades LIKE %s';
      $args[] = $likeGrade;
    }
    if ($source){
      $where .= ' AND source=%s';
      $args[] = $source;
    }

    // Pagination
    $paged = max(1, intval($_GET['paged'] ?? 1));
    $per   = 20;
    $off   = ($paged-1)*$per;

    $total = (int)$wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$tbl} WHERE {$where}", $args) );
    $rows  = $wpdb->get_results(
      $wpdb->prepare("SELECT * FROM {$tbl} WHERE {$where} ORDER BY date_created DESC LIMIT %d OFFSET %d",
        array_merge($args, [$per, $off])
      ), ARRAY_A
    );

    // Get unique sources for filter
    $sources = $wpdb->get_col("SELECT DISTINCT source FROM {$tbl} WHERE source IS NOT NULL AND source != '' ORDER BY source");

    echo '<div class="wrap"><h1>Orders</h1>';

    // Filters UI
    echo '<form method="get" style="margin-bottom:12px;display:flex;gap:8px;align-items:center;">';
    echo '<input type="hidden" name="page" value="fodr-orders" />';
    echo '<input type="search" name="s" value="'.esc_attr($s).'" placeholder="Search..."> ';
    echo '<select name="status"><option value="">All Statuses</option>';
    foreach (['pending','processing','on-hold','completed','cancelled','refunded','failed','out-for-delivery'] as $st){
      printf('<option %s value="%s">%s</option>', selected($status,$st,false), esc_attr($st), esc_html(ucfirst(str_replace('-', ' ', $st))));
    }
    echo '</select>';
    echo '<select name="source"><option value="">All Sources</option>';
    foreach ($sources as $src){
      printf('<option %s value="%s">%s</option>', selected($source,$src,false), esc_attr($src), esc_html($src));
    }
    echo '</select>';
    echo '<input type="text" name="grade" value="'.esc_attr($grade).'" placeholder="Filter by grade name">';
    echo ' <button class="button">Filter</button></form>';

    // BULK form start
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    wp_nonce_field('fodr_delete_orders');
    echo '<input type="hidden" name="action" value="fodr_delete_orders">';

    // Bulk actions bar (top)
    echo '<div class="tablenav top" style="margin-bottom:8px">';
    echo '<div class="alignleft actions">';
    echo '<select name="bulk_action"><option value="">Bulk actions</option><option value="delete">Delete selected</option></select> ';
    echo '<button class="button action" onclick="return confirm(\'Delete selected orders (and their items/parts)?\')">Apply</button>';
    echo '</div></div>';

    // Table
    echo '<table class="widefat fixed striped"><thead><tr>
      <td class="manage-column check-column"><input type="checkbox" onclick="jQuery(\'.fodr-order-chk\').prop(\'checked\', this.checked)"></td>
      <th>Task</th>
      <th>Source</th>
      <th>Grades</th>
      <th>Depths</th>
      <th>Job Description</th>
      <th>Progress</th>
      <th>Status</th>
      <th>Priority</th>
      <th>Order Value</th>
      <th>Date Rec</th>
      <th>Actions</th>
    </tr></thead><tbody>';

    if ($rows){
      foreach($rows as $r){
        $task     = esc_html($r['order_id'].' - '.$r['customer_name']);
        $source   = esc_html($r['source'] ?? 'Unknown');
        $grades   = esc_html($r['grades'] ?? '');
        $depths   = esc_html($r['depths'] ?? '');
        $job_desc = esc_html($r['job_desc'] ?? '');
        $progress = (isset($r['parts_total']) && (int)$r['parts_total'] > 0)
          ? intval($r['parts_done']).'/'.intval($r['parts_total']).' ('.intval($r['progress_pct']).'%)'
          : '—';
        $status_h = esc_html(ucfirst(str_replace('-', ' ', $r['status'])));
        $priority = esc_html($r['priority'] ?? '');
        $val      = number_format((float)$r['total'], 2);
        $date_rec = esc_html(date_i18n('M j', strtotime($r['date_created'])));
        
        // View Order link
        $view_link = admin_url('admin.php?page=fodr-orders&view=order&order_id='.intval($r['order_id']));

        echo '<tr>
          <th class="check-column"><input class="fodr-order-chk" type="checkbox" name="order_ids[]" value="'.intval($r['order_id']).'"></th>
          <td>'.$task.'</td>
          <td><span style="background:#e3f2fd;padding:2px 6px;border-radius:3px;font-size:11px;">'.$source.'</span></td>
          <td>'.$grades.'</td>
          <td>'.$depths.'</td>
          <td>'.$job_desc.'</td>
          <td>'.$progress.'</td>
          <td>'.$status_h.'</td>
          <td>'.$priority.'</td>
          <td>'.$val.'</td>
          <td>'.$date_rec.'</td>
          <td><a href="'.esc_url($view_link).'" class="button button-small">View Order</a></td>
        </tr>';
      }
    } else {
      echo '<tr><td colspan="12">No orders yet.</td></tr>';
    }

    echo '</tbody></table>';

    // Bulk actions bar (bottom)
    echo '<div class="tablenav bottom" style="margin-top:8px">';
    echo '<div class="alignleft actions">';
    echo '<select name="bulk_action_bottom"><option value="">Bulk actions</option><option value="delete">Delete selected</option></select> ';
    echo '<button class="button action" onclick="return confirm(\'Delete selected orders (and their items/parts)?\')">Apply</button>';
    echo '</div></div>';

    echo '</form>'; // end bulk form

    // Pagination
    $pages = max(1, (int)ceil($total/$per));
    if ($pages>1){
      $base = add_query_arg(['page'=>'fodr-orders','status'=>$status,'s'=>$s,'grade'=>$grade,'source'=>$source,'paged'=>'%#%']);
      echo '<div class="tablenav"><div class="tablenav-pages">'.paginate_links([
        'base'=>$base,'format'=>'','total'=>$pages,'current'=>$paged,'prev_text'=>'&laquo;','next_text'=>'&raquo;'
      ]).'</div></div>';
    }

    echo '</div>'; // .wrap
  }

  // New method to render single order view
  private static function render_single_order(){
    global $wpdb;
    
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
    if (!$order_id) {
      echo '<div class="wrap"><div class="notice notice-error"><p>Invalid order ID.</p></div></div>';
      return;
    }

    $orders_tbl = $wpdb->prefix.'foam_orders';
    $items_tbl = $wpdb->prefix.'foam_order_items';
    $parts_tbl = $wpdb->prefix.'foam_order_parts';

    // Get order details
    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $orders_tbl WHERE order_id = %d", $order_id), ARRAY_A);
    if (!$order) {
      echo '<div class="wrap"><div class="notice notice-error"><p>Order not found.</p></div></div>';
      return;
    }

    // Get order items
    $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM $items_tbl WHERE order_id = %d", $order_id), ARRAY_A);
    
    // Get order parts
    $parts = $wpdb->get_results($wpdb->prepare("SELECT * FROM $parts_tbl WHERE order_id = %d ORDER BY created_at ASC", $order_id), ARRAY_A);

    // Parse addresses
    $billing_address = null;
    $shipping_address = null;
    
    if ($order['billing_address']) {
      $billing_address = json_decode($order['billing_address'], true);
    }
    if ($order['shipping_address']) {
      $shipping_address = json_decode($order['shipping_address'], true);
    }

    echo '<div class="wrap fodr-order-detail" style="max-width: none !important; width: 100% !important; margin: 0 !important;">';
    echo '<style>
      .fodr-order-detail { 
        max-width: none !important; 
        width: calc(100vw - 180px) !important; 
        margin: 0 !important;
        padding-right: 20px !important;
      }
      .fodr-order-detail .card { 
        width: 100% !important; 
        box-sizing: border-box !important; 
        max-width: none !important;
      }
      .fodr-order-detail table {
        width: 100% !important;
        max-width: none !important;
      }
      body.folded .fodr-order-detail {
        width: calc(100vw - 56px) !important;
      }
    </style>';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">';
    echo '<h1 style="margin:0;">Order #'.esc_html($order['order_number']).'</h1>';
    echo '<a href="'.admin_url('admin.php?page=fodr-orders').'" class="button">← Back to Orders</a>';
    echo '</div>';

    // Order header with source
    echo '<div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:15px;margin-bottom:15px;">';
    
    // Customer Info
    echo '<div class="card" style="padding:12px;">';
    echo '<h3 style="margin:0 0 8px;font-size:14px;color:#666;">CUSTOMER</h3>';
    echo '<div style="font-size:16px;font-weight:bold;margin-bottom:4px;">'.esc_html($order['customer_name']).'</div>';
    echo '<div style="font-size:13px;color:#666;line-height:1.3;">';
    echo '<a href="mailto:'.esc_attr($order['email']).'" style="color:#0073aa;">'.esc_html($order['email']).'</a><br>';
    echo esc_html($order['phone']);
    echo '</div></div>';
    
    // Source Info
    echo '<div class="card" style="padding:12px;">';
    echo '<h3 style="margin:0 0 8px;font-size:14px;color:#666;">SOURCE</h3>';
    echo '<div style="font-size:16px;font-weight:bold;margin-bottom:4px;color:#0073aa;">'.esc_html($order['source'] ?? 'Unknown').'</div>';
    echo '<div style="font-size:13px;color:#666;line-height:1.3;">';
    echo 'Order received from<br>external system';
    echo '</div></div>';
    
    // Order Info
    echo '<div class="card" style="padding:12px;">';
    echo '<h3 style="margin:0 0 8px;font-size:14px;color:#666;">ORDER INFO</h3>';
    echo '<div style="font-size:18px;font-weight:bold;margin-bottom:4px;">'.esc_html($order['currency']).' '.number_format((float)$order['total'], 2).'</div>';
    echo '<div style="font-size:13px;color:#666;line-height:1.3;">';
    echo '<span style="color:'.self::status_color($order['status']).';font-weight:bold;">'.esc_html(ucfirst(str_replace('-', ' ', $order['status']))).'</span><br>';
    echo esc_html($order['shipping_method'] ?: 'Standard Shipping').'<br>';
    echo esc_html($order['priority'] ?: 'Standard Priority');
    echo '</div></div>';
    
    // Progress & Dates
    echo '<div class="card" style="padding:12px;">';
    echo '<h3 style="margin:0 0 8px;font-size:14px;color:#666;">PROGRESS</h3>';
    if (isset($order['parts_total']) && $order['parts_total'] > 0) {
      $progress = intval($order['parts_done']).'/'.intval($order['parts_total']).' ('.intval($order['progress_pct']).'%)';
      echo '<div style="font-size:16px;font-weight:bold;margin-bottom:4px;">'.$progress.'</div>';
    } else {
      echo '<div style="font-size:16px;font-weight:bold;margin-bottom:4px;">0/0 (0%)</div>';
    }
    echo '<div style="font-size:13px;color:#666;line-height:1.3;">';
    echo 'Created: '.esc_html(date_i18n('M j, Y', strtotime($order['date_created']))).'<br>';
    echo 'Modified: '.esc_html(date_i18n('M j, Y', strtotime($order['date_modified'])));
    echo '</div></div>';
    
    echo '</div>'; // end 4-column grid

    // Customer notes if present
    if (!empty($order['customer_notes'])) {
      echo '<div class="card" style="padding:15px;margin-bottom:15px;background:#fffbf0;border-left:4px solid #ff9800;">';
      echo '<h3 style="margin:0 0 10px;font-size:14px;color:#f57f17;">📝 CUSTOMER NOTES</h3>';
      echo '<div style="font-size:13px;line-height:1.4;color:#333;">'.esc_html($order['customer_notes']).'</div>';
      echo '</div>';
    }

    // Addresses section
    if ($billing_address || $shipping_address) {
      echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">';
      
      // Billing Address
      if ($billing_address) {
        echo '<div class="card" style="padding:12px;">';
        echo '<h3 style="margin:0 0 8px;font-size:14px;color:#666;">BILLING ADDRESS</h3>';
        echo '<div style="font-size:13px;line-height:1.5;">';
        if (!empty($billing_address['first_name']) || !empty($billing_address['last_name'])) {
          echo '<strong>'.esc_html(trim($billing_address['first_name'].' '.$billing_address['last_name'])).'</strong><br>';
        }
        if (!empty($billing_address['company'])) {
          echo esc_html($billing_address['company']).'<br>';
        }
        if (!empty($billing_address['address_1'])) {
          echo esc_html($billing_address['address_1']).'<br>';
        }
        if (!empty($billing_address['address_2'])) {
          echo esc_html($billing_address['address_2']).'<br>';
        }
        if (!empty($billing_address['city']) || !empty($billing_address['postcode'])) {
          echo esc_html(trim($billing_address['city'].' '.$billing_address['postcode'])).'<br>';
        }
        if (!empty($billing_address['state'])) {
          echo esc_html($billing_address['state']).'<br>';
        }
        if (!empty($billing_address['country'])) {
          echo esc_html($billing_address['country']);
        }
        echo '</div></div>';
      }
      
      // Shipping Address
      if ($shipping_address) {
        echo '<div class="card" style="padding:12px;">';
        echo '<h3 style="margin:0 0 8px;font-size:14px;color:#666;">SHIPPING ADDRESS</h3>';
        echo '<div style="font-size:13px;line-height:1.5;">';
        if (!empty($shipping_address['first_name']) || !empty($shipping_address['last_name'])) {
          echo '<strong>'.esc_html(trim($shipping_address['first_name'].' '.$shipping_address['last_name'])).'</strong><br>';
        }
        if (!empty($shipping_address['company'])) {
          echo esc_html($shipping_address['company']).'<br>';
        }
        if (!empty($shipping_address['address_1'])) {
          echo esc_html($shipping_address['address_1']).'<br>';
        }
        if (!empty($shipping_address['address_2'])) {
          echo esc_html($shipping_address['address_2']).'<br>';
        }
        if (!empty($shipping_address['city']) || !empty($shipping_address['postcode'])) {
          echo esc_html(trim($shipping_address['city'].' '.$shipping_address['postcode'])).'<br>';
        }
        if (!empty($shipping_address['state'])) {
          echo esc_html($shipping_address['state']).'<br>';
        }
        if (!empty($shipping_address['country'])) {
          echo esc_html($shipping_address['country']);
        }
        echo '</div></div>';
      }
      
      echo '</div>';
    }

    // Compact 2-column for grades/depths and job description
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px;">';
    
    // Grades + Depths combined
    echo '<div class="card" style="padding:12px;">';
    echo '<h3 style="margin:0 0 8px;font-size:14px;color:#666;">FOAM SPECIFICATIONS</h3>';
    echo '<div style="margin-bottom:8px;">';
    echo '<strong style="font-size:12px;color:#0073aa;">GRADES:</strong><br>';
    echo '<span style="font-family:monospace;font-size:13px;">'.esc_html($order['grades'] ?: 'Not specified').'</span>';
    echo '</div>';
    echo '<div>';
    echo '<strong style="font-size:12px;color:#0073aa;">DEPTHS:</strong><br>';
    echo '<span style="font-family:monospace;font-size:13px;">'.esc_html($order['depths'] ?: 'Not specified').'</span>';
    echo '</div></div>';
    
    // Job Description
    echo '<div class="card" style="padding:12px;">';
    echo '<h3 style="margin:0 0 8px;font-size:14px;color:#666;">JOB DESCRIPTION</h3>';
    echo '<div style="font-size:13px;line-height:1.4;color:#333;">';
    echo esc_html($order['job_desc'] ?: 'No description provided');
    echo '</div></div>';
    
    echo '</div>'; // end 2-column grid

    // Order items - full width, simple layout
    if ($items) {
      echo '<div class="card" style="padding:15px;margin-bottom:15px;">';
      echo '<h3 style="margin:0 0 12px;font-size:16px;color:#333;border-bottom:2px solid #0073aa;padding-bottom:6px;">ORDER ITEMS ('.count($items).')</h3>';
      
      foreach ($items as $index => $item) {
        echo '<div style="border:1px solid #ddd;border-radius:4px;margin-bottom:12px;overflow:hidden;">';
        
        // Item header
        echo '<div style="background:#f5f5f5;padding:10px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">';
        echo '<div>';
        echo '<strong style="font-size:15px;">'.esc_html($item['name'] ?: 'Unnamed Product').'</strong>';
        if ($item['sku']) echo '<span style="font-size:13px;color:#666;margin-left:10px;">SKU: '.esc_html($item['sku']).'</span>';
        echo '</div>';
        echo '<div style="text-align:right;font-size:14px;">';
        echo '<strong>Qty: '.esc_html($item['qty']).'</strong><br>';
        echo '<strong style="color:#0073aa;">£'.number_format((float)$item['line_total'], 2).'</strong>';
        echo '</div>';
        echo '</div>';
        
        // Meta data - full width grid
        $meta = json_decode($item['meta_json'], true);
        if ($meta && is_array($meta)) {
          echo '<div style="padding:12px;">';
          
          // Process all fields
          $all_fields = [];
          
          // Direct fields
          foreach ($meta as $key => $value) {
            if (empty($value) || strpos($key, '_') === 0) continue;
            if (!is_array($value)) {
              $all_fields[self::format_meta_key($key)] = $value;
            }
          }
          
          // WAPF fields
          if (isset($meta['_wapf_meta']['fields']) && is_array($meta['_wapf_meta']['fields'])) {
            foreach ($meta['_wapf_meta']['fields'] as $field_id => $field_data) {
              if (isset($field_data['value']) && !empty($field_data['value'])) {
                $field_name = self::map_wapf_field_name($field_id, $field_data);
                $all_fields[$field_name] = $field_data['value'];
              }
            }
          }
          
          // Display in responsive grid
          if (!empty($all_fields)) {
            echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">';
            
            foreach ($all_fields as $key => $value) {
              echo '<div style="background:#fafafa;padding:8px;border-radius:3px;border-left:3px solid #0073aa;">';
              echo '<div style="font-size:11px;font-weight:bold;color:#0073aa;text-transform:uppercase;margin-bottom:3px;">'.esc_html($key).'</div>';
              echo '<div style="color:#333;font-size:13px;font-weight:500;">'.esc_html($value).'</div>';
              echo '</div>';
            }
            
            echo '</div>';
          }
          
          echo '</div>';
        }
        echo '</div>';
      }
      
      echo '</div>';
    }

    // Order parts - full width table
    if ($parts) {
      echo '<div class="card" style="padding:15px;">';
      echo '<h3 style="margin:0 0 12px;font-size:16px;color:#333;border-bottom:2px solid #0073aa;padding-bottom:6px;">ORDER PARTS ('.count($parts).')</h3>';
      echo '<div style="overflow-x:auto;">';
      echo '<table class="wp-list-table widefat striped" style="margin:0;border:1px solid #ddd;">';
      echo '<thead>';
      echo '<tr style="background:#f9f9f9;">';
      echo '<th style="padding:10px;text-align:left;font-weight:bold;border-bottom:2px solid #ddd;">Part ID</th>';
      echo '<th style="padding:10px;text-align:left;font-weight:bold;border-bottom:2px solid #ddd;">Item #</th>';
      echo '<th style="padding:10px;text-align:left;font-weight:bold;border-bottom:2px solid #ddd;">Grade</th>';
      echo '<th style="padding:10px;text-align:left;font-weight:bold;border-bottom:2px solid #ddd;">Depth (cm)</th>';
      echo '<th style="padding:10px;text-align:left;font-weight:bold;border-bottom:2px solid #ddd;">Qty</th>';
      echo '<th style="padding:10px;text-align:left;font-weight:bold;border-bottom:2px solid #ddd;">Status</th>';
      echo '<th style="padding:10px;text-align:left;font-weight:bold;border-bottom:2px solid #ddd;">Batch ID</th>';
      echo '<th style="padding:10px;text-align:left;font-weight:bold;border-bottom:2px solid #ddd;">Created</th>';
      echo '</tr>';
      echo '</thead>';
      echo '<tbody>';
      
      foreach ($parts as $part) {
        echo '<tr>';
        echo '<td style="padding:8px;border-bottom:1px solid #eee;"><strong>'.esc_html($part['id']).'</strong></td>';
        echo '<td style="padding:8px;border-bottom:1px solid #eee;">'.esc_html($part['item_index']).'</td>';
        echo '<td style="padding:8px;border-bottom:1px solid #eee;">'.esc_html($part['grade']).'</td>';
        echo '<td style="padding:8px;border-bottom:1px solid #eee;text-align:center;"><strong>'.esc_html($part['depth_cm']).'</strong></td>';
        echo '<td style="padding:8px;border-bottom:1px solid #eee;text-align:center;">'.esc_html($part['qty']).'</td>';
        echo '<td style="padding:8px;border-bottom:1px solid #eee;">';
        echo '<span style="background:'.self::status_bg_color($part['status']).';color:'.self::status_color($part['status']).';padding:3px 8px;border-radius:12px;font-size:12px;font-weight:bold;">';
        echo esc_html(ucfirst($part['status']));
        echo '</span></td>';
        echo '<td style="padding:8px;border-bottom:1px solid #eee;">'.esc_html($part['batch_id'] ?: '—').'</td>';
        echo '<td style="padding:8px;border-bottom:1px solid #eee;">'.esc_html(date_i18n('M j, Y', strtotime($part['created_at']))).'</td>';
        echo '</tr>';
      }
      
      echo '</tbody></table>';
      echo '</div></div>';
    }

    echo '</div>'; // .wrap
  }

  // Helper function to format meta keys
  private static function format_meta_key($key) {
    // Convert camelCase or snake_case to readable format
    $key = str_replace(['_', '-'], ' ', $key);
    return ucwords($key);
  }

  // Helper function to map WAPF field IDs to readable names
  private static function map_wapf_field_name($field_id, $field_data) {
    // Try to get label from field data first
    if (isset($field_data['label']) && !empty($field_data['label'])) {
      return $field_data['label'];
    }
    
    // Try to get name from field data
    if (isset($field_data['name']) && !empty($field_data['name'])) {
      return self::format_meta_key($field_data['name']);
    }
    
    // Try to get type and make it readable
    if (isset($field_data['type']) && !empty($field_data['type'])) {
      return ucwords(str_replace('_', ' ', $field_data['type'])) . ' Field';
    }
    
    // Last fallback - just return a generic name
    return 'Custom Field';
  }

  // Helper function for status colors
  private static function status_color($status) {
    switch (strtolower($status)) {
      case 'completed': case 'done': return '#0073aa';
      case 'processing': case 'in_progress': return '#d54e21';  
      case 'pending': return '#ffb900';
      case 'cancelled': case 'failed': return '#dc3232';
      case 'out-for-delivery': return '#9c27b0';
      default: return '#666';
    }
  }

  // Helper function for status background colors
  private static function status_bg_color($status) {
    switch (strtolower($status)) {
      case 'completed': case 'done': return '#e7f3ff';
      case 'processing': case 'in_progress': return '#ffeaa7';  
      case 'pending': return '#fff3cd';
      case 'cancelled': case 'failed': return '#f8d7da';
      case 'out-for-delivery': return '#f3e5f5';
      default: return '#f8f9fa';
    }
  }

  public static function handle_bulk_delete(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_delete_orders');

    $order_ids = isset($_POST['order_ids']) ? array_map('intval',(array)$_POST['order_ids']) : [];
    // allow either select box (top/bottom)
    $action = $_POST['bulk_action'] ?? $_POST['bulk_action_bottom'] ?? '';
    if ($action !== 'delete' || empty($order_ids)){ 
      wp_redirect(add_query_arg(['page'=>'fodr-orders','msg'=>'no-selection'], admin_url('admin.php'))); 
      exit; 
    }

    global $wpdb;
    $orders_tbl = $wpdb->prefix.'foam_orders';
    $items_tbl  = $wpdb->prefix.'foam_order_items';
    $parts_tbl  = $wpdb->prefix.'foam_order_parts';
    $batches_tbl= $wpdb->prefix.'foam_batches';

    // Gather affected batch IDs first
    $in = implode(',', array_map('intval',$order_ids));
    $affected_uids = $wpdb->get_col("SELECT DISTINCT batch_id FROM $parts_tbl WHERE order_id IN ($in) AND batch_id IS NOT NULL AND batch_id <> ''");

    // Delete parts + items + orders
    $wpdb->query("DELETE FROM $parts_tbl WHERE order_id IN ($in)");
    $wpdb->query("DELETE FROM $items_tbl WHERE order_id IN ($in)");
    $wpdb->query("DELETE FROM $orders_tbl WHERE order_id IN ($in)");

    // Clean up empty batches (no remaining parts)
    if ($affected_uids){
      foreach ($affected_uids as $uid){
        $cnt = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $parts_tbl WHERE batch_id=%s", $uid));
        if ($cnt === 0){
          $wpdb->delete($batches_tbl, ['batch_uid'=>$uid]);
        }
      }
    }

    wp_redirect(add_query_arg(['page'=>'fodr-orders','msg'=>'deleted','n'=>count($order_ids)], admin_url('admin.php'))); 
    exit;
  }
}
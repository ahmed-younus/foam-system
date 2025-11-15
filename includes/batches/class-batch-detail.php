<?php
if (!defined('ABSPATH')) exit;

class FODR_Batch_Detail {

  public static function init(){
    add_action('admin_menu', [__CLASS__, 'menu']);
    add_action('admin_post_fodr_remove_parts', [__CLASS__, 'remove_parts']);
    add_action('admin_post_fodr_delete_batch', [__CLASS__, 'delete_batch']);
    add_action('admin_post_fodr_mark_batch_done', [__CLASS__, 'mark_done']);
    add_action('admin_post_fodr_undo_batch_completion', [__CLASS__, 'undo_batch_completion']); // NEW
    add_action('admin_post_fodr_auto_apply', [__CLASS__, 'auto_apply']);
    add_action('admin_post_fodr_mark_parts_done', [__CLASS__, 'mark_parts_done']); // NEW
    
    // Add AJAX handler for print view
    add_action('wp_ajax_fodr_print_batch', [__CLASS__, 'ajax_print_batch']);
  }

  public static function menu(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    add_submenu_page('fodr-dashboard', __('Batch Detail','fod-receiver'), __('Batch Detail','fod-receiver'),
      $cap, 'fodr-batch', [__CLASS__, 'render']);
    
    // NEW: Add Completed Batches submenu
    add_submenu_page('fodr-dashboard', __('Completed Batches','fod-receiver'), __('Completed Batches','fod-receiver'),
      $cap, 'fodr-completed-batches', [__CLASS__, 'render_completed_batches']);
  }

  public static function render(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    if (!current_user_can($cap)) wp_die(__('Not allowed','fod-receiver'));

    global $wpdb;
    $uid = isset($_GET['uid']) ? sanitize_text_field($_GET['uid']) : '';

    echo '<div class="wrap fodr-batch-detail">';
    echo '<style>
      .wrap.fodr-batch-detail { 
        max-width: none !important; 
        width: calc(100% - 20px) !important; 
        margin: 10px !important;
      }
      #wpcontent {
        padding-left: 0 !important;
      }
      .fodr-batch-detail .card { 
        width: 100% !important; 
        box-sizing: border-box !important; 
        max-width: none !important;
        margin-bottom: 20px !important;
      }
      .fodr-batch-detail table {
        width: 100% !important;
        max-width: none !important;
      }
      .batch-card {
        transition: all 0.3s ease;
        cursor: pointer;
      }
      .batch-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.1);
      }
      @media (max-width: 960px) {
        .wrap.fodr-batch-detail {
          width: calc(100% - 10px) !important;
          margin: 5px !important;
        }
      }
    </style>';
    
    echo '<h1>Batch Detail & Production Management</h1>';

    // Show success messages
    if (isset($_GET['msg'])) {
      if ($_GET['msg'] === 'batch_deleted') {
        $waste_released = isset($_GET['waste_released']) ? intval($_GET['waste_released']) : 0;
        if ($waste_released > 0) {
          echo '<div class="notice notice-success"><p>🗑️ Batch deleted successfully! '.$waste_released.' waste pieces released back to available inventory.</p></div>';
        } else {
          echo '<div class="notice notice-success"><p>🗑️ Batch deleted successfully!</p></div>';
        }
      }
    }

    // List open batches if no UID
    if (!$uid){
      $open = FODR_Batches_Common::get_open_batches_with_stats();
      echo '<p class="description">Select a batch to manage production details.</p>';
      
      // NEW: Add search bar for batches
      self::render_batch_search();
      
      if (!$open){
        echo '<div class="notice notice-info"><p>No active batches available.</p></div></div>'; 
        return;
      }
      
      self::render_batch_selector($open);
      echo '</div>';
      return;
    }

    // Single batch detailed view
    $tbl_batches = $wpdb->prefix.'foam_batches';
    $tbl_parts   = $wpdb->prefix.'foam_order_parts';
    $tbl_waste   = $wpdb->prefix.'foam_waste';

    $batch = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl_batches WHERE batch_uid=%s", $uid), ARRAY_A);
    if (!$batch){ 
      echo '<div class="notice notice-error"><p>Batch not found.</p></div></div>'; 
      return; 
    }

    // Get parts with order details - FIXED QUERY FOR PROPER META MATCHING
    $base_parts = $wpdb->get_results($wpdb->prepare("
      SELECT p.*, o.customer_name, o.order_number
      FROM $tbl_parts p
      LEFT JOIN {$wpdb->prefix}foam_orders o ON p.order_id = o.order_id  
      WHERE p.batch_id = %s
      ORDER BY p.status ASC, p.created_at ASC
    ", $uid), ARRAY_A);
    
    // Now get proper meta for each part based on item_index
    $rows = [];
    foreach ($base_parts as $part) {
      $item_index = max(0, intval($part['item_index']) - 1);
      $meta_json = $wpdb->get_var($wpdb->prepare("
        SELECT meta_json 
        FROM {$wpdb->prefix}foam_order_items 
        WHERE order_id = %d 
        ORDER BY id ASC 
        LIMIT 1 OFFSET %d
      ", $part['order_id'], $item_index));
      
      $part['meta_json'] = $meta_json;
      $rows[] = $part;
    }

    // Get waste assignments for this batch
    $waste_assignments = self::get_waste_assignments($uid);

    $done=0; $total=count($rows);
    foreach ($rows as $r){ if ($r['status']==='done') $done++; }
    $pct = $total ? (int)floor(($done/$total)*100) : 0;

    // Show success messages
    if (isset($_GET['msg'])) {
      if ($_GET['msg'] === 'parts_completed') {
        $completed_count = isset($_GET['completed']) ? intval($_GET['completed']) : 0;
        echo '<div class="notice notice-success"><p>✅ Successfully marked '.$completed_count.' parts as completed!</p></div>';
      } elseif ($_GET['msg'] === 'batch_completed') {
        $batch_id = isset($_GET['batch_id']) ? sanitize_text_field($_GET['batch_id']) : '';
        echo '<div class="notice notice-success"><p>🎉 Batch '.$batch_id.' has been completed successfully! All parts marked as done.</p></div>';
      }
    }

    // Batch header with key metrics
    self::render_batch_header($batch, $total, $done, $pct, $waste_assignments);

    // Main content grid - 100% width layout
    echo '<div style="display:grid;grid-template-columns:1fr;gap:20px;margin-top:20px;">';

    // Waste assignment summary - if exists
    if ($waste_assignments) {
      self::render_waste_assignments($waste_assignments, $batch['grade']);
    }

    // Production analysis
    self::render_production_analysis($rows, $batch);

    // Parts detailed table WITH INDIVIDUAL COMPLETION
    self::render_parts_table_with_completion($uid, $rows);

    // Batch actions and completion in a row
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">';
    
    // Batch management actions
    self::render_batch_actions($uid);

    // Batch completion
    self::render_completion_section($uid, $done, $total);
    
    echo '</div>'; // end actions row

    echo '</div>'; // end main grid

    echo '</div>'; // end wrap

    // Add JavaScript functionality with PRINT support
    echo '<script>
    function exportBatchData(batchUid) {
      // This would export batch data to CSV
      alert("Export functionality would be implemented here for batch: " + batchUid);
    }

    function printProductionSheet(batchUid) {
      // Open print view in new window
      const printUrl = "' . admin_url('admin-ajax.php') . '?action=fodr_print_batch&batch_uid=" + batchUid;
      const printWindow = window.open(printUrl, "_blank", "width=1000,height=800,scrollbars=yes");
      
      // Let the window handle its own printing
    }
    
    function completeBatchFromList(batchUid) {
      if (confirm("Are you sure you want to complete batch " + batchUid + "?\\n\\nThis will mark ALL parts in this batch as completed.")) {
        // Create and submit form to complete the batch
        const form = document.createElement("form");
        form.method = "POST";
        form.action = "' . admin_url('admin-post.php') . '";
        
        const fields = {
          "action": "fodr_mark_batch_done",
          "uid": batchUid,
          "_wpnonce": "' . wp_create_nonce('fodr_mark_batch_done') . '"
        };
        
        Object.keys(fields).forEach(key => {
          const input = document.createElement("input");
          input.type = "hidden";
          input.name = key;
          input.value = fields[key];
          form.appendChild(input);
        });
        
        document.body.appendChild(form);
        form.submit();
      }
    }
    </script>';
  }

  // NEW: Batch search functionality
  private static function render_batch_search() {
    $search_term = isset($_GET['batch_search']) ? sanitize_text_field($_GET['batch_search']) : '';
    
    echo '<div style="background:white;border:1px solid #ddd;border-radius:8px;padding:15px;margin-bottom:20px;">';
    echo '<form method="get" style="display:flex;gap:10px;align-items:center;">';
    echo '<input type="hidden" name="page" value="fodr-batch">';
    echo '<label style="font-weight:bold;">🔍 Search Batches:</label>';
    echo '<input type="text" name="batch_search" value="'.esc_attr($search_term).'" placeholder="Search by Batch ID or Grade..." style="flex:1;padding:8px;border:1px solid #ddd;border-radius:4px;">';
    echo '<button class="button button-primary">Search</button>';
    if ($search_term) {
      echo '<a href="'.admin_url('admin.php?page=fodr-batch').'" class="button">Clear</a>';
    }
    echo '</form>';
    echo '</div>';
    
    // If search term exists, filter results
    if ($search_term) {
      global $wpdb;
      $tbl_batches = $wpdb->prefix.'foam_batches';
      $tbl_parts = $wpdb->prefix.'foam_order_parts';
      
      $search_results = $wpdb->get_results($wpdb->prepare("
        SELECT 
          b.batch_uid, b.grade, b.depth_cm, b.status, b.created_at,
          COALESCE(s.total,0) AS total,
          COALESCE(s.done,0) AS done
        FROM $tbl_batches b
        LEFT JOIN (
          SELECT batch_id,
                 COUNT(*) AS total,
                 SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) AS done
          FROM $tbl_parts
          WHERE batch_id IS NOT NULL AND batch_id <> ''
          GROUP BY batch_id
        ) s ON s.batch_id = b.batch_uid
        WHERE (b.batch_uid LIKE %s OR b.grade LIKE %s)
        AND b.status IN ('open','in_progress','done')
        ORDER BY b.created_at DESC
        LIMIT 50
      ", '%'.$search_term.'%', '%'.$search_term.'%'), ARRAY_A);
      
      if ($search_results) {
        echo '<div class="card" style="padding:15px;margin-bottom:20px;">';
        echo '<h3 style="margin:0 0 15px;">🔎 Search Results for "'.esc_html($search_term).'" ('.count($search_results).' found)</h3>';
        self::render_batch_selector($search_results);
        echo '</div>';
      } else {
        echo '<div class="notice notice-warning"><p>No batches found matching "'.esc_html($search_term).'"</p></div>';
      }
    }
  }

  // NEW: Render completed batches page
  public static function render_completed_batches(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    if (!current_user_can($cap)) wp_die(__('Not allowed','fod-receiver'));

    echo '<div class="wrap fodr-completed-batches">';
    echo '<style>
      .wrap.fodr-completed-batches { 
        max-width: none !important; 
        width: calc(100vw - 180px) !important; 
        margin: 10px !important;
        padding-right: 20px !important;
      }
      #wpcontent {
        padding-left: 0 !important;
      }
      .fodr-completed-batches .card { 
        width: 100% !important; 
        box-sizing: border-box !important; 
        max-width: none !important;
        margin-bottom: 20px !important;
      }
      .fodr-completed-batches table {
        width: 100% !important;
        max-width: none !important;
      }
      body.folded .fodr-completed-batches {
        width: calc(100vw - 56px) !important;
      }
      @media (max-width: 960px) {
        .wrap.fodr-completed-batches {
          width: calc(100% - 10px) !important;
          margin: 5px !important;
        }
      }
    </style>';
    
    echo '<h1>📋 Completed Batches</h1>';
    
    // Show success messages
    if (isset($_GET['msg'])) {
      if ($_GET['msg'] === 'batch_undone') {
        $batch_id = isset($_GET['batch_id']) ? sanitize_text_field($_GET['batch_id']) : '';
        $waste_released = isset($_GET['waste_released']) ? intval($_GET['waste_released']) : 0;
        if ($waste_released > 0) {
          echo '<div class="notice notice-success"><p>↶ Batch '.$batch_id.' has been moved back to active status. All parts are now in-progress. '.$waste_released.' waste pieces released back to available inventory.</p></div>';
        } else {
          echo '<div class="notice notice-success"><p>↶ Batch '.$batch_id.' has been moved back to active status. All parts are now in-progress.</p></div>';
        }
      }
    }
    
    // Search functionality
    $search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    
    echo '<div style="background:white;border:1px solid #ddd;border-radius:8px;padding:15px;margin-bottom:20px;">';
    echo '<form method="get" style="display:flex;gap:10px;align-items:center;">';
    echo '<input type="hidden" name="page" value="fodr-completed-batches">';
    echo '<label style="font-weight:bold;">🔍 Search Completed Batches:</label>';
    echo '<input type="text" name="search" value="'.esc_attr($search_term).'" placeholder="Search by Batch ID or Grade..." style="flex:1;padding:8px;border:1px solid #ddd;border-radius:4px;">';
    echo '<button class="button button-primary">Search</button>';
    if ($search_term) {
      echo '<a href="'.admin_url('admin.php?page=fodr-completed-batches').'" class="button">Clear</a>';
    }
    echo '</form>';
    echo '</div>';

    // Get completed batches
    global $wpdb;
    $tbl_batches = $wpdb->prefix.'foam_batches';
    $tbl_parts = $wpdb->prefix.'foam_order_parts';
    
    $where = "WHERE b.status = 'done'";
    $args = [];
    
    if ($search_term) {
      $where .= " AND (b.batch_uid LIKE %s OR b.grade LIKE %s)";
      $args[] = '%'.$search_term.'%';
      $args[] = '%'.$search_term.'%';
    }
    
    $completed_batches = $wpdb->get_results($wpdb->prepare("
      SELECT 
        b.*, 
        COALESCE(s.total,0) AS total,
        COALESCE(s.done,0) AS done
      FROM $tbl_batches b
      LEFT JOIN (
        SELECT batch_id,
               COUNT(*) AS total,
               SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) AS done
        FROM $tbl_parts
        WHERE batch_id IS NOT NULL AND batch_id <> ''
        GROUP BY batch_id
      ) s ON s.batch_id = b.batch_uid
      $where
      ORDER BY b.completed_at DESC, b.created_at DESC
      LIMIT 100
    ", $args), ARRAY_A);

    if ($completed_batches) {
      echo '<div class="card" style="padding:20px;">';
      echo '<h2 style="margin:0 0 20px;">✅ Completed Batches ('.count($completed_batches).')</h2>';
      
      echo '<div style="overflow-x:auto;">';
      echo '<table class="wp-list-table widefat striped">';
      echo '<thead><tr>';
      echo '<th style="padding:12px;">Batch ID</th>';
      echo '<th style="padding:12px;">Grade</th>';
      echo '<th style="padding:12px;">Parts</th>';
      echo '<th style="padding:12px;">Created</th>';
      echo '<th style="padding:12px;">Completed</th>';
      echo '<th style="padding:12px;">Duration</th>';
      echo '<th style="padding:12px;">Actions</th>';
      echo '</tr></thead><tbody>';
      
      foreach ($completed_batches as $batch) {
        $created = new DateTime($batch['created_at']);
        $completed = new DateTime($batch['completed_at'] ?: $batch['created_at']);
        $duration = $created->diff($completed);
        $duration_text = $duration->days . ' days';
        if ($duration->days == 0) {
          $duration_text = $duration->h . ' hours';
        }
        
        echo '<tr>';
        echo '<td style="padding:10px;"><a href="'.admin_url('admin.php?page=fodr-batch&uid='.$batch['batch_uid']).'" style="font-weight:bold;color:#0073aa;text-decoration:none;" title="View batch details">'.$batch['batch_uid'].'</a></td>';
        echo '<td style="padding:10px;">';
        echo '<span style="background:#e8f5e8;padding:3px 8px;border-radius:4px;font-size:11px;">'.$batch['grade'].'</span>';
        echo '</td>';
        echo '<td style="padding:10px;text-align:center;"><strong>'.$batch['done'].'/'.$batch['total'].'</strong></td>';
        echo '<td style="padding:10px;">'.date_i18n('M j, Y', strtotime($batch['created_at'])).'</td>';
        echo '<td style="padding:10px;">'.date_i18n('M j, Y', strtotime($batch['completed_at'] ?: $batch['created_at'])).'</td>';
        echo '<td style="padding:10px;">'.$duration_text.'</td>';
        echo '<td style="padding:10px;">';
        echo '<a href="'.admin_url('admin.php?page=fodr-batch&uid='.$batch['batch_uid']).'" class="button button-small">View Details</a> ';
        echo '<button onclick="printProductionSheet(\''.$batch['batch_uid'].'\')" class="button button-small">Print</button> ';
        echo '<button onclick="undoBatchCompletion(\''.$batch['batch_uid'].'\')" class="button button-small" style="background:#ff9800;color:white;border-color:#ff9800;" title="Undo batch completion">↶ Undo</button>';
        echo '</td>';
        echo '</tr>';
      }
      
      echo '</tbody></table>';
      echo '</div>';
      echo '</div>';
    } else {
      echo '<div class="notice notice-info"><p>No completed batches found.</p></div>';
    }
    
    echo '</div>';
    
    // JavaScript for print functionality and undo
    echo '<script>
    function printProductionSheet(batchUid) {
      const printUrl = "' . admin_url('admin-ajax.php') . '?action=fodr_print_batch&batch_uid=" + batchUid;
      window.open(printUrl, "_blank", "width=1000,height=800,scrollbars=yes");
    }
    
    function undoBatchCompletion(batchUid) {
      if (confirm("Are you sure you want to undo completion for batch " + batchUid + "?\\n\\nThis will move the batch back to active status and all parts will be marked as in-progress.")) {
        // Create and submit form to undo batch completion
        const form = document.createElement("form");
        form.method = "POST";
        form.action = "' . admin_url('admin-post.php') . '";
        
        const fields = {
          "action": "fodr_undo_batch_completion",
          "uid": batchUid,
          "_wpnonce": "' . wp_create_nonce('fodr_undo_batch_completion') . '"
        };
        
        console.log("Undoing batch completion:", fields);
        
        Object.keys(fields).forEach(key => {
          const input = document.createElement("input");
          input.type = "hidden";
          input.name = key;
          input.value = fields[key];
          form.appendChild(input);
        });
        
        document.body.appendChild(form);
        form.submit();
      }
    }
    </script>';
  }

  // UPDATED: Parts table with individual completion functionality
  private static function render_parts_table_with_completion($uid, $rows) {
    echo '<div class="card" style="padding:20px;background:white;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);">';
    echo '<h3 style="margin:0 0 15px;border-bottom:2px solid #0073aa;padding-bottom:8px;font-size:18px;">📋 Parts Management</h3>';

    if ($rows) {
      // Individual completion form
      echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="border:2px solid #4caf50;border-radius:8px;padding:15px;margin-bottom:20px;background:#f8fff8;" id="completion-form">';
      wp_nonce_field('fodr_mark_parts_done');
      echo '<input type="hidden" name="action" value="fodr_mark_parts_done">';
      echo '<input type="hidden" name="uid" value="'.esc_attr($uid).'">';
      
      echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">';
      echo '<h4 style="margin:0;color:#2e7d32;">✅ Mark Parts as Complete</h4>';
      echo '<button type="submit" class="button button-primary button-large" onclick="return confirm(\'Mark selected parts as completed?\')">Complete Selected Parts</button>';
      echo '</div>';
      
      echo '<div style="overflow-x:auto;">';
      echo '<table class="wp-list-table widefat fixed striped" style="font-size:13px;">';
      echo '<thead style="background:#f9f9f9;"><tr>
              <td class="manage-column check-column" style="width:40px;"><input type="checkbox" onclick="jQuery(\'.completion-chk\').prop(\'checked\', this.checked)"></td>
              <th style="padding:10px;width:80px;">Order</th>
              <th style="padding:10px;">Customer</th>
              <th style="padding:10px;width:150px;">Grade</th>
              <th style="padding:10px;width:80px;">Depth</th>
              <th style="padding:10px;width:60px;">Qty</th>
              <th style="padding:10px;width:100px;">Status</th>
              <th style="padding:10px;">Dimensions</th>
              <th style="padding:10px;width:100px;">Created</th>
            </tr></thead><tbody>';

      foreach ($rows as $r){
        $lnk = admin_url('admin.php?page=fodr-orders&view=order&order_id='.$r['order_id']);
        $status_color = self::get_status_color($r['status']);
        $is_completed = ($r['status'] === 'done');
        
        // Extract dimensions from meta - FIXED
        $dimensions = '';
        if ($r['meta_json']) {
          $meta = json_decode($r['meta_json'], true);
          if ($meta) {
            $unit = $meta['Measure your cushions'] ?? ($meta['_wapf_meta']['fields']['62ea52c0ab295']['value'] ?? 'CM');
            $w = $meta['Side A (Width)'] ?? ($meta['_wapf_meta']['fields']['62ea52c0ab299']['value'] ?? '');
            $l = $meta['Side B (Length)'] ?? ($meta['_wapf_meta']['fields']['62ea52c0ab29c']['value'] ?? '');
            $d = $meta['Side C (Depth)'] ?? ($meta['_wapf_meta']['fields']['62ea52c0ab2a0']['value'] ?? '');
            
            if ($w && $l) {
              $dimensions = $w.'×'.$l;
              if ($d) $dimensions .= '×'.$d;
              $dimensions .= ' '.$unit;
            }
          }
        }

        echo '<tr style="'.($is_completed ? 'background:#f0f8f0;' : '').'">';
        echo '<th class="check-column">';
        if (!$is_completed) {
          echo '<input class="completion-chk" type="checkbox" name="part_ids[]" value="'.intval($r['id']).'">';
        } else {
          echo '<span style="color:#4caf50;font-size:16px;">✓</span>';
        }
        echo '</th>';
        echo '<td style="padding:8px;"><a href="'.esc_url($lnk).'" style="font-weight:bold;color:#0073aa;">#'.intval($r['order_id']).'</a></td>';
        echo '<td style="padding:8px;">'.esc_html($r['customer_name'] ?: 'Unknown').'</td>';
        echo '<td style="padding:8px;"><span style="background:#e3f2fd;padding:3px 8px;border-radius:4px;font-size:11px;">'.esc_html($r['grade']).'</span></td>';
        echo '<td style="padding:8px;text-align:center;background:#f3e5f5;"><strong>'.esc_html($r['depth_cm']).' cm</strong></td>';
        echo '<td style="padding:8px;text-align:center;">'.esc_html($r['qty']).'</td>';
        echo '<td style="padding:8px;"><span style="background:'.self::get_status_bg($r['status']).';color:'.$status_color.';padding:4px 10px;border-radius:12px;font-size:11px;font-weight:bold;">'.esc_html(ucfirst($r['status'])).'</span></td>';
        echo '<td style="padding:8px;font-family:monospace;font-size:12px;color:#0073aa;font-weight:bold;">'.esc_html($dimensions ?: '—').'</td>';
        echo '<td style="padding:8px;">'.esc_html(date_i18n('M j', strtotime($r['created_at']))).'</td>';
        echo '</tr>';
      }
      echo '</tbody></table></div>';
      echo '</form>';

      // Bulk remove form
      echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
      wp_nonce_field('fodr_remove_parts');
      echo '<input type="hidden" name="action" value="fodr_remove_parts">';
      echo '<input type="hidden" name="uid" value="'.esc_attr($uid).'">';

      // Bulk remove section
      echo '<div style="border:2px solid #ff9800;border-radius:8px;padding:15px;background:#fff8f0;">';
      echo '<h4 style="margin:0 0 10px;color:#f57f17;">🗑️ Remove Parts from Batch</h4>';
      echo '<div style="display:flex;gap:10px;align-items:center;margin-bottom:10px;">';
      echo '<input type="checkbox" onclick="jQuery(\'.remove-chk\').prop(\'checked\', this.checked)" id="remove-all">';
      echo '<label for="remove-all">Select all for removal</label>';
      echo '<button class="button button-secondary" onclick="return confirm(\'Remove selected parts from this batch?\')" style="margin-left:auto;">Remove Selected</button>';
      echo '</div>';
      
      echo '<div style="max-height:200px;overflow-y:auto;">';
      foreach ($rows as $r) {
        echo '<div style="padding:5px;border-bottom:1px solid #eee;">';
        echo '<label><input class="remove-chk" type="checkbox" name="part_ids[]" value="'.intval($r['id']).'"> ';
        echo '#'.intval($r['order_id']).' - '.esc_html($r['customer_name'] ?: 'Unknown').' ('.esc_html($r['grade']).')';
        echo '</label></div>';
      }
      echo '</div>';
      echo '</div>';
      echo '</form>';

    } else {
      echo '<div style="text-align:center;padding:40px;color:#666;">No parts assigned to this batch yet.</div>';
    }

    echo '</div>';
  }

  // NEW: Handler for marking individual parts as done
  public static function mark_parts_done(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_mark_parts_done');

    global $wpdb;
    $uid = sanitize_text_field($_POST['uid'] ?? '');
    $part_ids = array_map('intval', (array)($_POST['part_ids'] ?? []));
    
    if (!$uid || !$part_ids) { 
      wp_redirect(admin_url('admin.php?page=fodr-batch&uid='.$uid)); 
      exit; 
    }

    $tbl_parts = $wpdb->prefix.'foam_order_parts';
    $in = implode(',', $part_ids);
    
    // Mark selected parts as done
    $wpdb->query("UPDATE $tbl_parts SET status='done' WHERE id IN ($in) AND batch_id='".esc_sql($uid)."'");
    
    // Recalculate batch progress
    FODR_Batches_Common::recalc_orders_by_part_ids($part_ids);
    
    wp_redirect(admin_url('admin.php?page=fodr-batch&uid='.$uid.'&msg=parts_completed&completed='.count($part_ids))); 
    exit;
  }

  // AJAX handler for print view - WITH PAGE NUMBERING IN FOOTER
  public static function ajax_print_batch() {
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    if (!current_user_can($cap)) wp_die('Not authorized');
    
    global $wpdb;
    $batch_uid = sanitize_text_field($_GET['batch_uid'] ?? '');
    
    if (!$batch_uid) {
      wp_die('Invalid batch ID');
    }
    
    // Get batch info
    $batch = $wpdb->get_row($wpdb->prepare("
      SELECT * FROM {$wpdb->prefix}foam_batches WHERE batch_uid = %s
    ", $batch_uid), ARRAY_A);
    
    if (!$batch) {
      wp_die('Batch not found');
    }
    
    // Get all parts with full meta
    $parts = $wpdb->get_results($wpdb->prepare("
      SELECT p.*, o.order_number, o.customer_name
      FROM {$wpdb->prefix}foam_order_parts p
      LEFT JOIN {$wpdb->prefix}foam_orders o ON p.order_id = o.order_id
      WHERE p.batch_id = %s
      ORDER BY p.created_at ASC
    ", $batch_uid), ARRAY_A);
    
    // Get proper meta for each part
    foreach ($parts as &$part) {
      $item_index = max(0, intval($part['item_index']) - 1);
      $meta_json = $wpdb->get_var($wpdb->prepare("
        SELECT meta_json 
        FROM {$wpdb->prefix}foam_order_items 
        WHERE order_id = %d 
        ORDER BY id ASC 
        LIMIT 1 OFFSET %d
      ", $part['order_id'], $item_index));
      
      $part['meta_json'] = $meta_json;
      $part['meta_array'] = json_decode($meta_json ?: '{}', true);
    }
    
    // Calculate total pages
    $items_per_page = 4;
    $total_parts = count($parts);
    $total_pages = ceil($total_parts / $items_per_page);
    
    // Generate print view HTML
    ?>
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="UTF-8">
      <title>Batch_<?php echo esc_html($batch_uid); ?>_<?php echo date('Y-m-d'); ?></title>
      <style>
        @page {
          size: A4;
          margin: 15mm 15mm 25mm 15mm; /* Extra bottom margin for footer */
          
          /* Page numbering in footer */
          @bottom-center {
            content: "<?php echo esc_html($batch_uid); ?> - Page " counter(page) " of <?php echo $total_pages; ?>";
            font-family: Arial, sans-serif;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 8px;
            margin-top: 10px;
          }
        }
        
        body {
          font-family: Arial, sans-serif;
          font-size: 11px;
          line-height: 1.4;
          margin: 0;
          padding: 0;
        }
        
        .header {
          border-bottom: 2px solid #333;
          padding-bottom: 10px;
          margin-bottom: 20px;
        }
        
        .batch-info {
          display: flex;
          justify-content: space-between;
          margin-bottom: 10px;
        }
        
        h1 {
          margin: 0;
          font-size: 20px;
          color: #333;
        }
        
        .grade {
          font-size: 14px;
          color: #666;
        }
        
        table {
          width: 100%;
          border-collapse: collapse;
          margin-bottom: 15px;
        }
        
        th {
          background: #f0f0f0;
          border: 1px solid #ddd;
          padding: 8px;
          text-align: left;
          font-size: 10px;
          font-weight: bold;
        }
        
        td {
          border: 1px solid #ddd;
          padding: 6px;
          font-size: 10px;
          vertical-align: top;
        }
        
        .page-break {
          page-break-before: always;
        }
        
        @media print {
          .no-print {
            display: none;
          }
          
          /* Ensure proper table pagination */
          table {
            page-break-inside: auto;
          }
          
          tr {
            page-break-inside: avoid;
            page-break-after: auto;
          }
          
          thead {
            display: table-header-group;
          }
          
          tbody {
            display: table-row-group;
          }
          
          /* Page counter styling */
          body {
            counter-reset: page 1;
          }
        }
      </style>
    </head>
    <body>
      <div class="header">
        <div class="batch-info">
          <div>
            <h1>Batch: <?php echo esc_html($batch_uid); ?></h1>
            <div class="grade"><?php echo esc_html($batch['grade']); ?></div>
          </div>
          <div style="text-align: right;">
            <strong>Date:</strong> <?php echo date('Y-m-d H:i'); ?><br>
            <strong>Total Parts:</strong> <?php echo count($parts); ?><br>
            <strong>Pages:</strong> <?php echo $total_pages; ?>
          </div>
        </div>
      </div>
      
      <?php
      $current_page = 1;
      
      // Split parts into pages
      for ($page_start = 0; $page_start < $total_parts; $page_start += $items_per_page) {
        $page_parts = array_slice($parts, $page_start, $items_per_page);
        
        // Add page break before each new page (except first)
        if ($current_page > 1) {
          echo '<div class="page-break"></div>';
        }
        ?>
        
        <table>
          <thead>
            <tr>
              <th style="width: 80px;">Order</th>
              <th>Order Item Data</th>
              <th style="width: 40px;">Qty</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($page_parts as $part): 
              // Extract all fields from meta dynamically
              $meta = $part['meta_array'];
              $formatted_fields = [];
              
              if ($meta && is_array($meta)) {
                // Get all top-level fields (excluding _wapf_meta and other internal fields)
                foreach ($meta as $key => $value) {
                  // Skip internal fields (those starting with _)
                  if (strpos($key, '_') === 0) continue;
                  
                  // Skip null or empty values
                  if ($value === null || $value === '') continue;
                  
                  // Skip array values (like _wapf_meta)
                  if (is_array($value)) continue;
                  
                  // Clean up value (remove pricing hints)
                  $cleaned_value = preg_replace('/\s*\(\+[^)]*\)\s*$/', '', $value);
                  
                  // Add to formatted fields
                  $formatted_fields[$key] = $cleaned_value;
                }
              }
              ?>
              <tr style="page-break-inside: avoid;">
                <td style="text-align: center; padding: 10px; vertical-align: top;">
                  <strong>#<?php echo intval($part['order_id']); ?></strong>
                </td>
                <td style="padding: 10px; vertical-align: top;">
                  <?php if (!empty($formatted_fields)): ?>
                    <?php 
                    // Build dimensions string if width/length/depth are available
                    $dims = [];
                    if (isset($formatted_fields['Side A (Width)'])) $dims[] = $formatted_fields['Side A (Width)'];
                    if (isset($formatted_fields['Side B (Length)'])) $dims[] = $formatted_fields['Side B (Length)'];
                    if (isset($formatted_fields['Side C (Depth)'])) $dims[] = $formatted_fields['Side C (Depth)'];
                    
                    if (!empty($dims)): ?>
                      <div class="dimensions-header" style="font-weight:bold;color:#333;margin-bottom:6px;">
                        <?php 
                        echo implode(' × ', $dims);
                        if (isset($formatted_fields['Measure your cushions'])) {
                          echo ' ' . $formatted_fields['Measure your cushions'];
                        }
                        ?>
                      </div>
                    <?php endif; ?>
                    
                    <?php 
                    // Display fields in consistent order
                    $field_order = [
                      'Measure your cushions',
                      'Side A (Width)',
                      'Side B (Length)', 
                      'Side C (Depth)',
                      'Side D',
                      'Side E',
                      'Select your foam type',
                      'Standard Foams',
                      'Bonded Foams',
                      'Custom Foams',
                      'Stockinette'
                    ];
                    
                    // Show fields in defined order first
                    foreach ($field_order as $field_key) {
                      if (isset($formatted_fields[$field_key])) {
                        ?>
                        <div class="field-item" style="margin-bottom:3px;">
                          <span class="field-label" style="font-weight:600;color:#0073aa;"><?php echo esc_html($field_key); ?>:</span>
                          <span class="field-value" style="margin-left:6px;"><?php echo esc_html($formatted_fields[$field_key]); ?></span>
                        </div>
                        <?php
                        unset($formatted_fields[$field_key]);
                      }
                    }
                    
                    // Show any remaining fields
                    foreach ($formatted_fields as $label => $value): ?>
                      <div class="field-item" style="margin-bottom:3px;">
                        <span class="field-label" style="font-weight:600;color:#0073aa;"><?php echo esc_html($label); ?>:</span>
                        <span class="field-value" style="margin-left:6px;"><?php echo esc_html($value); ?></span>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <div style="color:#999;">No data available</div>
                  <?php endif; ?>
                </td>
                <td style="text-align: center; font-weight: bold; font-size: 12px; padding: 10px; vertical-align: top;">
                  <?php echo intval($part['qty']); ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        
        <?php
        $current_page++;
      }
      ?>
      
      <script>
        // Set the document title immediately and multiple times to ensure it takes effect
        var batchFilename = 'Batch_<?php echo esc_js($batch_uid); ?>_<?php echo date('Y-m-d'); ?>';
        var batchUid = '<?php echo esc_js($batch_uid); ?>';
        var totalPages = <?php echo $total_pages; ?>;
        
        document.title = batchFilename;
        
        // Override print function to ensure title is set
        var originalPrint = window.print;
        window.print = function() {
          document.title = batchFilename;
          originalPrint.call(window);
        };
        
        // Set title multiple times during load
        document.addEventListener('DOMContentLoaded', function() {
          document.title = batchFilename;
        });
        
        window.onload = function() {
          // Set title
          document.title = batchFilename;
          
          // Wait longer before auto-printing to ensure everything is loaded
          setTimeout(function() {
            document.title = batchFilename;
            // Add another small delay
            setTimeout(function() {
              window.print();
            }, 100);
          }, 1000);
        };
        
        // Set title before print dialog
        window.addEventListener('beforeprint', function(event) {
          document.title = batchFilename;
        });
        
        // Keep title after print
        window.addEventListener('afterprint', function(event) {
          document.title = batchFilename;
        });
      </script>
    </body>
    </html>
    <?php
    exit;
  }

  private static function render_batch_selector($open_batches) {
    echo '<div class="card" style="padding:20px;background:white;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);">';
    echo '<h2 style="margin:0 0 20px;color:#333;">Active Batches</h2>';
    
    // Changed to single column full width layout
    echo '<div style="display:flex;flex-direction:column;gap:15px;">';
    
    foreach($open_batches as $r){
      $link = admin_url('admin.php?page=fodr-batch&uid='.$r['batch_uid']);
      $depth = ($r['depth_cm']!==null ? intval($r['depth_cm']).' cm' : 'Mixed depths');
      $progress = intval($r['done']).'/'.intval($r['total']);
      $progress_pct = $r['total'] ? floor($r['done']*100/$r['total']) : 0;
      $status_color = self::get_status_color($r['status']);
      
      // Full width single line batch card
      echo '<div class="batch-card" style="background:white;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;display:flex;align-items:center;">';
      
      // Left section - Batch ID and Grade
      echo '<div style="background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);color:white;padding:15px 20px;min-width:200px;">';
      echo '<h4 style="margin:0;font-size:16px;">'.$r['batch_uid'].'</h4>';
      echo '<div style="font-size:13px;opacity:0.9;margin-top:3px;">'.esc_html($r['grade']).'</div>';
      echo '</div>';
      
      // Middle section - Details
      echo '<div style="flex:1;padding:15px 20px;display:flex;align-items:center;justify-content:space-between;">';
      
      echo '<div style="display:flex;gap:40px;align-items:center;">';
      echo '<div><strong style="color:#666;font-size:12px;">DEPTH</strong><br><span style="font-size:15px;">'.$depth.'</span></div>';
      echo '<div><strong style="color:#666;font-size:12px;">STATUS</strong><br><span style="color:'.$status_color.';font-weight:bold;font-size:15px;">'.esc_html(ucwords($r['status'])).'</span></div>';
      echo '<div><strong style="color:#666;font-size:12px;">PROGRESS</strong><br><span style="font-size:15px;font-weight:bold;">'.$progress.' <span style="color:#666;">('.$progress_pct.'%)</span></span></div>';
      echo '<div><strong style="color:#666;font-size:12px;">CREATED</strong><br><span style="font-size:14px;">'.esc_html(date_i18n('M j, Y', strtotime($r['created_at']))).'</span></div>';
      echo '</div>';
      
      // Progress bar
      echo '<div style="width:200px;">';
      echo '<div style="background:#e0e0e0;border-radius:10px;height:8px;overflow:hidden;">';
      echo '<div style="background:linear-gradient(90deg, #4caf50, #8bc34a);width:'.$progress_pct.'%;height:100%;transition:width 0.3s ease;"></div>';
      echo '</div>';
      echo '</div>';
      
      // Action buttons - Complete, Print, and Manage
      echo '<div style="display:flex;gap:8px;">';
      echo '<button onclick="event.stopPropagation(); printProductionSheet(\''.$r['batch_uid'].'\');" class="button" style="min-width:50px;" title="Print Production Sheet">🖨️ Print</button>';
      echo '<a href="'.esc_url($link).'" class="button button-primary" style="min-width:120px;text-align:center;" onclick="event.stopPropagation();">Manage Batch</a>';
      
      // Show Complete button for any active batch (not already completed)
      if ($r['status'] !== 'done') {
        echo '<button onclick="event.stopPropagation(); completeBatchFromList(\''.$r['batch_uid'].'\');" class="button" style="background:#4caf50;color:white;border-color:#4caf50;min-width:80px;margin-left:10px;" title="Complete all parts in this batch">✅ Complete</button>';
      }
      
      echo '</div>';
      
      echo '</div>';
      
      echo '</div>';
    }
    
    echo '</div></div>';
    
    // Add JavaScript for all functions INCLUDING completeBatchFromList
    echo '<script>
    function printProductionSheet(batchUid) {
      const printUrl = "' . admin_url('admin-ajax.php') . '?action=fodr_print_batch&batch_uid=" + batchUid;
      window.open(printUrl, "_blank", "width=1000,height=800,scrollbars=yes");
    }
    
    function completeBatchFromList(batchUid) {
      if (confirm("Are you sure you want to complete batch " + batchUid + "?\\n\\nThis will mark ALL parts in this batch as completed.")) {
        // Create and submit form to complete the batch
        const form = document.createElement("form");
        form.method = "POST";
        form.action = "' . admin_url('admin-post.php') . '";
        
        const fields = {
          "action": "fodr_mark_batch_done",
          "uid": batchUid,
          "_wpnonce": "' . wp_create_nonce('fodr_mark_batch_done') . '"
        };
        
        console.log("Submitting batch completion:", fields);
        
        Object.keys(fields).forEach(key => {
          const input = document.createElement("input");
          input.type = "hidden";
          input.name = key;
          input.value = fields[key];
          form.appendChild(input);
        });
        
        document.body.appendChild(form);
        form.submit();
      }
    }
    </script>';
  }

  private static function render_batch_header($batch, $total, $done, $pct, $waste_assignments) {
    $status_color = self::get_status_color($batch['status']);
    $waste_count = count($waste_assignments);
    $total_waste_volume = 0;
    foreach ($waste_assignments as $wa) {
      $total_waste_volume += ($wa['width_cm'] * $wa['length_cm'] * $wa['thickness_cm'] * $wa['quantity']);
    }

    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding:20px;background:linear-gradient(135deg, #667eea 0%, #764ba2 100%);color:white;border-radius:10px;box-shadow:0 4px 15px rgba(102,126,234,0.3);">';
    
    echo '<div>';
    echo '<h2 style="margin:0;font-size:28px;">'.$batch['batch_uid'].'</h2>';
    echo '<div style="font-size:15px;opacity:0.95;margin-top:5px;">';
    echo esc_html($batch['grade']);
    if ($batch['depth_cm']) echo ' • '.intval($batch['depth_cm']).' cm depth';
    echo ' • Created '.date_i18n('M j, Y', strtotime($batch['created_at']));
    echo '</div>';
    echo '</div>';

    echo '<div style="display:flex;gap:30px;align-items:center;">';
    
    // Parts complete
    echo '<div style="text-align:center;">';
    echo '<div style="font-size:32px;font-weight:bold;">'.$done.'/'.$total.'</div>';
    echo '<div style="font-size:13px;opacity:0.9;">Parts Complete</div>';
    echo '</div>';
    
    // Progress percentage
    echo '<div style="text-align:center;">';
    echo '<div style="font-size:32px;font-weight:bold;">'.$pct.'%</div>';
    echo '<div style="font-size:13px;opacity:0.9;">Progress</div>';
    echo '</div>';
    
    // Waste info if exists
    if ($waste_count > 0) {
      echo '<div style="text-align:center;border-left:1px solid rgba(255,255,255,0.3);padding-left:30px;">';
      echo '<div style="font-size:24px;font-weight:bold;">'.$waste_count.'</div>';
      echo '<div style="font-size:13px;opacity:0.9;">Waste Pieces</div>';
      echo '</div>';
      
      echo '<div style="text-align:center;">';
      echo '<div style="font-size:24px;font-weight:bold;">'.number_format($total_waste_volume/1000, 1).' L</div>';
      echo '<div style="font-size:13px;opacity:0.9;">Waste Volume</div>';
      echo '</div>';
    }
    
    // Status
    echo '<div style="text-align:center;border-left:1px solid rgba(255,255,255,0.3);padding-left:30px;">';
    echo '<div style="font-size:18px;font-weight:bold;padding:5px 15px;background:rgba(255,255,255,0.2);border-radius:20px;">'.esc_html(ucwords($batch['status'])).'</div>';
    echo '<div style="font-size:13px;opacity:0.9;margin-top:5px;">Status</div>';
    echo '</div>';
    
    echo '</div>';

    echo '</div>';

    // Back navigation WITH NEW COMPLETED BATCHES LINK
    echo '<div style="margin-bottom:15px;">';
    echo '<a href="'.admin_url('admin.php?page=fodr-batches').'" class="button">← Back to Batches</a> ';
    echo '<a href="'.admin_url('admin.php?page=fodr-batch').'" class="button">Select Different Batch</a> ';
    echo '<a href="'.admin_url('admin.php?page=fodr-completed-batches').'" class="button" style="background:#4caf50;color:white;border-color:#4caf50;">📋 Completed Batches</a>';
    echo '</div>';
  }

  private static function get_waste_assignments($batch_uid) {
    global $wpdb;
    
    $waste_assignments = $wpdb->get_results($wpdb->prepare("
      SELECT w.*, CONCAT('W', LPAD(w.id, 4, '0')) as tracking_id
      FROM {$wpdb->prefix}foam_waste w
      WHERE w.note LIKE %s OR w.note LIKE %s
      ORDER BY w.updated_at DESC
    ", '%batch: '.$batch_uid.'%', '%batch:'.$batch_uid.'%'), ARRAY_A);

    return $waste_assignments;
  }

  private static function render_waste_assignments($waste_assignments, $grade) {
    echo '<div class="card" style="padding:20px;background:#fff8e1;border-left:5px solid #ffc107;border-radius:8px;">';
    echo '<h3 style="margin:0 0 15px;color:#f57f17;font-size:18px;">🗂️ Assigned Waste Materials</h3>';

    $total_volume = 0;
    $total_value_estimate = 0;

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:15px;">';
    
    foreach ($waste_assignments as $waste) {
      $volume = $waste['width_cm'] * $waste['length_cm'] * $waste['thickness_cm'] * $waste['quantity'];
      $total_volume += $volume;
      
      $value_per_liter = 5;
      $estimated_value = ($volume / 1000) * $value_per_liter;
      $total_value_estimate += $estimated_value;

      echo '<div style="background:white;border:1px solid #e0e0e0;border-radius:6px;padding:12px;">';
      echo '<div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:8px;">';
      echo '<div>';
      echo '<strong style="color:#f57f17;font-size:14px;">'.$waste['tracking_id'].'</strong>';
      echo '<div style="font-size:11px;color:#666;">Added '.date_i18n('M j, Y', strtotime($waste['created_at'])).'</div>';
      echo '</div>';
      echo '<span style="background:#e8f5e8;color:#2e7d32;padding:2px 8px;border-radius:12px;font-size:11px;">'.esc_html($waste['status']).'</span>';
      echo '</div>';

      echo '<div style="font-family:monospace;font-size:13px;margin-bottom:6px;color:#333;">';
      echo $waste['width_cm'].' × '.$waste['length_cm'].' × '.$waste['thickness_cm'].' cm';
      if ($waste['quantity'] > 1) echo ' × '.$waste['quantity'].' qty';
      echo '</div>';

      echo '<div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;">';
      echo '<span><strong>'.number_format($volume/1000, 1).' L</strong></span>';
      echo '<span style="color:#666;">~£'.number_format($estimated_value, 2).'</span>';
      echo '</div>';

      if ($waste['location']) {
        echo '<div style="font-size:11px;color:#666;margin-top:6px;">📍 '.$waste['location'].'</div>';
      }

      echo '</div>';
    }

    echo '</div>';

    // Summary
    echo '<div style="margin-top:15px;padding:12px;background:#f5f5f5;border-radius:6px;display:flex;justify-content:space-between;align-items:center;">';
    echo '<span><strong>Total Waste Volume:</strong> '.number_format($total_volume/1000, 1).' L ('.count($waste_assignments).' pieces)</span>';
    echo '<span><strong>Estimated Value:</strong> £'.number_format($total_value_estimate, 2).'</span>';
    echo '</div>';

    echo '</div>';
  }

  private static function render_production_analysis($rows, $batch) {
    // Calculate production metrics
    $by_status = [];
    $by_depth = [];
    $total_volume = 0;
    
    foreach ($rows as $row) {
      $status = $row['status'];
      $depth = (int)$row['depth_cm'];
      
      if (!isset($by_status[$status])) $by_status[$status] = 0;
      $by_status[$status]++;
      
      if (!isset($by_depth[$depth])) $by_depth[$depth] = 0;
      $by_depth[$depth] += (int)$row['qty'];

      // Calculate volume from meta if available
      if ($row['meta_json']) {
        $meta = json_decode($row['meta_json'], true);
        if ($meta) {
          $volume = self::calculate_part_volume($meta, $row['depth_cm'], $row['qty']);
          $total_volume += $volume;
        }
      }
    }

    ksort($by_depth);

    echo '<div class="card" style="padding:20px;background:white;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);">';
    echo '<h3 style="margin:0 0 15px;border-bottom:2px solid #0073aa;padding-bottom:8px;font-size:18px;">📊 Production Analysis</h3>';

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;">';

    // Status breakdown
    echo '<div style="background:#f8f9fa;padding:15px;border-radius:6px;">';
    echo '<h4 style="margin:0 0 10px;color:#333;font-size:14px;">Status Breakdown</h4>';
    foreach ($by_status as $status => $count) {
      $color = self::get_status_color($status);
      $bg = self::get_status_bg($status);
      echo '<div style="display:flex;justify-content:space-between;padding:8px;margin-bottom:5px;background:'.$bg.';border-radius:4px;">';
      echo '<span style="color:'.$color.';font-weight:bold;">'.ucfirst($status).'</span>';
      echo '<span style="font-weight:bold;">'.$count.' parts</span>';
      echo '</div>';
    }
    echo '</div>';

    // Depth distribution
    echo '<div style="background:#f8f9fa;padding:15px;border-radius:6px;">';
    echo '<h4 style="margin:0 0 10px;color:#333;font-size:14px;">Depth Distribution</h4>';
    foreach ($by_depth as $depth => $qty) {
      echo '<div style="display:flex;justify-content:space-between;padding:8px;margin-bottom:5px;background:white;border-radius:4px;">';
      echo '<span style="font-weight:500;">'.$depth.' cm</span>';
      echo '<span style="font-weight:bold;">'.$qty.' pieces</span>';
      echo '</div>';
    }
    echo '</div>';

    // Volume & efficiency
    echo '<div style="background:#f8f9fa;padding:15px;border-radius:6px;">';
    echo '<h4 style="margin:0 0 10px;color:#333;font-size:14px;">Volume Metrics</h4>';
    echo '<div style="display:flex;justify-content:space-between;padding:8px;margin-bottom:5px;background:white;border-radius:4px;">';
    echo '<span>Total Volume</span>';
    echo '<span style="font-weight:bold;color:#0073aa;">'.number_format($total_volume/1000, 1).' L</span>';
    echo '</div>';
    echo '<div style="display:flex;justify-content:space-between;padding:8px;background:white;border-radius:4px;">';
    echo '<span>Avg per Part</span>';
    echo '<span style="font-weight:bold;">'.number_format($total_volume/max(1,count($rows))/1000, 2).' L</span>';
    echo '</div>';
    if ($batch['notes']) {
      echo '<div style="font-size:12px;color:#666;margin-top:10px;padding:8px;background:white;border-radius:4px;">';
      echo '<strong>Notes:</strong> '.$batch['notes'];
      echo '</div>';
    }
    echo '</div>';

    echo '</div></div>';
  }

  private static function calculate_part_volume($meta, $depth_cm, $qty) {
    $unit = $meta['Measure your cushions'] ?? ($meta['_wapf_meta']['fields']['62ea52c0ab295']['value'] ?? 'CM');
    
    $width = self::dimension_to_cm($meta['Side A (Width)'] ?? ($meta['_wapf_meta']['fields']['62ea52c0ab299']['value'] ?? 0), $unit);
    $length = self::dimension_to_cm($meta['Side B (Length)'] ?? ($meta['_wapf_meta']['fields']['62ea52c0ab29c']['value'] ?? 0), $unit);
    $depth = $depth_cm;

    return $width * $length * $depth * $qty;
  }

  private static function dimension_to_cm($value, $unit) {
    $numeric = (float)preg_replace('/[^0-9.]/', '', (string)$value);
    switch (strtoupper($unit)) {
      case 'MM': return $numeric / 10;
      case 'IN': case 'INCH': return $numeric * 2.54;
      default: return $numeric;
    }
  }

  private static function render_batch_actions($uid) {
    echo '<div class="card" style="padding:20px;background:#f8f9fa;border-radius:8px;">';
    echo '<h3 style="margin:0 0 12px;font-size:16px;color:#333;">⚙️ Batch Actions</h3>';

    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
    
    // Delete batch
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline;">';
    wp_nonce_field('fodr_delete_batch');
    echo '<input type="hidden" name="action" value="fodr_delete_batch">';
    echo '<input type="hidden" name="uid" value="'.esc_attr($uid).'">';
    echo '<button class="button button-secondary" onclick="return confirm(\'Delete this batch? All parts will return to pending status.\')">🗑️ Delete Batch</button>';
    echo '</form>';

    // Export data
    echo '<button class="button" onclick="exportBatchData(\''.$uid.'\')" title="Export batch data to CSV">📊 Export Data</button>';

    // Print production sheet
    echo '<button class="button" onclick="printProductionSheet(\''.$uid.'\')" title="Print production worksheet with JSON data">🖨️ Print Sheet</button>';

    echo '</div></div>';
  }

  private static function render_completion_section($uid, $done, $total) {
    $can_complete = ($done === $total && $total > 0);
    
    echo '<div class="card" style="padding:20px;background:'.($can_complete ? '#e8f5e8' : '#fff3cd').';border-left:5px solid '.($can_complete ? '#4caf50' : '#ffc107').';border-radius:8px;">';
    echo '<h3 style="margin:0 0 12px;color:'.($can_complete ? '#2e7d32' : '#f57f17').';font-size:16px;">🎯 Batch Completion</h3>';

    if ($can_complete) {
      echo '<p style="margin:0 0 12px;color:#2e7d32;">All parts in this batch are complete and ready for final processing.</p>';
      
      echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
      wp_nonce_field('fodr_mark_batch_done');
      echo '<input type="hidden" name="action" value="fodr_mark_batch_done">';
      echo '<input type="hidden" name="uid" value="'.esc_attr($uid).'">';
      echo '<button class="button button-primary button-large">✅ Mark Batch Complete</button>';
      echo '</form>';
    } else {
      $remaining = $total - $done;
      echo '<p style="margin:0;color:#f57f17;">'.$remaining.' parts remaining to complete this batch ('.$done.'/'.$total.' done).</p>';
    }

    echo '</div>';
  }

  // Helper functions
  private static function get_status_color($status) {
    switch (strtolower($status)) {
      case 'done': case 'completed': return '#4caf50';
      case 'in_progress': return '#ff9800'; 
      case 'open': return '#2196f3';
      case 'pending': return '#ffb900';
      default: return '#666';
    }
  }

  private static function get_status_bg($status) {
    switch (strtolower($status)) {
      case 'done': case 'completed': return '#e8f5e8';
      case 'in_progress': return '#fff3e0'; 
      case 'open': return '#e3f2fd';
      case 'pending': return '#fff3cd';
      default: return '#f5f5f5';
    }
  }

  // Keep auto_apply for backwards compatibility but remove the UI
  public static function auto_apply(){
    // This method is kept for backwards compatibility
    // but the Auto Plan UI has been removed
    wp_redirect(admin_url('admin.php?page=fodr-batch'));
    exit;
  }

  public static function remove_parts(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_remove_parts');

    global $wpdb;
    $uid      = sanitize_text_field($_POST['uid'] ?? '');
    $part_ids = array_map('intval', (array)($_POST['part_ids'] ?? []));
    if (!$uid || !$part_ids){ wp_redirect(admin_url('admin.php?page=fodr-batch&uid='.$uid)); exit; }

    $tbl_parts = $wpdb->prefix.'foam_order_parts';
    $in = implode(',', $part_ids);
    $wpdb->query("UPDATE $tbl_parts SET status='pending', batch_id=NULL WHERE id IN ($in)");

    FODR_Batches_Common::recalc_orders_by_part_ids($part_ids);

    wp_redirect(admin_url('admin.php?page=fodr-batch&uid='.$uid)); exit;
  }

  public static function delete_batch(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_delete_batch');

    global $wpdb;
    $uid = sanitize_text_field($_POST['uid'] ?? '');
    if (!$uid){ wp_redirect(admin_url('admin.php?page=fodr-batches')); exit; }

    $tbl_batches = $wpdb->prefix.'foam_batches';
    $tbl_parts   = $wpdb->prefix.'foam_order_parts';
    $tbl_waste   = $wpdb->prefix.'foam_waste';

    // Get part IDs before deletion for progress recalculation
    $pids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $tbl_parts WHERE batch_id=%s",$uid)) ?: [];
    
    // Reset parts to pending status and remove batch assignment
    $wpdb->query($wpdb->prepare("UPDATE $tbl_parts SET status='pending', batch_id=NULL WHERE batch_id=%s",$uid));
    
    // Release assigned waste materials back to available status
    $wpdb->update($tbl_waste, [
      'status' => 'available',
      'note' => 'Released from deleted batch',
      'updated_at' => current_time('mysql')
    ], [
      'status' => 'reserved'
    ], "note LIKE '%batch: $uid%' OR note LIKE '%batch:$uid%'");
    
    // Also handle waste with different note formats
    $affected_waste = $wpdb->get_results($wpdb->prepare("
      SELECT id FROM $tbl_waste 
      WHERE status = 'reserved' 
      AND (note LIKE %s OR note LIKE %s)
    ", '%batch: '.$uid.'%', '%batch:'.$uid.'%'));
    
    if ($affected_waste) {
      $waste_ids = array_column($affected_waste, 'id');
      $waste_in = implode(',', array_map('intval', $waste_ids));
      
      $wpdb->query("
        UPDATE $tbl_waste 
        SET status = 'available', 
            note = CONCAT(COALESCE(note, ''), ' - Released from deleted batch $uid'),
            updated_at = '".current_time('mysql')."'
        WHERE id IN ($waste_in)
      ");
    }
    
    // Delete the batch record
    $wpdb->delete($tbl_batches, ['batch_uid'=>$uid]);

    // Recalculate order progress for affected parts
    if ($pids) FODR_Batches_Common::recalc_orders_by_part_ids($pids);

    wp_redirect(admin_url('admin.php?page=fodr-batches&msg=batch_deleted&waste_released='.count($affected_waste))); exit;
  }

  // NEW: Undo batch completion handler
  public static function undo_batch_completion(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_undo_batch_completion');

    global $wpdb;
    $uid = sanitize_text_field($_POST['uid'] ?? '');
    if (!$uid){ 
      wp_redirect(admin_url('admin.php?page=fodr-completed-batches')); 
      exit; 
    }

    $tbl_batches = $wpdb->prefix.'foam_batches';
    $tbl_parts   = $wpdb->prefix.'foam_order_parts';
    $tbl_waste   = $wpdb->prefix.'foam_waste';

    // Move batch back to in_progress status and clear completion timestamp
    $wpdb->update($tbl_batches, [
      'status'=>'in_progress',
      'completed_at'=>null
    ], ['batch_uid'=>$uid]);
    
    // Mark all parts in this batch back to in_progress
    $wpdb->update($tbl_parts, ['status'=>'in_progress'], ['batch_id'=>$uid]);

    // Release any waste materials that were consumed/reserved for this batch back to available
    $affected_waste = $wpdb->get_results($wpdb->prepare("
      SELECT id FROM $tbl_waste 
      WHERE (status = 'consumed' OR status = 'reserved')
      AND (note LIKE %s OR note LIKE %s)
    ", '%batch: '.$uid.'%', '%batch:'.$uid.'%'));
    
    if ($affected_waste) {
      $waste_ids = array_column($affected_waste, 'id');
      $waste_in = implode(',', array_map('intval', $waste_ids));
      
      $wpdb->query("
        UPDATE $tbl_waste 
        SET status = 'available', 
            note = CONCAT(COALESCE(note, ''), ' - Released from undone batch $uid'),
            updated_at = '".current_time('mysql')."'
        WHERE id IN ($waste_in)
      ");
    }

    // Recalculate order progress for affected orders
    $pids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $tbl_parts WHERE batch_id=%s",$uid)) ?: [];
    if ($pids) {
      FODR_Batches_Common::recalc_orders_by_part_ids($pids);
    }

    // Redirect back to completed batches with success message
    wp_redirect(admin_url('admin.php?page=fodr-completed-batches&msg=batch_undone&batch_id='.$uid.'&waste_released='.count($affected_waste))); 
    exit;
  }

  public static function mark_done(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_mark_batch_done');

    global $wpdb;
    $uid = sanitize_text_field($_POST['uid'] ?? '');
    if (!$uid){ 
      wp_redirect(admin_url('admin.php?page=fodr-batch')); 
      exit; 
    }

    $tbl_batches = $wpdb->prefix.'foam_batches';
    $tbl_parts   = $wpdb->prefix.'foam_order_parts';

    // Mark ALL parts in this batch as done
    $wpdb->update($tbl_parts, ['status'=>'done'], ['batch_id'=>$uid]);
    
    // Update batch status to completed
    $wpdb->update($tbl_batches, [
      'status'=>'done',
      'completed_at'=>current_time('mysql')
    ], ['batch_uid'=>$uid]);

    // Recalculate order progress for affected orders
    $pids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $tbl_parts WHERE batch_id=%s",$uid)) ?: [];
    if ($pids) {
      FODR_Batches_Common::recalc_orders_by_part_ids($pids);
    }

    // Redirect back to batch list with success message
    wp_redirect(admin_url('admin.php?page=fodr-batch&msg=batch_completed&batch_id='.$uid)); 
    exit;
  }
}
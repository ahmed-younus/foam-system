<?php
if (!defined('ABSPATH')) exit;

class FODR_Waste {

  public static function init(){
    add_action('admin_menu', [__CLASS__, 'menu']);
    add_action('admin_post_fodr_waste_add',    [__CLASS__, 'handle_add']);
    add_action('admin_post_fodr_waste_delete', [__CLASS__, 'handle_delete']);
    add_action('admin_post_fodr_waste_update_status', [__CLASS__, 'handle_update_status']);
    
    // Ensure table exists on init
    self::ensure_waste_table();
  }

  public static function menu(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    add_submenu_page('fodr-dashboard', __('Waste','fod-receiver'), __('Waste','fod-receiver'),
      $cap, 'fodr-waste', [__CLASS__, 'render']);
  }

  // Ensure table exists with all required columns
  public static function ensure_waste_table(){
    global $wpdb;
    $tbl = $wpdb->prefix.'foam_waste';
    
    // Check if table exists
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl));
    
    if ($table_exists != $tbl) {
        // Create table if doesn't exist
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$tbl} (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          grade VARCHAR(255) NOT NULL,
          width_cm INT NOT NULL,
          length_cm INT NOT NULL,
          thickness_cm INT NOT NULL,
          quantity INT NOT NULL DEFAULT 1,
          status ENUM('available','reserved','consumed') NOT NULL DEFAULT 'available',
          location VARCHAR(120) NULL,
          note TEXT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NULL,
          PRIMARY KEY (id),
          KEY grade_idx (grade),
          KEY status_idx (status),
          KEY thickness_idx (thickness_cm)
        ) {$charset};";
        
        dbDelta($sql);
    } else {
        // Table exists, check if all columns exist
        $columns = $wpdb->get_col("DESCRIBE {$tbl}");
        
        if (!in_array('quantity', $columns)) {
            $wpdb->query("ALTER TABLE {$tbl} ADD COLUMN quantity INT NOT NULL DEFAULT 1 AFTER thickness_cm");
            error_log('Added quantity column to foam_waste table');
        }
        
        if (!in_array('updated_at', $columns)) {
            $wpdb->query("ALTER TABLE {$tbl} ADD COLUMN updated_at DATETIME NULL AFTER created_at");
            error_log('Added updated_at column to foam_waste table');
        }
    }
    return true;
  }

  public static function render(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    if (!current_user_can($cap)) wp_die(__('Not allowed','fod-receiver'));

    // Show messages
    if (isset($_GET['success'])) {
      if ($_GET['success'] === 'added') {
        echo '<div class="notice notice-success"><p>✅ Waste piece added successfully!</p></div>';
      } elseif ($_GET['success'] === 'deleted') {
        echo '<div class="notice notice-success"><p>✅ Waste piece deleted!</p></div>';
      } elseif ($_GET['success'] === 'status_updated') {
        echo '<div class="notice notice-success"><p>✅ Status updated!</p></div>';
      }
    }
    if (isset($_GET['error'])) {
      echo '<div class="notice notice-error"><p>❌ Error: '.esc_html($_GET['error']).'</p></div>';
    }

    echo '<div class="wrap">';
    echo '<h1>Waste Inventory Management</h1>';

    // Quick stats
    self::render_quick_stats();

    // Main 2-column layout
    echo '<div style="display:grid;grid-template-columns:1fr 2fr;gap:20px;margin-top:15px;">';
    
    // Left: Add form
    echo '<div>';
    echo '<h2>Add New Waste</h2>';
    self::render_add_form();
    echo '</div>';
    
    // Right: Inventory table
    echo '<div>';
    echo '<h2>Current Inventory</h2>';
    self::render_inventory_table();
    echo '</div>';
    
    echo '</div>'; // end grid

    echo '</div>'; // end wrap
  }

  private static function render_quick_stats(){
    global $wpdb;
    $tbl = $wpdb->prefix.'foam_waste';

    $available = $wpdb->get_var("SELECT COUNT(*) FROM $tbl WHERE status='available'");
    $reserved = $wpdb->get_var("SELECT COUNT(*) FROM $tbl WHERE status='reserved'");
    $consumed = $wpdb->get_var("SELECT COUNT(*) FROM $tbl WHERE status='consumed'");
    $total_volume = $wpdb->get_var("SELECT SUM(width_cm * length_cm * thickness_cm * quantity) FROM $tbl WHERE status='available'");
    $total_volume = $total_volume ? $total_volume / 1000 : 0;

    echo '<div style="display:flex;gap:15px;margin-bottom:20px;">';
    echo '<div style="background:#e8f5e8;padding:12px;border-radius:6px;text-align:center;min-width:100px;">';
    echo '<div style="font-size:24px;font-weight:bold;color:#2e7d32;">'.$available.'</div>';
    echo '<div style="font-size:12px;color:#666;">Available</div>';
    echo '</div>';
    echo '<div style="background:#fff3e0;padding:12px;border-radius:6px;text-align:center;min-width:100px;">';
    echo '<div style="font-size:24px;font-weight:bold;color:#f57f17;">'.$reserved.'</div>';
    echo '<div style="font-size:12px;color:#666;">Reserved</div>';
    echo '</div>';
    echo '<div style="background:#f5f5f5;padding:12px;border-radius:6px;text-align:center;min-width:100px;">';
    echo '<div style="font-size:24px;font-weight:bold;color:#666;">'.$consumed.'</div>';
    echo '<div style="font-size:12px;color:#666;">Consumed</div>';
    echo '</div>';
    echo '<div style="background:#e3f2fd;padding:12px;border-radius:6px;text-align:center;min-width:120px;">';
    echo '<div style="font-size:24px;font-weight:bold;color:#1976d2;">'.number_format($total_volume, 0).'L</div>';
    echo '<div style="font-size:12px;color:#666;">Available Volume</div>';
    echo '</div>';
    echo '</div>';
  }

  private static function render_add_form(){
    // Get existing grades
    global $wpdb;
    $grades_query = "
      SELECT DISTINCT grade FROM (
        SELECT DISTINCT grade FROM {$wpdb->prefix}foam_order_parts WHERE grade != ''
        UNION 
        SELECT DISTINCT grade FROM {$wpdb->prefix}foam_waste WHERE grade != ''
      ) AS all_grades 
      ORDER BY grade ASC
    ";
    $existing_grades = $wpdb->get_col($grades_query);

    echo '<div style="background:white;border:1px solid #ddd;border-radius:8px;padding:16px;">';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    wp_nonce_field('fodr_waste_add');
    echo '<input type="hidden" name="action" value="fodr_waste_add">';

    // Grade dropdown
    echo '<div style="margin-bottom:12px;">';
    echo '<label style="font-weight:bold;display:block;margin-bottom:4px;">Grade *</label>';
    echo '<select name="grade" id="grade_select" onchange="toggleCustomGrade()" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;">';
    echo '<option value="">Select Grade</option>';
    foreach($existing_grades as $grade) {
      echo '<option value="'.esc_attr($grade).'">'.esc_html($grade).'</option>';
    }
    echo '<option value="__CUSTOM__">+ Add New Grade</option>';
    echo '</select>';
    echo '<input type="text" name="custom_grade" id="custom_grade" placeholder="Enter new grade name" style="width:100%;padding:8px;margin-top:4px;display:none;border:1px solid #ddd;border-radius:4px;">';
    echo '</div>';

    // Dimensions grid
    echo '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:12px;">';
    echo '<div><label style="font-weight:bold;display:block;margin-bottom:4px;">Width (cm) *</label>';
    echo '<input name="width_cm" type="number" min="1" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;"></div>';
    echo '<div><label style="font-weight:bold;display:block;margin-bottom:4px;">Length (cm) *</label>';
    echo '<input name="length_cm" type="number" min="1" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;"></div>';
    echo '<div><label style="font-weight:bold;display:block;margin-bottom:4px;">Thickness (cm) *</label>';
    echo '<input name="thickness_cm" type="number" min="1" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;"></div>';
    echo '</div>';

    // Other fields
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">';
    echo '<div><label style="font-weight:bold;display:block;margin-bottom:4px;">Quantity</label>';
    echo '<input name="quantity" type="number" min="1" value="1" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;"></div>';
    echo '<div><label style="font-weight:bold;display:block;margin-bottom:4px;">Location</label>';
    echo '<input name="location" type="text" placeholder="e.g., Rack A-3" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;"></div>';
    echo '</div>';

    echo '<div style="margin-bottom:15px;">';
    echo '<label style="font-weight:bold;display:block;margin-bottom:4px;">Notes</label>';
    echo '<input name="note" type="text" placeholder="Optional description" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;">';
    echo '</div>';

    echo '<button class="button button-primary" style="width:100%;padding:10px;">Add to Inventory</button>';

    echo '</form>';

    // Volume calculator
    echo '<div style="margin-top:12px;padding:8px;background:#f8f9fa;border-radius:4px;font-size:13px;">';
    echo '<strong>💡 Quick Calc:</strong> <span id="volume_display">Enter dimensions to see volume</span>';
    echo '</div>';

    echo '</div>'; // end form card

    // JavaScript
    echo '<script>
    function toggleCustomGrade() {
      const select = document.getElementById("grade_select");
      const customInput = document.getElementById("custom_grade");
      
      if (select.value === "__CUSTOM__") {
        customInput.style.display = "block";
        customInput.required = true;
        customInput.focus();
        select.required = false;
      } else {
        customInput.style.display = "none";
        customInput.required = false;
        customInput.value = "";
        select.required = true;
      }
    }

    function updateVolumeDisplay() {
      const w = document.querySelector("input[name=width_cm]").value || 0;
      const l = document.querySelector("input[name=length_cm]").value || 0;
      const t = document.querySelector("input[name=thickness_cm]").value || 0;
      const q = document.querySelector("input[name=quantity]").value || 1;
      
      if (w > 0 && l > 0 && t > 0) {
        const volume_cm3 = w * l * t * q;
        const volume_l = volume_cm3 / 1000;
        document.getElementById("volume_display").textContent = 
          w + "×" + l + "×" + t + " cm × " + q + " qty = " + volume_cm3.toLocaleString() + " cm³ (" + volume_l.toFixed(1) + " L)";
      } else {
        document.getElementById("volume_display").textContent = "Enter dimensions to see volume";
      }
    }
    
    // Form validation before submit
    document.querySelector("form").addEventListener("submit", function(e) {
      const gradeSelect = document.getElementById("grade_select");
      const customGrade = document.getElementById("custom_grade");
      
      let gradeValue = "";
      if (gradeSelect.value === "__CUSTOM__") {
        gradeValue = customGrade.value.trim();
        if (!gradeValue) {
          alert("Please enter a custom grade name");
          e.preventDefault();
          customGrade.focus();
          return false;
        }
      } else {
        gradeValue = gradeSelect.value;
        if (!gradeValue) {
          alert("Please select a grade");
          e.preventDefault();
          gradeSelect.focus();
          return false;
        }
      }
    });
    
    document.addEventListener("DOMContentLoaded", function() {
      ["width_cm", "length_cm", "thickness_cm", "quantity"].forEach(name => {
        document.querySelector("input[name=" + name + "]").addEventListener("input", updateVolumeDisplay);
      });
    });
    </script>';
  }

  private static function render_inventory_table(){
    global $wpdb;
    $tbl = $wpdb->prefix.'foam_waste';

    // Filters
    $grade = isset($_GET['grade']) ? sanitize_text_field($_GET['grade']) : '';
    $status= isset($_GET['status'])? sanitize_text_field($_GET['status']): '';

    $where = "WHERE 1=1";
    $args  = [];
    if ($grade){ $where .= " AND grade = %s"; $args[] = $grade; }
    if ($status){ $where .= " AND status = %s"; $args[] = $status; }

    $rows = $wpdb->get_results($wpdb->prepare("
      SELECT *, 
        (width_cm * length_cm * thickness_cm * quantity) as total_volume,
        CONCAT('W', LPAD(id, 4, '0')) as tracking_id
      FROM $tbl $where 
      ORDER BY status ASC, created_at DESC 
      LIMIT 200
    ", $args), ARRAY_A);

    // Get unique grades and counts for filters
    $grades_stats = $wpdb->get_results("
      SELECT grade, status, COUNT(*) as count 
      FROM $tbl 
      GROUP BY grade, status 
      ORDER BY grade ASC, status ASC
    ", ARRAY_A);

    echo '<div style="background:white;border:1px solid #ddd;border-radius:8px;overflow:hidden;">';
    
    // Filters header
    echo '<div style="background:#f8f9fa;padding:12px;border-bottom:1px solid #ddd;">';
    echo '<form method="get" style="display:flex;gap:10px;align-items:center;">';
    echo '<input type="hidden" name="page" value="fodr-waste">';
    
    echo '<select name="grade" style="padding:6px;border:1px solid #ddd;border-radius:4px;">';
    echo '<option value="">All Grades</option>';
    $unique_grades = array_unique(array_column($grades_stats, 'grade'));
    foreach($unique_grades as $g){
      echo '<option value="'.esc_attr($g).'" '.selected($grade,$g,false).'>'.esc_html($g).'</option>';
    }
    echo '</select>';

    echo '<select name="status" style="padding:6px;border:1px solid #ddd;border-radius:4px;">';
    echo '<option value="">All Status</option>';
    foreach(['available','reserved','consumed'] as $st){
      echo '<option value="'.$st.'" '.selected($status,$st,false).'>'.ucfirst($st).'</option>';
    }
    echo '</select>';

    echo '<button class="button button-small">Filter</button>';
    echo '<a href="'.admin_url('admin.php?page=fodr-waste').'" class="button button-small">Reset</a>';
    echo '</form>';
    echo '</div>';

    // Table content
    if ($rows) {
      echo '<div style="max-height:600px;overflow-y:auto;">';
      echo '<table class="widefat striped" style="font-size:13px;margin:0;border:none;">';
      echo '<thead style="position:sticky;top:0;background:#f9f9f9;z-index:1;"><tr>';
      echo '<th style="padding:8px;width:70px;">ID</th>';
      echo '<th style="padding:8px;">Grade</th>';
      echo '<th style="padding:8px;width:120px;">Dimensions</th>';
      echo '<th style="padding:8px;width:60px;">Qty</th>';
      echo '<th style="padding:8px;width:80px;">Volume</th>';
      echo '<th style="padding:8px;width:80px;">Status</th>';
      echo '<th style="padding:8px;">Location</th>';
      echo '<th style="padding:8px;width:80px;">Added</th>';
      echo '<th style="padding:8px;width:100px;">Actions</th>';
      echo '</tr></thead><tbody>';

      foreach($rows as $r){
        $volume_l = $r['total_volume'] / 1000;
        $status_color = self::get_status_color($r['status']);
        $status_bg = self::get_status_bg($r['status']);
        
        echo '<tr>';
        echo '<td style="padding:6px;"><strong style="color:#0073aa;">'.esc_html($r['tracking_id']).'</strong></td>';
        echo '<td style="padding:6px;">';
        echo '<span style="background:#e3f2fd;padding:2px 6px;border-radius:3px;font-size:11px;">'.esc_html($r['grade']).'</span>';
        echo '</td>';
        echo '<td style="padding:6px;font-family:monospace;font-size:11px;">';
        echo intval($r['width_cm']).'×'.intval($r['length_cm']).'×'.intval($r['thickness_cm']);
        echo '</td>';
        echo '<td style="padding:6px;text-align:center;">'.intval($r['quantity']).'</td>';
        echo '<td style="padding:6px;text-align:right;font-weight:bold;">'.number_format($volume_l, 1).'L</td>';
        echo '<td style="padding:6px;">';
        echo '<span style="background:'.$status_bg.';color:'.$status_color.';padding:2px 6px;border-radius:10px;font-size:10px;font-weight:bold;">';
        echo esc_html(ucfirst($r['status']));
        echo '</span></td>';
        echo '<td style="padding:6px;font-size:11px;">'.esc_html($r['location'] ?: '—').'</td>';
        echo '<td style="padding:6px;font-size:11px;">'.esc_html(date_i18n('M j', strtotime($r['created_at']))).'</td>';
        echo '<td style="padding:6px;">';
        
        // Quick status change buttons
        if ($r['status'] === 'available') {
          echo '<button onclick="updateStatus('.$r['id'].', \'reserved\')" class="button button-small" style="font-size:10px;padding:2px 6px;margin:1px;">Reserve</button>';
        } elseif ($r['status'] === 'reserved') {
          echo '<button onclick="updateStatus('.$r['id'].', \'available\')" class="button button-small" style="font-size:10px;padding:2px 6px;margin:1px;">Release</button><br>';
          echo '<button onclick="updateStatus('.$r['id'].', \'consumed\')" class="button button-small" style="font-size:10px;padding:2px 6px;margin:1px;">Use</button>';
        }
        
        echo '<form style="display:inline;" method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'Delete?\');">';
        wp_nonce_field('fodr_waste_delete');
        echo '<input type="hidden" name="action" value="fodr_waste_delete">';
        echo '<input type="hidden" name="id" value="'.intval($r['id']).'">';
        echo '<button style="font-size:10px;padding:2px 6px;margin:1px;color:#dc3232;border:none;background:none;cursor:pointer;">✕</button>';
        echo '</form>';
        
        echo '</td></tr>';
      }

      echo '</tbody></table></div>';
      
      echo '<div style="padding:10px;background:#f8f9fa;text-align:center;font-size:12px;color:#666;">';
      echo 'Showing '.count($rows).' pieces • Total volume: '.number_format(array_sum(array_column($rows, 'total_volume'))/1000, 1).' L';
      echo '</div>';
    } else {
      echo '<div style="padding:40px;text-align:center;color:#666;">No waste pieces found</div>';
    }

    echo '</div>'; // end table card

    // Status update JavaScript
    $nonce = wp_create_nonce('fodr_waste_update_status');
    $admin_url = admin_url('admin-post.php');
    
    echo '<script>
    function updateStatus(wasteId, newStatus) {
      if (!confirm("Update status to " + newStatus + "?")) return;
      
      const form = document.createElement("form");
      form.method = "POST";
      form.action = "'.esc_js($admin_url).'";
      
      const fields = {
        "action": "fodr_waste_update_status",
        "id": wasteId,
        "status": newStatus,
        "_wpnonce": "'.esc_js($nonce).'"
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
    </script>';
  }

  // Helper functions
  private static function get_status_color($status) {
    switch($status) {
      case 'available': return '#2e7d32';
      case 'reserved': return '#f57f17';
      case 'consumed': return '#666';
      default: return '#666';
    }
  }

  private static function get_status_bg($status) {
    switch($status) {
      case 'available': return '#e8f5e8';
      case 'reserved': return '#fff3e0';
      case 'consumed': return '#f5f5f5';
      default: return '#f0f0f0';
    }
  }

  // Handler methods with proper error handling
  public static function handle_add(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_waste_add');
    
    global $wpdb;
    $tbl = $wpdb->prefix.'foam_waste';

    // Debug: Log what we received
    error_log('POST data received: ' . print_r($_POST, true));

    // Get grade - fix the logic
    $grade = '';
    if (!empty($_POST['custom_grade']) && trim($_POST['custom_grade']) !== '') {
      $grade = sanitize_text_field(trim($_POST['custom_grade']));
    } elseif (!empty($_POST['grade']) && $_POST['grade'] !== '__CUSTOM__') {
      $grade = sanitize_text_field($_POST['grade']);
    }

    $width_cm = max(1, intval($_POST['width_cm'] ?? 0));
    $length_cm = max(1, intval($_POST['length_cm'] ?? 0));
    $thickness_cm = max(1, intval($_POST['thickness_cm'] ?? 0));
    $quantity = max(1, intval($_POST['quantity'] ?? 1));
    $location = sanitize_text_field($_POST['location'] ?? '');
    $note = sanitize_text_field($_POST['note'] ?? '');

    // Debug: Log processed values
    error_log("Processed values - Grade: '$grade', W:$width_cm, L:$length_cm, T:$thickness_cm, Q:$quantity");

    // Better validation
    if (empty($grade) || $width_cm <= 0 || $length_cm <= 0 || $thickness_cm <= 0) { 
      error_log("Validation failed - Grade empty: " . (empty($grade) ? 'YES' : 'NO'));
      wp_redirect(admin_url('admin.php?page=fodr-waste&error=validation_failed')); 
      exit; 
    }

    // Prepare insert data
    $insert_data = [
      'grade' => $grade,
      'width_cm' => $width_cm,
      'length_cm' => $length_cm,
      'thickness_cm' => $thickness_cm,
      'quantity' => $quantity,
      'status' => 'available',
      'location' => $location,
      'note' => $note,
      'created_at' => current_time('mysql')
    ];

    // Debug: Log insert data
    error_log('Insert data: ' . print_r($insert_data, true));

    // Attempt insert
    $result = $wpdb->insert($tbl, $insert_data);

    // Debug: Log result
    error_log('Insert result: ' . ($result === false ? 'FALSE' : $result));
    error_log('Last error: ' . $wpdb->last_error);
    error_log('Last query: ' . $wpdb->last_query);
    
    if ($result === false) {
      error_log('DETAILED ERROR: Insert failed for waste. Error: ' . $wpdb->last_error);
      wp_redirect(admin_url('admin.php?page=fodr-waste&error=insert_failed&details='.urlencode($wpdb->last_error))); 
    } else {
      $insert_id = $wpdb->insert_id;
      error_log('SUCCESS: Waste inserted with ID: ' . $insert_id);
      $volume = ($width_cm * $length_cm * $thickness_cm * $quantity) / 1000;
      wp_redirect(admin_url('admin.php?page=fodr-waste&success=added&id='.$insert_id.'&volume='.$volume)); 
    }
    exit;
  }

  public static function handle_delete(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_waste_delete');
    
    global $wpdb;
    $tbl = $wpdb->prefix.'foam_waste';
    $id = intval($_POST['id'] ?? 0);
    
    if ($id) {
      $result = $wpdb->delete($tbl, ['id' => $id]);
      if ($result === false) {
        wp_redirect(admin_url('admin.php?page=fodr-waste&error=delete_failed'));
      } else {
        wp_redirect(admin_url('admin.php?page=fodr-waste&success=deleted'));
      }
    } else {
      wp_redirect(admin_url('admin.php?page=fodr-waste&error=invalid_id'));
    }
    exit;
  }

  public static function handle_update_status(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'fodr_waste_update_status')) {
      wp_die('Security check failed');
    }
    
    global $wpdb;
    $tbl = $wpdb->prefix.'foam_waste';
    $id = intval($_POST['id'] ?? 0);
    $status = sanitize_text_field($_POST['status'] ?? '');
    
    if ($id && in_array($status, ['available', 'reserved', 'consumed'])) {
      $result = $wpdb->update($tbl, [
        'status' => $status,
        'updated_at' => current_time('mysql')
      ], ['id' => $id]);
      
      if ($result === false) {
        wp_redirect(admin_url('admin.php?page=fodr-waste&error=status_update_failed'));
      } else {
        wp_redirect(admin_url('admin.php?page=fodr-waste&success=status_updated'));
      }
    } else {
      wp_redirect(admin_url('admin.php?page=fodr-waste&error=invalid_status_params'));
    }
    exit;
  }
}
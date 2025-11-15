<?php
if (!defined('ABSPATH')) exit;

class FODR_Batches_List {

  public static function init(){
    add_action('admin_menu', [__CLASS__, 'menu']);
    add_action('admin_post_fodr_create_batch', [__CLASS__, 'handle_create_batch']);
    add_action('admin_post_fodr_rebuild_parts', [__CLASS__, 'handle_rebuild_action']);
    add_action('admin_post_fodr_assign_waste', [__CLASS__, 'handle_assign_waste']);
    
    // AJAX handler for waste preview
    add_action('wp_ajax_waste_preview', [__CLASS__, 'ajax_waste_preview']);
    
    // Debug test handler
    add_action('wp_ajax_waste_preview_test', [__CLASS__, 'debug_ajax_test']);
    
    // Log that init was called
    error_log('FODR_Batches_List::init() called - AJAX handlers registered');
  }

  // Debug test function
  public static function debug_ajax_test() {
    error_log('Debug AJAX test called successfully');
    wp_send_json_success(['message' => 'AJAX is working fine', 'time' => current_time('mysql')]);
  }

  public static function menu(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    add_submenu_page('fodr-dashboard', __('Batches','fod-receiver'), __('Batches','fod-receiver'),
      $cap, 'fodr-batches', [__CLASS__, 'render']);
  }

  public static function render(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    if (!current_user_can($cap)) wp_die(__('Not allowed','fod-receiver'));
    
    // Show success/error messages
    if (isset($_GET['assigned'])) {
      echo '<div class="notice notice-success"><p>✅ Assigned '.$_GET['assigned'].' parts with '.$_GET['efficiency'].'% efficiency';
      if (isset($_GET['batch_uid'])) echo ' to batch '.$_GET['batch_uid'];
      echo '</p></div>';
    }
    
    echo '<div class="wrap">';
    echo '<h1>Production Batches</h1>';
    
    // Debug info
    echo '<div style="background:#f0f8ff;padding:8px;margin-bottom:10px;border-radius:4px;font-size:12px;">';
    echo '<strong>Debug Info:</strong> AJAX URL: ' . admin_url('admin-ajax.php') . ' | User Can: ' . (current_user_can($cap) ? 'YES' : 'NO');
    echo ' | <button onclick="testAjax()" class="button button-small">Test AJAX</button>';
    echo '</div>';
    
    // Quick stats at top
    self::render_quick_stats();
    
    // Main content in 2 columns
    echo '<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-top:15px;">';
    
    // Left column - Grades to batch
    echo '<div>';
    echo '<h2>Create New Batches</h2>';
    self::render_grades_section();
    echo '</div>';
    
    // Right column - Waste inventory
    echo '<div>';
    echo '<h2>Available Waste</h2>';
    self::render_waste_sidebar();
    echo '</div>';
    
    echo '</div>'; // end grid
    
    // Bottom section - Open batches
    self::render_open_batches();
    
    echo '</div>'; // end wrap
  }

  private static function render_quick_stats(){
    global $wpdb;
    $parts = $wpdb->prefix.'foam_order_parts';
    $waste = $wpdb->prefix.'foam_waste';
    
    $pending_parts = $wpdb->get_var("SELECT COUNT(*) FROM $parts WHERE status='pending' AND (batch_id IS NULL OR batch_id='')");
    $available_waste = $wpdb->get_var("SELECT COUNT(*) FROM $waste WHERE status='available'");
    $waste_volume = $wpdb->get_var("SELECT SUM(width_cm * length_cm * thickness_cm * quantity) FROM $waste WHERE status='available'");
    $waste_volume = $waste_volume ? $waste_volume / 1000 : 0;
    
    echo '<div style="display:flex;gap:15px;margin-bottom:20px;">';
    echo '<div style="background:#e3f2fd;padding:12px;border-radius:6px;text-align:center;min-width:100px;">';
    echo '<div style="font-size:24px;font-weight:bold;color:#1976d2;">'.$pending_parts.'</div>';
    echo '<div style="font-size:12px;color:#666;">Pending Parts</div>';
    echo '</div>';
    echo '<div style="background:#e8f5e8;padding:12px;border-radius:6px;text-align:center;min-width:100px;">';
    echo '<div style="font-size:24px;font-weight:bold;color:#2e7d32;">'.$available_waste.'</div>';
    echo '<div style="font-size:12px;color:#666;">Waste Pieces</div>';
    echo '</div>';
    echo '<div style="background:#fff3e0;padding:12px;border-radius:6px;text-align:center;min-width:120px;">';
    echo '<div style="font-size:24px;font-weight:bold;color:#f57f17;">'.number_format($waste_volume, 0).'L</div>';
    echo '<div style="font-size:12px;color:#666;">Waste Volume</div>';
    echo '</div>';
    echo '</div>';
  }

  private static function render_grades_section(){
    global $wpdb;
    $parts = $wpdb->prefix.'foam_order_parts';

    // Get grades with counts
    $grades = $wpdb->get_results("
      SELECT grade, COUNT(*) as count, 
             COUNT(DISTINCT depth_cm) as depth_variations,
             SUM(qty) as total_qty
      FROM $parts
      WHERE status='pending' AND (batch_id IS NULL OR batch_id='')
      GROUP BY grade
      ORDER BY count DESC
    ", ARRAY_A);

    if (!$grades) {
      echo '<div style="background:#f5f5f5;padding:20px;text-align:center;border-radius:6px;">';
      echo '<p style="color:#666;margin:0;">No pending parts to batch</p>';
      echo '</div>';
      return;
    }

    foreach($grades as $g){ 
      self::render_grade_card($g['grade'], $g['count'], $g['depth_variations'], $g['total_qty']); 
    }
  }

  private static function render_grade_card($grade, $part_count, $depth_variations, $total_qty){
    global $wpdb;
    $parts = $wpdb->prefix.'foam_order_parts';
    $orders = $wpdb->prefix.'foam_orders';

    // FIXED: Get parts with proper customer info - no more duplicates!
    $sql = "
      SELECT DISTINCT p.id, p.order_id, p.depth_cm, p.qty, p.item_index, o.customer_name
      FROM $parts p
      LEFT JOIN $orders o ON p.order_id = o.order_id
      WHERE p.grade = %s AND p.status = 'pending' 
      AND (p.batch_id IS NULL OR p.batch_id = '')
      ORDER BY p.depth_cm DESC, p.created_at DESC
      LIMIT 50
    ";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $grade), ARRAY_A);

    $anchor = 'grade_'.md5($grade);
    
    echo '<div style="background:white;border:1px solid #ddd;border-radius:8px;margin-bottom:15px;overflow:hidden;">';
    
    // Header
    echo '<div style="background:#f8f9fa;padding:12px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">';
    echo '<div>';
    echo '<h3 style="margin:0;color:#333;">'.esc_html($grade).'</h3>';
    echo '<small style="color:#666;">'.$part_count.' parts • '.$depth_variations.' depths • '.$total_qty.' total qty</small>';
    echo '</div>';
    
    // Quick actions
    echo '<div style="display:flex;gap:8px;">';
    echo '<button onclick="toggleGrade(\''.$anchor.'\')" class="button button-small">Toggle Details</button>';
    echo '</div>';
    echo '</div>';

    // Collapsible content
    echo '<div id="'.$anchor.'" style="display:none;">';
    
    // Smart waste suggestions for this grade
    self::render_smart_waste_for_grade($grade, $rows);
    
    // Parts table
    echo '<div style="padding:15px;">';
    
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    wp_nonce_field('fodr_create_batch');
    echo '<input type="hidden" name="action" value="fodr_create_batch">';
    echo '<input type="hidden" name="grade" value="'.esc_attr($grade).'">';

    // Group by depth for easier selection
    $by_depth = [];
    foreach($rows as $r){ 
      $d = (int)$r['depth_cm'];
      if (!isset($by_depth[$d])) $by_depth[$d] = [];
      $by_depth[$d][] = $r;
    }
    krsort($by_depth);

    // Depth selection buttons
    echo '<div style="margin-bottom:10px;display:flex;gap:6px;flex-wrap:wrap;">';
    foreach(array_keys($by_depth) as $d){
      $count = count($by_depth[$d]);
      echo '<button type="button" class="button button-small" onclick="selectDepth(\''.$anchor.'\', '.$d.')">';
      echo $d.'cm ('.$count.')</button>';
    }
    echo '<button type="button" class="button button-small" onclick="selectAll(\''.$anchor.'\')">All</button>';
    echo '<button type="button" class="button button-small" onclick="clearAll(\''.$anchor.'\')">Clear</button>';
    echo '</div>';

    // Simplified parts table - FIXED to prevent duplicates
    echo '<table class="widefat striped" style="font-size:13px;"><thead><tr>';
    echo '<th style="width:30px;"><input type="checkbox" onclick="jQuery(\'.chk-'.esc_attr($anchor).'\').prop(\'checked\', this.checked)"></th>';
    echo '<th style="width:60px;">Order</th><th>Customer</th><th style="width:60px;">Depth</th>';
    echo '<th>Dimensions</th><th style="width:50px;">Qty</th><th style="width:60px;">Volume</th>';
    echo '</tr></thead><tbody>';

    foreach($by_depth as $depth => $parts_group){
      // Depth header row
      echo '<tr style="background:#f0f8ff;"><td colspan="7">';
      echo '<strong>'.$depth.'cm — '.count($parts_group).' parts</strong>';
      echo '</td></tr>';
      
      foreach($parts_group as $r){
        // Get dimensions for this specific part using item_index
        $dimensions = self::get_part_dimensions($r['order_id'], $r['item_index']);
        $volume_l = ($dimensions['width_cm'] * $dimensions['length_cm'] * $depth * $r['qty']) / 1000;
        
        echo '<tr>';
        echo '<td><input class="chk-'.$anchor.' chk-'.$anchor.'_'.$depth.'" type="checkbox" name="part_ids[]" value="'.intval($r['id']).'"></td>';
        echo '<td><a href="'.admin_url('admin.php?page=fodr-orders&view=order&order_id='.$r['order_id']).'">'.intval($r['order_id']).'</a></td>';
        echo '<td>'.esc_html(substr($r['customer_name'] ?: 'Unknown', 0, 20)).'</td>';
        echo '<td style="text-align:center;font-weight:bold;">'.$depth.'</td>';
        echo '<td style="font-family:monospace;font-size:11px;">'.$dimensions['display'].'</td>';
        echo '<td style="text-align:center;">'.intval($r['qty']).'</td>';
        echo '<td style="text-align:right;">'.number_format($volume_l, 1).'L</td>';
        echo '</tr>';
      }
    }
    echo '</tbody></table>';

    echo '<div style="margin-top:12px;display:flex;gap:8px;align-items:center;">';
    echo '<button class="button button-primary">Create Manual Batch</button>';
    echo '<label>Lock Depth: <input name="depth_cm" type="number" min="0" style="width:80px;padding:4px;"></label>';
    echo '</div>';

    echo '</form>';
    echo '</div>'; // padding
    echo '</div>'; // collapsible content
    echo '</div>'; // card
  }

  // FIXED: Get dimensions for specific part using item_index
  private static function get_part_dimensions($order_id, $item_index) {
    global $wpdb;
    $items = $wpdb->prefix.'foam_order_items';
    
    // Get the specific item based on item_index (1-based, convert to 0-based)
    $adjusted_index = max(0, intval($item_index) - 1);
    
    $meta_json = $wpdb->get_var($wpdb->prepare("
      SELECT meta_json 
      FROM $items 
      WHERE order_id = %d 
      ORDER BY id ASC 
      LIMIT 1 OFFSET %d
    ", $order_id, $adjusted_index));
    
    if (!$meta_json) {
      return [
        'width_cm' => 0,
        'length_cm' => 0,
        'display' => 'No dimensions'
      ];
    }
    
    $meta = json_decode($meta_json, true) ?: [];
    
    $unit = $meta['Measure your cushions'] ?? ($meta['_wapf_meta']['fields']['62ea52c0ab295']['value'] ?? 'CM');
    $A = $meta['Side A (Width)'] ?? ($meta['_wapf_meta']['fields']['62ea52c0ab299']['value'] ?? '');
    $B = $meta['Side B (Length)'] ?? ($meta['_wapf_meta']['fields']['62ea52c0ab29c']['value'] ?? '');
    $C = $meta['Side C (Depth)'] ?? ($meta['_wapf_meta']['fields']['62ea52c0ab2a0']['value'] ?? '');
    
    $width_cm = self::dimension_to_cm($A, $unit);
    $length_cm = self::dimension_to_cm($B, $unit);
    
    $display = '';
    if ($A && $B) {
        $display = $A.'×'.$B;
        if ($C) $display .= '×'.$C;
        $display .= ' '.$unit;
    } else {
        $display = 'No dims';
    }
    
    return [
        'width_cm' => max(0, $width_cm),
        'length_cm' => max(0, $length_cm),
        'display' => $display
    ];
  }

  private static function render_smart_waste_for_grade($grade, $parts){
    global $wpdb;
    $waste_table = $wpdb->prefix.'foam_waste';
    
    // Get available waste sorted by efficiency potential
    $available_waste = $wpdb->get_results($wpdb->prepare("
      SELECT *, (width_cm * length_cm * thickness_cm * quantity) as volume_cm3
      FROM $waste_table 
      WHERE grade = %s AND status = 'available'
      ORDER BY volume_cm3 DESC
      LIMIT 5
    ", $grade), ARRAY_A);

    if (!$available_waste) return;

    echo '<div style="background:#fff8e1;margin:15px;padding:12px;border-radius:6px;border-left:4px solid #ffc107;">';
    echo '<h4 style="margin:0 0 8px;color:#f57f17;font-size:14px;">🎯 Smart Waste Matching</h4>';

    foreach ($available_waste as $waste) {
        $matching = self::calculate_smart_waste_matching($waste, $parts);
        $tracking_id = 'W' . str_pad($waste['id'], 4, '0', STR_PAD_LEFT);
        $volume_l = $waste['volume_cm3'] / 1000;
        
        echo '<div style="border:1px solid #e0e0e0;border-radius:6px;margin:8px 0;overflow:hidden;">';
        
        // Waste piece header
        echo '<div style="background:#f8f9fa;padding:8px;display:flex;justify-content:space-between;align-items:center;">';
        echo '<div>';
        echo '<strong style="color:#f57f17;">'.$tracking_id.'</strong> ';
        echo '<span style="font-family:monospace;font-size:12px;">';
        echo $waste['width_cm'].'×'.$waste['length_cm'].'×'.$waste['thickness_cm'].'cm';
        echo '</span>';
        echo '<span style="color:#666;margin-left:8px;">('.number_format($volume_l, 1).'L)</span>';
        echo '</div>';
        echo '<div>';
        if ($matching['efficiency'] >= 85) {
            echo '<span style="background:#e8f5e8;color:#2e7d32;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:bold;">✓ Excellent</span>';
        } elseif ($matching['efficiency'] >= 70) {
            echo '<span style="background:#fff3e0;color:#f57f17;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:bold;">⚡ Good</span>';
        } elseif ($matching['can_fit'] > 0) {
            echo '<span style="background:#e3f2fd;color:#1976d2;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:bold;">~ OK</span>';
        } else {
            echo '<span style="background:#ffebee;color:#c62828;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:bold;">✗ Poor</span>';
        }
        echo '</div>';
        echo '</div>';
        
        if ($matching['can_fit'] > 0) {
            // Show matching details
            echo '<div style="padding:10px;background:white;">';
            
            // Best scenario summary
            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;padding:6px;background:#f0f8ff;border-radius:4px;">';
            echo '<div style="font-size:13px;">';
            echo '<strong>Best Fit:</strong> '.$matching['can_fit'].' parts • ';
            echo '<strong>Efficiency:</strong> '.$matching['efficiency'].'% • ';
            echo '<strong>Waste:</strong> '.$matching['waste_pct'].'%';
            echo '</div>';
            echo '<button type="button" onclick="showWastePreview(\''.$grade.'\', '.$waste['id'].')" class="button button-small" style="font-size:11px;">Preview</button>';
            echo '</div>';
            
            // Parts that will fit (top matches)
            if (!empty($matching['fitting_parts'])) {
                echo '<div style="font-size:12px;margin-bottom:8px;">';
                echo '<strong style="color:#666;">Will fit:</strong> ';
                $part_previews = [];
                foreach (array_slice($matching['fitting_parts'], 0, 4) as $part) {
                    $part_previews[] = $part['depth'].'cm ('.$part['qty'].'x)';
                }
                echo implode(', ', $part_previews);
                if (count($matching['fitting_parts']) > 4) {
                    echo ' +'.( count($matching['fitting_parts']) - 4 ).' more';
                }
                echo '</div>';
            }
            
            // Action buttons
            echo '<div style="display:flex;gap:6px;">';
            echo '<button type="button" onclick="autoAssignWaste(\''.$grade.'\', '.$waste['id'].')" class="button button-primary button-small">Auto Assign ('.$matching['can_fit'].' parts)</button>';
            if ($matching['efficiency'] >= 85) {
                echo '<button type="button" onclick="selectWasteParts(\''.$grade.'\', '.$waste['id'].')" class="button button-small">Select These Parts</button>';
            }
            echo '</div>';
            
            echo '</div>';
        } else {
            // No fit explanation
            echo '<div style="padding:10px;background:#fff;color:#666;font-size:12px;">';
            echo 'No parts fit in this waste piece (max depth: '.$waste['thickness_cm'].'cm)';
            echo '</div>';
        }
        
        echo '</div>'; // end waste card
    }
    echo '</div>';

    // Add JavaScript for preview functionality
    self::add_waste_preview_js();
  }

  // Smart matching algorithm
  private static function calculate_smart_waste_matching($waste, $parts) {
    $waste_volume = $waste['width_cm'] * $waste['length_cm'] * $waste['thickness_cm'];
    $max_depth = $waste['thickness_cm'];
    
    $fitting_parts = [];
    $total_fit_volume = 0;
    
    // Analyze each part
    foreach ($parts as $part) {
        if ($part['depth_cm'] > $max_depth) continue;
        
        $dimensions = self::get_part_dimensions($part['order_id'], $part['item_index']);
        if ($dimensions['width_cm'] <= 0 || $dimensions['length_cm'] <= 0) continue;
        
        // Check if part dimensions fit in waste piece (with rotation)
        $fits_normal = ($dimensions['width_cm'] <= $waste['width_cm'] && 
                       $dimensions['length_cm'] <= $waste['length_cm']);
        $fits_rotated = ($dimensions['length_cm'] <= $waste['width_cm'] && 
                        $dimensions['width_cm'] <= $waste['length_cm']);
        
        if ($fits_normal || $fits_rotated) {
            $part_volume = $dimensions['width_cm'] * $dimensions['length_cm'] * $part['depth_cm'] * $part['qty'];
            
            $fitting_parts[] = [
                'id' => $part['id'],
                'order_id' => $part['order_id'],
                'depth' => $part['depth_cm'],
                'qty' => $part['qty'],
                'volume' => $part_volume,
                'customer' => $part['customer_name'],
                'dimensions' => $dimensions['display']
            ];
            
            $total_fit_volume += $part_volume;
        }
    }
    
    // Sort by volume (largest first for better efficiency)
    usort($fitting_parts, function($a, $b) {
        return $b['volume'] <=> $a['volume'];
    });
    
    // Calculate efficiency - how much of waste volume will be used
    $efficiency = $waste_volume > 0 ? min(100, ($total_fit_volume / $waste_volume) * 100) : 0;
    $waste_pct = max(0, 100 - $efficiency);
    
    return [
        'can_fit' => count($fitting_parts),
        'fitting_parts' => $fitting_parts,
        'efficiency' => round($efficiency, 1),
        'waste_pct' => round($waste_pct, 1),
        'total_volume_used' => $total_fit_volume,
        'waste_volume' => $waste_volume
    ];
  }

  private static function render_waste_sidebar(){
    global $wpdb;
    $waste_table = $wpdb->prefix.'foam_waste';
    
    // Get waste grouped by grade
    $waste_by_grade = $wpdb->get_results("
      SELECT grade, COUNT(*) as count, 
             SUM(width_cm * length_cm * thickness_cm * quantity) as total_volume
      FROM $waste_table 
      WHERE status = 'available'
      GROUP BY grade
      ORDER BY total_volume DESC
    ", ARRAY_A);

    if (!$waste_by_grade) {
      echo '<div style="background:#f5f5f5;padding:20px;text-align:center;border-radius:6px;">';
      echo '<p style="color:#666;margin:0;">No waste available</p>';
      echo '<a href="'.admin_url('admin.php?page=fodr-waste').'" class="button" style="margin-top:8px;">Add Waste</a>';
      echo '</div>';
      return;
    }

    echo '<div style="background:white;border:1px solid #ddd;border-radius:6px;">';
    
    foreach($waste_by_grade as $waste_grade) {
      $volume_l = $waste_grade['total_volume'] / 1000;
      
      echo '<div style="padding:12px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;">';
      
      echo '<div>';
      echo '<div style="font-weight:bold;color:#333;">'.esc_html($waste_grade['grade']).'</div>';
      echo '<div style="font-size:12px;color:#666;">';
      echo $waste_grade['count'].' pieces • '.number_format($volume_l, 1).'L';
      echo '</div>';
      echo '</div>';
      
      echo '<div>';
      echo '<button onclick="showWasteDetails(\''.$waste_grade['grade'].'\')" class="button button-small">View</button>';
      echo '</div>';
      
      echo '</div>';
    }
    
    echo '<div style="padding:12px;text-align:center;">';
    echo '<a href="'.admin_url('admin.php?page=fodr-waste').'" class="button">Manage Waste Inventory</a>';
    echo '</div>';
    
    echo '</div>';
  }

  private static function render_open_batches(){
    $open = FODR_Batches_Common::get_open_batches_with_stats();
    
    echo '<h2 style="margin-top:30px;">Current Open Batches</h2>';
    
    if (!$open){
      echo '<div style="background:#f5f5f5;padding:20px;text-align:center;border-radius:6px;">';
      echo '<p style="color:#666;margin:0;">No open batches</p>';
      echo '</div>';
      return;
    }

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:15px;">';
    
    foreach($open as $r){
      $link = admin_url('admin.php?page=fodr-batch&uid='.$r['batch_uid']);
      $depth = ($r['depth_cm']!==null ? intval($r['depth_cm']).' cm' : 'mixed');
      $progress = intval($r['done']).'/'.intval($r['total']);
      $progress_pct = $r['total'] ? floor($r['done']*100/$r['total']) : 0;
      
      echo '<div style="background:white;border:1px solid #ddd;border-radius:6px;overflow:hidden;">';
      
      echo '<div style="background:#667eea;color:white;padding:10px;">';
      echo '<h4 style="margin:0;font-size:14px;">'.$r['batch_uid'].'</h4>';
      echo '<div style="font-size:11px;opacity:0.9;">'.esc_html($r['grade']).' • '.$depth.'</div>';
      echo '</div>';
      
      echo '<div style="padding:10px;">';
      echo '<div style="margin-bottom:8px;">Progress: <strong>'.$progress.' ('.$progress_pct.'%)</strong></div>';
      echo '<div style="font-size:11px;color:#666;margin-bottom:8px;">Created: '.date_i18n('M j', strtotime($r['created_at'])).'</div>';
      echo '<a href="'.esc_url($link).'" class="button button-small" style="width:100%;">Manage Batch</a>';
      echo '</div>';
      
      echo '</div>';
    }
    
    echo '</div>';
  }

  // Helper functions
  private static function dimension_to_cm($value, $unit) {
    if (!$value) return 0;
    $numeric = (float)preg_replace('/[^0-9.]/', '', (string)$value);
    
    switch (strtoupper($unit)) {
        case 'MM': return $numeric / 10;
        case 'IN': case 'INCH': case 'INCHES': return $numeric * 2.54;
        case 'CM': default: return $numeric;
    }
  }

  // AJAX handler for waste preview with DETAILED DEBUG
  public static function ajax_waste_preview() {
    error_log('=== AJAX waste_preview CALLED ===');
    error_log('GET params: ' . print_r($_GET, true));
    error_log('POST params: ' . print_r($_POST, true));
    error_log('Request method: ' . $_SERVER['REQUEST_METHOD']);
    error_log('Current user ID: ' . get_current_user_id());
    
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) {
        error_log('PERMISSION DENIED - User cannot manage options');
        wp_send_json_error('Permission denied. User ID: ' . get_current_user_id());
        return;
    }
    
    $grade = sanitize_text_field($_GET['grade'] ?? '');
    $waste_id = intval($_GET['waste_id'] ?? 0);
    
    error_log("Processing: grade='$grade', waste_id=$waste_id");
    
    if (!$grade || !$waste_id) {
        error_log('INVALID PARAMETERS');
        wp_send_json_error("Invalid parameters: grade='$grade', waste_id=$waste_id");
        return;
    }
    
    global $wpdb;
    
    // Get waste details
    $waste_query = "SELECT * FROM {$wpdb->prefix}foam_waste WHERE id = %d";
    error_log("Waste query: " . $wpdb->prepare($waste_query, $waste_id));
    
    $waste = $wpdb->get_row($wpdb->prepare($waste_query, $waste_id), ARRAY_A);
    
    if (!$waste) {
        error_log('WASTE NOT FOUND for ID: ' . $waste_id);
        wp_send_json_error('Waste piece not found. ID: ' . $waste_id);
        return;
    }
    
    error_log('Found waste: ' . print_r($waste, true));
    
    // Get parts for this grade - FIXED query to prevent duplicates
    $parts_query = "
      SELECT DISTINCT p.id, p.order_id, p.depth_cm, p.qty, p.item_index, o.customer_name
      FROM {$wpdb->prefix}foam_order_parts p
      LEFT JOIN {$wpdb->prefix}foam_orders o ON p.order_id = o.order_id
      WHERE p.grade = %s AND p.status = 'pending' 
      AND (p.batch_id IS NULL OR p.batch_id = '')
      ORDER BY p.depth_cm DESC
      LIMIT 100
    ";
    
    error_log("Parts query: " . $wpdb->prepare($parts_query, $grade));
    $parts = $wpdb->get_results($wpdb->prepare($parts_query, $grade), ARRAY_A);
    error_log('Found ' . count($parts) . ' parts for grade: ' . $grade);
    
    // Calculate matching
    $matching = self::calculate_smart_waste_matching($waste, $parts);
    error_log('Matching calculation complete: ' . count($matching['fitting_parts']) . ' parts can fit');
    
    // Build response
    $preview_data = [
        'waste' => [
            'tracking_id' => 'W' . str_pad($waste_id, 4, '0', STR_PAD_LEFT),
            'dimensions' => $waste['width_cm'].'×'.$waste['length_cm'].'×'.$waste['thickness_cm'].'cm',
            'volume' => number_format(($waste['width_cm'] * $waste['length_cm'] * $waste['thickness_cm']) / 1000, 1).'L',
            'location' => $waste['location'] ?: 'Not specified'
        ],
        'efficiency' => [
            'percentage' => $matching['efficiency'],
            'waste_pct' => $matching['waste_pct'],
            'parts_fit' => $matching['can_fit']
        ],
        'fitting_parts' => array_slice($matching['fitting_parts'], 0, 20),
        'layers' => self::analyze_layers($matching['fitting_parts'], $waste['thickness_cm']),
        'debug' => [
            'waste_id' => $waste_id,
            'grade' => $grade,
            'total_parts_found' => count($parts),
            'fitting_parts_count' => count($matching['fitting_parts']),
            'query_executed' => true,
            'time' => current_time('mysql')
        ]
    ];
    
    error_log('Sending successful response with data: ' . print_r($preview_data['efficiency'], true));
    wp_send_json_success($preview_data);
  }

  // Layer analysis for nesting preview - FIXED TYPE CASTING AND ARRAY FORMAT
  private static function analyze_layers($fitting_parts, $max_thickness) {
    $layers = [];
    $current_thickness = 0;
    
    // Group by depth
    $by_depth = [];
    foreach ($fitting_parts as $part) {
        $depth = (int)$part['depth'];  // Ensure integer
        if (!isset($by_depth[$depth])) $by_depth[$depth] = [];
        $by_depth[$depth][] = $part;
    }
    
    // Sort by depth (thickest first)
    krsort($by_depth);
    
    foreach ($by_depth as $depth => $depth_parts) {
        $depth_int = (int)$depth;  // Convert string key to integer
        if (($current_thickness + $depth_int) <= $max_thickness) {
            // FIXED: Ensure customers is always an array, not object
            $customers = array_values(array_unique(array_column($depth_parts, 'customer')));
            
            $layers[] = [
                'depth' => $depth_int,
                'parts_count' => count($depth_parts),
                'parts' => array_slice($depth_parts, 0, 5),
                'customers' => $customers  // Now always an array
            ];
            $current_thickness += $depth_int;
        }
    }
    
    return [
        'layers' => $layers,
        'remaining_thickness' => max(0, $max_thickness - $current_thickness),
        'total_layers' => count($layers)
    ];
  }

  // JavaScript for waste preview with ENHANCED DEBUG - FIXED BUTTONS
  private static function add_waste_preview_js() {
    static $js_added = false;
    if ($js_added) return;
    $js_added = true;

    echo '<script>
    // Test function for AJAX
    function testAjax() {
      console.log("Testing AJAX connection...");
      fetch("'.admin_url('admin-ajax.php').'?action=waste_preview_test")
        .then(response => response.json())
        .then(result => {
          console.log("AJAX test result:", result);
          alert("AJAX Test: " + (result.success ? "SUCCESS" : "FAILED") + "\\n" + JSON.stringify(result.data));
        })
        .catch(error => {
          console.error("AJAX test error:", error);
          alert("AJAX Test FAILED: " + error.message);
        });
    }
    
    function showWastePreview(grade, wasteId) {
      console.log("showWastePreview called:", grade, wasteId);
      
      // Create modal with loading state - WITH ID FOR EASY ACCESS
      const modal = document.createElement("div");
      modal.id = "waste-preview-modal";  // ADD ID HERE
      modal.style.cssText = "position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:10000;display:flex;align-items:center;justify-content:center;";
      modal.onclick = function(e) { if (e.target === modal) modal.remove(); };
      
      const content = document.createElement("div");
      content.style.cssText = "background:white;padding:25px;border-radius:8px;max-width:700px;max-height:80vh;overflow-y:auto;box-shadow:0 10px 30px rgba(0,0,0,0.3);";
      
      const wasteTrackingId = "W" + String(wasteId).padStart(4, "0");
      
      content.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;border-bottom:2px solid #0073aa;padding-bottom:10px;">
          <h3 style="margin:0;color:#0073aa;">🎯 Waste Preview: ${wasteTrackingId}</h3>
          <button onclick="document.getElementById(\'waste-preview-modal\').remove()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#666;">&times;</button>
        </div>
        <div id="preview-content" style="text-align:center;padding:40px;">
          <div style="font-size:18px;color:#666;">Loading detailed analysis...</div>
          <div style="margin-top:10px;">⏳</div>
          <div style="font-size:12px;color:#999;margin-top:10px;">Grade: ${grade}<br>Waste ID: ${wasteId}</div>
        </div>
      `;
      
      modal.appendChild(content);
      document.body.appendChild(modal);
      
      // Load actual data via AJAX
      loadWastePreviewData(grade, wasteId);
    }
    
    function loadWastePreviewData(grade, wasteId) {
      const ajaxUrl = "'.admin_url('admin-ajax.php').'";
      const url = ajaxUrl + "?action=waste_preview&grade=" + encodeURIComponent(grade) + "&waste_id=" + wasteId;
      
      console.log("Loading preview from URL:", url);
      console.log("AJAX URL base:", ajaxUrl);
      
      fetch(url, {
        method: "GET",
        credentials: "same-origin",
        headers: {
          "X-Requested-With": "XMLHttpRequest"
        }
      })
      .then(response => {
        console.log("Response status:", response.status);
        console.log("Response headers:", [...response.headers.entries()]);
        console.log("Response OK:", response.ok);
        
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        return response.text();
      })
      .then(text => {
        console.log("Raw response length:", text.length);
        console.log("Raw response (first 500 chars):", text.substring(0, 500));
        
        try {
          const result = JSON.parse(text);
          console.log("Parsed JSON result:", result);
          
          if (result.success) {
            renderWastePreview(result.data, grade, wasteId);
          } else {
            document.getElementById("preview-content").innerHTML = `
              <div style="color:#dc3232;padding:20px;text-align:left;">
                <h4>⌘ Error Loading Preview</h4>
                <p><strong>Error:</strong> ${result.data || "Unknown error"}</p>
                <details>
                  <summary>Debug Information</summary>
                  <pre style="font-size:11px;background:#f5f5f5;padding:10px;overflow:auto;max-height:200px;margin-top:10px;">${text}</pre>
                </details>
              </div>
            `;
          }
        } catch (parseError) {
          console.error("JSON parse error:", parseError);
          document.getElementById("preview-content").innerHTML = `
            <div style="color:#dc3232;padding:20px;text-align:left;">
              <h4>⌘ JSON Parse Error</h4>
              <p><strong>Error:</strong> ${parseError.message}</p>
              <p><strong>URL:</strong> ${url}</p>
              <details>
                <summary>Raw Response (${text.length} chars)</summary>
                <pre style="font-size:11px;background:#f5f5f5;padding:10px;overflow:auto;max-height:200px;margin-top:10px;">${text.replace(/</g, "&lt;").replace(/>/g, "&gt;")}</pre>
              </details>
            </div>
          `;
        }
      })
      .catch(error => {
        console.error("Fetch error:", error);
        document.getElementById("preview-content").innerHTML = `
          <div style="color:#dc3232;padding:20px;text-align:left;">
            <h4>⌘ Network Error</h4>
            <p><strong>Error:</strong> ${error.message}</p>
            <p><strong>URL:</strong> ${url}</p>
            <p><strong>AJAX Base:</strong> ${ajaxUrl}</p>
          </div>
        `;
      });
    }
    
    function renderWastePreview(data, grade, wasteId) {
      console.log("Rendering preview with data:", data);
      
      const efficiency = data.efficiency;
      const waste = data.waste;
      const layers = data.layers;
      const debug = data.debug || {};
      
      let layersHtml = "";
      if (layers.layers && layers.layers.length > 0) {
        layersHtml = layers.layers.map(layer => {
          // FIXED: Handle both array and object format for customers
          let customers = layer.customers;
          if (!Array.isArray(customers)) {
            // Convert object to array if needed
            customers = Object.values(customers || {});
          }
          
          return `<div style="margin:5px 0;padding:8px;background:#f8f9fa;border-radius:4px;">
            <strong>${layer.depth}cm depth:</strong> ${layer.parts_count} parts 
            <span style="color:#666;">(${customers.slice(0,2).join(", ")}${customers.length > 2 ? " +more" : ""})</span>
          </div>`;
        }).join("");
        
        if (layers.remaining_thickness > 0) {
          layersHtml += `<div style="margin:5px 0;padding:8px;background:#fff3e0;border-radius:4px;color:#f57f17;">
            <strong>Remaining:</strong> ${layers.remaining_thickness}cm depth available for more parts
          </div>`;
        }
      } else {
        layersHtml = "<div style=\'color:#666;\'>No suitable layer configuration found</div>";
      }
      
      // FIXED: Using IDs for buttons instead of inline onclick
      document.getElementById("preview-content").innerHTML = `
        <div style="background:#f8f9fa;padding:15px;border-radius:6px;margin-bottom:15px;">
          <h4 style="margin:0 0 10px;color:#333;">📊 Efficiency Analysis</h4>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:10px;">
            <div style="text-align:center;padding:8px;background:#e8f5e8;border-radius:4px;">
              <div style="font-size:18px;font-weight:bold;color:#2e7d32;">${efficiency.percentage}%</div>
              <div style="font-size:12px;color:#666;">Efficiency</div>
            </div>
            <div style="text-align:center;padding:8px;background:#fff3e0;border-radius:4px;">
              <div style="font-size:18px;font-weight:bold;color:#f57f17;">${efficiency.waste_pct}%</div>
              <div style="font-size:12px;color:#666;">Waste</div>
            </div>
            <div style="text-align:center;padding:8px;background:#e3f2fd;border-radius:4px;">
              <div style="font-size:18px;font-weight:bold;color:#1976d2;">${efficiency.parts_fit}</div>
              <div style="font-size:12px;color:#666;">Parts Fit</div>
            </div>
          </div>
        </div>
        
        <div style="background:#f0f8ff;padding:15px;border-radius:6px;margin-bottom:15px;">
          <h4 style="margin:0 0 10px;color:#333;">🔍 Nesting Preview</h4>
          <div style="font-size:13px;line-height:1.5;">
            <strong>Waste Piece:</strong> ${waste.dimensions} (${waste.volume})<br>
            <strong>Location:</strong> ${waste.location}<br><br>
            
            <strong>Layer Strategy:</strong><br>
            ${layersHtml}
            
            <br><strong>Estimated cutting time:</strong> ${Math.round(efficiency.parts_fit * 2)} minutes
          </div>
        </div>
        
        ${debug.query_executed ? `
        <div style="background:#e8f5e8;padding:10px;border-radius:4px;margin-bottom:15px;font-size:12px;">
          <strong>✅ Debug Info:</strong> Found ${debug.total_parts_found} total parts, ${debug.fitting_parts_count} can fit
        </div>
        ` : ""}
        
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;border-top:1px solid #ddd;padding-top:15px;">
          <button id="cancel-waste-preview" class="button">Cancel</button>
          <button id="proceed-waste-preview" class="button button-primary">✓ Proceed with Assignment</button>
        </div>
      `;
      
      // FIXED: Add event listeners with proper scope and debugging
      setTimeout(function() {
        console.log("Adding event listeners to buttons...");
        
        var cancelBtn = document.getElementById("cancel-waste-preview");
        var proceedBtn = document.getElementById("proceed-waste-preview");
        
        console.log("Cancel button found:", cancelBtn ? "YES" : "NO");
        console.log("Proceed button found:", proceedBtn ? "YES" : "NO");
        
        if (cancelBtn) {
          cancelBtn.addEventListener("click", function(e) {
            e.preventDefault();
            console.log("Cancel button clicked");
            var modal = document.getElementById("waste-preview-modal");
            if (modal) {
              console.log("Closing modal...");
              modal.remove();
            } else {
              console.log("Modal not found!");
            }
          });
        }
        
        if (proceedBtn) {
          proceedBtn.addEventListener("click", function(e) {
            e.preventDefault();
            console.log("Proceed button clicked");
            // Close modal first
            var modal = document.getElementById("waste-preview-modal");
            if (modal) {
              modal.remove();
            }
            // Then proceed with assignment
            autoAssignWaste(grade, wasteId);
          });
        }
      }, 100);  // Increased timeout for safety
    }
    
    function proceedWithWasteAssignment(grade, wasteId) {
      // This function is not needed anymore since we handle it directly in button click
      var modal = document.getElementById("waste-preview-modal");
      if (modal) {
        modal.remove();
      }
      autoAssignWaste(grade, wasteId);
    }
    
    function autoAssignWaste(grade, wasteId) {
      console.log("autoAssignWaste called with:", grade, wasteId);
      const wasteTrackingId = "W" + String(wasteId).padStart(4, "0");
      
      if (!confirm(`Create optimized batch from waste ${wasteTrackingId} for ${grade}?\\n\\nThis will automatically select and assign the best-fitting parts.`)) {
        return;
      }
      
      console.log("User confirmed, creating form...");
      
      // Show loading state
      const buttons = document.querySelectorAll("button");
      buttons.forEach(btn => {
        if (btn.textContent.includes("Auto Assign")) {
          btn.textContent = "Assigning...";
          btn.disabled = true;
        }
      });
      
      // Submit form
      const form = document.createElement("form");
      form.method = "POST";
      form.action = "'.admin_url('admin-post.php').'";
      
      const fields = {
        "action": "fodr_assign_waste",
        "grade": grade,
        "waste_id": wasteId,
        "_wpnonce": "'.wp_create_nonce('fodr_assign_waste').'"
      };
      
      console.log("Submitting form with fields:", fields);
      
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
    
    function selectWasteParts(grade, wasteId) {
      const wasteTrackingId = "W" + String(wasteId).padStart(4, "0");
      alert(`Feature: Will auto-select the parts that fit in waste ${wasteTrackingId}`);
    }
    </script>';
  }

  // Main JavaScript for page interactions
  public static function add_javascript() {
    echo '<script>
    function toggleGrade(anchor) {
      const element = document.getElementById(anchor);
      element.style.display = element.style.display === "none" ? "block" : "none";
    }
    
    function selectDepth(anchor, depth) {
      document.querySelectorAll(".chk-" + anchor + "_" + depth).forEach(e => e.checked = true);
    }
    
    function selectAll(anchor) {
      document.querySelectorAll(".chk-" + anchor).forEach(e => e.checked = true);
    }
    
    function clearAll(anchor) {
      document.querySelectorAll(".chk-" + anchor).forEach(e => e.checked = false);
    }
    
    function showWasteDetails(grade) {
      window.location.href = "'.admin_url('admin.php?page=fodr-waste').'&grade=" + encodeURIComponent(grade);
    }
    </script>';
  }

  // Keep existing handler methods
  public static function handle_create_batch(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    check_admin_referer('fodr_create_batch');

    global $wpdb;
    $tbl_batches = $wpdb->prefix.'foam_batches';
    $tbl_parts   = $wpdb->prefix.'foam_order_parts';

    $grade    = sanitize_text_field($_POST['grade'] ?? '');
    $depth_cm = isset($_POST['depth_cm']) && $_POST['depth_cm']!=='' ? intval($_POST['depth_cm']) : null;
    $part_ids = array_map('intval', (array)($_POST['part_ids'] ?? []));

    if (!$grade || !$part_ids){
      wp_redirect(admin_url('admin.php?page=fodr-batches')); exit;
    }

    $uid = FODR_Batches_Common::new_batch_uid();

    $wpdb->insert($tbl_batches, [
      'batch_uid'  => $uid,
      'grade'      => $grade,
      'depth_cm'   => $depth_cm,
      'status'     => 'in_progress',
      'created_at' => current_time('mysql'),
    ]);

    $in = implode(',', $part_ids);
    $wpdb->query("UPDATE $tbl_parts SET status='in_progress', batch_id='".esc_sql($uid)."' WHERE id IN ($in)");

    FODR_Batches_Common::recalc_orders_by_part_ids($part_ids);

    wp_redirect(admin_url('admin.php?page=fodr-batch&uid='.$uid)); exit;
  }

  public static function handle_assign_waste(){
    if (!current_user_can(function_exists('fodr_cap')?fodr_cap():'manage_options')) wp_die('Not allowed');
    
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'fodr_assign_waste')) {
      wp_die('Security check failed');
    }

    $grade = sanitize_text_field($_POST['grade'] ?? '');
    $waste_id = intval($_POST['waste_id'] ?? 0);
    
    if (!$grade || !$waste_id) {
      wp_redirect(admin_url('admin.php?page=fodr-batches&error=invalid'));
      exit;
    }

    // Use smart matching algorithm for assignment
    $result = self::smart_waste_assignment($grade, $waste_id);
    
    $params = [
      'assigned' => $result['assigned'],
      'efficiency' => $result['efficiency']
    ];
    if (isset($result['batch_uid'])) {
      $params['batch_uid'] = $result['batch_uid'];
    }
    
    wp_redirect(add_query_arg($params, admin_url('admin.php?page=fodr-batches')));
    exit;
  }

  // Enhanced smart waste assignment
  private static function smart_waste_assignment($grade, $waste_id) {
    global $wpdb;
    
    // Get waste piece
    $waste = $wpdb->get_row($wpdb->prepare("
      SELECT * FROM {$wpdb->prefix}foam_waste WHERE id = %d
    ", $waste_id), ARRAY_A);
    
    if (!$waste) return ['assigned' => 0, 'efficiency' => 0];
    
    // FIXED: Get pending parts with no duplicates
    $parts_query = "
      SELECT DISTINCT p.id, p.order_id, p.depth_cm, p.qty, p.item_index
      FROM {$wpdb->prefix}foam_order_parts p
      WHERE p.grade = %s AND p.status = 'pending' 
      AND (p.batch_id IS NULL OR p.batch_id = '')
      AND p.depth_cm <= %d
      ORDER BY p.depth_cm DESC, p.created_at ASC
      LIMIT 100
    ";
    
    $parts = $wpdb->get_results($wpdb->prepare($parts_query, $grade, $waste['thickness_cm']), ARRAY_A);
    
    if (!$parts) return ['assigned' => 0, 'efficiency' => 0];
    
    // Use smart matching to get best parts
    $matching = self::calculate_smart_waste_matching($waste, $parts);
    $selected_parts = array_column($matching['fitting_parts'], 'id');
    
    // Create batch if worth it
    $batch_uid = null;
    if (count($selected_parts) >= 2) {
        $batch_uid = FODR_Batches_Common::new_batch_uid();
        
        // Create batch
        $wpdb->insert($wpdb->prefix.'foam_batches', [
            'batch_uid' => $batch_uid,
            'grade' => $grade,
            'status' => 'in_progress',
            'notes' => sprintf(
                'Smart-assigned from W%04d (%dx%dx%dcm, %.1fL). Efficiency: %s%%. %d parts.',
                $waste_id,
                $waste['width_cm'], $waste['length_cm'], $waste['thickness_cm'],
                ($waste['width_cm'] * $waste['length_cm'] * $waste['thickness_cm']) / 1000,
                $matching['efficiency'],
                count($selected_parts)
            ),
            'created_at' => current_time('mysql')
        ]);
        
        // Assign parts
        if (!empty($selected_parts)) {
            $parts_in = implode(',', array_map('intval', $selected_parts));
            $wpdb->query("
                UPDATE {$wpdb->prefix}foam_order_parts 
                SET status = 'in_progress', batch_id = '$batch_uid'
                WHERE id IN ($parts_in)
            ");
        }
        
        // Update waste
        $wpdb->update($wpdb->prefix.'foam_waste', [
            'status' => 'reserved',
            'note' => 'Smart-assigned to batch: ' . $batch_uid . ' (' . count($selected_parts) . ' parts, '.$matching['efficiency'].'% efficiency)',
            'updated_at' => current_time('mysql')
        ], ['id' => $waste_id]);
        
        // Recalc progress
        if (class_exists('FODR_Batches_Common')) {
            FODR_Batches_Common::recalc_orders_by_part_ids($selected_parts);
        }
    }
    
    return [
        'assigned' => count($selected_parts),
        'efficiency' => $matching['efficiency'],
        'batch_uid' => $batch_uid
    ];
  }

  // Placeholder rebuild handler
  public static function handle_rebuild_action(){
    wp_redirect(admin_url('admin.php?page=fodr-batches&msg=rebuild_not_implemented'));
    exit;
  }
}

// Auto-add JavaScript when page loads
add_action('admin_footer', function(){
  if (isset($_GET['page']) && $_GET['page'] === 'fodr-batches') {
    FODR_Batches_List::add_javascript();
  }
});
?>
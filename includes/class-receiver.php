<?php
if (!defined('ABSPATH')) exit;

class FODR_Receiver {
  public static function init(){
    add_action('rest_api_init', [__CLASS__, 'routes']);
  }

  public static function routes(){
    register_rest_route('fod/v1', '/ingest', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'ingest'],
      'permission_callback' => '__return_true',
    ]);
  }

  /** Shared secret (wp-config constant preferred) */
  public static function secret(){
    if (defined('FOD_SHARED_SECRET') && FOD_SHARED_SECRET) return FOD_SHARED_SECRET;
    return get_option('fod_shared_secret', 'change-me');
  }

  /** ---------- UTIL: DB schema ---------- */
  private static function ensure_schema(){
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // Parts table (for batching)
    $tbl_parts = $wpdb->prefix.'foam_order_parts';
    $sql1 = "CREATE TABLE IF NOT EXISTS $tbl_parts (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_row_id BIGINT UNSIGNED NOT NULL,
      order_id BIGINT UNSIGNED NOT NULL,
      item_index INT NULL,
      grade VARCHAR(255) NOT NULL,
      depth_cm INT NULL,
      qty INT NOT NULL DEFAULT 1,
      status ENUM('pending','in_progress','done') NOT NULL DEFAULT 'pending',
      batch_id VARCHAR(64) NULL,
      thumb TEXT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      KEY (order_row_id),
      KEY (order_id),
      KEY (status),
      KEY (grade),
      KEY (depth_cm)
    ) $charset;";

    // Progress columns on parent orders
    $tbl_orders = $wpdb->prefix.'foam_orders';

    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    dbDelta($sql1);

    $col = $wpdb->get_var( $wpdb->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'parts_total'", $tbl_orders) );
    if (!$col) {
      // add columns if missing
      $wpdb->query("ALTER TABLE $tbl_orders ADD COLUMN parts_total INT NULL AFTER job_desc");
      $wpdb->query("ALTER TABLE $tbl_orders ADD COLUMN parts_done INT NULL AFTER parts_total");
      $wpdb->query("ALTER TABLE $tbl_orders ADD COLUMN progress_pct TINYINT NULL AFTER parts_done");
    }
    
    // Add new columns if they don't exist
    $columns = $wpdb->get_col("DESCRIBE {$tbl_orders}");
    if (!in_array('source', $columns)) {
        $wpdb->query("ALTER TABLE {$tbl_orders} ADD COLUMN source VARCHAR(100) NULL");
    }
    if (!in_array('billing_address', $columns)) {
        $wpdb->query("ALTER TABLE {$tbl_orders} ADD COLUMN billing_address TEXT NULL");
    }
    if (!in_array('shipping_address', $columns)) {
        $wpdb->query("ALTER TABLE {$tbl_orders} ADD COLUMN shipping_address TEXT NULL");
    }
    if (!in_array('customer_notes', $columns)) {
        $wpdb->query("ALTER TABLE {$tbl_orders} ADD COLUMN customer_notes TEXT NULL");
    }
  }

  /** ---------- UTIL: parsing ---------- */
  private static function strip_hint($txt){
    $txt = wp_strip_all_tags((string)$txt);
    // remove "(+£xx.xx)" or "(+...)" tail
    return preg_replace('/\s*\(\+[^)]*\)\s*$/u','', $txt);
  }
  private static function to_num($v){
    if (is_numeric($v)) return (float)$v;
    $v = wp_strip_all_tags((string)$v);
    $v = preg_replace('/[^0-9.\-]+/','',$v);
    return $v === '' ? 0.0 : (float)$v;
  }
  private static function cm_ceil($val, $unit){
    $n = self::to_num($val);
    $u = strtoupper(trim((string)$unit));
    if ($u==='CM') return (int) ceil($n);
    if ($u==='MM') return (int) ceil($n/10.0);
    if ($u==='IN' || $u==='INCH' || $u==='INCHES') return (int) ceil($n*2.54);
    return (int) ceil($n);
  }
  private static function detect_unit_from_meta($meta){
    if (!empty($meta['Measure your cushions'])) return $meta['Measure your cushions'];
    if (!empty($meta['_wapf_meta']['fields']['62ea52c0ab295']['value'])) {
      return $meta['_wapf_meta']['fields']['62ea52c0ab295']['value'];
    }
    return 'CM';
  }
  private static function detect_depth_from_meta_cm($meta){
    $unit = self::detect_unit_from_meta($meta);
    $val  = null;
    if (isset($meta['Side C (Depth)'])) {
      $val = $meta['Side C (Depth)'];
    } elseif (!empty($meta['_wapf_meta']['fields']['62ea52c0ab2a0']['value'])) {
      $val = $meta['_wapf_meta']['fields']['62ea52c0ab2a0']['value'];
    }
    return ($val!==null && $val!=='') ? self::cm_ceil($val, $unit) : null;
  }
  private static function grade_from_meta($meta){
    $g = '';
    if (!empty($meta['Standard Foams'])) $g = $meta['Standard Foams'];
    if (!empty($meta['Bonded Foams']))  $g = $meta['Bonded Foams'];
    return self::strip_hint($g);
  }

  /** ---------- MAIN: ingest ---------- */
  public static function ingest(WP_REST_Request $req){
    // Verify headers/signature
    $sig  = $req->get_header('x-fod-signature');
    $time = $req->get_header('x-fod-timestamp');
    $raw  = $req->get_body();
    if (!$sig || !$time || abs(time() - (int)$time) > 300) {
      return new WP_REST_Response(['ok'=>false,'msg'=>'bad headers'], 401);
    }
    $calc = hash_hmac('sha256', $time.'.'.$raw, self::secret());
    if (!hash_equals($calc, $sig)) {
      return new WP_REST_Response(['ok'=>false,'msg'=>'bad signature'], 401);
    }

    $p = json_decode($raw, true);
    if (!is_array($p) || empty($p['order_id'])) {
      return new WP_REST_Response(['ok'=>false,'msg'=>'invalid payload'], 400);
    }

    global $wpdb;
    self::ensure_schema(); // make sure parts table / progress cols exist

    $orders = $wpdb->prefix.'foam_orders';
    $items  = $wpdb->prefix.'foam_order_items';
    $parts  = $wpdb->prefix.'foam_order_parts';

    // Prepare billing and shipping address as JSON
    $billing_address = null;
    $shipping_address = null;
    
    // Check if we have individual billing fields or legacy billing_address
    if (!empty($p['billing_first_name']) || !empty($p['billing_address_1'])) {
        $billing_address = wp_json_encode([
            'first_name' => (string)($p['billing_first_name'] ?? ''),
            'last_name' => (string)($p['billing_last_name'] ?? ''),
            'company' => (string)($p['billing_company'] ?? ''),
            'address_1' => (string)($p['billing_address_1'] ?? ''),
            'address_2' => (string)($p['billing_address_2'] ?? ''),
            'city' => (string)($p['billing_city'] ?? ''),
            'postcode' => (string)($p['billing_postcode'] ?? ''),
            'country' => (string)($p['billing_country'] ?? ''),
            'state' => (string)($p['billing_state'] ?? '')
        ], JSON_UNESCAPED_UNICODE);
    } elseif (!empty($p['billing_address'])) {
        $billing_address = wp_json_encode($p['billing_address'], JSON_UNESCAPED_UNICODE);
    }
    
    if (!empty($p['shipping_first_name']) || !empty($p['shipping_address_1'])) {
        $shipping_address = wp_json_encode([
            'first_name' => (string)($p['shipping_first_name'] ?? ''),
            'last_name' => (string)($p['shipping_last_name'] ?? ''),
            'company' => (string)($p['shipping_company'] ?? ''),
            'address_1' => (string)($p['shipping_address_1'] ?? ''),
            'address_2' => (string)($p['shipping_address_2'] ?? ''),
            'city' => (string)($p['shipping_city'] ?? ''),
            'postcode' => (string)($p['shipping_postcode'] ?? ''),
            'country' => (string)($p['shipping_country'] ?? ''),
            'state' => (string)($p['shipping_state'] ?? '')
        ], JSON_UNESCAPED_UNICODE);
    } elseif (!empty($p['shipping_address'])) {
        $shipping_address = wp_json_encode($p['shipping_address'], JSON_UNESCAPED_UNICODE);
    }

    // ---- UPSERT parent order ----
    $row = [
      'order_id'        => (int)$p['order_id'],
      'order_number'    => (string)($p['order_number'] ?? $p['order_id']),
      'status'          => (string)($p['status'] ?? 'processing'),
      'customer_name'   => (string)($p['customer_name'] ?? ''),
      'email'           => (string)($p['email'] ?? ''),
      'phone'           => (string)($p['phone'] ?? ''),
      'total'           => (float)($p['total'] ?? 0),
      'currency'        => (string)($p['currency'] ?? 'GBP'),
      'shipping_method' => (string)($p['shipping_method'] ?? ''),
      'priority'        => (string)($p['priority'] ?? ''),
      'date_created'    => (string)($p['date_created'] ?? current_time('mysql')),
      'date_modified'   => (string)($p['date_modified'] ?? current_time('mysql')),
      'grades'          => (string)($p['grades'] ?? ''),
      'depths'          => (string)($p['depths'] ?? ''),
      'job_desc'        => (string)($p['job_desc'] ?? ''),
      'source'          => (string)($p['store'] ?? 'Unknown'),
      'billing_address' => $billing_address,
      'shipping_address'=> $shipping_address,
      'customer_notes'  => (string)($p['customer_notes'] ?? ''),
    ];
    $wpdb->replace($orders, $row, ['%d','%s','%s','%s','%s','%s','%f','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']);

    // Retrieve parent order_row_id
    $order_row_id = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $orders WHERE order_id=%d", (int)$p['order_id']
    ));

    // ---- Replace items for this order ----
    if (!empty($p['items']) && is_array($p['items'])){
      $wpdb->delete($items, ['order_id' => (int)$p['order_id']]);

      $idx = 0;
      foreach ($p['items'] as $it){
        $idx++;
        $meta = isset($it['meta']) ? (array)$it['meta'] : [];
        $wpdb->insert($items, [
          'order_id'   => (int)$p['order_id'],
          'product_id' => isset($it['product_id']) ? (int)$it['product_id'] : null,
          'sku'        => isset($it['sku']) ? (string)$it['sku'] : null,
          'name'       => (string)($it['name'] ?? ''),
          'qty'        => (int)($it['qty'] ?? 0),
          'line_total' => (float)($it['line_total'] ?? 0),
          'meta_json'  => wp_json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
      }
    }

    // ---- Build parts from items (split for batching) ----
    // Strategy: delete & rebuild parts for this order_id
    $wpdb->delete($parts, ['order_id' => (int)$p['order_id']]);

    // fetch fresh items we just inserted
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT id, qty, meta_json FROM $items WHERE order_id=%d", (int)$p['order_id']
    ), ARRAY_A);

    $idx = 0; $inserted = 0;
    foreach ($rows as $r){
      $idx++;
      $meta = json_decode($r['meta_json'], true) ?: [];

      // grade text (strip pricing hints)
      $gradeRaw = self::grade_from_meta($meta);
      if ($gradeRaw==='') continue;

      // depth in cm (ceil). Prefer sender-side if provided in item meta as 'depth_cm'
      $depth_cm = null;
      if (isset($meta['depth_cm'])) {
        $depth_cm = (int) $meta['depth_cm'];
      } else {
        $depth_cm = self::detect_depth_from_meta_cm($meta);
      }

      $qty = (int)($r['qty'] ?? 1);
      $thumb = null; // if later you store item images, set here

      $parts_to_create = [];

      // CASE A: "Mixed With" bonded string -> split into two grades
      if (stripos($gradeRaw, 'Mixed With') !== false){
        [$g1, $g2] = array_map('trim', explode('Mixed With', $gradeRaw, 2));
        if ($g1!=='') $parts_to_create[] = ['grade'=>$g1, 'depth_cm'=>$depth_cm, 'qty'=>$qty];
        if ($g2!=='') $parts_to_create[] = ['grade'=>$g2, 'depth_cm'=>$depth_cm, 'qty'=>$qty];
      }
      // CASE B: comma-separated grades
      elseif (strpos($gradeRaw, ',') !== false){
        $grades = array_values(array_filter(array_map('trim', explode(',', $gradeRaw))));
        foreach ($grades as $g){
          $parts_to_create[] = ['grade'=>$g, 'depth_cm'=>$depth_cm, 'qty'=>$qty];
        }
      }
      // CASE C: single grade
      else {
        $parts_to_create[] = ['grade'=>$gradeRaw, 'depth_cm'=>$depth_cm, 'qty'=>$qty];
      }

      // insert parts for this item
      foreach ($parts_to_create as $pt){
        $wpdb->insert($parts, [
          'order_row_id' => $order_row_id,
          'order_id'     => (int)$p['order_id'],
          'item_index'   => $idx,
          'grade'        => (string)$pt['grade'],
          'depth_cm'     => isset($pt['depth_cm']) ? (int)$pt['depth_cm'] : null,
          'qty'          => (int)$pt['qty'],
          'status'       => 'pending',
          'batch_id'     => null,
          'thumb'        => $thumb,
        ]);
        $inserted++;
      }
    }

    // ---- Recalculate order progress (parts) ----
    if ($order_row_id){
      $total = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}foam_order_parts WHERE order_row_id=%d", $order_row_id
      ));
      $done  = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}foam_order_parts WHERE order_row_id=%d AND status='done'", $order_row_id
      ));
      $pct   = $total ? (int) floor(($done / $total) * 100) : 0;

      $wpdb->update($orders, [
        'parts_total' => $total,
        'parts_done'  => $done,
        'progress_pct'=> $pct,
      ], ['id' => $order_row_id]);
    }

    return new WP_REST_Response(['ok'=>true, 'parts_created'=>$inserted], 200);
  }
}
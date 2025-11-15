<?php
if (!defined('ABSPATH')) exit;

class FODR_Batches_Common {

  /** Single place to run/migrate schema */
  public static function activate(){
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $tbl_parts   = $wpdb->prefix.'foam_order_parts';
    $tbl_batches = $wpdb->prefix.'foam_batches';
    $tbl_waste = $wpdb->prefix.'foam_waste';
    $tbl_alloc = $wpdb->prefix.'foam_allocations';

    require_once ABSPATH.'wp-admin/includes/upgrade.php';

    dbDelta("CREATE TABLE IF NOT EXISTS $tbl_parts (
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
      KEY (order_row_id), KEY (order_id), KEY (status), KEY (grade), KEY (depth_cm), KEY (batch_id)
    ) $charset;");

    dbDelta("CREATE TABLE IF NOT EXISTS $tbl_batches (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      batch_uid VARCHAR(64) NOT NULL UNIQUE,
      grade VARCHAR(255) NOT NULL,
      depth_cm INT NULL,
      notes TEXT NULL,
      status ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      completed_at DATETIME NULL,
      KEY (grade), KEY (depth_cm), KEY (status), KEY (created_at)
    ) $charset;");
    
    dbDelta("CREATE TABLE IF NOT EXISTS $tbl_waste (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grade VARCHAR(255) NOT NULL,
  width_cm INT NOT NULL,
  length_cm INT NOT NULL,
  thickness_cm INT NOT NULL,
  status ENUM('available','reserved','consumed') NOT NULL DEFAULT 'available',
  location VARCHAR(120) NULL,
  note TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  KEY (grade), KEY (status), KEY (thickness_cm)
) $charset;");

dbDelta("CREATE TABLE IF NOT EXISTS $tbl_alloc (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  part_id BIGINT UNSIGNED NOT NULL,
  batch_uid VARCHAR(64) NULL,
  source_type ENUM('waste','block','trade') NOT NULL DEFAULT 'waste',
  source_id BIGINT UNSIGNED NOT NULL,
  cut_width_cm INT NOT NULL,
  cut_length_cm INT NOT NULL,
  cut_thickness_cm INT NOT NULL,
  yield_pct TINYINT NULL,
  plan_json LONGTEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY (part_id), KEY (batch_uid), KEY (source_type), KEY (source_id)
) $charset;");

    // parent progress columns on orders
    $orders = $wpdb->prefix.'foam_orders';
    self::maybe_add_column($orders, 'parts_total',  "ALTER TABLE $orders ADD COLUMN parts_total INT NULL AFTER job_desc");
    self::maybe_add_column($orders, 'parts_done',   "ALTER TABLE $orders ADD COLUMN parts_done INT NULL AFTER parts_total");
    self::maybe_add_column($orders, 'progress_pct', "ALTER TABLE $orders ADD COLUMN progress_pct TINYINT NULL AFTER parts_done");

    // in case old batches table existed
    self::maybe_add_column($tbl_batches, 'batch_uid',    "ALTER TABLE $tbl_batches ADD COLUMN batch_uid VARCHAR(64) NOT NULL UNIQUE AFTER id");
    self::maybe_add_column($tbl_batches, 'status',       "ALTER TABLE $tbl_batches ADD COLUMN status ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open' AFTER notes");
    self::maybe_add_column($tbl_batches, 'completed_at', "ALTER TABLE $tbl_batches ADD COLUMN completed_at DATETIME NULL AFTER created_at");
  }

  private static function maybe_add_column($table, $col, $sql){
    global $wpdb;
    $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", $col));
    if (!$exists) { $wpdb->query($sql); }
  }

  /** Generate unique Batch UID */
  public static function new_batch_uid(){
    global $wpdb;
    $tbl = $wpdb->prefix.'foam_batches';
    for ($i=0; $i<6; $i++){
      $uid = 'B-'.date('Ymd').'-'.wp_rand(100,999);
      $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tbl WHERE batch_uid=%s",$uid));
      if (!$exists) return $uid;
    }
    return 'B-'.date('YmdHis').'-'.wp_rand(100,999);
  }

  /** Recalc parent progress for affected orders */
  public static function recalc_orders_by_part_ids(array $part_ids){
    if (!$part_ids) return;
    global $wpdb;
    $parts_tbl  = $wpdb->prefix.'foam_order_parts';
    $orders_tbl = $wpdb->prefix.'foam_orders';
    $in = implode(',', array_map('intval',$part_ids));
    $order_row_ids = $wpdb->get_col("SELECT DISTINCT order_row_id FROM $parts_tbl WHERE id IN ($in)");
    if (!$order_row_ids) return;

    foreach ($order_row_ids as $orow){
      $orow = (int)$orow;
      $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $parts_tbl WHERE order_row_id=%d",$orow));
      $done  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $parts_tbl WHERE order_row_id=%d AND status='done'",$orow));
      $pct   = $total ? (int) floor(($done/$total)*100) : 0;

      $wpdb->update($orders_tbl, [
        'parts_total'=>$total,
        'parts_done' =>$done,
        'progress_pct'=>$pct,
      ], ['id'=>$orow]);

      if ($total>0 && $done===$total){
        $wpdb->update($orders_tbl, ['status'=>'completed'], ['id'=>$orow]);
      }
    }
  }
    /** List open/in_progress batches with stats (total/done) */
  public static function get_open_batches_with_stats(){
    global $wpdb;
    $b = $wpdb->prefix.'foam_batches';
    $p = $wpdb->prefix.'foam_order_parts';

    $sql = "
      SELECT 
        b.batch_uid, b.grade, b.depth_cm, b.status, b.created_at,
        COALESCE(s.total,0)   AS total,
        COALESCE(s.done,0)    AS done
      FROM $b b
      LEFT JOIN (
        SELECT batch_id,
               COUNT(*) AS total,
               SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) AS done
        FROM $p
        WHERE batch_id IS NOT NULL AND batch_id <> ''
        GROUP BY batch_id
      ) s ON s.batch_id = b.batch_uid
      WHERE b.status IN ('open','in_progress')
      ORDER BY b.created_at DESC
      LIMIT 500
    ";
    return $wpdb->get_results($sql, ARRAY_A);
  }

}

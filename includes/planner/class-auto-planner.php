<?php
if (!defined('ABSPATH')) exit;

/**
 * FODR_AutoPlanner
 * - builds rectangles from parts (reads item meta for A/B + unit)
 * - simple 2D greedy packing for a given piece (W×L) per depth layer
 * - multi-depth “layered” planning over one waste/fresh piece
 * - applies a plan (records allocations + updates part statuses)
 *
 * NOTE: packing here is heuristic/greedy (fast + good-enough).
 * You can swap the inner algorithm later without changing the API.
 */
class FODR_AutoPlanner {

  /* ------------------------------
   * Low-level helpers / data
   * ------------------------------ */

  /** foam_order_parts still “pending / in_progress” for this batch+grade */
  public static function get_pending_parts_for_batch(string $batch_uid, string $grade): array {
    global $wpdb; $tbl = $wpdb->prefix.'foam_order_parts';
    return $wpdb->get_results($wpdb->prepare(
      "SELECT id, order_id, item_index, grade, depth_cm, qty
         FROM $tbl
        WHERE batch_id=%s AND grade=%s AND status IN ('pending','in_progress')
        ORDER BY id ASC",
      $batch_uid, $grade
    ), ARRAY_A) ?: [];
  }

  /** Fetch the order item meta_json row corresponding to (order_id, item_index) */
  protected static function get_item_meta_for_part(array $part): ?array {
    global $wpdb;
    $items = $wpdb->prefix.'foam_order_items';
    $rows  = $wpdb->get_results($wpdb->prepare(
      "SELECT id, meta_json FROM $items WHERE order_id=%d ORDER BY id ASC",
      (int)$part['order_id']
    ), ARRAY_A) ?: [];
    $idx = max(1, (int)$part['item_index']) - 1;
    if (!isset($rows[$idx])) return null;
    $meta = json_decode($rows[$idx]['meta_json'] ?? '{}', true);
    return is_array($meta) ? $meta : null;
  }

  /** Convert number in IN/MM/CM → ceil cm */
  protected static function depth_to_cm_ceil($val, $unit): int {
    if ($val === null || $val === '') return 0;
    $n = (float) preg_replace('/[^0-9.\-]+/','', (string)$val);
    $u = strtoupper(trim((string)$unit));
    if ($u === 'CM') return (int) ceil($n);
    if ($u === 'MM') return (int) ceil($n/10.0);
    if ($u === 'IN' || $u==='INCH' || $u==='INCHES') return (int) ceil($n*2.54);
    return (int) ceil($n);
  }

  /** Build rectangles (cm) from a list of part rows. returns [{part_id,w,l,depth}] */
  public static function parts_to_rects(array $parts): array {
    $out = [];
    foreach ($parts as $p){
      $meta = self::get_item_meta_for_part($p) ?: [];
      // prefer top-level keys; fallback _wapf_meta
      $unit  = $meta['Measure your cushions']
        ?? ($meta['_wapf_meta']['fields']['62ea52c0ab295']['value'] ?? 'CM');

      $A = $meta['Side A (Width)']
        ?? ($meta['_wapf_meta']['fields']['62ea52c0ab299']['value'] ?? null);

      $B = $meta['Side B (Length)']
        ?? ($meta['_wapf_meta']['fields']['62ea52c0ab29c']['value'] ?? null);

      $depth = $meta['Side C (Depth)']
        ?? ($meta['_wapf_meta']['fields']['62ea52c0ab2a0']['value'] ?? $p['depth_cm']);

      $w = self::depth_to_cm_ceil($A, $unit);
      $l = self::depth_to_cm_ceil($B, $unit);
      $d = (int) self::depth_to_cm_ceil($depth, $unit);

      // qty was already expanded per-piece upstream (when we index by depth)
      if ($w>0 && $l>0) {
        $out[] = [
          'part_id' => (int)$p['id'],
          'w'       => $w,
          'l'       => $l,
          'depth'   => $d,
        ];
      }
    }
    return $out;
  }

  /** group parts by depth, expanding qty into unit pieces */
  protected static function index_parts_by_depth(array $parts): array {
    $by = [];
    foreach ($parts as $p) {
      $d = (int)$p['depth_cm']; if ($d<=0) continue;
      if (!isset($by[$d])) $by[$d] = [];
      $qty = max(1, (int)$p['qty']);
      for ($i=0; $i<$qty; $i++) $by[$d][] = $p;
    }
    krsort($by); // thick first
    return $by;
  }

  /** Your waste inventory (replace with DB in Phase-2 UI) */
  public static function available_waste_pieces(string $grade): array {
    // Example “big” waste supplied by you:
    return [
      ['w'=>200, 'l'=>200, 'h'=>200, 'label'=>'Waste 200×200×200'],
      // add more records from a real table later…
    ];
  }

  /** Standard fresh block per grade (trade sizes) */
  public static function standard_block(string $grade): ?array {
    $g = strtolower($grade);
    if (strpos($g,'33h blue') !== false)         return ['w'=>200,'l'=>240,'h'=>112,'label'=>'Fresh 200×240×112'];
    if (strpos($g,'premium 39h blue') !== false) return ['w'=>200,'l'=>230,'h'=>106,'label'=>'Fresh 200×230×106'];
    if (strpos($g,'reflex grey') !== false)      return ['w'=>200,'l'=>239,'h'=>106,'label'=>'Fresh 200×239×106'];
    if (strpos($g,'memory') !== false)           return ['w'=>200,'l'=>180,'h'=>93, 'label'=>'Fresh 200×180×93'];
    if (strpos($g,'6lb recon') !== false)        return ['w'=>200,'l'=>150,'h'=>58, 'label'=>'Fresh 200×150×58'];
    return null;
  }

  /* ------------------------------
   * Greedy 2D packing for one sheet
   * ------------------------------ */

  /**
   * Simulate packing on a single sheet (one layer of a piece).
   * $pieceDims = ['w'=>W,'l'=>L] if you want to constrain sheet size; otherwise 200×200 default.
   *
   * Returns: ['placements'=>[{'part_id','x','y','rot'}], 'unplaced'=>[], 'yield_pct', 'pieces_touched', 'time_min']
   */
  public static function simulate_waste_plan(string $grade, int $depth, array $rects, int $sheets=1, ?array $pieceDims=null): array {
    $W = (int)($pieceDims['w'] ?? 200);
    $L = (int)($pieceDims['l'] ?? 200);

    // sort largest-first to improve greedy fit
    usort($rects, function($a,$b){
      return ($b['w']*$b['l']) <=> ($a['w']*$a['l']);
    });

    $placements = [];
    $unplaced   = [];

    // very simple skyline strip: place rows along L
    $x=0; $y=0; $rowH=0;
    $usedArea=0; $sheetArea=$W*$L;

    foreach ($rects as $r){
      $w=$r['w']; $l=$r['l']; $rot=false;

      // rotate if better
      if ($l>$w && $l<=$W && $w<=$L) { $rot=true; [$w,$l]=[$l,$w]; }
      if ($w>$W || $l>$L) { $unplaced[]=$r; continue; }

      // move to next row if doesn't fit horizontally
      if ($x+$w > $W){
        $x=0; $y+=$rowH; $rowH=0;
      }
      // if vertical overflow → cannot place (single sheet)
      if ($y+$l > $L){ $unplaced[]=$r; continue; }

      // place
      $placements[] = ['part_id'=>$r['part_id'],'x'=>$x,'y'=>$y,'rot'=>$rot?1:0,'w'=>$w,'l'=>$l,'depth'=>$depth];
      $usedArea += ($w*$l);
      $rowH = max($rowH, $l);
      $x += $w;
    }

    $yield = $sheetArea>0 ? round(($usedArea/$sheetArea)*100) : 0;
    $time  = round(max(0.5, count($placements)*0.2), 1); // fake timing
    return [
      'placements'     => $placements,
      'unplaced'       => $unplaced,
      'yield_pct'      => $yield,
      'pieces_touched' => 1,
      'time_min'       => $time,
    ];
  }

  /* ------------------------------
   * Multi-depth layered planning (one piece → many depths)
   * ------------------------------ */

  /** candidate stacks of depths whose sum ≤ H (bounded exploration) */
  protected static function layer_candidates(array $depths, int $H, int $limit=10): array {
    $uniq = array_values(array_unique(array_map('intval',$depths)));
    sort($uniq);
    $cand = [];

    // greedy thin->thick
    $h=0; $pick=[];
    foreach ($uniq as $d){ if ($h+$d<=$H){ $pick[]=$d; $h+=$d; } }
    if ($pick) $cand[]=$pick;

    // greedy thick->thin
    $h=0; $pick=[]; $desc=$uniq; rsort($desc);
    foreach ($desc as $d){ if ($h+$d<=$H){ $pick[]=$d; $h+=$d; } }
    if ($pick) $cand[]=$pick;

    // bounded DFS (allow repeats)
    $stack=[[[],0,0]];
    $seen=[];
    while ($stack && count($cand)<$limit){
      [$chosen,$i,$sum]=array_pop($stack);
      if ($sum<=$H && $chosen){
        $key=implode('+',$chosen);
        if (!isset($seen[$key])){ $seen[$key]=1; $cand[]=$chosen; }
      }
      for ($k=$i; $k<count($uniq); $k++){
        $d=$uniq[$k]; if ($sum+$d>$H) continue;
        $next=$chosen; $next[]=$d;
        $stack[] = [$next,$k,$sum+$d];
        if (count($cand)>=$limit) break;
      }
    }

    // normalize + dedup
    $norm=[];
    foreach ($cand as $c){ sort($c); $norm[join('+',$c)]=$c; }
    return array_values($norm);
  }

  /** simulate one W×L×H piece across multiple depth layers */
  public static function simulate_piece_multilayer(string $grade, array $piece, array $all_parts): array {
    $poolByD = self::index_parts_by_depth($all_parts);
    if (!$poolByD) return ['error'=>'No pending parts'];

    $H = (int)$piece['h']; $W=(int)$piece['w']; $L=(int)$piece['l'];
    $depths = array_keys($poolByD);
    $cands = self::layer_candidates($depths, $H, 10);
    if (!$cands) return ['error'=>'No layer combination fits height'];

    $best=null;
    foreach ($cands as $layers){
      $pool = $poolByD;
      $placements=[]; $placedTotal=0; $unTotal=0; $time=0.0; $touch=0; $yieldAcc=0; $layerN=0;

      foreach ($layers as $d){
        if (empty($pool[$d])) continue;
        $rects = self::parts_to_rects($pool[$d]);
        if (!$rects) continue;

        $sim = self::simulate_waste_plan($grade, (int)$d, $rects, 1, ['w'=>$W,'l'=>$L]);
        $pl = is_array($sim['placements'] ?? null) ? count($sim['placements']) : 0;
        $un = is_array($sim['unplaced'] ?? null)   ? count($sim['unplaced'])   : 0;

        // remove placed from pool
        if (!empty($sim['placements'])){
          $used = [];
          foreach ($sim['placements'] as $plr){ $used[(int)$plr['part_id']] = true; }
          $pool[$d] = array_values(array_filter($pool[$d], function($p) use ($used){ return empty($used[(int)$p['id']]); }));
        }

        $placements[] = ['depth'=>$d, 'placements'=>$sim['placements'] ?? []];
        $placedTotal  += $pl;  $unTotal += $un;
        $time         += floatval($sim['time_min'] ?? 0);
        $touch        += intval($sim['pieces_touched'] ?? 0);
        if (isset($sim['yield_pct'])){ $yieldAcc += (int)$sim['yield_pct']; $layerN++; }
      }

      $total = $placedTotal + $unTotal;
      $ratio = $total ? ($placedTotal/$total) : 0;
      $avgY  = $layerN ? ($yieldAcc/$layerN) : 0;
      $score = ($ratio*0.65) + ($avgY/100.0)*0.25 + (min(1, $touch?1/$touch:1))*0.10;

      $pack = [
        'piece'          => $piece,
        'layers'         => $layers,
        'placements'     => $placements,
        'placed'         => $placedTotal,
        'unplaced'       => $unTotal,
        'yield_pct'      => round($avgY),
        'pieces_touched' => $touch,
        'time_min'       => round($time,1),
        'score'          => $score,
      ];
      if (!$best || $pack['score'] > $best['score']) $best = $pack;
    }
    return $best ?: ['error'=>'No feasible packing'];
  }

  /** pick the single best plan across ALL waste pieces + 1 fresh block */
  public static function best_for_batch_multi(string $batch_uid, string $grade): ?array {
    $parts = self::get_pending_parts_for_batch($batch_uid, $grade);
    if (!$parts) return null;

    $cands = [];
    foreach (self::available_waste_pieces($grade) as $w){
      $cands[] = self::simulate_piece_multilayer($grade, $w, $parts);
    }
    $fresh = self::standard_block($grade);
    if ($fresh) $cands[] = self::simulate_piece_multilayer($grade, $fresh, $parts);

    $cands = array_values(array_filter($cands, fn($c)=> is_array($c) && empty($c['error'])));
    if (!$cands) return null;

    usort($cands, fn($a,$b)=> $b['score'] <=> $a['score']);
    $best = $cands[0];
    $best['type'] = ($fresh && $best['piece']==$fresh) ? 'block' : 'waste';
    return $best;
  }

  /* ------------------------------
   * Apply plan → allocations table
   * ------------------------------ */

  /** ensure allocations table exists */
  protected static function ensure_alloc_table(){
    global $wpdb;
    $tbl = $wpdb->prefix.'foam_allocations';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tbl));
    if ($exists === $tbl) return;
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $tbl (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      batch_uid VARCHAR(64) NOT NULL,
      part_id BIGINT UNSIGNED NOT NULL,
      layer_depth INT NULL,
      piece_label VARCHAR(191) NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      KEY batch_uid (batch_uid), KEY part_id (part_id)
    ) $charset;");
  }

  /**
   * Apply a computed plan:
   * - record each placed part_id in foam_allocations
   * - leave parts in 'in_progress' (already batched)
   * - return number of placed items
   */
  public static function apply_plan(string $batch_uid, string $grade, int $depth, array $plan): int {
    if (empty($plan['placements'])) return 0;
    self::ensure_alloc_table();
    global $wpdb;
    $alloc = $wpdb->prefix.'foam_allocations';
    $parts = $wpdb->prefix.'foam_order_parts';

    $pieceLabel = !empty($plan['piece']['label'])
      ? $plan['piece']['label']
      : (($plan['piece']['w']??'').'×'.($plan['piece']['l']??'').'×'.($plan['piece']['h']??''));

    $placedIds = [];
    foreach ($plan['placements'] as $layer) {
      $d = (int)($layer['depth'] ?? 0);
      foreach (($layer['placements'] ?? []) as $p) {
        $pid = (int)($p['part_id'] ?? 0);
        if (!$pid) continue;
        $wpdb->insert($alloc, [
          'batch_uid'  => $batch_uid,
          'part_id'    => $pid,
          'layer_depth'=> $d,
          'piece_label'=> $pieceLabel,
        ]);
        $placedIds[] = $pid;
      }
    }
    // keep status in_progress; but make sure they are marked as being worked on
    if ($placedIds){
      $in = implode(',', array_map('intval',$placedIds));
      $wpdb->query("UPDATE $parts SET status='in_progress' WHERE id IN ($in)");
    }
    return count($placedIds);
  }
}

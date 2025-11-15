<?php
if (!defined('ABSPATH')) exit;

add_action('admin_post_fodr_auto_apply', function(){
  if ( ! current_user_can(function_exists('fodr_cap') ? fodr_cap() : 'manage_options') ) wp_die('Not allowed');
  check_admin_referer('fodr_auto_apply');

  $batch = sanitize_text_field($_POST['batch_uid'] ?? '');
  $grade = sanitize_text_field($_POST['grade'] ?? '');
  $depth = intval($_POST['depth'] ?? 0);
  $which = sanitize_text_field($_POST['which'] ?? 'waste');

  $simW = json_decode(stripslashes($_POST['plan_waste'] ?? ''), true);
  $simB = json_decode(stripslashes($_POST['plan_block'] ?? ''), true);

  $plan = ($which==='block') ? $simB : $simW;
  if (!$plan || empty($plan['placements'])) {
    wp_redirect(add_query_arg(['page'=>'fodr-batches','view'=>'detail','uid'=>$batch,'msg'=>'auto-empty'], admin_url('admin.php'))); exit;
  }

  $n = FODR_AutoPlanner::apply_plan($batch, $grade, $depth, $plan);

  // Optional: recalc progress if you have a helper for it
  if ($n>0 && class_exists('FODR_Batches_Common')){
    global $wpdb; $a_tbl=$wpdb->prefix.'foam_allocations';
    $pids = $wpdb->get_col($wpdb->prepare(
      "SELECT part_id FROM $a_tbl WHERE batch_uid=%s ORDER BY id DESC LIMIT %d",
      $batch, $n
    ));
    if ($pids) FODR_Batches_Common::recalc_orders_by_part_ids($pids);
  }

  wp_redirect(add_query_arg(['page'=>'fodr-batches','view'=>'detail','uid'=>$batch,'msg'=>'auto-applied','n'=>$n], admin_url('admin.php'))); exit;
});

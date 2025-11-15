<?php
if (!defined('ABSPATH')) exit;

class FODR_Dashboard {
  public static function init(){ add_action('admin_menu', [__CLASS__, 'menu']); }
  public static function menu(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    add_menu_page(__('Foam Orders','fod-receiver'), __('Foam Orders','fod-receiver'),
      $cap, 'fodr-dashboard', [__CLASS__, 'render'], 'dashicons-analytics', 56);
    add_submenu_page('fodr-dashboard', __('Dashboard','fod-receiver'), __('Dashboard','fod-receiver'),
      $cap, 'fodr-dashboard', [__CLASS__, 'render']);
  }
  public static function render(){
    $cap = function_exists('fodr_cap') ? fodr_cap() : 'manage_options';
    if ( ! current_user_can($cap) ) { wp_die(__('Sorry, you are not allowed to access this page.')); }
    global $wpdb; $tbl = $wpdb->prefix.'foam_orders';
    $tot = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tbl}");
    $today = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tbl} WHERE DATE(date_created)=DATE(%s)", current_time('mysql')));
    $open = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tbl} WHERE status IN ('pending','processing','on-hold')");
    $done = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tbl} WHERE status='completed'");
    echo '<div class="wrap"><h1>Foam Orders – Dashboard (Receiver)</h1>';
    echo '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;">';
    self::card('Total Orders', $tot);
    self::card('Today', $today);
    self::card('Open', $open);
    self::card('Completed', $done);
    echo '</div>';
    echo '<p style="margin-top:24px">Go to <a href="'.admin_url('admin.php?page=fodr-orders').'">Orders</a> or <a href="'.admin_url('admin.php?page=fodr-settings').'">Settings</a>.</p>';
    echo '</div>';
  }
  private static function card($label, $val){
    echo '<div style="background:#fff;border:1px solid #e5e5e5;padding:16px;border-radius:8px;">
      <div style="font-size:12px;color:#666;">'.esc_html($label).'</div>
      <div style="font-size:28px;font-weight:700;">'.esc_html($val).'</div>
    </div>';
  }
}

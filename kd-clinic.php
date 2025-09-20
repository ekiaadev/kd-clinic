<?php
/**
 * Plugin Name: Khairo Diet Clinic Core
 * Description: Custom Development for Khairo Diet Clinic
 * Plugin URI: https://www.bixrx.ng
 * Author: BizRx Digital Klinik
 * Author URI: https://www.instagram.com/bizrxng
 * Version: 3.2
 */

if (!defined('ABSPATH')) exit;

define('KDC_PATH', plugin_dir_path(__FILE__));
define('KDC_URL',  plugin_dir_url(__FILE__));

require_once KDC_PATH . 'includes/config.php';   // << MUST be first
require_once KDC_PATH . 'includes/helpers.php';  // optional if you have it

$kdc_modules = [
  'cpt-intake.php',
  'booking-prefill.php',
  'checkout-prefill.php',
  'hmo-pricing.php',
  'thankyou-consultation.php',
  'intake-save.php',
  'myaccount.php',
  'community-lose.php',
  'paid-services-policy.php',
  'cart-hygiene.php',
  'security.php',
  'admin-menu.php',
  'admin-intakes.php',
  'admin-metaboxes.php',
  'plan-create.php',
  'capabilities.php',
  'order-linking.php',
  'plan-frontend.php'
];

foreach ($kdc_modules as $mod) {
    $path = KDC_PATH.'includes/'.$mod;
    if (file_exists($path)) require_once $path;
    else error_log('[KDC] Missing module: '.$mod);
}

register_activation_hook(__FILE__, function(){
    $dir = WP_CONTENT_DIR . '/uploads/kd-private/';
    if (!is_dir($dir)) wp_mkdir_p($dir);
    $ht  = $dir . '.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Deny from all\n");
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function(){ flush_rewrite_rules(); });

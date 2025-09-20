<?php
/**
 * Plugin Name: Khairo Diet Clinic Core
 * Description: Custom Development for Khairo Diet Clinic
 * Plugin URI: https://www.bixrx.ng
 * Author: BizRx Digital Klinik
 * Author URI: https://www.instagram.com/bizrxng
 * Version: 8.6
 */

if (!defined('ABSPATH')) exit;

define('KDC_PATH', plugin_dir_path(__FILE__));
define('KDC_URL',  plugin_dir_url(__FILE__));

require_once KDC_PATH . 'includes/config.php';
require_once KDC_PATH . 'includes/helpers.php';

// Explicit module list — avoids loading retired files like hmo-pricing.php or intake-save.php.
$kdc_modules = [
    // Data models
    'includes/cpt-intake.php',
    'includes/cpt-consultation.php',      // NEW

    // Admin UI
    'includes/admin-menu.php',
    'includes/admin-intakes.php',
    'includes/admin-consultation.php',    // NEW
    'includes/admin-metaboxes.php',

    // Front/site flows
    'includes/community-lose.php',
    'includes/cart-hygiene.php',
    'includes/checkout-prefill.php',
    'includes/booking-prefill.php',
    'includes/paid-services-policy.php',
    'includes/myaccount.php',

    // Pricing via coupons (accounting-safe)
    'includes/pricing-coupons.php',

    // Order linking + create Intake post-payment (deferred)
    'includes/order-linking.php',         // legacy binding (safe if session set)
    'includes/intake-postpay.php',        // NEW — authoritative intake creator

    // Security / private files
    'includes/private-files.php',
    'includes/security.php',

    // Entry deferral/purge
    'includes/defer-entries.php',
];

foreach ($kdc_modules as $file) {
    $path = KDC_PATH . $file;
    if (file_exists($path)) require_once $path;
}

/** Activation: ensure coupons exist & schedule cleanup */
function kdcl_activate() {
    if (!function_exists('kdcl_ensure_service_coupons')) {
        require_once KDC_PATH . 'includes/pricing-coupons.php';
    }
    kdcl_ensure_service_coupons();

    if (!wp_next_scheduled('kdcl_purge_stale_ff_entries')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'kdcl_purge_stale_ff_entries');
    }
}
register_activation_hook(__FILE__, 'kdcl_activate');

/** Deactivation: clear scheduled purge */
function kdcl_deactivate() {
    wp_clear_scheduled_hook('kdcl_purge_stale_ff_entries');
}
register_deactivation_hook(__FILE__, 'kdcl_deactivate');

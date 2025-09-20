<?php if (!defined('ABSPATH')) exit;

/* Ensure kd-private/.htaccess exists (Apache). Add Nginx rule server-side as needed. */
add_action('admin_init', function(){
    $dir = WP_CONTENT_DIR . '/uploads/kd-private/';
    if (!is_dir($dir)) return;
    $ht = $dir . '.htaccess';
    if (!file_exists($ht)) { @file_put_contents($ht, "Deny from all\n"); }
});

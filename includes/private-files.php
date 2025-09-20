<?php if (!defined('ABSPATH')) exit;

/**
 * Proxy to view private intake files from kd-private securely.
 * Usage: admin-ajax.php?action=kdcl_view_private&intake_id=123&idx=0&_wpnonce=XXXX&inline=1
 */
add_action('wp_ajax_kdcl_view_private', function () {
    $intake_id = isset($_GET['intake_id']) ? (int) $_GET['intake_id'] : 0;
    $idx       = isset($_GET['idx']) ? (int) $_GET['idx'] : -1;
    if (!$intake_id || $idx < 0) wp_die('Bad request');

    if (!current_user_can('read_post', $intake_id)) wp_die('Forbidden');

    check_admin_referer('kdcl_view_file_'.$intake_id);

    $files = (array) get_post_meta($intake_id, '_kd_intake_files', true);
    if (!isset($files[$idx])) wp_die('File not found');

    $path = $files[$idx];
    if (!file_exists($path)) wp_die('Missing file');

    $inline = !empty($_GET['inline']);
    $mime   = wp_check_filetype(basename($path))['type'] ?: 'application/octet-stream';

    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0');
    header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="'.basename($path).'"');

    readfile($path);
    exit;
});

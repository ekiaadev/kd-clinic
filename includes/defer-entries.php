<?php
if (!defined('ABSPATH')) exit;

/**
 * Defer/Intake glue with LOCAL helper names only (no collisions).
 * - Creates/updates Intake immediately on FF submit
 * - Stores name/email/phone + client_type in WC session (prefill & coupons)
 * - Links Intake/FF to order; marks Intake paid on payment
 *
 * All helpers here use kdcl__* (double underscore) and will not clash
 * with helpers defined in other files (e.g., admin-intakes.php).
 */

/* ---------- LOCAL HELPERS (PRIVATE NAMES) ---------- */

function kdcl__ff_details_payload($entry_id){
    global $wpdb;
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT field_name, field_value FROM {$wpdb->prefix}fluentform_entry_details WHERE submission_id = %d",
            (int)$entry_id
        ),
        ARRAY_A
    );
    $out = [];
    foreach ((array)$rows as $r) {
        $k = isset($r['field_name']) ? sanitize_key($r['field_name']) : '';
        if ($k === '') continue;
        $out[$k] = isset($r['field_value']) ? maybe_unserialize($r['field_value']) : '';
    }
    return $out;
}

function kdcl__ff_raw_payload($formData){
    $src = (isset($formData['fields']) && is_array($formData['fields'])) ? $formData['fields'] : (array)$formData;
    $out = [];
    foreach ($src as $k => $v) {
        $key = sanitize_key($k); if ($key === '') continue;
        $out[$key] = is_array($v) ? array_map('sanitize_text_field', $v) : sanitize_text_field((string)$v);
    }
    return $out;
}

function kdcl__best_full_name(array $p){
    $first=''; $last=''; $single='';
    foreach (['first_name','firstname','first','first-name','first name'] as $k) {
        if (!empty($p[$k])) { $first = is_array($p[$k]) ? implode(' ', $p[$k]) : (string)$p[$k]; break; }
    }
    foreach (['last_name','lastname','last','surname','last-name','last name'] as $k) {
        if (!empty($p[$k])) { $last  = is_array($p[$k]) ? implode(' ', $p[$k]) : (string)$p[$k]; break; }
    }
    foreach (['names','full_name','fullname','name'] as $k) {
        if (!empty($p[$k])) { $single = is_array($p[$k]) ? implode(' ', $p[$k]) : (string)$p[$k]; break; }
    }
    $first = trim($first); $last = trim($last); $single = trim($single);
    if ($first || $last) return trim($first.' '.$last);
    return $single ?: '';
}

function kdcl__set_cookie($name,$value,$ttl=3600){
    if (headers_sent()) return;
    setcookie($name,(string)$value,time()+(int)$ttl, COOKIEPATH?:'/', COOKIE_DOMAIN, is_ssl(), true);
}

/* ---------- INTAKE CREATION (IDEMPOTENT) ---------- */

function kdcl__create_or_update_intake($entry_id,$formData=[]){
    $existing = get_posts([
        'post_type'      => 'kh_intake',
        'post_status'    => ['private','publish','draft'],
        'meta_key'       => '_kd_ff_entry_id',
        'meta_value'     => (int)$entry_id,
        'fields'         => 'ids',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
    ]);
    $intake_id = !empty($existing[0]) ? (int)$existing[0] : 0;

    $payload = kdcl__ff_details_payload($entry_id);
    if (empty($payload) && !empty($formData)) $payload = kdcl__ff_raw_payload($formData);

    $name = kdcl__best_full_name($payload);
    if ($name === '') $name = 'Unknown';
    $title = sprintf('%s (Intake – %d)', wp_strip_all_tags($name), (int)$entry_id);

    if ($intake_id) {
        update_post_meta($intake_id, '_kd_intake_payload', $payload);
        update_post_meta($intake_id, '_kd_contact_name',   $name);
        if (!empty($payload['email'])) update_post_meta($intake_id, '_kd_contact_email', sanitize_email($payload['email']));
        if (!get_post_meta($intake_id, '_kd_payment_status', true)) update_post_meta($intake_id, '_kd_payment_status', 'awaiting');
        return (int)$intake_id;
    }

    $intake_id = wp_insert_post([
        'post_title'  => $title,
        'post_type'   => 'kh_intake',
        'post_status' => 'private',
        'post_author' => get_current_user_id(),
    ], true);
    if (is_wp_error($intake_id) || !$intake_id) return 0;

    update_post_meta($intake_id, '_kd_ff_entry_id',    (int)$entry_id);
    update_post_meta($intake_id, '_kd_intake_payload', $payload);
    update_post_meta($intake_id, '_kd_contact_name',   $name);
    if (!empty($payload['email'])) update_post_meta($intake_id, '_kd_contact_email', sanitize_email($payload['email']));
    update_post_meta($intake_id, '_kd_payment_status', 'awaiting');

    return (int)$intake_id;
}

/* ---------- FF SUBMIT: CREATE INTAKE + STASH SESSION ---------- */
add_action('fluentform/submission_inserted', function($entryId,$formData,$form){
    // We purposely do not depend on a specific form ID here.
    $intake_id = kdcl__create_or_update_intake((int)$entryId, $formData);

    // Capture contact + client type for prefill & coupons
    $raw   = kdcl__ff_raw_payload($formData);
    $name  = kdcl__best_full_name($raw);
    $email = isset($raw['email']) ? (string)$raw['email'] : '';
    $phone = isset($raw['phone']) ? (string)$raw['phone'] : '';
    $ctype = '';
    foreach (['client_type','clienttype','payment_option'] as $k) {
        if (!empty($raw[$k])) { $ctype = (string)$raw[$k]; break; }
    }

    if (function_exists('WC') && WC()->session) {
        WC()->session->set('kd_ff_entry_id',   (int)$entryId);
        WC()->session->set('kd_intake_id',     (int)$intake_id);
        WC()->session->set('kd_contact_name',  $name);
        WC()->session->set('kd_contact_email', $email);
        WC()->session->set('kd_contact_phone', $phone);
        if ($ctype !== '') WC()->session->set('kd_client_type', $ctype);
        WC()->session->set('kd_service_flow',  1);
    }
    kdcl__set_cookie('kd_ff_entry_id', (int)$entryId, 3600);
    kdcl__set_cookie('kd_intake_id',   (int)$intake_id, 3600);
}, 9, 3);

/* ---------- ORDER CREATION: LINK INTAKE / ENTRY ---------- */
add_action('woocommerce_checkout_create_order', function ($order, $data) {
    if (!function_exists('WC')) return;
    $intake_id = 0; $entry_id = 0;

    if (WC()->session) {
        $intake_id = (int) WC()->session->get('kd_intake_id');
        $entry_id  = (int) WC()->session->get('kd_ff_entry_id');
    }
    if (!$intake_id && isset($_COOKIE['kd_intake_id']))  $intake_id = (int) $_COOKIE['kd_intake_id'];
    if (!$entry_id  && isset($_COOKIE['kd_ff_entry_id'])) $entry_id  = (int) $_COOKIE['kd_ff_entry_id'];

    if ($intake_id > 0) $order->update_meta_data('_kd_intake_id', (int)$intake_id);
    if ($entry_id  > 0) $order->update_meta_data('_kd_pending_entry_id', (int)$entry_id);
}, 20, 2);

/* ---------- PAYMENT: MARK INTAKE PAID + PUBLISH FF ENTRY ---------- */
function kdcl__on_payment_mark_paid($order_id){
    $order = wc_get_order($order_id);
    if (!$order) return;

    $intake_id = (int) $order->get_meta('_kd_intake_id');
    $entry_id  = (int) $order->get_meta('_kd_pending_entry_id');

    if ($intake_id > 0) {
        update_post_meta($intake_id, '_kd_order_id', (int)$order_id);
        update_post_meta($intake_id, '_kd_payment_status', 'paid');
    }

    if ($entry_id > 0) {
        global $wpdb;
        $subs = $wpdb->prefix.'fluentform_submissions';
        $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$subs} WHERE id = %d", (int)$entry_id));
        if ($status && $status !== 'published') {
            $wpdb->update($subs, ['status' => 'published'], ['id' => (int)$entry_id], ['%s'], ['%d']);
        }
        $meta = $wpdb->prefix.'fluentform_submission_meta';
        $wpdb->insert($meta, [
            'submission_id' => (int)$entry_id,
            'meta_key'      => '_kd_order_id',
            'meta_value'    => (string)$order_id
        ], ['%d','%s','%s']);
    }

    if (function_exists('WC') && WC()->session) {
        WC()->session->__unset('kd_intake_id');
        WC()->session->__unset('kd_ff_entry_id');
        WC()->session->__unset('kd_service_flow');
    }
}
add_action('woocommerce_payment_complete',        'kdcl__on_payment_mark_paid', 15);
add_action('woocommerce_order_status_processing', 'kdcl__on_payment_mark_paid', 15);

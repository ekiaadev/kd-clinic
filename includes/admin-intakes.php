<?php
// kd-clinic/includes/admin-intakes.php

if (!defined('ABSPATH')) exit;

/**
 * Helper: Try to get the intake owner and payload from common meta keys.
 * Owner fallbacks: kh_client_user_id → kd_client_user_id → user_id → post_author
 * Payload fallbacks: kh_intake_payload → kd_intake_payload → ff_submission → ff5_submission → post_content (JSON)
 */
function kdcl_get_intake_context($intake_id) {
    $post = get_post($intake_id);
    if (!$post || $post->post_type !== 'kh_intake') {
        return new WP_Error('kdcl_not_intake', __('Invalid intake post.', 'kd-clinic'));
    }

    $uid = (int) get_post_meta($intake_id, 'kh_client_user_id', true);
    if (!$uid) $uid = (int) get_post_meta($intake_id, 'kd_client_user_id', true);
    if (!$uid) $uid = (int) get_post_meta($intake_id, 'user_id', true);
    if (!$uid) $uid = (int) $post->post_author;

    $payload_meta_keys = ['_kd_intake_payload','kh_intake_payload','kd_intake_payload','ff_submission','ff5_submission'];
    $payload = [];
    foreach ($payload_meta_keys as $mk) {
        $raw = get_post_meta($intake_id, $mk, true);
        if ($raw) {
            if (is_array($raw)) { $payload = $raw; break; }
            $j = json_decode(is_string($raw) ? $raw : '', true);
            if (is_array($j)) { $payload = $j; break; }
        }
    }
    if (!$payload && !empty($post->post_content)) {
        $j = json_decode($post->post_content, true);
        if (is_array($j)) $payload = $j;
    }

    return [
        'post'           => $post,
        'client_user_id' => $uid ?: 0,
        'payload'        => $payload,
    ];
}

// One-time, lazy title fixer for existing intakes (max 25 per admin page load)
add_action('admin_init', function () {
    if (!is_admin()) return;
    if (!current_user_can('edit_others_posts')) return;

    $q = new WP_Query([
        'post_type'      => 'kh_intake',
        'post_status'    => ['private','publish'],
        'posts_per_page' => 25,
        'meta_query'     => [
            'relation' => 'OR',
            [ 'key' => '_kd_title_fixed', 'compare' => 'NOT EXISTS' ],
            [ 'key' => '_kd_title_fixed', 'value' => '0' ],
        ],
        'no_found_rows'  => true,
        'fields'         => 'ids',
    ]);

    foreach ($q->posts as $pid) {
        $ctx = kdcl_get_intake_context($pid);
        if (is_wp_error($ctx)) { add_post_meta($pid, '_kd_title_fixed', 1, true); continue; }

        $entry_id = (int) get_post_meta($pid, '_kd_ff_entry_id', true);
        if (!$entry_id) $entry_id = $pid;

        // try payload name
        $name = '';
        $p = is_array($ctx['payload']) ? $ctx['payload'] : [];
        foreach (['names','full_name','fullname','name'] as $k) { if (!empty($p[$k])) { $name = trim((string)$p[$k]); break; } }
        if (!$name) {
            $first=''; $last='';
            foreach (['first_name','firstname'] as $k){ if (!empty($p[$k])) { $first = trim((string)$p[$k]); break; } }
            foreach (['last_name','lastname'] as $k){ if (!empty($p[$k]))  { $last  = trim((string)$p[$k]); break; } }
            $name = trim($first.' '.$last);
        }
        if (!$name) {
            $u = get_userdata((int)$ctx['client_user_id']);
            $name = $u ? ($u->display_name ?: $u->user_login) : 'Intake';
        }
        $name = ucwords(strtolower($name));
        $new_title = sprintf('%s (Intake – %d)', $name, $entry_id);

        wp_update_post(['ID' => $pid, 'post_title' => $new_title]);
        update_post_meta($pid, '_kd_title_fixed', 1);
    }
});

/**
 * Get client's full name from intake payload first, then user profile.
 * Tries common keys and normalizes case.
 */
function kdcl_get_client_full_name($intake_id){
    $ctx = kdcl_get_intake_context($intake_id);
    if (is_wp_error($ctx)) return '';

    $p = is_array($ctx['payload']) ? $ctx['payload'] : [];

    // Common keys you may have in FluentForms
    $candidates = [
        'names', // Fluent Forms “Names” field
        'full_name', 'fullname', 'name',
        'contact_name', 'contact_fullname',
    ];
    foreach ($candidates as $k){
        if (!empty($p[$k]) && is_string($p[$k])) {
            $n = trim($p[$k]);
            if ($n !== '') return ucwords(strtolower($n));
        }
    }

    // First/last combos
    $first_keys = ['first_name','firstname','contact_first','first','given_name'];
    $last_keys  = ['last_name','lastname','contact_last','last','surname','family_name'];

    $first = ''; $last = '';
    foreach ($first_keys as $k){ if (!empty($p[$k])) { $first = trim((string)$p[$k]); break; } }
    foreach ($last_keys  as $k){ if (!empty($p[$k]))  { $last  = trim((string)$p[$k]); break; } }

    if ($first || $last){
        $name = trim($first.' '.$last);
        if ($name !== '') return ucwords(strtolower($name));
    }

    // Fallback to WP user display_name
    $uid = (int)$ctx['client_user_id'];
    if ($uid){
        $u = get_userdata($uid);
        if ($u && $u->display_name){
            return ucwords(strtolower($u->display_name));
        }
    }
    return '';
}

/**
 * Resolve & bind the Woo order for an intake with strict rules:
 * Priority: payload[order_id] → referrer ?order_id → ±40min search.
 * Validates: same client, contains KD_CONSULT_PRODUCT_ID, created within ±40min,
 * and not already bound to another intake via _kd_bound_intake.
 * On success: saves _kd_order_id on intake and _kd_bound_intake on the order.
 */
if (!function_exists('kdcl_resolve_order_for_intake')) {
    function kdcl_resolve_order_for_intake($intake_id){
        $saved = (int) get_post_meta($intake_id, '_kd_order_id', true);
        if ($saved) return $saved;
        if (!function_exists('wc_get_orders')) return 0;
    
        $post = get_post($intake_id);
        if (!$post) return 0;
    
        // Context
        $ctx = kdcl_get_intake_context($intake_id);
        if (is_wp_error($ctx)) return 0;
        $uid = (int) $ctx['client_user_id'];
        if (!$uid) return 0;
    
        // Time window ±40 minutes from intake post_date_gmt (fallback to local date)
        $ts = strtotime($post->post_date_gmt ?: $post->post_date ?: 'now');
        $window = 40 * MINUTE_IN_SECONDS;
        $from = date('Y-m-d H:i:s', $ts - $window);
        $to   = date('Y-m-d H:i:s', $ts + $window);
    
        // Required product
        if (!defined('KD_CONSULT_PRODUCT_ID') || !KD_CONSULT_PRODUCT_ID) return 0;
        $consult_pid = (int) KD_CONSULT_PRODUCT_ID;
    
        // Helper: check an order meets all rules and bind it
        $try_bind = function($order) use ($intake_id, $uid, $ts, $window, $consult_pid){
            if (!$order) return 0;
            $oid = (int) $order->get_id();
    
            // Not already bound to another intake
            $bound = (int) $order->get_meta('_kd_bound_intake');
            if ($bound && $bound !== (int)$intake_id) return 0;
    
            // Same client
            if ((int) $order->get_customer_id() !== $uid) return 0;
    
            // Created within ±40 minutes
            $ots = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
            if (!$ots || abs($ots - $ts) > $window) return 0;
    
            // Contains the consultation product (normalize variations to parent)
            $has_product = false;
            foreach ($order->get_items() as $item){
                $pid = (int) $item->get_product_id();
                $parent = $pid;
                if ($item->get_variation_id()){
                    $v = wc_get_product($item->get_variation_id());
                    if ($v && $v->get_parent_id()) $parent = (int) $v->get_parent_id();
                }
                if ($parent === $consult_pid){ $has_product = true; break; }
            }
            if (!$has_product) return 0;
    
            // Bind both ways
            update_post_meta($intake_id, '_kd_order_id', $oid);
            $order->update_meta_data('_kd_bound_intake', (int)$intake_id);
            $order->save();
    
            return $oid;
        };
    
        // 1) From payload hidden field `order_id`
        $payload = is_array($ctx['payload']) ? $ctx['payload'] : [];
        if (!empty($payload['order_id']) && preg_match('/^\d+$/', (string)$payload['order_id'])) {
            $o = wc_get_order((int)$payload['order_id']);
            if ($oid = $try_bind($o)) return $oid;
        }
    
        // 2) From HTTP referrer query (?order_id=XXXX)
        $ref = get_post_meta($intake_id, '_wp_http_referer', true);
        if (!$ref) $ref = get_post_meta($intake_id, 'wp_http_referer', true);
        if ($ref) {
            $q = [];
            $query = parse_url($ref, PHP_URL_QUERY);
            if ($query) parse_str($query, $q);
            if (!empty($q['order_id']) && is_numeric($q['order_id'])) {
                $o = wc_get_order((int)$q['order_id']);
                if ($oid = $try_bind($o)) return $oid;
            }
        }
    
        // 3) Tight search within ±40 minutes for this client, any status
        $orders = wc_get_orders([
            'type'         => 'shop_order',
            'customer_id'  => $uid,
            'date_created' => $from.'...'.$to,
            'limit'        => 15,
            'orderby'      => 'date',
            'order'        => 'DESC',
            'status'       => array_keys(wc_get_order_statuses()),
            'return'       => 'objects',
        ]);
    
        foreach ($orders as $order){
            if ($oid = $try_bind($order)) return $oid;
        }
    
        return 0;
    }
}

/**
 * Try to find the Fluent Forms submission/entry ID for an intake.
 * Falls back to post ID if not found.
 */
function kdcl_get_ff_submission_id($intake_id){
    // Common meta keys we may have saved
    $meta_keys = [
        '_kd_ff_entry_id','ff_submission_id','ff_entry_id',
        'fluentform_entry_id','_fluentform_entry_id','_entry_id'
    ];
    foreach ($meta_keys as $mk){
        $v = get_post_meta($intake_id, $mk, true);
        if ($v) return (int)$v;
    }
    // Try inside payload
    $ctx = kdcl_get_intake_context($intake_id);
    if (!is_wp_error($ctx) && !empty($ctx['payload'])) {
        $p = $ctx['payload'];
        foreach (['entry_id','submission_id','id'] as $k){
            if (!empty($p[$k]) && is_numeric($p[$k])) return (int)$p[$k];
        }
    }
    return (int)$intake_id;
}
// Format the Title column: "Full Name (Intake – {FF submission ID})"
add_filter('the_title', function($title, $post_id){
    if (!is_admin()) return $title;
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'kh_intake') return $title;

    $name     = kdcl_get_client_full_name($post_id);
    if ($name === '') $name = __('Intake', 'kd-clinic');

    $entry_id = kdcl_get_ff_submission_id($post_id);

    return sprintf('%s (Intake – %s)', $name, $entry_id);
}, 10, 2);

/** Columns (admin list header) */
add_filter('manage_edit-kh_intake_columns', function ($cols) {
    // Admin view: Title | Date | Order | Status | My Nutrition Plan
    $new = [];
    $new['cb']     = $cols['cb'] ?? '<input type="checkbox" />';
    $new['title']  = __('Title', 'kd-clinic');
    $new['date']   = $cols['date'] ?? __('Date', 'kd-clinic');
    $new['order']  = __('Order', 'kd-clinic');
    $new['status'] = __('Status', 'kd-clinic');
    $new['plan']   = __('My Nutrition Plan', 'kd-clinic');
    return $new;
}, 99);

/** Columns */
add_action('manage_kh_intake_posts_custom_column', function ($col, $post_id) {
    if ($col === 'client') {
        $ctx = kdcl_get_intake_context($post_id);
        if (is_wp_error($ctx)) { echo '—'; return; }
        $uid = (int) $ctx['client_user_id'];
        if ($uid) {
            $u = get_userdata($uid);
            echo $u ? esc_html($u->display_name . ' (' . $u->user_email . ')') : '—';
        } else {
            echo '—';
        }
        return;
    }

    if ($col === 'order') {
        $order_id = (int) get_post_meta($post_id, '_kd_order_id', true);
        if (!$order_id) {
            $order_id = kdcl_resolve_order_for_intake($post_id);
        }
        if ($order_id) {
            $link = admin_url('post.php?post='.$order_id.'&action=edit');
            echo '<a href="'.esc_url($link).'">#'.intval($order_id).'</a>';
        } else {
            echo '—';
        }
        return;
    }


    if ($col === 'status') {
        // "Pending" until a kh_plan linked to this intake is published → then "Completed"
        $status = 'Pending';

        $plan_q = new WP_Query([
            'post_type'      => 'kh_plan',
            'posts_per_page' => 1,
            'post_status'    => ['publish'], // completed only when published
            'meta_query'     => [[ 'key' => 'kdcl_source_intake', 'value' => (int)$post_id ]],
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        if (!empty($plan_q->posts)) {
            $status = 'Completed';
        }
        echo esc_html($status);
        return;
    }

    if ($col === 'plan') {
        // If a plan exists for this intake, show Edit/View/PDF; else "Investigating…"
        $plan_q = new WP_Query([
            'post_type'      => 'kh_plan',
            'posts_per_page' => 1,
            'post_status'    => ['draft','pending','publish','private'],
            'meta_query'     => [[ 'key' => 'kdcl_source_intake', 'value' => (int)$post_id ]],
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        if (!empty($plan_q->posts)) {
            $plan_id = (int) $plan_q->posts[0];
            $edit = admin_url('post.php?post='.$plan_id.'&action=edit');
            $view = get_permalink($plan_id);
            $pdf  = add_query_arg('download', 'pdf', $view);
            echo '<a class="button button-small" href="'.esc_url($edit).'">'.esc_html__('Edit', 'kd-clinic').'</a> ';
            echo '<a class="button button-small" href="'.esc_url($view).'" target="_blank">'.esc_html__('View', 'kd-clinic').'</a> ';
            echo '<a class="button button-small" href="'.esc_url($pdf).'">'.esc_html__('PDF', 'kd-clinic').'</a>';
        } else {
            echo esc_html__('Investigating…', 'kd-clinic');
        }
        return;
    }
}, 10, 2);

/** Row actions under title → replace Edit/Quick Edit with a single "Review" */
add_filter('post_row_actions', function ($actions, $post) {
    if ($post->post_type !== 'kh_intake') return $actions;
    if (!current_user_can('read_post', $post->ID)) return $actions;

    // Build Fluent Entry URL
    $ff_form  = (int) (get_post_meta($post->ID, '_kd_ff_form_id', true) ?: (defined('KD_FFID_INTAKE') ? KD_FFID_INTAKE : 0));
    $ff_entry = (int) get_post_meta($post->ID, '_kd_ff_entry_id', true);
    $ff_url   = $ff_form && $ff_entry
        ? admin_url('admin.php?page=fluent_forms&route=entries&form_id='.$ff_form.'#/entries/'.$ff_entry)
        : '';

    // Remove default Edit / Quick Edit
    unset($actions['edit'], $actions['inline hide-if-no-js']);

    // Insert our single Review action (first)
    if ($ff_url) {
        $review_link = '<a href="'.esc_url($ff_url).'" target="_blank" rel="noopener">'.esc_html__('Review', 'kd-clinic').'</a>';
        $actions = array_merge(['kdcl_review' => $review_link], $actions);
    }

    // Keep Trash / View if present; keep our plan actions
    $nonce = wp_create_nonce('kdcl_intake_action_' . $post->ID);
    $actions['kdcl_create_plan'] = '<a href="'.esc_url(admin_url('admin-post.php?action=kdcl_create_plan&intake_id='.$post->ID.'&_wpnonce='.$nonce)).'">'.esc_html__('Create Plan', 'kd-clinic').'</a>';
    $actions['kdcl_ai_draft']    = '<a href="'.esc_url(admin_url('admin-post.php?action=kdcl_ai_draft_plan&intake_id='.$post->ID.'&_wpnonce='.$nonce)).'">'.esc_html__('AI Draft Plan', 'kd-clinic').'</a>';

    return $actions;
}, 10, 2);

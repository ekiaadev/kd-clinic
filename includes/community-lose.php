<?php
/**
 * Community & Lose-A-Dress flows
 * - FluentForms submit → add correct item to cart → redirect to Checkout
 * - Honors HMO (free order) via session flags set from the form
 * - Supports variable product (plan attribute) OR optional plan→product mapping
 */
if (!defined('ABSPATH')) exit;

/** Lightweight logger (writes only if WP_DEBUG & WP_DEBUG_LOG are true) */
if (!function_exists('kdc_log')) {
    function kdc_log($msg){
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[KDC] '.$msg);
        }
    }
}

/* ---------- Helpers ---------- */
if (!function_exists('kd_norm')) {
    function kd_norm($s){ $s = strtolower(trim(wp_strip_all_tags((string)$s))); return preg_replace('/[^a-z0-9]+/','',$s); }
}

if (!function_exists('kd_map_plan_value_to_label')) {
    // Map common short values to human labels that exist on your variations
    function kd_map_plan_value_to_label($form_val){
        $v = strtolower(trim($form_val));
        $map = [
            '1m'  => '1 Month',
            '3m'  => '3 Months',
            '6m'  => '6 Months',
            '12m' => '12 Months',
            '1'   => '1 Month',
            '3'   => '3 Months',
            '6'   => '6 Months',
            '12'  => '12 Months'
        ];
        return $map[$v] ?? $form_val; // if form already returns labels, pass-through
    }
}

if (!function_exists('kd_find_variation_by_plan')) {
    /**
     * Find a variation ID on a variable product by matching the "plan" attribute against a human label
     * Accepts labels like "1 Month", "3 Months", etc.
     * Will try global attr 'pa_plan', custom 'plan', and raw variation attributes (attribute_pa_plan).
     */
    function kd_find_variation_by_plan($product_id, $target_label){
        if (!$product_id || !$target_label) return 0;

        $product = wc_get_product($product_id);
        if (!$product) { kdc_log('Product not found: '.$product_id); return 0; }
        if (!$product->is_type('variable')) { kdc_log('Product is not variable: '.$product_id); return 0; }

        $target = kd_norm($target_label); // e.g. "1month"
        foreach ($product->get_children() as $vid) {
            $v = wc_get_product($vid);
            if (!$v || !$v->exists()) continue;

            // attempt label lookups
            foreach (['pa_plan', 'plan'] as $key) {
                $val = $v->get_attribute($key); // returns term/label
                if ($val && kd_norm($val) === $target) {
                    return (int)$vid;
                }
            }
            // fallback to raw variation attributes (usually slugs)
            $attrs = $v->get_variation_attributes(); // e.g. [ 'attribute_pa_plan' => '1-month' ]
            foreach ($attrs as $k => $val) {
                if (kd_norm(str_replace('-', ' ', $val)) === $target) {
                    return (int)$vid;
                }
            }
        }
        kdc_log('No variation matched label "'.$target_label.'" on product '.$product_id);
        return 0;
    }
}

/* ---------- Core: add-to-cart from FluentForms (Community / Lose) ---------- */
add_action('fluentform_submission_inserted', function($entryId, $formData, $form) {
    try {
        $fid = (int)($form->id ?? 0);

        // Only handle our two forms
        $is_comm = (defined('KD_FFID_COMMUNITY') && $fid === (int)KD_FFID_COMMUNITY);
        $is_lose = (defined('KD_FFID_LOSE')      && $fid === (int)KD_FFID_LOSE);
        if (!$is_comm && !$is_lose) return;

        if (!function_exists('WC') || !WC()->session) { kdc_log('WC session unavailable on FF submit'); return; }

        // Extract fields (FF sends either ['fields'=>...] or flat array)
        $fields = is_array($formData) ? ($formData['fields'] ?? $formData) : [];

        // HMO flags from form (client_type: 'hmo' or 'private')
        $ct = strtolower(trim($fields['client_type'] ?? 'private'));
        WC()->session->set('kd_client_type', in_array($ct, ['hmo','private'], true) ? $ct : 'private');
        if (!empty($fields['hmo_provider']))  WC()->session->set('kd_hmo_provider',  sanitize_text_field($fields['hmo_provider']));
        if (!empty($fields['hmo_member_id'])) WC()->session->set('kd_hmo_member_id', sanitize_text_field($fields['hmo_member_id']));

        if (!WC()->cart) { kdc_log('WC cart unavailable on FF submit'); return; }

        // Enforce single-service start: clear cart if configured
        if (defined('KD_CLEAR_CART_ON_FORM') && KD_CLEAR_CART_ON_FORM && WC()->cart->get_cart_contents_count() > 0) {
            WC()->cart->empty_cart();
            kdc_log('Cart emptied at form submit (single-service start)');
        }

        /* ====== COMMUNITY ====== */
        if ($is_comm) {
            if (!defined('KD_COMM_PRODUCT_ID') || !KD_COMM_PRODUCT_ID) { kdc_log('KD_COMM_PRODUCT_ID undefined'); return; }

            // Accept multiple possible field keys (yours is 'plan_dropdown')
            $plan_raw = '';
            foreach (['plan_dropdown','plan','plan_select','plan_choice'] as $key) {
                if (isset($fields[$key]) && $fields[$key] !== '') { $plan_raw = $fields[$key]; break; }
            }
            if ($plan_raw === '') {
                if (function_exists('wc_add_notice')) wc_add_notice(__('Please choose a plan.', 'kd'), 'error');
                kdc_log('Community submit missing plan field');
                return;
            }

            $plan_label = kd_map_plan_value_to_label($plan_raw); // maps 1m→1 Month, etc.

            // OPTIONAL: plan→product mapping (skip variations entirely)
            $map = apply_filters('kdc_comm_plan_product_map', []);
            if (!empty($map)) {
                $key1 = strtolower(trim($plan_raw));
                $key2 = strtolower(trim($plan_label));
                $pid  = isset($map[$key1]) ? (int)$map[$key1] : (isset($map[$key2]) ? (int)$map[$key2] : 0);
                if ($pid > 0) {
                    WC()->cart->add_to_cart($pid, 1);
                    kdc_log('Community plan mapped to simple product ID '.$pid.' via kdc_comm_plan_product_map');
                    return; // done
                }
            }

            // Default path: variable product with plan attribute
            $vid = kd_find_variation_by_plan((int)KD_COMM_PRODUCT_ID, $plan_label);
            if (!$vid) {
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(__('We couldn’t match your selected plan. Please refresh and try again.', 'kd'), 'error');
                }
                kdc_log('Community: no variation matched for plan "'.$plan_label.'" on product '.KD_COMM_PRODUCT_ID);
                return;
            }

            $vprod = wc_get_product($vid);
            $attrs = $vprod ? $vprod->get_variation_attributes() : [];
            WC()->cart->add_to_cart((int)KD_COMM_PRODUCT_ID, 1, (int)$vid, $attrs);
            kdc_log('Community added: parent='.KD_COMM_PRODUCT_ID.' variation='.$vid.' plan="'.$plan_label.'"');
        }

        /* ====== LOSE A DRESS ====== */
        if ($is_lose) {
            if (!defined('KD_LOSE_PRODUCT_ID') || !KD_LOSE_PRODUCT_ID) { kdc_log('KD_LOSE_PRODUCT_ID undefined'); return; }
            WC()->cart->add_to_cart((int)KD_LOSE_PRODUCT_ID, 1);
            kdc_log('Lose A Dress added: '.KD_LOSE_PRODUCT_ID);
        }

    } catch (\Throwable $e) {
        kdc_log('FF submit error: '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine());
        // Soft-fail: do not throw; FF will show default confirmation if any
        return;
    }
}, 10, 3);

/* ---------- Redirect these two forms to Checkout (FF version-agnostic) ---------- */
add_filter('fluentform/submission_confirmation', function($return, $form = null, $confirmation = null, $entryId = null, $formData = null){
    // Determine form ID safely (object or array)
    $fid = 0;
    if (is_object($form) && isset($form->id))      $fid = (int)$form->id;
    elseif (is_array($form) && isset($form['id'])) $fid = (int)$form['id'];

    $is_comm = (defined('KD_FFID_COMMUNITY') && $fid === (int)KD_FFID_COMMUNITY);
    $is_lose = (defined('KD_FFID_LOSE')      && $fid === (int)KD_FFID_LOSE);
    if (!$is_comm && !$is_lose) return $return;

    if (!function_exists('wc_get_checkout_url')) return $return;

    $return['type']       = 'redirect';
    $return['redirectTo'] = wc_get_checkout_url();
    $return['message']    = '';
    return $return;
}, 20, 5);

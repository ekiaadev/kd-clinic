<?php
if (!defined('ABSPATH')) exit;

/**
 * Pricing + Coupons (collision-safe)
 * - Uses kdcl_coupon_for_client_type() from helpers.php (no re-declare)
 * - Creates/updates coupons (codes come from helpers.php)
 * - Applies coupon exactly once with a session lock
 * - Removes conflicting kd- coupons
 * - Silences the "already applied" error for kd- codes
 */

/* ---------- locals (private names; no collisions) ---------- */

function kdcl__service_product_ids() {
    $ids = [];
    if (defined('KD_COMM_PRODUCT_ID'))     $ids[] = (int) KD_COMM_PRODUCT_ID;
    if (defined('KD_NUTRI_PRODUCT_ID'))    $ids[] = (int) KD_NUTRI_PRODUCT_ID;
    if (defined('KD_CONSULT_PRODUCT_ID'))  $ids[] = (int) KD_CONSULT_PRODUCT_ID;
    return array_values(array_filter(array_map('intval', $ids)));
}

function kdcl__paid_service_cat_id() {
    $slug = defined('KD_PAID_CAT_SLUG') ? KD_PAID_CAT_SLUG : 'paid-services';
    $term = get_term_by('slug', $slug, 'product_cat');
    return $term ? (int) $term->term_id : 0;
}

/** True if cart has at least one service line */
function kdcl__cart_has_service_items($cart) {
    if (!$cart || !is_a($cart, 'WC_Cart')) return false;

    // Prefer global helper if present
    if (function_exists('kdcl_cart_has_service')) {
        return (bool) kdcl_cart_has_service();
    }

    // Fallback: category / explicit IDs
    foreach ($cart->get_cart() as $item) {
        if (empty($item['data'])) continue;
        $p = $item['data'];
        $pid = $p->is_type('variation') ? $p->get_parent_id() : $p->get_id();

        if (defined('KD_PAID_CAT_SLUG')) {
            $terms = get_the_terms($pid, 'product_cat');
            if (!empty($terms) && !is_wp_error($terms)) {
                foreach ($terms as $t) { if (!empty($t->slug) && $t->slug === KD_PAID_CAT_SLUG) return true; }
            }
        }
        if ((defined('KD_COMM_PRODUCT_ID')     && (int)$pid === (int)KD_COMM_PRODUCT_ID) ||
            (defined('KD_NUTRI_PRODUCT_ID')    && (int)$pid === (int)KD_NUTRI_PRODUCT_ID) ||
            (defined('KD_CONSULT_PRODUCT_ID')  && (int)$pid === (int)KD_CONSULT_PRODUCT_ID)) {
            return true;
        }
    }
    return false;
}

/** Create or update a percent coupon with product/category scope */
function kdcl__ensure_percent_coupon($code, $percent, array $product_ids, $cat_id) {
    if (!$code) return;
    $code = strtolower(trim($code));
    $coupon_id = wc_get_coupon_id_by_code($code);

    if (!$coupon_id) {
        $coupon_id = wp_insert_post([
            'post_title'   => $code,
            'post_status'  => 'publish',
            'post_type'    => 'shop_coupon',
            'post_author'  => get_current_user_id(),
            'post_excerpt' => 'Auto-created by Khairo Diet Clinic Core',
        ], true);
        if (is_wp_error($coupon_id) || !$coupon_id) return;
    }

    update_post_meta($coupon_id, 'discount_type', 'percent');
    update_post_meta($coupon_id, 'coupon_amount', (string) (int)$percent);
    update_post_meta($coupon_id, 'individual_use', 'yes');
    update_post_meta($coupon_id, 'exclude_sale_items', 'no');
    update_post_meta($coupon_id, 'usage_limit', '');
    update_post_meta($coupon_id, 'usage_limit_per_user', '');

    $product_ids = array_unique(array_map('intval', $product_ids));
    update_post_meta($coupon_id, 'product_ids', implode(',', $product_ids));
    if ($cat_id > 0) {
        update_post_meta($coupon_id, 'product_categories', [$cat_id]);
        delete_post_meta($coupon_id, 'exclude_product_categories');
    }
}

/* ---------- bootstrap coupons (uses codes from helpers.php) ---------- */

add_action('init', function () {
    if (!class_exists('WC_Coupon')) return;
    if (!function_exists('kdcl_coupon_for_client_type')) return;

    $pids  = kdcl__service_product_ids();
    $catid = kdcl__paid_service_cat_id();

    $code_hmo      = kdcl_coupon_for_client_type('hmo');
    $code_union    = kdcl_coupon_for_client_type('bank_union');
    $code_providus = kdcl_coupon_for_client_type('bank_providus');

    kdcl__ensure_percent_coupon($code_hmo,      100, $pids, $catid);
    kdcl__ensure_percent_coupon($code_union,     40, $pids, $catid);
    kdcl__ensure_percent_coupon($code_providus,  30, $pids, $catid);
}, 20);

/* ---------- apply coupon once, cleanly ---------- */

/**
 * Apply correct coupon once per cart:
 * - If our target code is already applied (case-insensitive), do nothing.
 * - If another kd- coupon is applied, remove it then apply the correct one.
 * - Use a session lock to prevent duplicate attempts from multiple runs.
 */
add_action('woocommerce_before_calculate_totals', function ($cart) {
    if (!is_a($cart, 'WC_Cart')) return;
    if (!function_exists('WC') || !WC()->session) return;
    if (!function_exists('kdcl_coupon_for_client_type')) return;
    if (!kdcl__cart_has_service_items($cart)) return;

    $type = WC()->session->get('kd_client_type');
    $code = kdcl_coupon_for_client_type($type);
    if (!$code) return;
    $code = strtolower($code);

    // Lock prevents re-applying on subsequent calculations
    $lock = strtolower((string) WC()->session->get('kd_coupon_apply_lock'));
    if ($lock === $code) return;

    $applied = array_map('strtolower', (array) $cart->get_applied_coupons());
    if (in_array($code, $applied, true)) {
        WC()->session->set('kd_coupon_apply_lock', $code);
        return;
    }

    // Remove any other kd- coupon so only one remains
    foreach ($applied as $c) {
        if (strpos($c, 'kd-') === 0 && $c !== $code) {
            $cart->remove_coupon($c);
        }
    }

    // Apply and lock
    $cart->apply_coupon($code);
    WC()->session->set('kd_coupon_apply_lock', $code);
}, 8);

/** Clear lock when coupons are removed or cart emptied */
add_action('woocommerce_removed_coupon', function ($code) {
    if (!function_exists('WC') || !WC()->session) return;
    $lock = strtolower((string) WC()->session->get('kd_coupon_apply_lock'));
    if ($lock && strtolower($code) === $lock) WC()->session->set('kd_coupon_apply_lock', '');
}, 10, 1);
add_action('woocommerce_cart_emptied', function () {
    if (function_exists('WC') && WC()->session) WC()->session->set('kd_coupon_apply_lock', '');
}, 10);

/* ---------- silence duplicate "already applied" error for kd- codes ---------- */
add_filter('woocommerce_add_error', function ($message) {
    // Hide only the specific duplicate-apply error for our kd- coupons
    $m = strtolower(trim(wp_strip_all_tags($message)));
    if ($m && strpos($m, 'already applied') !== false && strpos($m, 'coupon code') !== false && strpos($m, 'kd-') !== false) {
        return ''; // suppress it so checkout isn't blocked
    }
    return $message;
}, 10);

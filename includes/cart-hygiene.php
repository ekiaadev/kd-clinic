<?php
if (!defined('ABSPATH')) exit;

/**
 * Hygiene: if the cart contains any “service” product, remove all non-service items.
 * A “service” product is:
 *   - kdcl_is_service_product( $product ) === true (preferred – defined in helpers.php), OR
 *   - in product_cat KD_PAID_CAT_SLUG, OR
 *   - explicit product IDs: KD_COMM_PRODUCT_ID, KD_NUTRI_PRODUCT_ID, KD_CONSULT_PRODUCT_ID.
 *
 * NOTE: Do NOT define kdcl_cart_has_service() here — it already exists in helpers.php.
 */

/** Local, non-conflicting checker (name is private to this file) */
function kdcl__is_service_product_local($product_or_id) {
    $product = is_object($product_or_id) ? $product_or_id : wc_get_product((int)$product_or_id);
    if (!$product) return false;

    // Prefer the global helper if present (but also allow our fallbacks below)
    $is_service = false;
    if (function_exists('kdcl_is_service_product')) {
        $is_service = (bool) kdcl_is_service_product($product);
        if ($is_service) return true; // fast path
    }

    $pid = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();

    // Category check
    if (defined('KD_PAID_CAT_SLUG')) {
        $terms = get_the_terms($pid, 'product_cat');
        if (!empty($terms) && !is_wp_error($terms)) {
            foreach ($terms as $t) {
                if (!empty($t->slug) && $t->slug === KD_PAID_CAT_SLUG) return true;
            }
        }
    }

    // Explicit IDs (covers Nutrition Care product id too)
    if (defined('KD_COMM_PRODUCT_ID')     && (int)$pid === (int)KD_COMM_PRODUCT_ID)  return true;
    if (defined('KD_NUTRI_PRODUCT_ID')    && (int)$pid === (int)KD_NUTRI_PRODUCT_ID) return true;
    if (defined('KD_CONSULT_PRODUCT_ID')  && (int)$pid === (int)KD_CONSULT_PRODUCT_ID) return true;

    return false;
}

/**
 * If there is at least one service item in cart, strip out any non-service items.
 * We never empty the cart entirely, and we never remove service items.
 */
function kdcl_enforce_service_only_cart() {
    if (!function_exists('WC') || !WC()->cart) return;

    $cart = WC()->cart;
    $has_service = false;

    foreach ($cart->get_cart() as $item) {
        if (!empty($item['data']) && kdcl__is_service_product_local($item['data'])) {
            $has_service = true;
            break;
        }
    }
    if (!$has_service) return;

    $to_remove = [];
    foreach ($cart->get_cart() as $key => $item) {
        if (empty($item['data'])) continue;
        if (!kdcl__is_service_product_local($item['data'])) {
            $to_remove[] = $key;
        }
    }

    if ($to_remove) {
        foreach ($to_remove as $k) {
            $cart->remove_cart_item($k);
        }
    }
}

add_action('woocommerce_cart_loaded_from_session', 'kdcl_enforce_service_only_cart', 20);
add_action('woocommerce_before_calculate_totals', 'kdcl_enforce_service_only_cart', 20);


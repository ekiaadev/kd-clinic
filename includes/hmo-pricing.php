<?php
/**
 * Khairo Diet Clinic Core — Client Type Discount Engine
 * Applies discounts based on Fluent Forms dropdown "client_type"
 * Options: direct (0%), hmo (100%), bank_union (40%), bank_providus (30%)
 */

if (!defined('ABSPATH')) exit;

// Re-apply when cart loads from session & before totals are calculated.
add_action('woocommerce_cart_loaded_from_session', 'kdcl_apply_client_type_discounts', 20);
add_action('woocommerce_before_calculate_totals', 'kdcl_apply_client_type_discounts', 20);

/**
 * Main discount applier
 *
 * @param WC_Cart $cart
 */
function kdcl_apply_client_type_discounts($cart) {
    if (is_admin() && !wp_doing_ajax()) return;
    if (!$cart instanceof WC_Cart) return;

    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        if (empty($cart_item['data']) || !$cart_item['data'] instanceof WC_Product) {
            continue;
        }

        /** @var WC_Product $product */
        $product = $cart_item['data'];

        // Only touch our paid service products
        if (!kdcl_is_paid_service_product($product)) {
            continue;
        }

        // Get client_type from cart item data or session fallback
        $client_type = isset($cart_item['kd_client_type'])
            ? $cart_item['kd_client_type']
            : (WC()->session ? WC()->session->get('kd_client_type') : 'direct');

        $discount = kdcl_discount_percent_for_client_type($client_type);

        // Remember original/base price so re-calculations don't re-discount
        if (!isset($cart_item['kd_base_price'])) {
            // Use current catalog price as base (not forcing regular_price to avoid fighting sales rules)
            $base_price = (float) $product->get_price();
            $cart_item['kd_base_price'] = $base_price;
            // Persist our mutation back into cart (important)
            $cart->cart_contents[$cart_item_key] = $cart_item;
        } else {
            $base_price = (float) $cart_item['kd_base_price'];
        }

        // Compute new price
        if ($discount >= 100) {
            $new_price = 0.0;
        } elseif ($discount > 0) {
            $new_price = round($base_price * (1 - ($discount / 100)), wc_get_price_decimals());
        } else {
            $new_price = $base_price; // direct
        }

        $new_price = max(0, (float) $new_price);

        // Apply on the product object clone stored in the cart item
        $product->set_price($new_price);
    }
}

/**
 * Map client type to discount percent
 *
 * @param string $type
 * @return int
 */
function kdcl_discount_percent_for_client_type($type) {
    $t = strtolower(trim((string) $type));
    switch ($t) {
        case 'hmo':
        case 'hmo:hmo':
            return 100;

        case 'bank_union':
        case 'union bank':
        case 'union':
            return 40;

        case 'bank_providus':
        case 'providus bank':
        case 'providus':
            return 30;

        case 'direct':
        default:
            return 0;
    }
}

/**
 * Check the "paid-services" category on parent if it's a variation
 *
 * @param WC_Product $product
 * @return bool
 */
function kdcl_is_paid_service_product(WC_Product $product) {
    $pid = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
    $terms = get_the_terms($pid, 'product_cat');
    if (empty($terms) || is_wp_error($terms)) {
        return false;
    }
    foreach ($terms as $term) {
        if ($term->slug === 'paid-services') {
            return true;
        }
    }
    return false;
}

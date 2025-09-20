<?php
if (!defined('ABSPATH')) exit;

/**
 * Single-service cart rules:
 *  - If adding a service, silently clear the cart first (no notice).
 *  - If cart already has a service, block adding non-service items.
 *  - Force qty = 1 for service items.
 */

add_filter('woocommerce_add_to_cart_validation', function ($pass, $product_id, $quantity, $variation_id = 0, $variations = []) {
    $product = wc_get_product($variation_id ?: $product_id);
    if (!$product) return $pass;

    $is_service       = kdcl_is_service_product($product);
    $cart_has_service = kdcl_cart_has_service();

    // Adding a service: silently clear any existing cart content
    if ($is_service) {
        if (function_exists('WC') && WC()->cart && count(WC()->cart->get_cart()) > 0) {
            WC()->cart->empty_cart(true); // no wc_add_notice here (silenced)
        }
        return true;
    }

    // Adding non-service while a service is in cart: block (keep this error)
    if (!$is_service && $cart_has_service) {
        wc_add_notice(__('You cannot add other products together with a service. Please complete or remove the service first.', 'kd-clinic'), 'error');
        return false;
    }

    return $pass;
}, 10, 5);

/**
 * Service items must be quantity = 1
 */
add_action('woocommerce_before_calculate_totals', function ($cart) {
    if (!$cart) return;
    foreach ($cart->get_cart() as $key => $item) {
        if (!empty($item['data']) && kdcl_is_service_product($item['data'])) {
            if ($item['quantity'] !== 1) {
                $cart->set_quantity($key, 1);
            }
        }
    }
}, 20);

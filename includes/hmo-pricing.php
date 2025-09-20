<?php if (!defined('ABSPATH')) exit;

/* Zero prices if HMO, hide gateways, store order meta, auto-complete free 
add_action('woocommerce_before_calculate_totals', function($cart){
    if (is_admin()) return;
    if (!function_exists('WC') || !WC()->session) return;

    $ct = WC()->session->get('kd_client_type');
    if ($ct !== 'hmo') return;

    foreach ($cart->get_cart() as $item) {
        if (!empty($item['data']) && is_object($item['data'])) {
            $item['data']->set_price(0);
        }
    }
}, 20);
*/

add_filter('woocommerce_available_payment_gateways', function($gateways){
    if (!is_checkout() || !function_exists('WC') || !WC()->cart) return $gateways;
    $total = (float) WC()->cart->get_total('edit');
    if ($total <= 0) { foreach ($gateways as $id => $gw) unset($gateways[$id]); }
    return $gateways;
});

add_action('woocommerce_checkout_create_order', function($order){
    if (!function_exists('WC') || !WC()->session) return;
    $s = WC()->session;

    $order->update_meta_data('_kd_client_type',   $s->get('kd_client_type'));
    $order->update_meta_data('_kd_hmo_provider',  $s->get('kd_hmo_provider'));
    $order->update_meta_data('_kd_hmo_member_id', $s->get('kd_hmo_member_id'));
    $order->update_meta_data('_kd_contact_name',  trim(($s->get('kd_contact_first').' '.$s->get('kd_contact_last'))));
    $order->update_meta_data('_kd_contact_email', $s->get('kd_contact_email'));
}, 10);

add_action('woocommerce_thankyou', function($order_id){
    $order = wc_get_order($order_id); if (!$order) return;
    if ((float)$order->get_total() == 0 && $order->has_status(['pending','on-hold','processing'])) {
        $order->payment_complete();
        $order->update_status('completed', 'Auto-completed (HMO / Free order).');
    }
}, 10);

/**
 * Zero service prices ONLY when the entire cart is service-only and HMO is set.
 * If any non-service item appears, drop HMO flags immediately.
 
add_action('woocommerce_before_calculate_totals', function ($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    if (!WC()->session || !$cart) return;

    $hmo = WC()->session->get('kd_hmo') || WC()->session->get('kdc_hmo') || WC()->session->get('kd_is_hmo');
    if (!$hmo) return;

    $paid_cat_slug = (defined('KD_PAID_CAT_SLUG') && KD_PAID_CAT_SLUG) ? KD_PAID_CAT_SLUG : 'paid-services';

    // Check if ALL cart items are services
    $all_service = true;
    foreach ($cart->get_cart() as $item) {
        $pid = (int) ($item['variation_id'] ?: $item['product_id']);
        if (!has_term($paid_cat_slug, 'product_cat', $pid)) { $all_service = false; break; }
    }

    if (!$all_service) {
        // As soon as any non-service enters, disable HMO for this session
        WC()->session->__unset('kd_hmo');
        WC()->session->__unset('kdc_hmo');
        WC()->session->__unset('kd_is_hmo');
        kdc_log('Unset HMO in before_calculate_totals due to non-service in cart');
        return;
    }

    // Service-only + HMO: zero prices
    foreach ($cart->get_cart() as &$item) {
        $pid = (int) ($item['variation_id'] ?: $item['product_id']);
        if (has_term($paid_cat_slug, 'product_cat', $pid) && isset($item['data'])) {
            $item['data']->set_price(0);
        }
    }
}, 5);*/ // earlier priority to clear HMO before other pricing logic runs
add_action('woocommerce_before_calculate_totals', function ($cart) {
    if (is_admin() && !defined('DOING_AJAX')) return;
    if (!WC()->session || !$cart) return;

    $s = WC()->session;
    $hmo_active =
        ($s->get('kd_client_type') === 'hmo') ||
        $s->get('kd_hmo') ||
        $s->get('kdc_hmo') ||
        $s->get('kd_is_hmo');

    if (!$hmo_active) return;

    $paid_cat_slug = (defined('KD_PAID_CAT_SLUG') && KD_PAID_CAT_SLUG) ? KD_PAID_CAT_SLUG : 'paid-services';

    // Must be service-only to apply HMO
    $all_service = true;
    foreach ($cart->get_cart() as $item) {
        $pid = (int) ($item['variation_id'] ?: $item['product_id']);
        if (!has_term($paid_cat_slug, 'product_cat', $pid)) { $all_service = false; break; }
    }

    if (!$all_service) {
        // Drop ALL HMO flags if a non-service item is present
        $s->__unset('kd_client_type');
        $s->__unset('kd_hmo');
        $s->__unset('kdc_hmo');
        $s->__unset('kd_is_hmo');
        $s->__unset('kd_hmo_provider');
        $s->__unset('kd_hmo_member_id');
        kdc_log('Unset HMO flags in before_calculate_totals due to non-service in cart');
        return;
    }

    // Service-only + HMO: zero price for service items only
    foreach ($cart->get_cart() as &$item) {
        $pid = (int) ($item['variation_id'] ?: $item['product_id']);
        if (has_term($paid_cat_slug, 'product_cat', $pid) && isset($item['data'])) {
            $item['data']->set_price(0);
        }
    }
}, 5);

/**
 * Hide gateways only when HMO is active AND the cart is service-only.
 */
add_filter('woocommerce_available_payment_gateways', function ($gateways) {
    if (!is_checkout() || !WC()->session) return $gateways;

    $hmo = WC()->session->get('kd_hmo') || WC()->session->get('kdc_hmo') || WC()->session->get('kd_is_hmo');
    if (!$hmo) return $gateways;

    $paid_cat_slug = (defined('KD_PAID_CAT_SLUG') && KD_PAID_CAT_SLUG) ? KD_PAID_CAT_SLUG : 'paid-services';

    // Verify service-only
    $all_service = true;
    foreach (WC()->cart->get_cart() as $item) {
        $pid = (int) ($item['variation_id'] ?: $item['product_id']);
        if (!has_term($paid_cat_slug, 'product_cat', $pid)) { $all_service = false; break; }
    }

    if (!$all_service) return $gateways;

    // HMO service-only: hide all gateways (adjust if you want COD/etc.)
    return [];
}, 20);

/**
 * When cart is loaded from session, if HMO flag exists but cart isn't service-only,
 * clear the HMO flags immediately (prevents "everything is free" on return visits).
 */
add_action('woocommerce_cart_loaded_from_session', function ($cart) {
    if (!WC()->session || !$cart) return;

    $hmo = WC()->session->get('kd_hmo') || WC()->session->get('kdc_hmo') || WC()->session->get('kd_is_hmo');
    if (!$hmo) return;

    $paid_cat_slug = (defined('KD_PAID_CAT_SLUG') && KD_PAID_CAT_SLUG) ? KD_PAID_CAT_SLUG : 'paid-services';

    $all_service = true;
    foreach ($cart->get_cart() as $item) {
        $pid = (int) ($item['variation_id'] ?: $item['product_id']);
        if (!has_term($paid_cat_slug, 'product_cat', $pid)) { $all_service = false; break; }
    }

    if (!$all_service) {
        $s = WC()->session;
        $s->__unset('kd_client_type');   // NEW: also clear client_type=hmo
        $s->__unset('kd_hmo');
        $s->__unset('kdc_hmo');
        $s->__unset('kd_is_hmo');
        $s->__unset('kd_hmo_provider');  // NEW
        $s->__unset('kd_hmo_member_id'); // NEW
        kdc_log('Unset HMO flags in cart_loaded_from_session due to non-service cart');
    }
}, 5);

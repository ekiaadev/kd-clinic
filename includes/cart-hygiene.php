<?php
/**
 * Cart Hygiene & Flow Guardrails
 * - Enforce single-service cart for Consultation / Community / Lose
 * - Tag active flow in session
 * - Keep cart clean when switching flows
 * - Clear flow/HMO flags at the right times
 */
if (!defined('ABSPATH')) exit;

/**
 * Optional logger to wp-content/debug.log (only if WP_DEBUG & WP_DEBUG_LOG are true)
 */
if (!function_exists('kdc_log')) {
    function kdc_log($msg){
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[KDC] '.$msg);
        }
    }
}

/* --------------------------------------------------------------------------
 * 1) Entering Consultation booking page → mark flow + empty cart
 * -------------------------------------------------------------------------- */
add_action('template_redirect', function () {
    if (!function_exists('WC') || !WC()->cart || !WC()->session) return;
    if (!defined('KD_BOOKING_URL') || !KD_BOOKING_URL) return;

    $req  = esc_url_raw($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($req, PHP_URL_PATH) ?: $req;

    $is_booking = (stripos(untrailingslashit($path), untrailingslashit(KD_BOOKING_URL)) !== false);
    if (!$is_booking) return;

    WC()->session->set('kd_flow', 'consultation');

    if (WC()->cart->get_cart_contents_count() > 0) {
        WC()->cart->empty_cart();
        if (function_exists('wc_add_notice')) {
            wc_add_notice(__('We cleared your cart to start the consultation booking.', 'kd'), 'notice');
        }
        kdc_log('Cart emptied on booking page entry; flow=consultation');
    }
}, 1);

/* --------------------------------------------------------------------------
 * 2) Tag flow when Community / Lose forms are submitted (before add_to_cart)
 * -------------------------------------------------------------------------- */
add_action('fluentform_submission_inserted', function($entryId, $formData, $form) {
    if (!function_exists('WC') || !WC()->session) return;

    $fid = (int)($form->id ?? 0);

    if (defined('KD_FFID_COMMUNITY') && $fid === (int)KD_FFID_COMMUNITY) {
        WC()->session->set('kd_flow', 'community');
        kdc_log('Flow set to community from FF submit');
    } elseif (defined('KD_FFID_LOSE') && $fid === (int)KD_FFID_LOSE) {
        WC()->session->set('kd_flow', 'lose');
        kdc_log('Flow set to lose from FF submit');
    }
}, 9, 3); // priority 9 so our tag runs before any add_to_cart at 10

/* --------------------------------------------------------------------------
 * 3) Enforce only allowed item(s) for active flow when reaching checkout
 *    (but DO NOT touch carts that have no service item; clear stale kd_flow)
 * -------------------------------------------------------------------------- */
add_action('template_redirect', function () {
    if (!function_exists('is_checkout') || !is_checkout()) return;
    if (!function_exists('WC') || !WC()->cart || !WC()->session) return;

    $flow = WC()->session->get('kd_flow');
    if (!$flow) return;

    $allowed = [];

    if ($flow === 'consultation' && defined('KD_CONSULT_PRODUCT_ID') && KD_CONSULT_PRODUCT_ID) {
        $allowed[] = (int) KD_CONSULT_PRODUCT_ID;
    }
    if ($flow === 'community' && defined('KD_COMM_PRODUCT_ID') && KD_COMM_PRODUCT_ID) {
        $allowed[] = (int) KD_COMM_PRODUCT_ID; // variations share this parent
    }
    if ($flow === 'lose' && defined('KD_LOSE_PRODUCT_ID') && KD_LOSE_PRODUCT_ID) {
        $allowed[] = (int) KD_LOSE_PRODUCT_ID;
    }

    if (!$allowed) return;

    // If the cart does NOT contain any allowed service item, kd_flow is stale → drop it and bail.
    $cart_has_service = false;
    foreach (WC()->cart->get_cart() as $i) {
        $pid = (int) ($i['product_id'] ?? 0);
        if (in_array($pid, $allowed, true)) { $cart_has_service = true; break; }
        if (!empty($i['variation_id'])) {
            $vp = wc_get_product((int)$i['variation_id']);
            if ($vp && $vp->get_parent_id() && in_array((int)$vp->get_parent_id(), $allowed, true)) {
                $cart_has_service = true; break;
            }
        }
    }
    if (!$cart_has_service) {
        WC()->session->__unset('kd_flow'); // stale flag, do not police unrelated carts
        return;
    }

    // Cart has a service: remove unrelated items (keep only the allowed service family)
    $removed_any = false;
    foreach (WC()->cart->get_cart() as $key => $item) {
        $pid = (int) ($item['product_id'] ?? 0);

        if (!in_array($pid, $allowed, true)) {
            // Allow variations whose parent is allowed
            if (!empty($item['variation_id'])) {
                $vp = wc_get_product((int)$item['variation_id']);
                if ($vp && $vp->get_parent_id() && in_array((int)$vp->get_parent_id(), $allowed, true)) {
                    continue;
                }
            }
            WC()->cart->remove_cart_item($key);
            $removed_any = true;
        }
    }

    if ($removed_any) {
        if (function_exists('wc_add_notice')) {
            wc_add_notice(__('We removed unrelated items to complete this service.', 'kd'), 'notice');
        }
        kdc_log('Removed unrelated cart items at checkout for flow='.$flow);
    }
}, 2);

/* --------------------------------------------------------------------------
 * 4) Global add_to_cart guard: only one service family in cart at a time
 *     + if adding a non-service while a service (often HMO=0) sits in cart:
 *       clear service + HMO flags and allow the add
 * -------------------------------------------------------------------------- */
add_filter('woocommerce_add_to_cart_validation', function($passed, $product_id, $quantity, $variation_id = 0){
    if (!$passed) return $passed;
    if (!function_exists('WC') || !WC()->cart || !WC()->session) return $passed;

    // Known service parents (only those defined)
    $service_parents = [];
    if (defined('KD_CONSULT_PRODUCT_ID') && KD_CONSULT_PRODUCT_ID) $service_parents[] = (int) KD_CONSULT_PRODUCT_ID;
    if (defined('KD_COMM_PRODUCT_ID')    && KD_COMM_PRODUCT_ID)    $service_parents[] = (int) KD_COMM_PRODUCT_ID;
    if (defined('KD_LOSE_PRODUCT_ID')    && KD_LOSE_PRODUCT_ID)    $service_parents[] = (int) KD_LOSE_PRODUCT_ID;

    // Normalize target to parent ID (if variation is being added)
    $target_parent = (int)$product_id;
    if ($variation_id) {
        $v = wc_get_product((int)$variation_id);
        if ($v && $v->get_parent_id()) $target_parent = (int)$v->get_parent_id();
    }

    $is_service_add = in_array($target_parent, $service_parents, true);

    // Helper: does cart currently contain any service parent?
    $cart_has_service = false;
    $cart_has_other_than_target_service = false;
    foreach (WC()->cart->get_cart() as $item) {
        $parent = (int)($item['product_id'] ?? 0);
        if (!empty($item['variation_id'])) {
            $vp = wc_get_product((int)$item['variation_id']);
            if ($vp && $vp->get_parent_id()) $parent = (int)$vp->get_parent_id();
        }
        if (in_array($parent, $service_parents, true)) {
            $cart_has_service = true;
            if ($is_service_add && $parent !== $target_parent) {
                $cart_has_other_than_target_service = true;
            }
        }
    }

    // A) Adding a SERVICE
    if ($is_service_add) {
        // If any different service OR any non-service items are already in cart, start fresh
        if (WC()->cart->get_cart_contents_count() > 0) {
            $needs_reset = false;
            foreach (WC()->cart->get_cart() as $item) {
                $parent = (int)($item['product_id'] ?? 0);
                if (!empty($item['variation_id'])) {
                    $vp = wc_get_product((int)$item['variation_id']);
                    if ($vp && $vp->get_parent_id()) $parent = (int)$vp->get_parent_id();
                }
                if ($parent !== $target_parent) { $needs_reset = true; break; }
            }
            if ($needs_reset) {
                WC()->cart->empty_cart();
                // Tag correct flow
                if (defined('KD_CONSULT_PRODUCT_ID') && $target_parent === (int)KD_CONSULT_PRODUCT_ID) {
                    WC()->session->set('kd_flow', 'consultation');
                } elseif (defined('KD_COMM_PRODUCT_ID') && $target_parent === (int)KD_COMM_PRODUCT_ID) {
                    WC()->session->set('kd_flow', 'community');
                } elseif (defined('KD_LOSE_PRODUCT_ID') && $target_parent === (int)KD_LOSE_PRODUCT_ID) {
                    WC()->session->set('kd_flow', 'lose');
                } else {
                    WC()->session->__unset('kd_flow');
                }
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(__('We cleared other items to start this service.', 'kd'), 'notice');
                }
                kdc_log('Cart emptied on add_to_cart for service; new parent='.$target_parent);
            }
        }
        return $passed;
    }

    // B) Adding a NON-SERVICE while a (possibly HMO=0) service sits in cart:
    if ($cart_has_service) {
        WC()->cart->empty_cart();
        // Clear flow + any HMO flags
        WC()->session->__unset('kd_flow');
        WC()->session->__unset('kd_client_type');   // ← ADD THIS
        WC()->session->__unset('kd_hmo');
        WC()->session->__unset('kdc_hmo');
        WC()->session->__unset('kd_is_hmo');
        WC()->session->__unset('kd_hmo_provider');  // ← ADD THIS
        WC()->session->__unset('kd_hmo_member_id'); // ← ADD THIS
        if (function_exists('wc_add_notice')) {
            wc_add_notice(__('We cleared a previous consultation to add this product.', 'kd'), 'notice');
        }
        kdc_log('Cleared service + HMO due to non-service add');
        return true; // allow this add
    }

    // C) Normal NON-SERVICE add (no service present)
    return $passed;
}, 10, 4);

/* --------------------------------------------------------------------------
 * 4b) Belt & suspenders: if a NON-service is actually added (some flows bypass
 *      validation), clear any service + HMO flags so pricing returns to normal.
 * -------------------------------------------------------------------------- */
add_action('woocommerce_add_to_cart', function ($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    if (!function_exists('WC') || !WC()->session) return;

    // Determine the real product being added
    $pid = (int) ($variation_id ?: $product_id);

    // Known service parents (as in your Section 4)
    $service_parents = [];
    if (defined('KD_CONSULT_PRODUCT_ID') && KD_CONSULT_PRODUCT_ID) $service_parents[] = (int) KD_CONSULT_PRODUCT_ID;
    if (defined('KD_COMM_PRODUCT_ID')    && KD_COMM_PRODUCT_ID)    $service_parents[] = (int) KD_COMM_PRODUCT_ID;
    if (defined('KD_LOSE_PRODUCT_ID')    && KD_LOSE_PRODUCT_ID)    $service_parents[] = (int) KD_LOSE_PRODUCT_ID;

    // Normalize to parent if it's a variation
    $parent_id = $pid;
    $p = wc_get_product($pid);
    if ($p && $p->is_type('variation') && $p->get_parent_id()) {
        $parent_id = (int) $p->get_parent_id();
    }

    // If this is NOT a service parent, clear HMO + flow flags (in case validation was skipped)
    if ($service_parents && !in_array($parent_id, $service_parents, true)) {
        WC()->session->__unset('kd_flow');
        WC()->session->__unset('kd_client_type');   // ← ADD THIS
        WC()->session->__unset('kd_hmo');
        WC()->session->__unset('kdc_hmo');
        WC()->session->__unset('kd_is_hmo');
        WC()->session->__unset('kd_hmo_provider');  // ← ADD THIS
        WC()->session->__unset('kd_hmo_member_id'); // ← ADD THIS
        kdc_log('Cleared HMO + flow in woocommerce_add_to_cart for non-service add');
    }
}, 10, 6);

/* --------------------------------------------------------------------------
 * 5) Flow heuristic: infer kd_flow from cart if user lands on /checkout directly
 * -------------------------------------------------------------------------- */
add_action('template_redirect', function () {
    if (!function_exists('is_checkout') || (!is_checkout() && !is_cart())) return;
    if (!function_exists('WC') || !WC()->session || !WC()->cart) return;

    $s = WC()->session;
    if ($s->get('kd_flow')) return;

    foreach (WC()->cart->get_cart() as $item) {
        $parent = (int) ($item['product_id'] ?? 0);

        if (defined('KD_CONSULT_PRODUCT_ID') && $parent === (int)KD_CONSULT_PRODUCT_ID) { $s->set('kd_flow','consultation'); break; }
        if (defined('KD_COMM_PRODUCT_ID')    && $parent === (int)KD_COMM_PRODUCT_ID)    { $s->set('kd_flow','community');    break; }
        if (defined('KD_LOSE_PRODUCT_ID')    && $parent === (int)KD_LOSE_PRODUCT_ID)    { $s->set('kd_flow','lose');         break; }
    }
    if ($s->get('kd_flow')) kdc_log('Heuristic set kd_flow='.$s->get('kd_flow').' from cart contents');
}, 0);

/* --------------------------------------------------------------------------
 * 6) Session hygiene: when to clear kd_flow vs. all flags
 * -------------------------------------------------------------------------- */
if (!function_exists('kd_clear_flow_flags_all')) {
    function kd_clear_flow_flags_all() {
        if (!function_exists('WC') || !WC()->session) return;
        WC()->session->__unset('kd_flow');
        WC()->session->__unset('kd_client_type');
        WC()->session->__unset('kd_hmo_provider');
        WC()->session->__unset('kd_hmo_member_id');
        kdc_log('Cleared flow + HMO flags (all)');
    }
}
if (!function_exists('kd_clear_flow_flag_only')) {
    function kd_clear_flow_flag_only() {
        if (!function_exists('WC') || !WC()->session) return;
        // Keep HMO flags so ₦0 pricing can still apply after we intentionally empty cart
        WC()->session->__unset('kd_flow');
        kdc_log('Cleared flow flag only');
    }
}

/* After order thank-you: clear everything */
add_action('woocommerce_thankyou', function(){ kd_clear_flow_flags_all(); }, 99);

/* When the cart is emptied (by user or us): clear only the flow tag */
add_action('woocommerce_cart_emptied', function(){ kd_clear_flow_flag_only(); });

/* On logout: clear everything */
add_action('wp_logout', function(){ kd_clear_flow_flags_all(); });

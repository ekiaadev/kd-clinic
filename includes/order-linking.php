<?php
if (!defined('ABSPATH')) exit;

/**
 * On checkout order creation, bind this order to the current intake
 * saved in session at the consolidated form submit.
 */
add_action('woocommerce_checkout_create_order', function($order){
    if (!function_exists('WC') || !WC()->session) return;
    $s = WC()->session;
    $intake_id = (int) $s->get('kd_current_intake_id');
    if (!$intake_id) return;

    // Bind both ways
    $oid = (int) $order->get_id();
    update_post_meta($intake_id, '_kd_order_id', $oid);
    $order->update_meta_data('_kd_bound_intake', $intake_id);

    // Helpful context
    if ($email = get_post_meta($intake_id, '_kd_contact_email', true)) {
        $order->update_meta_data('_kd_contact_email', $email);
    }
    if ($name = get_post_meta($intake_id, '_kd_contact_name', true)) {
        $order->update_meta_data('_kd_contact_name', $name);
    }
}, 20);

/** After thank-you, clear the handoff (intake is already bound) */
add_action('woocommerce_thankyou', function(){
    if (!function_exists('WC') || !WC()->session) return;
    $s = WC()->session;
    $s->__unset('kd_current_intake_id');
}, 99);

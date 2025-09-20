<?php if (!defined('ABSPATH')) exit;

add_action('template_redirect', function () {
    if (!function_exists('is_checkout') || !is_checkout()) return;
    if (!function_exists('WC') || !WC()->session) return;

    $u = wp_get_current_user();
    $first = $u->first_name ?: ''; $last = $u->last_name ?: '';
    if (!$first && !$last) list($first,$last) = kd_split_name($u->display_name);

    $customer = WC()->customer;
    if ($first) $customer->set_billing_first_name($first);
    if ($last)  $customer->set_billing_last_name($last);
    if ($u->user_email) $customer->set_billing_email($u->user_email);
    $customer->save();

    WC()->session->set('kd_contact_first', $first);
    WC()->session->set('kd_contact_last',  $last);
    WC()->session->set('kd_contact_email', $u->user_email ?: '');
}, 1);

add_filter('woocommerce_checkout_fields', function($fields){
    if (!function_exists('WC') || !WC()->session) return $fields;
    $s = WC()->session;
    foreach ([
        'billing_first_name' => $s->get('kd_contact_first'),
        'billing_last_name'  => $s->get('kd_contact_last'),
        'billing_email'      => $s->get('kd_contact_email'),
    ] as $key => $val) {
        if (!empty($val) && isset($fields['billing'][$key])) {
            $fields['billing'][$key]['default'] = $val;
        }
    }
    return $fields;
}, 10, 1);

add_filter('woocommerce_checkout_get_value', function($value, $input){
    if (!function_exists('WC') || !WC()->session) return $value;
    $s = WC()->session;
    if ($input === 'billing_first_name' && $s->get('kd_contact_first')) return $s->get('kd_contact_first');
    if ($input === 'billing_last_name'  && $s->get('kd_contact_last'))  return $s->get('kd_contact_last');
    if ($input === 'billing_email'      && $s->get('kd_contact_email')) return $s->get('kd_contact_email');
    return $value;
}, 10, 2);

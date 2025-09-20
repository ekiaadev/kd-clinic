<?php if (!defined('ABSPATH')) exit;

/* Thank-you → Intake (only for Consultation product orders) */
add_action('template_redirect', function () {
    if (!function_exists('is_order_received_page') || !is_order_received_page()) return;

    $order_id = 0;
    $qv = get_query_var('order-received');
    if ($qv) $order_id = absint($qv);
    elseif (!empty($_GET['order_id'])) $order_id = absint($_GET['order_id']);
    if (!$order_id) return;

    $order = wc_get_order($order_id); if (!$order) return;

    $total = (float) $order->get_total();
    $ok = $order->is_paid() || $total == 0 || in_array($order->get_status(), ['processing','completed'], true);
    if (!$ok) return;

    $has_consult = false;
    foreach ($order->get_items('line_item') as $item) {
        if ((int)$item->get_product_id() === (int)KD_CONSULT_PRODUCT_ID) { $has_consult = true; break; }
    }
    if (!$has_consult) return;

    $booking_id = get_post_meta($order_id, '_fluentbooking_booking_id', true);

    $first = (string) $order->get_billing_first_name();
    $last  = (string) $order->get_billing_last_name();
    $email = (string) $order->get_billing_email();

    if (!$first && !$last) {
        $u = wp_get_current_user();
        $first = $u->first_name ?: ''; $last = $u->last_name ?: '';
        if (!$first && !$last) list($first,$last) = kd_split_name($u->display_name);
        if (!$email) $email = $u->user_email ?: '';
    }
    $n = trim($first.' '.$last);

    $intake_url = home_url( trailingslashit( ltrim(KD_INTAKE_URL, '/') ) );
    $url = add_query_arg(array_filter([
        'order_id'   => $order_id,
        'booking_id' => $booking_id,
        'n'          => $n,
        'nf'         => $first,
        'nl'         => $last,
        'e'          => $email
    ]), $intake_url);

    wp_safe_redirect($url);
    exit;
});

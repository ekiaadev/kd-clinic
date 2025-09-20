<?php if (!defined('ABSPATH')) exit;

add_action('wp_enqueue_scripts', function(){
    if (!kd_is_booking_page()) return;

    $u = wp_get_current_user();
    $first = $u->first_name ?: ''; $last = $u->last_name ?: '';
    if (!$first && !$last) list($first,$last) = kd_split_name($u->display_name);
    $payload = ['n' => trim($first.' '.$last), 'e' => ($u->user_email ?: '')];

    wp_enqueue_script('kd-booking-prefill', KDC_URL.'assets/js/booking-prefill.js', [], null, true);
    wp_localize_script('kd-booking-prefill', 'KD_BOOKING', $payload);
});

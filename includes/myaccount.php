<?php if (!defined('ABSPATH')) exit;

/**
 * Frontend-safe order resolver for an intake (±40 minutes window).
 * Prefers cached _kd_order_id, else resolves and caches it. Also binds
 * reverse meta on the order (_kd_bound_intake) when missing.
 * This guard avoids redeclaration if we later centralize it.
 */
if (!function_exists('kdcl_resolve_order_for_intake')) {
    function kdcl_resolve_order_for_intake($intake_id){
        // 0) If already cached, return fast
        $saved = (int) get_post_meta($intake_id, '_kd_order_id', true);
        if ($saved) return $saved;
        if (!function_exists('wc_get_orders')) return 0;

        // 1) Basics
        $post = get_post($intake_id);
        if (!$post) return 0;

        // Client (prefer stored meta, else author)
        $uid = (int) get_post_meta($intake_id, 'kh_client_user_id', true);
        if (!$uid && $post->post_author) $uid = (int) $post->post_author;
        if (!$uid) return 0;

        // Time window for matching (±40 minutes around intake creation)
        $ts = strtotime($post->post_date_gmt ?: $post->post_date ?: 'now');
        $window = 40 * MINUTE_IN_SECONDS;
        $from = date('Y-m-d H:i:s', $ts - $window);
        $to   = date('Y-m-d H:i:s', $ts + $window);

        // Required consultation product parent
        if (!defined('KD_CONSULT_PRODUCT_ID') || !KD_CONSULT_PRODUCT_ID) return 0;
        $consult_pid = (int) KD_CONSULT_PRODUCT_ID;

        // 2) Search recent orders for this user within the window
        $orders = wc_get_orders([
            'type'         => 'shop_order',
            'customer_id'  => $uid,
            'date_created' => $from.'...'.$to,
            'limit'        => 15,
            'orderby'      => 'date',
            'order'        => 'DESC',
            'status'       => array_keys(wc_get_order_statuses()),
            'return'       => 'objects',
        ]);

        foreach ($orders as $order){
            // Must contain the consultation product (normalize variations)
            $has = false;
            foreach ($order->get_items() as $item){
                $pid = (int) $item->get_product_id();
                $parent = $pid;
                if ($item->get_variation_id()){
                    $v = wc_get_product($item->get_variation_id());
                    if ($v && $v->get_parent_id()) $parent = (int) $v->get_parent_id();
                }
                if ($parent === $consult_pid) { $has = true; break; }
            }
            if (!$has) continue;

            // Bind and cache
            $oid = (int) $order->get_id();
            update_post_meta($intake_id, '_kd_order_id', $oid);

            if (!(int)$order->get_meta('_kd_bound_intake')) {
                $order->update_meta_data('_kd_bound_intake', (int)$intake_id);
                $order->save();
            }
            return $oid;
        }
        return 0;
    }
}

/* --------------------------------------------------------------------------
 * Endpoints
 * -------------------------------------------------------------------------- */
add_action('init', function () {
    add_rewrite_endpoint('appointments', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('intakes', EP_ROOT | EP_PAGES);
});

add_filter('woocommerce_account_menu_items', function($items){
    $new = [];
    foreach ($items as $key=>$label) {
        $new[$key] = $label;
        if ($key === 'orders') {
            $new['appointments'] = __('Appointments','kd');
            $new['intakes']      = __('Intakes','kd');
        }
    }
    return $new;
}, 20);

add_action('woocommerce_account_appointments_endpoint', function(){
    echo '<h3>My Appointments</h3>';
    echo do_shortcode('[fluent_booking_lists title="" filter="show" pagination="show" period="upcoming" per_page=10 no_bookings="No bookings found"]');
});

/* --------------------------------------------------------------------------
 * My Account: Intakes endpoint (Date | Order | Status | My Nutrition Plan)
 * -------------------------------------------------------------------------- */
add_action('woocommerce_account_intakes_endpoint', function () {
    if (!is_user_logged_in()) { echo ''; return; }

    $uid = get_current_user_id();

    $q = new WP_Query([
        'post_type'      => 'kh_intake',
        'post_status'    => ['private','publish'],
        'author'         => $uid,
        'posts_per_page' => 50,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ]);

    echo '<h3>'.esc_html__('My Intakes', 'kd-clinic').'</h3>';
    echo '<table class="shop_table shop_table_responsive">';
    echo '<thead><tr>';
    echo '<th>'.esc_html__('Date', 'kd-clinic').'</th>';
    echo '<th>'.esc_html__('Order', 'kd-clinic').'</th>';
    echo '<th>'.esc_html__('Status', 'kd-clinic').'</th>';
    echo '<th>'.esc_html__('My Nutrition Plan', 'kd-clinic').'</th>';
    echo '</tr></thead><tbody>';

    if (!$q->have_posts()) {
        echo '<tr><td colspan="4">'.esc_html__('No intake found yet.', 'kd-clinic').'</td></tr>';
        echo '</tbody></table>';
        return;
    }

    while ($q->have_posts()) { $q->the_post();
        $intake_id = get_the_ID();
        $date      = esc_html(get_the_date());

        // ORDER (resolve & cache as before)
        $order_id = (int) get_post_meta($intake_id, '_kd_order_id', true);
        if (!$order_id) {
            $order_id = kdcl_resolve_order_for_intake($intake_id);
        }
        
        if ($order_id && ($order = wc_get_order($order_id))) {
            // Client view: show plain order number (no link) to avoid bad endpoints
            $order_col  = '#' . intval($order_id);
            $status_col = esc_html( wc_get_order_status_name( $order->get_status() ) );
        } else {
            $order_col  = '—';
            $status_col = '—';
        }

        // PLAN (published/private visible to owner via gate)
        $plan_id = 0;
        $plan_q = new WP_Query([
            'post_type'      => 'kh_plan',
            'posts_per_page' => 1,
            'post_status'    => ['publish','private'],
            'meta_query'     => [[ 'key' => 'kdcl_source_intake', 'value' => $intake_id ]],
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        if (!empty($plan_q->posts)) $plan_id = (int)$plan_q->posts[0];

        if ($plan_id) {
            $view = get_permalink($plan_id);
            $pdf  = add_query_arg('download', 'pdf', $view);
            $plan_col = '<a class="button" href="'.esc_url($view).'">'.esc_html__('View', 'kd-clinic').'</a> '
                      . '<a class="button" href="'.esc_url($pdf).'">'.esc_html__('Download PDF', 'kd-clinic').'</a>';
        } else {
            $plan_col = esc_html__('Investigating…', 'kd-clinic');
        }

        echo '<tr>';
        echo '<td>'.$date.'</td>';
        echo '<td>'.$order_col.'</td>';
        echo '<td>'.$status_col.'</td>';
        echo '<td>'.$plan_col.'</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    wp_reset_postdata();
});

/* --------------------------------------------------------------------------
 * Rename "Intakes" tab to "Consultation Data"
 * -------------------------------------------------------------------------- */
add_filter('woocommerce_account_menu_items', function ($items) {
    if (isset($items['intakes'])) {
        $items['intakes'] = __('Consultation Data', 'kd-clinic');
        return $items;
    }
    foreach ($items as $key => $label) {
        if (is_string($label) && trim(wp_strip_all_tags($label)) === 'Intakes') {
            $items[$key] = __('Consultation Data', 'kd-clinic');
        }
    }
    return $items;
}, 20);

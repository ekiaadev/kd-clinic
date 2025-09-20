<?php if (!defined('ABSPATH')) exit;

/* Helper */
if (!function_exists('kd_order_items_in_cat')) {
    function kd_order_items_in_cat(WC_Order $order, string $cat_slug): bool {
        $items = $order->get_items('line_item');
        if (empty($items)) return false;
        foreach ($items as $item) {
            $pid = (int)$item->get_product_id(); if (!$pid) return false;
            $terms = get_the_terms($pid, 'product_cat'); if (empty($terms)) return false;
            $slugs = array_map(static function($t){ return $t->slug; }, $terms);
            if (!in_array($cat_slug, $slugs, true)) return false;
        }
        return true;
    }
}

/* Auto-complete + mute customer emails for Paid Services only orders */
add_action('woocommerce_thankyou', function($order_id){
    $order = wc_get_order($order_id); if (!$order) return;
    if (!kd_order_items_in_cat($order, KD_PAID_CAT_SLUG)) return;

    $total = (float) $order->get_total();
    $is_paid = $order->is_paid() || $total == 0.0;

    if ($is_paid && !$order->has_status('completed')) {
        if (!$order->is_paid() && $total > 0) $order->payment_complete();
        $order->update_status('completed', 'Auto-completed (Paid Services policy).');
    }
}, 20);

add_filter('woocommerce_email_enabled_customer_processing_order', function($enabled, $order){
    if ($order instanceof WC_Order && kd_order_items_in_cat($order, KD_PAID_CAT_SLUG)) return false;
    return $enabled;
}, 20, 2);

add_filter('woocommerce_email_enabled_customer_completed_order', function($enabled, $order){
    if ($order instanceof WC_Order && kd_order_items_in_cat($order, KD_PAID_CAT_SLUG)) return false;
    return $enabled;
}, 20, 2);

/* NOTE: Admin emails unchanged. AffiliateWP untouched (commissions still apply). */

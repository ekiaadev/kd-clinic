<?php
if (!defined('ABSPATH')) exit;

/**
 * IDs of service products for targeting (if defined).
 */
function kdcl_service_product_ids() {
	$ids = [];
	if (defined('KD_COMM_PRODUCT_ID'))    $ids[] = (int) KD_COMM_PRODUCT_ID;
	if (defined('KD_CONSULT_PRODUCT_ID')) $ids[] = (int) KD_CONSULT_PRODUCT_ID;
	return array_values(array_filter(array_map('intval', $ids)));
}

/**
 * Ensure our program coupons exist and are correctly configured
 * - Percentage discounts (100 / 40 / 30)
 * - Individual use
 * - Limited to paid-services category AND explicit product IDs (safer for bookings)
 */
function kdcl_ensure_service_coupons() {
	if (!function_exists('wc_get_coupon_id_by_code')) return;

	$service_cat_id = kdcl_get_term_id_by_slug(defined('KD_PAID_CAT_SLUG') ? KD_PAID_CAT_SLUG : 'paid-services', 'product_cat');
	$product_ids    = kdcl_service_product_ids();
	$product_ids_str= implode(',', $product_ids); // Woo stores this as comma-separated string

	$coupons = [
		(defined('KD_COUPON_HMO') ? KD_COUPON_HMO : 'KD-HMO') => [
			'discount_type'          => 'percent',
			'coupon_amount'          => '100',
			'individual_use'         => 'yes',
			'product_categories'     => $service_cat_id ? [$service_cat_id] : [],
			'product_ids'            => $product_ids_str,
			'exclude_sale_items'     => 'no',
			'free_shipping'          => 'no',
			'description'            => 'HMO 100% for service products',
		],
		(defined('KD_COUPON_UNION') ? KD_COUPON_UNION : 'KD-UNION') => [
			'discount_type'          => 'percent',
			'coupon_amount'          => '40',
			'individual_use'         => 'yes',
			'product_categories'     => $service_cat_id ? [$service_cat_id] : [],
			'product_ids'            => $product_ids_str,
			'exclude_sale_items'     => 'no',
			'free_shipping'          => 'no',
			'description'            => 'Union Bank 40% for service products',
		],
		(defined('KD_COUPON_PROVIDUS') ? KD_COUPON_PROVIDUS : 'KD-PROVIDUS') => [
			'discount_type'          => 'percent',
			'coupon_amount'          => '30',
			'individual_use'         => 'yes',
			'product_categories'     => $service_cat_id ? [$service_cat_id] : [],
			'product_ids'            => $product_ids_str,
			'exclude_sale_items'     => 'no',
			'free_shipping'          => 'no',
			'description'            => 'Providus Bank 30% for service products',
		],
	];

	foreach ($coupons as $code => $meta) {
		$code = strtoupper($code);
		$existing_id = wc_get_coupon_id_by_code($code);

		if ($existing_id) {
			foreach ($meta as $k => $v) {
				update_post_meta($existing_id, $k, $v);
			}
			wp_update_post([
				'ID'           => $existing_id,
				'post_title'   => $code,
				'post_excerpt' => $meta['description'],
			]);
			continue;
		}

		$post_id = wp_insert_post([
			'post_title'   => $code,
			'post_content' => '',
			'post_status'  => 'publish',
			'post_author'  => get_current_user_id(),
			'post_type'    => 'shop_coupon',
			'post_excerpt' => $meta['description'],
		]);
		if (is_wp_error($post_id) || !$post_id) continue;

		foreach ($meta as $k => $v) {
			update_post_meta($post_id, $k, $v);
		}
	}
}

/**
 * Auto-apply program coupon for service carts based on kd_client_type
 */
function kdcl_maybe_apply_program_coupon() {
	if (!function_exists('WC') || !WC()->cart) return;

	$cart = WC()->cart;

	// If no service in cart → remove our program coupons and bail
	if (!kdcl_cart_has_service()) {
		foreach ($cart->get_applied_coupons() as $code) {
			$up = strtoupper($code);
			if (in_array($up, [
				defined('KD_COUPON_HMO') ? KD_COUPON_HMO : 'KD-HMO',
				defined('KD_COUPON_UNION') ? KD_COUPON_UNION : 'KD-UNION',
				defined('KD_COUPON_PROVIDUS') ? KD_COUPON_PROVIDUS : 'KD-PROVIDUS'
			], true)) {
				$cart->remove_coupon($code);
			}
		}
		return;
	}

	// Determine client_type from session or stamped cart meta
	$client_type = '';
	if (WC()->session) $client_type = WC()->session->get('kd_client_type');
	if (!$client_type) {
		foreach ($cart->get_cart() as $item) {
			if (!empty($item['data']) && kdcl_is_service_product($item['data']) && !empty($item['kd_client_type'])) {
				$client_type = $item['kd_client_type'];
				break;
			}
		}
	}

	$target = kdcl_coupon_for_client_type($client_type);

	// Remove any of our program coupons that don't match the target
	foreach ($cart->get_applied_coupons() as $code) {
		$up = strtoupper($code);
		$our_codes = [
			defined('KD_COUPON_HMO') ? KD_COUPON_HMO : 'KD-HMO',
			defined('KD_COUPON_UNION') ? KD_COUPON_UNION : 'KD-UNION',
			defined('KD_COUPON_PROVIDUS') ? KD_COUPON_PROVIDUS : 'KD-PROVIDUS'
		];
		if (in_array($up, $our_codes, true) && (!$target || $up !== strtoupper($target))) {
			$cart->remove_coupon($code);
		}
	}

	if (!$target) return; // direct → nothing to apply

	// Ensure coupons are present/updated (also fixes any old bad meta)
	kdcl_ensure_service_coupons();

	// Apply target if not already
	if (!in_array(strtolower($target), array_map('strtolower', $cart->get_applied_coupons()), true)) {
		$cart->apply_coupon($target);
	}
}
add_action('woocommerce_cart_loaded_from_session', 'kdcl_maybe_apply_program_coupon', 50);
add_action('woocommerce_before_calculate_totals', 'kdcl_maybe_apply_program_coupon', 50);

/**
 * EXTRA SAFETY: when ANY item is added, stamp kd_client_type from session on that cart item.
 * This covers how Fluent Booking inserts the product.
 */
add_action('woocommerce_add_to_cart', function ($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
	if (!function_exists('WC') || !WC()->session || !WC()->cart) return;
	$t = WC()->session->get('kd_client_type');
	if (!$t) return;

	if (isset(WC()->cart->cart_contents[$cart_item_key])) {
		WC()->cart->cart_contents[$cart_item_key]['kd_client_type'] = $t;
	}
}, 10, 6);

<?php
if (!defined('ABSPATH')) exit;

/**
 * ===== URL / PATH HELPERS =====
 */

if (!function_exists('kdcl_current_path')) {
	function kdcl_current_path() {
		$uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$path = wp_parse_url($uri, PHP_URL_PATH);
		$path = is_string($path) ? $path : '/';
		return '/' . ltrim($path, '/');
	}
}

if (!function_exists('kd_path_match')) {
	function kd_path_match($target_path) {
		if (!$target_path) return false;
		$cur = untrailingslashit(kdcl_current_path());
		$tar = '/' . ltrim((string) $target_path, '/');
		$tar = untrailingslashit($tar);
		return $cur === $tar;
	}
}

if (!function_exists('kd_is_booking_page')) {
	function kd_is_booking_page() {
		$matches = false;
		if (defined('KD_NUTRI_BOOKING_URL') && KD_NUTRI_BOOKING_URL) {
			$matches = $matches || kd_path_match(KD_NUTRI_BOOKING_URL);
		}
		if (defined('KD_BOOKING_URL') && KD_BOOKING_URL) {
			$matches = $matches || kd_path_match(KD_BOOKING_URL);
		}
		return (bool) $matches;
	}
}

if (!function_exists('kd_is_intake_page')) {
	function kd_is_intake_page() {
		return (defined('KD_INTAKE_URL') && KD_INTAKE_URL && kd_path_match(KD_INTAKE_URL));
	}
}

if (!function_exists('kdcl_safe_redirect')) {
	function kdcl_safe_redirect($url) {
		wp_safe_redirect(esc_url_raw($url));
		exit;
	}
}

/**
 * ===== PRODUCT / CART HELPERS =====
 */

if (!function_exists('kdcl_get_term_id_by_slug')) {
	function kdcl_get_term_id_by_slug($slug, $taxonomy) {
		$term = get_term_by('slug', $slug, $taxonomy);
		return ($term && !is_wp_error($term)) ? (int)$term->term_id : 0;
	}
}

/**
 * Treat products in paid-services category OR known service product IDs as “service”.
 */
if (!function_exists('kdcl_is_service_product')) {
	function kdcl_is_service_product($product) {
		if (!$product) return false;

		$pid   = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
		$match = false;

		// A) Category check (parent/category-aware)
		$terms = get_the_terms($pid, 'product_cat');
		if (!empty($terms) && !is_wp_error($terms) && defined('KD_PAID_CAT_SLUG')) {
			foreach ($terms as $t) {
				if (!empty($t->slug) && $t->slug === KD_PAID_CAT_SLUG) {
					$match = true; break;
				}
			}
		}

		// B) Fallback to explicit product IDs (works even if category missing)
		if (!$match) {
			if (defined('KD_COMM_PRODUCT_ID') && (int)$pid === (int)KD_COMM_PRODUCT_ID) $match = true;
			if (defined('KD_CONSULT_PRODUCT_ID') && (int)$pid === (int)KD_CONSULT_PRODUCT_ID) $match = true;
		}

		return $match;
	}
}

if (!function_exists('kdcl_cart_has_service')) {
	function kdcl_cart_has_service() {
		if (!function_exists('WC') || !WC()->cart) return false;
		foreach (WC()->cart->get_cart() as $item) {
			if (!empty($item['data']) && kdcl_is_service_product($item['data'])) return true;
		}
		return false;
	}
}

/**
 * ===== COUPON HELPERS =====
 */
if (!function_exists('kdcl_coupon_for_client_type')) {
	function kdcl_coupon_for_client_type($type) {
		$t = strtolower(trim((string)$type));
		if ($t === 'hmo')            return defined('KD_COUPON_HMO') ? KD_COUPON_HMO : '';
		if ($t === 'bank_union')     return defined('KD_COUPON_UNION') ? KD_COUPON_UNION : '';
		if ($t === 'bank_providus')  return defined('KD_COUPON_PROVIDUS') ? KD_COUPON_PROVIDUS : '';
		return ''; // direct/unknown => no coupon
	}
}

/**
 * ===== MISC =====
 */
if (!function_exists('kd_split_name')) {
	function kd_split_name($full_name) {
		$full_name = trim(preg_replace('/\s+/', ' ', (string) $full_name));
		if ($full_name === '') return ['first_name' => '', 'last_name' => ''];
		$parts = explode(' ', $full_name);
		$first = array_shift($parts);
		$last  = count($parts) ? implode(' ', $parts) : '';
		return ['first_name' => $first, 'last_name' => $last];
	}
}

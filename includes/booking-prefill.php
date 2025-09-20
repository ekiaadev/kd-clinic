<?php
if (!defined('ABSPATH')) exit;

/**
 * Only run on booking pages; fail-safe if helper isn’t present.
 */
add_action('wp_enqueue_scripts', function () {
	if (!function_exists('kd_is_booking_page')) {
		return;
	}
	if (!kd_is_booking_page()) {
		return;
	}

	// Enqueue your existing assets for booking prefill here
	// Example (keep your original handle/paths):
	$handle = 'kdcl-booking-prefill';
	wp_register_script(
		$handle,
		trailingslashit(plugin_dir_url(__FILE__)) . '../assets/js/booking-prefill.js',
		['jquery'],
		'1.0',
		true
	);
	wp_enqueue_script($handle);
}, 20);

<?php
// kd-clinic/includes/admin-menu.php
if (!defined('ABSPATH')) exit;

/**
 * Create one top-level: "Diet Clinic"
 */
add_action('admin_menu', function () {
    add_menu_page(
        __('Diet Clinic', 'kd-clinic'),
        __('Diet Clinic', 'kd-clinic'),
        'read',
        'kdc-diet-clinic',
        function () {
            // landing: Intakes list
            wp_safe_redirect(admin_url('edit.php?post_type=kh_intake'));
            exit;
        },
        'dashicons-heart',
        26
    );
}, 20);

/**
 * Move CPT menus under "Diet Clinic" to avoid duplicates.
 * This assumes your CPTs are already registered by this point.
 */
add_action('admin_init', function () {
    // Only adjust if the CPTs exist
    $pto_i = get_post_type_object('kh_intake');
    $pto_p = get_post_type_object('kh_plan');

    // If they exist, set show_in_menu to our top-level slug.
    if ($pto_i) {
        add_filter('register_post_type_args', function ($args, $post_type) {
            if ($post_type === 'kh_intake') {
                $args['show_in_menu']     = 'kdc-diet-clinic';
                $args['show_in_admin_bar']= false;
            }
            return $args;
        }, 10, 2);
    }

    if ($pto_p) {
        add_filter('register_post_type_args', function ($args, $post_type) {
            if ($post_type === 'kh_plan') {
                $args['show_in_menu']     = 'kdc-diet-clinic';
                $args['show_in_admin_bar']= false;
            }
            return $args;
        }, 10, 2);
    }
}, 20);

/**
 * After args filters, re-register CPT menus by doing a late hook to flush menu.
 * (WP builds menu on admin_menu, so our admin_init filters apply before that.)
 */

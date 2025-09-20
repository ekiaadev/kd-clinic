<?php
if (!defined('ABSPATH')) exit;

/**
 * Top-level "Diet Clinic" menu (right under Dashboard) + submenus.
 * Position: 3 (Dashboard ~2), so it stays at the top.
 */
add_action('admin_menu', function () {
    add_menu_page(
        __('Diet Clinic', 'kd-clinic'),
        __('Diet Clinic', 'kd-clinic'),
        'edit_posts',
        'edit.php?post_type=kh_intake',
        '',
        'dashicons-heart',
        3
    );

    // Pre-Consultation (kh_intake) — read-only list
    add_submenu_page(
        'edit.php?post_type=kh_intake',
        __('Pre-Consultation', 'kd-clinic'),
        __('Pre-Consultation', 'kd-clinic'),
        'edit_posts',
        'edit.php?post_type=kh_intake'
    );

    // Consultations (kh_consult)
    if (post_type_exists('kh_consult')) {
        add_submenu_page(
            'edit.php?post_type=kh_intake',
            __('Consultations', 'kd-clinic'),
            __('Consultations', 'kd-clinic'),
            'edit_posts',
            'edit.php?post_type=kh_consult'
        );
    }

    // Diet Plans (kh_plan) — we’ll rewire later
    if (post_type_exists('kh_plan')) {
        add_submenu_page(
            'edit.php?post_type=kh_intake',
            __('Diet Plans', 'kd-clinic'),
            __('Diet Plans', 'kd-clinic'),
            'edit_posts',
            'edit.php?post_type=kh_plan'
        );
    }
}, 9);

/**
 * Make kh_intake read-only (remove edit/delete/publish caps).
 */
add_filter('user_has_cap', function ($allcaps, $caps, $args) {
    if (!empty($args[0]) && in_array($args[0], ['edit_post','delete_post','publish_post'], true)) {
        $post_id = isset($args[2]) ? (int)$args[2] : 0;
        if ($post_id) {
            $post = get_post($post_id);
            if ($post && $post->post_type === 'kh_intake') {
                $allcaps['edit_post']    = false;
                $allcaps['delete_post']  = false;
                $allcaps['publish_post'] = false;
            }
        }
    }
    return $allcaps;
}, 10, 3);

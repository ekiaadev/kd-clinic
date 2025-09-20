<?php
// kd-clinic/includes/capabilities.php
if (!defined('ABSPATH')) exit;

/**
 * Allow users with our explicit cap to read kh_intake posts (even if private),
 * authors can read their own.
 */
add_filter('map_meta_cap', function ($caps, $cap, $user_id, $args) {
    if ($cap !== 'read_post' || empty($args[0])) return $caps;

    $post_id = (int) $args[0];
    if (get_post_type($post_id) !== 'kh_intake') return $caps;

    $post = get_post($post_id);
    if ($post && (int)$post->post_author === (int)$user_id) return ['read']; // owner

    // anyone with this cap (assign via Members) can read any Intake
    if (user_can($user_id, 'read_kh_intake')) return ['read'];

    // admins/editors fallback
    if (user_can($user_id, 'manage_options') || user_can($user_id, 'edit_others_posts')) return ['read'];

    return $caps;
}, 10, 4);

/** Expose caps to Members UI */
add_action('init', function () {
    if (function_exists('members_register_cap')) {
        members_register_cap('read_kh_intake', ['label' => __('Read Intake (kh_intake)', 'kd-clinic')]);
        // If your kh_plan uses custom caps, expose them as needed:
        members_register_cap('edit_kh_plan', ['label' => __('Edit Diet Plans (kh_plan)', 'kd-clinic')]);
        members_register_cap('publish_kh_plans', ['label' => __('Publish Diet Plans (kh_plan)', 'kd-clinic')]);
        members_register_cap('read_private_kh_plans', ['label' => __('Read Private Diet Plans (kh_plan)', 'kd-clinic')]);
    }
});

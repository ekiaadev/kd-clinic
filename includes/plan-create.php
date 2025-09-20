<?php
// kd-clinic/includes/plan-create.php

if (!defined('ABSPATH')) exit;

if (!function_exists('kdcl_get_intake_context')) {
    // If file load order changes, ensure the helper exists
    function kdcl_get_intake_context($intake_id) { return new WP_Error('kdcl_missing_helper', 'Helper not loaded'); }
}

/**
 * Check if a plan already exists for an intake to avoid duplicates.
 */
function kdcl_find_existing_plan_for_intake($intake_id) {
    $q = new WP_Query([
        'post_type'      => 'kh_plan',
        'posts_per_page' => 1,
        'post_status'    => ['draft','pending','publish'],
        'meta_key'       => 'kdcl_source_intake',
        'meta_value'     => (int)$intake_id,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);
    return $q->posts ? (int)$q->posts[0] : 0;
}

/**
 * Create a kh_plan draft from an intake. Accepts content sections array (heading => body).
 */
function kdcl_create_plan_from_intake($intake_id, $sections = []) {
    $ctx = kdcl_get_intake_context($intake_id);
    if (is_wp_error($ctx)) return $ctx;

    $existing = kdcl_find_existing_plan_for_intake($intake_id);
    if ($existing) return $existing; // idempotent: reuse existing plan

    $client_user_id = (int) ($ctx['client_user_id'] ?? 0);
    $client_name = 'Client';
    if ($client_user_id) {
        $u = get_userdata($client_user_id);
        if ($u) $client_name = $u->display_name ?: $u->user_login;
    }
    $title = sprintf(__('Diet Plan for %s', 'kd-clinic'), $client_name);

    // Build sanitized content
    $content = '';
    if ($sections && is_array($sections)) {
        foreach ($sections as $heading => $body) {
            $content .= '<h2>' . esc_html($heading) . '</h2>' . wp_kses_post(wpautop($body));
        }
    }

    $plan_id = wp_insert_post([
        'post_type'   => 'kh_plan',
        'post_title'  => $title,
        'post_status' => 'draft',
        'post_content'=> $content,
        'post_author' => get_current_user_id(),
    ], true);

    if (is_wp_error($plan_id)) return $plan_id;

    if ($client_user_id) update_post_meta($plan_id, 'kh_client_user_id', $client_user_id);
    update_post_meta($plan_id, 'kdcl_source_intake', (int) $intake_id);

    // Optional: copy common fields for quick filtering/search
    // $payload = $ctx['payload'];
    // if (!empty($payload['goal'])) update_post_meta($plan_id, 'kh_goal', sanitize_text_field($payload['goal']));

    return (int) $plan_id;
}

/**
 * Rule-based AI fallback (safe offline) and hook for real AI.
 */
function kdcl_build_ai_sections_from_payload($payload) {
    $sections = [
        __('Overview', 'kd-clinic') =>
            sprintf(
                "Client objectives: %s\nPrimary concerns: %s",
                $payload['goal']      ?? '—',
                $payload['concerns']  ?? '—'
            ),
        __('Nutrition Strategy', 'kd-clinic') =>
            "• Daily caloric target: " . ($payload['calories_target'] ?? 'to be set by dietician') . "\n"
            ."• Macro emphasis: "    . ($payload['macro_focus']      ?? 'balanced') . "\n"
            ."• Prioritize: "        . ($payload['foods_preferred']  ?? 'leafy greens, lean proteins, whole grains') . "\n"
            ."• Limit/Avoid: "       . ($payload['foods_avoid']      ?? 'refined sugar, deep-fried foods'),
        __('Sample Day', 'kd-clinic') =>
            "Breakfast: Oats + protein + fruit\n"
            ."Lunch: Lean protein + vegetables + complex carbs\n"
            ."Dinner: Vegetables + moderate protein\n"
            ."Snacks: Nuts, yogurt, fruit\n"
            ."Hydration: 2–3 L water",
        __('Activity & Lifestyle', 'kd-clinic') =>
            "Movement: " . ($payload['activity_level'] ?? 'light-to-moderate') . "\n"
            ."Sleep: 7–8 hours\n"
            ."Stress: brief breathing/relaxation sessions",
        __('Medical/Allergies Notes', 'kd-clinic') =>
            is_string($payload['medical'] ?? '') ? $payload['medical'] : 'None reported',
    ];

    /**
     * Hook point for real AI integration.
     * Return associative array 'Heading' => 'Body text'.
     */
    return apply_filters('kdcl_ai_generate_plan_sections', $sections, $payload);
}

/** Admin-post: Create Plan (no AI) */
add_action('admin_post_kdcl_create_plan', function () {
    $intake_id = isset($_GET['intake_id']) ? (int) $_GET['intake_id'] : 0;
    $nonce     = $_GET['_wpnonce'] ?? '';
    if (!$intake_id || !wp_verify_nonce($nonce, 'kdcl_intake_action_' . $intake_id)) wp_die('Invalid request.');
    if (!current_user_can('edit_posts')) wp_die('Insufficient permission.');

    $plan_id = kdcl_create_plan_from_intake($intake_id);
    if (is_wp_error($plan_id)) wp_die(esc_html($plan_id->get_error_message()));

    wp_safe_redirect(admin_url('post.php?post='.(int)$plan_id.'&action=edit'));
    exit;
});

/** Admin-post: AI Draft Plan */
add_action('admin_post_kdcl_ai_draft_plan', function () {
    $intake_id = isset($_GET['intake_id']) ? (int) $_GET['intake_id'] : 0;
    $nonce     = $_GET['_wpnonce'] ?? '';
    if (!$intake_id || !wp_verify_nonce($nonce, 'kdcl_intake_action_' . $intake_id)) wp_die('Invalid request.');
    if (!current_user_can('edit_posts')) wp_die('Insufficient permission.');

    $ctx = kdcl_get_intake_context($intake_id);
    if (is_wp_error($ctx)) wp_die(esc_html($ctx->get_error_message()));

    $sections = kdcl_build_ai_sections_from_payload($ctx['payload'] ?? []);
    $plan_id  = kdcl_create_plan_from_intake($intake_id, $sections);
    if (is_wp_error($plan_id)) wp_die(esc_html($plan_id->get_error_message()));

    wp_safe_redirect(admin_url('post.php?post='.(int)$plan_id.'&action=edit'));
    exit;
});

<?php
// kd-clinic/includes/roles.php

if (!defined('ABSPATH')) exit;

/**
 * Ensure Dietician role exists and has caps to read kh_intake and create kh_plan drafts.
 * We pull caps from the actual CPT objects if they exist, so we don’t assume custom cap names.
 */
add_action('init', function () {
    // Ensure role exists
    $role = get_role('dietician');
    if (!$role) {
        $role = add_role('dietician', __('Dietician', 'kd-clinic'), ['read' => true]);
    }

    if (!$role) return; // hard stop if WP couldn’t create/fetch role

    // Grant generic reading capability
    if (!$role->has_cap('read')) $role->add_cap('read');

    // Derive caps from CPTs where possible
    $intake_pto = get_post_type_object('kh_intake');
    $plan_pto   = get_post_type_object('kh_plan');

    // Allow reading private intakes (view in list)
    $role->add_cap('read_private_posts');
    $role->add_cap('list_users'); // optional: for user dropdowns

    // Plans: editing/publishing drafts
    if ($plan_pto && !empty($plan_pto->cap)) {
        foreach (['edit_post','read_post','delete_post','edit_posts','edit_others_posts','publish_posts','read_private_posts'] as $cap_key) {
            $cap = $plan_pto->cap->$cap_key ?? null;
            if ($cap) $role->add_cap($cap);
        }
    } else {
        // Fallback to default post caps if kh_plan uses 'post' caps
        foreach (['edit_posts','publish_posts','read_private_posts'] as $cap) $role->add_cap($cap);
    }
}, 20);

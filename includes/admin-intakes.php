<?php
if (!defined('ABSPATH')) exit;

/** Build the admin URL for viewing an FF entry */
function kdcl_ff_admin_entry_url($entry_id, $form_id) {
    return admin_url('admin.php?page=fluent_forms&route=entries&form_id=' . (int)$form_id . '#/entries/' . (int)$entry_id);
}

/** Small helpers to map intake payload -> consultation schema */
function kdcl_pick($arr, $candidates) {
    foreach ($candidates as $k) {
        if (isset($arr[$k]) && $arr[$k] !== '') return $arr[$k];
    }
    return '';
}
function kdcl_parse_height_m($raw) {
    if (!$raw) return 0.0;
    $s = strtolower(trim((string)$raw));
    // normalize commas, strip text except digits/period
    $s = str_replace(',', '.', $s);
    if (strpos($s, 'cm') !== false) {
        $num = floatval(preg_replace('/[^0-9\.]/', '', $s));
        return $num > 0 ? ($num / 100.0) : 0.0;
    }
    // "1.6m" or just "1.6"
    $num = floatval(preg_replace('/[^0-9\.]/', '', $s));
    if ($num > 3.0 && $num < 300.0) { // likely cm accidentally
        return $num / 100.0;
    }
    return $num > 0 ? $num : 0.0;
}
function kdcl_parse_weight_kg($raw) {
    if (!$raw) return 0.0;
    $s = strtolower(trim((string)$raw));
    $s = str_replace(',', '.', $s);
    $num = floatval(preg_replace('/[^0-9\.]/', '', $s));
    return $num > 0 ? $num : 0.0;
}
function kdcl_compute_age($dob_str) {
    if (!$dob_str) return '';
    try {
        $dob = new DateTime($dob_str);
        $now = new DateTime('now', wp_timezone());
        $diff = $now->diff($dob);
        return (string) $diff->y;
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Map the stored intake payload (sanitized FF keys) to our consultation schema keys.
 * We exclude the 4 system fields you requested (and a few variants).
 */
function kdcl_map_intake_payload_to_consult(array $payload) {
    // Drop system/noise keys (handle multiple possible variants)
    $strip_keys = [
        'fluentformnonce', 'fluentform_5_fluentformnonce', 'fluent_form_embded_post_id',
        '_fluent_form_embded_post_id', '_wp_http_referer', 'wp_http_referer',
        'how_you_find_us', 'term_agreed'
    ];
    foreach ($strip_keys as $rm) unset($payload[$rm]);

    // Pick values from likely keys (payload keys are sanitized by kdcl_build_ff_payload)
    $client_name  = kdcl_pick($payload, ['names','full_name','fullname','name','first_name']);
    $gender       = kdcl_pick($payload, ['gender','sex']);
    $dob          = kdcl_pick($payload, ['date_of_birth','dob','birth_date']);
    $age          = kdcl_compute_age($dob);

    $height_raw   = kdcl_pick($payload, ['height_input','height']);
    $weight_raw   = kdcl_pick($payload, ['weight_input','weight']);

    $height_m     = kdcl_parse_height_m($height_raw);
    $weight_kg    = kdcl_parse_weight_kg($weight_raw);
    $bmi          = ($height_m > 0 && $weight_kg > 0) ? round($weight_kg / ($height_m * $height_m), 1) : '';

    $history      = kdcl_pick($payload, ['medical_history','history']);
    $presenting   = kdcl_pick($payload, ['consultation_purpose','presenting_complaint','purpose','goal','concern']);
    $activity     = kdcl_pick($payload, ['preferred_contact','activity_level','physical_activity']); // move if you have a dedicated field
    $medications  = kdcl_pick($payload, ['medications','supplements','current_medications']);

    // Build consultation data using the schema keys expected by the meta box
    $consult = [
        'client_name'     => sanitize_text_field($client_name),
        'gender'          => sanitize_text_field($gender),
        'age'             => sanitize_text_field($age),
        'height'          => is_string($height_raw) ? sanitize_text_field($height_raw) : $height_raw,
        'weight'          => is_string($weight_raw) ? sanitize_text_field($weight_raw) : $weight_raw,
        'bmi'             => $bmi !== '' ? (string)$bmi : '',
        'history'         => is_string($history) ? sanitize_text_field($history) : $history,
        'presenting'      => is_string($presenting) ? sanitize_text_field($presenting) : $presenting,
        'family_history'  => '',          // add mapping if you capture this in Intake
        'activity_level'  => is_string($activity) ? sanitize_text_field($activity) : $activity,
        'medications'     => is_string($medications) ? sanitize_text_field($medications) : $medications,

        // Biochemical (left blank until you capture them; add mappings later)
        'bio_na'          => '',
        'bio_cl'          => '',
        'bio_urea'        => '',
        'bio_creatinine'  => '',
        'bio_egfr'        => '',
        'bio_hdl'         => '',
        'bio_ldl'         => '',
        'bio_trigs'       => '',

        // Diet history (will usually be filled during consultation)
        'diet_breakfast'  => '',
        'diet_lunch'      => '',
        'diet_dinner'     => '',
        'recall_24h'      => '',
        'food_prediction' => '',

        // Assessment
        'diagnosis'       => '',
        'intervention'    => '',
        'notes'           => '',
    ];

    return $consult;
}

/** Row actions: Review (FF) + Create Consultation */
add_filter('post_row_actions', function ($actions, $post) {
    if ($post->post_type !== 'kh_intake') return $actions;

    // Read-only: remove default edit/quick links
    unset($actions['edit'], $actions['inline hide-if-no-js']);

    $entry_id = (int)get_post_meta($post->ID, '_kd_ff_entry_id', true);
    if ($entry_id) {
        $ff_url = kdcl_ff_admin_entry_url($entry_id, defined('KD_FFID_INTAKE') ? KD_FFID_INTAKE : 0);
        $actions['kd_review'] = '<a href="' . esc_url($ff_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Review', 'kd-clinic') . '</a>';
    }

    $actions['kd_make_consult'] = '<a href="' . esc_url(
        wp_nonce_url(admin_url('admin-post.php?action=kdcl_create_consultation&intake_id=' . (int)$post->ID), 'kdcl_make_consult_' . (int)$post->ID)
    ) . '">' . esc_html__('Create Consultation', 'kd-clinic') . '</a>';

    return $actions;
}, 10, 2);

/** Create consultation from an intake (with real prefill mapping) */
add_action('admin_post_kdcl_create_consultation', function () {
    if (!current_user_can('edit_posts')) wp_die(__('You do not have permission.', 'kd-clinic'));
    $intake_id = isset($_GET['intake_id']) ? (int)$_GET['intake_id'] : 0;
    check_admin_referer('kdcl_make_consult_' . $intake_id);

    $intake = get_post($intake_id);
    if (!$intake || $intake->post_type !== 'kh_intake') wp_die(__('Invalid intake.', 'kd-clinic'));

    $title = sprintf(__('Consultation for %s', 'kd-clinic'),
        get_post_meta($intake_id, '_kd_contact_name', true) ?: $intake->post_title
    );

    $consult_id = wp_insert_post([
        'post_title'  => $title,
        'post_type'   => 'kh_consult',
        'post_status' => 'private',
        'post_author' => (int)$intake->post_author,
    ], true);
    if (is_wp_error($consult_id) || !$consult_id) wp_die(__('Could not create consultation.', 'kd-clinic'));

    // Get stored Intake payload and MAP it to the Consultation schema
    $raw_payload = (array)get_post_meta($intake_id, '_kd_intake_payload', true);
    $mapped      = kdcl_map_intake_payload_to_consult($raw_payload);

    update_post_meta($consult_id, '_kd_consult_from_intake', (int)$intake_id);
    update_post_meta($consult_id, '_kd_consult_data',        $mapped);
    update_post_meta($intake_id,  '_kd_consultation_id',      (int)$consult_id);

    wp_safe_redirect(admin_url('post.php?post=' . (int)$consult_id . '&action=edit'));
    exit;
});

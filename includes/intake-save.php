<?php
if (!defined('ABSPATH')) exit;

// Consolidated Intake (FFID = KD_FFID_INTAKE)
add_action('fluentform_submission_inserted', function($entryId, $formData, $form){
    if ((int)($form->id ?? 0) !== (int) KD_FFID_INTAKE) return;

    // 1) Normalize payload we store on the intake post
    $payload = is_array($formData) ? $formData : [];
    $client_id = get_current_user_id();

    // 2) Create intake CPT now
    // Build a human title: "Full Name (Intake – {entryId})"
$name_from_payload = '';
if (is_array($formData)) {
    foreach (['names','full_name','fullname','name'] as $k) {
        if (!empty($formData[$k])) { $name_from_payload = trim((string)$formData[$k]); break; }
    }
    if (!$name_from_payload) {
        $first = ''; $last = '';
        foreach (['first_name','firstname','given_name'] as $k) { if (!empty($formData[$k])) { $first = trim((string)$formData[$k]); break; } }
        foreach (['last_name','lastname','surname','family_name'] as $k) { if (!empty($formData[$k]))  { $last  = trim((string)$formData[$k]); break; } }
        $name_from_payload = trim($first.' '.$last);
    }
}
if (!$name_from_payload) {
    $u = wp_get_current_user();
    $name_from_payload = $u && $u->display_name ? $u->display_name : $u->user_login;
}
$name_from_payload = ucwords(strtolower($name_from_payload));

$title = sprintf('%s (Intake – %d)', $name_from_payload, (int)$entryId);
//
    $intake_id = wp_insert_post([
        'post_title'   => $title,
        'post_type'    => 'kh_intake',
        'post_status'  => 'private',
        'post_author'  => $client_id ?: 0,
        'post_content' => '', // keep JSON only in meta to avoid editor noise
    ], true);

    if (is_wp_error($intake_id)) {
        // Fail safe: do nothing else if we couldn't create the CPT
        return;
    }

    // Save core meta
    update_post_meta($intake_id, '_kd_intake_payload', $payload);
    update_post_meta($intake_id, 'kh_client_user_id', (int)$client_id);
    update_post_meta($intake_id, '_kd_ff_entry_id', (int)$entryId);
    update_post_meta($intake_id, '_kd_ff_form_id', (int)$form->id);

    // 3) HMO / client flags from the consolidated form
    // Expecting something like client_type = 'hmo' | 'self'
    if (function_exists('WC') && WC()->session) {
        $s = WC()->session;
        $client_type = isset($payload['client_type']) ? sanitize_text_field($payload['client_type']) : '';
        if ($client_type === 'hmo') {
            $s->set('kd_client_type', 'hmo');
            $s->set('kd_hmo', 1);
            $s->set('kdc_hmo', 1);
            $s->set('kd_is_hmo', 1);
            if (!empty($payload['hmo_provider']))  $s->set('kd_hmo_provider', sanitize_text_field($payload['hmo_provider']));
            if (!empty($payload['hmo_member_id'])) $s->set('kd_hmo_member_id', sanitize_text_field($payload['hmo_member_id']));
        } else {
            // Self-pay → clear HMO flags
            $s->__unset('kd_client_type');
            $s->__unset('kd_hmo'); $s->__unset('kdc_hmo'); $s->__unset('kd_is_hmo');
            $s->__unset('kd_hmo_provider'); $s->__unset('kd_hmo_member_id');
        }

        // 4) Hand off this intake to the booking/checkout stage
        $s->set('kd_current_intake_id', (int)$intake_id);
        $s->set('kd_flow', 'consultation');
    }

    // Optionally capture email/name for CRM/order meta later
    if (!empty($payload['email']))        update_post_meta($intake_id, '_kd_contact_email', sanitize_email($payload['email']));
    if (!empty($payload['names']))        update_post_meta($intake_id, '_kd_contact_name',  sanitize_text_field($payload['names']));
    if (!empty($payload['first_name']) || !empty($payload['last_name'])) {
        $n = trim(($payload['first_name'] ?? '').' '.($payload['last_name'] ?? ''));
        if ($n) update_post_meta($intake_id, '_kd_contact_name', sanitize_text_field($n));
    }
}, 9, 3);

<?php if (!defined('ABSPATH')) exit;

/**
 * Pre-gate (FF ID KD_FFID_START)
 * Only stores HMO fields into WC session (user is logged in; name/email not needed)
 */
add_action('fluentform_submission_inserted', function($entryId, $formData, $form) {
    if ((int)($form->id ?? 0) !== (int)KD_FFID_START) return;
    if (!function_exists('WC') || !WC()->session) return;

    $f = is_array($formData) ? ($formData['fields'] ?? $formData) : [];
    $ct = strtolower(trim($f['client_type'] ?? 'private'));
    WC()->session->set('kd_client_type', in_array($ct, ['hmo','private'], true) ? $ct : 'private');

    if (!empty($f['hmo_provider']))  WC()->session->set('kd_hmo_provider',  sanitize_text_field($f['hmo_provider']));
    if (!empty($f['hmo_member_id'])) WC()->session->set('kd_hmo_member_id', sanitize_text_field($f['hmo_member_id']));

    // Cache contact (from account)
    $u = wp_get_current_user();
    $first = $u->first_name ?: ''; $last = $u->last_name ?: '';
    if (!$first && !$last) { list($first,$last) = kd_split_name($u->display_name); }
    WC()->session->set('kd_contact_first', $first);
    WC()->session->set('kd_contact_last',  $last);
    WC()->session->set('kd_contact_email', $u->user_email ?: '');
}, 10, 3);

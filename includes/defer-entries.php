<?php
if (!defined('ABSPATH')) exit;

/**
 * Service form IDs helper (only those that are defined).
 * Currently: Community (KD_FFID_COMMUNITY), Nutrition Care (KD_FFID_INTAKE)
 */
function kdcl_service_form_ids() {
    $ids = [];
    if (defined('KD_FFID_COMMUNITY')) $ids[] = (int) KD_FFID_COMMUNITY;
    if (defined('KD_FFID_INTAKE'))    $ids[] = (int) KD_FFID_INTAKE;
    return $ids;
}

/** True if a form id is one of our service forms */
function kdcl_is_service_form_id($form_id) {
    return in_array((int)$form_id, kdcl_service_form_ids(), true);
}

/**
 * After FF saves an entry, flip to pending_payment and stash in session (service forms only)
 */
add_action('fluentform/submission_inserted', function ($entryId, $formData, $form) {
    // Resolve form id (works for object or array shapes)
    $fid = 0;
    if (is_object($form) && isset($form->id)) $fid = (int) $form->id;
    if (!$fid && isset($formData['_form_id'])) $fid = (int) $formData['_form_id'];

    // If not a service form, do nothing
    if (!$fid || !kdcl_is_service_form_id($fid)) return;

    global $wpdb;
    $table = $wpdb->prefix . 'fluentform_submissions';

    // Set status to pending_payment (keeps FF clean until payment)
    $wpdb->update($table, ['status' => 'pending_payment'], ['id' => (int)$entryId], ['%s'], ['%d']);

    // Remember entry for the next order to link
    if (function_exists('WC') && WC()->session) {
        WC()->session->set('kd_pending_entry_id', (int)$entryId);
    }
}, 9, 3);

/**
 * When checkout creates an order, link the pending FF entry to that order (service only)
 */
add_action('woocommerce_checkout_create_order', function ($order, $data) {
    if (!function_exists('WC') || !WC()->session) return;
    $entry_id = (int) WC()->session->get('kd_pending_entry_id');
    if ($entry_id) {
        $order->update_meta_data('_kd_pending_entry_id', $entry_id);
    }
}, 10, 2);

/**
 * On payment success, publish the entry and store _kd_order_id meta
 */
function kdcl_publish_pending_entry($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $entry_id = (int) $order->get_meta('_kd_pending_entry_id');
    if (!$entry_id) return;

    global $wpdb;
    $subs_table  = $wpdb->prefix . 'fluentform_submissions';
    $meta_table  = $wpdb->prefix . 'fluentform_submission_meta';

    // Only promote if still pending_payment
    $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$subs_table} WHERE id = %d", $entry_id));
    if ($status !== 'pending_payment') return;

    // Publish entry
    $wpdb->update($subs_table, ['status' => 'published'], ['id' => $entry_id], ['%s'], ['%d']);

    // Link order id in FF meta
    $wpdb->insert($meta_table, [
        'submission_id' => $entry_id,
        'meta_key'      => '_kd_order_id',
        'meta_value'    => (string) $order_id
    ], ['%d','%s','%s']);

    // Clear session pointer
    if (function_exists('WC') && WC()->session) {
        WC()->session->__unset('kd_pending_entry_id');
    }
}
add_action('woocommerce_payment_complete',         'kdcl_publish_pending_entry', 10);
add_action('woocommerce_order_status_processing',  'kdcl_publish_pending_entry', 10); // sync payments safety

/**
 * On failed/cancelled/refunded orders, delete the pending entry to avoid clutter
 */
function kdcl_delete_pending_entry($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    $entry_id = (int) $order->get_meta('_kd_pending_entry_id');
    if (!$entry_id) return;

    global $wpdb;
    $subs   = $wpdb->prefix . 'fluentform_submissions';
    $det    = $wpdb->prefix . 'fluentform_entry_details';
    $meta   = $wpdb->prefix . 'fluentform_submission_meta';

    // Only delete if still pending_payment (never delete real/published entries)
    $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$subs} WHERE id = %d", $entry_id));
    if ($status !== 'pending_payment') return;

    $wpdb->delete($meta, ['submission_id' => $entry_id], ['%d']);
    $wpdb->delete($det,  ['submission_id' => $entry_id], ['%d']);
    $wpdb->delete($subs, ['id' => $entry_id],            ['%d']);
}
add_action('woocommerce_order_status_failed',    'kdcl_delete_pending_entry', 10);
add_action('woocommerce_order_status_cancelled', 'kdcl_delete_pending_entry', 10);
add_action('woocommerce_order_status_refunded',  'kdcl_delete_pending_entry', 10);

/**
 * Hourly purge: remove stale pending_payment entries older than 1 hour (no linked paid order)
 * The cron event 'kdcl_purge_stale_ff_entries' is scheduled in the main plugin on activation.
 */
add_action('kdcl_purge_stale_ff_entries', function () {
    global $wpdb;
    $subs   = $wpdb->prefix . 'fluentform_submissions';
    $det    = $wpdb->prefix . 'fluentform_entry_details';
    $meta   = $wpdb->prefix . 'fluentform_submission_meta';

    $cutoff = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$subs} WHERE status = %s AND created_at < %s",
        'pending_payment', $cutoff
    ));
    if (empty($ids)) return;

    foreach ($ids as $entry_id) {
        $wpdb->delete($meta, ['submission_id' => (int)$entry_id], ['%d']);
        $wpdb->delete($det,  ['submission_id' => (int)$entry_id], ['%d']);
        $wpdb->delete($subs, ['id' => (int)$entry_id],            ['%d']);
    }
});

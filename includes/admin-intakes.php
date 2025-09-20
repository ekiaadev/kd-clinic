<?php
if (!defined('ABSPATH')) exit;

/**
 * Diet Clinic – Admin: Pre-Consultation (Intakes)
 * - Read-only Intake UI (no Diet Plan boxes)
 * - Row actions: Review (FF), Create Consultation / Follow-up Consultation
 * - Create root or follow-up Consultation (child under the root)
 * - Manual Import: Import any FF entry into an Intake
 *
 * This file is self-contained and does NOT depend on other plugin files.
 */

/* --------------------------- UI: Clean up Intake screen --------------------------- */
// Remove any Diet Plan metaboxes from Intake screen (works regardless of their IDs)
add_action('do_meta_boxes', function($post_type, $context, $post){
    if(!$post || $post->post_type!=='kh_intake') return;
    global $wp_meta_boxes;
    if (empty($wp_meta_boxes['kh_intake'])) return;

    foreach (['side','normal','advanced'] as $ctx) {
        if (empty($wp_meta_boxes['kh_intake'][$ctx])) continue;
        foreach ($wp_meta_boxes['kh_intake'][$ctx] as $prio => $boxes) {
            foreach ((array)$boxes as $id => $box) {
                $title = isset($box['title']) ? trim($box['title']) : '';
                if ($title && stripos($title, 'diet plan') !== false) {
                    unset($wp_meta_boxes['kh_intake'][$ctx][$prio][$id]);
                }
            }
        }
    }
}, 100, 3);

/* --------------------------- Helpers (local, no external calls) --------------------------- */

/** Link to FF entry in admin */
function kdcl_ff_admin_entry_url($entry_id, $form_id) {
    $form_id  = (int) $form_id;
    $entry_id = (int) $entry_id;
    return admin_url('admin.php?page=fluent_forms&route=entries&form_id=' . $form_id . '#/entries/' . $entry_id);
}

/** Load FF payload for a submission id: prefer entry_details, fallback to submissions.response JSON */
function kdcl_load_ff_payload_for_entry($entry_id) {
    global $wpdb;
    $payload = [];

    // 1) details table
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT field_name, field_value FROM {$wpdb->prefix}fluentform_entry_details WHERE submission_id = %d", (int)$entry_id),
        ARRAY_A
    );
    foreach ((array)$rows as $r) {
        $k = isset($r['field_name']) ? sanitize_key($r['field_name']) : '';
        $v = isset($r['field_value']) ? maybe_unserialize($r['field_value']) : '';
        if ($k !== '') $payload[$k] = $v;
    }
    if (!empty($payload)) return $payload;

    // 2) submissions.response JSON (fallback)
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT response FROM {$wpdb->prefix}fluentform_submissions WHERE id = %d", (int)$entry_id),
        ARRAY_A
    );
    if (!empty($row['response'])) {
        $resp = json_decode($row['response'], true);
        if (is_array($resp)) {
            $src = (isset($resp['fields']) && is_array($resp['fields'])) ? $resp['fields'] : $resp;
            foreach ($src as $k => $v) {
                $key = sanitize_key($k);
                if ($key === '') continue;
                $payload[$key] = is_array($v) ? array_map('sanitize_text_field', $v) : sanitize_text_field((string)$v);
            }
        }
    }
    return $payload;
}

/** Best-effort full name from payload (first + last preferred; else single) */
function kdcl_best_full_name_from_payload(array $p) {
    $first = ''; $last = ''; $single = '';

    foreach (['first_name','firstname','first'] as $k) {
        if (!empty($p[$k])) { $first = is_array($p[$k]) ? implode(' ', $p[$k]) : (string)$p[$k]; break; }
    }
    foreach (['last_name','lastname','last','surname'] as $k) {
        if (!empty($p[$k])) { $last = is_array($p[$k]) ? implode(' ', $p[$k]) : (string)$p[$k]; break; }
    }
    foreach (['names','full_name','fullname','name'] as $k) {
        if (!empty($p[$k])) { $single = is_array($p[$k]) ? implode(' ', $p[$k]) : (string)$p[$k]; break; }
    }

    $first = trim($first); $last = trim($last); $single = trim($single);
    if ($first && $last) return trim($first . ' ' . $last);
    if ($single)        return $single;
    if ($first)         return $first;
    return $last ?: '';
}

/** All consultations linked to an intake */
function kdcl_get_consults_for_intake($intake_id) {
    return get_posts([
        'post_type'      => 'kh_consult',
        'post_status'    => ['private','publish','draft'],
        'meta_key'       => '_kd_consult_from_intake',
        'meta_value'     => (int)$intake_id,
        'fields'         => 'ids',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ]);
}

/** Find the root consultation (parent == 0) for an intake; return 0 if none */
function kdcl_get_root_consult_for_intake($intake_id) {
    $list = kdcl_get_consults_for_intake($intake_id);
    foreach ($list as $cid) {
        if (!wp_get_post_parent_id($cid)) return (int)$cid;
    }
    return 0;
}

/* --------------------------- Intake list: Row Actions --------------------------- */

/** Row actions for kh_intake: Review + Create/Follow-up Consultation */
add_filter('post_row_actions', function ($actions, $post) {
    if ($post->post_type !== 'kh_intake') return $actions;

    // Read-only: remove default edit/quick edit
    unset($actions['edit'], $actions['inline hide-if-no-js']);

    $entry_id = (int) get_post_meta($post->ID, '_kd_ff_entry_id', true);
    $form_id  = defined('KD_FFID_INTAKE') ? (int) KD_FFID_INTAKE : 5;

    if ($entry_id) {
        $ff_url = kdcl_ff_admin_entry_url($entry_id, $form_id);
        $actions['kd_review'] =
            '<a href="' . esc_url($ff_url) . '" target="_blank" rel="noopener">' .
            esc_html__('Open in Fluent Forms', 'kd-clinic') . '</a>';
    }

    $has_root = kdcl_get_root_consult_for_intake($post->ID) > 0;
    $label    = $has_root ? __('Follow-up Consultation', 'kd-clinic')
                          : __('Create Consultation', 'kd-clinic');

    $actions['kd_make_consult'] =
        '<a href="' . esc_url(
            wp_nonce_url(
                admin_url('admin-post.php?action=kdcl_create_consultation&intake_id=' . (int)$post->ID),
                'kdcl_make_consult_' . (int)$post->ID
            )
        ) . '">' . esc_html($label) . '</a>';

    return $actions;
}, 10, 2);

/* --------------------------- Create Consultation handler --------------------------- */

/**
 * Creates a root consultation for the Intake (if none),
 * otherwise creates a follow-up as a child of the root.
 * Prefills from Intake payload.
 */
add_action('admin_post_kdcl_create_consultation', function () {
    if (!current_user_can('edit_posts')) wp_die(__('You do not have permission.', 'kd-clinic'));

    $intake_id = isset($_GET['intake_id']) ? (int) $_GET['intake_id'] : 0;
    check_admin_referer('kdcl_make_consult_' . $intake_id);

    $intake = get_post($intake_id);
    if (!$intake || $intake->post_type !== 'kh_intake') {
        wp_die(__('Invalid intake.', 'kd-clinic'));
    }

    $root_id = kdcl_get_root_consult_for_intake($intake_id);

    if (!$root_id) {
        // Create ROOT Consultation
        $payload = (array) get_post_meta($intake_id, '_kd_intake_payload', true);
        $name    = get_post_meta($intake_id, '_kd_contact_name', true);
        if (!$name) $name = kdcl_best_full_name_from_payload($payload);
        if (!$name) $name = $intake->post_title;

        $title = sprintf(__('Consultation for %s', 'kd-clinic'), $name);

        $root_id = wp_insert_post([
            'post_title'  => $title,
            'post_type'   => 'kh_consult',
            'post_status' => 'private',
            'post_author' => (int) $intake->post_author,
        ], true);
        if (is_wp_error($root_id) || !$root_id) wp_die(__('Could not create consultation.', 'kd-clinic'));

        // Link back to intake + prefill data (store raw payload; your meta box can shape it)
        update_post_meta($root_id, '_kd_consult_from_intake', (int)$intake_id);
        update_post_meta($root_id, '_kd_consult_data', $payload);

        wp_safe_redirect(admin_url('post.php?post=' . (int)$root_id . '&action=edit'));
        exit;
    }

    // Create FOLLOW-UP as a child of root
    $title = get_the_title($root_id) . ' — ' . __('Follow-up', 'kd-clinic');

    $child_id = wp_insert_post([
        'post_title'  => $title,
        'post_type'   => 'kh_consult',
        'post_status' => 'private',
        'post_author' => (int) $intake->post_author,
        'post_parent' => (int) $root_id,
    ], true);
    if (is_wp_error($child_id) || !$child_id) wp_die(__('Could not create follow-up.', 'kd-clinic'));

    update_post_meta($child_id, '_kd_consult_from_intake', (int)$intake_id);

    wp_safe_redirect(admin_url('post.php?post=' . (int)$child_id . '&action=edit'));
    exit;
});

/* --------------------------- Manual Import: Intake from FF entry --------------------------- */

/** Add Import submenu under Pre-Consultation (kh_intake) */
add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=kh_intake',
        __('Import FF Entry', 'kd-clinic'),
        __('Import FF Entry', 'kd-clinic'),
        'edit_posts',
        'kdcl-import-ff',
        'kdcl_render_import_ff_page'
    );
}, 20);

/** Import screen */
function kdcl_render_import_ff_page() {
    if (!current_user_can('edit_posts')) wp_die(__('You do not have permission.', 'kd-clinic'));

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Import Fluent Forms Entry to Pre-Consultation', 'kd-clinic'); ?></h1>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('kdcl_import_ff'); ?>
            <input type="hidden" name="action" value="kdcl_import_ff_entry" />
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="kdcl_form_id"><?php esc_html_e('Form ID', 'kd-clinic'); ?></label></th>
                        <td><input name="form_id" id="kdcl_form_id" type="number" class="regular-text" value="<?php echo esc_attr(defined('KD_FFID_INTAKE') ? KD_FFID_INTAKE : 5); ?>" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kdcl_entry_id"><?php esc_html_e('Entry ID', 'kd-clinic'); ?></label></th>
                        <td><input name="entry_id" id="kdcl_entry_id" type="number" class="regular-text" placeholder="e.g. 78" required /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kdcl_order_id"><?php esc_html_e('Attach Order ID (optional)', 'kd-clinic'); ?></label></th>
                        <td><input name="order_id" id="kdcl_order_id" type="number" class="regular-text" placeholder="If known (shop_order id)" /></td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button(__('Import Entry', 'kd-clinic')); ?>
        </form>
    </div>
    <?php
}

/** Import handler: create Intake if not existing; otherwise open it */
add_action('admin_post_kdcl_import_ff_entry', function () {
    if (!current_user_can('edit_posts')) wp_die(__('You do not have permission.', 'kd-clinic'));
    check_admin_referer('kdcl_import_ff');

    $form_id  = isset($_POST['form_id'])  ? (int) $_POST['form_id']  : 0; // not used except for UI consistency
    $entry_id = isset($_POST['entry_id']) ? (int) $_POST['entry_id'] : 0;
    $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;

    if ($entry_id <= 0) wp_die(__('Invalid entry id.', 'kd-clinic'));

    // If already imported, open it.
    $exists = get_posts([
        'post_type'      => 'kh_intake',
        'post_status'    => ['private','publish','draft'],
        'meta_key'       => '_kd_ff_entry_id',
        'meta_value'     => (int)$entry_id,
        'fields'         => 'ids',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
    ]);
    if (!empty($exists[0])) {
        wp_safe_redirect(admin_url('post.php?post=' . (int)$exists[0] . '&action=edit'));
        exit;
    }

    $payload = kdcl_load_ff_payload_for_entry($entry_id);
    if (empty($payload)) wp_die(__('Could not load Fluent Forms entry fields.', 'kd-clinic'));

    $name = kdcl_best_full_name_from_payload($payload);
    if (!$name) $name = 'Unknown';
    $title = sprintf('%s (Intake – %d)', wp_strip_all_tags($name), (int)$entry_id);

    $intake_id = wp_insert_post([
        'post_title'  => $title,
        'post_type'   => 'kh_intake',
        'post_status' => 'private',
        'post_author' => get_current_user_id(),
    ], true);
    if (is_wp_error($intake_id) || !$intake_id) wp_die(__('Failed to create Intake.', 'kd-clinic'));

    update_post_meta($intake_id, '_kd_ff_entry_id',    (int)$entry_id);
    update_post_meta($intake_id, '_kd_intake_payload', $payload);
    update_post_meta($intake_id, '_kd_contact_name',   sanitize_text_field($name));
    if (!empty($payload['email'])) update_post_meta($intake_id, '_kd_contact_email', sanitize_email($payload['email']));
    update_post_meta($intake_id, '_kd_payment_status', 'awaiting');
    if ($order_id > 0) update_post_meta($intake_id, '_kd_order_id', (int)$order_id);

    wp_safe_redirect(admin_url('post.php?post='.(int)$intake_id.'&action=edit'));
    exit;
});

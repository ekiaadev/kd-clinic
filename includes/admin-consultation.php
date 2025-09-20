<?php
if (!defined('ABSPATH')) exit;

/** keep your existing meta box renderer function if already present */
if (!function_exists('kdcl_render_consult_meta_box')) {
    function kdcl_render_consult_meta_box($post) {
        $data = (array) get_post_meta($post->ID, '_kd_consult_data', true);
        wp_nonce_field('kdcl_save_consult_'.$post->ID, 'kdcl_consult_nonce');
        echo '<table class="form-table"><tbody>';
        foreach ($data as $k=>$v) {
            echo '<tr><th><label for="'.esc_attr($k).'">'.esc_html(ucwords(str_replace('_',' ',$k))).'</label></th>';
            if (is_array($v)) $v = implode(', ', $v);
            echo '<td><input type="text" class="regular-text" name="kdcl_consult['.esc_attr($k).']" id="'.esc_attr($k).'" value="'.esc_attr($v).'"></td></tr>';
        }
        echo '</tbody></table>';
    }
}

add_action('add_meta_boxes', function () {
    add_meta_box('kdcl_consult_core', __('Consultation Details','kd-clinic'), 'kdcl_render_consult_meta_box', 'kh_consult', 'normal', 'high');
});

/** Save handler stays simple (only updates keys that exist already) */
add_action('save_post_kh_consult', function ($post_id) {
    if (!isset($_POST['kdcl_consult_nonce']) || !wp_verify_nonce($_POST['kdcl_consult_nonce'], 'kdcl_save_consult_'.$post_id)) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post',$post_id)) return;

    $incoming = isset($_POST['kdcl_consult']) && is_array($_POST['kdcl_consult']) ? $_POST['kdcl_consult'] : [];
    $current  = (array) get_post_meta($post_id, '_kd_consult_data', true);
    foreach ($incoming as $k=>$v) { $current[$k] = is_array($v) ? array_map('sanitize_text_field',$v) : sanitize_text_field($v); }
    update_post_meta($post_id, '_kd_consult_data', $current);
}, 10, 1);

/** Row action: Follow-up Consultation */
add_filter('post_row_actions', function ($actions, $post) {
    if ($post->post_type !== 'kh_consult') return $actions;
    $actions['kd_followup'] =
        '<a href="' . esc_url(
            wp_nonce_url(admin_url('admin-post.php?action=kdcl_followup_consult&consult_id='.(int)$post->ID), 'kdcl_followup_'.(int)$post->ID)
        ) . '">' . esc_html__('Follow-up Consultation','kd-clinic') . '</a>';
    return $actions;
}, 10, 2);

add_action('admin_post_kdcl_followup_consult', function () {
    if (!current_user_can('edit_posts')) wp_die(__('You do not have permission.','kd-clinic'));
    $consult_id = isset($_GET['consult_id']) ? (int)$_GET['consult_id'] : 0;
    check_admin_referer('kdcl_followup_' . $consult_id);

    $consult = get_post($consult_id);
    if (!$consult || $consult->post_type !== 'kh_consult') wp_die(__('Invalid consultation.','kd-clinic'));

    // find root ancestor
    $root_id = $consult_id;
    while ($p = wp_get_post_parent_id($root_id)) { $root_id = $p; }

    $title = get_the_title($root_id) . ' — ' . __('Follow-up','kd-clinic');
    $child_id = wp_insert_post([
        'post_title'  => $title,
        'post_type'   => 'kh_consult',
        'post_status' => 'private',
        'post_author' => (int)$consult->post_author,
        'post_parent' => (int)$root_id,
    ], true);
    if (is_wp_error($child_id) || !$child_id) wp_die(__('Could not create follow-up.','kd-clinic'));

    // carry intake link
    $intake_id = (int) get_post_meta($consult_id, '_kd_consult_from_intake', true);
    if ($intake_id) update_post_meta($child_id, '_kd_consult_from_intake', $intake_id);

    wp_safe_redirect(admin_url('post.php?post='.(int)$child_id.'&action=edit')); exit;
});

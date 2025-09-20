<?php
if (!defined('ABSPATH')) exit;

/** Add the main meta box */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'kdcl_consult_core',
        __('Consultation Details', 'kd-clinic'),
        'kdcl_render_consult_meta_box',
        'kh_consult',
        'normal',
        'high'
    );
});

function kdcl_get_consult_schema() {
    return [
        'client_name'        => __('Client Name', 'kd-clinic'),
        'gender'             => __('Gender', 'kd-clinic'),
        'age'                => __('Age', 'kd-clinic'),
        'height'             => __('Height', 'kd-clinic'),
        'weight'             => __('Weight', 'kd-clinic'),
        'bmi'                => __('BMI', 'kd-clinic'),
        'history'            => __('Medical History', 'kd-clinic'),
        'presenting'         => __('Presenting Complaint', 'kd-clinic'),
        'family_history'     => __('Family History', 'kd-clinic'),
        'activity_level'     => __('Physical Activity Level', 'kd-clinic'),
        'medications'        => __('Current Medications/Supplements', 'kd-clinic'),

        // Biochemical (sample aligned)
        'bio_na'             => __('Biochemical: Sodium (Na)', 'kd-clinic'),
        'bio_cl'             => __('Chloride (Cl)', 'kd-clinic'),
        'bio_urea'           => __('Urea', 'kd-clinic'),
        'bio_creatinine'     => __('Creatinine', 'kd-clinic'),
        'bio_egfr'           => __('eGFR', 'kd-clinic'),
        'bio_hdl'            => __('HDL', 'kd-clinic'),
        'bio_ldl'            => __('LDL', 'kd-clinic'),
        'bio_trigs'          => __('Triglycerides', 'kd-clinic'),

        // Diet history
        'diet_breakfast'     => __('Usual Breakfast', 'kd-clinic'),
        'diet_lunch'         => __('Usual Lunch', 'kd-clinic'),
        'diet_dinner'        => __('Usual Dinner', 'kd-clinic'),
        'recall_24h'         => __('24-hour Dietary Recall', 'kd-clinic'),
        'food_prediction'    => __('Predicted/Recommended Foods', 'kd-clinic'),

        // Assessment
        'diagnosis'          => __('Nutrition Diagnosis', 'kd-clinic'),
        'intervention'       => __('Nutrition Intervention/Plan', 'kd-clinic'),
        'notes'              => __('Dietitian Notes', 'kd-clinic'),
    ];
}

function kdcl_render_consult_meta_box($post) {
    $schema  = kdcl_get_consult_schema();
    $data    = (array)get_post_meta($post->ID, '_kd_consult_data', true);
    $intake  = (int)get_post_meta($post->ID, '_kd_consult_from_intake', true);

    wp_nonce_field('kdcl_save_consult_' . $post->ID, 'kdcl_consult_nonce');

    echo '<div class="kdcl-consult-wrap">';
    if ($intake) {
        echo '<p><strong>' . esc_html__('Source intake:', 'kd-clinic') . '</strong> ';
        echo '<a href="' . esc_url(admin_url('post.php?post=' . $intake . '&action=edit')) . '">#' . (int)$intake . '</a></p>';
    }

    echo '<table class="form-table"><tbody>';
    foreach ($schema as $key => $label) {
        $val = isset($data[$key]) ? $data[$key] : '';
        echo '<tr>';
        echo '<th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th>';
        echo '<td>';
        if (in_array($key, ['history','presenting','family_history','activity_level','medications','recall_24h','food_prediction','diagnosis','intervention','notes'], true)) {
            echo '<textarea id="' . esc_attr($key) . '" name="kdcl_consult[' . esc_attr($key) . ']" rows="4" class="large-text">' . esc_textarea($val) . '</textarea>';
        } else {
            echo '<input type="text" id="' . esc_attr($key) . '" name="kdcl_consult[' . esc_attr($key) . ']" value="' . esc_attr($val) . '" class="regular-text" />';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}

/** Save handler */
add_action('save_post_kh_consult', function ($post_id) {
    if (!isset($_POST['kdcl_consult_nonce']) || !wp_verify_nonce($_POST['kdcl_consult_nonce'], 'kdcl_save_consult_' . $post_id)) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $schema   = kdcl_get_consult_schema();
    $incoming = isset($_POST['kdcl_consult']) && is_array($_POST['kdcl_consult']) ? $_POST['kdcl_consult'] : [];
    $clean    = [];
    foreach ($schema as $key => $_label) {
        $val = isset($incoming[$key]) ? $incoming[$key] : '';
        $clean[$key] = is_array($val) ? array_map('sanitize_text_field', $val) : sanitize_text_field($val);
    }
    update_post_meta($post_id, '_kd_consult_data', $clean);
}, 10, 1);

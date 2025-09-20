<?php
// kd-clinic/includes/admin-metaboxes.php

if (!defined('ABSPATH')) exit;

add_action('add_meta_boxes', function () {
    add_meta_box(
        'kdcl_intake_actions',
        __('Diet Plan Actions', 'kd-clinic'),
        function ($post) {
            if ($post->post_type !== 'kh_intake') return;
            if (!current_user_can('read_post', $post->ID)) return;
            $nonce = wp_create_nonce('kdcl_intake_action_' . $post->ID);
            $create_url = admin_url('admin-post.php?action=kdcl_create_plan&intake_id=' . $post->ID . '&_wpnonce=' . $nonce);
            $ai_url     = admin_url('admin-post.php?action=kdcl_ai_draft_plan&intake_id=' . $post->ID . '&_wpnonce=' . $nonce);
            echo '<p><a class="button button-primary" href="'.esc_url($create_url).'">'.esc_html__('Create Plan from Intake', 'kd-clinic').'</a></p>';
            echo '<p><a class="button" href="'.esc_url($ai_url).'">'.esc_html__('Generate AI Draft Plan', 'kd-clinic').'</a></p>';
        },
        'kh_intake',
        'side',
        'high'
    );
});

// Intakes don't need the WP content editor
add_action('init', function () {
    remove_post_type_support('kh_intake', 'editor');
});


// Submitted Data (read-only pretty view)
// === Submitted Data (read-only; handles uploads) ===
add_action('add_meta_boxes', function () {
    add_meta_box(
        'kdcl_intake_payload',
        __('Submitted Data', 'kd-clinic'),
        function ($post) {
            if ($post->post_type !== 'kh_intake') return;
            if (!current_user_can('read_post', $post->ID)) {
                echo '<p>'.esc_html__('You do not have permission to view this data.', 'kd-clinic').'</p>';
                return;
            }

            // primary key used by your intake-save
            $keys = ['_kd_intake_payload','kh_intake_payload','kd_intake_payload','ff_submission','ff5_submission'];
            $payload = [];
            foreach ($keys as $k) {
                $raw = get_post_meta($post->ID, $k, true);
                if ($raw) {
                    if (is_array($raw)) { $payload = $raw; break; }
                    $j = json_decode(is_string($raw) ? $raw : '', true);
                    if (is_array($j)) { $payload = $j; break; }
                }
            }
            if (!$payload && $post->post_content) {
                $j = json_decode($post->post_content, true);
                if (is_array($j)) $payload = $j;
            }

            if (!$payload) { echo '<p>'.esc_html__('No structured payload found.', 'kd-clinic').'</p>'; return; }

            echo '<table class="widefat striped"><tbody>';
            foreach ($payload as $key => $val) {
                $label = ucwords(str_replace(['_','-'], ' ', (string)$key));
                echo '<tr><th style="width:260px">'.esc_html($label).'</th><td>';
                if (is_array($val)) {
                    foreach ($val as $item) echo kdcl_render_payload_value($item).'<br />';
                } else {
                    echo kdcl_render_payload_value($val);
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        },
        'kh_intake',
        'normal',
        'high'
    );
}, 20);

// helper for uploads/urls/scalars
if (!function_exists('kdcl_render_payload_value')) {
    function kdcl_render_payload_value($v) {
        // Attachment ID?
        if (is_numeric($v)) {
            $url = wp_get_attachment_url((int)$v);
            if ($url) {
                $name = basename(parse_url($url, PHP_URL_PATH));
                $type = get_post_mime_type((int)$v);
                $suffix = $type ? ' <span class="muted">('.esc_html($type).')</span>' : '';
                return '<a href="'.esc_url($url).'" target="_blank" rel="noopener">'.esc_html($name).'</a>'.$suffix;
            }
        }
        // URL?
        if (is_string($v) && preg_match('~^https?://~i', $v)) {
            $path = parse_url($v, PHP_URL_PATH);
            $name = $path ? basename($path) : $v;
            return '<a href="'.esc_url($v).'" target="_blank" rel="noopener">'.esc_html($name).'</a>';
        }
        // JSON string pretty print?
        if (is_string($v) && ($j = json_decode($v, true)) && is_array($j)) {
            return '<pre style="white-space:pre-wrap;margin:0">'.esc_html(json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';
        }
        // Scalar / arrays
        if (is_array($v)) {
            $out = [];
            foreach ($v as $item) $out[] = strip_tags(kdcl_render_payload_value($item));
            return nl2br(esc_html(implode(", ", $out)));
        }
        return nl2br(esc_html(is_scalar($v) ? (string)$v : print_r($v, true)));
    }
}

// Fluent Entry quick access box
add_action('add_meta_boxes', function () {
    add_meta_box(
        'kdcl_fluent_entry_link',
        __('Fluent Entry', 'kd-clinic'),
        function ($post) {
            if ($post->post_type !== 'kh_intake') return;
            $ff_form  = (int) (get_post_meta($post->ID, '_kd_ff_form_id', true) ?: (defined('KD_FFID_INTAKE') ? KD_FFID_INTAKE : 0));
            $ff_entry = (int) get_post_meta($post->ID, '_kd_ff_entry_id', true);
            if (!$ff_form || !$ff_entry) {
                echo '<p>'.esc_html__('No linked Fluent Forms entry found.', 'kd-clinic').'</p>';
                return;
            }
            $ff_url = admin_url('admin.php?page=fluent_forms&route=entries&form_id='.$ff_form.'#/entries/'.$ff_entry);
            echo '<a class="button button-primary" href="'.esc_url($ff_url).'" target="_blank" rel="noopener">'.esc_html__('Open in Fluent Forms', 'kd-clinic').'</a>';
        },
        'kh_intake',
        'side',
        'high'
    );
});

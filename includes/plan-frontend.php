<?php
if (!defined('ABSPATH')) exit;

/** Gate single kh_plan: client (kh_client_user_id) or staff (read_kh_intake) */
add_action('template_redirect', function () {
    if (!is_singular('kh_plan')) return;

    $plan_id   = get_queried_object_id();
    $client_id = (int) get_post_meta($plan_id, 'kh_client_user_id', true);

    $allowed = false;
    if (is_user_logged_in()) {
        $uid = get_current_user_id();
        if ($client_id && $uid === $client_id) $allowed = true;
        if (!$allowed && current_user_can('read_kh_intake')) $allowed = true;
    }

    if (!$allowed) {
        global $wp_query;
        $wp_query->set_404(); status_header(404); nocache_headers(); exit;
    }
}, 1);

/** Elementor Single template support */
add_action('init', function () {
    add_post_type_support('kh_plan', 'elementor');
});

/** Download PDF: /plan-url/?download=pdf */
add_action('template_redirect', function () {
    if (!is_singular('kh_plan')) return;
    if (!isset($_GET['download']) || $_GET['download'] !== 'pdf') return;

    $plan_id   = get_queried_object_id();
    $client_id = (int) get_post_meta($plan_id, 'kh_client_user_id', true);
    $allowed = (is_user_logged_in() && (get_current_user_id() === $client_id || current_user_can('read_kh_intake')));
    if (!$allowed) wp_die(__('You are not allowed to download this plan.', 'kd-clinic'), 403);

    // Render HTML (prefer Elementor)
    $html = '';
    if (did_action('elementor/loaded') && class_exists('\Elementor\Plugin')) {
        $html = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display($plan_id);
    }
    if (!$html) {
        $post = get_post($plan_id);
        $html = apply_filters('the_content', $post ? $post->post_content : '');
    }

    $html = '<html><head><meta charset="utf-8"><style>
        body{font-family: DejaVu Sans, Arial, sans-serif; font-size:12px; color:#222;}
        h1,h2,h3{margin:18px 0 8px;}
        table{width:100%; border-collapse:collapse; margin:10px 0;}
        th,td{border:1px solid #ddd; padding:8px; vertical-align:top;}
        .muted{color:#666;}
    </style></head><body>'.$html.'</body></html>';

    if (!class_exists('\Dompdf\Dompdf')) {
        wp_die(__('PDF engine not installed yet (Dompdf). Please install dompdf/dompdf via Composer.', 'kd-clinic'));
    }

    $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4');
    $dompdf->render();

    $filename = sanitize_file_name(get_the_title($plan_id) ?: 'nutrition-plan').'.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo $dompdf->output();
    exit;
}, 20);

<?php if (!defined('ABSPATH')) exit;

add_action('init', function () {
    // EXISTING
    register_post_type('kh_intake', [
        'label'           => 'Pre-Consultation',
        'public'          => false,
        'show_ui'         => true,
        'supports'        => ['title','editor','custom-fields','author'],
        'capability_type' => ['kh_intake','kh_intakes'],
        'map_meta_cap'    => true,
        'menu_icon'       => 'dashicons-forms',
        // bind under Diet Clinic
        'show_in_menu'    => 'kdc-diet-clinic',
    ]);

    // NEW — Diet Plans CPT (needed by plan-create.php)
    register_post_type('kh_plan', [
        'label'           => 'Diet Plans',
        'public'          => false,
        'show_ui'         => true,
        'supports'        => ['title','editor','custom-fields','author'],
        'capability_type' => ['kh_plan','kh_plans'],
        'map_meta_cap'    => true,
        'menu_icon'       => 'dashicons-portfolio',
        // bind under Diet Clinic
        'show_in_menu'    => 'kdc-diet-clinic',
    ]);
});

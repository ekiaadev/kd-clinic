<?php if (!defined('ABSPATH')) exit;

add_action('init', function () {
    // EXISTING
    register_post_type('kh_intake', [
        'label'           => 'Pre-Consultation',
        'labels'          => [ 'name' => 'Pre-Consultation', 'singular_name' => 'Intake' ],
        'public'          => false,
        'show_ui'         => true,
        'show_in_menu'    => 'kdc-diet-clinic',
        'supports'        => ['title','author'],
        'map_meta_cap'    => true,
        'capability_type' => ['kh_intake','kh_intakes'],
        'capabilities'    => [
            'publish_posts'       => 'publish_kh_intakes',
            'edit_posts'          => 'edit_kh_intakes',
            'edit_others_posts'   => 'edit_others_kh_intakes',
            'edit_private_posts'  => 'edit_private_kh_intakes',
            'edit_published_posts'=> 'edit_published_kh_intakes',
            'read_private_posts'  => 'read_private_kh_intakes',
            'delete_posts'        => 'delete_kh_intakes',
            'delete_private_posts'=> 'delete_private_kh_intakes',
            'delete_published_posts'=>'delete_published_kh_intakes',
            'delete_others_posts' => 'delete_others_kh_intakes',
            'read_post'           => 'read_kh_intake',
            'edit_post'           => 'edit_kh_intake',
            'delete_post'         => 'delete_kh_intake',
        ],
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

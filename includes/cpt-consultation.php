<?php
if (!defined('ABSPATH')) exit;

add_action('init', function () {
    $labels = [
        'name'          => __('Consultations','kd-clinic'),
        'singular_name' => __('Consultation','kd-clinic'),
        'menu_name'     => __('Consultations','kd-clinic'),
        'all_items'     => __('Consultations','kd-clinic'),
        'add_new'       => __('Add Consultation','kd-clinic'),
        'add_new_item'  => __('Add New Consultation','kd-clinic'),
        'edit_item'     => __('Edit Consultation','kd-clinic'),
    ];
    register_post_type('kh_consult', [
        'labels'        => $labels,
        'public'        => false,
        'show_ui'       => true,
        'show_in_menu'  => false,
        'capability_type'=> 'post',
        'map_meta_cap'  => true,
        'supports'      => ['title','page-attributes'],
        'hierarchical'  => true,
        'has_archive'   => false,
        'rewrite'       => false,
    ]);
});

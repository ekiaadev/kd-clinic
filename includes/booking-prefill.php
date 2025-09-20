<?php
if (!defined('ABSPATH')) exit;

add_action('wp_enqueue_scripts', function(){
    // Works on both Nutrition booking & any other booking URL you defined in helpers.php
    if (!function_exists('kd_is_booking_page') || !kd_is_booking_page()) return;

    $h='kdcl-booking-prefill';
    wp_register_script($h,'',[], '1.0', true);

    $d=['name'=>'','email'=>'','phone'=>''];
    if(function_exists('WC') && WC()->session){
        $d['name'] =(string)WC()->session->get('kd_contact_name');
        $d['email']=(string)WC()->session->get('kd_contact_email');
        $d['phone']=(string)WC()->session->get('kd_contact_phone');
    }

    wp_enqueue_script($h);
    wp_add_inline_script($h,'try{(function(d){function setV(q,v){if(!v)return;var e=document.querySelector(q);if(e&&!e.value){e.value=v;e.dispatchEvent(new Event("input",{bubbles:true}));}}
        setV("input[name*=name]", d.name);
        setV("input[type=email],input[name*=email]", d.email);
        setV("input[type=tel],input[name*=phone]", d.phone);
    })('.wp_json_encode($d).')}catch(e){}');
},20);

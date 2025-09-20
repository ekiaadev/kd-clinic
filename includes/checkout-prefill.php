<?php
if (!defined('ABSPATH')) exit;

function kdcl_split_name_local($name){
    $name=trim((string)$name); if($name==='') return ['first'=>'','last'=>''];
    $p=preg_split('/\s+/', $name); $first=array_shift($p); $last=implode(' ',$p);
    return ['first'=>$first,'last'=>$last];
}

add_filter('woocommerce_checkout_fields', function($fields){
    if(!function_exists('WC') || !WC()->session) return $fields;
    $nm=(string)WC()->session->get('kd_contact_name');
    $em=(string)WC()->session->get('kd_contact_email');
    $ph=(string)WC()->session->get('kd_contact_phone');
    $sp=kdcl_split_name_local($nm);
    $fields['billing']['billing_first_name']['default']= $sp['first'] ?: ($fields['billing']['billing_first_name']['default'] ?? '');
    $fields['billing']['billing_last_name']['default'] = $sp['last']  ?: ($fields['billing']['billing_last_name']['default']  ?? '');
    $fields['billing']['billing_email']['default']     = $em ?: ($fields['billing']['billing_email']['default'] ?? '');
    $fields['billing']['billing_phone']['default']     = $ph ?: ($fields['billing']['billing_phone']['default'] ?? '');
    return $fields;
}, 9);

add_filter('woocommerce_checkout_get_value', function($value,$input){
    if(!function_exists('WC') || !WC()->session) return $value;
    $nm=(string)WC()->session->get('kd_contact_name');
    $em=(string)WC()->session->get('kd_contact_email');
    $ph=(string)WC()->session->get('kd_contact_phone');
    $sp=kdcl_split_name_local($nm);
    switch($input){
        case 'billing_first_name':
        case 'account_first_name': return $sp['first'] ?: $value;
        case 'billing_last_name':
        case 'account_last_name':  return $sp['last']  ?: $value;
        case 'billing_email':
        case 'account_email':      return $em ?: $value;
        case 'billing_phone':      return $ph ?: $value;
        default: return $value;
    }
}, 9, 2);

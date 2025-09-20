<?php
if (!defined('ABSPATH')) exit;

/**
 * Community + Nutrition Care flows
 * - Community (FF ID 10): add *specific* variation based on plan_dropdown → redirect to Checkout (AJAX-safe)
 * - Nutrition Care (FF ID 5): redirect to booking page; booking product then applies coupon automatically
 *
 * Variation resolution here is EXACT to your data:
 * plan_dropdown values: 1m | 3m | 6m | 12m
 * Woo attribute "Plan" values (slugs): 1-month | 3-months | 6-months | 12-months
 */

function kdcl_normalize_client_type($raw) {
    $v = is_string($raw) ? strtolower(trim($raw)) : '';
    $map = [
        'direct'        => 'direct',
        'hmo'           => 'hmo',
        'bank_union'    => 'bank_union',
        'union bank'    => 'bank_union',
        'bank_providus' => 'bank_providus',
        'providus bank' => 'bank_providus',
    ];
    return $map[$v] ?? $v;
}

/** Get a field from Fluent Forms payload (and $_POST fallback). */
function kdcl_ffv($formData, $key) {
    if (isset($formData[$key])) return $formData[$key];
    if (isset($formData['fields'][$key])) return $formData['fields'][$key];
    if (isset($_POST[$key])) return wp_unslash($_POST[$key]);
    return '';
}

/** Map FF plan code → Woo variation slug + nice label */
function kdcl_map_plan_code($code) {
    $code = strtolower(trim((string)$code));
    $map = [
        '1m'  => ['slug' => '1-month',  'label' => '1 Month'],
        '3m'  => ['slug' => '3-months', 'label' => '3 Months'],
        '6m'  => ['slug' => '6-months', 'label' => '6 Months'],
        '12m' => ['slug' => '12-months','label' => '12 Months'],
    ];
    return $map[$code] ?? ['slug' => '', 'label' => ''];
}

/**
 * Find variation for the given plan slug.
 * Checks both global attr (pa_plan) and custom attr (plan).
 *
 * @return array [variation_id, variation_attributes]
 */
function kdcl_find_variation_by_plan_slug($product, $plan_slug) {
    if (!$product || !$product->is_type('variable')) return [0, []];
    $plan_slug = sanitize_title($plan_slug);
    if (!$plan_slug) return [0, []];

    $variations = $product->get_available_variations();
    if (empty($variations)) return [0, []];

    foreach ($variations as $var) {
        $attrs = $var['attributes'] ?? [];
        // Attribute keys come like attribute_pa_plan or attribute_plan
        $val = '';
        if (isset($attrs['attribute_pa_plan'])) {
            $val = $attrs['attribute_pa_plan'];
        } elseif (isset($attrs['attribute_plan'])) {
            $val = $attrs['attribute_plan'];
        }
        if ($val && sanitize_title($val) === $plan_slug) {
            return [$var['variation_id'], $attrs];
        }
    }

    // No exact match found
    return [0, []];
}

/**
 * After FF saves entry, set session client_type and perform per-form action.
 * No server redirects here—AJAX response handles redirection.
 */
add_action('fluentform/submission_inserted', function ($entryId, $formData, $form) {
    $form_id = 0;
    if (is_object($form) && isset($form->id)) $form_id = (int)$form->id;
    if (!$form_id && isset($formData['_form_id'])) $form_id = (int)$formData['_form_id'];
    if (!$form_id) return;

    $client_type = kdcl_normalize_client_type(kdcl_ffv($formData, 'client_type'));

    if (function_exists('WC') && WC()->session) {
        WC()->session->set('kd_client_type', $client_type ?: 'direct');
        WC()->session->set('kd_ff_entry_id', (int)$entryId); // NEW: store entry id
    }

    // COMMUNITY → clear cart → add the exact variation chosen via plan_dropdown
    if ($form_id === (int) (defined('KD_FFID_COMMUNITY') ? KD_FFID_COMMUNITY : 0)) {
        if (!function_exists('WC')) return;
        if (!WC()->cart) wc_load_cart();

        // Silent hard clear before adding the service
        WC()->cart->empty_cart(true);

        $product_id = (int) (defined('KD_COMM_PRODUCT_ID') ? KD_COMM_PRODUCT_ID : 0);
        if ($product_id <= 0) return;

        $product = wc_get_product($product_id);
        if (!$product) return;

        // Exact mapping from FF "plan_dropdown" → variation slug
        $code = kdcl_ffv($formData, 'plan_dropdown');
        $map  = kdcl_map_plan_code($code);

        $variation_id = 0; $variation_attrs = [];
        if ($product->is_type('variable')) {
            if (!empty($map['slug'])) {
                list($variation_id, $variation_attrs) = kdcl_find_variation_by_plan_slug($product, $map['slug']);
            }
            // If still not found, fall back to product default variation (to avoid empty cart)
            if (!$variation_id) {
                $variations = $product->get_available_variations();
                $defaults   = $product->get_default_attributes();
                if (!empty($defaults)) {
                    foreach ($variations as $var) {
                        $attrs = $var['attributes'] ?? [];
                        $ok = true;
                        foreach ($defaults as $k => $v) {
                            $key = 'attribute_' . $k;
                            if (!isset($attrs[$key]) || sanitize_title($attrs[$key]) !== sanitize_title($v)) { $ok = false; break; }
                        }
                        if ($ok) { $variation_id = $var['variation_id']; $variation_attrs = $attrs; break; }
                    }
                }
                // Final fallback: first available
                if (!$variation_id && !empty($variations[0]['variation_id'])) {
                    $variation_id   = $variations[0]['variation_id'];
                    $variation_attrs= ($variations[0]['attributes'] ?? []);
                }
            }
        }

        $added_key = WC()->cart->add_to_cart(
            $product_id,
            1,
            $variation_id,
            $variation_attrs,
            ['kd_client_type' => $client_type ?: 'direct']
        );

        if ($added_key && WC()->session) {
            WC()->session->set('kd_ff_last_form', 'community');
        }
    }

    // NUTRITION CARE → mark for redirect to booking page
    if ($form_id === (int) (defined('KD_FFID_INTAKE') ? KD_FFID_INTAKE : 0) && function_exists('WC') && WC()->session) {
        WC()->session->set('kd_ff_last_form', 'nutrition');
    }
}, 10, 3);

/**
 * AJAX response redirect:
 *  - Community  → Checkout
 *  - Nutrition  → Nutrition booking URL
 */
add_filter('fluentform_submission_response', function ($response, $formData, $form) {
    $form_id = 0;
    if (is_object($form) && isset($form->id)) $form_id = (int)$form->id;
    if (!$form_id && isset($formData['_form_id'])) $form_id = (int)$formData['_form_id'];
    if (!$form_id || !function_exists('WC') || !WC()->session) return $response;

    $flag = WC()->session->get('kd_ff_last_form');
    if (!$flag) return $response;

    if ($flag === 'community' && $form_id === (int) (defined('KD_FFID_COMMUNITY') ? KD_FFID_COMMUNITY : 0)) {
        $target = wc_get_checkout_url();
    } elseif ($flag === 'nutrition' && $form_id === (int) (defined('KD_FFID_INTAKE') ? KD_FFID_INTAKE : 0)) {
        $target = home_url(defined('KD_NUTRI_BOOKING_URL') ? KD_NUTRI_BOOKING_URL : '/');
    } else {
        return $response;
    }

    WC()->session->__unset('kd_ff_last_form');

    $response['result']     = 'success';
    $response['message']    = isset($response['message']) ? $response['message'] : __('Redirecting…', 'kd-clinic');
    $response['redirectTo'] = esc_url_raw($target);
    return $response;
}, 10, 3);

/** Stamp client_type onto cart items for safety (coupons read this if session is lost) */
add_filter('woocommerce_add_cart_item_data', function ($cart_item_data, $product_id, $variation_id) {
    if (function_exists('WC') && WC()->session) {
        $t = WC()->session->get('kd_client_type');
        if ($t) $cart_item_data['kd_client_type'] = $t;
    }
    return $cart_item_data;
}, 10, 3);

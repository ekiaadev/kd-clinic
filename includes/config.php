<?php
if (!defined('ABSPATH')) exit;

/** Fluent Forms IDs */
if (!defined('KD_FFID_COMMUNITY')) define('KD_FFID_COMMUNITY', 10); // Community (no booking)
if (!defined('KD_FFID_INTAKE'))    define('KD_FFID_INTAKE', 5);     // Nutrition Care

/** Booking page (Nutrition Care only) */
if (!defined('KD_NUTRI_BOOKING_URL')) define('KD_NUTRI_BOOKING_URL', '/services/booking');

/** Woo products */
if (!defined('KD_COMM_PRODUCT_ID'))    define('KD_COMM_PRODUCT_ID', 1365); // Community (variable)
if (!defined('KD_CONSULT_PRODUCT_ID')) define('KD_CONSULT_PRODUCT_ID', 1316); // Nutrition Care booking payment product

/** Service category slug (all service products must be in this category) */
if (!defined('KD_PAID_CAT_SLUG')) define('KD_PAID_CAT_SLUG', 'paid-services');

/** Coupon codes */
if (!defined('KD_COUPON_HMO'))      define('KD_COUPON_HMO', 'KD-HMO');         // 100%
if (!defined('KD_COUPON_UNION'))    define('KD_COUPON_UNION', 'KD-UNION');     // 40%
if (!defined('KD_COUPON_PROVIDUS')) define('KD_COUPON_PROVIDUS', 'KD-PROVIDUS'); // 30%

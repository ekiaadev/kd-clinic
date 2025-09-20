<?php if (!defined('ABSPATH')) exit;

/* === FORM & PAGE IDS === */
//if (!defined('KD_FFID_START'))        define('KD_FFID_START', 6);      // Pre-gate FF
if (!defined('KD_FFID_INTAKE'))       define('KD_FFID_INTAKE', 5);     // Intake FF
if (!defined('KD_FFID_COMMUNITY'))    define('KD_FFID_COMMUNITY', 10); // Community FF
if (!defined('KD_FFID_LOSE'))         define('KD_FFID_LOSE', 9);       // Lose A Dress FF

if (!defined('KD_BOOKING_URL'))       define('KD_BOOKING_URL', '/availability/');
if (!defined('KD_INTAKE_URL'))        define('KD_INTAKE_URL',  '/consultation/');

/* === PRODUCTS === */
if (!defined('KD_CONSULT_PRODUCT_ID')) define('KD_CONSULT_PRODUCT_ID', 1316);
if (!defined('KD_COMM_PRODUCT_ID'))    define('KD_COMM_PRODUCT_ID',    1365); // variable OR use mapping
if (!defined('KD_LOSE_PRODUCT_ID'))    define('KD_LOSE_PRODUCT_ID',    1373);

/* === LANDING PAGES === */
if (!defined('KD_COMM_WELCOME_URL')) define('KD_COMM_WELCOME_URL', '/services/thank-you/');
if (!defined('KD_LOSE_WELCOME_URL')) define('KD_LOSE_WELCOME_URL', '/services/thank-you/');

/* === POLICIES === */
if (!defined('KD_PAID_CAT_SLUG'))      define('KD_PAID_CAT_SLUG', 'paid-services');
if (!defined('KD_CLEAR_CART_ON_FORM')) define('KD_CLEAR_CART_ON_FORM', true);

/* === Toggles (optional) === */
if (!defined('KD_ENABLE_BOOKING_PREFILL'))  define('KD_ENABLE_BOOKING_PREFILL',  true);
if (!defined('KD_ENABLE_CHECKOUT_PREFILL')) define('KD_ENABLE_CHECKOUT_PREFILL', true);

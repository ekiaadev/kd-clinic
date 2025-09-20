<?php if (!defined('ABSPATH')) exit;

if (!function_exists('kd_path_match')) {
    function kd_path_match($req_uri, $target){
        $path = parse_url($req_uri, PHP_URL_PATH) ?: $req_uri;
        return (stripos(untrailingslashit($path), untrailingslashit($target)) !== false);
    }
}
if (!function_exists('kd_is_booking_page')) {
    function kd_is_booking_page(){ $req = esc_url_raw($_SERVER['REQUEST_URI'] ?? ''); return kd_path_match($req, KD_BOOKING_URL); }
}
if (!function_exists('kd_is_intake_page')) {
    function kd_is_intake_page(){  $req = esc_url_raw($_SERVER['REQUEST_URI'] ?? ''); return kd_path_match($req, KD_INTAKE_URL); }
}
if (!function_exists('kd_split_name')) {
    function kd_split_name($name) {
        $name = trim(preg_replace('/\s+/', ' ', (string)$name));
        if ($name === '') return ['', ''];
        $parts = explode(' ', $name);
        $first = array_shift($parts);
        $last  = implode(' ', $parts);
        return [$first, $last];
    }
}

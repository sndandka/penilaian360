<?php
/**
 * Manajemen Session & CORS
 * Semua endpoint API meng-include file ini di awal.
 *
 * PERUBAHAN TAHAP 2 (Keamanan):
 * - Cookie session diberi flag HttpOnly, SameSite, dan Secure (otomatis
 *   aktif jika diakses via HTTPS) untuk mengurangi risiko session hijacking
 *   & XSS cookie theft.
 * - session_regenerate_id() dipanggil terpisah saat login berhasil
 *   (lihat auth.php) untuk mencegah session fixation.
 */

// Cegah output warning/notice PHP merusak format JSON API di server produksi
ini_set('display_errors', '0');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false)
    || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
    || (!empty($_SERVER['HTTP_ORIGIN']) && stripos($_SERVER['HTTP_ORIGIN'], 'https://') === 0);

$originHeader = $_SERVER['HTTP_ORIGIN'] ?? '';
$requestHost  = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '');
$originHost   = $originHeader !== '' ? (parse_url($originHeader, PHP_URL_HOST) ?: '') : '';
$serverHost   = $requestHost !== '' ? (explode(':', $requestHost)[0]) : '';
$isCrossOrigin = ($originHost !== '' && $serverHost !== '' && strcasecmp($originHost, $serverHost) !== 0);

if (session_status() === PHP_SESSION_NONE) {
    $customSavePath = getenv('PHP_SESSION_SAVE_PATH');
    if ($customSavePath && is_dir($customSavePath) && is_writable($customSavePath)) {
        session_save_path($customSavePath);
    }

    $sameSite = getenv('SESSION_SAMESITE') ?: (($isCrossOrigin && $isHttps) ? 'None' : 'Lax');
    $cookieSecure = ($sameSite === 'None') ? true : $isHttps;

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $cookieSecure,
        'httponly' => true,
        'samesite' => $sameSite,
    ]);
    session_start();
}

$allowedOriginsEnv = trim((string)(getenv('CORS_ALLOWED_ORIGINS') ?: ''));
if ($originHeader !== '') {
    $allowOrigin = null;
    if ($allowedOriginsEnv === '' || $allowedOriginsEnv === '*') {
        $allowOrigin = $originHeader;
    } else {
        $allowedList = array_filter(array_map('trim', explode(',', $allowedOriginsEnv)));
        foreach ($allowedList as $allowed) {
            if (strcasecmp(rtrim($originHeader, '/'), rtrim($allowed, '/')) === 0) {
                $allowOrigin = $originHeader;
                break;
            }
        }
    }
    if ($allowOrigin !== null) {
        header("Access-Control-Allow-Origin: " . $allowOrigin);
        header("Access-Control-Allow-Credentials: true");
        header("Vary: Origin");
    }
} else {
    header("Access-Control-Allow-Origin: *");
}

header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// Header keamanan tambahan
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

<?php
/**
 * Helper CSRF Protection
 *
 * Pola "double submit cookie" sederhana yang cocok untuk arsitektur
 * PHP native + fetch() ini:
 *  1. Saat login berhasil, server membuat csrf_token dan menyimpannya di
 *     $_SESSION, lalu mengirimkannya ke client di body response login.
 *  2. Client (frontend/assets/js/api.js) menyimpan token tsb dan
 *     menyertakannya di header 'X-CSRF-Token' pada setiap request
 *     POST/PUT/DELETE.
 *  3. requireCsrf() di bawah ini memvalidasi header tsb sama dengan yang
 *     ada di session sebelum request diproses.
 *
 * GET/OPTIONS tidak diverifikasi karena tidak mengubah state (aman dari CSRF).
 */

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function requireCsrf() {
    $method = $_SERVER['REQUEST_METHOD'];
    if (in_array($method, ['GET', 'OPTIONS', 'HEAD'])) {
        return; // aman, tidak mengubah state
    }

    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (empty($sessionToken) || empty($headerToken) || !hash_equals($sessionToken, $headerToken)) {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Token keamanan (CSRF) tidak valid atau kedaluwarsa. Silakan muat ulang halaman dan coba lagi."
        ]);
        exit();
    }
}

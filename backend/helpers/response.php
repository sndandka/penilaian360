<?php
/**
 * Helper untuk response JSON yang konsisten & utilitas input dasar
 */
function sendResponse($success, $message, $data = null, $code = 200, $meta = null) {
    http_response_code($code);
    $response = ["success" => $success, "message" => $message];
    if ($data !== null) {
        $response["data"] = $data;
    }
    if ($meta !== null) {
        $response["meta"] = $meta;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

function getJsonInput() {
    $raw = file_get_contents("php://input");
    $input = json_decode($raw, true);
    return is_array($input) ? $input : [];
}

/**
 * VALIDASI INPUT: memastikan field wajib ada dan tidak kosong.
 * Menghentikan eksekusi (sendResponse 400) jika ada yang kosong.
 */
function requireFields(array $input, array $fields) {
    foreach ($fields as $field) {
        if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
            sendResponse(false, "Field '$field' wajib diisi.", null, 400);
        }
    }
}

/**
 * VALIDASI INPUT: membersihkan string dari whitespace berlebih & tag HTML
 * (mencegah stored XSS sederhana pada field teks bebas).
 */
function cleanStr($value) {
    if ($value === null) return null;
    return trim(strip_tags((string) $value));
}

/**
 * VALIDASI INPUT: memastikan nilai numerik berada dalam rentang tertentu.
 */
function validateNumberRange($value, $min, $max, $label) {
    if (!is_numeric($value) || $value < $min || $value > $max) {
        sendResponse(false, "$label harus berupa angka antara $min - $max.", null, 400);
    }
}

/**
 * VALIDASI INPUT: format email sederhana (opsional field).
 */
function validateEmailIfPresent($email, $label = 'Email') {
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendResponse(false, "$label tidak valid.", null, 400);
    }
}

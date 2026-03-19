<?php
define('ENCRYPTION_KEY', 'your-secret-key-32chars'); // Change to a secure key

function encryptData($data) {
    $key = ENCRYPTION_KEY;
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('AES-256-CBC'));
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
    return base64_encode($iv . $encrypted);
}

function decryptData($data) {
    $key = ENCRYPTION_KEY;
    $data = base64_decode($data);
    $iv_length = openssl_cipher_iv_length('AES-256-CBC');
    $iv = substr($data, 0, $iv_length);
    $encrypted = substr($data, $iv_length);
    return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
}
?>

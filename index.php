<?php

$keepLoggedIn = ($_COOKIE['oc_keep_logged_in'] ?? '0') === '1';
$sessionLifetime = $keepLoggedIn ? 60 * 60 * 24 * 30 : 0;
if ($keepLoggedIn) {
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
}

session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'domain' => '',
    'secure' => (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    ),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

require_once "Routing.php";
$path = trim($_SERVER["REQUEST_URI"], '/');
$path = parse_url($path,PHP_URL_PATH);

Routing::run($path);

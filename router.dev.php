<?php
/**
 * Router for PHP's built-in server, used only for local development.
 *
 * The real site runs on Apache, where .htaccess sends everything that is not
 * a real file to the front controller. The built-in server has no .htaccess,
 * so the same rule is spelled out here. Not deployed.
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/public' . $path;

if ($path !== '/' && is_file($file)) {
    return false; // let the server deliver the asset as it is
}

require __DIR__ . '/public/index.php';

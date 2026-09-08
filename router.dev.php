<?php
/**
 * Router for PHP's built-in server, used only for local development.
 *
 * The real site runs on Apache, where .htaccess sends everything that is not
 * a real file to the front controller. The built-in server has no .htaccess,
 * so the same rule is spelled out here. Not deployed.
 */
declare(strict_types=1);

/* The local database, unless told otherwise.
 *
 * dev.sh sets REGAL_CONFIG and everything is fine. Started any other way -
 * an editor's run configuration, php -S typed by hand - there is no variable,
 * Config falls back to config.php, and on this machine that is the file with
 * the server's MySQL credentials in it. The result is a PDOException on every
 * page, which reads like a broken application rather than a server that was
 * started without its configuration.
 *
 * This file is development only and never deployed, so it is the one place
 * that may assume config.dev.php is what was meant. An explicit REGAL_CONFIG
 * still wins. */
if (getenv('REGAL_CONFIG') === false && is_file(__DIR__ . '/config.dev.php')) {
    putenv('REGAL_CONFIG=' . __DIR__ . '/config.dev.php');
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/public' . $path;

if ($path !== '/' && is_file($file)) {
    return false; // let the server deliver the asset as it is
}

require __DIR__ . '/public/index.php';

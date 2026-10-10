<?php

declare(strict_types=1);

/*
 * Router for PHP's built-in web server (local development, screenshots):
 *   php -S localhost:8000 -t public backend/bin/dev-router.php
 * Does what public/.htaccess does in production: /api/v1/… goes to the API front controller,
 * everything else is served as a static file from public/.
 */
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#^/api/(v1(?:/.*)?)$#', $path, $m)) {
    $_GET['_route'] = '/' . $m[1];
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require __DIR__ . '/../../public/api/index.php';
    return true;
}
return false;

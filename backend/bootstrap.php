<?php

declare(strict_types=1);

/*
 * BookSpot bootstrap: autoloading, configuration, error handling.
 * Every entry point (public/api/*.php, backend/bin/*.php) requires this file.
 */

use BookSpot\App;
use BookSpot\Config;

$root = dirname(__DIR__);

if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} else {
    // Composer-less fallback (e.g. FTP upload without vendor/): everything but Web Push works.
    spl_autoload_register(static function (string $class) use ($root): void {
        if (str_starts_with($class, 'BookSpot\\')) {
            $file = $root . '/backend/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

Config::load($root . '/.env');

date_default_timezone_set(Config::get('APP_TIMEZONE', 'Europe/Berlin'));
mb_internal_encoding('UTF-8');

$isDev = Config::get('APP_ENV', 'production') === 'development';
error_reporting(E_ALL);
ini_set('display_errors', $isDev && PHP_SAPI === 'cli' ? '1' : '0');
ini_set('log_errors', '1');
if (is_writable(App::varDir())) {
    ini_set('error_log', App::varDir() . '/error.log');
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

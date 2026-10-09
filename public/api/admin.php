<?php

declare(strict_types=1);

require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\Api;
use BookSpot\App;
use BookSpot\ApiException;
use BookSpot\Auth;
use BookSpot\Config;
use BookSpot\Cron;
use BookSpot\PushService;
use BookSpot\Request;

/*
 * Admin-only system information.
 * GET  admin.php                              cron token (+ whether it comes from .env), feature status
 * POST admin.php?action=regenerate_cron_token  new random token (only when CRON_TOKEN is not set in .env)
 */
Api::run(function (Request $req) {
    Auth::requireAdmin();
    $db = App::db();

    if ($req->method === 'POST' && $req->action() === 'regenerate_cron_token') {
        return ['cron_token' => Cron::regenerateToken($db), 'cron_token_from_config' => false];
    }
    if (!$req->isSafe()) {
        throw new ApiException('unknown_action', 404);
    }
    return [
        'cron_token' => Cron::token($db),
        'cron_token_from_config' => Cron::tokenFromConfig(),
        'registration_open' => Config::bool('ALLOW_REGISTRATION', false),
        'push_available' => PushService::available(),
        'google_books_key' => Config::get('GOOGLE_BOOKS_API_KEY') !== null,
        'dnb_enabled' => Config::bool('METADATA_DNB', true) && class_exists(DOMDocument::class),
        'db_driver' => $db->driver(),
        'php_version' => PHP_VERSION,
        'version' => App::VERSION,
    ];
});

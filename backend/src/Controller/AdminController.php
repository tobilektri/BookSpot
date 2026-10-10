<?php

declare(strict_types=1);

namespace BookSpot\Controller;

use BookSpot\ApiException;
use BookSpot\App;
use BookSpot\Config;
use BookSpot\Cron;
use BookSpot\PushService;
use BookSpot\Request;

/** Admin-only system information. */
final class AdminController extends Controller
{
    /** Cron token (+ whether it comes from .env), feature status */
    public function system(Request $request): array
    {
        $this->admin($request);
        $db = $this->db();
        return [
            'cron_token' => Cron::token($db),
            'cron_token_from_config' => Cron::tokenFromConfig(),
            'registration_open' => Config::bool('ALLOW_REGISTRATION', false),
            'push_available' => PushService::available(),
            'google_books_key' => Config::get('GOOGLE_BOOKS_API_KEY') !== null,
            'dnb_enabled' => Config::bool('METADATA_DNB', true) && class_exists(\DOMDocument::class),
            'db_driver' => $db->driver(),
            'php_version' => PHP_VERSION,
            'version' => App::VERSION,
        ];
    }

    /** New random token (only when CRON_TOKEN is not set in .env) */
    public function regenerateCronToken(Request $request): array
    {
        $this->admin($request);
        return ['cron_token' => Cron::regenerateToken($this->db()), 'cron_token_from_config' => false];
    }

    /** admin.php */
    public function legacy(Request $request): array
    {
        $this->admin($request);
        if ($request->method === 'POST' && $request->action() === 'regenerate_cron_token') {
            return $this->regenerateCronToken($request);
        }
        if (!$request->isSafe()) {
            throw new ApiException('unknown_action', 404);
        }
        return $this->system($request);
    }
}

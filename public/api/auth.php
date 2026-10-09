<?php

declare(strict_types=1);

require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\Api;
use BookSpot\App;
use BookSpot\ApiException;
use BookSpot\Auth;
use BookSpot\Config;
use BookSpot\PushService;
use BookSpot\Request;

Api::run(function (Request $req) {
    $auth = new Auth(App::db());

    return match ($req->action()) {
        // App start: who am I, can I register, which features are available?
        'status' => [
            'user' => Auth::user(),
            'registration_open' => $auth->registrationOpen(),
            'needs_setup' => $auth->userCount() === 0,
            'push_available' => PushService::available(),
            'app_name' => Config::get('APP_NAME'),
            'version' => App::VERSION,
        ],
        'login' => (function () use ($req, $auth) {
            $req->isSafe() && Api::methodNotAllowed();
            $user = $auth->attemptLogin((string) $req->input('login', ''), (string) $req->input('password', ''), $req->ip);
            Auth::loginSession($user);
            return ['user' => $user];
        })(),
        'register' => (function () use ($req, $auth) {
            $req->isSafe() && Api::methodNotAllowed();
            $user = $auth->register(
                (string) $req->input('username', ''),
                (string) $req->input('email', ''),
                (string) $req->input('password', ''),
                (string) $req->input('locale', 'de'),
            );
            Auth::loginSession($user);
            Api::status(201);
            return ['user' => $user];
        })(),
        'logout' => (function () use ($req) {
            $req->isSafe() && Api::methodNotAllowed();
            Auth::logoutSession();
            return ['ok' => true];
        })(),
        'profile' => (function () use ($req, $auth) {
            $user = Auth::requireUser();
            if ($req->isSafe()) {
                return ['user' => $user];
            }
            return ['user' => $auth->updateProfile($user['id'], $req->body)];
        })(),
        'users' => (function () use ($auth) {
            Auth::requireUser();
            // Usernames only for members (e.g. to pick who borrowed a book); full list for admins.
            $users = $auth->listUsers();
            if (Auth::requireUser()['role'] !== 'admin') {
                $users = array_map(fn ($u) => ['id' => $u['id'], 'username' => $u['username']], $users);
            }
            return ['users' => $users];
        })(),
        default => throw new ApiException('unknown_action', 404),
    };
});

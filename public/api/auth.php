<?php

declare(strict_types=1);

require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\Api;
use BookSpot\App;
use BookSpot\ApiException;
use BookSpot\Auth;
use BookSpot\Config;
use BookSpot\InviteService;
use BookSpot\PushService;
use BookSpot\Request;

Api::run(function (Request $req) {
    $auth = new Auth(App::db());

    return match ($req->action()) {
        // App start: who am I, can I register, which features are available?
        'status' => [
            'user' => Auth::user(),
            'impersonator' => ($imp = Auth::impersonator()) === null ? null : ['id' => $imp['id'], 'username' => $imp['username']],
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
        'onboarded' => (function () use ($req, $auth) {
            $req->isSafe() && Api::methodNotAllowed();
            $user = Auth::requireUser();
            // An admin looking at the app as someone else must not complete their introduction.
            if (Auth::impersonator() === null) {
                $auth->markOnboarded($user['id']);
            }
            return ['ok' => true];
        })(),
        'stop_impersonation' => (function () use ($req) {
            $req->isSafe() && Api::methodNotAllowed();
            return ['user' => Auth::stopImpersonation()];
        })(),
        // Invitation link (public): who is invited, then set the password and log in.
        'invite_info' => (new InviteService(App::db()))->info((string) $req->query('token', '')),
        'accept_invite' => (function () use ($req) {
            $req->isSafe() && Api::methodNotAllowed();
            $user = (new InviteService(App::db()))->accept((string) $req->input('token', ''), (string) $req->input('password', ''));
            Auth::loginSession($user);
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
        default => throw new ApiException('unknown_action', 404),
    };
});

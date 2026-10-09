<?php

declare(strict_types=1);

require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\Api;
use BookSpot\App;
use BookSpot\ApiException;
use BookSpot\Auth;
use BookSpot\PushService;
use BookSpot\Request;

/*
 * GET  push.php                     {available, public_key, subscriptions}
 * POST push.php?action=subscribe    PushSubscription JSON
 * POST push.php?action=unsubscribe  {endpoint}
 * POST push.php?action=test         sends a test notification to all own devices
 */
Api::run(function (Request $req) {
    $user = Auth::requireUser();
    $push = new PushService(App::db());

    if ($req->isSafe()) {
        return [
            'available' => PushService::available(),
            'public_key' => $push->publicKey(),
            'subscriptions' => $push->subscriptionCount($user['id']),
        ];
    }

    return match ($req->action()) {
        'subscribe' => (function () use ($push, $user, $req) {
            // While an admin acts as another user, this device must not receive that user's pushes.
            if (Auth::impersonator() !== null) {
                throw new ApiException('impersonation_no_push', 409);
            }
            $push->subscribe($user['id'], $req->body, $req->header('User-Agent'));
            return ['ok' => true];
        })(),
        'unsubscribe' => (function () use ($push, $user, $req) {
            $push->unsubscribe($user['id'], (string) $req->input('endpoint', ''));
            return ['ok' => true];
        })(),
        'test' => $push->send([$user['id']], [
            'title' => $user['locale'] === 'en' ? '📚 BookSpot test' : '📚 SchmökerSpot-Test',
            'body' => $user['locale'] === 'en' ? 'Push notifications are working.' : 'Push-Benachrichtigungen funktionieren.',
            'url' => './#/settings',
            'tag' => 'test',
        ]),
        default => throw new ApiException('unknown_action', 404),
    };
});

<?php

declare(strict_types=1);

namespace BookSpot\Controller;

use BookSpot\ApiException;
use BookSpot\Auth;
use BookSpot\PushService;
use BookSpot\Request;

final class PushController extends Controller
{
    /** {available, public_key, subscriptions} */
    public function info(Request $request): array
    {
        $user = $this->user($request);
        $push = new PushService($this->db());
        return [
            'available' => PushService::available(),
            'public_key' => $push->publicKey(),
            'subscriptions' => $push->subscriptionCount($user['id']),
        ];
    }

    /** PushSubscription JSON */
    public function subscribe(Request $request): array
    {
        $user = $this->user($request);
        // While an admin acts as another user, this device must not receive that user's pushes.
        if (Auth::impersonator() !== null) {
            throw new ApiException('impersonation_no_push', 409);
        }
        (new PushService($this->db()))->subscribe($user['id'], $request->body, $request->header('User-Agent'));
        return ['ok' => true];
    }

    /** {endpoint} */
    public function unsubscribe(Request $request): array
    {
        $user = $this->user($request);
        (new PushService($this->db()))->unsubscribe($user['id'], (string) $request->input('endpoint', ''));
        return ['ok' => true];
    }

    /** Sends a test notification to all own devices */
    public function test(Request $request): array
    {
        $user = $this->user($request);
        $en = $user['locale'] === 'en';
        return (new PushService($this->db()))->send([$user['id']], [
            'title' => $en ? '📚 BookSpot test' : '📚 SchmökerSpot-Test',
            'body' => $en ? 'Push notifications are working.' : 'Push-Benachrichtigungen funktionieren.',
            'url' => './#/settings',
            'tag' => 'test',
        ]);
    }

    /** push.php */
    public function legacy(Request $request): array
    {
        if ($request->isSafe()) {
            return $this->info($request);
        }
        return match ($request->action()) {
            'subscribe' => $this->subscribe($request),
            'unsubscribe' => $this->unsubscribe($request),
            'test' => $this->test($request),
            default => throw new ApiException('unknown_action', 404),
        };
    }
}

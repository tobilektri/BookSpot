<?php

declare(strict_types=1);

namespace BookSpot;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

/**
 * Web Push (VAPID) via minishlink/web-push.
 *
 * VAPID keys come from .env (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY) or are
 * generated once and stored in the settings table, so push works without any
 * manual key handling on shared hosting.
 */
final class PushService
{
    public function __construct(private readonly Database $db)
    {
    }

    public static function available(): bool
    {
        return class_exists(WebPush::class);
    }

    /** @return array{publicKey: string, privateKey: string}|null */
    public function vapidKeys(): ?array
    {
        $public = Config::get('VAPID_PUBLIC_KEY');
        $private = Config::get('VAPID_PRIVATE_KEY');
        if ($public && $private) {
            return ['publicKey' => $public, 'privateKey' => $private];
        }
        $stored = $this->db->value("SELECT value FROM settings WHERE name = 'vapid_keys'");
        if (is_string($stored)) {
            $keys = json_decode($stored, true);
            if (isset($keys['publicKey'], $keys['privateKey'])) {
                return $keys;
            }
        }
        if (!self::available()) {
            return null;
        }
        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            App::log('[BookSpot] Could not create VAPID keys: ' . $e->getMessage());
            return null;
        }
        $this->db->insert('settings', ['name' => 'vapid_keys', 'value' => json_encode($keys)]);
        return $keys;
    }

    public function publicKey(): ?string
    {
        return $this->vapidKeys()['publicKey'] ?? null;
    }

    public function subscribe(int $userId, array $input, ?string $userAgent): void
    {
        $endpoint = (string) ($input['endpoint'] ?? '');
        $p256dh = (string) ($input['keys']['p256dh'] ?? '');
        $auth = (string) ($input['keys']['auth'] ?? '');
        if (!preg_match('#^https://#', $endpoint) || strlen($endpoint) > 2000 || $p256dh === '' || $auth === ''
            || !self::publicEndpoint($endpoint)) {
            throw ApiException::validation(['subscription' => 'invalid_subscription']);
        }
        $hash = hash('sha256', $endpoint);
        $this->db->transaction(function () use ($hash, $userId, $endpoint, $p256dh, $auth, $userAgent) {
            $this->db->run('DELETE FROM push_subscriptions WHERE endpoint_hash = ?', [$hash]);
            $this->db->insert('push_subscriptions', [
                'user_id' => $userId,
                'endpoint' => $endpoint,
                'endpoint_hash' => $hash,
                'public_key' => mb_substr($p256dh, 0, 255),
                'auth_token' => mb_substr($auth, 0, 255),
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
                'created_at' => App::nowString(),
            ]);
        });
    }

    public function unsubscribe(int $userId, string $endpoint): void
    {
        $this->db->run('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?', [hash('sha256', $endpoint), $userId]);
    }

    public function subscriptionCount(int $userId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$userId]);
    }

    /**
     * Push endpoints are URLs of the browser vendors' push services. The server posts to
     * them, so they must not point into the hoster's network (SSRF).
     */
    public static function publicEndpoint(string $endpoint): bool
    {
        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        return $host !== '' && NetworkGuard::isPublicHost($host);
    }

    /**
     * Sends a notification to all devices of the given users.
     *
     * @param list<int> $userIds
     * @param array{title: string, body: string, url?: string, tag?: string} $payload
     * @return array{sent: int, failed: int, removed: int}
     */
    public function send(array $userIds, array $payload): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'removed' => 0];
        $keys = $this->vapidKeys();
        if ($userIds === [] || $keys === null || !self::available()) {
            return $result;
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $subscriptions = $this->db->all("SELECT * FROM push_subscriptions WHERE user_id IN ($placeholders)", array_values($userIds));
        if ($subscriptions === []) {
            return $result;
        }

        $webPush = new WebPush(['VAPID' => [
            'subject' => Config::get('VAPID_SUBJECT') ?? (Config::get('APP_URL') ?: 'mailto:admin@localhost'),
            'publicKey' => $keys['publicKey'],
            'privateKey' => $keys['privateKey'],
        ]], ['TTL' => 86400, 'urgency' => 'normal']);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($subscriptions as $row) {
            if (!self::publicEndpoint((string) $row['endpoint'])) {
                // Checked again at sending time: the name could point elsewhere by now (DNS rebinding).
                $this->db->run('DELETE FROM push_subscriptions WHERE endpoint_hash = ?', [$row['endpoint_hash']]);
                $result['removed']++;
                continue;
            }
            $webPush->queueNotification(new Subscription($row['endpoint'], $row['public_key'], $row['auth_token'], 'aes128gcm'), $json);
        }
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $result['sent']++;
                continue;
            }
            $result['failed']++;
            if ($report->isSubscriptionExpired()) {
                $this->db->run('DELETE FROM push_subscriptions WHERE endpoint_hash = ?', [hash('sha256', $report->getEndpoint())]);
                $result['removed']++;
            } else {
                App::log('[BookSpot] Push failed: ' . $report->getReason());
            }
        }
        return $result;
    }
}

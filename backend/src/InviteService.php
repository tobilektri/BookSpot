<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Invitations: a one-time link with which a new user sets their own password.
 * Only the SHA-256 hash of the token is stored. Sending a new invitation
 * invalidates older open ones.
 */
final class InviteService
{
    public const PURPOSE = 'invite';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Creates a link and – when $sendMail – mails it to the user.
     *
     * @return array{link: string, expires_at: string, mail_sent: bool, mail_available: bool, email: string}
     */
    public function invite(int $userId, int $adminId, string $baseUrl, bool $sendMail = true): array
    {
        $user = $this->db->one('SELECT id, username, email, locale FROM users WHERE id = ?', [$userId]) ?? throw ApiException::notFound('user_not_found');
        $admin = $this->db->one('SELECT username FROM users WHERE id = ?', [$adminId]);

        $token = bin2hex(random_bytes(32));
        $days = max(1, Config::int('INVITE_VALID_DAYS', 7));
        $expires = App::now()->modify("+$days days")->format('Y-m-d H:i:s');
        $this->db->transaction(function () use ($userId, $adminId, $token, $expires) {
            $this->db->run('DELETE FROM user_tokens WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [$userId, self::PURPOSE]);
            $this->db->insert('user_tokens', [
                'user_id' => $userId,
                'purpose' => self::PURPOSE,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expires,
                'created_by' => $adminId,
                'created_at' => App::nowString(),
            ]);
        });

        $link = rtrim($baseUrl, '/') . '/#/invite/' . $token;
        $sent = false;
        if ($sendMail) {
            [$subject, $body] = self::message($user, (string) ($admin['username'] ?? ''), $link, $expires);
            $sent = Mailer::send((string) $user['email'], $subject, $body);
            if (!$sent) {
                error_log('[BookSpot] Invitation mail to user #' . $userId . ' could not be sent');
            }
        }
        return ['link' => $link, 'expires_at' => $expires, 'mail_sent' => $sent, 'mail_available' => Mailer::available(), 'email' => (string) $user['email']];
    }

    /** @return array{username: string, locale: string, expires_at: string} public info for the invite page */
    public function info(string $token): array
    {
        $row = $this->find($token);
        return ['username' => $row['username'], 'locale' => $row['locale'], 'expires_at' => $row['expires_at']];
    }

    /**
     * Sets the password, marks the link as used and returns the user (to log in).
     *
     * @return array<string, mixed>
     */
    public function accept(string $token, string $password): array
    {
        if (mb_strlen($password) < 8) {
            throw ApiException::validation(['password' => 'password_too_short']);
        }
        return $this->db->transaction(function () use ($token, $password) {
            $row = $this->find($token);
            $this->db->update('users', ['password_hash' => Auth::hash($password), 'last_login_at' => App::nowString()], ['id' => (int) $row['user_id']]);
            $this->db->update('user_tokens', ['used_at' => App::nowString()], ['id' => (int) $row['token_id']]);
            return (new Auth($this->db))->findById((int) $row['user_id']) ?? throw ApiException::notFound('user_not_found');
        });
    }

    /** User ids with an open (unused, not expired) invitation => expiry. @return array<int, string> */
    public function pending(): array
    {
        $rows = $this->db->all(
            'SELECT user_id, MAX(expires_at) AS expires_at FROM user_tokens WHERE purpose = ? AND used_at IS NULL AND expires_at > ? GROUP BY user_id',
            [self::PURPOSE, App::nowString()]
        );
        return array_column($rows, 'expires_at', 'user_id');
    }

    /** @return array<string, mixed> */
    private function find(string $token): array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            throw new ApiException('invite_invalid', 404);
        }
        $row = $this->db->one(
            'SELECT t.id AS token_id, t.user_id, t.expires_at, t.used_at, u.username, u.locale
             FROM user_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.purpose = ?',
            [hash('sha256', $token), self::PURPOSE]
        );
        if ($row === null || $row['used_at'] !== null) {
            throw new ApiException('invite_invalid', 404);
        }
        if ($row['expires_at'] < App::nowString()) {
            throw new ApiException('invite_expired', 410);
        }
        return $row;
    }

    /**
     * Public base URL of the app: APP_URL, otherwise derived from the request
     * (works for domain root, public/ as document root and sub-directories).
     */
    public static function baseUrl(): string
    {
        $configured = trim((string) Config::get('APP_URL', ''));
        if ($configured !== '' && preg_match('#^https?://#', $configured)) {
            return rtrim($configured, '/') . '/';
        }
        $scheme = Auth::isHttps() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $base = ($pos = strpos($path, '/api/')) !== false ? substr($path, 0, $pos) : rtrim(dirname($path), '/');
        return $scheme . '://' . $host . $base . '/';
    }

    /** @return array{0: string, 1: string} [subject, body] */
    public static function message(array $user, string $adminName, string $link, string $expires): array
    {
        $appName = Config::get('APP_NAME') ?? ($user['locale'] === 'en' ? 'BookSpot' : 'SchmökerSpot');
        $date = (new \DateTimeImmutable($expires))->format($user['locale'] === 'en' ? 'Y-m-d H:i' : 'd.m.Y, H:i');
        $by = $adminName !== '' ? $adminName : $appName;
        if ($user['locale'] === 'en') {
            return ["Invitation to $appName", <<<TXT
            Hi {$user['username']},

            $by has invited you to $appName – our shared book collection with shelves, loans and reading statistics.

            Choose your password here (the link is valid until $date and can be used once):
            $link

            Your username: {$user['username']}

            After that you can install $appName as an app on your phone or computer.

            See you soon!
            $appName
            TXT];
        }
        return ["Einladung zu $appName", <<<TXT
        Hallo {$user['username']},

        $by hat dich zu $appName eingeladen – unserer gemeinsamen Bücherverwaltung mit Regalen, Ausleihen und Lesestatistiken.

        Lege hier dein Passwort fest (der Link ist bis $date gültig und nur einmal verwendbar):
        $link

        Dein Benutzername: {$user['username']}

        Danach kannst du $appName auch als App auf dem Handy oder Computer installieren.

        Viel Spaß beim Schmökern!
        $appName
        TXT];
    }
}

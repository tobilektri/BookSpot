<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Session handling (HttpOnly, SameSite=Strict, Secure on HTTPS) and the
 * user account service (registration, login throttling, profile changes).
 */
final class Auth
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_USER = 'user';
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_USER];

    private const MAX_FAILED_LOGINS = 10;
    private const LOCKOUT_MINUTES = 15;

    /** @var array<string, mixed>|null */
    private static ?array $currentUser = null;

    public function __construct(private readonly Database $db)
    {
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secureSetting = Config::get('SESSION_SECURE', 'auto');
        $secure = $secureSetting === 'auto' ? self::isHttps() : Config::bool('SESSION_SECURE');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) (Config::int('SESSION_LIFETIME_DAYS', 30) * 86400));
        $savePath = App::varDir() . '/sessions';
        if (Config::bool('SESSION_USE_VAR_DIR', true) && (is_dir($savePath) || @mkdir($savePath, 0770, true)) && is_writable($savePath)) {
            session_save_path($savePath);
        }
        session_name('BOOKSPOT_SID');
        session_set_cookie_params([
            'lifetime' => Config::int('SESSION_LIFETIME_DAYS', 30) * 86400,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    }

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }
        self::startSession();
        $id = $_SESSION['uid'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        $user = (new self(App::db()))->findById($id);
        if ($user === null) {
            unset($_SESSION['uid']);
            return null;
        }
        return self::$currentUser = $user;
    }

    /** @return array<string, mixed> */
    public static function requireUser(): array
    {
        return self::user() ?? throw new ApiException('unauthenticated', 401);
    }

    /** @return array<string, mixed> */
    public static function requireAdmin(): array
    {
        $user = self::requireUser();
        if ($user['role'] !== self::ROLE_ADMIN) {
            throw new ApiException('forbidden', 403);
        }
        return $user;
    }

    /** For tests and CLI. @param array<string, mixed>|null $user */
    public static function setUser(?array $user): void
    {
        self::$currentUser = $user;
    }

    public static function loginSession(array $user): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        self::$currentUser = $user;
    }

    public static function logoutSession(): void
    {
        self::startSession();
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_destroy();
        self::$currentUser = null;
    }

    // ---------------------------------------------------------------- service

    public function userCount(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM users');
    }

    public function registrationOpen(): bool
    {
        return $this->userCount() === 0 || Config::bool('ALLOW_REGISTRATION', false);
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $user = $this->db->one('SELECT id, username, email, role, locale, created_at FROM users WHERE id = ?', [$id]);
        return $user === null ? null : self::publicUser($user);
    }

    /**
     * Self-registration: the very first account becomes admin, later ones are users
     * (only while ALLOW_REGISTRATION=true; admins can always create accounts).
     *
     * @return array<string, mixed>
     */
    public function register(string $username, string $email, string $password, string $locale = 'de', bool $force = false): array
    {
        if (!$force && !$this->registrationOpen()) {
            throw new ApiException('registration_closed', 403);
        }
        return $this->createUser([
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'locale' => $locale,
            'role' => $this->userCount() === 0 ? self::ROLE_ADMIN : self::ROLE_USER,
        ]);
    }

    /**
     * Creates an account (admin user management and registration).
     *
     * @param array{username?: mixed, email?: mixed, password?: mixed, role?: mixed, locale?: mixed} $input
     * @return array<string, mixed>
     */
    public function createUser(array $input): array
    {
        $username = trim((string) ($input['username'] ?? ''));
        $email = trim(mb_strtolower((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $role = (string) ($input['role'] ?? self::ROLE_USER);
        $locale = (string) ($input['locale'] ?? 'de');

        $errors = [];
        if (!preg_match('/^[\p{L}\p{N}._-]{3,50}$/u', $username)) {
            $errors['username'] = 'invalid_username';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
            $errors['email'] = 'invalid_email';
        }
        if (mb_strlen($password) < 8) {
            $errors['password'] = 'password_too_short';
        }
        if (!in_array($role, self::ROLES, true)) {
            $errors['role'] = 'invalid_choice';
        }
        if ($errors === []) {
            if ($this->db->value('SELECT id FROM users WHERE LOWER(username) = LOWER(?)', [$username]) !== null) {
                $errors['username'] = 'username_taken';
            }
            if ($this->db->value('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
                $errors['email'] = 'email_taken';
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        $id = $this->db->insert('users', [
            'username' => $username,
            'email' => $email,
            'password_hash' => self::hash($password),
            'role' => $role,
            'locale' => in_array($locale, ['de', 'en'], true) ? $locale : 'de',
            'created_at' => App::nowString(),
        ]);
        return $this->findById($id) ?? throw new \LogicException('User vanished');
    }

    /**
     * Admin changes to another account: role, email, new password.
     * There must always be at least one admin left.
     *
     * @return array<string, mixed>
     */
    public function updateUser(int $actorId, int $id, array $input): array
    {
        return $this->db->transaction(function () use ($actorId, $id, $input) {
            $row = $this->db->one('SELECT * FROM users WHERE id = ?', [$id]) ?? throw ApiException::notFound('user_not_found');
            $update = [];
            $errors = [];

            if (array_key_exists('role', $input) && $input['role'] !== $row['role']) {
                if (!in_array($input['role'], self::ROLES, true)) {
                    $errors['role'] = 'invalid_choice';
                } elseif ($row['role'] === self::ROLE_ADMIN && $this->adminCount() <= 1) {
                    throw new ApiException('last_admin', 409);
                } else {
                    $update['role'] = $input['role'];
                }
            }
            if (isset($input['email']) && trim((string) $input['email']) !== $row['email']) {
                $email = trim(mb_strtolower((string) $input['email']));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
                    $errors['email'] = 'invalid_email';
                } elseif ($this->db->value('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id]) !== null) {
                    $errors['email'] = 'email_taken';
                } else {
                    $update['email'] = $email;
                }
            }
            if (!empty($input['password'])) {
                if (mb_strlen((string) $input['password']) < 8) {
                    $errors['password'] = 'password_too_short';
                } else {
                    $update['password_hash'] = self::hash((string) $input['password']);
                }
            }
            if ($errors !== []) {
                throw ApiException::validation($errors);
            }
            $this->db->update('users', $update, ['id' => $id]);
            if (isset($update['role']) && $id === $actorId) {
                self::$currentUser = null; // own role changed: reload on next access
            }
            return $this->findById($id) ?? throw ApiException::notFound('user_not_found');
        });
    }

    /**
     * Deletes an account incl. its personal reading data. Shared catalogue
     * entries stay (created_by / loan_user_id are set to NULL).
     */
    public function deleteUser(int $actorId, int $id): void
    {
        $this->db->transaction(function () use ($actorId, $id) {
            if ($actorId === $id) {
                throw new ApiException('cannot_delete_self', 409);
            }
            $row = $this->db->one('SELECT role FROM users WHERE id = ?', [$id]) ?? throw ApiException::notFound('user_not_found');
            if ($row['role'] === self::ROLE_ADMIN && $this->adminCount() <= 1) {
                throw new ApiException('last_admin', 409);
            }
            $this->db->run('DELETE FROM users WHERE id = ?', [$id]);
        });
    }

    public function adminCount(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM users WHERE role = ?', [self::ROLE_ADMIN]);
    }

    /** @return array<string, mixed> */
    public function attemptLogin(string $login, string $password, string $ip): array
    {
        $since = App::now()->modify('-' . self::LOCKOUT_MINUTES . ' minutes')->format('Y-m-d H:i:s');
        $failures = (int) $this->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?',
            [$ip, $since]
        );
        if ($failures >= self::MAX_FAILED_LOGINS) {
            throw new ApiException('too_many_attempts', 429);
        }

        $login = trim($login);
        $row = $this->db->one(
            'SELECT * FROM users WHERE LOWER(username) = LOWER(?) OR email = ?',
            [$login, mb_strtolower($login)]
        );
        // Always run password_verify to keep timing similar for unknown users.
        static $dummyHash = null;
        $hash = $row['password_hash'] ?? ($dummyHash ??= self::hash(bin2hex(random_bytes(8))));
        if (!password_verify($password, $hash) || $row === null) {
            $this->db->insert('login_attempts', ['ip' => $ip, 'username' => mb_substr($login, 0, 100), 'attempted_at' => App::nowString()]);
            throw new ApiException('invalid_credentials', 401);
        }

        $update = ['last_login_at' => App::nowString()];
        if (password_needs_rehash($row['password_hash'], self::algorithm())) {
            $update['password_hash'] = self::hash($password);
        }
        $this->db->update('users', $update, ['id' => (int) $row['id']]);
        $this->db->run('DELETE FROM login_attempts WHERE ip = ? OR attempted_at < ?', [$ip, $since]);

        return self::publicUser($row);
    }

    /** @return array<string, mixed> */
    public function updateProfile(int $userId, array $input): array
    {
        $row = $this->db->one('SELECT * FROM users WHERE id = ?', [$userId]) ?? throw ApiException::notFound();
        $update = [];
        $errors = [];

        if (isset($input['email']) && trim((string) $input['email']) !== $row['email']) {
            $email = trim(mb_strtolower((string) $input['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'invalid_email';
            } elseif ($this->db->value('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $userId]) !== null) {
                $errors['email'] = 'email_taken';
            } else {
                $update['email'] = $email;
            }
        }
        if (isset($input['locale']) && in_array($input['locale'], ['de', 'en'], true)) {
            $update['locale'] = $input['locale'];
        }
        if (!empty($input['new_password'])) {
            if (!password_verify((string) ($input['current_password'] ?? ''), $row['password_hash'])) {
                $errors['current_password'] = 'wrong_password';
            } elseif (mb_strlen((string) $input['new_password']) < 8) {
                $errors['new_password'] = 'password_too_short';
            } else {
                $update['password_hash'] = self::hash((string) $input['new_password']);
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        $this->db->update('users', $update, ['id' => $userId]);
        return $this->findById($userId) ?? throw ApiException::notFound();
    }

    /** @return list<array<string, mixed>> */
    public function listUsers(): array
    {
        return array_map(
            self::publicUser(...),
            $this->db->all('SELECT id, username, email, role, locale, created_at, last_login_at FROM users ORDER BY username')
        );
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    /** Argon2id where available (spec), bcrypt as a fallback for minimal PHP builds on shared hosting. */
    private static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /** @return array<string, mixed> */
    private static function publicUser(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'username' => $row['username'],
            'email' => $row['email'],
            'role' => $row['role'],
            'locale' => $row['locale'],
            'created_at' => $row['created_at'],
        ] + (array_key_exists('last_login_at', $row) ? ['last_login_at' => $row['last_login_at']] : []);
    }
}

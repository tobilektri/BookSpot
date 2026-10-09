<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\ApiException;
use BookSpot\Auth;
use BookSpot\Config;

final class AuthTest extends TestCase
{
    public function testFirstUserIsAdminAndRegistrationThenCloses(): void
    {
        Config::set('ALLOW_REGISTRATION', 'false');
        $auth = new Auth($this->db);
        self::assertTrue($auth->registrationOpen());
        $admin = $auth->register('Admin', 'ADMIN@example.org', 'long-password');
        self::assertSame('admin', $admin['role']);
        self::assertSame('admin@example.org', $admin['email']);
        self::assertFalse($auth->registrationOpen());

        $this->expectExceptionMessage('registration_closed');
        $auth->register('second', 'second@example.org', 'long-password');
    }

    public function testLoginWithUsernameOrEmailAndArgon2(): void
    {
        $auth = new Auth($this->db);
        $auth->register('anna', 'anna@example.org', 'long-password');
        $hash = (string) $this->db->value('SELECT password_hash FROM users');
        if (defined('PASSWORD_ARGON2ID')) {
            self::assertStringStartsWith('$argon2id$', $hash);
        }
        self::assertSame('anna', $auth->attemptLogin('ANNA', 'long-password', '1.2.3.4')['username']);
        self::assertSame('anna', $auth->attemptLogin('anna@example.org', 'long-password', '1.2.3.4')['username']);
    }

    public function testBruteForceThrottling(): void
    {
        $auth = new Auth($this->db);
        $auth->register('anna', 'anna@example.org', 'long-password');
        for ($i = 0; $i < 10; $i++) {
            try {
                $auth->attemptLogin('anna', 'wrong', '9.9.9.9');
            } catch (ApiException $e) {
                self::assertSame('invalid_credentials', $e->getMessage());
            }
        }
        try {
            $auth->attemptLogin('anna', 'long-password', '9.9.9.9');
            self::fail('not throttled');
        } catch (ApiException $e) {
            self::assertSame(429, $e->getCode());
        }
        // Other IPs are unaffected.
        self::assertSame('anna', $auth->attemptLogin('anna', 'long-password', '8.8.8.8')['username']);
    }

    public function testValidationAndProfileUpdate(): void
    {
        $auth = new Auth($this->db);
        try {
            $auth->register('a', 'nope', 'short');
            self::fail('accepted invalid registration');
        } catch (ApiException $e) {
            self::assertSame(['username', 'email', 'password'], array_keys($e->details['errors']));
        }
        $user = $auth->register('anna', 'anna@example.org', 'long-password');
        $updated = $auth->updateProfile($user['id'], ['locale' => 'en', 'current_password' => 'long-password', 'new_password' => 'even-longer-password']);
        self::assertSame('en', $updated['locale']);
        self::assertSame('anna', $auth->attemptLogin('anna', 'even-longer-password', '1.1.1.1')['username']);
    }
}

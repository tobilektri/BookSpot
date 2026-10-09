<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\ApiException;
use BookSpot\Auth;
use BookSpot\BookService;
use BookSpot\Config;
use BookSpot\Cron;
use BookSpot\ShelfService;
use BookSpot\UserBookService;

final class UserAdminTest extends TestCase
{
    private Auth $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = new Auth($this->db);
    }

    public function testAdminCreatesUsersWithRoles(): void
    {
        $admin = $this->auth->register('admin', 'admin@example.org', 'long-password');
        self::assertSame(Auth::ROLE_ADMIN, $admin['role']);

        $user = $this->auth->createUser(['username' => 'ben', 'email' => 'ben@example.org', 'password' => 'long-password', 'role' => 'user']);
        $second = $this->auth->createUser(['username' => 'cleo', 'email' => 'cleo@example.org', 'password' => 'long-password', 'role' => 'admin', 'locale' => 'en']);
        self::assertSame('user', $user['role']);
        self::assertSame('admin', $second['role']);
        self::assertSame('en', $second['locale']);
        self::assertSame(2, $this->auth->adminCount());

        try {
            $this->auth->createUser(['username' => 'x', 'email' => 'ben@example.org', 'password' => 'short', 'role' => 'superuser']);
            self::fail('invalid input accepted');
        } catch (ApiException $e) {
            self::assertSame(['username' => 'invalid_username', 'password' => 'password_too_short', 'role' => 'invalid_choice'], $e->details['errors']);
        }
    }

    public function testSelfRegistrationCreatesUsersNotAdmins(): void
    {
        Config::set('ALLOW_REGISTRATION', 'true');
        $this->auth->register('admin', 'admin@example.org', 'long-password');
        self::assertSame('user', $this->auth->register('ben', 'ben@example.org', 'long-password')['role']);
        Config::set('ALLOW_REGISTRATION', null);
    }

    public function testRoleChangesAndPasswordReset(): void
    {
        $admin = $this->auth->register('admin', 'admin@example.org', 'long-password');
        $user = $this->auth->createUser(['username' => 'ben', 'email' => 'ben@example.org', 'password' => 'long-password']);

        $promoted = $this->auth->updateUser($admin['id'], $user['id'], ['role' => 'admin', 'password' => 'new-long-password']);
        self::assertSame('admin', $promoted['role']);
        self::assertSame('ben', $this->auth->attemptLogin('ben', 'new-long-password', '1.1.1.1')['username']);

        // The first admin may step down now that there is another admin …
        self::assertSame('user', $this->auth->updateUser($admin['id'], $admin['id'], ['role' => 'user'])['role']);
        // … but the last admin can neither be demoted nor deleted.
        foreach ([fn () => $this->auth->updateUser($user['id'], $user['id'], ['role' => 'user']), fn () => $this->auth->deleteUser($admin['id'], $user['id'])] as $action) {
            try {
                $action();
                self::fail('last admin removed');
            } catch (ApiException $e) {
                self::assertSame('last_admin', $e->getMessage());
            }
        }
    }

    public function testDeleteUserKeepsSharedCatalogue(): void
    {
        $admin = $this->auth->register('admin', 'admin@example.org', 'long-password');
        $user = $this->auth->createUser(['username' => 'ben', 'email' => 'ben@example.org', 'password' => 'long-password']);
        $book = (new BookService($this->db, new ShelfService($this->db)))->create(['title' => 'Geteilt', 'entry_type' => 'library_loan', 'loan_due' => '2026-10-20'], $user['id']);
        (new UserBookService($this->db))->upsert($user['id'], $book['id'], ['reading_status' => 'read']);

        try {
            $this->auth->deleteUser($admin['id'], $admin['id']);
            self::fail('deleted self');
        } catch (ApiException $e) {
            self::assertSame('cannot_delete_self', $e->getMessage());
        }

        $this->auth->deleteUser($admin['id'], $user['id']);
        self::assertNull($this->auth->findById($user['id']));
        $row = $this->db->one('SELECT created_by, loan_user_id FROM books WHERE id = ?', [$book['id']]);
        self::assertSame([null, null], [$row['created_by'], $row['loan_user_id']], 'book stays, references are cleared');
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM user_books'));
    }

    public function testCronTokenIsGeneratedOnceUnlessConfigured(): void
    {
        Config::set('CRON_TOKEN', null);
        $token = Cron::token($this->db);
        self::assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $token);
        self::assertSame($token, Cron::token($this->db), 'stored and reused');

        $new = Cron::regenerateToken($this->db);
        self::assertNotSame($token, $new);
        self::assertSame($new, Cron::token($this->db));

        Config::set('CRON_TOKEN', 'from-env-0123456789');
        self::assertSame('from-env-0123456789', Cron::token($this->db), '.env wins');
        try {
            Cron::regenerateToken($this->db);
            self::fail('regenerated a configured token');
        } catch (ApiException $e) {
            self::assertSame('cron_token_from_config', $e->getMessage());
        }
        Config::set('CRON_TOKEN', 'short');
        try {
            Cron::token($this->db);
            self::fail('accepted short token');
        } catch (ApiException $e) {
            self::assertSame('cron_token_too_short', $e->getMessage());
        }
        Config::set('CRON_TOKEN', null);
    }
}

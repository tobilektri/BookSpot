<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\ApiException;
use BookSpot\App;
use BookSpot\Auth;
use BookSpot\InviteService;
use BookSpot\Mailer;

final class InviteTest extends TestCase
{
    /** @var list<array{to: string, subject: string, body: string, headers: string}> */
    private array $mails = [];
    private array $admin;
    private array $user;
    private InviteService $invites;

    protected function setUp(): void
    {
        parent::setUp();
        Mailer::setTransport(function (string $to, string $subject, string $body, string $headers) {
            $this->mails[] = compact('to', 'subject', 'body', 'headers');
            return true;
        });
        $auth = new Auth($this->db);
        $this->admin = $auth->register('tobi', 'tobi@example.org', 'long-password');
        $this->user = $auth->createUser(['username' => 'lena', 'email' => 'lena@example.org', 'password' => bin2hex(random_bytes(16)), 'locale' => 'de']);
        $this->invites = new InviteService($this->db);
    }

    protected function tearDown(): void
    {
        Mailer::setTransport(null);
        parent::tearDown();
    }

    public function testInvitationMailAndAcceptance(): void
    {
        $invite = $this->invites->invite($this->user['id'], $this->admin['id'], 'https://books.example.org/');
        self::assertTrue($invite['mail_sent']);
        self::assertMatchesRegularExpression('#^https://books\.example\.org/\#/invite/[0-9a-f]{64}$#', $invite['link']);
        self::assertSame('2026-10-16 12:00:00', $invite['expires_at']);

        $mail = $this->mails[0];
        self::assertSame('lena@example.org', $mail['to']);
        self::assertSame('Einladung zu SchmökerSpot', mb_decode_mimeheader($mail['subject']));
        self::assertStringContainsString('tobi hat dich zu SchmökerSpot eingeladen', $mail['body']);
        self::assertStringContainsString($invite['link'], $mail['body']);
        self::assertStringContainsString('16.10.2026', $mail['body']);
        self::assertStringNotContainsString('Passwort:', $mail['body'], 'no password in the mail');
        self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $mail['headers']);

        $token = substr($invite['link'], -64);
        self::assertSame('lena', $this->invites->info($token)['username']);
        self::assertSame([$this->user['id'] => '2026-10-16 12:00:00'], $this->invites->pending());

        $user = $this->invites->accept($token, 'my-own-password');
        self::assertSame('lena', $user['username']);
        self::assertSame('lena', (new Auth($this->db))->attemptLogin('lena', 'my-own-password', '1.1.1.1')['username']);
        self::assertSame([], $this->invites->pending());

        $this->expectExceptionMessage('invite_invalid');
        $this->invites->accept($token, 'another-password'); // one-time link
    }

    public function testNewInvitationInvalidatesOldLinkAndLinksExpire(): void
    {
        $first = substr($this->invites->invite($this->user['id'], $this->admin['id'], 'https://x.example/')['link'], -64);
        $second = substr($this->invites->invite($this->user['id'], $this->admin['id'], 'https://x.example/')['link'], -64);
        try {
            $this->invites->info($first);
            self::fail('old link still valid');
        } catch (ApiException $e) {
            self::assertSame('invite_invalid', $e->getMessage());
        }
        App::setNow('2026-10-17 12:00:00');
        try {
            $this->invites->accept($second, 'long-password');
            self::fail('expired link accepted');
        } catch (ApiException $e) {
            self::assertSame('invite_expired', $e->getMessage());
            self::assertSame(410, $e->getCode());
        }
    }

    public function testValidationAndEnglishMail(): void
    {
        $en = (new Auth($this->db))->createUser(['username' => 'sam', 'email' => 'sam@example.org', 'password' => 'long-password', 'locale' => 'en']);
        $invite = $this->invites->invite($en['id'], $this->admin['id'], 'https://x.example/');
        self::assertSame('Invitation to BookSpot', mb_decode_mimeheader($this->mails[0]['subject']));
        self::assertStringContainsString('tobi has invited you', $this->mails[0]['body']);

        try {
            $this->invites->accept(substr($invite['link'], -64), 'short');
            self::fail('short password accepted');
        } catch (ApiException $e) {
            self::assertSame(['password' => 'password_too_short'], $e->details['errors']);
        }
        $this->expectExceptionMessage('invite_invalid');
        $this->invites->info('not-a-token');
    }

    public function testFailedMailStillReturnsTheLink(): void
    {
        Mailer::setTransport(fn () => false);
        $invite = $this->invites->invite($this->user['id'], $this->admin['id'], 'https://x.example/');
        self::assertFalse($invite['mail_sent']);
        self::assertStringStartsWith('https://x.example/#/invite/', $invite['link']);
    }

    public function testHeaderInjectionIsRejected(): void
    {
        self::assertFalse(Mailer::send("victim@example.org\r\nBcc: spam@example.org", 'Hi', 'x'));
        self::assertFalse(Mailer::send('victim@example.org', "Hi\r\nBcc: spam@example.org", 'x'));
        self::assertSame([], $this->mails);
    }
}

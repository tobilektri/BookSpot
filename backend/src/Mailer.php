<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Plain-text mails via PHP mail() – available on practically every shared host.
 * Sender: MAIL_FROM ("Name <address>"); otherwise noreply@<host>.
 */
final class Mailer
{
    /** @var (callable(string, string, string, string): bool)|null test double */
    private static $transport = null;

    /** @param callable(string $to, string $subject, string $body, string $headers): bool|null $transport */
    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    public static function available(): bool
    {
        if (self::$transport !== null) {
            return true;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return function_exists('mail') && !in_array('mail', $disabled, true);
    }

    public static function send(string $to, string $subject, string $body): bool
    {
        // Header injection guard: addresses must be a single valid address.
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $subject)) {
            return false;
        }
        [$fromName, $fromAddress] = self::sender();
        $headers = implode("\r\n", [
            'From: ' . ($fromName !== '' ? mb_encode_mimeheader($fromName, 'UTF-8', 'Q') . ' ' : '') . "<$fromAddress>",
            'Reply-To: ' . (Config::get('MAIL_REPLY_TO') ?: $fromAddress),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: BookSpot/' . App::VERSION,
        ]);
        $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        if (self::$transport !== null) {
            return (bool) (self::$transport)($to, $encodedSubject, $body, $headers);
        }
        if (!self::available()) {
            return false;
        }
        try {
            // -f sets the envelope sender; many hosts reject mails without a matching one.
            return @mail($to, $encodedSubject, $body, $headers, '-f' . $fromAddress);
        } catch (\Throwable $e) {
            error_log('[BookSpot] mail() failed: ' . $e->getMessage());
            return false;
        }
    }

    /** @return array{0: string, 1: string} [name, address] */
    private static function sender(): array
    {
        $from = trim((string) Config::get('MAIL_FROM', ''));
        if (preg_match('/^(.*)<([^>]+)>$/', $from, $m) && filter_var(trim($m[2]), FILTER_VALIDATE_EMAIL)) {
            return [trim($m[1], " \"'"), trim($m[2])];
        }
        if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['', $from];
        }
        $host = preg_replace('/^www\./', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'))) ?? 'localhost';
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        $name = Config::get('APP_NAME') ?? 'SchmökerSpot';
        return [$name, 'noreply@' . (filter_var('x@' . $host, FILTER_VALIDATE_EMAIL) ? $host : 'localhost')];
    }
}

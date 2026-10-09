<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * An error that is safe to show to the client.
 *
 * The message is a stable machine-readable code (e.g. "invalid_credentials")
 * that the frontend translates; `$details` carries extra context such as
 * per-field validation codes: ['errors' => ['title' => 'required']].
 */
final class ApiException extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $code, int $status = 400, public readonly array $details = [])
    {
        parent::__construct($code, $status);
    }

    public static function notFound(string $code = 'not_found'): self
    {
        return new self($code, 404);
    }

    /** @param array<string, string> $errors field => code */
    public static function validation(array $errors): self
    {
        return new self('validation_failed', 422, ['errors' => $errors]);
    }
}

<?php

declare(strict_types=1);

namespace BookSpot;

/** Metadata services could not be reached (network error, rate limit, 5xx) – retry later. */
final class MetadataUnavailable extends \RuntimeException
{
    /**
     * @param int      $status     HTTP status (0 = network error, -1 = skipped by the rate limiter)
     * @param int|null $retryAfter Retry-After header of a 429 answer in seconds
     */
    public function __construct(string $message = '', public readonly int $status = 0, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}

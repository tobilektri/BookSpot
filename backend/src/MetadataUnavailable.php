<?php

declare(strict_types=1);

namespace BookSpot;

/** Metadata services could not be reached (network error, rate limit, 5xx) – retry later. */
final class MetadataUnavailable extends \RuntimeException
{
}

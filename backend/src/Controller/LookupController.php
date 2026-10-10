<?php

declare(strict_types=1);

namespace BookSpot\Controller;

use BookSpot\MetadataService;
use BookSpot\MetadataUnavailable;
use BookSpot\Request;

final class LookupController extends Controller
{
    /** Metadata for an ISBN from Open Library, DNB and Google Books */
    public function lookup(Request $request): array
    {
        $this->user($request);
        try {
            $meta = (new MetadataService($this->db()))->lookup((string) ($request->params['isbn'] ?? $request->query('isbn', '')));
        } catch (MetadataUnavailable) {
            return ['found' => false, 'metadata' => null, 'unavailable' => true];
        }
        return ['found' => $meta !== null, 'metadata' => $meta];
    }
}

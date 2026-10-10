<?php

declare(strict_types=1);

// Deprecated endpoint, kept for installed app versions and existing cron URLs.
// Handled by the central router (backend/src/Http/Kernel.php); use /api/v1/… instead.
require __DIR__ . '/../../backend/bootstrap.php';

BookSpot\Http\Kernel::serve('/books.php');

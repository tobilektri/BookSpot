<?php

declare(strict_types=1);

/*
 * Front controller of the API: /api/v1/… is rewritten to this file
 * (public/.htaccess, docker/nginx.conf). Without URL rewriting the same routes
 * are reachable as api/index.php/v1/… . Route table: backend/src/Http/Kernel.php
 */
require __DIR__ . '/../../backend/bootstrap.php';

BookSpot\Http\Kernel::serve();

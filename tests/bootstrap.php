<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

BookSpot\Config::set('APP_TIMEZONE', 'Europe/Berlin');
BookSpot\Config::set('METADATA_LOOKUP', 'false');
date_default_timezone_set('Europe/Berlin');

<?php

declare(strict_types=1);

// Creates a user even when registration is closed:
//   php backend/bin/create-user.php <username> <email> <password> [de|en]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';

[, $username, $email, $password, $locale] = $argv + [null, null, null, null, 'de'];
if (!$username || !$email || !$password) {
    fwrite(STDERR, "Usage: php backend/bin/create-user.php <username> <email> <password> [de|en]\n");
    exit(1);
}
try {
    $user = (new BookSpot\Auth(BookSpot\App::db()))->register($username, $email, $password, $locale, force: true);
    echo "Created user #{$user['id']} ({$user['username']}, role {$user['role']}).\n";
} catch (BookSpot\ApiException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . ' ' . json_encode($e->details) . "\n");
    exit(1);
}

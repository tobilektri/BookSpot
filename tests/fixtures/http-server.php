<?php

// Router for `php -S` in HttpTest: answers like the book data and cover sources do.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
switch ($path) {
    case '/json':
        header('Content-Type: application/json');
        echo json_encode(['ua' => $_SERVER['HTTP_USER_AGENT'] ?? '', 'accept' => $_SERVER['HTTP_ACCEPT'] ?? '']);
        break;
    case '/missing':
        http_response_code(404);
        echo 'not found';
        break;
    case '/limited':
        http_response_code(429);
        header('Retry-After: 120');
        break;
    case '/broken':
        http_response_code(503);
        break;
    case '/redirect':
        header('Location: /cover.png', true, 302);
        break;
    case '/cover.png':
        $img = imagecreatetruecolor(60, 90);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 200));
        header('Content-Type: image/png');
        imagepng($img);
        break;
    default:
        http_response_code(500);
}

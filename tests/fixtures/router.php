<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_URI'] === '/unavailable') {
    http_response_code(503);
    header('Retry-After: 2');
    echo 'unavailable';
    return;
}
header('Content-Type: application/json');
header('X-Test-Transport: curl');
echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'parameters' => $_POST], JSON_THROW_ON_ERROR);

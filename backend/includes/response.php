<?php

declare(strict_types=1);

function json_success(array $data = [], int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => true, 'data' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

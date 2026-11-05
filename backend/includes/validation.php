<?php

declare(strict_types=1);

function read_json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function get_period_value(?string $value): string
{
    $candidate = trim((string) ($value ?? ''));
    if ($candidate === '') {
        $candidate = date('Y-m');
    }

    if (!preg_match('/^\d{4}-\d{2}$/', $candidate)) {
        throw new InvalidArgumentException('A valid period is required (YYYY-MM).');
    }

    return $candidate;
}

function app_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
}

function normalize_email(string $value): string
{
    return strtolower(trim($value));
}

function validate_email(string $value): bool
{
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

function require_method(string ...$methods): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, $methods, true)) {
        json_error('Method not allowed.', 405);
    }
}

function ensure_allowed_source(string $source): string
{
    $source = strtolower(trim($source));
    $allowed = ['bank', 'ledger', 'sias', 'voucher'];
    if (!in_array($source, $allowed, true)) {
        json_error('Invalid source type.', 422);
    }

    return $source;
}

function get_setting(string $key, $default = null)
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT svalue FROM settings WHERE skey = :k LIMIT 1');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch();

    return $row ? $row['svalue'] : $default;
}

function get_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function check_csrf_if_present(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null;
    if ($token === null || $token === '') {
        return;
    }

    if (!hash_equals((string) $_SESSION['csrf_token'] ?? '', (string) $token)) {
        json_error('Invalid CSRF token.', 419);
    }
}

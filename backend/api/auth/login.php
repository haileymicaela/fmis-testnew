<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

require_method('POST');
check_csrf_if_present();

$payload = read_json_input();
$email = normalize_email((string) ($payload['email'] ?? ''));
$password = (string) ($payload['password'] ?? '');

if ($email === '' || $password === '' || !validate_email($email)) {
    json_error('Incorrect email or password. Please try again.', 401);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

if (!$user) {
    json_error('Incorrect email or password. Please try again.', 401);
}

$lockMinutes = (int) get_setting('lock_minutes', 15);
$lockThreshold = (int) get_setting('lock_threshold', 5);
$lockedUntil = $user['locked_until'] ? new DateTimeImmutable($user['locked_until']) : null;
$now = new DateTimeImmutable('now');

if ($lockedUntil && $lockedUntil > $now) {
    json_error('Account locked after 5 failed attempts. Try again in 15 minutes or contact the Accounting Office.', 423);
}

if (!password_verify($password, (string) $user['password_hash'])) {
    $failedAttempts = (int) $user['failed_attempts'] + 1;
    $lockAt = null;

    if ($failedAttempts >= $lockThreshold) {
        $lockAt = $now->modify('+' . $lockMinutes . ' minutes')->format('Y-m-d H:i:s');
    }

    $update = $pdo->prepare('UPDATE users SET failed_attempts = :failed, locked_until = :locked WHERE id = :id');
    $update->execute([
        ':failed' => $failedAttempts,
        ':locked' => $lockAt,
        ':id' => $user['id'],
    ]);

    if ($failedAttempts >= $lockThreshold) {
        json_error('Account locked after 5 failed attempts. Try again in 15 minutes or contact the Accounting Office.', 423);
    }

    json_error('Incorrect email or password. Please try again.', 401);
}

session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['user_name'] = (string) $user['full_name'];
$_SESSION['user_email'] = $user['email'];

$pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = :id')->execute([':id' => $user['id']]);
log_audit_event('User login', 'Authentication', $email, 'success', 'Successful login');

json_success([
    'user' => [
        'name' => $user['full_name'],
        'email' => $user['email'],
    ],
]);

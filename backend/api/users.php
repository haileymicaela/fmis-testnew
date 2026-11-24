<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_method('GET', 'POST', 'PATCH');
require_role(ROLE_HEAD_ACCOUNTANT);
header('Cache-Control: no-store');

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    json_success($pdo->query('SELECT id, full_name, email, role_label, last_login FROM users ORDER BY full_name, id')->fetchAll());
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals(get_csrf_token(), $token)) {
    json_error('Your session has changed. Refresh and try again.', 419);
}

$data = read_json_input();
$name = trim(is_string($data['full_name'] ?? null) ? $data['full_name'] : '');
$role = $data['role_label'] ?? null;
if ($name === '' || mb_strlen($name) > 80) {
    json_error('Enter a full name of 1 to 80 characters.', 422);
}
if (!in_array($role, [ROLE_BOOKKEEPER, ROLE_ACCOUNTANT], true)) {
    json_error('Choose Bookkeeper 1 or Accountant.', 422);
}

$parts = preg_split('/\s+/u', $name);
$initials = mb_substr($parts[0], 0, 1);
if (count($parts) > 1) {
    $initials .= mb_substr(end($parts), 0, 1);
}
$initials = mb_strtoupper($initials);
$values = [':name' => $name, ':role' => $role, ':initials' => mb_substr($initials, 0, 4)];

$creating = $method === 'POST';
if ($creating) {
    $email = normalize_email(is_string($data['email'] ?? null) ? $data['email'] : '');
    $password = $data['password'] ?? null;
    if (!validate_email($email) || strlen($email) > 120) {
        json_error('Enter a valid email address of up to 120 characters.', 422);
    }
    if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72 || strpos($password, "\0") !== false) {
        json_error('Enter a password from 12 to 72 bytes.', 422);
    }
    $values[':email'] = $email;
    $values[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
} else {
    $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        json_error('Select a valid user.', 422);
    }
}

$pdo->beginTransaction();
try {
    if ($creating) {
        $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role_label, initials)
            VALUES (:name, :email, :password_hash, :role, :initials)');
        $stmt->execute($values);
        $reference = 'USER-' . $pdo->lastInsertId();
        $beforeRole = null;
    } else {
        $stmt = $pdo->prepare('SELECT role_label FROM users WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $target = $stmt->fetch();
        if (!$target || $target['role_label'] === ROLE_HEAD_ACCOUNTANT) {
            $pdo->rollBack();
            json_error($target ? 'Head Accountant accounts are protected.' : 'User not found.', $target ? 403 : 404);
        }
        $stmt = $pdo->prepare('UPDATE users SET full_name = :name, role_label = :role, initials = :initials WHERE id = :id');
        $stmt->execute($values + [':id' => $id]);
        $reference = 'USER-' . $id;
        $beforeRole = $target['role_label'];
    }

    log_audit_event($creating ? 'User created' : 'User updated', 'User Management',
        $reference, 'success', null, null, $beforeRole, $role);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($creating && $error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
        json_error('That email address is already in use.', 409);
    }
    throw $error;
}

json_success(['saved' => true], $creating ? 201 : 200);

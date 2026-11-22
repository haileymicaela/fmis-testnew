<?php

declare(strict_types=1);

const ROLE_BOOKKEEPER = 'Bookkeeper 1';
const ROLE_ACCOUNTANT = 'Accountant';
const ROLE_HEAD_ACCOUNTANT = 'Head Accountant';

function require_auth(): array
{
    $user = current_user();
    if ($user === null) {
        json_error('Authentication required.', 401);
    }

    if (!in_array($user['role_label'], [ROLE_BOOKKEEPER, ROLE_ACCOUNTANT, ROLE_HEAD_ACCOUNTANT], true)) {
        json_error('An accounting role must be assigned to this account.', 403);
    }

    return $user;
}

function require_role(string ...$allowedRoles): void
{
    $user = require_auth();
    if (!in_array($user['role_label'], $allowedRoles, true)) {
        json_error('Your role does not have permission to perform this action.', 403);
    }
}

function current_user(): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId === null) {
        return null;
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, full_name, email, initials, role_label FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function log_audit_event(
    string $action,
    string $module,
    ?string $reference = null,
    string $result = 'success',
    ?string $note = null,
    ?string $sessionInfo = null,
    ?string $beforeStatus = null,
    ?string $afterStatus = null
): void {
    $pdo = db();
    $userId = $_SESSION['user_id'] ?? null;
    $userLabel = $_SESSION['user_name'] ?? 'System';
    $occurredAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO audit_log (event_id, occurred_at, user_id, user_label, action, module, reference, result, session_info, before_status, after_status, note)
         VALUES (:event_id, :occurred_at, :user_id, :user_label, :action, :module, :reference, :result, :session_info, :before_status, :after_status, :note)'
    );
    $stmt->execute([
        ':event_id' => 'EVT-' . strtoupper(bin2hex(random_bytes(6))),
        ':occurred_at' => $occurredAt,
        ':user_id' => $userId,
        ':user_label' => $userLabel,
        ':action' => $action,
        ':module' => $module,
        ':reference' => $reference,
        ':result' => $result,
        ':session_info' => $sessionInfo,
        ':before_status' => $beforeStatus,
        ':after_status' => $afterStatus,
        ':note' => $note,
    ]);
}

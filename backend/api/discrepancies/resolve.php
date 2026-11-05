<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

require_method('POST');
require_auth();
check_csrf_if_present();

$payload = read_json_input();
$id = (int) ($payload['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    json_error('A valid discrepancy identifier is required.', 422);
}

$status = strtolower(trim((string) ($payload['status'] ?? 'resolved')));
$note = trim((string) ($payload['note'] ?? ''));
if ($status !== 'resolved' && $status !== 'escalated') {
    $status = 'resolved';
}

$pdo = db();
$check = $pdo->prepare('SELECT * FROM discrepancies WHERE id = :id LIMIT 1');
$check->execute([':id' => $id]);
$discrepancy = $check->fetch();
if (!$discrepancy) {
    json_error('Discrepancy not found.', 404);
}

$pdo->prepare(
    'UPDATE discrepancies SET status = :status, resolution_note = :note, resolved_by = :user_id, resolved_at = NOW() WHERE id = :id'
)->execute([
    ':status' => $status,
    ':note' => $note !== '' ? $note : 'Updated by accounting review',
    ':user_id' => $_SESSION['user_id'] ?? null,
    ':id' => $id,
]);

log_audit_event($status === 'resolved' ? 'Discrepancy resolved' : 'Discrepancy escalated', 'Discrepancy Review', (string) $discrepancy['ref_code'], $status === 'resolved' ? 'resolved' : 'flagged', $note !== '' ? $note : 'No note provided');

$update = $pdo->prepare('SELECT * FROM discrepancies WHERE id = :id LIMIT 1');
$update->execute([':id' => $id]);

json_success($update->fetch());

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

$status = strtolower(trim((string) ($payload['status'] ?? '')));
if (!in_array($status, ['resolved', 'escalated'], true)) {
    json_error('Status must be "resolved" or "escalated".', 422);
}
$note = trim((string) ($payload['note'] ?? ''));
if ($note === '' || mb_strlen($note) > 255) {
    json_error('A resolution note of 1 to 255 characters is required.', 422);
}

function accept_date_variance(PDO $pdo, array $d): void
{
    $stmt = $pdo->prepare('SELECT id, source FROM records WHERE id IN (:a, :b) FOR UPDATE');
    $stmt->execute([':a' => (int) $d['record_id'], ':b' => (int) $d['counter_record_id']]);
    $bankId = $ledgerId = $supportId = null;
    foreach ($stmt->fetchAll() as $r) {
        if ($r['source'] === 'bank') { $bankId = (int) $r['id']; }
        elseif ($r['source'] === 'ledger') { $ledgerId = (int) $r['id']; }
        elseif (in_array($r['source'], ['sias', 'voucher'], true)) { $supportId = (int) $r['id']; }
    }
    $uid = $_SESSION['user_id'] ?? null;

    if ($bankId !== null && $ledgerId !== null) {
        $q = $pdo->prepare('SELECT * FROM matches WHERE bank_id = :b AND ledger_id = :l AND confirmed = 0 LIMIT 1 FOR UPDATE');
        $q->execute([':b' => $bankId, ':l' => $ledgerId]);
        $m = $q->fetch();
        if (!$m || (int) $m['a_ok'] !== 1 || (int) $m['r_ok'] !== 1) {
            json_error('Only date-only variances can be accepted; amount or reference differs.', 422);
        }
        $c = $pdo->prepare('SELECT id FROM matches WHERE confirmed = 1 AND id <> :id
            AND (bank_id IN (:b1, :l1) OR ledger_id IN (:b2, :l2)) LIMIT 1 FOR UPDATE');
        $c->execute([':id' => $m['id'], ':b1' => $bankId, ':l1' => $ledgerId, ':b2' => $bankId, ':l2' => $ledgerId]);
        if ($c->fetch()) { json_error('A confirmed match already uses one of these records.', 409); }
        $pdo->prepare('UPDATE matches SET confirmed = 1, confirmed_by = :u, confirmed_at = NOW(),
            result = "matched", note = "Date variance accepted by reviewer" WHERE id = :id')
            ->execute([':u' => $uid, ':id' => $m['id']]);
        $pdo->prepare('UPDATE records SET rec_status = "matched" WHERE id IN (:b, :l)')
            ->execute([':b' => $bankId, ':l' => $ledgerId]);
        return;
    }

    if ($ledgerId !== null && $supportId !== null) {
        $q = $pdo->prepare('SELECT * FROM support_matches WHERE ledger_id = :l AND support_record_id = :s AND confirmed = 0 LIMIT 1 FOR UPDATE');
        $q->execute([':l' => $ledgerId, ':s' => $supportId]);
        $m = $q->fetch();
        if (!$m || (int) $m['amount_ok'] !== 1 || (int) $m['reference_ok'] !== 1) {
            json_error('Only date-only variances can be accepted; amount or reference differs.', 422);
        }
        $c = $pdo->prepare('SELECT id FROM support_matches WHERE support_record_id = :s AND confirmed = 1 AND id <> :id LIMIT 1 FOR UPDATE');
        $c->execute([':s' => $supportId, ':id' => $m['id']]);
        if ($c->fetch()) { json_error('This supporting record is already confirmed against another entry.', 409); }
        $pdo->prepare('UPDATE support_matches SET confirmed = 1, confirmed_by = :u, confirmed_at = NOW(),
            result = "matched", note = "Date variance accepted by reviewer" WHERE id = :id')
            ->execute([':u' => $uid, ':id' => $m['id']]);
        $pdo->prepare('UPDATE records SET rec_status = "matched" WHERE id = :s')->execute([':s' => $supportId]);
    }
}

$pdo = db();
$pdo->beginTransaction();
try {
    $check = $pdo->prepare('SELECT * FROM discrepancies WHERE id = :id LIMIT 1 FOR UPDATE');
    $check->execute([':id' => $id]);
    $discrepancy = $check->fetch();
    if (!$discrepancy) { json_error('Discrepancy not found.', 404); }
    if ($discrepancy['status'] === 'resolved') { json_error('This discrepancy is already resolved.', 409); }

    if ($status === 'resolved' && $discrepancy['issue_type'] === 'date_gap'
        && $discrepancy['counter_record_id'] !== null) {
        accept_date_variance($pdo, $discrepancy);
    }

    $resolved = $status === 'resolved';
    $pdo->prepare('UPDATE discrepancies SET status = :status, resolution_note = :note,
        resolved_by = :u, resolved_at = :at WHERE id = :id')->execute([
        ':status' => $status,
        ':note'   => $note,
        ':u'      => $resolved ? ($_SESSION['user_id'] ?? null) : null,
        ':at'     => $resolved ? (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s') : null,
        ':id'     => $id,
    ]);

    log_audit_event(
        $resolved ? 'Discrepancy resolved' : 'Discrepancy escalated',
        'Discrepancy Review', (string) $discrepancy['ref_code'],
        $resolved ? 'resolved' : 'flagged', $note, null,
        (string) $discrepancy['status'], $status
    );
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    throw $e;
}

$out = $pdo->prepare('SELECT * FROM discrepancies WHERE id = :id LIMIT 1');
$out->execute([':id' => $id]);
json_success($out->fetch());
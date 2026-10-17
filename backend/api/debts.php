<?php
require_once __DIR__ . '/../db.php';

$m = method();
$user = require_admin(); // Owner-only, same as the rest of finance.php

$PAYMENT_METHODS = ['cash', 'bank_transfer', 'mobile_money', 'check', 'other'];

/* ---- Summary: total outstanding receivables & payables ---- */
if ($m === 'GET' && ($_GET['action'] ?? '') === 'summary') {
  $receivable = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM finance_debts WHERE type='receivable' AND status='unpaid'")->fetchColumn();
  $payable = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM finance_debts WHERE type='payable' AND status='unpaid'")->fetchColumn();
  $overdueReceivable = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM finance_debts WHERE type='receivable' AND status='unpaid' AND due_date IS NOT NULL AND due_date < CURDATE()")->fetchColumn();
  $overduePayable = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM finance_debts WHERE type='payable' AND status='unpaid' AND due_date IS NOT NULL AND due_date < CURDATE()")->fetchColumn();

  json_out([
    'ok' => true,
    'total_receivable' => round($receivable, 2),
    'total_payable' => round($payable, 2),
    'overdue_receivable' => round($overdueReceivable, 2),
    'overdue_payable' => round($overduePayable, 2),
    'net_outstanding' => round($receivable - $payable, 2),
  ]);
}

/* ---- LIST debts (filter by type/status) ---- */
if ($m === 'GET' && !isset($_GET['id'])) {
  $where = [];
  $params = [];
  if (!empty($_GET['type'])) { $where[] = 'd.type = ?'; $params[] = $_GET['type']; }
  if (!empty($_GET['status'])) { $where[] = 'd.status = ?'; $params[] = $_GET['status']; }

  $sql = 'SELECT d.*, u.full_name AS created_by_name, o.customer_name AS order_name
          FROM finance_debts d
          LEFT JOIN users u ON u.id = d.created_by
          LEFT JOIN orders o ON o.id = d.order_id';
  if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
  $sql .= ' ORDER BY (d.status = "unpaid") DESC, d.due_date IS NULL, d.due_date ASC, d.created_at DESC';

  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  json_out(['ok' => true, 'debts' => $stmt->fetchAll()]);
}

/* ---- CREATE a receivable or payable ---- */
if ($m === 'POST' && !isset($_GET['action'])) {
  $in = json_input();
  $type = $in['type'] ?? '';
  $partyName = trim($in['party_name'] ?? '');
  $amount = $in['amount'] ?? null;
  $description = trim($in['description'] ?? '');
  $category = trim($in['category'] ?? '');
  $dueDate = trim($in['due_date'] ?? '');
  $orderId = !empty($in['order_id']) ? (int)$in['order_id'] : null;

  if (!in_array($type, ['receivable', 'payable'], true)) json_error('Invalid type');
  if ($partyName === '') json_error('ከማን/ለማን የሚለው ስም ያስፈልጋል / Party name is required');
  if (!is_numeric($amount) || (float)$amount <= 0) json_error('ትክክለኛ መጠን ያስፈልጋል / Valid amount required');

  $stmt = db()->prepare(
    'INSERT INTO finance_debts (type, party_name, amount, description, category, due_date, order_id, created_by)
     VALUES (?,?,?,?,?,?,?,?)'
  );
  $stmt->execute([
    $type, $partyName, number_format(round((float)$amount + 1e-9, 2), 2, '.', ''),
    $description ?: null, $category ?: null,
    $dueDate ?: null, $orderId, $user['id'],
  ]);
  json_out(['ok' => true, 'id' => db()->lastInsertId()]);
}

/* ---- MARK PAID: records the actual cash movement in the main ledger ---- */
if ($m === 'POST' && ($_GET['action'] ?? '') === 'pay' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  $in = json_input();

  $stmt = db()->prepare('SELECT * FROM finance_debts WHERE id=?');
  $stmt->execute([$id]);
  $debt = $stmt->fetch();
  if (!$debt) json_error('Not found', 404);
  if ($debt['status'] === 'paid') json_error('ይህ ዕዳ አስቀድሞ ተከፍሏል / Already marked paid');

  $paymentMethod = $in['payment_method'] ?? 'cash';
  $bankReference = trim($in['bank_reference'] ?? '');
  $bankName = trim($in['bank_name'] ?? '');
  $txnDate = trim($in['txn_date'] ?? '') ?: date('Y-m-d');

  if (!in_array($paymentMethod, $PAYMENT_METHODS, true)) json_error('Invalid payment method');
  if ($paymentMethod === 'bank_transfer' && $bankName === '') json_error('የባንኩ ስም ያስፈልጋል / Bank name is required for bank transfers');
  if (in_array($paymentMethod, ['bank_transfer', 'mobile_money'], true) && $bankReference === '') {
    json_error('የባንክ/ሞባይል ትራንዛክሽን ቁጥር ያስፈልጋል / A transaction reference number is required for bank/mobile payments');
  }

  db()->beginTransaction();
  try {
    // receivable being collected = income; payable being settled = expense
    $txnType = $debt['type'] === 'receivable' ? 'income' : 'expense';
    $category = $debt['category'] ?: ($debt['type'] === 'receivable' ? 'ያልተሰበሰበ ገቢ ተሰብስቧል' : 'ያልተከፈለ እዳ ተከፍሏል');

    $stmt = db()->prepare(
      'INSERT INTO finance_transactions
         (type, category, amount, description, party_name, payment_method, bank_name, bank_reference, order_id, txn_date, created_by)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
      $txnType, $category, $debt['amount'],
      $debt['description'], $debt['party_name'],
      $paymentMethod, ($paymentMethod === 'bank_transfer' ? $bankName : '') ?: null, $bankReference ?: null,
      $debt['order_id'], $txnDate, $user['id'],
    ]);
    $txnId = db()->lastInsertId();

    db()->prepare("UPDATE finance_debts SET status='paid', paid_at=NOW(), linked_transaction_id=? WHERE id=?")
        ->execute([$txnId, $id]);

    db()->commit();
  } catch (Exception $e) {
    db()->rollBack();
    json_error('ስህተት ተከስቷል / Something went wrong while recording payment', 500);
  }

  json_out(['ok' => true, 'transaction_id' => $txnId]);
}

/* ---- UPDATE (only while still unpaid) ---- */
if ($m === 'PUT' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  $stmt = db()->prepare('SELECT status FROM finance_debts WHERE id=?');
  $stmt->execute([$id]);
  $row = $stmt->fetch();
  if (!$row) json_error('Not found', 404);
  if ($row['status'] === 'paid') json_error('የተከፈለ ዕዳ ማስተካከል አይቻልም / Cannot edit a paid record');

  $in = json_input();
  $fields = [];
  $params = [];
  if (isset($in['party_name'])) { $fields[] = 'party_name=?'; $params[] = trim($in['party_name']); }
  if (isset($in['amount']) && is_numeric($in['amount'])) { $fields[] = 'amount=?'; $params[] = number_format(round((float)$in['amount'] + 1e-9, 2), 2, '.', ''); }
  if (isset($in['description'])) { $fields[] = 'description=?'; $params[] = trim($in['description']); }
  if (isset($in['due_date'])) { $fields[] = 'due_date=?'; $params[] = $in['due_date'] ?: null; }
  if (!$fields) json_error('Nothing to update');

  $params[] = $id;
  db()->prepare('UPDATE finance_debts SET ' . implode(',', $fields) . ' WHERE id=?')->execute($params);
  json_out(['ok' => true]);
}

/* ---- DELETE (only while still unpaid, to protect ledger integrity) ---- */
if ($m === 'DELETE' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  $stmt = db()->prepare('SELECT status FROM finance_debts WHERE id=?');
  $stmt->execute([$id]);
  $row = $stmt->fetch();
  if (!$row) json_error('Not found', 404);
  if ($row['status'] === 'paid') json_error('የተከፈለ ዕዳ መሰረዝ አይቻልም / Cannot delete a paid record');

  db()->prepare('DELETE FROM finance_debts WHERE id=?')->execute([$id]);
  json_out(['ok' => true]);
}

json_error('Unsupported request', 405);

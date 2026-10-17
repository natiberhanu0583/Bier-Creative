<?php
require_once __DIR__ . '/../db.php';

$m = method();
$user = require_admin(); // whole file: Owner-only (require_admin checks role === 'owner')

$PAYMENT_METHODS = ['cash', 'bank_transfer', 'mobile_money', 'check', 'other'];

/* ---- Daily income/expense series, for the dashboard revenue chart ---- */
if ($m === 'GET' && ($_GET['chart'] ?? '') === 'daily') {
  $days = max(7, min(90, (int)($_GET['days'] ?? 30)));
  $stmt = db()->prepare(
    "SELECT txn_date,
            SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS income,
            SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense
     FROM finance_transactions
     WHERE txn_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
     GROUP BY txn_date ORDER BY txn_date ASC"
  );
  $stmt->execute([$days]);
  $rows = $stmt->fetchAll();

  $byDate = [];
  foreach ($rows as $r) $byDate[$r['txn_date']] = $r;
  $series = [];
  for ($i = $days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $series[] = [
      'date' => $d,
      'income' => isset($byDate[$d]) ? round((float)$byDate[$d]['income'], 2) : 0,
      'expense' => isset($byDate[$d]) ? round((float)$byDate[$d]['expense'], 2) : 0,
    ];
  }
  json_out(['ok' => true, 'series' => $series]);
}

/* ---- Completed orders still waiting for their payment to be recorded ----
   An order counts as "paid" once an income transaction is linked to it (order_id). */
if ($m === 'GET' && ($_GET['action'] ?? '') === 'pending_orders') {
  $rows = db()->query(
    "SELECT o.id, o.customer_name, o.phone, o.service, o.budget, o.updated_at AS completed_at
     FROM orders o
     WHERE o.status='completed'
       AND NOT EXISTS (SELECT 1 FROM finance_transactions t WHERE t.order_id = o.id AND t.type='income')
     ORDER BY o.updated_at DESC LIMIT 100"
  )->fetchAll();
  json_out(['ok' => true, 'orders' => $rows]);
}

/* ---- Period summary: day / week / month / lifetime ----
   ?action=summary&period=day&date=YYYY-MM-DD   (date optional, defaults to today)
   ?action=summary&period=week&date=YYYY-MM-DD  (the Mon-Sun week containing that date)
   ?action=summary&period=month&date=YYYY-MM-DD (the calendar month containing that date)
   ?action=summary&period=lifetime               (all-time, no date needed) */
if ($m === 'GET' && ($_GET['action'] ?? '') === 'summary') {
  $period = $_GET['period'] ?? 'day';
  $date = $_GET['date'] ?? date('Y-m-d');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

  $where = '1=1';
  $params = [];
  $label = '';

  if ($period === 'day') {
    $where = 'txn_date = ?';
    $params = [$date];
    $label = $date;
  } elseif ($period === 'week') {
    $stmt = db()->prepare("SELECT YEARWEEK(?, 1) AS yw");
    $stmt->execute([$date]);
    $yw = $stmt->fetchColumn();
    $where = 'YEARWEEK(txn_date, 1) = ?';
    $params = [$yw];
    $weekStart = date('Y-m-d', strtotime($date . ' -' . (date('N', strtotime($date)) - 1) . ' days'));
    $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
    $label = "$weekStart to $weekEnd";
  } elseif ($period === 'month') {
    $where = "DATE_FORMAT(txn_date, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')";
    $params = [$date];
    $label = date('Y-m', strtotime($date));
  } elseif ($period === 'lifetime') {
    $where = '1=1';
    $params = [];
    $label = 'lifetime';
  } else {
    json_error('Invalid period');
  }

  $stmt = db()->prepare(
    "SELECT
       COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END), 0) AS income,
       COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) AS expense,
       COUNT(*) AS txn_count,
       MIN(txn_date) AS first_txn_date
     FROM finance_transactions WHERE $where"
  );
  $stmt->execute($params);
  $row = $stmt->fetch();

  $income = round((float)$row['income'], 2);
  $expense = round((float)$row['expense'], 2);

  $stmt2 = db()->prepare(
    "SELECT type, category, SUM(amount) AS total, COUNT(*) AS cnt
     FROM finance_transactions WHERE $where GROUP BY type, category ORDER BY total DESC"
  );
  $stmt2->execute($params);
  $byCategory = $stmt2->fetchAll();

  json_out([
    'ok' => true,
    'period' => $period,
    'label' => $label,
    'income' => $income,
    'expense' => $expense,
    'net' => round($income - $expense, 2),
    'txn_count' => (int)$row['txn_count'],
    'first_txn_date' => $row['first_txn_date'],
    'by_category' => $byCategory,
  ]);
}

/* ---- Breakdown by day (week/month) or by month (lifetime) ----
   Powers the "የየእለቱ ገቢ/ወጪ" table so the owner can see every single day's
   (or, for lifetime, every month's) income/expense at a glance.
   ?action=breakdown&period=week|month|lifetime&date=YYYY-MM-DD */
if ($m === 'GET' && ($_GET['action'] ?? '') === 'breakdown') {
  $period = $_GET['period'] ?? 'week';
  $date = $_GET['date'] ?? date('Y-m-d');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

  if ($period === 'week') {
    $weekStart = date('Y-m-d', strtotime($date . ' -' . (date('N', strtotime($date)) - 1) . ' days'));
    $stmt = db()->prepare(
      "SELECT txn_date AS bucket,
              SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS income,
              SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense
       FROM finance_transactions
       WHERE txn_date BETWEEN ? AND DATE_ADD(?, INTERVAL 6 DAY)
       GROUP BY txn_date"
    );
    $stmt->execute([$weekStart, $weekStart]);
    $rows = $stmt->fetchAll();
    $byBucket = [];
    foreach ($rows as $r) $byBucket[$r['bucket']] = $r;

    $out = [];
    for ($i = 0; $i < 7; $i++) {
      $d = date('Y-m-d', strtotime("$weekStart +$i days"));
      $out[] = [
        'bucket' => $d,
        'income' => isset($byBucket[$d]) ? round((float)$byBucket[$d]['income'], 2) : 0,
        'expense' => isset($byBucket[$d]) ? round((float)$byBucket[$d]['expense'], 2) : 0,
      ];
    }
    json_out(['ok' => true, 'unit' => 'day', 'breakdown' => $out]);
  }

  if ($period === 'month') {
    $monthStart = date('Y-m-01', strtotime($date));
    $daysInMonth = (int)date('t', strtotime($date));
    $stmt = db()->prepare(
      "SELECT txn_date AS bucket,
              SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS income,
              SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense
       FROM finance_transactions
       WHERE DATE_FORMAT(txn_date, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')
       GROUP BY txn_date"
    );
    $stmt->execute([$date]);
    $rows = $stmt->fetchAll();
    $byBucket = [];
    foreach ($rows as $r) $byBucket[$r['bucket']] = $r;

    $out = [];
    for ($i = 0; $i < $daysInMonth; $i++) {
      $d = date('Y-m-d', strtotime("$monthStart +$i days"));
      $out[] = [
        'bucket' => $d,
        'income' => isset($byBucket[$d]) ? round((float)$byBucket[$d]['income'], 2) : 0,
        'expense' => isset($byBucket[$d]) ? round((float)$byBucket[$d]['expense'], 2) : 0,
      ];
    }
    json_out(['ok' => true, 'unit' => 'day', 'breakdown' => $out]);
  }

  if ($period === 'lifetime') {
    // Too many days to list one-by-one over the company's lifetime, so group by month.
    $stmt = db()->query(
      "SELECT DATE_FORMAT(txn_date, '%Y-%m') AS bucket,
              SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS income,
              SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense
       FROM finance_transactions
       GROUP BY DATE_FORMAT(txn_date, '%Y-%m')
       ORDER BY bucket ASC"
    );
    $rows = $stmt->fetchAll();
    $out = array_map(fn($r) => [
      'bucket' => $r['bucket'],
      'income' => round((float)$r['income'], 2),
      'expense' => round((float)$r['expense'], 2),
    ], $rows);
    json_out(['ok' => true, 'unit' => 'month', 'breakdown' => $out]);
  }

  json_error('Invalid period');
}

/* ---- LIST transactions (with optional period/date filters) ---- */
if ($m === 'GET' && !isset($_GET['id'])) {
  $where = [];
  $params = [];
  if (!empty($_GET['type'])) { $where[] = 'type = ?'; $params[] = $_GET['type']; }
  if (!empty($_GET['from'])) { $where[] = 'txn_date >= ?'; $params[] = $_GET['from']; }
  if (!empty($_GET['to']))   { $where[] = 'txn_date <= ?'; $params[] = $_GET['to']; }

  if (!empty($_GET['period']) && $_GET['period'] !== 'lifetime') {
    $date = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    if ($_GET['period'] === 'day') {
      $where[] = 'txn_date = ?'; $params[] = $date;
    } elseif ($_GET['period'] === 'week') {
      $stmt = db()->prepare("SELECT YEARWEEK(?, 1)"); $stmt->execute([$date]);
      $where[] = 'YEARWEEK(txn_date, 1) = ?'; $params[] = $stmt->fetchColumn();
    } elseif ($_GET['period'] === 'month') {
      $where[] = "DATE_FORMAT(txn_date, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')"; $params[] = $date;
    }
  }

  $sql = 'SELECT f.*, u.full_name AS created_by_name, o.customer_name AS order_name
          FROM finance_transactions f
          LEFT JOIN users u ON u.id = f.created_by
          LEFT JOIN orders o ON o.id = f.order_id';
  if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
  $sql .= ' ORDER BY f.txn_date DESC, f.id DESC';

  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();

  $income = 0.0; $expense = 0.0;
  foreach ($rows as $r) {
    if ($r['type'] === 'income') $income += (float)$r['amount'];
    else $expense += (float)$r['amount'];
  }

  json_out([
    'ok' => true,
    'transactions' => $rows,
    'summary' => [
      'total_income' => round($income, 2),
      'total_expense' => round($expense, 2),
      'net_profit' => round($income - $expense, 2),
    ],
  ]);
}

/* ---- CREATE transaction ----
   Strict validation: every transaction records who it was with (party_name)
   and how it moved (payment_method); bank/mobile transfers must include a
   reference number, so the ledger stays auditable. */
if ($m === 'POST') {
  $in = json_input();
  $type = $in['type'] ?? '';
  $category = trim($in['category'] ?? '');
  $amount = $in['amount'] ?? null;
  $date = trim($in['txn_date'] ?? '');
  $partyName = trim($in['party_name'] ?? '');
  $paymentMethod = $in['payment_method'] ?? 'cash';
  $bankReference = trim($in['bank_reference'] ?? '');
  $bankName = trim($in['bank_name'] ?? '');

  if (!in_array($type, ['income', 'expense'], true)) json_error('Invalid type');
  if ($category === '') json_error('Category required / ምድብ ያስፈልጋል');
  if (!is_numeric($amount) || (float)$amount <= 0) json_error('Valid amount required / ትክክለኛ መጠን ያስፈልጋል');
  $amount = number_format(round((float)$amount + 1e-9, 2), 2, '.', '');
  if (!in_array($paymentMethod, $PAYMENT_METHODS, true)) json_error('Invalid payment method');
  if ($date === '') $date = date('Y-m-d');

  if ($paymentMethod === 'bank_transfer' && $bankName === '') json_error('የባንኩ ስም ያስፈልጋል / Bank name is required for bank transfers');
  if ($partyName === '') json_error('ከማን/ለማን የሚለው ስም ያስፈልጋል / Party name (from/to) is required');
  if (in_array($paymentMethod, ['bank_transfer', 'mobile_money'], true) && $bankReference === '') {
    json_error('የባንክ/ሞባይል ትራንዛክሽን ቁጥር ያስፈልጋል / A transaction reference number is required for bank/mobile payments');
  }

  if ($type === 'income' && !empty($in['order_id'])) {
    $dup = db()->prepare("SELECT COUNT(*) FROM finance_transactions WHERE order_id=? AND type='income'");
    $dup->execute([(int)$in['order_id']]);
    if ((int)$dup->fetchColumn() > 0) json_error('ለዚህ ኦርደር ክፍያ አስቀድሞ ተመዝግቧል / Payment already recorded for this order');
  }

  $stmt = db()->prepare(
    'INSERT INTO finance_transactions
       (type, category, amount, description, party_name, payment_method, bank_name, bank_reference, order_id, txn_date, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
  );
  $stmt->execute([
    $type, $category, $amount,
    trim($in['description'] ?? '') ?: null,
    $partyName,
    $paymentMethod,
    ($paymentMethod === 'bank_transfer' ? $bankName : '') ?: null,
    $bankReference ?: null,
    !empty($in['order_id']) ? (int)$in['order_id'] : null,
    $date, $user['id'],
  ]);
  json_out(['ok' => true, 'id' => db()->lastInsertId()]);
}

/* ---- UPDATE transaction ---- */
if ($m === 'PUT' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  $in = json_input();
  $fields = [];
  $params = [];

  if (isset($in['type']) && in_array($in['type'], ['income','expense'], true)) { $fields[] = 'type=?'; $params[] = $in['type']; }
  if (isset($in['category'])) { $fields[] = 'category=?'; $params[] = trim($in['category']); }
  if (isset($in['amount']) && is_numeric($in['amount'])) { $fields[] = 'amount=?'; $params[] = number_format(round((float)$in['amount'] + 1e-9, 2), 2, '.', ''); }
  if (isset($in['description'])) { $fields[] = 'description=?'; $params[] = trim($in['description']); }
  if (isset($in['party_name'])) { $fields[] = 'party_name=?'; $params[] = trim($in['party_name']); }
  if (isset($in['payment_method'])) {
    if (!in_array($in['payment_method'], $PAYMENT_METHODS, true)) json_error('Invalid payment method');
    $fields[] = 'payment_method=?'; $params[] = $in['payment_method'];
  }
  if (isset($in['bank_name'])) { $fields[] = 'bank_name=?'; $params[] = trim($in['bank_name']) ?: null; }
  if (isset($in['bank_reference'])) { $fields[] = 'bank_reference=?'; $params[] = trim($in['bank_reference']) ?: null; }
  if (isset($in['txn_date'])) { $fields[] = 'txn_date=?'; $params[] = $in['txn_date']; }
  if (!$fields) json_error('Nothing to update');

  $params[] = $id;
  db()->prepare('UPDATE finance_transactions SET ' . implode(',', $fields) . ' WHERE id=?')->execute($params);
  json_out(['ok' => true]);
}

/* ---- DELETE transaction ---- */
if ($m === 'DELETE' && isset($_GET['id'])) {
  db()->prepare('DELETE FROM finance_transactions WHERE id=?')->execute([(int)$_GET['id']]);
  json_out(['ok' => true]);
}

json_error('Unsupported request', 405);

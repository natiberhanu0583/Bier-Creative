<?php
require_once __DIR__ . '/../db.php';

$m = method();

/* ---- OWNER ONLY: delete one order (?id=) ---- */
if ($m === 'DELETE' && isset($_GET['id'])) {
  require_admin();
  $st = db()->prepare('DELETE FROM orders WHERE id = ?');
  $st->execute([(int)$_GET['id']]);
  if (!$st->rowCount()) json_error('ኦርደሩ አልተገኘም', 404);
  json_out(['ok' => true, 'deleted' => 1]);
}

/* ---- OWNER ONLY: delete many orders at once  POST ?bulk_delete=1  {ids:[1,2,3]} ---- */
if ($m === 'POST' && isset($_GET['bulk_delete'])) {
  require_admin();
  $in = json_input();
  $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['ids'] ?? [])), fn($v) => $v > 0)));
  if (!$ids) json_error('ምንም ኦርደር አልተመረጠም');
  if (count($ids) > 500) json_error('በአንድ ጊዜ እስከ 500 ኦርደር ብቻ');
  $ph = implode(',', array_fill(0, count($ids), '?'));
  $st = db()->prepare("DELETE FROM orders WHERE id IN ($ph)");
  $st->execute($ids);
  json_out(['ok' => true, 'deleted' => $st->rowCount()]);
}

/* ---- PUBLIC: create order from the website's order form ----
   OR authenticated manual entry (?source=manual) for orders taken by
   phone/walk-in/etc — logged in staff/owner/manager only. */
if ($m === 'POST' && !isset($_GET['note']) && !isset($_GET['id'])) {
  $isManual = ($_GET['source'] ?? '') === 'manual';
  $creator = null;
  if ($isManual) $creator = require_login();

  $in = json_input();
  $name = trim($in['name'] ?? '');
  $phone = trim($in['phone'] ?? '');
  if ($name === '' || $phone === '') json_error('ስም እና ስልክ ያስፈልጋሉ');

  // A non-elevated staff member registering a walk-in/phone order is
  // naturally the one handling it, unless they explicitly assign someone else.
  $assignedTo = null;
  if ($isManual) {
    if (isset($in['assigned_to']) && is_elevated($creator['role'])) {
      $assignedTo = $in['assigned_to'] ? (int)$in['assigned_to'] : null;
    } else {
      $assignedTo = $creator['id'];
    }
  }

  // Public-form protection: ignore an identical repeat from the same phone within 10 minutes,
  // and cap website submissions (anti-spam) at 40 per 10 minutes overall.
  if (!$isManual) {
    $d = db()->prepare("SELECT id FROM orders WHERE phone=? AND customer_name=? AND source='website' AND created_at > (NOW() - INTERVAL 10 MINUTE) ORDER BY id DESC LIMIT 1");
    $d->execute([$phone, $name]);
    if ($dupId = $d->fetchColumn()) json_out(['ok' => true, 'id' => (int)$dupId, 'duplicate' => true]);
    if ((int)db()->query("SELECT COUNT(*) FROM orders WHERE source='website' AND created_at > (NOW() - INTERVAL 10 MINUTE)")->fetchColumn() >= 40) json_error('እባክዎ ትንሽ ቆይተው ይሞክሩ / Please try again in a few minutes', 429);
    if (mb_strlen($name) > 150 || strlen($phone) > 40) json_error('Invalid input');
  }

  $stmt = db()->prepare(
    'INSERT INTO orders (customer_name, phone, email, service, budget, message, source, created_by, assigned_to)
     VALUES (?,?,?,?,?,?,?,?,?)'
  );
  $stmt->execute([
    $name, $phone,
    trim($in['email'] ?? '') ?: null,
    trim($in['service'] ?? '') ?: null,
    trim($in['budget'] ?? '') ?: null,
    trim($in['message'] ?? '') ?: null,
    $isManual ? 'manual' : 'website',
    $isManual ? $creator['id'] : null,
    $assignedTo,
  ]);

  $newId = db()->lastInsertId();
  if ($isManual && $assignedTo && (int)$assignedTo !== (int)$creator['id']) {
    require_once __DIR__ . '/../push.php';
    notify_user((int)$assignedTo, '🛒 ኦርደር ተመድቦልዎታል', $name . (trim($in['service'] ?? '') ? ' — ' . trim($in['service']) : ''), 'orders');
  }

  // Record the very first activity-log entry for manually-entered orders,
  // so the "who handled it / what happened" trail starts from creation.
  if ($isManual) {
    db()->prepare('INSERT INTO order_notes (order_id, user_id, note) VALUES (?,?,?)')
        ->execute([$newId, $creator['id'], 'ደንበኛውን በቀጥታ (ከድህረ ገጽ ውጭ) አስተናግደው መዘገቡ']);
    work_log((int)$creator['id'], 'order', (int)$newId, $name . (trim($in['service'] ?? '') ? ' — ' . trim($in['service']) : ''), 'order_created', 'በቀጥታ የተመዘገበ ኦርደር');
  }

  if (!$isManual) { require_once __DIR__ . '/../push.php'; push_wake(); }
  json_out(['ok' => true, 'id' => $newId]);
}

/* ---- everything below requires staff/admin login ---- */
$user = require_login();

/* ---- LIST orders (with optional filters) ---- */
if ($m === 'GET' && !isset($_GET['id'])) {
  $where = [];
  $params = [];

  if (!empty($_GET['status'])) { $where[] = 'status = ?'; $params[] = $_GET['status']; }
  if (!empty($_GET['source'])) { $where[] = 'source = ?'; $params[] = $_GET['source']; }

  // staff only see orders assigned to them, unless they're admin/manager
  if (!is_elevated($user['role'])) {
    $where[] = 'assigned_to = ?';
    $params[] = $user['id'];
  } elseif (!empty($_GET['assigned_to'])) {
    $where[] = 'assigned_to = ?';
    $params[] = $_GET['assigned_to'];
  }

  $sql = 'SELECT o.*, u.full_name AS assigned_name, u.phone AS assigned_phone, c.full_name AS created_by_name
          FROM orders o
          LEFT JOIN users u ON u.id = o.assigned_to
          LEFT JOIN users c ON c.id = o.created_by';
  if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
  $sql .= ' ORDER BY o.created_at DESC';

  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  json_out(['ok' => true, 'orders' => $stmt->fetchAll()]);
}

/* ---- GET single order + notes ---- */
if ($m === 'GET' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  $stmt = db()->prepare(
    'SELECT o.*, u.full_name AS assigned_name, u.phone AS assigned_phone, c.full_name AS created_by_name
     FROM orders o
     LEFT JOIN users u ON u.id=o.assigned_to
     LEFT JOIN users c ON c.id=o.created_by
     WHERE o.id=?'
  );
  $stmt->execute([$id]);
  $order = $stmt->fetch();
  if (!$order) json_error('Not found', 404);
  if (!is_elevated($user['role']) && (int)$order['assigned_to'] !== (int)$user['id']) {
    json_error('Forbidden', 403);
  }

  $n = db()->prepare('SELECT on2.*, u.full_name FROM order_notes on2 LEFT JOIN users u ON u.id=on2.user_id WHERE order_id=? ORDER BY created_at ASC');
  $n->execute([$id]);
  $order['notes'] = $n->fetchAll();

  json_out(['ok' => true, 'order' => $order]);
}

/* ---- UPDATE order (status / assignment) ---- */
if ($m === 'PUT' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  $in = json_input();

  $stmt = db()->prepare('SELECT * FROM orders WHERE id=?');
  $stmt->execute([$id]);
  $order = $stmt->fetch();
  if (!$order) json_error('Not found', 404);
  if (!is_elevated($user['role']) && (int)$order['assigned_to'] !== (int)$user['id']) {
    json_error('Forbidden', 403);
  }

  $fields = [];
  $params = [];
  $statusChanged = null;
  $reassigned = null;

  if (isset($in['status'])) {
    $allowed = ['new','contacted','in_progress','completed','cancelled'];
    if (!in_array($in['status'], $allowed, true)) json_error('Invalid status');
    if ($in['status'] !== $order['status']) $statusChanged = $in['status'];
    $fields[] = 'status = ?';
    $params[] = $in['status'];
  }

  if (isset($in['assigned_to']) && is_elevated($user['role'])) {
    $newAssignee = $in['assigned_to'] === null ? null : (int)$in['assigned_to'];
    if ($newAssignee !== (int)$order['assigned_to']) $reassigned = $newAssignee;
    $fields[] = 'assigned_to = ?';
    $params[] = $newAssignee;
  }

  if (!$fields) json_error('Nothing to update');

  $params[] = $id;
  $sql = 'UPDATE orders SET ' . implode(', ', $fields) . ' WHERE id = ?';
  db()->prepare($sql)->execute($params);
  if ($reassigned && (int)$reassigned !== (int)$user['id']) {
    require_once __DIR__ . '/../push.php';
    notify_user((int)$reassigned, '🛒 ኦርደር ተመድቦልዎታል', ($order['customer_name'] ?? '') . ($order['service'] ? ' — ' . $order['service'] : ''), 'orders');
  }

  // Auto-log status/assignment changes into the activity trail, so "who did
  // what and when" is always visible without requiring a manual note.
  $STATUS_LABEL = ['new'=>'አዲስ','contacted'=>'ተደውሎለታል','in_progress'=>'በሂደት ላይ','completed'=>'ተጠናቋል','cancelled'=>'ተሰርዟል'];
  if ($statusChanged !== null) {
    db()->prepare('INSERT INTO order_notes (order_id, user_id, note) VALUES (?,?,?)')
        ->execute([$id, $user['id'], 'ሁኔታውን ወደ "' . $STATUS_LABEL[$statusChanged] . '" ቀይሮታል']);
    work_log((int)$user['id'], 'order', $id, ($order['customer_name'] ?? '') . ($order['service'] ? ' — ' . $order['service'] : ''),
      $statusChanged === 'completed' ? 'order_completed' : 'order_status', 'ሁኔታ: ' . $STATUS_LABEL[$order['status']] . ' → ' . $STATUS_LABEL[$statusChanged]);
  }
  // Finance heads-up: an order was completed -> tell the owner(s) (the Finance tab is owner-only).
  if ($statusChanged === 'completed') {
    try {
      require_once __DIR__ . '/../push.php';
      $who = trim((string)($user['full_name'] ?? ''));
      $body = ($order['customer_name'] ?? '') . ($order['service'] ? ' — ' . $order['service'] : '') . ($order['budget'] ? ' (' . $order['budget'] . ')' : '') . ($who !== '' ? ' · ' . $who : '');
      $owners = db()->query("SELECT id FROM users WHERE role='owner' AND active=1")->fetchAll(PDO::FETCH_COLUMN);
      foreach ($owners as $oid) notify_user((int)$oid, '✅ ኦርደር ተጠናቋል — ክፍያ ተፈጽሟል', $body, 'finance');
    } catch (Throwable $e) {}
  }
  if ($reassigned !== null) {
    $name = null;
    if ($reassigned) {
      $s = db()->prepare('SELECT full_name FROM users WHERE id=?');
      $s->execute([$reassigned]);
      $name = $s->fetchColumn();
    }
    db()->prepare('INSERT INTO order_notes (order_id, user_id, note) VALUES (?,?,?)')
        ->execute([$id, $user['id'], $name ? "ለ$name መድቧል" : 'ምደባውን አንስቷል']);
    work_log((int)$user['id'], 'order', $id, ($order['customer_name'] ?? '') . ($order['service'] ? ' — ' . $order['service'] : ''), 'order_assigned', $name ? "ለ$name መድቧል" : 'ምደባውን አንስቷል');
  }

  json_out(['ok' => true]);
}

/* ---- ADD NOTE to an order ---- */
if ($m === 'POST' && isset($_GET['note']) && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  $in = json_input();
  $note = trim($in['note'] ?? '');
  if ($note === '') json_error('Note required');

  $stmt = db()->prepare('INSERT INTO order_notes (order_id, user_id, note) VALUES (?,?,?)');
  $stmt->execute([$id, $user['id'], $note]);
  $oq = db()->prepare('SELECT customer_name, service FROM orders WHERE id=?'); $oq->execute([$id]); $oo = $oq->fetch();
  if ($oo) work_log((int)$user['id'], 'order', $id, $oo['customer_name'] . ($oo['service'] ? ' — ' . $oo['service'] : ''), 'order_note', $note);
  json_out(['ok' => true]);
}

json_error('Unsupported request', 405);

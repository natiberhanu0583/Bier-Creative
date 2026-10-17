<?php
/**
 * CRM — የደንበኞች መዝገብ
 *
 *  GET    customers.php                     ዝርዝር (?q= &status= &stage= &type= &assigned= &followup=due|week)
 *  GET    customers.php?id=5                አንድ ደንበኛ + ታሪክ + ተግባራት + ተዛማጅ ኦርደሮች
 *  POST   customers.php                     አዲስ ደንበኛ መዝግብ
 *  PUT    customers.php?id=5                አስተካክል (stage ጨምሮ)
 *  DELETE customers.php?id=5                ሰርዝ (ባለቤት/ማናጀር)
 *  POST   customers.php?action=interaction&id=5     ማስታወሻ/ጥሪ/ስብሰባ ወደ ታሪክ ጨምር
 *  DELETE customers.php?action=interaction&iid=9    ማስታወሻ ሰርዝ
 *  POST   customers.php?action=order&id=5           ከደንበኛው ኦርደር መዝግብ (ደረጃው "ስራ አዟል" ይሆናል)
 *  POST   customers.php?action=import_orders        ከኦርደሮች ደንበኞችን አስመጣ (ባለቤት/ማናጀር)
 *
 *  ተግባራት/ቀጠሮዎች/ማሳሰቢያዎች → activities.php
 *
 *  ባለቤት/ማናጀር: ሁሉንም ደንበኞች ያያሉ እና ያስተካክላሉ።
 *  ሌላ ስታፍ: ለራሱ የተመደቡለትን ወይም ራሱ የመዘገባቸውን ደንበኞች ብቻ።
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../crm_lib.php';

$m = method();
$user = require_login();
$elevated = is_elevated($user['role']);
ensure_crm_schema();

$STATUSES = ['lead', 'active', 'vip', 'inactive'];
$TYPES = ['individual', 'business'];
$SOURCES = ['website', 'referral', 'social', 'walk_in', 'phone', 'other'];
$ITYPES = ['note', 'call', 'meeting', 'message', 'email', 'payment'];

function clean_tags($v): ?string {
  if (is_array($v)) $v = implode(',', $v);
  $parts = array_filter(array_map('trim', explode(',', (string)$v)), fn($x) => $x !== '');
  $parts = array_values(array_unique($parts));
  if (!$parts) return null;
  // mbstring ሳይጭን እንዲሰራ በ regex (UTF-8) ወደ 255 ፊደል ይቆርጣል
  preg_match('/^.{0,255}/su', implode(',', $parts), $mm);
  return $mm[0] !== '' ? $mm[0] : null;
}

function clean_money($v): ?string {
  $v = preg_replace('/[^\d.]/', '', (string)$v);
  return ($v === '' || !is_numeric($v)) ? null : $v;
}

$action = $_GET['action'] ?? '';

/* ============ import customers from orders ============ */
if ($m === 'POST' && $action === 'import_orders') {
  if (!$elevated) json_error('ለባለቤት/ማናጀር ብቻ', 403);
  $existing = [];
  foreach (db()->query('SELECT phone FROM customers')->fetchAll() as $r) $existing[phone_key($r['phone'])] = true;

  $orders = db()->query('SELECT customer_name, phone, email, source, status, assigned_to FROM orders ORDER BY created_at ASC')->fetchAll();
  $ins = db()->prepare(
    'INSERT INTO customers (full_name, phone, email, status, stage, stage_updated_at, source, assigned_to, created_by) VALUES (?,?,?,?,?,NOW(),?,?,?)'
  );
  $stageOf = ['new' => 'new', 'contacted' => 'contacted', 'in_progress' => 'in_progress', 'completed' => 'delivered', 'cancelled' => 'lost'];
  $added = 0;
  foreach ($orders as $o) {
    $k = phone_key($o['phone']);
    if ($k === '' || isset($existing[$k])) continue;
    $existing[$k] = true;
    $ins->execute([
      $o['customer_name'], $o['phone'], $o['email'] ?: null,
      $o['status'] === 'completed' ? 'active' : 'lead', $stageOf[$o['status']] ?? 'new',
      $o['source'] === 'manual' ? 'phone' : 'website',
      $o['assigned_to'] ?: null, $user['id'],
    ]);
    $added++;
  }
  json_out(['ok' => true, 'added' => $added]);
}

/* ============ interactions (ታሪክ) ============ */
if ($action === 'interaction') {
  if ($m === 'POST') {
    $cid = (int)($_GET['id'] ?? 0);
    $c = fetch_customer($cid);
    if (!$c) json_error('ደንበኛ አልተገኘም', 404);
    if (!can_access_customer($c, $user)) json_error('ፈቃድ የለዎትም', 403);
    $in = json_input();
    $content = trim($in['content'] ?? '');
    $type = in_array(($in['type'] ?? 'note'), $ITYPES, true) ? $in['type'] : 'note';
    if ($content === '') json_error('ይዘት ያስፈልጋል');
    log_interaction($cid, $user['id'], $type, $content);
    $newId = (int)db()->lastInsertId();
    db()->prepare('UPDATE customers SET updated_at = NOW() WHERE id = ?')->execute([$cid]);
    json_out(['ok' => true, 'id' => $newId]);
  }
  if ($m === 'DELETE') {
    $iid = (int)($_GET['iid'] ?? 0);
    $s = db()->prepare('SELECT * FROM customer_interactions WHERE id = ?');
    $s->execute([$iid]);
    $i = $s->fetch();
    if (!$i) json_error('አልተገኘም', 404);
    if (!$elevated && (int)$i['user_id'] !== (int)$user['id']) json_error('የራስዎን ማስታወሻ ብቻ መሰረዝ ይችላሉ', 403);
    db()->prepare('DELETE FROM customer_interactions WHERE id=?')->execute([$iid]);
    json_out(['ok' => true]);
  }
  json_error('Unsupported request', 405);
}

/* ============ create an order from the customer ============ */
if ($m === 'POST' && $action === 'order') {
  $cid = (int)($_GET['id'] ?? 0);
  $c = fetch_customer($cid);
  if (!$c) json_error('ደንበኛ አልተገኘም', 404);
  if (!can_access_customer($c, $user)) json_error('ፈቃድ የለዎትም', 403);
  $in = json_input();
  $service = trim($in['service'] ?? '');
  if ($service === '') json_error('አገልግሎት/የስራ ዓይነት ያስፈልጋል');

  $assigned = $c['assigned_to'] ?: $user['id'];
  if ($elevated && !empty($in['assigned_to'])) $assigned = (int)$in['assigned_to'];
  db()->prepare(
    "INSERT INTO orders (customer_name, phone, email, service, budget, message, source, created_by, assigned_to)
     VALUES (?,?,?,?,?,?,'manual',?,?)"
  )->execute([
    $c['full_name'], $c['phone'], $c['email'] ?: null, $service,
    trim($in['budget'] ?? '') ?: null, trim($in['message'] ?? '') ?: null, $user['id'], $assigned,
  ]);
  $oid = (int)db()->lastInsertId();
  db()->prepare('INSERT INTO order_notes (order_id, user_id, note) VALUES (?,?,?)')
    ->execute([$oid, $user['id'], 'ከ CRM ደንበኛ መዝገብ ላይ ተመዘገበ']);

  log_interaction($cid, $user['id'], 'note', 'ኦርደር ተመዝግቧል: ' . $service . (!empty($in['budget']) ? ' (' . $in['budget'] . ')' : ''));
  if (in_array($c['stage'], ['new', 'contacted', 'meeting', 'proposal', 'negotiation', 'lost'], true)) {
    set_customer_stage($c, 'ordered', $user['id']);
  }
  if ($c['status'] === 'lead') db()->prepare("UPDATE customers SET status='active' WHERE id=?")->execute([$cid]);
  json_out(['ok' => true, 'order_id' => $oid]);
}

/* ============ GET ============ */
if ($m === 'GET') {
  // ---- single customer ----
  if (isset($_GET['id'])) {
    $c = fetch_customer((int)$_GET['id']);
    if (!$c) json_error('ደንበኛ አልተገኘም', 404);
    if (!can_access_customer($c, $user)) json_error('ፈቃድ የለዎትም', 403);

    $s = db()->prepare(
      'SELECT i.id, i.type, i.content, i.created_at, i.user_id, u.full_name AS user_name
         FROM customer_interactions i LEFT JOIN users u ON u.id = i.user_id
        WHERE i.customer_id = ? ORDER BY i.created_at DESC, i.id DESC'
    );
    $s->execute([$c['id']]);
    $interactions = $s->fetchAll();

    $a = db()->prepare(
      "SELECT a.*, u.full_name AS assigned_name FROM customer_activities a LEFT JOIN users u ON u.id = a.assigned_to
        WHERE a.customer_id = ? ORDER BY (a.status='pending') DESC, a.due_date ASC, a.due_time ASC, a.id DESC LIMIT 200"
    );
    $a->execute([$c['id']]);
    $activities = $a->fetchAll();

    // ኦርደሮችን በስልክ ቁጥር ያገናኛል (ከድህረ ገጹ የመጡትም ራሳቸው ይያያዛሉ)
    $key = phone_key($c['phone']);
    $orders = [];
    if ($key !== '') {
      $o = db()->prepare(
        "SELECT id, service, budget, status, source, created_at FROM orders
          WHERE " . phone_norm_sql('phone') . " = ? ORDER BY created_at DESC"
      );
      $o->execute([$key]);
      $orders = $o->fetchAll();
    }
    // ኦርደር እያለው ደረጃው ገና "ስራ አዟል" ካልደረሰ ጥቆማ
    $suggest = null;
    if ($orders && in_array($c['stage'], ['new', 'contacted', 'meeting', 'proposal', 'negotiation'], true)) $suggest = 'ordered';

    json_out(['ok' => true, 'customer' => $c, 'interactions' => $interactions, 'activities' => $activities, 'orders' => $orders, 'suggest_stage' => $suggest]);
  }

  // ---- list ----
  $where = [];
  $params = [];
  $today = date('Y-m-d');
  if (!$elevated) {
    $where[] = '(c.assigned_to = ? OR c.created_by = ?)';
    array_push($params, $user['id'], $user['id']);
  }
  $q = trim($_GET['q'] ?? '');
  if ($q !== '') {
    $where[] = '(c.full_name LIKE ? OR c.company LIKE ? OR c.phone LIKE ? OR c.phone2 LIKE ? OR c.email LIKE ? OR c.tags LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
  }
  if (in_array($_GET['status'] ?? '', $STATUSES, true)) { $where[] = 'c.status = ?'; $params[] = $_GET['status']; }
  if (in_array($_GET['stage'] ?? '', CRM_STAGES, true)) { $where[] = 'c.stage = ?'; $params[] = $_GET['stage']; }
  if (in_array($_GET['type'] ?? '', $TYPES, true)) { $where[] = 'c.type = ?'; $params[] = $_GET['type']; }
  if ($elevated && !empty($_GET['assigned'])) {
    if ($_GET['assigned'] === 'none') $where[] = 'c.assigned_to IS NULL';
    else { $where[] = 'c.assigned_to = ?'; $params[] = (int)$_GET['assigned']; }
  }
  $pend = "SELECT 1 FROM customer_activities a WHERE a.customer_id = c.id AND a.status='pending'";
  if (($_GET['followup'] ?? '') === 'due') { $where[] = "EXISTS ($pend AND a.due_date <= ?)"; $params[] = $today; }
  if (($_GET['followup'] ?? '') === 'week') { $where[] = "EXISTS ($pend AND a.due_date BETWEEN ? AND ?)"; array_push($params, $today, date('Y-m-d', strtotime('+7 days'))); }

  $nextSub = "(SELECT %s FROM customer_activities a WHERE a.customer_id = c.id AND a.status='pending' ORDER BY a.due_date ASC, a.due_time IS NULL, a.due_time ASC, a.id ASC LIMIT 1)";
  $sql = 'SELECT c.*, u.full_name AS assigned_name,
                 (SELECT COUNT(*) FROM orders o WHERE ' . phone_norm_sql('o.phone') . ' = ' . phone_norm_sql('c.phone') . ') AS order_count,
                 (SELECT MAX(i.created_at) FROM customer_interactions i WHERE i.customer_id = c.id) AS last_contact,
                 (SELECT COUNT(*) FROM customer_activities a WHERE a.customer_id = c.id AND a.status=\'pending\') AS pending_count,
                 ' . sprintf($nextSub, 'a.due_date') . ' AS next_due,
                 ' . sprintf($nextSub, 'a.due_time') . ' AS next_time,
                 ' . sprintf($nextSub, 'a.type') . ' AS next_type,
                 ' . sprintf($nextSub, 'a.title') . ' AS next_title
            FROM customers c LEFT JOIN users u ON u.id = c.assigned_to'
       . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
       . ' ORDER BY c.updated_at DESC LIMIT 1000';
  $s = db()->prepare($sql);
  $s->execute($params);
  $rows = $s->fetchAll();

  // ማጠቃለያ (ለተጠቃሚው በሚታዩት ደንበኞች ብቻ)
  $uid = (int)$user['id'];
  $scope = $elevated ? '' : " WHERE (assigned_to = $uid OR created_by = $uid)";
  $sum = db()->query(
    "SELECT COUNT(*) AS total,
            SUM(status='lead') AS leads, SUM(status='active') AS active, SUM(status='vip') AS vip, SUM(status='inactive') AS inactive
       FROM customers" . $scope
  )->fetch();
  foreach ($sum as $k => $v) $sum[$k] = (int)$v;
  $stages = array_fill_keys(CRM_STAGES, 0);
  foreach (db()->query('SELECT stage, COUNT(*) n FROM customers' . $scope . ' GROUP BY stage')->fetchAll() as $r) $stages[$r['stage']] = (int)$r['n'];
  $sum['stages'] = $stages;

  json_out(['ok' => true, 'customers' => $rows, 'summary' => $sum]);
}

/* ============ POST (create) ============ */
if ($m === 'POST') {
  $in = json_input();
  $name = trim($in['full_name'] ?? '');
  $phone = trim($in['phone'] ?? '');
  if ($name === '' || $phone === '') json_error('ስም እና ስልክ ያስፈልጋሉ');

  // ተመሳሳይ ስልክ ያለው ደንበኛ ካለ ድግግሞሽን ይከለክላል
  $key = phone_key($phone);
  if ($key !== '') {
    $dup = db()->prepare('SELECT id, full_name FROM customers WHERE ' . phone_norm_sql('phone') . ' = ? LIMIT 1');
    $dup->execute([$key]);
    if ($d = $dup->fetch()) json_error('ይህ ስልክ ቁጥር አስቀድሞ በ "' . $d['full_name'] . '" ስም ተመዝግቧል');
  }

  $assigned = $elevated ? (!empty($in['assigned_to']) ? (int)$in['assigned_to'] : null) : $user['id'];
  $stage = in_array(($in['stage'] ?? ''), CRM_STAGES, true) ? $in['stage'] : 'new';
  db()->prepare(
    'INSERT INTO customers (full_name, company, phone, phone2, email, telegram, city, address, type, status, stage, stage_updated_at, expected_value, source, tags, notes, assigned_to, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?,?,?,?)'
  )->execute([
    $name,
    trim($in['company'] ?? '') ?: null,
    $phone,
    trim($in['phone2'] ?? '') ?: null,
    trim($in['email'] ?? '') ?: null,
    trim($in['telegram'] ?? '') ?: null,
    trim($in['city'] ?? '') ?: null,
    trim($in['address'] ?? '') ?: null,
    in_array(($in['type'] ?? ''), $TYPES, true) ? $in['type'] : 'individual',
    in_array(($in['status'] ?? ''), $STATUSES, true) ? $in['status'] : 'lead',
    $stage,
    clean_money($in['expected_value'] ?? ''),
    in_array(($in['source'] ?? ''), $SOURCES, true) ? $in['source'] : 'other',
    clean_tags($in['tags'] ?? ''),
    trim($in['notes'] ?? '') ?: null,
    $assigned,
    $user['id'],
  ]);
  $id = (int)db()->lastInsertId();
  log_interaction($id, $user['id'], 'note', 'ደንበኛው ተመዝግቧል');

  // ቀጣይ ክትትል ቀን ከተላከ እንደ ጥሪ ተግባር ይመዘገባል (ሰዓትም ካለ አብሮ)
  $fu = clean_date($in['next_followup'] ?? '');
  if ($fu) {
    db()->prepare("INSERT INTO customer_activities (customer_id, type, title, due_date, due_time, assigned_to, created_by) VALUES (?,?,?,?,?,?,?)")
      ->execute([$id, 'call', 'ክትትል', $fu, clean_time($in['next_followup_time'] ?? ''), $assigned ?: $user['id'], $user['id']]);
    sync_next_followup($id);
  }
  json_out(['ok' => true, 'id' => $id]);
}

/* ============ PUT (update) ============ */
if ($m === 'PUT' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  $c = fetch_customer($id);
  if (!$c) json_error('ደንበኛ አልተገኘም', 404);
  if (!can_access_customer($c, $user)) json_error('ፈቃድ የለዎትም', 403);

  $in = json_input();
  $f = [];
  $p = [];
  $text = ['full_name', 'company', 'phone', 'phone2', 'email', 'telegram', 'city', 'address', 'notes'];
  foreach ($text as $k) {
    if (!array_key_exists($k, $in)) continue;
    $v = trim((string)$in[$k]);
    if (in_array($k, ['full_name', 'phone'], true) && $v === '') json_error('ስም እና ስልክ ባዶ መሆን አይችሉም');
    $f[] = "$k=?";
    $p[] = $v === '' ? null : $v;
  }
  if (isset($in['phone'])) {
    $key = phone_key(trim($in['phone']));
    if ($key !== '') {
      $dup = db()->prepare('SELECT id, full_name FROM customers WHERE id<>? AND ' . phone_norm_sql('phone') . ' = ? LIMIT 1');
      $dup->execute([$id, $key]);
      if ($d = $dup->fetch()) json_error('ይህ ስልክ ቁጥር አስቀድሞ በ "' . $d['full_name'] . '" ስም ተመዝግቧል');
    }
  }
  if (isset($in['type']) && in_array($in['type'], $TYPES, true)) { $f[] = 'type=?'; $p[] = $in['type']; }
  if (isset($in['status']) && in_array($in['status'], $STATUSES, true)) { $f[] = 'status=?'; $p[] = $in['status']; }
  if (isset($in['source']) && in_array($in['source'], $SOURCES, true)) { $f[] = 'source=?'; $p[] = $in['source']; }
  if (array_key_exists('tags', $in)) { $f[] = 'tags=?'; $p[] = clean_tags($in['tags']); }
  if (array_key_exists('expected_value', $in)) { $f[] = 'expected_value=?'; $p[] = clean_money($in['expected_value']); }
  if ($elevated && array_key_exists('assigned_to', $in)) { $f[] = 'assigned_to=?'; $p[] = !empty($in['assigned_to']) ? (int)$in['assigned_to'] : null; }

  if (!$f && !isset($in['stage'])) json_error('Nothing to update');
  if ($f) {
    $p[] = $id;
    db()->prepare('UPDATE customers SET ' . implode(',', $f) . ' WHERE id=?')->execute($p);
  }
  if (isset($in['stage'])) set_customer_stage($c, (string)$in['stage'], $user['id'], trim((string)($in['lost_reason'] ?? '')));

  if (isset($in['status']) && $in['status'] !== $c['status'] && in_array($in['status'], $STATUSES, true)) {
    $labels = ['lead' => 'አዲስ ተስፋ', 'active' => 'ንቁ', 'vip' => 'VIP', 'inactive' => 'እንቅስቃሴ የሌለው'];
    log_interaction($id, $user['id'], 'note', 'ሁኔታ ተቀየረ: ' . $labels[$c['status']] . ' → ' . $labels[$in['status']]);
  }
  json_out(['ok' => true]);
}

/* ============ DELETE ============ */
if ($m === 'DELETE' && isset($_GET['id'])) {
  if (!$elevated) json_error('ለባለቤት/ማናጀር ብቻ', 403);
  db()->prepare('DELETE FROM customers WHERE id=?')->execute([(int)$_GET['id']]);
  json_out(['ok' => true]);
}

json_error('Unsupported request', 405);

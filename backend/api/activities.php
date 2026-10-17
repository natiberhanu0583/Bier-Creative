<?php
/**
 * CRM — ተግባራት፣ ቀጠሮዎች እና ማሳሰቢያዎች (Follow-ups / Appointments / Reminders)
 *
 *  GET    activities.php                       ዝርዝር (?range=overdue|today|tomorrow|week|later|all &status=pending|done|all &customer_id= &mine=1 &type=)
 *  GET    activities.php?action=alerts         ለደወሉ (bell) የሚያስፈልጉ ማሳሰቢያዎች (?all=1 ለባለቤት/ማናጀር)
 *  POST   activities.php                       አዲስ ተግባር/ቀጠሮ (customer_id, type, title, due_date, due_time, location, notes, remind_days, assigned_to)
 *  PUT    activities.php?id=7                  አስተካክል / ቀን ቀይር
 *  PUT    activities.php?id=7&action=done      ተጠናቀቀ (outcome, stage, next{...})
 *  PUT    activities.php?id=7&action=reopen    እንደገና ክፈት
 *  PUT    activities.php?id=7&action=cancel    ሰርዝ (ታሪኩ ይቀራል)
 *  DELETE activities.php?id=7                  ሙሉ በሙሉ አጥፋ
 *
 * የማሳሰቢያ ደረጃዎች (levels):
 *   overdue  = ጊዜው አልፏል      soon  = በ60 ደቂቃ ውስጥ
 *   today    = ዛሬ            upcoming = remind_days ውስጥ (ነባሪ 1 ቀን በፊት → "ነገ")
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../crm_lib.php';

$m = method();
$user = require_login();
$elevated = is_elevated($user['role']);
ensure_crm_schema();
$uid = (int)$user['id'];
$action = $_GET['action'] ?? '';

/* ---------- helpers ---------- */
function fetch_activity(int $id): ?array {
  $s = db()->prepare(
    'SELECT a.*, c.full_name AS customer_name, c.phone AS customer_phone, c.assigned_to AS c_assigned, c.created_by AS c_created, c.stage AS c_stage
       FROM customer_activities a JOIN customers c ON c.id = a.customer_id WHERE a.id = ? LIMIT 1'
  );
  $s->execute([$id]);
  $r = $s->fetch();
  return $r ?: null;
}

function can_touch(array $a, array $user): bool {
  if (is_elevated($user['role'])) return true;
  $uid = (int)$user['id'];
  return (int)$a['assigned_to'] === $uid || (int)$a['created_by'] === $uid || (int)$a['c_assigned'] === $uid || (int)$a['c_created'] === $uid;
}

function act_label(string $t): string { return CRM_ACT_LABELS[$t] ?? $t; }

function when_text(string $date, ?string $time): string {
  return $date . ($time ? ' ' . substr($time, 0, 5) : '');
}

function log_type_for(string $t): string {
  return ['call' => 'call', 'meeting' => 'meeting', 'visit' => 'meeting', 'message' => 'message', 'email' => 'email', 'payment' => 'payment'][$t] ?? 'note';
}

/** ከ POST/PUT body ተግባር ይፈጥራል። አዲሱን id ይመልሳል። */
function create_activity(array $in, array $c, array $user, bool $elevated): int {
  $type = in_array(($in['type'] ?? 'call'), CRM_ACT_TYPES, true) ? $in['type'] : 'call';
  $due = clean_date($in['due_date'] ?? '');
  if (!$due) json_error('ቀን ያስፈልጋል (YYYY-MM-DD)');
  $time = clean_time($in['due_time'] ?? '');
  $title = trim((string)($in['title'] ?? '')) ?: act_label($type);
  $remind = isset($in['remind_days']) ? max(0, min(7, (int)$in['remind_days'])) : 1;
  $assigned = $user['id'];
  if ($elevated) $assigned = !empty($in['assigned_to']) ? (int)$in['assigned_to'] : ($c['assigned_to'] ?: $user['id']);

  db()->prepare(
    'INSERT INTO customer_activities (customer_id, type, title, due_date, due_time, location, notes, remind_days, assigned_to, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?)'
  )->execute([
    (int)$c['id'], $type, clip_text($title, 200), $due, $time,
    trim((string)($in['location'] ?? '')) ?: null, trim((string)($in['notes'] ?? '')) ?: null,
    $remind, $assigned, $user['id'],
  ]);
  $id = (int)db()->lastInsertId();
  log_interaction((int)$c['id'], $user['id'], 'note', '📅 ' . act_label($type) . ' ተይዟል: ' . $title . ' — ' . when_text($due, $time));
  // ስብሰባ ከተያዘ እና ደንበኛው ገና በመጀመሪያ ደረጃ ከሆነ ደረጃው "ቀጠሮ ተይዟል" ይሆናል
  if (in_array($type, ['meeting', 'visit'], true) && in_array($c['stage'], ['new', 'contacted'], true)) {
    set_customer_stage($c, 'meeting', $user['id']);
  }
  sync_next_followup((int)$c['id']);
  return $id;
}

/* ============ ALERTS (ለደወሉ) ============ */
if ($m === 'GET' && $action === 'alerts') {
  $all = $elevated && !empty($_GET['all']);
  $today = date('Y-m-d');
  $limit = date('Y-m-d', strtotime('+7 days'));
  $sql = "SELECT a.id, a.customer_id, a.type, a.title, a.due_date, a.due_time, a.remind_days, a.location,
                 c.full_name AS customer_name, c.phone AS customer_phone, c.company
            FROM customer_activities a JOIN customers c ON c.id = a.customer_id
           WHERE a.status = 'pending' AND a.due_date <= ?";
  $params = [$limit];
  if (!$all) {
    $sql .= ' AND (a.assigned_to = ? OR (a.assigned_to IS NULL AND a.created_by = ?))';
    array_push($params, $uid, $uid);
  }
  $sql .= ' ORDER BY a.due_date ASC, a.due_time IS NULL, a.due_time ASC LIMIT 300';
  $st = db()->prepare($sql);
  $st->execute($params);

  $now = time();
  $alerts = [];
  $counts = ['overdue' => 0, 'soon' => 0, 'today' => 0, 'upcoming' => 0];
  foreach ($st->fetchAll() as $r) {
    $dueTs = strtotime($r['due_date'] . ' ' . ($r['due_time'] ?: '23:59:59'));
    $daysLeft = (int)floor((strtotime($r['due_date']) - strtotime($today)) / 86400);
    $level = null;
    $minutes = null;
    if ($r['due_date'] < $today) $level = 'overdue';
    elseif ($r['due_date'] === $today) {
      if ($r['due_time'] && $dueTs < $now) $level = 'overdue';
      elseif ($r['due_time'] && ($dueTs - $now) <= 3600) { $level = 'soon'; $minutes = (int)ceil(($dueTs - $now) / 60); }
      else $level = 'today';
    } elseif ($daysLeft <= (int)$r['remind_days']) $level = 'upcoming';
    if (!$level) continue;
    $r['level'] = $level;
    $r['days_left'] = $daysLeft;
    $r['minutes_left'] = $minutes;
    $alerts[] = $r;
    $counts[$level]++;
  }
  $order = ['overdue' => 0, 'soon' => 1, 'today' => 2, 'upcoming' => 3];
  usort($alerts, fn($x, $y) => [$order[$x['level']], $x['due_date'], (string)$x['due_time']] <=> [$order[$y['level']], $y['due_date'], (string)$y['due_time']]);
  $counts['total'] = count($alerts);
  json_out(['ok' => true, 'alerts' => $alerts, 'counts' => $counts, 'now' => date('Y-m-d H:i'), 'scope' => $all ? 'all' : 'mine']);
}

/* ============ LIST ============ */
if ($m === 'GET') {
  $where = [];
  $params = [];
  $today = date('Y-m-d');

  $status = $_GET['status'] ?? 'pending';
  if (in_array($status, ['pending', 'done', 'cancelled'], true)) { $where[] = 'a.status = ?'; $params[] = $status; }

  if (!empty($_GET['customer_id'])) { $where[] = 'a.customer_id = ?'; $params[] = (int)$_GET['customer_id']; }
  if (in_array($_GET['type'] ?? '', CRM_ACT_TYPES, true)) { $where[] = 'a.type = ?'; $params[] = $_GET['type']; }

  $range = $_GET['range'] ?? 'all';
  if ($range === 'overdue') { $where[] = '(a.due_date < ? OR (a.due_date = ? AND a.due_time IS NOT NULL AND a.due_time < ?))'; array_push($params, $today, $today, date('H:i:s')); }
  elseif ($range === 'today') { $where[] = 'a.due_date = ?'; $params[] = $today; }
  elseif ($range === 'tomorrow') { $where[] = 'a.due_date = ?'; $params[] = date('Y-m-d', strtotime('+1 day')); }
  elseif ($range === 'week') { $where[] = 'a.due_date BETWEEN ? AND ?'; array_push($params, $today, date('Y-m-d', strtotime('+7 days'))); }
  elseif ($range === 'later') { $where[] = 'a.due_date > ?'; $params[] = date('Y-m-d', strtotime('+7 days')); }

  if (!$elevated || !empty($_GET['mine'])) {
    $where[] = '(a.assigned_to = ? OR (a.assigned_to IS NULL AND a.created_by = ?))';
    array_push($params, $uid, $uid);
  }

  $sql = "SELECT a.*, c.full_name AS customer_name, c.phone AS customer_phone, c.company, c.stage AS customer_stage, u.full_name AS assigned_name
            FROM customer_activities a
            JOIN customers c ON c.id = a.customer_id
            LEFT JOIN users u ON u.id = a.assigned_to"
       . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
       . ($status === 'pending' || $status === 'all'
            ? ' ORDER BY a.due_date ASC, a.due_time IS NULL, a.due_time ASC, a.id ASC'
            : ' ORDER BY a.done_at DESC, a.due_date DESC')
       . ' LIMIT 500';
  $st = db()->prepare($sql);
  $st->execute($params);
  json_out(['ok' => true, 'activities' => $st->fetchAll(), 'today' => $today, 'now' => date('Y-m-d H:i')]);
}

/* ============ CREATE ============ */
if ($m === 'POST') {
  $in = json_input();
  $c = fetch_customer((int)($in['customer_id'] ?? 0));
  if (!$c) json_error('ደንበኛ አልተገኘም', 404);
  if (!can_access_customer($c, $user)) json_error('ፈቃድ የለዎትም', 403);
  $id = create_activity($in, $c, $user, $elevated);
  json_out(['ok' => true, 'id' => $id]);
}

/* ============ UPDATE / DONE / REOPEN / CANCEL ============ */
if ($m === 'PUT' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  $a = fetch_activity($id);
  if (!$a) json_error('አልተገኘም', 404);
  if (!can_touch($a, $user)) json_error('ፈቃድ የለዎትም', 403);
  $in = json_input();
  $cid = (int)$a['customer_id'];
  $c = fetch_customer($cid);

  if ($action === 'done') {
    if ($a['status'] === 'done') json_error('አስቀድሞ ተጠናቅቋል');
    $outcome = trim((string)($in['outcome'] ?? ''));
    db()->prepare("UPDATE customer_activities SET status='done', done_at=NOW(), outcome=? WHERE id=?")->execute([$outcome ?: null, $id]);
    $msg = '✔ ' . act_label($a['type']) . ' ተጠናቀቀ: ' . $a['title'];
    if ($outcome) $msg .= "\n" . $outcome;
    log_interaction($cid, $user['id'], log_type_for($a['type']), $msg);

    // የመጀመሪያ ጥሪ ተደርጎ ከሆነ ደረጃው "ተነጋግረናል" ይሆናል
    if ($a['type'] === 'call' && $c['stage'] === 'new') { set_customer_stage($c, 'contacted', $user['id']); $c['stage'] = 'contacted'; }
    if (!empty($in['stage'])) set_customer_stage(fetch_customer($cid), (string)$in['stage'], $user['id'], trim((string)($in['lost_reason'] ?? '')));

    $nextId = null;
    if (!empty($in['next']) && is_array($in['next']) && !empty($in['next']['due_date'])) {
      $nextId = create_activity($in['next'], fetch_customer($cid), $user, $elevated);
    }
    sync_next_followup($cid);
    json_out(['ok' => true, 'next_id' => $nextId]);
  }

  if ($action === 'reopen') {
    db()->prepare("UPDATE customer_activities SET status='pending', done_at=NULL WHERE id=?")->execute([$id]);
    log_interaction($cid, $user['id'], 'note', '↩ እንደገና ተከፍቷል: ' . $a['title']);
    sync_next_followup($cid);
    json_out(['ok' => true]);
  }

  if ($action === 'cancel') {
    db()->prepare("UPDATE customer_activities SET status='cancelled' WHERE id=?")->execute([$id]);
    log_interaction($cid, $user['id'], 'note', '✖ ተሰርዟል: ' . act_label($a['type']) . ' — ' . $a['title']);
    sync_next_followup($cid);
    json_out(['ok' => true]);
  }

  // ---- ተራ ማስተካከያ / ቀን መቀየር ----
  $f = [];
  $p = [];
  if (isset($in['type']) && in_array($in['type'], CRM_ACT_TYPES, true)) { $f[] = 'type=?'; $p[] = $in['type']; }
  if (isset($in['title'])) { $t = trim((string)$in['title']); if ($t !== '') { $f[] = 'title=?'; $p[] = clip_text($t, 200); } }
  if (isset($in['due_date'])) {
    $d = clean_date($in['due_date']);
    if (!$d) json_error('ቀን ትክክል አይደለም');
    $f[] = 'due_date=?'; $p[] = $d;
  }
  if (array_key_exists('due_time', $in)) { $f[] = 'due_time=?'; $p[] = clean_time($in['due_time']); }
  if (array_key_exists('location', $in)) { $f[] = 'location=?'; $p[] = trim((string)$in['location']) ?: null; }
  if (array_key_exists('notes', $in)) { $f[] = 'notes=?'; $p[] = trim((string)$in['notes']) ?: null; }
  if (isset($in['remind_days'])) { $f[] = 'remind_days=?'; $p[] = max(0, min(7, (int)$in['remind_days'])); }
  if ($elevated && array_key_exists('assigned_to', $in)) { $f[] = 'assigned_to=?'; $p[] = !empty($in['assigned_to']) ? (int)$in['assigned_to'] : null; }
  if (!$f) json_error('Nothing to update');
  $p[] = $id;
  db()->prepare('UPDATE customer_activities SET ' . implode(',', $f) . ' WHERE id=?')->execute($p);

  if (isset($in['due_date']) || array_key_exists('due_time', $in)) {
    $newDate = clean_date($in['due_date'] ?? $a['due_date']);
    $newTime = array_key_exists('due_time', $in) ? clean_time($in['due_time']) : $a['due_time'];
    if ($newDate !== $a['due_date'] || $newTime !== $a['due_time']) {
      log_interaction($cid, $user['id'], 'note', '🔁 ቀጠሮ ተቀየረ: ' . $a['title'] . ' — ' . when_text($a['due_date'], $a['due_time']) . ' → ' . when_text($newDate, $newTime));
    }
  }
  sync_next_followup($cid);
  json_out(['ok' => true]);
}

/* ============ DELETE ============ */
if ($m === 'DELETE' && isset($_GET['id'])) {
  $a = fetch_activity((int)$_GET['id']);
  if (!$a) json_error('አልተገኘም', 404);
  if (!$elevated && (int)$a['created_by'] !== $uid) json_error('የራስዎን ብቻ መሰረዝ ይችላሉ', 403);
  db()->prepare('DELETE FROM customer_activities WHERE id=?')->execute([(int)$a['id']]);
  sync_next_followup((int)$a['customer_id']);
  json_out(['ok' => true]);
}

json_error('Unsupported request', 405);

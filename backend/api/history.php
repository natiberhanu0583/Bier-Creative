<?php
/**
 * Work history — who did what, and when.
 *  GET history.php?range=today|7|30|all &user= &kind=task|order &q= &before=<id> &limit=
 * Owner/manager: everyone (optionally filtered by ?user=). Regular staff: ONLY their own entries.
 */
require_once __DIR__ . '/../db.php';
$user = require_login();
$elev = is_elevated($user['role']);
work_log_ensure();

$range = $_GET['range'] ?? '30';
$where = ['1=1']; $p = [];
if ($range === 'today') $where[] = 'w.created_at >= CURDATE()';
elseif ($range === '7') $where[] = 'w.created_at >= CURDATE() - INTERVAL 6 DAY';
elseif ($range === '30') $where[] = 'w.created_at >= CURDATE() - INTERVAL 29 DAY';
$base = $where;           // range only (for per-person summary)
if ($elev) { if (!empty($_GET['user'])) { $where[] = 'w.user_id = ?'; $p[] = (int)$_GET['user']; } }
else { $where[] = 'w.user_id = ?'; $p[] = (int)$user['id']; }
if (in_array($_GET['kind'] ?? '', ['task', 'order'], true)) { $where[] = 'w.kind = ?'; $p[] = $_GET['kind']; }
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') { $where[] = '(w.ref_title LIKE ? OR w.detail LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
$W = implode(' AND ', $where);

/* summary (same filters, ignoring pagination) */
$s = db()->prepare("SELECT
    SUM(w.action='task_done') AS tasks_done, SUM(w.action='task_created') AS tasks_created,
    SUM(w.action='order_completed') AS orders_completed, SUM(w.action='order_note') AS notes, COUNT(*) AS total
  FROM work_log w WHERE $W");
$s->execute($p);
$sum = array_map('intval', $s->fetch() ?: []);

/* items */
$limit = max(10, min(100, (int)($_GET['limit'] ?? 50)));
$before = (int)($_GET['before'] ?? 0);
$W2 = $W . ($before ? ' AND w.id < ' . $before : '');
$i = db()->prepare("SELECT w.id, w.user_id, u.full_name, u.role, w.kind, w.ref_id, w.ref_title, w.action, w.detail, w.created_at
  FROM work_log w LEFT JOIN users u ON u.id = w.user_id WHERE $W2 ORDER BY w.id DESC LIMIT " . ($limit + 1));
$i->execute($p);
$items = $i->fetchAll();
$more = count($items) > $limit; if ($more) array_pop($items);

$out = ['ok' => true, 'summary' => $sum, 'items' => $items, 'more' => $more, 'elevated' => $elev];

/* per-person summary for owner/manager */
if ($elev) {
  $pp = db()->prepare("SELECT u.id, u.full_name, u.role,
      COALESCE(SUM(w.action='task_done'),0) AS tasks_done, COALESCE(SUM(w.action='order_completed'),0) AS orders_completed,
      COUNT(w.id) AS total, MAX(w.created_at) AS last_at
    FROM users u LEFT JOIN work_log w ON w.user_id = u.id AND " . implode(' AND ', array_map(fn($x) => str_replace('w.created_at', 'w.created_at', $x), $base)) . "
    WHERE u.active = 1 AND u.role != 'owner' GROUP BY u.id ORDER BY total DESC, u.full_name");
  $pp->execute();
  $out['people'] = $pp->fetchAll();
}
json_out($out);

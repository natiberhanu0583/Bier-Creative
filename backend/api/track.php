<?php
require_once __DIR__ . '/../db.php';

$m = method();
$action = $_GET['action'] ?? '';

/* ---- PUBLIC: log a page view from the main website (script.js) ---- */
if ($m === 'POST' && $action === 'log') {
  $in = json_input();
  $visitorKey = trim($in['visitor_key'] ?? '');
  $page = trim($in['page'] ?? '/');
  $referrer = trim($in['referrer'] ?? '');

  if ($visitorKey === '' || strlen($visitorKey) > 64) json_error('Invalid visitor key');

  $stmt = db()->prepare('INSERT INTO page_views (visitor_key, page, referrer) VALUES (?,?,?)');
  $stmt->execute([$visitorKey, mb_substr($page, 0, 255), $referrer !== '' ? mb_substr($referrer, 0, 255) : null]);

  json_out(['ok' => true]);
}

/* ---- OWNER/MANAGER: daily views + unique visitors for the dashboard chart ---- */
if ($m === 'GET' && $action === 'daily') {
  require_manager_or_owner();
  $days = max(7, min(90, (int)($_GET['days'] ?? 30)));

  $stmt = db()->prepare(
    "SELECT DATE(created_at) AS d,
            COUNT(*) AS views,
            COUNT(DISTINCT visitor_key) AS uniques
     FROM page_views
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
     GROUP BY DATE(created_at) ORDER BY d ASC"
  );
  $stmt->execute([$days]);
  $rows = $stmt->fetchAll();

  $byDate = [];
  foreach ($rows as $r) $byDate[$r['d']] = $r;
  $series = [];
  for ($i = $days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $series[] = [
      'date' => $d,
      'views' => isset($byDate[$d]) ? (int)$byDate[$d]['views'] : 0,
      'uniques' => isset($byDate[$d]) ? (int)$byDate[$d]['uniques'] : 0,
    ];
  }

  $totalViews = (int)db()->query("SELECT COUNT(*) FROM page_views WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL $days DAY)")->fetchColumn();
  $totalUniques = (int)db()->query("SELECT COUNT(DISTINCT visitor_key) FROM page_views WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL $days DAY)")->fetchColumn();

  json_out(['ok' => true, 'series' => $series, 'total_views' => $totalViews, 'total_uniques' => $totalUniques]);
}

json_error('Unsupported request', 405);

<?php
/* Live event feed for the admin (new orders / visitor chats / testimonials). */
require_once __DIR__ . '/../db.php';
$me = require_login();
require_once __DIR__ . '/../push.php'; push_tables();
$a = $_GET['action'] ?? 'since';

function feed_max(): array {
  $q = fn($sql) => (int)db()->query($sql)->fetchColumn();
  return [
    'o' => $q("SELECT COALESCE(MAX(id),0) FROM orders WHERE source='website'"),
    'c' => $q("SELECT COALESCE(MAX(id),0) FROM chat_messages WHERE sender='user'"),
    't' => $q("SELECT COALESCE(MAX(id),0) FROM testimonials"),
    'n' => $q("SELECT COALESCE(MAX(id),0) FROM notif_events WHERE user_id=" . (int)$GLOBALS['me']['id']),
  ];
}
function feed_items(int $o, int $c, int $t, int $n = 0): array {
  $items = [];
  $st = db()->prepare('SELECT id, title, body, tab FROM notif_events WHERE user_id=? AND id>? ORDER BY id LIMIT 20'); $st->execute([$GLOBALS['me']['id'], $n]);
  foreach ($st as $r) $items[] = ['type'=>'ev','id'=>(int)$r['id'],'title'=>$r['title'],'body'=>$r['body'],'tab'=>$r['tab']];
  $st = db()->prepare("SELECT id, customer_name, service FROM orders WHERE source='website' AND id>? ORDER BY id LIMIT 20"); $st->execute([$o]);
  foreach ($st as $r) $items[] = ['type'=>'order','id'=>(int)$r['id'],'title'=>'🛒 አዲስ ኦርደር','body'=>$r['customer_name'].($r['service']?' — '.$r['service']:''),'tab'=>'orders'];
  $st = db()->prepare("SELECT m.id, m.message, s.visitor_name FROM chat_messages m JOIN chat_sessions s ON s.id=m.session_id WHERE m.sender='user' AND m.id>? ORDER BY m.id LIMIT 20"); $st->execute([$c]);
  foreach ($st as $r) $items[] = ['type'=>'chat','id'=>(int)$r['id'],'title'=>'💬 አዲስ የቻት መልእክት'.($r['visitor_name']?' — '.$r['visitor_name']:''),'body'=>mb_substr($r['message'],0,120),'tab'=>'chats'];
  $st = db()->prepare("SELECT id, customer_name, rating FROM testimonials WHERE id>? ORDER BY id LIMIT 20"); $st->execute([$t]);
  foreach ($st as $r) $items[] = ['type'=>'testimonial','id'=>(int)$r['id'],'title'=>'⭐ አዲስ ቴስቲሞኒያል','body'=>$r['customer_name'].' ('.$r['rating'].'/5)','tab'=>'testimonials'];
  return $items;
}

if ($a === 'cursor') json_out(['ok' => true, 'cursor' => feed_max()]);
if ($a === 'latest') {   // used by the service worker after an empty push
  $mx = feed_max();
  $it = feed_items(max(0, $mx['o'] - 1), max(0, $mx['c'] - 1), max(0, $mx['t'] - 1), max(0, $mx['n'] - 1));
  json_out(['ok' => true, 'items' => array_slice($it, -3)]);
}
json_out(['ok' => true, 'items' => feed_items((int)($_GET['o'] ?? 0), (int)($_GET['c'] ?? 0), (int)($_GET['t'] ?? 0), (int)($_GET['n'] ?? 0)), 'cursor' => feed_max()]);

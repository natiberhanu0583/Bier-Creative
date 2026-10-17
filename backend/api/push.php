<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../push.php';
$user = require_login();
$a = $_GET['action'] ?? '';
$m = method();

if ($a === 'key') {
  $k = vapid_keys();
  json_out(['ok' => (bool)$k, 'key' => $k['pub'] ?? null]);
}
if ($m === 'POST' && $a === 'subscribe') {
  push_tables();
  $in = json_input(); $ep = trim($in['endpoint'] ?? '');
  if ($ep === '' || strlen($ep) > 700) json_error('Invalid subscription');
  db()->prepare('INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash) VALUES (?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)')
      ->execute([$user['id'], $ep, sha1($ep)]);
  json_out(['ok' => true]);
}
if ($m === 'POST' && $a === 'unsubscribe') {
  push_tables();
  $in = json_input();
  db()->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash=?')->execute([sha1(trim($in['endpoint'] ?? ''))]);
  json_out(['ok' => true]);
}
json_error('Unknown action', 404);

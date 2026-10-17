<?php
/* Personal notes — every user sees ONLY their own notes. */
require_once __DIR__ . '/../db.php';
$user = require_login();
$m = method();

db()->exec("CREATE TABLE IF NOT EXISTS user_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(150) NOT NULL DEFAULT '',
  body TEXT,
  color VARCHAR(12) NOT NULL DEFAULT 'gold',
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

const NOTE_COLORS = ['gold','teal','orange','blue','purple','pink','gray'];
function note_color($c) { return in_array($c, NOTE_COLORS, true) ? $c : 'gold'; }

if ($m === 'GET') {
  $st = db()->prepare('SELECT id, title, body, color, pinned, created_at, updated_at FROM user_notes WHERE user_id=? ORDER BY pinned DESC, updated_at DESC LIMIT 500');
  $st->execute([$user['id']]);
  json_out(['ok' => true, 'notes' => $st->fetchAll()]);
}
if ($m === 'POST') {
  $in = json_input();
  $title = mb_substr(trim($in['title'] ?? ''), 0, 150);
  $body = mb_substr(trim($in['body'] ?? ''), 0, 20000);
  if ($title === '' && $body === '') json_error('ማስታወሻው ባዶ ነው');
  db()->prepare('INSERT INTO user_notes (user_id, title, body, color, pinned) VALUES (?,?,?,?,?)')
      ->execute([$user['id'], $title, $body, note_color($in['color'] ?? ''), !empty($in['pinned']) ? 1 : 0]);
  json_out(['ok' => true, 'id' => db()->lastInsertId()]);
}
if ($m === 'PUT' && isset($_GET['id'])) {
  $in = json_input(); $f = []; $p = [];
  if (array_key_exists('title', $in)) { $f[] = 'title=?'; $p[] = mb_substr(trim($in['title']), 0, 150); }
  if (array_key_exists('body', $in)) { $f[] = 'body=?'; $p[] = mb_substr(trim($in['body']), 0, 20000); }
  if (array_key_exists('color', $in)) { $f[] = 'color=?'; $p[] = note_color($in['color']); }
  if (array_key_exists('pinned', $in)) { $f[] = 'pinned=?'; $p[] = $in['pinned'] ? 1 : 0; }
  if (!$f) json_error('Nothing to update');
  $p[] = (int)$_GET['id']; $p[] = $user['id'];
  $st = db()->prepare('UPDATE user_notes SET ' . implode(',', $f) . ' WHERE id=? AND user_id=?'); $st->execute($p);
  json_out(['ok' => true]);
}
if ($m === 'DELETE' && isset($_GET['id'])) {
  db()->prepare('DELETE FROM user_notes WHERE id=? AND user_id=?')->execute([(int)$_GET['id'], $user['id']]);
  json_out(['ok' => true]);
}
json_error('Unknown request', 404);

<?php
/* Internal team messenger (owner/manager <-> staff): text, voice notes, photos. */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../push.php';
$user = require_login();
$m = method();
$a = $_GET['action'] ?? '';
$me = (int)$user['id'];

db()->exec("CREATE TABLE IF NOT EXISTS team_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  from_id INT NOT NULL,
  to_id INT NOT NULL,
  kind ENUM('text','voice','image') NOT NULL DEFAULT 'text',
  body TEXT,
  file_name VARCHAR(80) DEFAULT NULL,
  file_mime VARCHAR(60) DEFAULT NULL,
  duration INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME DEFAULT NULL,
  KEY (from_id, to_id, id), KEY (to_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

foreach (['reply_to' => 'INT NULL DEFAULT NULL', 'fwd_name' => 'VARCHAR(100) NULL DEFAULT NULL', 'pinned_at' => 'DATETIME NULL DEFAULT NULL', 'deleted_at' => 'DATETIME NULL DEFAULT NULL'] as $col => $def) {
  try { if (!db()->query("SHOW COLUMNS FROM team_messages LIKE '$col'")->fetch()) db()->exec("ALTER TABLE team_messages ADD COLUMN $col $def"); } catch (Throwable $e) {}
}
try { $hasEd = db()->query("SHOW COLUMNS FROM team_messages LIKE 'edited_at'")->fetch(); if (!$hasEd) db()->exec("ALTER TABLE team_messages ADD COLUMN edited_at DATETIME NULL DEFAULT NULL"); } catch (Throwable $e) {}

const TEAM_DIR = __DIR__ . '/../uploads/team';

function team_user(int $id): ?array {
  $s = db()->prepare('SELECT id, full_name, role, active FROM users WHERE id=?'); $s->execute([$id]);
  $u = $s->fetch(); return ($u && (int)$u['active'] === 1) ? $u : null;
}
/** A conversation is allowed when at least one side is owner/manager. */
function team_allowed(array $me, ?array $other): bool {
  if (!$other || (int)$other['id'] === (int)$me['id']) return false;
  return is_elevated($me['role']) || is_elevated($other['role']);
}
function team_preview(array $r): string {
  if (!empty($r['deleted_at'])) return '🚫 መልእክት ተሰርዟል';
  if ($r['kind'] === 'voice') return '🎤 የድምጽ መልእክት';
  if ($r['kind'] === 'image') return '📷 ፎቶ' . (trim((string)$r['body']) !== '' ? ' · ' . mb_substr($r['body'], 0, 40) : '');
  return mb_substr((string)$r['body'], 0, 80);
}

/* ---- contacts ---- */
if ($a === 'contacts') {
  $users = db()->query('SELECT id, full_name, role FROM users WHERE active=1 ORDER BY full_name')->fetchAll();
  $out = [];
  foreach ($users as $u) {
    if (!team_allowed($user, $u)) continue;
    $uid = (int)$u['id'];
    $l = db()->prepare('SELECT id, from_id, kind, body, deleted_at, created_at FROM team_messages WHERE (from_id=? AND to_id=?) OR (from_id=? AND to_id=?) ORDER BY id DESC LIMIT 1');
    $l->execute([$me, $uid, $uid, $me]); $last = $l->fetch();
    $c = db()->prepare('SELECT COUNT(*) FROM team_messages WHERE from_id=? AND to_id=? AND read_at IS NULL'); $c->execute([$uid, $me]);
    $out[] = ['id' => $uid, 'full_name' => $u['full_name'], 'role' => $u['role'],
      'last' => $last ? ['preview' => team_preview($last), 'mine' => (int)$last['from_id'] === $me, 'at' => $last['created_at'], 'id' => (int)$last['id']] : null,
      'unread' => (int)$c->fetchColumn()];
  }
  usort($out, function ($x, $y) {
    $a = $x['last']['id'] ?? 0; $b = $y['last']['id'] ?? 0;
    return $a === $b ? strcmp($x['full_name'], $y['full_name']) : $b <=> $a;
  });
  json_out(['ok' => true, 'contacts' => $out]);
}

/* ---- unread total (sidebar badge) ---- */
if ($a === 'unread') {
  $c = db()->prepare('SELECT COUNT(*) FROM team_messages WHERE to_id=? AND read_at IS NULL'); $c->execute([$me]);
  json_out(['ok' => true, 'unread' => (int)$c->fetchColumn()]);
}

/* ---- thread ---- */
if ($m === 'GET' && $a === 'thread') {
  $with = (int)($_GET['with'] ?? 0);
  if (!team_allowed($user, team_user($with))) json_error('አልተፈቀደም', 403);
  $since = (int)($_GET['since'] ?? 0);
  if ($since > 0) {
    $s = db()->prepare('SELECT * FROM team_messages WHERE ((from_id=? AND to_id=?) OR (from_id=? AND to_id=?)) AND id>? ORDER BY id ASC LIMIT 200');
    $s->execute([$me, $with, $with, $me, $since]);
    $rows = $s->fetchAll();
  } else {
    $s = db()->prepare('SELECT * FROM (SELECT * FROM team_messages WHERE (from_id=? AND to_id=?) OR (from_id=? AND to_id=?) ORDER BY id DESC LIMIT 150) t ORDER BY id ASC');
    $s->execute([$me, $with, $with, $me]);
    $rows = $s->fetchAll();
  }
  db()->prepare('UPDATE team_messages SET read_at=NOW() WHERE from_id=? AND to_id=? AND read_at IS NULL')->execute([$with, $me]);
  $r = db()->prepare('SELECT COALESCE(MAX(id),0) FROM team_messages WHERE from_id=? AND to_id=? AND read_at IS NOT NULL'); $r->execute([$me, $with]);
  foreach ($rows as &$x) { $x['mine'] = (int)$x['from_id'] === $me; $x['has_file'] = $x['file_name'] !== null && !$x['deleted_at']; unset($x['file_name']); if ($x['deleted_at']) { $x['body'] = null; $x['kind'] = 'text'; } }
  unset($x);
  // reply previews
  $rids = array_values(array_unique(array_filter(array_map(fn($z) => (int)$z['reply_to'], $rows))));
  $rmap = [];
  if ($rids) {
    $in = implode(',', array_fill(0, count($rids), '?'));
    $q = db()->prepare("SELECT t.id, t.from_id, t.kind, t.body, t.deleted_at, u.full_name FROM team_messages t LEFT JOIN users u ON u.id=t.from_id WHERE t.id IN ($in)");
    $q->execute($rids);
    foreach ($q->fetchAll() as $z) $rmap[(int)$z['id']] = ['id' => (int)$z['id'], 'name' => $z['full_name'], 'kind' => $z['kind'], 'preview' => team_preview($z), 'deleted' => !empty($z['deleted_at']), 'mine' => (int)$z['from_id'] === $me];
  }
  foreach ($rows as &$x) { $x['reply'] = $x['reply_to'] ? ($rmap[(int)$x['reply_to']] ?? ['id' => (int)$x['reply_to'], 'deleted' => true]) : null; }
  unset($x);
  $pq = db()->prepare('SELECT id, kind, body, pinned_at FROM team_messages WHERE ((from_id=? AND to_id=?) OR (from_id=? AND to_id=?)) AND pinned_at IS NOT NULL AND deleted_at IS NULL ORDER BY pinned_at DESC LIMIT 20');
  $pq->execute([$me, $with, $with, $me]);
  $pinned = array_map(fn($z) => ['id' => (int)$z['id'], 'preview' => team_preview($z), 'kind' => $z['kind']], $pq->fetchAll());
  $out = ['ok' => true, 'messages' => $rows, 'read_upto' => (int)$r->fetchColumn(), 'pinned' => $pinned];
  $kmin = (int)($_GET['kmin'] ?? 0);
  if ($since > 0 && $kmin > 0) {
    $q = db()->prepare('SELECT id, kind, body, edited_at, deleted_at FROM team_messages WHERE ((from_id=? AND to_id=?) OR (from_id=? AND to_id=?)) AND id>=? ORDER BY id ASC LIMIT 400');
    $q->execute([$me, $with, $with, $me, $kmin]);
    $alive = []; $edited = []; $deleted = [];
    foreach ($q->fetchAll() as $er) { $alive[] = (int)$er['id']; if ($er['deleted_at']) $deleted[] = ['id' => (int)$er['id'], 'deleted_at' => $er['deleted_at']]; elseif ($er['edited_at']) $edited[] = ['id' => (int)$er['id'], 'body' => $er['body'], 'edited_at' => $er['edited_at']]; }
    $out['alive'] = $alive; $out['edited'] = $edited; $out['deleted'] = $deleted;
  }
  json_out($out);
}

/* ---- send ---- */
if ($m === 'POST' && $a === 'send') {
  $isMulti = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/') === 0;
  $in = $isMulti ? $_POST : json_input();
  $to = (int)($in['to'] ?? 0);
  $other = team_user($to);
  if (!team_allowed($user, $other)) json_error('አልተፈቀደም', 403);
  $replyTo = (int)($in['reply_to'] ?? 0);
  if ($replyTo) {
    $rq = db()->prepare('SELECT id FROM team_messages WHERE id=? AND ((from_id=? AND to_id=?) OR (from_id=? AND to_id=?))'); $rq->execute([$replyTo, $me, $to, $to, $me]);
    if (!$rq->fetch()) $replyTo = 0;
  }
  /* forward an existing message (copy; keeps original author's name) */
  if (!empty($in['forward_id'])) {
    $fq = db()->prepare('SELECT t.*, u.full_name FROM team_messages t LEFT JOIN users u ON u.id=t.from_id WHERE t.id=? AND t.deleted_at IS NULL AND (t.from_id=? OR t.to_id=?)');
    $fq->execute([(int)$in['forward_id'], $me, $me]);
    $src = $fq->fetch(); if (!$src) json_error('መልእክቱ አልተገኘም', 404);
    $newFile = null;
    if ($src['file_name']) {
      $from = TEAM_DIR . '/' . basename($src['file_name']);
      if (!is_file($from)) json_error('ፋይሉ አልተገኘም', 404);
      $newFile = bin2hex(random_bytes(14)) . '.' . pathinfo($from, PATHINFO_EXTENSION);
      if (!@copy($from, TEAM_DIR . '/' . $newFile)) json_error('ማስተላለፍ አልተቻለም', 500);
    }
    $orig = $src['fwd_name'] ?: $src['full_name'];
    db()->prepare('INSERT INTO team_messages (from_id, to_id, kind, body, file_name, file_mime, duration, fwd_name) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$me, $to, $src['kind'], $src['body'], $newFile, $src['file_mime'], $src['duration'], mb_substr((string)$orig, 0, 100)]);
    $id = (int)db()->lastInsertId();
    notify_user($to, '💬 ' . ($user['full_name'] ?? 'መልእክት'), '↪ ' . team_preview($src), 'team');
    json_out(['ok' => true, 'id' => $id]);
  }
  $kind = $in['kind'] ?? 'text';
  if (!in_array($kind, ['text', 'voice', 'image'], true)) json_error('Invalid kind');
  $body = mb_substr(trim((string)($in['body'] ?? '')), 0, 4000);
  $fileName = null; $mime = null; $dur = null;

  if ($kind === 'text') {
    if ($body === '') json_error('መልእክቱ ባዶ ነው');
  } else {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) json_error('ፋይሉ አልተላከም (መጠኑ ከፍተኛ ሊሆን ይችላል)');
    $f = $_FILES['file'];
    $max = $kind === 'image' ? 8 * 1024 * 1024 : 10 * 1024 * 1024;
    if ($f['size'] > $max || $f['size'] < 100) json_error($kind === 'image' ? 'ፎቶው ከ8MB መብለጥ የለበትም' : 'የድምጽ ፋይሉ ከ10MB መብለጥ የለበትም');
    $fi = new finfo(FILEINFO_MIME_TYPE); $det = $fi->file($f['tmp_name']);
    if ($kind === 'image') {
      $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
      if (!isset($map[$det]) || !@getimagesize($f['tmp_name'])) json_error('ፎቶ (JPG/PNG/GIF/WEBP) ብቻ ይፈቀዳል');
    } else {
      $map = ['audio/webm' => 'webm', 'video/webm' => 'webm', 'audio/ogg' => 'ogg', 'application/ogg' => 'ogg', 'audio/mp4' => 'm4a', 'video/mp4' => 'm4a',
              'audio/x-m4a' => 'm4a', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav'];
      if (!isset($map[$det])) json_error('የድምጽ ቅርጸቱ አይደገፍም (' . $det . ')');
    }
    if (!is_dir(TEAM_DIR)) @mkdir(TEAM_DIR, 0755, true);
    $fileName = bin2hex(random_bytes(14)) . '.' . $map[$det];
    if (!move_uploaded_file($f['tmp_name'], TEAM_DIR . '/' . $fileName)) json_error('ፋይሉን ማስቀመጥ አልተቻለም', 500);
    $mime = $kind === 'voice' ? (strpos($det, 'video/') === 0 ? 'audio/' . substr($det, 6) : $det) : $det;
    $dur = $kind === 'voice' ? max(0, min(3600, (int)($in['duration'] ?? 0))) : null;
  }
  db()->prepare('INSERT INTO team_messages (from_id, to_id, kind, body, file_name, file_mime, duration, reply_to) VALUES (?,?,?,?,?,?,?,?)')
      ->execute([$me, $to, $kind, $body !== '' ? $body : null, $fileName, $mime, $dur, $replyTo ?: null]);
  $id = (int)db()->lastInsertId();
  $prev = team_preview(['kind' => $kind, 'body' => $body]);
  notify_user($to, '💬 ' . ($user['full_name'] ?? 'መልእክት'), $prev, 'team');
  json_out(['ok' => true, 'id' => $id]);
}

/* ---- file (authenticated, supports Range for audio seeking) ---- */
if ($m === 'GET' && $a === 'file') {
  $s = db()->prepare('SELECT * FROM team_messages WHERE id=?'); $s->execute([(int)($_GET['id'] ?? 0)]);
  $r = $s->fetch();
  if (!$r || $r['deleted_at'] || !$r['file_name'] || ((int)$r['from_id'] !== $me && (int)$r['to_id'] !== $me)) { http_response_code(404); exit; }
  $path = TEAM_DIR . '/' . basename($r['file_name']);
  if (!is_file($path)) { http_response_code(404); exit; }
  $size = filesize($path); $start = 0; $end = $size - 1;
  header('Content-Type: ' . $r['file_mime']); header('Accept-Ranges: bytes');
  header('Cache-Control: private, max-age=86400'); header('X-Content-Type-Options: nosniff');
  if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $mm)) {
    if ($mm[1] !== '') $start = (int)$mm[1];
    if ($mm[2] !== '') $end = min($end, (int)$mm[2]);
    if ($mm[1] === '' && $mm[2] !== '') { $start = max(0, $size - (int)$mm[2]); $end = $size - 1; }
    if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206); header("Content-Range: bytes $start-$end/$size");
  }
  header('Content-Length: ' . ($end - $start + 1));
  $fh = fopen($path, 'rb'); fseek($fh, $start); $left = $end - $start + 1;
  while ($left > 0 && !feof($fh)) { $chunk = fread($fh, min(8192, $left)); echo $chunk; $left -= strlen($chunk); }
  fclose($fh); exit;
}

/* ---- pin / unpin (either participant) ---- */
if ($m === 'POST' && $a === 'pin' && isset($_GET['id'])) {
  $s = db()->prepare('SELECT id, pinned_at FROM team_messages WHERE id=? AND deleted_at IS NULL AND (from_id=? OR to_id=?)'); $s->execute([(int)$_GET['id'], $me, $me]);
  $r = $s->fetch(); if (!$r) json_error('መልእክቱ አልተገኘም', 404);
  db()->prepare('UPDATE team_messages SET pinned_at=' . ($r['pinned_at'] ? 'NULL' : 'NOW()') . ' WHERE id=?')->execute([$r['id']]);
  json_out(['ok' => true, 'pinned' => !$r['pinned_at']]);
}

/* ---- edit own message (text, or an image caption) ---- */
if ($m === 'PUT' && isset($_GET['id'])) {
  $s = db()->prepare('SELECT * FROM team_messages WHERE id=? AND from_id=? AND deleted_at IS NULL'); $s->execute([(int)$_GET['id'], $me]);
  $r = $s->fetch(); if (!$r) json_error('መልእክቱ አልተገኘም', 404);
  if ($r['kind'] === 'voice') json_error('የድምጽ መልእክት አይስተካከልም');
  $body = mb_substr(trim((string)(json_input()['body'] ?? '')), 0, 4000);
  if ($r['kind'] === 'text' && $body === '') json_error('መልእክቱ ባዶ ነው');
  if ($body !== (string)$r['body']) db()->prepare('UPDATE team_messages SET body=?, edited_at=NOW() WHERE id=?')->execute([$body, $r['id']]);
  json_out(['ok' => true, 'body' => $body]);
}

/* ---- delete own message ---- */
if ($m === 'DELETE' && isset($_GET['id'])) {
  $s = db()->prepare('SELECT * FROM team_messages WHERE id=? AND from_id=?'); $s->execute([(int)$_GET['id'], $me]);
  if ($r = $s->fetch()) {
    if ($r['file_name']) @unlink(TEAM_DIR . '/' . basename($r['file_name']));
    db()->prepare('UPDATE team_messages SET deleted_at=NOW(), body=NULL, file_name=NULL, file_mime=NULL, duration=NULL, pinned_at=NULL WHERE id=?')->execute([$r['id']]);
  }
  json_out(['ok' => true]);
}
json_error('Unknown action', 404);

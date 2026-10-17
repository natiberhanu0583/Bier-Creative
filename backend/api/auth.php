<?php
require_once __DIR__ . '/../db.php';

$action = $_GET['action'] ?? '';

if (method() === 'POST' && $action === 'login') {
  $in = json_input();
  $username = trim($in['username'] ?? '');
  $password = (string)($in['password'] ?? '');

  if ($username === '' || $password === '') {
    json_error('Username እና password ያስፈልጋሉ');
  }

  // brute-force ጥበቃ: ከአንድ IP ወይም ለአንድ username በ15 ደቂቃ ውስጥ 8 ያልተሳኩ ሙከራዎች → ጊዜያዊ እገዳ
  $ip = client_ip();
  try {
    $cnt = db()->prepare('SELECT SUM(ip=?) AS by_ip, SUM(username=?) AS by_user FROM login_attempts WHERE created_at > (NOW() - INTERVAL 15 MINUTE)');
    $cnt->execute([$ip, mb_strtolower($username)]);
    $c = $cnt->fetch();
    if ((int)($c['by_ip'] ?? 0) >= 8 || (int)($c['by_user'] ?? 0) >= 8) {
      json_error('በጣም ብዙ ያልተሳኩ ሙከራዎች — እባክዎ ከ15 ደቂቃ በኋላ እንደገና ይሞክሩ', 429);
    }
  } catch (Throwable $e) {}

  $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
  $stmt->execute([$username]);
  $user = $stmt->fetch();

  // የ username መኖር በጊዜ ልዩነት እንዳይታወቅ ሁልጊዜ hash ይፈትሻል
  $okPw = password_verify($password, $user['password_hash'] ?? '$2y$10$usesomesillystringforsaltuQ3m0u3ZyVq0cQzK1aH8bX1m5eYk8y');
  if (!$user || !$okPw) {
    try { db()->prepare('INSERT INTO login_attempts (ip, username) VALUES (?,?)')->execute([$ip, mb_strtolower($username)]); } catch (Throwable $e) {}
    json_error('የተሳሳተ username ወይም password', 401);
  }
  try {
    db()->prepare('DELETE FROM login_attempts WHERE ip=? OR username=?')->execute([$ip, mb_strtolower($username)]);
    db()->exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 2 DAY)');
  } catch (Throwable $e) {}
  session_regenerate_id(true);
  if (!(int)$user['active']) {
    json_error('አካውንትዎ ታግዷል — እባክዎ ባለቤቱን ያናግሩ', 403);
  }

  $_SESSION['user'] = [
    'id' => (int)$user['id'],
    'full_name' => $user['full_name'],
    'username' => $user['username'],
    'role' => $user['role'],
  ];

  json_out(['ok' => true, 'user' => $_SESSION['user']]);
}

if ($action === 'logout') {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) { $cp = session_get_cookie_params(); setcookie(session_name(), '', time() - 3600, $cp['path'], $cp['domain'] ?? '', $cp['secure'], $cp['httponly']); }
  session_destroy();
  json_out(['ok' => true]);
}

if ($action === 'me') {
  $u = current_user();
  json_out(['ok' => true, 'user' => $u]);
}

json_error('Unknown action', 404);

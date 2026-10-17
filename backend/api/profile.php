<?php
require_once __DIR__ . '/../db.php';

$m = method();
$authUser = require_login();

if ($m === 'GET') {
  $stmt = db()->prepare('SELECT id, full_name, username, phone, role FROM users WHERE id=?');
  $stmt->execute([$authUser['id']]);
  $row = $stmt->fetch();
  if (!$row) json_error('Not found', 404);
  json_out(['ok' => true, 'profile' => $row]);
}

if ($m === 'PUT') {
  $stmt = db()->prepare('SELECT * FROM users WHERE id=?');
  $stmt->execute([$authUser['id']]);
  $row = $stmt->fetch();
  if (!$row) json_error('Not found', 404);

  $in = json_input();
  $fields = [];
  $params = [];
  $sensitiveChange = !empty($in['new_password']) || (isset($in['username']) && trim($in['username']) !== $row['username']);

  // Changing the username or password requires proving you know the current
  // password — protects against someone using an unlocked/left-open session.
  if ($sensitiveChange) {
    $current = (string)($in['current_password'] ?? '');
    if ($current === '' || !password_verify($current, $row['password_hash'])) {
      json_error('የአሁኑ የይለፍ ቃል (current password) ትክክል አይደለም');
    }
  }

  if (isset($in['full_name']) && trim($in['full_name']) !== '') { $fields[] = 'full_name=?'; $params[] = trim($in['full_name']); }
  if (isset($in['phone'])) { $fields[] = 'phone=?'; $params[] = trim($in['phone']); }

  if (isset($in['username']) && trim($in['username']) !== $row['username']) {
    $newUsername = trim($in['username']);
    if ($newUsername === '') json_error('Username ባዶ መሆን አይችልም');
    $exists = db()->prepare('SELECT id FROM users WHERE username=? AND id<>?');
    $exists->execute([$newUsername, $authUser['id']]);
    if ($exists->fetch()) json_error('ይህ username ቀደም ሲል ተይዟል');
    $fields[] = 'username=?'; $params[] = $newUsername;
  }

  if (!empty($in['new_password'])) {
    if (strlen($in['new_password']) < 6) json_error('አዲሱ የይለፍ ቃል ቢያንስ 6 ፊደል ሊኖረው ይገባል');
    $fields[] = 'password_hash=?'; $params[] = password_hash($in['new_password'], PASSWORD_BCRYPT);
    $fields[] = 'password_plain=?'; $params[] = pw_enc($in['new_password']);
  }

  if (!$fields) json_error('የሚቀየር መረጃ የለም');

  $params[] = $authUser['id'];
  db()->prepare('UPDATE users SET ' . implode(',', $fields) . ' WHERE id=?')->execute($params);

  // Keep the session in sync so the sidebar/topbar reflect the change immediately.
  $stmt = db()->prepare('SELECT id, full_name, username, role FROM users WHERE id=?');
  $stmt->execute([$authUser['id']]);
  $_SESSION['user'] = $stmt->fetch();

  json_out(['ok' => true, 'user' => $_SESSION['user']]);
}

json_error('Unsupported request', 405);

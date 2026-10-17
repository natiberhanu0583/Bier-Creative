<?php
require_once __DIR__ . '/../db.php';

$m = method();
$user = require_manager_or_owner(); // GET (view) allowed for owner+manager

if ($m === 'GET') {
  $stmt = db()->query('SELECT id, full_name, username, password_plain, role, phone, active, created_at, updated_at FROM users ORDER BY created_at DESC');
  $rows = $stmt->fetchAll();
  // Only the owner can see everyone's current plaintext password — a manager
  // sees the rest of the staff list but never this field.
  if ($user['role'] !== 'owner') {
    foreach ($rows as &$r) unset($r['password_plain']);
  } else {
    foreach ($rows as &$r) $r['password_plain'] = pw_dec($r['password_plain']);
  }
  unset($r);
  json_out(['ok' => true, 'staff' => $rows]);
}

// account creation / edit / delete: owner only
$ROLES = ['owner','manager','content_creator','editor','coder','host'];

if ($m === 'POST') {
  require_admin();
  $in = json_input();
  $name = trim($in['full_name'] ?? '');
  $username = trim($in['username'] ?? '');
  $password = (string)($in['password'] ?? '');
  $role = in_array(($in['role'] ?? 'content_creator'), $ROLES, true) ? $in['role'] : 'content_creator';

  if ($name === '' || $username === '' || strlen($password) < 6) {
    json_error('ስም, username እና ቢያንስ 6 ፊደል password ያስፈልጋሉ');
  }

  $exists = db()->prepare('SELECT id FROM users WHERE username=?');
  $exists->execute([$username]);
  if ($exists->fetch()) json_error('Username already exists');

  $stmt = db()->prepare('INSERT INTO users (full_name, username, password_hash, password_plain, role, phone) VALUES (?,?,?,?,?,?)');
  $stmt->execute([$name, $username, password_hash($password, PASSWORD_BCRYPT), pw_enc($password), $role, trim($in['phone'] ?? '') ?: null]);
  json_out(['ok' => true, 'id' => db()->lastInsertId()]);
}

if ($m === 'PUT' && isset($_GET['id'])) {
  require_admin();
  $id = (int)$_GET['id'];
  $in = json_input();
  $fields = [];
  $params = [];

  if (isset($in['full_name'])) { $fields[] = 'full_name=?'; $params[] = trim($in['full_name']); }
  if (isset($in['username'])) {
    $newUsername = trim($in['username']);
    if ($newUsername === '') json_error('Username ባዶ መሆን አይችልም');
    $exists = db()->prepare('SELECT id FROM users WHERE username=? AND id<>?');
    $exists->execute([$newUsername, $id]);
    if ($exists->fetch()) json_error('Username already exists');
    $fields[] = 'username=?'; $params[] = $newUsername;
  }
  if (isset($in['phone'])) { $fields[] = 'phone=?'; $params[] = trim($in['phone']); }
  if (isset($in['role']) && in_array($in['role'], $ROLES, true)) { $fields[] = 'role=?'; $params[] = $in['role']; }
  if (isset($in['active'])) {
    if ($id === $user['id'] && !$in['active']) json_error('የራስዎን አካውንት ማገድ አይችሉም');
    $fields[] = 'active=?'; $params[] = $in['active'] ? 1 : 0;
  }
  if (!empty($in['password'])) {
    if (strlen($in['password']) < 6) json_error('Password too short');
    $fields[] = 'password_hash=?'; $params[] = password_hash($in['password'], PASSWORD_BCRYPT);
    $fields[] = 'password_plain=?'; $params[] = pw_enc($in['password']);
  }
  if (!$fields) json_error('Nothing to update');

  $params[] = $id;
  db()->prepare('UPDATE users SET ' . implode(',', $fields) . ' WHERE id=?')->execute($params);
  json_out(['ok' => true]);
}

if ($m === 'DELETE' && isset($_GET['id'])) {
  require_admin();
  $id = (int)$_GET['id'];
  if ($id === $user['id']) json_error('You cannot delete your own account while logged in');
  db()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
  json_out(['ok' => true]);
}

json_error('Unsupported request', 405);

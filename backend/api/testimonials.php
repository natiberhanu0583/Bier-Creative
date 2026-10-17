<?php
require_once __DIR__ . '/../db.php';

$m = method();

/* ---- PUBLIC: submit a new testimonial (goes to "pending") ---- */
if ($m === 'POST' && !isset($_GET['id'])) {
  $in = json_input();
  $name = trim($in['name'] ?? '');
  $text = trim($in['text'] ?? '');
  $rating = max(1, min(5, (int)($in['rating'] ?? 5)));
  if ($name === '' || $text === '') json_error('ስም እና አስተያየት ያስፈልጋሉ');

  $stmt = db()->prepare('INSERT INTO testimonials (customer_name, service, rating, message, status) VALUES (?,?,?,?,\'pending\')');
  $stmt->execute([$name, trim($in['service'] ?? '') ?: null, $rating, $text]);
  $__id = db()->lastInsertId();
  require_once __DIR__ . '/../push.php'; push_wake();
  json_out(['ok' => true, 'id' => $__id]);
}

/* ---- PUBLIC: list approved testimonials for the homepage ---- */
if ($m === 'GET' && ($_GET['scope'] ?? '') === 'public') {
  $stmt = db()->query("SELECT id, customer_name AS name, service, rating, message AS text, created_at
                        FROM testimonials WHERE status='approved' ORDER BY created_at DESC LIMIT 30");
  json_out(['ok' => true, 'testimonials' => $stmt->fetchAll()]);
}

/* ---- everything below is admin-only moderation ---- */
$user = require_manager_or_owner();

if ($m === 'GET') {
  $where = '';
  $params = [];
  if (!empty($_GET['status'])) { $where = 'WHERE status = ?'; $params[] = $_GET['status']; }
  $stmt = db()->prepare("SELECT * FROM testimonials $where ORDER BY created_at DESC");
  $stmt->execute($params);
  json_out(['ok' => true, 'testimonials' => $stmt->fetchAll()]);
}

if ($m === 'PUT' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  $in = json_input();
  $status = $in['status'] ?? '';
  if (!in_array($status, ['pending','approved','rejected'], true)) json_error('Invalid status');

  db()->prepare('UPDATE testimonials SET status=? WHERE id=?')->execute([$status, $id]);
  json_out(['ok' => true]);
}

if ($m === 'DELETE' && isset($_GET['id'])) {
  db()->prepare('DELETE FROM testimonials WHERE id=?')->execute([(int)$_GET['id']]);
  json_out(['ok' => true]);
}

json_error('Unsupported request', 405);

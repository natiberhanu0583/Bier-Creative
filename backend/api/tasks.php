<?php
require_once __DIR__ . '/../db.php';

$m = method();
$user = require_login();

if ($m === 'GET' && !isset($_GET['id'])) {
  $where = [];
  $params = [];
  if (!is_elevated($user['role'])) { $where[] = 't.assigned_to = ?'; $params[] = $user['id']; }
  elseif (!empty($_GET['assigned_to'])) { $where[] = 't.assigned_to = ?'; $params[] = $_GET['assigned_to']; }
  if (!empty($_GET['status'])) { $where[] = 't.status = ?'; $params[] = $_GET['status']; }

  // Filter to a single calendar day (by due_date)
  if (!empty($_GET['date'])) { $where[] = 't.due_date = ?'; $params[] = $_GET['date']; }

  // Overdue = due before today (or before a given `before` date) and not done yet
  if (!empty($_GET['overdue'])) {
    $before = !empty($_GET['before']) ? $_GET['before'] : date('Y-m-d');
    $where[] = 't.due_date IS NOT NULL AND t.due_date < ? AND t.status != \'done\'';
    $params[] = $before;
  }

  $sql = 'SELECT t.*, u.full_name AS assigned_name, o.customer_name AS order_name
          FROM tasks t
          LEFT JOIN users u ON u.id = t.assigned_to
          LEFT JOIN orders o ON o.id = t.order_id';
  if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
  $sql .= ' ORDER BY FIELD(t.status,\'todo\',\'in_progress\',\'done\'), t.due_date IS NULL, t.due_date ASC, t.id DESC';

  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  json_out(['ok' => true, 'tasks' => $stmt->fetchAll()]);
}

if ($m === 'POST') {
  $in = json_input();
  $title = trim($in['title'] ?? '');
  if ($title === '') json_error('Title required');

  // Elevated staff (owner/manager) can assign work to anyone.
  // Regular staff can only register work for themselves — this lets everyone
  // log their own daily tasks, not just managers assigning to others.
  if (is_elevated($user['role'])) {
    $assignedTo = !empty($in['assigned_to']) ? (int)$in['assigned_to'] : null;
  } else {
    $assignedTo = $user['id'];
  }

  $stmt = db()->prepare('INSERT INTO tasks (title, description, order_id, assigned_to, created_by, due_date) VALUES (?,?,?,?,?,?)');
  $stmt->execute([
    $title,
    trim($in['description'] ?? '') ?: null,
    !empty($in['order_id']) ? (int)$in['order_id'] : null,
    $assignedTo,
    $user['id'],
    !empty($in['due_date']) ? $in['due_date'] : date('Y-m-d'),
  ]);
  $__tid = db()->lastInsertId();
  $__an = null;
  if ($assignedTo && (int)$assignedTo !== (int)$user['id']) { $q = db()->prepare('SELECT full_name FROM users WHERE id=?'); $q->execute([$assignedTo]); $__an = $q->fetchColumn(); }
  work_log((int)$user['id'], 'task', (int)$__tid, $title, 'task_created', $__an ? 'ለ' . $__an . ' ተመድቧል' : null);
  if ($assignedTo && (int)$assignedTo !== (int)$user['id']) {
    require_once __DIR__ . '/../push.php';
    notify_user((int)$assignedTo, '📋 አዲስ ስራ ተመድቦልዎታል', $title . ' — ከ' . ($user['full_name'] ?? 'አስተዳዳሪ'), 'tasks');
  }
  json_out(['ok' => true, 'id' => $__tid]);
}

if ($m === 'PUT' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  $stmt = db()->prepare('SELECT * FROM tasks WHERE id=?');
  $stmt->execute([$id]);
  $task = $stmt->fetch();
  if (!$task) json_error('Not found', 404);

  $isOwnerOfTask = (int)$task['assigned_to'] === (int)$user['id'] && (int)$task['created_by'] === (int)$user['id'];
  if (!is_elevated($user['role']) && (int)$task['assigned_to'] !== (int)$user['id']) json_error('Forbidden', 403);

  $in = json_input();
  $fields = [];
  $params = [];

  if (isset($in['status'])) {
    if (!in_array($in['status'], ['todo','in_progress','done'], true)) json_error('Invalid status');
    $fields[] = 'status=?'; $params[] = $in['status'];
    // Track exactly when a task was actually completed, and clear it if reopened.
    $fields[] = 'completed_at=?';
    $params[] = $in['status'] === 'done' ? date('Y-m-d H:i:s') : null;
  }
  // Elevated staff can edit any task's details; regular staff can edit
  // details only for tasks they registered for themselves.
  if (is_elevated($user['role']) || $isOwnerOfTask) {
    if (is_elevated($user['role']) && array_key_exists('assigned_to', $in)) { $fields[] = 'assigned_to=?'; $params[] = $in['assigned_to'] ?: null; }
    if (isset($in['title'])) { $fields[] = 'title=?'; $params[] = trim($in['title']); }
    if (isset($in['description'])) { $fields[] = 'description=?'; $params[] = trim($in['description']); }
    if (array_key_exists('due_date', $in)) { $fields[] = 'due_date=?'; $params[] = $in['due_date'] ?: null; }
  }
  if (!$fields) json_error('Nothing to update');

  $params[] = $id;
  db()->prepare('UPDATE tasks SET ' . implode(',', $fields) . ' WHERE id=?')->execute($params);
  if (isset($in['status']) && $in['status'] !== $task['status']) {
    $map = ['in_progress' => 'task_started', 'done' => 'task_done', 'todo' => 'task_reopened'];
    work_log((int)$user['id'], 'task', $id, $task['title'] ?? '', $map[$in['status']]);
  }
  if (is_elevated($user['role']) && array_key_exists('assigned_to', $in) && (int)($in['assigned_to'] ?: 0) !== (int)$task['assigned_to']) {
    $nm = null; if (!empty($in['assigned_to'])) { $q = db()->prepare('SELECT full_name FROM users WHERE id=?'); $q->execute([(int)$in['assigned_to']]); $nm = $q->fetchColumn(); }
    work_log((int)$user['id'], 'task', $id, $task['title'] ?? '', 'task_assigned', $nm ? 'ለ' . $nm . ' መድቧል' : 'ምደባውን አንስቷል');
  }
  if (is_elevated($user['role']) && array_key_exists('assigned_to', $in) && $in['assigned_to']
      && (int)$in['assigned_to'] !== (int)$task['assigned_to'] && (int)$in['assigned_to'] !== (int)$user['id']) {
    require_once __DIR__ . '/../push.php';
    notify_user((int)$in['assigned_to'], '📋 ስራ ተመድቦልዎታል', ($task['title'] ?? 'ስራ') . ' — ከ' . ($user['full_name'] ?? 'አስተዳዳሪ'), 'tasks');
  }
  json_out(['ok' => true]);
}

if ($m === 'DELETE' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  if (!is_elevated($user['role'])) {
    $stmt = db()->prepare('SELECT * FROM tasks WHERE id=?');
    $stmt->execute([$id]);
    $task = $stmt->fetch();
    $isOwnerOfTask = $task && (int)$task['assigned_to'] === (int)$user['id'] && (int)$task['created_by'] === (int)$user['id'];
    if (!$isOwnerOfTask) json_error('Forbidden', 403);
  }
  $q = db()->prepare('SELECT title FROM tasks WHERE id=?'); $q->execute([$id]); $__t = $q->fetchColumn();
  db()->prepare('DELETE FROM tasks WHERE id=?')->execute([$id]);
  if ($__t !== false) work_log((int)$user['id'], 'task', $id, (string)$__t, 'task_deleted');
  json_out(['ok' => true]);
}

json_error('Unsupported request', 405);

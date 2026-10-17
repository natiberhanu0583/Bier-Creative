<?php
require_once __DIR__ . '/../db.php';
$user = require_login();

$stats = [];

if (is_elevated($user['role'])) {
  $stats['new_orders'] = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status='new'")->fetchColumn();
  $stats['total_orders'] = (int)db()->query("SELECT COUNT(*) FROM orders")->fetchColumn();
  $stats['completed_orders'] = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status='completed'")->fetchColumn();
  $stats['pending_orders'] = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status IN ('new','contacted','in_progress')")->fetchColumn();
  $stats['pending_testimonials'] = (int)db()->query("SELECT COUNT(*) FROM testimonials WHERE status='pending'")->fetchColumn();
  $stats['active_chats'] = (int)db()->query("SELECT COUNT(*) FROM chat_sessions WHERE mode IN ('human_requested','human')")->fetchColumn();
  $stats['open_tasks'] = (int)db()->query("SELECT COUNT(*) FROM tasks WHERE status!='done'")->fetchColumn();
  $stats['done_tasks'] = (int)db()->query("SELECT COUNT(*) FROM tasks WHERE status='done'")->fetchColumn();
  $stats['staff_count'] = (int)db()->query("SELECT COUNT(*) FROM users WHERE role!='owner' AND active=1")->fetchColumn();

  // Order status breakdown (for the dashboard donut chart)
  $statusRows = db()->query("SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status")->fetchAll();
  $stats['orders_by_status'] = $statusRows;

  // Orders this week vs last week (trend arrow for the Orders stat card)
  $ordersThisWeek = (int)db()->query("SELECT COUNT(*) FROM orders WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)")->fetchColumn();
  $ordersLastWeek = (int)db()->query("SELECT COUNT(*) FROM orders WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE() - INTERVAL 7 DAY, 1)")->fetchColumn();
  $stats['orders_this_week'] = $ordersThisWeek;
  $stats['orders_trend_pct'] = $ordersLastWeek > 0 ? round((($ordersThisWeek - $ordersLastWeek) / $ordersLastWeek) * 100, 1) : null;

  // Top requested services (by order count), for the dashboard widget
  $topServices = db()->query(
    "SELECT COALESCE(NULLIF(service,''), 'ያልተገለጸ') AS service, COUNT(*) AS cnt
     FROM orders GROUP BY service ORDER BY cnt DESC LIMIT 5"
  )->fetchAll();
  $stats['top_services'] = $topServices;

  // Finance snapshot: owner only (kept out of the manager's view for privacy)
  if ($user['role'] === 'owner') {
    $income = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE type='income' AND MONTH(txn_date)=MONTH(CURDATE()) AND YEAR(txn_date)=YEAR(CURDATE())")->fetchColumn();
    $expense = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE type='expense' AND MONTH(txn_date)=MONTH(CURDATE()) AND YEAR(txn_date)=YEAR(CURDATE())")->fetchColumn();
    $prevIncome = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE type='income' AND MONTH(txn_date)=MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(txn_date)=YEAR(CURDATE() - INTERVAL 1 MONTH)")->fetchColumn();
    $stats['month_income'] = round($income, 2);
    $stats['month_expense'] = round($expense, 2);
    $stats['month_net'] = round($income - $expense, 2);
    $stats['income_trend_pct'] = $prevIncome > 0 ? round((($income - $prevIncome) / $prevIncome) * 100, 1) : null;
  }
} else {
  $uid = $user['id'];
  $stmt = db()->prepare("SELECT COUNT(*) FROM orders WHERE assigned_to=? AND status NOT IN ('completed','cancelled')");
  $stmt->execute([$uid]);
  $stats['my_orders'] = (int)$stmt->fetchColumn();

  $stmt = db()->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND status!='done'");
  $stmt->execute([$uid]);
  $stats['my_open_tasks'] = (int)$stmt->fetchColumn();

  $stmt = db()->prepare("SELECT COUNT(*) FROM chat_sessions WHERE claimed_by=? AND mode='human'");
  $stmt->execute([$uid]);
  $stats['my_active_chats'] = (int)$stmt->fetchColumn();

  $stats['open_human_requests'] = (int)db()->query("SELECT COUNT(*) FROM chat_sessions WHERE mode='human_requested'")->fetchColumn();

  // --- richer personal dashboard data ---
  $q = db()->prepare("SELECT status, COUNT(*) c FROM tasks WHERE assigned_to=? GROUP BY status"); $q->execute([$uid]);
  $tb = ['todo' => 0, 'in_progress' => 0, 'done' => 0];
  foreach ($q->fetchAll() as $r) $tb[$r['status']] = (int)$r['c'];
  $stats['my_tasks_by_status'] = $tb;
  $stats['my_done_tasks'] = $tb['done'];
  $q = db()->prepare("SELECT COUNT(*) FROM tasks WHERE assigned_to=? AND status!='done' AND due_date IS NOT NULL AND due_date < CURDATE()"); $q->execute([$uid]);
  $stats['my_overdue_tasks'] = (int)$q->fetchColumn();
  $q = db()->prepare("SELECT status, COUNT(*) c FROM orders WHERE assigned_to=? GROUP BY status"); $q->execute([$uid]);
  $stats['my_orders_by_status'] = $q->fetchAll();
  $q = db()->prepare("SELECT COUNT(*) FROM orders WHERE assigned_to=? AND status='completed'"); $q->execute([$uid]);
  $stats['my_completed_orders'] = (int)$q->fetchColumn();
  // tasks finished per day, last 14 days (zero-filled)
  $q = db()->prepare("SELECT DATE(completed_at) d, COUNT(*) c FROM tasks WHERE assigned_to=? AND status='done' AND completed_at >= CURDATE() - INTERVAL 13 DAY GROUP BY DATE(completed_at)"); $q->execute([$uid]);
  $byDay = []; foreach ($q->fetchAll() as $r) $byDay[$r['d']] = (int)$r['c'];
  $q = db()->prepare("SELECT DATE(created_at) d, COUNT(*) c FROM orders WHERE assigned_to=? AND created_at >= CURDATE() - INTERVAL 13 DAY GROUP BY DATE(created_at)"); $q->execute([$uid]);
  $ordDay = []; foreach ($q->fetchAll() as $r) $ordDay[$r['d']] = (int)$r['c'];
  $series = [];
  for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $series[] = ['date' => $d, 'tasks_done' => $byDay[$d] ?? 0, 'orders' => $ordDay[$d] ?? 0]; }
  $stats['my_activity'] = $series;
  $q = db()->prepare("SELECT id, title, status, due_date FROM tasks WHERE assigned_to=? AND status!='done' ORDER BY (due_date IS NULL), due_date ASC, id DESC LIMIT 6"); $q->execute([$uid]);
  $stats['my_next_tasks'] = $q->fetchAll();
  $q = db()->prepare("SELECT id, customer_name, service, status, created_at FROM orders WHERE assigned_to=? ORDER BY id DESC LIMIT 5"); $q->execute([$uid]);
  $stats['my_recent_orders'] = $q->fetchAll();
}

json_out(['ok' => true, 'stats' => $stats, 'role' => $user['role']]);

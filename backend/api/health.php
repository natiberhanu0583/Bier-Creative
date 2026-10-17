<?php
// የግንኙነት ፍተሻ: ሠንጠረዦች መኖራቸውን ያሳያል (ምንም ሚስጥር አያሳይም)። ?repair=1 የጎደሉትን ይፈጥራል።
require_once __DIR__ . '/../db.php';
$need = ['users','orders','order_notes','testimonials','chat_sessions','chat_messages','tasks','finance_transactions','finance_debts','page_views','site_settings','customers','customer_interactions','customer_activities'];
$out = ['ok' => true, 'tables' => [], 'missing' => []];
try {
  $pdo = db();
  if (($_GET['repair'] ?? '') === '1') $out['repair_errors'] = run_schema_file($pdo);
  foreach ($need as $t) {
    try { $n = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); $out['tables'][$t] = $n; }
    catch (Throwable $e) { $out['missing'][] = $t; }
  }
  $out['ok'] = !$out['missing'];
  $out['php'] = PHP_VERSION;
  $out['db_version'] = $pdo->query('SELECT VERSION()')->fetchColumn();
  $log = __DIR__ . '/../schema_errors.log';
  if (is_file($log)) $out['schema_errors'] = array_slice(explode("\n", file_get_contents($log)), 0, 5);
} catch (Throwable $e) { $out['ok'] = false; $out['error'] = $e->getMessage(); }
json_out($out);

<?php
/**
 * CRM shared helpers — customers.php እና activities.php ይጠቀሙበታል።
 *
 * ensure_crm_schema(): ሠንጠረዦቹ ወይም አዲሶቹ ኮሎሞች ከሌሉ በራሱ ይፈጥራል፣
 * ስለዚህ schema.sql ን እንደገና Import ማድረግ ባይችሉም ሲስተሙ ይሰራል።
 */

const CRM_STAGES = ['new', 'contacted', 'meeting', 'proposal', 'negotiation', 'ordered', 'in_progress', 'delivered', 'lost'];
const CRM_STAGE_LABELS = [
  'new' => 'አዲስ', 'contacted' => 'ተነጋግረናል', 'meeting' => 'ቀጠሮ ተይዟል', 'proposal' => 'ዋጋ ተልኳል',
  'negotiation' => 'በድርድር ላይ', 'ordered' => 'ስራ አዟል', 'in_progress' => 'ስራ በሂደት ላይ',
  'delivered' => 'ተጠናቋል', 'lost' => 'አልተሳካም',
];
const CRM_ACT_TYPES = ['call', 'meeting', 'visit', 'message', 'email', 'task', 'payment'];
const CRM_ACT_LABELS = [
  'call' => 'ጥሪ', 'meeting' => 'ስብሰባ', 'visit' => 'ጉብኝት', 'message' => 'መልዕክት',
  'email' => 'ኢሜይል', 'task' => 'ተግባር', 'payment' => 'ክፍያ',
];

function ensure_crm_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  $pdo = db();
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
      id INT AUTO_INCREMENT PRIMARY KEY,
      full_name VARCHAR(150) NOT NULL,
      company VARCHAR(150) DEFAULT NULL,
      phone VARCHAR(40) NOT NULL,
      phone2 VARCHAR(40) DEFAULT NULL,
      email VARCHAR(150) DEFAULT NULL,
      telegram VARCHAR(80) DEFAULT NULL,
      city VARCHAR(100) DEFAULT NULL,
      address VARCHAR(255) DEFAULT NULL,
      type ENUM('individual','business') NOT NULL DEFAULT 'individual',
      status ENUM('lead','active','vip','inactive') NOT NULL DEFAULT 'lead',
      stage ENUM('new','contacted','meeting','proposal','negotiation','ordered','in_progress','delivered','lost') NOT NULL DEFAULT 'new',
      stage_updated_at DATETIME DEFAULT NULL,
      expected_value DECIMAL(12,2) DEFAULT NULL,
      lost_reason VARCHAR(255) DEFAULT NULL,
      source ENUM('website','referral','social','walk_in','phone','other') NOT NULL DEFAULT 'other',
      tags VARCHAR(255) DEFAULT NULL,
      notes TEXT,
      assigned_to INT DEFAULT NULL,
      created_by INT DEFAULT NULL,
      next_followup DATE DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_customers_phone (phone),
      INDEX idx_customers_status (status),
      INDEX idx_customers_stage (stage),
      FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
      FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_interactions (
      id INT AUTO_INCREMENT PRIMARY KEY,
      customer_id INT NOT NULL,
      user_id INT DEFAULT NULL,
      type ENUM('note','call','meeting','message','email','payment') NOT NULL DEFAULT 'note',
      content TEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_ci_customer (customer_id),
      FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ---- ነባር customers ሠንጠረዥ ላይ አዲስ ኮሎሞች ማከል (v2) ----
    $have = [];
    foreach ($pdo->query('SHOW COLUMNS FROM customers')->fetchAll() as $c) $have[$c['Field']] = true;
    $add = [
      'stage' => "ADD COLUMN stage ENUM('new','contacted','meeting','proposal','negotiation','ordered','in_progress','delivered','lost') NOT NULL DEFAULT 'new' AFTER status",
      'stage_updated_at' => 'ADD COLUMN stage_updated_at DATETIME DEFAULT NULL',
      'expected_value' => 'ADD COLUMN expected_value DECIMAL(12,2) DEFAULT NULL',
      'lost_reason' => 'ADD COLUMN lost_reason VARCHAR(255) DEFAULT NULL',
    ];
    foreach ($add as $col => $ddl) {
      if (!isset($have[$col])) $pdo->exec("ALTER TABLE customers $ddl");
    }

    // ---- ተግባራት/ቀጠሮዎች ሠንጠረዥ ----
    $exists = $pdo->query("SHOW TABLES LIKE 'customer_activities'")->fetch();
    if (!$exists) {
      $pdo->exec("CREATE TABLE customer_activities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        type ENUM('call','meeting','visit','message','email','task','payment') NOT NULL DEFAULT 'call',
        title VARCHAR(200) NOT NULL,
        due_date DATE NOT NULL,
        due_time TIME DEFAULT NULL,
        location VARCHAR(200) DEFAULT NULL,
        notes TEXT,
        remind_days TINYINT NOT NULL DEFAULT 1,
        status ENUM('pending','done','cancelled') NOT NULL DEFAULT 'pending',
        assigned_to INT DEFAULT NULL,
        created_by INT DEFAULT NULL,
        outcome TEXT,
        done_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ca_due (status, due_date),
        INDEX idx_ca_customer (customer_id),
        INDEX idx_ca_assigned (assigned_to),
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      // v1 ላይ የነበረውን "ቀጣይ ክትትል ቀን" ወደ ተግባር ይቀይራል
      $pdo->exec("INSERT INTO customer_activities (customer_id, type, title, due_date, assigned_to, created_by)
                  SELECT id, 'call', 'ክትትል', next_followup, assigned_to, created_by FROM customers WHERE next_followup IS NOT NULL");
    }
  } catch (Throwable $e) {
    json_error('CRM ዳታቤዝ ማዘጋጀት አልተቻለም: ' . $e->getMessage(), 500);
  }
}

/* ---------- helpers ---------- */
function phone_key(string $p): string {
  $digits = preg_replace('/\D+/', '', $p);
  return strlen($digits) > 9 ? substr($digits, -9) : $digits;
}

// MySQL 5.7 እና ሁሉም የጋራ ሆስቶች ላይ እንዲሰራ REGEXP_REPLACE ሳይሆን REPLACE ብቻ ይጠቀማል
function phone_norm_sql(string $col): string {
  $e = $col;
  foreach ([' ', '-', '+', '(', ')', '.'] as $ch) $e = "REPLACE($e, '$ch', '')";
  return "RIGHT($e, 9)";
}

function fetch_customer(int $id): ?array {
  $s = db()->prepare(
    'SELECT c.*, u.full_name AS assigned_name
       FROM customers c LEFT JOIN users u ON u.id = c.assigned_to
      WHERE c.id = ? LIMIT 1'
  );
  $s->execute([$id]);
  $r = $s->fetch();
  return $r ?: null;
}

function can_access_customer(array $c, array $user): bool {
  if (is_elevated($user['role'])) return true;
  return (int)$c['assigned_to'] === (int)$user['id'] || (int)$c['created_by'] === (int)$user['id'];
}

function log_interaction(int $cid, ?int $uid, string $type, string $content): void {
  db()->prepare('INSERT INTO customer_interactions (customer_id, user_id, type, content) VALUES (?,?,?,?)')
    ->execute([$cid, $uid, $type, $content]);
}

/** customers.next_followup = ከሚጠባበቁ ተግባራት ቅርቡ ቀን */
function sync_next_followup(int $cid): void {
  db()->prepare(
    "UPDATE customers SET next_followup = (SELECT MIN(due_date) FROM customer_activities WHERE customer_id = ? AND status = 'pending') WHERE id = ?"
  )->execute([$cid, $cid]);
}

function clean_date($v): ?string {
  $v = trim((string)$v);
  if ($v === '') return null;
  $d = DateTime::createFromFormat('Y-m-d', $v);
  return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

function clean_time($v): ?string {
  $v = trim((string)$v);
  if ($v === '') return null;
  if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $v, $m)) return null;
  return sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
}

/** ደረጃ ሲቀየር ይመዘግባል። true = ተቀይሯል */
function set_customer_stage(array $c, string $stage, ?int $uid, ?string $reason = null): bool {
  if (!in_array($stage, CRM_STAGES, true) || $stage === $c['stage']) return false;
  db()->prepare('UPDATE customers SET stage=?, stage_updated_at=NOW(), lost_reason=? WHERE id=?')
    ->execute([$stage, $stage === 'lost' ? ($reason ?: null) : null, (int)$c['id']]);
  $msg = 'ደረጃ ተቀየረ: ' . CRM_STAGE_LABELS[$c['stage']] . ' → ' . CRM_STAGE_LABELS[$stage];
  if ($stage === 'lost' && $reason) $msg .= ' (ምክንያት: ' . $reason . ')';
  log_interaction((int)$c['id'], $uid, 'note', $msg);
  return true;
}

/** mbstring ሳይጭን UTF-8 ጽሁፍ ወደ $n ፊደል ይቆርጣል */
function clip_text(string $s, int $n): string {
  preg_match('/^.{0,' . $n . '}/su', $s, $m);
  return $m[0] ?? '';
}

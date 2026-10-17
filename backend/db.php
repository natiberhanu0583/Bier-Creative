<?php
/**
 * Shared helpers: DB connection, JSON I/O, auth guards.
 * Every file inside /backend/api/*.php requires this file first.
 */

require_once __DIR__ . '/config.php';

/* ---------- CORS + JSON headers ---------- */
// Browsers reject "Allow-Origin: *" together with credentialed requests
// (our admin login uses cookies), so when ALLOWED_ORIGIN is '*' we reflect
// the actual request origin instead — safe here because auth still requires
// a valid username/password, this only affects which sites *can ask*.
$reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
// Only the site itself (same host, with/without www) or the ALLOWED_ORIGIN you set may make credentialed
// cross-origin requests. Any other website is refused — this stops other sites from using a logged-in session.
$__reqHost = preg_replace('/^www\./', '', strtolower(parse_url($reqOrigin, PHP_URL_HOST) ?: ''));
$__myHost  = preg_replace('/^www\./', '', strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '')));
$__corsOk  = $reqOrigin !== '' && ($__reqHost === $__myHost || (ALLOWED_ORIGIN !== '*' && $reqOrigin === ALLOWED_ORIGIN));
if ($__corsOk) header('Access-Control-Allow-Origin: ' . $reqOrigin);
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header('Access-Control-Allow-Credentials: true');
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

/* ---------- Sessions (used for admin/staff login) ---------- */
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
$__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_set_cookie_params(['lifetime' => SESSION_LIFETIME, 'path' => '/', 'secure' => $__https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();



/* ---------- Reveal-able staff passwords are stored ENCRYPTED (AES-256-GCM, key from APP_SECRET) ---------- */
function pw_key(): string { return hash('sha256', 'bier-pw|' . APP_SECRET, true); }
function pw_enc(string $plain): string {
  if ($plain === '' || strpos($plain, 'enc1:') === 0) return $plain;
  $iv = random_bytes(12); $tag = '';
  $ct = openssl_encrypt($plain, 'aes-256-gcm', pw_key(), OPENSSL_RAW_DATA, $iv, $tag);
  return $ct === false ? '' : 'enc1:' . base64_encode($iv . $tag . $ct);
}
function pw_dec(?string $v): string {
  if ($v === null || $v === '') return '';
  if (strpos($v, 'enc1:') !== 0) return $v;
  $raw = base64_decode(substr($v, 5), true);
  if ($raw === false || strlen($raw) < 29) return '';
  $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', pw_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
  return $p === false ? '' : $p;
}
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0'), 0, 45); }

/* ---------- Schema runner: statement-by-statement (PDO::exec ብዙ statements ሲሆን ከ2ኛው ጀምሮ ስህተት አያሳይም) ---------- */
function run_schema_file(PDO $pdo): array {
  $errors = [];
  $sql = @file_get_contents(__DIR__ . '/schema.sql');
  if ($sql === false) return ['schema.sql አልተገኘም'];
  $sql = preg_replace('/^\s*--.*$/m', '', $sql);          // comments
  $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql);          // statement ends at ; + newline
  foreach ($parts as $st) {
    $st = trim($st);
    if ($st === '') continue;
    try { $pdo->exec($st); }
    catch (PDOException $e) {
      // "already exists" / duplicate ችላ ይባላል
      if (!in_array((int)($e->errorInfo[1] ?? 0), [1050, 1060, 1061, 1062], true)) $errors[] = substr($st, 0, 60) . ' … ' . $e->getMessage();
    }
  }
  return $errors;
}

/* ---------- DB connection (PDO) ---------- */
function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    try {
      $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
      $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
    } catch (PDOException $e) {
      $c = (int)($e->errorInfo[1] ?? 0);
      // localhost (XAMPP) ላይ ዳታቤዙ ከሌለ በራስ-ሰር ፍጠር + schema.sql አስገባ
      if ($c === 1049 && !empty($GLOBALS['__isLocal']) && is_file(__DIR__ . '/schema.sql')) {
        try {
          $boot = new PDO('mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
          $boot->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', DB_NAME) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
          $boot->exec('USE `' . str_replace('`', '', DB_NAME) . '`');
          $schemaErr = run_schema_file($boot);
          if ($schemaErr) @file_put_contents(__DIR__ . '/schema_errors.log', implode("\n", $schemaErr));
          $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
          ]);
          return $pdo;
        } catch (PDOException $e2) {
          $e = $e2; $c = (int)($e2->errorInfo[1] ?? 0);
        }
      }
      $hint = 'backend/config.php ውስጥ ያለውን ዳታቤዝ መረጃ ያረጋግጡ.';
      if (strpos(DB_NAME . DB_USER . DB_PASS, 'yourcpanel') !== false || DB_PASS === 'your_db_password') {
        $hint = 'config.php ውስጥ ያሉት DB_NAME / DB_USER / DB_PASS አሁንም ናሙና ናቸው — በcPanel ላይ የፈጠሩትን እውነተኛ ዳታቤዝ ስም፣ ተጠቃሚ እና ፓስዋርድ ያስገቡ.';
      } elseif ($c === 1045) { $hint = 'የዳታቤዝ ተጠቃሚ ስም ወይም ፓስዋርድ ልክ አይደለም (DB_USER / DB_PASS).';
      } elseif ($c === 1049) { $hint = 'ይህ ዳታቤዝ የለም (DB_NAME) — ስሙን ከcPanel MySQL Databases ጋር ያመሳስሉ.';
      } elseif ($c === 1044) { $hint = 'ተጠቃሚው ወደዚህ ዳታቤዝ አልተጨመረም — በcPanel ላይ User ➜ Database ያያይዙ (ALL PRIVILEGES).';
      } elseif ($c === 2002) { $hint = 'ዳታቤዝ ሰርቨሩን ማግኘት አልተቻለም (DB_HOST) — አብዛኛውን ጊዜ "localhost" ነው.'; }
      json_error('Database connection failed. ' . $hint, 500);
    }
  }
  // ዋና ሠንጠረዦች ከጎደሉ (ያልተሟላ ተከላ) በራስ-ሰር ሙላ
  static $healed = false;
  if (!$healed) {
    $healed = true;
    try { $pdo->query('SELECT 1 FROM orders LIMIT 0'); $pdo->query('SELECT 1 FROM testimonials LIMIT 0'); $pdo->query('SELECT 1 FROM chat_messages LIMIT 0'); }
    catch (Throwable $e) { $er = run_schema_file($pdo); if ($er) @file_put_contents(__DIR__ . '/schema_errors.log', implode("\n", $er)); }
  }
  // የባንክ ስም አምድ (ከሌለ ጨምር)
  static $bankCol = false;
  if (!$bankCol) {
    $bankCol = true;
    try {
      if (!$pdo->query("SHOW COLUMNS FROM finance_transactions LIKE 'bank_name'")->fetch()) {
        $pdo->exec("ALTER TABLE finance_transactions ADD COLUMN bank_name VARCHAR(100) DEFAULT NULL AFTER payment_method");
      }
    } catch (Throwable $e) {}
  }

  // ደህንነት: ቀደም ሲል በግልጽ የተቀመጡ password_plain ዋጋዎችን አንድ ጊዜ ኢንክሪፕት አድርግ + login_attempts ሠንጠረዥ
  static $secFix = false;
  if (!$secFix) {
    $secFix = true;
    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, username VARCHAR(80) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(ip, created_at), INDEX(username, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      $rows = $pdo->query("SELECT id, password_plain FROM users WHERE password_plain IS NOT NULL AND password_plain<>'' AND password_plain NOT LIKE 'enc1:%'")->fetchAll();
      if ($rows) { $up = $pdo->prepare("UPDATE users SET password_plain=? WHERE id=?"); foreach ($rows as $r) $up->execute([pw_enc($r['password_plain']), $r['id']]); }
      $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'password_plain'")->fetch();
      if ($col && stripos($col['Type'], 'varchar(') === 0 && (int)filter_var($col['Type'], FILTER_SANITIZE_NUMBER_INT) < 255) $pdo->exec("ALTER TABLE users MODIFY password_plain VARCHAR(255) DEFAULT NULL");
    } catch (Throwable $e) {}
  }
  // ኢሜይል ዶሜይን .et → .com (ባለቤቱ ራሱ ካልቀየረው ብቻ፣ አንድ ጊዜ)
  static $mailFix = false;
  if (!$mailFix) {
    $mailFix = true;
    try { $pdo->exec("UPDATE site_settings SET setting_value='info@biercreative.com' WHERE setting_key='email' AND setting_value='info@biercreative" . ".et'"); } catch (Throwable $e) {}
  }
  // localhost (XAMPP): ቀደም ሲል የተፈጠረውን ነባሪ admin / ChangeMe123! ወደ alex / 123456 አዘምን (አንድ ጊዜ)
  static $synced = false;
  if (!$synced && !empty($GLOBALS['__isLocal'])) {
    $synced = true;
    try {
      $has = $pdo->query("SELECT 1 FROM users WHERE username='alex' LIMIT 1")->fetchColumn();
      if (!$has) {
        $row = $pdo->query("SELECT id, password_hash FROM users WHERE username='admin' AND role='owner' LIMIT 1")->fetch();
        if ($row && password_verify('ChangeMe123!', $row['password_hash'])) {
          $pdo->prepare("UPDATE users SET username='alex', password_hash=?, password_plain='123456' WHERE id=?")
              ->execute([password_hash('123456', PASSWORD_BCRYPT), $row['id']]);
        }
      }
    } catch (Throwable $e) {}
  }
  return $pdo;
}

/* ---------- JSON helpers ---------- */
function json_input(): array {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function json_out($data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function json_error(string $message, int $code = 400): void {
  json_out(['ok' => false, 'error' => $message], $code);
}

/* ---------- Auth guards ---------- */
function current_user(): ?array {
  return $_SESSION['user'] ?? null;
}

function require_login(): array {
  $u = current_user();
  if (!$u) json_error('Login required / መግባት ያስፈልጋል', 401);
  // አካውንት ከታገደ ወይም ከተሰረዘ በአሁኑ ሰዓት የገባ ሰውም ወዲያውኑ ይወጣል
  $chk = db()->prepare('SELECT active, role FROM users WHERE id = ? LIMIT 1');
  $chk->execute([$u['id']]);
  $row = $chk->fetch();
  if (!$row || !(int)$row['active']) {
    $_SESSION = [];
    json_error('አካውንትዎ ታግዷል — ባለቤቱን ያናግሩ / Your account is blocked', 401);
  }
  if ($row['role'] !== $u['role']) { $_SESSION['user']['role'] = $row['role']; $u['role'] = $row['role']; }
  return $u;
}

function require_admin(): array {
  $u = require_login();
  if ($u['role'] !== 'owner') json_error('Owners only / ለባለቤት ብቻ', 403);
  return $u;
}

function require_manager_or_owner(): array {
  $u = require_login();
  if (!in_array($u['role'], ['owner', 'manager'], true)) json_error('Owners/Managers only / ለባለቤት/ማናጀር ብቻ', 403);
  return $u;
}

function is_elevated(string $role): bool {
  return in_array($role, ['owner', 'manager'], true);
}

function method(): string {
  return $_SERVER['REQUEST_METHOD'];
}

function random_token(int $len = 32): string {
  return bin2hex(random_bytes($len));
}

/* ---------------------------------------------------------------------
   Work history log — who did what and when (tasks + orders).
   Titles are snapshotted so the history survives deletions.
   --------------------------------------------------------------------- */
function work_log_ensure(): void {
  static $done = false;
  if ($done) return; $done = true;
  try {
    $exists = db()->query("SHOW TABLES LIKE 'work_log'")->fetch();
    if ($exists) return;
    db()->exec("CREATE TABLE work_log (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NULL,
      kind ENUM('task','order') NOT NULL,
      ref_id INT NULL,
      ref_title VARCHAR(220) NOT NULL DEFAULT '',
      action VARCHAR(30) NOT NULL,
      detail VARCHAR(500) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY (user_id, created_at), KEY (created_at), KEY (kind, action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // one-time backfill from existing data so history is not empty on day one
    db()->exec("INSERT INTO work_log (user_id, kind, ref_id, ref_title, action, detail, created_at)
      SELECT created_by, 'task', id, LEFT(title,220), 'task_created', NULL, created_at FROM tasks WHERE created_by IS NOT NULL");
    db()->exec("INSERT INTO work_log (user_id, kind, ref_id, ref_title, action, detail, created_at)
      SELECT assigned_to, 'task', id, LEFT(title,220), 'task_done', NULL, completed_at FROM tasks WHERE status='done' AND completed_at IS NOT NULL AND assigned_to IS NOT NULL");
    db()->exec("INSERT INTO work_log (user_id, kind, ref_id, ref_title, action, detail, created_at)
      SELECT n.user_id, 'order', n.order_id, LEFT(CONCAT(o.customer_name, IF(o.service IS NULL OR o.service='', '', CONCAT(' — ', o.service))),220), 'order_note', LEFT(n.note,500), n.created_at
      FROM order_notes n JOIN orders o ON o.id=n.order_id WHERE n.user_id IS NOT NULL");
  } catch (Throwable $e) {}
}
function work_log(?int $userId, string $kind, ?int $refId, string $title, string $action, ?string $detail = null): void {
  try {
    work_log_ensure();
    db()->prepare('INSERT INTO work_log (user_id, kind, ref_id, ref_title, action, detail) VALUES (?,?,?,?,?,?)')
        ->execute([$userId, $kind, $refId, mb_substr($title, 0, 220), $action, $detail !== null ? mb_substr($detail, 0, 500) : null]);
  } catch (Throwable $e) {}
}

<?php
/* Web Push (VAPID, empty-payload) + live feed helpers. No external libraries. */

function push_tables(): void {
  static $done = false; if ($done) return; $done = true;
  db()->exec("CREATE TABLE IF NOT EXISTS push_config (k VARCHAR(40) PRIMARY KEY, v TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  db()->exec("CREATE TABLE IF NOT EXISTS notif_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    body VARCHAR(300) NOT NULL DEFAULT '',
    tab VARCHAR(30) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY (user_id, id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  db()->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    endpoint VARCHAR(700) NOT NULL,
    endpoint_hash CHAR(40) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

function vapid_keys(): ?array {
  push_tables();
  $rows = db()->query("SELECT k,v FROM push_config WHERE k IN ('vapid_pem','vapid_pub')")->fetchAll(PDO::FETCH_KEY_PAIR);
  if (!empty($rows['vapid_pem']) && !empty($rows['vapid_pub'])) return ['pem' => $rows['vapid_pem'], 'pub' => $rows['vapid_pub']];
  if (!function_exists('openssl_pkey_new')) return null;
  $res = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
  if (!$res) return null;
  openssl_pkey_export($res, $pem);
  $d = openssl_pkey_get_details($res);
  $pub = b64u("\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT));
  $st = db()->prepare("REPLACE INTO push_config (k,v) VALUES (?,?)");
  $st->execute(['vapid_pem', $pem]); $st->execute(['vapid_pub', $pub]);
  return ['pem' => $pem, 'pub' => $pub];
}

function der_to_raw(string $der): string {   // ECDSA DER -> r||s (64 bytes)
  $o = 3; $rl = ord($der[$o]); $r = substr($der, $o + 1, $rl); $o += 1 + $rl + 1;
  $sl = ord($der[$o]); $s = substr($der, $o + 1, $sl);
  $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT); $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
  return $r . $s;
}
function vapid_jwt(string $aud, array $k): string {
  $h = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
  $p = b64u(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => 'mailto:admin@bier.local']));
  openssl_sign("$h.$p", $der, openssl_pkey_get_private($k['pem']), OPENSSL_ALGO_SHA256);
  return "$h.$p." . b64u(der_to_raw($der));
}

/** Wake every subscribed device (best-effort, short timeouts). */
function push_wake(?array $userIds = null): void {
  try {
    if (!function_exists('curl_init')) return;
    $k = vapid_keys(); if (!$k) return;
    if ($userIds === null) { $subs = db()->query('SELECT id, endpoint FROM push_subscriptions')->fetchAll(); }
    else {
      $userIds = array_values(array_filter(array_map('intval', $userIds)));
      if (!$userIds) return;
      $in = implode(',', array_fill(0, count($userIds), '?'));
      $q = db()->prepare("SELECT id, endpoint FROM push_subscriptions WHERE user_id IN ($in)"); $q->execute($userIds); $subs = $q->fetchAll();
    }
    foreach ($subs as $s) {
      $u = parse_url($s['endpoint']); if (empty($u['scheme']) || empty($u['host'])) continue;
      $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
      $ch = curl_init($s['endpoint']);
      curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Authorization: vapid t=' . vapid_jwt($aud, $k) . ', k=' . $k['pub'], 'TTL: 3600', 'Urgency: high', 'Content-Length: 0'],
      ]);
      curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
      if ($code === 404 || $code === 410) db()->prepare('DELETE FROM push_subscriptions WHERE id=?')->execute([$s['id']]);
    }
  } catch (Throwable $e) {}
}

/** Personal notification for one user (task / order assigned to them) + wake their devices. */
function notify_user(int $uid, string $title, string $body = '', string $tab = ''): void {
  try {
    push_tables();
    db()->prepare('INSERT INTO notif_events (user_id, title, body, tab) VALUES (?,?,?,?)')
        ->execute([$uid, mb_substr($title, 0, 200), mb_substr($body, 0, 300), $tab]);
    push_wake([$uid]);
  } catch (Throwable $e) {}
}

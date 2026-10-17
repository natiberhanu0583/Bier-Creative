<?php
require_once __DIR__ . '/../db.php';

$m = method();

/* ---- PUBLIC: read all settings (used by main website + chatbot) ---- */
if ($m === 'GET') {
  $rows = db()->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll();
  $out = [];
  foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
  json_out(['ok' => true, 'settings' => $out]);
}

/* ---- OWNER ONLY: update one or more settings ---- */
if ($m === 'PUT') {
  require_admin();
  $in = json_input();
  if (!$in || !is_array($in)) json_error('No settings provided');

  $stmt = db()->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
  db()->beginTransaction();
  foreach ($in as $key => $value) {
    // only allow known keys, alphanumeric/underscore, to prevent arbitrary key injection
    if (!preg_match('/^[a-z0-9_]{1,80}$/', $key)) continue;
    $value = trim((string)$value);
    // URL-type settings: only http(s) links are kept (blocks javascript: etc.)
    if (in_array($key, ['instagram', 'facebook', 'tiktok', 'youtube', 'map_link'], true) && $value !== '' && !preg_match('#^https?://#i', $value)) $value = '';
    // map_embed: accept a pasted <iframe> or URL, keep only the Google Maps https src
    if ($key === 'map_embed' && $value !== '') {
      if (preg_match('/src=["\']([^"\']+)["\']/i', $value, $mm)) $value = $mm[1];
      $value = html_entity_decode($value);
      if (!preg_match('#^https://(www\.)?(google\.[a-z.]+/maps|maps\.google\.[a-z.]+)#i', $value)) $value = '';
    }
    $stmt->execute([$key, $value]);
  }
  db()->commit();

  json_out(['ok' => true]);
}

json_error('Unsupported request', 405);

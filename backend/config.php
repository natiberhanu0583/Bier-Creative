<?php
/**
 * ============================================================
 *  BIER CREATIVE - Backend Configuration
 *  እነዚህን ከየጋራ ሆስት (cPanel) MySQL Database ጋር ይተኩ
 * ============================================================
 */

// --- Database ---
// በኮምፒውተርዎ (XAMPP / localhost) ሲከፍቱ በራስ-ሰር XAMPP ነባሪ ይጠቀማል:
//   ዳታቤዝ ስም = bier ፣ ተጠቃሚ = root ፣ ፓስዋርድ = ባዶ
// በእውነተኛ ሆስት (cPanel) ላይ ሲጭኑ ከታች ያሉትን 3 እሴቶች ይቀይሩ።
$__host = strtolower($_SERVER['HTTP_HOST'] ?? 'localhost');
$__isLocal = (bool)preg_match('/^(localhost|127\.0\.0\.1|\[::1\]|192\.168\.[0-9.]+)(:\d+)?$/', $__host);

define('DB_HOST', 'localhost');
define('DB_NAME', $__isLocal ? 'bier'  : 'biercrxg_biercreative');
define('DB_USER', $__isLocal ? 'root'  : 'biercrxg_biercrxg');
define('DB_PASS', $__isLocal ? ''      : 'your_db_password'); // <- cPanel ላይ ለተጠቃሚው የሰጡትን የይለፍ ቃል እዚህ ይተኩ
define('DB_CHARSET', 'utf8mb4');

// --- Security ---
// ማንኛውም ራንደም ረጅም ጽሁፍ ያድርጉት (ለ session/cookie ደህንነት)
define('APP_SECRET', 'change-this-to-a-long-random-string');

// --- CORS: ድህረ ገጹ እና አድሚኑ በተመሳሳይ ዶሜይን ላይ ከሆኑ መቀየር አያስፈልግም ---
define('ALLOWED_ORIGIN', '*'); // production ላይ ወደ 'https://yourdomain.com' ይቀይሩ

// --- Session cookie lifetime (seconds) ---
define('SESSION_LIFETIME', 60 * 60 * 12); // 12 hours

// --- AI ቻት ቦት (Anthropic Claude API) ---
// console.anthropic.com ላይ ገብተው API Key ይፍጠሩ እና እዚህ ይለጥፉ
define('ANTHROPIC_API_KEY', 'sk-ant-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'); // <- ይቀይሩ
define('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'); // ፈጣን እና ርካሽ፤ ካስፈለገ 'claude-sonnet-5' ይቀይሩ

// PHP ማስጠንቀቂያዎች JSON ምላሹን እንዳያበላሹ (XAMPP ነባሪ display_errors=On ነው)
ini_set('display_errors', '0');
error_reporting(E_ALL);

date_default_timezone_set('Africa/Addis_Ababa');

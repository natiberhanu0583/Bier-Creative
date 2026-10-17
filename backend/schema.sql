-- =====================================================================
--  BIER CREATIVE — PRODUCTION DATABASE SCHEMA (v3.0 Perfect Edition)
--  የብዕር ክርኤቲቭ ሙሉ የዳታቤዝ መዋቅር (አድሚን፣ ስታፍ፣ CRM፣ ፋይናንስ፣ ቻት፣ ፑሽ)
-- =====================================================================
--  Character Set: utf8mb4 / Collation: utf8mb4_unicode_ci (ሙሉ የአማርኛ ጽሁፍ ድጋፍ)
--  ይህን ፋይል በ cPanel phpMyAdmin ወይም MySQL CLI ውስጥ አንድ ጊዜ Import ያድርጉ።
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;
SET time_zone = '+03:00';

-- ---------------------------------------------------------------------
-- 1. USERS: የአድሚን፣ ማናጀር እና ሰራተኞች መለያዎች
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  password_plain VARCHAR(255) DEFAULT NULL,
  role ENUM('owner','manager','content_creator','editor','coder','host') NOT NULL DEFAULT 'content_creator',
  phone VARCHAR(40) DEFAULT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role (role),
  INDEX idx_users_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. SITE_SETTINGS: የድህረ ገጹ እና ቢሮው ዋና ዋና መረጃዎች (Key-Value)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. ORDERS: ከዋናው ድረ-ገጽ ወይም በአድሚን የተመዘገቡ የደንበኛ ትዕዛዞች
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_name VARCHAR(150) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  email VARCHAR(150) DEFAULT NULL,
  service VARCHAR(100) DEFAULT NULL,
  budget VARCHAR(60) DEFAULT NULL,
  message TEXT,
  status ENUM('new','contacted','in_progress','completed','cancelled') NOT NULL DEFAULT 'new',
  source ENUM('website','manual') NOT NULL DEFAULT 'website',
  assigned_to INT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_orders_status (status),
  INDEX idx_orders_created (created_at),
  INDEX idx_orders_phone (phone),
  INDEX idx_orders_assigned (assigned_to),
  INDEX idx_orders_created_by (created_by),
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. ORDER_NOTES: በትዕዛዞች ላይ የተጻፉ ማስታወሻዎች
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  user_id INT DEFAULT NULL,
  note TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_on_order (order_id),
  INDEX idx_on_user (user_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. TESTIMONIALS: የደንበኞች አስተያየቶችና ምዘናዎች
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS testimonials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_name VARCHAR(150) NOT NULL,
  service VARCHAR(100) DEFAULT NULL,
  rating TINYINT NOT NULL DEFAULT 5,
  message TEXT NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_test_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. CHAT_SESSIONS: የቀጥታ AI እና የሰው ቻት ክፍለ-ጊዜዎች
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_key VARCHAR(64) NOT NULL UNIQUE,
  visitor_name VARCHAR(120) DEFAULT NULL,
  lang VARCHAR(10) DEFAULT 'am',
  mode ENUM('bot','human_requested','human','closed') NOT NULL DEFAULT 'bot',
  claimed_by INT DEFAULT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_active DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_chat_mode_active (mode, last_active),
  INDEX idx_chat_claimed (claimed_by),
  FOREIGN KEY (claimed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. CHAT_MESSAGES: የቻት መልእክቶች (የደንበኛ፣ AI ቦት፣ ሰራተኛ)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  sender ENUM('user','bot','staff') NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cm_session_id (session_id, id),
  INDEX idx_cm_created (created_at),
  FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. TASKS: ለሰራተኞች የሚሰጡ የስራ ተግባራት
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  order_id INT DEFAULT NULL,
  assigned_to INT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  status ENUM('todo','in_progress','done') NOT NULL DEFAULT 'todo',
  due_date DATE DEFAULT NULL,
  completed_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tasks_status_due (status, due_date),
  INDEX idx_tasks_assigned (assigned_to),
  INDEX idx_tasks_order (order_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. FINANCE_TRANSACTIONS: የገቢና ወጪ የሂሳብ መዝገብ
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS finance_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('income','expense') NOT NULL,
  category VARCHAR(100) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  description TEXT,
  party_name VARCHAR(150) DEFAULT NULL,
  payment_method ENUM('cash','bank_transfer','mobile_money','check','other') NOT NULL DEFAULT 'cash',
  bank_name VARCHAR(100) DEFAULT NULL,
  bank_reference VARCHAR(100) DEFAULT NULL,
  order_id INT DEFAULT NULL,
  txn_date DATE NOT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ft_date_type (txn_date, type),
  INDEX idx_ft_order (order_id),
  INDEX idx_ft_created_by (created_by),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. FINANCE_DEBTS: የብድርና የዕዳ መዝገብ (የሚሰበሰብ / የሚከፈል)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS finance_debts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('receivable','payable') NOT NULL,
  party_name VARCHAR(150) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  description TEXT,
  category VARCHAR(100) DEFAULT NULL,
  due_date DATE DEFAULT NULL,
  status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  order_id INT DEFAULT NULL,
  linked_transaction_id INT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME DEFAULT NULL,
  INDEX idx_fd_status_due (status, due_date),
  INDEX idx_fd_party (party_name),
  INDEX idx_fd_order (order_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  FOREIGN KEY (linked_transaction_id) REFERENCES finance_transactions(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. PAGE_VIEWS: የድህረ ገጹ ጎብኚዎች እና ትራፊክ መከታተያ
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS page_views (
  id INT AUTO_INCREMENT PRIMARY KEY,
  visitor_key VARCHAR(64) NOT NULL,
  page VARCHAR(255) DEFAULT '/',
  referrer VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pv_created (created_at),
  INDEX idx_pv_visitor (visitor_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. CUSTOMERS: CRM የደንበኞች ሙሉ ዝርዝርና መረጃ
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
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
  INDEX idx_customers_assigned (assigned_to),
  INDEX idx_customers_followup (next_followup),
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. CUSTOMER_INTERACTIONS: ከደንበኛ ጋር የተደረጉ ግንኙነቶች (ጥሪ፣ ስብሰባ፣ ኢሜይል)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_interactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  user_id INT DEFAULT NULL,
  type ENUM('note','call','meeting','message','email','payment') NOT NULL DEFAULT 'note',
  content TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ci_customer (customer_id),
  INDEX idx_ci_user (user_id),
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 14. CUSTOMER_ACTIVITIES: ከደንበኞች ጋር ያሉ ተግባራት፣ ቀጠሮዎች እና ማሳሰቢያዎች
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_activities (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 15. USER_NOTES: የሰራተኞች የግል ማስታወሻዎች
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(150) NOT NULL DEFAULT '',
  body TEXT,
  color VARCHAR(20) NOT NULL DEFAULT 'gold',
  pinned TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_un_user_pinned (user_id, pinned),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 16. TEAM_MESSAGES: በሰራተኞች መካከል የሚላኩ መልእክቶች (የቡድን ውይይት)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS team_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  from_id INT NOT NULL,
  to_id INT NOT NULL,
  kind ENUM('text','voice','image') NOT NULL DEFAULT 'text',
  body TEXT,
  file_name VARCHAR(120) DEFAULT NULL,
  file_mime VARCHAR(60) DEFAULT NULL,
  duration INT DEFAULT NULL,
  reply_to INT DEFAULT NULL,
  fwd_name VARCHAR(120) DEFAULT NULL,
  pinned_at DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  edited_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME DEFAULT NULL,
  INDEX idx_tm_conversation (from_id, to_id, id),
  INDEX idx_tm_unread (to_id, read_at),
  INDEX idx_tm_pinned (pinned_at),
  FOREIGN KEY (from_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (to_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (reply_to) REFERENCES team_messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 17. WORK_LOG: የስራ ክንውን ታሪክ (ማን፣ ምን፣ መቼ ሰራ)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS work_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  kind ENUM('task','order') NOT NULL,
  ref_id INT DEFAULT NULL,
  ref_title VARCHAR(220) NOT NULL DEFAULT '',
  action VARCHAR(50) NOT NULL,
  detail VARCHAR(500) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_wl_user_created (user_id, created_at),
  INDEX idx_wl_created (created_at),
  INDEX idx_wl_kind_action (kind, action),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 18. PUSH_CONFIG: የዌብ ፑሽ (Web Push VAPID) ቁልፎች
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS push_config (
  k VARCHAR(40) PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 19. NOTIF_EVENTS: ለተጠቃሚዎች የተላኩ የኖቲፊኬሽን ክስተቶች
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notif_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  body VARCHAR(300) NOT NULL DEFAULT '',
  tab VARCHAR(30) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ne_user_id (user_id, id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 20. PUSH_SUBSCRIPTIONS: የተመዘገቡ የብራውዘር/ስልክ ፑሽ አድራሻዎች
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS push_subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  endpoint VARCHAR(700) NOT NULL,
  endpoint_hash CHAR(40) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ps_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 21. LOGIN_ATTEMPTS: የደህንነት መዝገብ (ያልተሳኩ የሎግኢን ሙከራዎች ገደብ)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(80) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_la_ip (ip, created_at),
  INDEX idx_la_user (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
--  DEFAULT SEED DATA (ነባሪ መረጃዎች)
-- =====================================================================

-- 1. የቢሮው ነባሪ መረጃዎች (Site Settings)
INSERT INTO site_settings (setting_key, setting_value) VALUES
  ('phone_primary', '+251925898913'),
  ('phone_secondary', '+251711727205'),
  ('email', 'info@biercreative.com'),
  ('telegram', '@biercreative'),
  ('instagram', 'https://instagram.com/bier_creative'),
  ('facebook', 'https://www.facebook.com/share/1FB8XGnkQ3/'),
  ('tiktok', 'https://tiktok.com/@biercreative'),
  ('address_am', 'ደብረ ብርሃን፣ ሰማያዊ ገባ ብሎ፣ እህል በረንዳ አካባቢ፣ ቦህ ህንጻ 3ኛ ፎቅ'),
  ('address_en', 'Debre Birhan, Semayawi area, near Ehil Beranda, Boh Building, 3rd floor'),
  ('map_query', 'Debre Birhan, Ethiopia'),
  ('hours_weekday_open', '08:00'),
  ('hours_weekday_close', '17:30'),
  ('hours_saturday_open', '09:00'),
  ('hours_saturday_close', '17:00')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- 2. ነባሪ የባለቤት (Owner) አካውንት:
--    Username: alex | Default Password: 123456
--    ማሳሰቢያ: የመጀመሪያውን ሎግኢን ካደረጉ በኋላ ወዲያውኑ በ Profile ገጽ ይቀይሩት!
INSERT INTO users (full_name, username, password_hash, password_plain, role, phone, active)
VALUES (
  'አለማየሁ ዘነበ',
  'alex',
  '$2y$10$Xe.adXq1q4w8.QEmv6DfIuqxUg56pMmP3jn/II.JtTWtem1gKhHVy',
  '123456',
  'owner',
  '+251925898913',
  1
)
ON DUPLICATE KEY UPDATE username = username;

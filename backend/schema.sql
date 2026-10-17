-- ============================================================
--  Bier Creative - Admin/Staff Backend Database Schema
--  ይህን ፋይል በ phpMyAdmin ወይም MySQL CLI ውስጥ አንድ ጊዜ Import ያድርጉ
-- ============================================================

SET NAMES utf8mb4;

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
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ▼▼ Migration: if you already have a `users` table from before (existing database),
--    run these lines once in phpMyAdmin / your MySQL client to add the new columns:
--    ALTER TABLE users ADD COLUMN password_plain VARCHAR(255) DEFAULT NULL AFTER password_hash;
--    ALTER TABLE users ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
--    (password_plain will be empty for existing accounts until each person's password is next changed —
--     that's expected, since old passwords were only ever stored as a hash.)

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
  created_by INT DEFAULT NULL, -- staff who manually registered this order (NULL for website submissions)
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If you already have this table from an earlier install, run this instead
-- of re-importing the whole schema (safe to run once, MySQL 8.0.29+):
-- ALTER TABLE orders
--   ADD COLUMN IF NOT EXISTS source ENUM('website','manual') NOT NULL DEFAULT 'website' AFTER status,
--   ADD COLUMN IF NOT EXISTS created_by INT DEFAULT NULL AFTER assigned_to,
--   ADD CONSTRAINT fk_orders_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS order_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  user_id INT DEFAULT NULL,
  note TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS testimonials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_name VARCHAR(150) NOT NULL,
  service VARCHAR(100) DEFAULT NULL,
  rating TINYINT NOT NULL DEFAULT 5,
  message TEXT NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_key VARCHAR(64) NOT NULL UNIQUE,
  visitor_name VARCHAR(120) DEFAULT NULL,
  lang VARCHAR(5) DEFAULT 'am',
  mode ENUM('bot','human_requested','human','closed') NOT NULL DEFAULT 'bot',
  claimed_by INT DEFAULT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_active DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (claimed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  sender ENUM('user','bot','staff') NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ▼▼ Migration: if you already have a `tasks` table from before (existing database),
--    run this one line once in phpMyAdmin / your MySQL client to add the new column:
--    ALTER TABLE tasks ADD COLUMN completed_at DATETIME DEFAULT NULL AFTER due_date;

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
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If you already have this table from an earlier install, run this instead
-- of re-importing the whole schema (safe to run once, MySQL 8.0.29+):
-- ALTER TABLE finance_transactions
--   ADD COLUMN IF NOT EXISTS party_name VARCHAR(150) DEFAULT NULL AFTER description,
--   ADD COLUMN IF NOT EXISTS payment_method ENUM('cash','bank_transfer','mobile_money','check','other') NOT NULL DEFAULT 'cash' AFTER party_name,
--   ADD COLUMN IF NOT EXISTS bank_reference VARCHAR(100) DEFAULT NULL AFTER payment_method;

CREATE TABLE IF NOT EXISTS finance_debts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  type ENUM('receivable','payable') NOT NULL, -- receivable: they owe us | payable: we owe them
  party_name VARCHAR(150) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  description TEXT,
  category VARCHAR(100) DEFAULT NULL,
  due_date DATE DEFAULT NULL,
  status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  order_id INT DEFAULT NULL,
  linked_transaction_id INT DEFAULT NULL, -- the ledger entry created when this was marked paid
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME DEFAULT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  FOREIGN KEY (linked_transaction_id) REFERENCES finance_transactions(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS page_views (
  id INT AUTO_INCREMENT PRIMARY KEY,
  visitor_key VARCHAR(64) NOT NULL,
  page VARCHAR(255) DEFAULT '/',
  referrer VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (created_at),
  INDEX idx_visitor (visitor_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS site_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value TEXT,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO site_settings (setting_key, setting_value) VALUES
  ('phone_primary', '+251925898913'),
  ('phone_secondary', '+251711727205'),
  ('email', 'info@biercreative.com'),
  ('telegram', '@biercreative'),
  ('address_am', 'ደብረ ብርሃን፣ ሰማያዊ ገባ ብሎ፣ እህል በረንዳ አካባቢ፣ ቦህ ህንጻ 3ኛ ፎቅ'),
  ('address_en', 'Debre Birhan, Semayawi area, near Ehil Beranda, Boh Building, 3rd floor'),
  ('hours_weekday_open', '08:00'),
  ('hours_weekday_close', '17:30'),
  ('hours_saturday_open', '09:00'),
  ('hours_saturday_close', '17:00')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ነባሪ የባለቤት (Owner) አካውንት: username = alex / password = 123456
-- ይህንን የይለፍ ቃል ወዲያውኑ ይቀይሩ! (ስታፍ ገጽ ውስጥ)
INSERT INTO users (full_name, username, password_hash, password_plain, role)
VALUES ('አለማየሁ ዘነበ', 'alex', '$2y$10$Xe.adXq1q4w8.QEmv6DfIuqxUg56pMmP3jn/II.JtTWtem1gKhHVy', '123456', 'owner')
ON DUPLICATE KEY UPDATE username = username;


-- ============================================================
--  CRM: የደንበኞች መዝገብ (Customers) — v2
--  ማሳሰቢያ: ይህን ክፍል Import ባያደርጉም backend/crm_lib.php ሲስተሙ ሲከፈት
--  ሠንጠረዦቹን እና አዲሶቹን ኮሎሞች በራሱ ይፈጥራል/ያሻሽላል። ለነባር ዳታ አይነካም።
-- ============================================================
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
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_interactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  user_id INT DEFAULT NULL,
  type ENUM('note','call','meeting','message','email','payment') NOT NULL DEFAULT 'note',
  content TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ci_customer (customer_id),
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ተግባራት / ቀጠሮዎች / ማሳሰቢያዎች
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Personal notes + internal team messenger (also created automatically by the API)
CREATE TABLE IF NOT EXISTS user_notes (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(150) NOT NULL DEFAULT '',
  body TEXT, color VARCHAR(12) NOT NULL DEFAULT 'gold', pinned TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS team_messages (
  id INT AUTO_INCREMENT PRIMARY KEY, from_id INT NOT NULL, to_id INT NOT NULL,
  kind ENUM('text','voice','image') NOT NULL DEFAULT 'text', body TEXT, file_name VARCHAR(120), file_mime VARCHAR(60),
  duration INT, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, read_at DATETIME NULL,
  KEY (from_id,to_id), KEY (to_id,read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ደህንነት: የሎግኢን ሙከራ ገደብ
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(80) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(ip, created_at),
  INDEX(username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

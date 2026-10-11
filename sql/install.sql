CREATE DATABASE IF NOT EXISTS `yuexin` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `yuexin`;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(120) DEFAULT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  status TINYINT NOT NULL DEFAULT 1,
  last_login_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS books (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  subtitle VARCHAR(255) DEFAULT NULL,
  author VARCHAR(255) DEFAULT NULL,
  author_country VARCHAR(100) DEFAULT NULL,
  publisher VARCHAR(255) DEFAULT NULL,
  publish_date VARCHAR(50) DEFAULT NULL,
  isbn VARCHAR(50) DEFAULT NULL,
  book_type VARCHAR(100) DEFAULT NULL,
  tags VARCHAR(500) DEFAULT NULL,
  format ENUM('paper','ebook','audiobook','unknown') NOT NULL DEFAULT 'unknown',
  platform VARCHAR(100) DEFAULT NULL,
  cover_url VARCHAR(500) DEFAULT NULL,
  status ENUM('unread','reading','finished','unfinished','abandoned') NOT NULL DEFAULT 'unread',
  progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
  first_start_date DATE DEFAULT NULL,
  final_finish_date DATE DEFAULT NULL,
  rating_score DECIMAL(3,1) DEFAULT NULL,
  rating_level ENUM('masterpiece','good','normal','bad','abandoned') DEFAULT NULL,
  short_review TEXT DEFAULT NULL,
  notes MEDIUMTEXT DEFAULT NULL,
  douban_subject_id VARCHAR(30) DEFAULT NULL,
  douban_title VARCHAR(255) DEFAULT NULL,
  douban_author VARCHAR(255) DEFAULT NULL,
  douban_publisher VARCHAR(255) DEFAULT NULL,
  douban_publish_date VARCHAR(50) DEFAULT NULL,
  douban_cover_url VARCHAR(500) DEFAULT NULL,
  douban_rating DECIMAL(3,1) DEFAULT NULL,
  douban_rating_count INT UNSIGNED DEFAULT NULL,
  douban_read_count INT UNSIGNED DEFAULT NULL,
  douban_wish_count INT UNSIGNED DEFAULT NULL,
  douban_summary TEXT DEFAULT NULL,
  douban_updated_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user_status (user_id,status),
  INDEX idx_user_title (user_id,title),
  INDEX idx_user_isbn (user_id,isbn),
  INDEX idx_douban (user_id,douban_subject_id),
  CONSTRAINT fk_books_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reading_plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  plan_type ENUM('weekly','monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  target_count INT UNSIGNED DEFAULT 0,
  description TEXT DEFAULT NULL,
  status ENUM('not_started','active','completed','failed','cancelled') NOT NULL DEFAULT 'not_started',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user_plan (user_id,plan_type,status,start_date),
  CONSTRAINT fk_plans_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reading_tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NOT NULL,
  book_id BIGINT UNSIGNED DEFAULT NULL,
  raw_book_title VARCHAR(255) DEFAULT NULL,
  raw_author VARCHAR(255) DEFAULT NULL,
  planned_start_date DATE DEFAULT NULL,
  planned_finish_date DATE DEFAULT NULL,
  actual_start_date DATE DEFAULT NULL,
  actual_finish_date DATE DEFAULT NULL,
  status ENUM('unread','reading','completed','unfinished','abandoned') NOT NULL DEFAULT 'unread',
  progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
  continue_next TINYINT(1) NOT NULL DEFAULT 0,
  rating_score DECIMAL(3,1) DEFAULT NULL,
  rating_level ENUM('masterpiece','good','normal','bad','abandoned') DEFAULT NULL,
  comment TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user_task (user_id,status,actual_finish_date),
  INDEX idx_plan (plan_id),
  INDEX idx_book (book_id),
  CONSTRAINT fk_tasks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_tasks_plan FOREIGN KEY (plan_id) REFERENCES reading_plans(id) ON DELETE CASCADE,
  CONSTRAINT fk_tasks_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reading_progress_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  task_id BIGINT UNSIGNED DEFAULT NULL,
  book_id BIGINT UNSIGNED DEFAULT NULL,
  progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(30) DEFAULT NULL,
  log_date DATE NOT NULL,
  note TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_log (user_id,log_date),
  CONSTRAINT fk_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_logs_task FOREIGN KEY (task_id) REFERENCES reading_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_logs_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  filename VARCHAR(255) DEFAULT NULL,
  import_type ENUM('books','history') NOT NULL DEFAULT 'books',
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  success_rows INT UNSIGNED NOT NULL DEFAULT 0,
  failed_rows INT UNSIGNED NOT NULL DEFAULT 0,
  history_plan_id BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_import_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_import_plan FOREIGN KEY (history_plan_id) REFERENCES reading_plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 豆瓣信息补全：异步队列（由豆瓣功能触发时自动写入）
CREATE TABLE IF NOT EXISTS douban_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  total INT NOT NULL DEFAULT 0,
  done INT NOT NULL DEFAULT 0,
  success INT NOT NULL DEFAULT 0,
  failed INT NOT NULL DEFAULT 0,
  skipped INT NOT NULL DEFAULT 0,
  consecutive_failed INT NOT NULL DEFAULT 0,
  current_book_id INT DEFAULT NULL,
  current_title VARCHAR(255) DEFAULT NULL,
  message TEXT DEFAULT NULL,
  last_error TEXT DEFAULT NULL,
  cancel_requested TINYINT(1) NOT NULL DEFAULT 0,
  notified TINYINT(1) NOT NULL DEFAULT 0,
  started_at DATETIME DEFAULT NULL,
  finished_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_status (user_id, status),
  KEY idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS douban_job_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT UNSIGNED NOT NULL,
  book_id INT NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  error TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  message TEXT DEFAULT NULL,
  UNIQUE KEY uniq_job_book (job_id, book_id),
  KEY idx_job_status (job_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS libraryflow
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE libraryflow;

CREATE TABLE IF NOT EXISTS books (
  book_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  isbn VARCHAR(20) NULL,
  title VARCHAR(200) NOT NULL,
  author VARCHAR(150) NOT NULL,
  publisher VARCHAR(150) NULL,
  category VARCHAR(80) NULL,
  publication_year SMALLINT UNSIGNED NULL,
  total_copies SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (book_id),
  UNIQUE KEY uq_books_isbn (isbn),
  KEY idx_books_title (title),
  KEY idx_books_author (author)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS members (
  member_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_code VARCHAR(30) NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  course VARCHAR(100) NULL,
  section_group VARCHAR(80) NULL,
  semester TINYINT UNSIGNED NULL,
  phone VARCHAR(20) NULL,
  email VARCHAR(254) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (member_id),
  UNIQUE KEY uq_members_code (member_code),
  KEY idx_members_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS issue_records (
  issue_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id INT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  issue_date DATE NOT NULL,
  due_date DATE NOT NULL,
  return_date DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (issue_id),
  KEY idx_issue_book_open (book_id, return_date),
  KEY idx_issue_member_open (member_id, return_date),
  KEY idx_issue_due_open (due_date, return_date),
  CONSTRAINT fk_issue_book
    FOREIGN KEY (book_id) REFERENCES books (book_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_issue_member
    FOREIGN KEY (member_id) REFERENCES members (member_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  user_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  display_name VARCHAR(100) NOT NULL,
  email VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin', 'librarian') NOT NULL DEFAULT 'librarian',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  session_version INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  token_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (token_id),
  UNIQUE KEY uq_password_reset_token_hash (token_hash),
  KEY idx_password_reset_user_created (user_id, created_at),
  KEY idx_password_reset_expires (expires_at),
  CONSTRAINT fk_password_reset_user
    FOREIGN KEY (user_id) REFERENCES users (user_id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_rate_limits (
  attempt_key CHAR(64) NOT NULL,
  window_started_at DATETIME NOT NULL,
  request_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (attempt_key),
  KEY idx_password_reset_rate_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  attempt_key CHAR(64) NOT NULL,
  failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  locked_until DATETIME NULL,
  PRIMARY KEY (attempt_key),
  KEY idx_login_attempts_locked_until (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

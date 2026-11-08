-- ===========================================================================
-- CSPC Accounting Reconciliation & Monitoring Module
-- Database: cspc_accounting   (MySQL / MariaDB, XAMPP-compatible)
-- Import with phpMyAdmin:  Import tab -> choose this file -> Go
-- Demo login:  maria.santos@cspc.edu.ph   /   Accounting@2026
-- All data is synthetic. "Today" in the demo is 18 February 2026.
-- ===========================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
CREATE DATABASE IF NOT EXISTS cspc_accounting CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cspc_accounting;

DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS reports;
DROP TABLE IF EXISTS discrepancies;
DROP TABLE IF EXISTS support_matches;
DROP TABLE IF EXISTS matches;
DROP TABLE IF EXISTS match_runs;
DROP TABLE IF EXISTS staged_records;
DROP TABLE IF EXISTS staged_imports;
DROP TABLE IF EXISTS records;
DROP TABLE IF EXISTS source_files;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(80) NOT NULL,
  email VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role_label VARCHAR(60) NOT NULL DEFAULT 'Authorized accountant',
  initials VARCHAR(4) NOT NULL,
  failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE settings (
  skey VARCHAR(40) PRIMARY KEY,
  svalue VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE source_files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source ENUM('bank','ledger','sias','voucher') NOT NULL,
  period CHAR(7) NOT NULL,
  file_name VARCHAR(120) NOT NULL,
  imported_at DATETIME NULL,
  imported_by INT UNSIGNED NULL,
  record_count INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('imported','pending') NOT NULL DEFAULT 'imported',
  KEY idx_sf_period (period, source),
  CONSTRAINT fk_sf_user FOREIGN KEY (imported_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- One row per financial record from any of the 4 sources.
--   ref_no    : the record's own number (BS-0213-0181, JV-2026-0175, OR-10231, DV-2026-0881)
--   cross_ref : shared reference used to pair bank <-> ledger (DEP-xxxx deposit slip / CHK-xxxxxx check)
--   support_ref: ledger entry's link to its source document (OR no. or DV no.)
CREATE TABLE records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source ENUM('bank','ledger','sias','voucher') NOT NULL,
  ref_no VARCHAR(30) NOT NULL,
  cross_ref VARCHAR(30) NULL,
  support_ref VARCHAR(30) NULL,
  txn_date DATE NOT NULL,
  period CHAR(7) NOT NULL,
  amount DECIMAL(14,2) NULL,
  payee VARCHAR(120) NULL,
  description VARCHAR(160) NULL,
  account_code VARCHAR(12) NULL,
  merge_status ENUM('merged','awaiting_file','missing_amount','possible_duplicate') NOT NULL DEFAULT 'merged',
  rec_status ENUM('matched','possible','unmatched','duplicate','pending') NOT NULL DEFAULT 'pending',
  file_id INT UNSIGNED NULL,
  UNIQUE KEY uq_record_ref (ref_no),
  KEY idx_rec_period (period, source),
  KEY idx_rec_cross (cross_ref),
  KEY idx_rec_support (support_ref),
  KEY idx_rec_status (rec_status),
  CONSTRAINT fk_rec_file FOREIGN KEY (file_id) REFERENCES source_files(id)
) ENGINE=InnoDB;

-- Validated uploads remain staged until an authorized user consolidates them.
CREATE TABLE staged_imports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source ENUM('bank','ledger','sias','voucher') NOT NULL,
  period CHAR(7) NOT NULL,
  file_name VARCHAR(120) NOT NULL,
  staged_by INT UNSIGNED NULL,
  staged_at DATETIME NOT NULL,
  status ENUM('staged','consolidated','discarded') NOT NULL DEFAULT 'staged',
  staged_count INT UNSIGNED NOT NULL DEFAULT 0,
  imported_count INT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_count INT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_staged_period (period, status),
  CONSTRAINT fk_staged_import_user FOREIGN KEY (staged_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE staged_records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staged_import_id INT UNSIGNED NOT NULL,
  `row_number` INT UNSIGNED NOT NULL,
  ref_no VARCHAR(30) NOT NULL,
  cross_ref VARCHAR(30) NULL,
  support_ref VARCHAR(30) NULL,
  txn_date DATE NOT NULL,
  period CHAR(7) NOT NULL,
  amount DECIMAL(14,2) NULL,
  payee VARCHAR(120) NULL,
  description VARCHAR(160) NULL,
  account_code VARCHAR(12) NULL,
  UNIQUE KEY uq_staged_file_row (staged_import_id, `row_number`),
  KEY idx_staged_record_ref (ref_no),
  CONSTRAINT fk_staged_record_import FOREIGN KEY (staged_import_id) REFERENCES staged_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE match_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period CHAR(7) NOT NULL,
  run_at DATETIME NOT NULL,
  run_by INT UNSIGNED NULL,
  total INT UNSIGNED NOT NULL DEFAULT 0,
  matched INT UNSIGNED NOT NULL DEFAULT 0,
  possible INT UNSIGNED NOT NULL DEFAULT 0,
  unmatched INT UNSIGNED NOT NULL DEFAULT 0,
  duplicates INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_run_user FOREIGN KEY (run_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- One row per bank<->ledger comparison (a_ok / r_ok / d_ok: 1 agrees, 0 differs, NULL no counterpart)
CREATE TABLE matches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period CHAR(7) NOT NULL,
  bank_id INT UNSIGNED NULL,
  ledger_id INT UNSIGNED NULL,
  amount DECIMAL(14,2) NULL,
  a_ok TINYINT NULL,
  r_ok TINYINT NULL,
  d_ok TINYINT NULL,
  date_diff SMALLINT NULL,
  result ENUM('matched','possible','unmatched','duplicate') NOT NULL,
  confirmed TINYINT(1) NOT NULL DEFAULT 0,
  confirmed_by INT UNSIGNED NULL,
  confirmed_at DATETIME NULL,
  note VARCHAR(200) NULL,
  KEY idx_m_period (period, result),
  CONSTRAINT fk_m_bank FOREIGN KEY (bank_id) REFERENCES records(id),
  CONSTRAINT fk_m_ledger FOREIGN KEY (ledger_id) REFERENCES records(id),
  CONSTRAINT fk_m_user FOREIGN KEY (confirmed_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- One supporting SIAS/voucher record per ledger entry; separate from bank-ledger matches.
CREATE TABLE support_matches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period CHAR(7) NOT NULL,
  ledger_id INT UNSIGNED NOT NULL,
  support_record_id INT UNSIGNED NULL,
  amount_ok TINYINT NULL,
  reference_ok TINYINT(1) NOT NULL DEFAULT 0,
  date_ok TINYINT NULL,
  date_diff SMALLINT NULL,
  result ENUM('matched','possible','unmatched','duplicate') NOT NULL,
  confirmed TINYINT(1) NOT NULL DEFAULT 0,
  confirmed_by INT UNSIGNED NULL,
  confirmed_at DATETIME NULL,
  note VARCHAR(200) NULL,
  UNIQUE KEY uq_support_match_ledger (ledger_id),
  KEY idx_support_match_period (period, result),
  KEY idx_support_match_record (support_record_id),
  CONSTRAINT fk_support_match_ledger FOREIGN KEY (ledger_id) REFERENCES records(id),
  CONSTRAINT fk_support_match_record FOREIGN KEY (support_record_id) REFERENCES records(id),
  CONSTRAINT fk_support_match_user FOREIGN KEY (confirmed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE discrepancies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ref_code VARCHAR(12) NOT NULL UNIQUE,
  period CHAR(7) NOT NULL,
  record_id INT UNSIGNED NOT NULL,
  counter_record_id INT UNSIGNED NULL,
  issue_type ENUM('no_match','duplicate','date_gap','wrong_code') NOT NULL,
  status ENUM('needs_review','pending','resolved','escalated') NOT NULL DEFAULT 'needs_review',
  flagged_on DATE NOT NULL,
  flagged_at DATETIME NOT NULL,
  assigned_to INT UNSIGNED NULL,
  variance DECIMAL(14,2) NOT NULL DEFAULT 0,
  diagnosis VARCHAR(255) NULL,
  suggested_action VARCHAR(160) NULL,
  resolved_by INT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  resolution_note VARCHAR(255) NULL,
  KEY idx_d_period (period, status),
  KEY idx_d_type (issue_type),
  CONSTRAINT fk_d_rec FOREIGN KEY (record_id) REFERENCES records(id),
  CONSTRAINT fk_d_counter FOREIGN KEY (counter_record_id) REFERENCES records(id),
  CONSTRAINT fk_d_assigned FOREIGN KEY (assigned_to) REFERENCES users(id),
  CONSTRAINT fk_d_resolver FOREIGN KEY (resolved_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_code VARCHAR(12) NOT NULL UNIQUE,
  report_type ENUM('reconciliation_summary','discrepancy_report','bank_ledger_variance','flagged_aging') NOT NULL,
  period CHAR(7) NOT NULL,
  generated_by INT UNSIGNED NULL,
  generated_at DATETIME NOT NULL,
  format ENUM('pdf','excel') NOT NULL,
  CONSTRAINT fk_r_user FOREIGN KEY (generated_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Append-only: triggers at the end of this file block UPDATE and DELETE.
CREATE TABLE audit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id VARCHAR(22) NOT NULL UNIQUE,
  occurred_at DATETIME NOT NULL,
  user_id INT UNSIGNED NULL,
  user_label VARCHAR(60) NOT NULL,
  action VARCHAR(80) NOT NULL,
  module VARCHAR(40) NOT NULL,
  reference VARCHAR(30) NULL,
  result ENUM('success','failed','flagged','resolved') NOT NULL,
  session_info VARCHAR(60) NULL,
  before_status VARCHAR(40) NULL,
  after_status VARCHAR(40) NULL,
  note VARCHAR(400) NULL,
  KEY idx_a_time (occurred_at),
  KEY idx_a_module (module),
  KEY idx_a_ref (reference),
  CONSTRAINT fk_a_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;


-- ----------------------------------------------------------- users
INSERT INTO users (id, full_name, email, password_hash, role_label, initials) VALUES
(1, 'Maria Santos', 'maria.santos@cspc.edu.ph', '$2y$10$JVFoWS7AXDokwN3qFv88oOKen5xY1m5eE77dlmkR8JNTPWEEgfEBu', 'Authorized accountant', 'MS'),
(2, 'Juan Dela Cruz', 'juan.delacruz@cspc.edu.ph', '$2y$10$JVFoWS7AXDokwN3qFv88oOKen5xY1m5eE77dlmkR8JNTPWEEgfEBu', 'Authorized accountant', 'JD'),
(3, 'Ana Reyes', 'ana.reyes@cspc.edu.ph', '$2y$10$JVFoWS7AXDokwN3qFv88oOKen5xY1m5eE77dlmkR8JNTPWEEgfEBu', 'Authorized accountant', 'AR');

-- --------------------------------------------------------- settings
INSERT INTO settings (skey, svalue) VALUES
('institution', 'Camarines Sur Polytechnic Colleges'),
('bank_name', 'Land Bank of the Philippines'),
('lock_threshold', '5'),
('lock_minutes', '15'),
('date_tolerance_days', '2'),
('overdue_days', '16');

SET FOREIGN_KEY_CHECKS = 1;

-- Audit entries are read-only: they cannot be edited or deleted.
DELIMITER $$
CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit entries are read-only.';
END$$
CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit entries cannot be deleted.';
END$$
DELIMITER ;

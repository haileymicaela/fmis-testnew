-- Track one SIAS or voucher support record per ledger entry.
CREATE TABLE IF NOT EXISTS support_matches (
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

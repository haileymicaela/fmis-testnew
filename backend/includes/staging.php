<?php

declare(strict_types=1);

function ensure_staging_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS staged_imports (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source ENUM("bank", "ledger", "sias", "voucher") NOT NULL,
            period CHAR(7) NOT NULL,
            file_name VARCHAR(120) NOT NULL,
            staged_by INT UNSIGNED NULL,
            staged_at DATETIME NOT NULL,
            status ENUM("staged", "consolidated", "discarded") NOT NULL DEFAULT "staged",
            staged_count INT UNSIGNED NOT NULL DEFAULT 0,
            imported_count INT UNSIGNED NOT NULL DEFAULT 0,
            duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
            rejected_count INT UNSIGNED NOT NULL DEFAULT 0,
            KEY idx_staged_period (period, status),
            CONSTRAINT fk_staged_import_user FOREIGN KEY (staged_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS staged_records (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            staged_import_id INT UNSIGNED NOT NULL,
            row_number INT UNSIGNED NOT NULL,
            ref_no VARCHAR(30) NOT NULL,
            cross_ref VARCHAR(30) NULL,
            support_ref VARCHAR(30) NULL,
            txn_date DATE NOT NULL,
            period CHAR(7) NOT NULL,
            amount DECIMAL(14,2) NULL,
            payee VARCHAR(120) NULL,
            description VARCHAR(160) NULL,
            account_code VARCHAR(12) NULL,
            UNIQUE KEY uq_staged_file_row (staged_import_id, row_number),
            KEY idx_staged_record_ref (ref_no),
            CONSTRAINT fk_staged_record_import FOREIGN KEY (staged_import_id) REFERENCES staged_imports(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );
}

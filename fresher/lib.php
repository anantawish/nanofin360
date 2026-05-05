<?php
declare(strict_types=1);

require_once __DIR__ . '/LoanCalculator.php';

function fresher_base_url(string $suffix = ''): string
{
    $base = app_base_url('fresher');
    if ($suffix === '') {
        return $base;
    }
    return $base . '/' . ltrim($suffix, '/');
}

function fresher_bootstrap(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    fresher_ensure_upload_dirs();
    fresher_ensure_tables();
    fresher_ensure_table_extensions();
    fresher_seed_branches_from_master();
}

function fresher_effective_role(): string
{
    $role = strtolower(trim(current_role_name()));
    if ($role === 'admin') {
        return 'admin';
    }
    if (in_array($role, ['director', 'executive'], true)) {
        return 'director';
    }
    return 'user';
}

function fresher_is_admin(): bool
{
    return fresher_effective_role() === 'admin';
}

function fresher_is_director(): bool
{
    return fresher_effective_role() === 'director';
}

function fresher_page_key_from_script(string $scriptName): string
{
    $script = strtolower(trim($scriptName));
    $map = [
        'index.php' => 'index',
        'executive_dashboard.php' => 'executive_dashboard',
        'admin.php' => 'admin',
        'customers.php' => 'customers',
        'affordability.php' => 'affordability',
        'hire_purchase.php' => 'hire_purchase',
        'installments.php' => 'installments',
        'collections.php' => 'collections',
        'repossessions.php' => 'repossessions',
        'legal_cases.php' => 'legal_cases',
        'documents.php' => 'documents',
        'receipt_print.php' => 'receipt_print',
    ];
    return $map[$script] ?? '';
}

function fresher_can_access_page(string $pageKey): bool
{
    $pageKey = trim($pageKey);
    if ($pageKey === '') {
        return true;
    }

    $role = fresher_effective_role();
    if ($role === 'admin') {
        return true;
    }

    if ($role === 'director') {
        return in_array($pageKey, ['index', 'executive_dashboard'], true);
    }

    // user
    if ($pageKey === 'admin') {
        return false;
    }
    return true;
}

function fresher_nav_can_see(string $pageKey): bool
{
    return fresher_can_access_page($pageKey);
}

function fresher_guard_page_access(?string $scriptName = null): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $scriptName = $scriptName ?? basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $pageKey = fresher_page_key_from_script($scriptName);
    if ($pageKey === '') {
        return;
    }

    if (fresher_can_access_page($pageKey)) {
        return;
    }

    add_flash('warning', 'คุณไม่มีสิทธิ์เข้าหน้านี้');
    if (fresher_is_director()) {
        redirect_to(fresher_base_url('executive_dashboard.php'));
    }
    redirect_to(fresher_base_url('index.php'));
}

function fresher_ensure_upload_dirs(): void
{
    $dirs = [
        __DIR__ . '/uploads',
        __DIR__ . '/uploads/images',
        __DIR__ . '/uploads/documents',
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}

function fresher_ensure_tables(): void
{
    $pdo = db();
    $queries = [
        'CREATE TABLE IF NOT EXISTS fresher_branches (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            branch_code VARCHAR(60) NOT NULL,
            branch_name VARCHAR(200) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT "",
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_fresher_branch_code (branch_code),
            KEY idx_fresher_branch_active (is_deleted, is_active, branch_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS fresher_products (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_code VARCHAR(60) NOT NULL,
            product_name VARCHAR(200) NOT NULL,
            model_name VARCHAR(200) NOT NULL,
            category_name VARCHAR(120) DEFAULT "",
            default_price DECIMAL(18,2) NOT NULL DEFAULT 0,
            sale_price DECIMAL(18,2) NOT NULL DEFAULT 0,
            stock_quantity INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT "",
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_fresher_product_code (product_code),
            KEY idx_fresher_product_active (is_deleted, is_active, product_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS fresher_collectors (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            collector_code VARCHAR(60) NOT NULL,
            collector_name VARCHAR(200) NOT NULL,
            phone_number VARCHAR(30) DEFAULT "",
            branch_code VARCHAR(60) DEFAULT "",
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT "",
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_fresher_collector_code (collector_code),
            KEY idx_fresher_collector_scope (is_deleted, is_active, branch_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS fresher_customers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_code VARCHAR(80) NOT NULL,
            first_name VARCHAR(120) NOT NULL,
            last_name VARCHAR(120) NOT NULL,
            phone_number VARCHAR(30) DEFAULT "",
            cid_tax_id VARCHAR(40) DEFAULT "",
            address_line VARCHAR(255) DEFAULT "",
            subdistrict VARCHAR(120) DEFAULT "",
            district VARCHAR(120) DEFAULT "",
            province VARCHAR(120) DEFAULT "",
            occupation VARCHAR(200) DEFAULT "",
            monthly_income DECIMAL(18,2) NOT NULL DEFAULT 0,
            family_dependents INT NOT NULL DEFAULT 0,
            attitude_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            branch_code VARCHAR(60) DEFAULT "",
            customer_photo_path VARCHAR(255) DEFAULT "",
            doc_id_card_path VARCHAR(255) DEFAULT "",
            doc_house_reg_path VARCHAR(255) DEFAULT "",
            doc_vehicle_ownership_path VARCHAR(255) DEFAULT "",
            doc_land_ownership_path VARCHAR(255) DEFAULT "",
            note_text TEXT NULL,
            customer_status VARCHAR(40) NOT NULL DEFAULT "ACTIVE",
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT "",
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_fresher_customer_code (customer_code),
            KEY idx_fresher_customer_scope (is_deleted, branch_code, customer_status),
            KEY idx_fresher_customer_name (first_name, last_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS fresher_affordability (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            assessment_code VARCHAR(80) NOT NULL,
            customer_code VARCHAR(80) NOT NULL,
            customer_name VARCHAR(255) DEFAULT "",
            branch_code VARCHAR(60) DEFAULT "",
            monthly_income DECIMAL(18,2) NOT NULL DEFAULT 0,
            occupation_expense DECIMAL(18,2) NOT NULL DEFAULT 0,
            family_expense DECIMAL(18,2) NOT NULL DEFAULT 0,
            existing_debt DECIMAL(18,2) NOT NULL DEFAULT 0,
            attitude_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            document_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            collateral_factor DECIMAL(8,4) NOT NULL DEFAULT 0.75,
            net_capacity DECIMAL(18,2) NOT NULL DEFAULT 0,
            recommended_installment DECIMAL(18,2) NOT NULL DEFAULT 0,
            recommended_limit DECIMAL(18,2) NOT NULL DEFAULT 0,
            result_status VARCHAR(40) NOT NULL DEFAULT "REVIEW",
            note_text TEXT NULL,
            assessment_date DATE NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT "",
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_fresher_assessment_code (assessment_code),
            KEY idx_fresher_assessment_scope (is_deleted, branch_code, customer_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS fresher_hire_purchase (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            contract_code VARCHAR(80) NOT NULL,
            customer_code VARCHAR(80) NOT NULL,
            customer_name VARCHAR(255) DEFAULT "",
            branch_code VARCHAR(60) DEFAULT "",
            product_code VARCHAR(60) DEFAULT "",
            product_name VARCHAR(255) DEFAULT "",
            model_name VARCHAR(255) DEFAULT "",
            serial_number VARCHAR(120) DEFAULT "",
            product_image_path VARCHAR(255) DEFAULT "",
            contract_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            down_payment DECIMAL(18,2) NOT NULL DEFAULT 0,
            financed_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            annual_interest_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
            installment_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            installment_count INT NOT NULL DEFAULT 0,
            start_date DATE NULL,
            due_day INT NOT NULL DEFAULT 1,
            contract_status VARCHAR(40) NOT NULL DEFAULT "ACTIVE",
            note_text TEXT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT "",
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_fresher_contract_code (contract_code),
            KEY idx_fresher_contract_scope (is_deleted, branch_code, customer_code, contract_status),
            KEY idx_fresher_contract_serial (serial_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS fresher_installments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            contract_code VARCHAR(80) NOT NULL,
            customer_code VARCHAR(80) NOT NULL,
            customer_name VARCHAR(255) DEFAULT "",
            branch_code VARCHAR(60) DEFAULT "",
            installment_no INT NOT NULL DEFAULT 1,
            due_date DATE NULL,
            installment_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            principal_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            interest_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            paid_date DATE NULL,
            payment_status VARCHAR(30) NOT NULL DEFAULT "UNPAID",
            receipt_no VARCHAR(120) DEFAULT "",
            note_text TEXT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT "",
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_fresher_installment (contract_code, installment_no),
            KEY idx_fresher_installment_scope (is_deleted, branch_code, contract_code, payment_status),
            KEY idx_fresher_installment_due (due_date, payment_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS fresher_collections (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            followup_code VARCHAR(80) NOT NULL,
            contract_code VARCHAR(80) NOT NULL,
            customer_code VARCHAR(80) DEFAULT "",
            customer_name VARCHAR(255) DEFAULT "",
            branch_code VARCHAR(60) DEFAULT "",
            dpd_days INT NOT NULL DEFAULT 0,
            followup_date DATE NULL,
            channel VARCHAR(40) DEFAULT "",
            outcome TEXT NULL,
            promise_date DATE NULL,
            promise_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            next_action_date DATE NULL,
            collector_code VARCHAR(80) DEFAULT "",
            collector_name VARCHAR(255) DEFAULT "",
            collection_status VARCHAR(40) NOT NULL DEFAULT "OPEN",
            note_text TEXT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT "",
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_fresher_followup_code (followup_code),
            KEY idx_fresher_collection_scope (is_deleted, branch_code, contract_code, collection_status),
            KEY idx_fresher_collection_next (next_action_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS fresher_repossessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            repo_code VARCHAR(80) NOT NULL,
            contract_code VARCHAR(80) NOT NULL,
            customer_code VARCHAR(80) DEFAULT "",
            customer_name VARCHAR(255) DEFAULT "",
            branch_code VARCHAR(60) DEFAULT "",
            repossession_date DATE NULL,
            asset_condition VARCHAR(200) DEFAULT "",
            storage_location VARCHAR(255) DEFAULT "",
            appraised_value DECIMAL(18,2) NOT NULL DEFAULT 0,
            sale_value DECIMAL(18,2) NOT NULL DEFAULT 0,
            repo_status VARCHAR(40) NOT NULL DEFAULT "PENDING",
            note_text TEXT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT "",
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_fresher_repo_code (repo_code),
            KEY idx_fresher_repo_scope (is_deleted, branch_code, contract_code, repo_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS fresher_legal_cases (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            case_code VARCHAR(80) NOT NULL,
            contract_code VARCHAR(80) NOT NULL,
            customer_code VARCHAR(80) DEFAULT "",
            customer_name VARCHAR(255) DEFAULT "",
            branch_code VARCHAR(60) DEFAULT "",
            filing_date DATE NULL,
            court_name VARCHAR(255) DEFAULT "",
            case_no VARCHAR(120) DEFAULT "",
            claim_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            paid_date DATE NULL,
            case_status VARCHAR(40) NOT NULL DEFAULT "OPEN",
            note_text TEXT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT "",
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_fresher_case_code (case_code),
            KEY idx_fresher_case_scope (is_deleted, branch_code, contract_code, case_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

        'CREATE TABLE IF NOT EXISTS fresher_documents (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            document_code VARCHAR(80) NOT NULL,
            contract_code VARCHAR(80) DEFAULT "",
            customer_code VARCHAR(80) DEFAULT "",
            customer_name VARCHAR(255) DEFAULT "",
            branch_code VARCHAR(60) DEFAULT "",
            document_type VARCHAR(120) DEFAULT "",
            file_name VARCHAR(255) DEFAULT "",
            file_path VARCHAR(255) DEFAULT "",
            note_text TEXT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT "",
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_fresher_document_code (document_code),
            KEY idx_fresher_doc_scope (is_deleted, branch_code, contract_code, customer_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ];

    foreach ($queries as $sql) {
        $pdo->exec($sql);
    }
}

function fresher_ensure_table_extensions(): void
{
    $pdo = db();

    $columns = [
        ['fresher_products', 'sale_price', 'DECIMAL(18,2) NOT NULL DEFAULT 0'],
        ['fresher_products', 'stock_quantity', 'INT NOT NULL DEFAULT 0'],

        ['fresher_customers', 'doc_id_card_path', 'VARCHAR(255) DEFAULT ""'],
        ['fresher_customers', 'doc_house_reg_path', 'VARCHAR(255) DEFAULT ""'],
        ['fresher_customers', 'doc_vehicle_ownership_path', 'VARCHAR(255) DEFAULT ""'],
        ['fresher_customers', 'doc_land_ownership_path', 'VARCHAR(255) DEFAULT ""'],

        ['fresher_affordability', 'document_score', 'DECIMAL(6,2) NOT NULL DEFAULT 0'],
        ['fresher_affordability', 'collateral_factor', 'DECIMAL(8,4) NOT NULL DEFAULT 0.75'],

        ['fresher_hire_purchase', 'flat_interest_rate', 'DECIMAL(8,4) NOT NULL DEFAULT 0'],
        ['fresher_hire_purchase', 'eir_interest_rate', 'DECIMAL(8,4) NOT NULL DEFAULT 0'],
        ['fresher_hire_purchase', 'calculation_method', 'VARCHAR(40) NOT NULL DEFAULT "FLAT_TO_EIR"'],

        ['fresher_collections', 'overdue_installments', 'INT NOT NULL DEFAULT 0'],
        ['fresher_collections', 'overdue_principal_amount', 'DECIMAL(18,2) NOT NULL DEFAULT 0'],
        ['fresher_collections', 'overdue_due_amount', 'DECIMAL(18,2) NOT NULL DEFAULT 0'],
        ['fresher_collections', 'requested_collection_fee', 'DECIMAL(18,2) NOT NULL DEFAULT 0'],
        ['fresher_collections', 'collection_fee_amount', 'DECIMAL(18,2) NOT NULL DEFAULT 0'],
        ['fresher_collections', 'collection_fee_note', 'VARCHAR(255) DEFAULT ""'],
        ['fresher_collections', 'late_penalty_rate', 'DECIMAL(8,4) NOT NULL DEFAULT 0'],
        ['fresher_collections', 'late_penalty_amount', 'DECIMAL(18,2) NOT NULL DEFAULT 0'],
        ['fresher_collections', 'contract_interest_rate', 'DECIMAL(8,4) NOT NULL DEFAULT 0'],

        ['fresher_receipts', 'payment_attachment_path', 'VARCHAR(255) DEFAULT ""'],
    ];

    foreach ($columns as [$table, $column, $definition]) {
        fresher_ensure_column((string)$table, (string)$column, (string)$definition);
    }

    // Backfill sale_price for old data created before this column existed.
    $pdo->exec(
        'UPDATE fresher_products
         SET sale_price = default_price
         WHERE sale_price = 0
           AND default_price > 0'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS fresher_hire_purchase_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            contract_code VARCHAR(80) NOT NULL,
            branch_code VARCHAR(60) DEFAULT "",
            product_code VARCHAR(60) NOT NULL,
            product_name VARCHAR(200) DEFAULT "",
            model_name VARCHAR(200) DEFAULT "",
            serial_number VARCHAR(120) DEFAULT "",
            quantity INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
            line_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT "",
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            KEY idx_fresher_hp_item_scope (is_deleted, contract_code, branch_code),
            KEY idx_fresher_hp_item_product (product_code, model_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS fresher_payoff_settlements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            settlement_code VARCHAR(80) NOT NULL,
            contract_code VARCHAR(80) NOT NULL,
            customer_code VARCHAR(80) DEFAULT "",
            customer_name VARCHAR(255) DEFAULT "",
            branch_code VARCHAR(60) DEFAULT "",
            quote_date DATE NULL,
            paid_ratio DECIMAL(8,4) NOT NULL DEFAULT 0,
            discount_tier VARCHAR(40) DEFAULT "",
            discount_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
            remaining_principal DECIMAL(18,2) NOT NULL DEFAULT 0,
            remaining_interest DECIMAL(18,2) NOT NULL DEFAULT 0,
            discount_interest DECIMAL(18,2) NOT NULL DEFAULT 0,
            payable_interest DECIMAL(18,2) NOT NULL DEFAULT 0,
            payoff_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            receipt_no VARCHAR(120) DEFAULT "",
            note_text TEXT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT "",
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_fresher_settlement_code (settlement_code),
            KEY idx_fresher_settlement_scope (is_deleted, branch_code, contract_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS fresher_receipts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            receipt_code VARCHAR(80) NOT NULL,
            contract_code VARCHAR(80) NOT NULL,
            customer_code VARCHAR(80) DEFAULT "",
            customer_name VARCHAR(255) DEFAULT "",
            branch_code VARCHAR(60) DEFAULT "",
            payment_date DATE NULL,
            payment_method VARCHAR(40) DEFAULT "",
            reference_no VARCHAR(120) DEFAULT "",
            payment_attachment_path VARCHAR(255) DEFAULT "",
            total_paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            total_principal_paid DECIMAL(18,2) NOT NULL DEFAULT 0,
            total_interest_paid DECIMAL(18,2) NOT NULL DEFAULT 0,
            total_late_penalty DECIMAL(18,2) NOT NULL DEFAULT 0,
            total_collection_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
            grand_total DECIMAL(18,2) NOT NULL DEFAULT 0,
            note_text TEXT NULL,
            receipt_status VARCHAR(30) NOT NULL DEFAULT "POSTED",
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT "",
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_fresher_receipt_code (receipt_code),
            KEY idx_fresher_receipt_scope (is_deleted, branch_code, contract_code, payment_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS fresher_receipt_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            receipt_code VARCHAR(80) NOT NULL,
            contract_code VARCHAR(80) NOT NULL,
            installment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            installment_no INT NOT NULL DEFAULT 0,
            due_date DATE NULL,
            installment_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            paid_before DECIMAL(18,2) NOT NULL DEFAULT 0,
            pay_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            paid_after DECIMAL(18,2) NOT NULL DEFAULT 0,
            principal_paid DECIMAL(18,2) NOT NULL DEFAULT 0,
            interest_paid DECIMAL(18,2) NOT NULL DEFAULT 0,
            payment_status_after VARCHAR(30) NOT NULL DEFAULT "PARTIAL",
            note_text TEXT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT "",
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            KEY idx_fresher_receipt_item_scope (is_deleted, receipt_code, contract_code),
            KEY idx_fresher_receipt_item_installment (installment_id, installment_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function fresher_column_exists(string $table, string $column): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

function fresher_table_exists(string $table): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name'
    );
    $stmt->execute([
        ':table_name' => $table,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

function fresher_ensure_column(string $table, string $column, string $definition): void
{
    if (!fresher_table_exists($table)) {
        return;
    }
    if (fresher_column_exists($table, $column)) {
        return;
    }
    db()->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
}

function fresher_seed_branches_from_master(): void
{
    $pdo = db();
    $count = (int)$pdo->query('SELECT COUNT(*) FROM fresher_branches WHERE is_deleted = 0')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $rows = $pdo->query(
        'SELECT branch_code, branch_name
         FROM master_branch
         WHERE is_latest = 1 AND is_deleted = 0
         ORDER BY branch_code'
    )->fetchAll();

    if ($rows === []) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO fresher_branches (
            branch_code, branch_name, is_active, is_deleted, created_by, created_at
         ) VALUES (
            :branch_code, :branch_name, 1, 0, :created_by, :created_at
         )'
    );

    $now = now_dt();
    $actor = current_user_name() !== '' ? current_user_name() : 'system_seed';
    foreach ($rows as $row) {
        $code = strtoupper(trim((string)($row['branch_code'] ?? '')));
        if ($code === '') {
            continue;
        }
        $stmt->execute([
            ':branch_code' => $code,
            ':branch_name' => trim((string)($row['branch_name'] ?? $code)),
            ':created_by' => $actor,
            ':created_at' => $now,
        ]);
    }
}

function fresher_scope_clause(string $column = 'branch_code', string $prefix = 'fr_scope'): array
{
    return access_scope_sql_clause($column, $prefix, current_access_scope());
}

function fresher_generate_code(string $prefix): string
{
    return strtoupper(trim($prefix)) . date('ymdHis') . random_int(100, 999);
}

function fresher_decimal($value): float
{
    if ($value === null || $value === '' || !is_numeric((string)$value)) {
        return 0.0;
    }
    return (float)$value;
}

function fresher_int($value): int
{
    if ($value === null || $value === '' || !is_numeric((string)$value)) {
        return 0;
    }
    return (int)$value;
}

function fresher_upload_file(string $fieldName, string $subDir, array $allowedExtensions, int $maxBytes = 10485760): ?array
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        return null;
    }

    $file = $_FILES[$fieldName];
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        throw new RuntimeException('ขนาดไฟล์ไม่ถูกต้องหรือเกินกำหนด');
    }

    $originalName = trim((string)($file['name'] ?? ''));
    $originalName = basename($originalName);
    $extension = strtolower(trim((string)pathinfo($originalName, PATHINFO_EXTENSION)));
    if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('ประเภทไฟล์ไม่รองรับ');
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('ไม่พบไฟล์ชั่วคราวสำหรับอัปโหลด');
    }

    $subDir = trim(str_replace('\\', '/', $subDir), '/');
    $targetDir = __DIR__ . '/uploads/' . $subDir;
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }

    $fileName = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    $targetPath = $targetDir . '/' . $fileName;
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        throw new RuntimeException('ย้ายไฟล์อัปโหลดไม่สำเร็จ');
    }

    return [
        'file_name' => $originalName,
        'file_path' => 'uploads/' . $subDir . '/' . $fileName,
    ];
}

function fresher_monthly_rate_from_effective_annual(float $annualRatePct): float
{
    $annualRate = max(0.0, $annualRatePct) / 100.0;
    if ($annualRate <= 0) {
        return 0.0;
    }
    return pow(1 + $annualRate, 1 / 12) - 1;
}

function fresher_calculate_installment_amount(float $financedAmount, float $annualRatePct, int $installmentCount): float
{
    if ($installmentCount <= 0 || $financedAmount <= 0) {
        return 0.0;
    }

    $monthlyRate = fresher_monthly_rate_from_effective_annual($annualRatePct);
    if ($monthlyRate <= 0.0) {
        return round($financedAmount / $installmentCount, 2);
    }

    $factor = ($monthlyRate * pow(1 + $monthlyRate, $installmentCount)) / (pow(1 + $monthlyRate, $installmentCount) - 1);
    return round($financedAmount * $factor, 2);
}

function fresher_calculate_loan_plan(
    float $financedAmount,
    float $flatRatePct,
    int $installmentCount,
    float $paymentOverride = 0.0
): array {
    $calculator = new LoanCalculator(
        $financedAmount,
        $flatRatePct,
        $installmentCount,
        $paymentOverride > 0 ? $paymentOverride : null
    );

    return [
        'installment_amount' => round($calculator->getPaymentPerMonth(), 2),
        'eir_interest_rate' => round($calculator->getEffectiveAnnualRatePct(), 4),
        'monthly_rate' => $calculator->getMonthlyRate(),
    ];
}

function fresher_generate_installment_rows(
    string $contractCode,
    string $customerCode,
    string $customerName,
    string $branchCode,
    string $startDate,
    int $installmentCount,
    float $installmentAmount,
    float $financedAmount,
    float $annualRatePct
): array {
    $rows = [];
    $balance = max(0.0, $financedAmount);
    $monthlyRate = fresher_monthly_rate_from_effective_annual($annualRatePct);
    $date = new DateTimeImmutable($startDate !== '' ? $startDate : date('Y-m-d'));

    for ($i = 1; $i <= $installmentCount; $i++) {
        $dueDate = $date->modify('+' . ($i - 1) . ' month')->format('Y-m-d');
        $interest = $monthlyRate > 0 ? round($balance * $monthlyRate, 2) : 0.0;
        $principal = round($installmentAmount - $interest, 2);

        if ($monthlyRate <= 0) {
            $principal = round($financedAmount / max(1, $installmentCount), 2);
            $interest = 0.0;
        }

        if ($principal < 0) {
            $principal = 0.0;
        }

        if ($i === $installmentCount) {
            $principal = round($balance, 2);
            $installmentAmount = round($principal + $interest, 2);
        }

        $balance = round(max(0.0, $balance - $principal), 2);

        $rows[] = [
            'contract_code' => $contractCode,
            'customer_code' => $customerCode,
            'customer_name' => $customerName,
            'branch_code' => $branchCode,
            'installment_no' => $i,
            'due_date' => $dueDate,
            'installment_amount' => $installmentAmount,
            'principal_amount' => $principal,
            'interest_amount' => $interest,
            'payment_status' => 'UNPAID',
        ];
    }

    return $rows;
}

function fresher_contract_snapshot(string $contractCode, ?string $asOfDate = null): array
{
    $contract = fresher_contract_row($contractCode);
    if (!$contract) {
        return [
            'contract' => null,
            'items' => [],
            'totals' => [],
        ];
    }

    $date = $asOfDate !== null && $asOfDate !== '' ? $asOfDate : date('Y-m-d');
    $stmt = db()->prepare(
        'SELECT *
         FROM fresher_installments
         WHERE contract_code = :contract_code
           AND is_deleted = 0
         ORDER BY installment_no'
    );
    $stmt->execute([':contract_code' => strtoupper(trim($contractCode))]);
    $rows = $stmt->fetchAll();

    $totals = [
        'total_principal' => 0.0,
        'total_interest' => 0.0,
        'total_installment' => 0.0,
        'paid_principal' => 0.0,
        'paid_interest' => 0.0,
        'paid_total' => 0.0,
        'remaining_principal' => 0.0,
        'remaining_interest' => 0.0,
        'overdue_installments' => 0,
        'overdue_principal' => 0.0,
        'overdue_due_amount' => 0.0,
        'late_penalty_amount' => 0.0,
    ];

    $enriched = [];
    $contractRate = fresher_decimal($contract['eir_interest_rate'] ?? $contract['annual_interest_rate'] ?? 0);
    $penaltyRate = max(0.0, $contractRate + 3.0);

    $asOf = new DateTimeImmutable($date);
    foreach ($rows as $row) {
        $installmentAmount = max(0, fresher_decimal($row['installment_amount'] ?? 0));
        $principalAmount = max(0, fresher_decimal($row['principal_amount'] ?? 0));
        $interestAmount = max(0, fresher_decimal($row['interest_amount'] ?? 0));
        $paidAmount = max(0, fresher_decimal($row['paid_amount'] ?? 0));
        $paidAmount = min($installmentAmount, $paidAmount);
        $paidRatio = $installmentAmount > 0 ? min(1, $paidAmount / $installmentAmount) : 0.0;

        $paidPrincipal = round($principalAmount * $paidRatio, 2);
        $paidInterest = round($interestAmount * $paidRatio, 2);
        $remainingPrincipal = max(0, round($principalAmount - $paidPrincipal, 2));
        $remainingInterest = max(0, round($interestAmount - $paidInterest, 2));

        $dueDateRaw = (string)($row['due_date'] ?? '');
        $isOverdue = false;
        $daysOverdue = 0;
        if ($dueDateRaw !== '') {
            $dueDate = new DateTimeImmutable($dueDateRaw);
            if ($dueDate < $asOf && $remainingPrincipal > 0.0) {
                $isOverdue = true;
                $daysOverdue = (int)$asOf->diff($dueDate)->format('%a');
            }
        }

        $latePenalty = 0.0;
        if ($isOverdue && $daysOverdue > 0 && $remainingPrincipal > 0.0) {
            $latePenalty = round($remainingPrincipal * ($penaltyRate / 100.0) * ($daysOverdue / 365.0), 2);
        }

        if ($isOverdue) {
            $totals['overdue_installments']++;
            $totals['overdue_principal'] += $remainingPrincipal;
            $totals['overdue_due_amount'] += max(0.0, $installmentAmount - $paidAmount);
        }
        $totals['late_penalty_amount'] += $latePenalty;

        $totals['total_principal'] += $principalAmount;
        $totals['total_interest'] += $interestAmount;
        $totals['total_installment'] += $installmentAmount;
        $totals['paid_principal'] += $paidPrincipal;
        $totals['paid_interest'] += $paidInterest;
        $totals['paid_total'] += $paidAmount;
        $totals['remaining_principal'] += $remainingPrincipal;
        $totals['remaining_interest'] += $remainingInterest;

        $enriched[] = $row + [
            'paid_ratio' => $paidRatio,
            'paid_principal' => $paidPrincipal,
            'paid_interest' => $paidInterest,
            'remaining_principal' => $remainingPrincipal,
            'remaining_interest' => $remainingInterest,
            'is_overdue' => $isOverdue ? 1 : 0,
            'days_overdue' => $daysOverdue,
            'late_penalty' => $latePenalty,
        ];
    }

    foreach ($totals as $k => $v) {
        if (is_float($v)) {
            $totals[$k] = round($v, 2);
        }
    }
    $totals['paid_ratio'] = $totals['total_principal'] > 0
        ? round($totals['paid_principal'] / $totals['total_principal'], 6)
        : 0.0;
    $totals['contract_interest_rate'] = round($contractRate, 4);
    $totals['late_penalty_rate'] = round($penaltyRate, 4);

    return [
        'contract' => $contract,
        'items' => $enriched,
        'totals' => $totals,
    ];
}

function fresher_early_payoff_quote(string $contractCode, ?string $quoteDate = null): array
{
    $snapshot = fresher_contract_snapshot($contractCode, $quoteDate);
    $contract = $snapshot['contract'];
    if (!$contract) {
        throw new RuntimeException('Contract not found.');
    }

    $totals = $snapshot['totals'];
    $paidRatio = (float)($totals['paid_ratio'] ?? 0.0);
    $remainingPrincipal = (float)($totals['remaining_principal'] ?? 0.0);
    $remainingInterest = (float)($totals['remaining_interest'] ?? 0.0);

    $discountRate = 0.60;
    $tier = 'TIER_1_LEQ_1_3';
    if ($paidRatio > (2 / 3)) {
        $discountRate = 1.00;
        $tier = 'TIER_3_GT_2_3';
    } elseif ($paidRatio > (1 / 3)) {
        $discountRate = 0.70;
        $tier = 'TIER_2_GT_1_3';
    }

    $discountInterest = round($remainingInterest * $discountRate, 2);
    $payableInterest = round(max(0.0, $remainingInterest - $discountInterest), 2);
    $payoffAmount = round($remainingPrincipal + $payableInterest, 2);

    return [
        'quote_date' => $quoteDate !== null && $quoteDate !== '' ? $quoteDate : date('Y-m-d'),
        'paid_ratio' => round($paidRatio, 6),
        'discount_tier' => $tier,
        'discount_rate' => round($discountRate, 4),
        'remaining_principal' => round($remainingPrincipal, 2),
        'remaining_interest' => round($remainingInterest, 2),
        'discount_interest' => $discountInterest,
        'payable_interest' => $payableInterest,
        'payoff_amount' => $payoffAmount,
    ];
}

function fresher_collection_fee_quote(string $contractCode, string $followupDate, int $excludeCollectionId = 0): array
{
    $snapshot = fresher_contract_snapshot($contractCode, $followupDate);
    if (!$snapshot['contract']) {
        throw new RuntimeException('Contract not found.');
    }

    $totals = $snapshot['totals'];
    $overdueInstallments = (int)($totals['overdue_installments'] ?? 0);
    $overdueDueAmount = max(0, fresher_decimal($totals['overdue_due_amount'] ?? 0));
    $recommendedFee = 0.0;
    $reason = 'No collection fee: account not eligible yet.';

    if ($overdueInstallments >= 1 && $overdueDueAmount >= 1000) {
        $recommendedFee = $overdueInstallments > 1 ? 100.0 : 50.0;
        $reason = 'Eligible by overdue rule.';

        $stmt = db()->prepare(
            'SELECT COUNT(*)
             FROM fresher_collections
             WHERE contract_code = :contract_code
               AND is_deleted = 0
               AND collection_fee_amount > 0
               AND followup_date IS NOT NULL
               AND YEAR(followup_date) = YEAR(:followup_date)
               AND MONTH(followup_date) = MONTH(:followup_date)
               AND (:exclude_id <= 0 OR id <> :exclude_id)'
        );
        $stmt->execute([
            ':contract_code' => strtoupper(trim($contractCode)),
            ':followup_date' => $followupDate,
            ':exclude_id' => $excludeCollectionId,
        ]);
        $alreadyCharged = (int)$stmt->fetchColumn() > 0;
        if ($alreadyCharged) {
            $recommendedFee = 0.0;
            $reason = 'Blocked: already charged in the same month.';
        }
    }

    return [
        'overdue_installments' => $overdueInstallments,
        'overdue_principal_amount' => round((float)($totals['overdue_principal'] ?? 0), 2),
        'overdue_due_amount' => round($overdueDueAmount, 2),
        'recommended_fee' => round($recommendedFee, 2),
        'reason' => $reason,
    ];
}

function fresher_late_penalty_quote(string $contractCode, string $asOfDate): array
{
    $snapshot = fresher_contract_snapshot($contractCode, $asOfDate);
    if (!$snapshot['contract']) {
        throw new RuntimeException('Contract not found.');
    }

    $totals = $snapshot['totals'];
    return [
        'contract_interest_rate' => round((float)($totals['contract_interest_rate'] ?? 0), 4),
        'late_penalty_rate' => round((float)($totals['late_penalty_rate'] ?? 0), 4),
        'late_penalty_amount' => round((float)($totals['late_penalty_amount'] ?? 0), 2),
    ];
}

function fresher_contract_dpd_days(string $contractCode): int
{
    $contractCode = trim($contractCode);
    if ($contractCode === '') {
        return 0;
    }

    $stmt = db()->prepare(
        'SELECT MIN(due_date)
         FROM fresher_installments
         WHERE contract_code = :contract_code
           AND is_deleted = 0
           AND payment_status IN ("UNPAID", "PARTIAL", "OVERDUE")
           AND due_date < CURDATE()'
    );
    $stmt->execute([':contract_code' => $contractCode]);
    $minDueDate = (string)$stmt->fetchColumn();
    if ($minDueDate === '') {
        return 0;
    }

    $today = new DateTimeImmutable('today');
    $dueDate = new DateTimeImmutable($minDueDate);
    $interval = $today->diff($dueDate);
    return max(0, (int)$interval->format('%r%a') * -1);
}

function fresher_refresh_contract_status(string $contractCode): void
{
    $contractCode = trim($contractCode);
    if ($contractCode === '') {
        return;
    }

    $stmt = db()->prepare(
        'SELECT
             SUM(CASE WHEN payment_status IN ("PAID", "WAIVED_EARLY") THEN 1 ELSE 0 END) AS paid_count,
             SUM(CASE WHEN payment_status NOT IN ("PAID", "WAIVED_EARLY") THEN 1 ELSE 0 END) AS unpaid_count,
             SUM(CASE WHEN payment_status NOT IN ("PAID", "WAIVED_EARLY") AND due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_count
         FROM fresher_installments
         WHERE contract_code = :contract_code
           AND is_deleted = 0'
    );
    $stmt->execute([':contract_code' => $contractCode]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }

    $unpaidCount = fresher_int($row['unpaid_count'] ?? 0);
    $overdueCount = fresher_int($row['overdue_count'] ?? 0);

    $status = 'ACTIVE';
    if ($unpaidCount <= 0) {
        $status = 'CLOSED';
    } elseif ($overdueCount > 0) {
        $status = 'DELINQUENT';
    }

    $stmtUpdate = db()->prepare(
        'UPDATE fresher_hire_purchase
         SET contract_status = :contract_status,
             updated_by = :updated_by,
             updated_at = :updated_at
         WHERE contract_code = :contract_code
           AND is_deleted = 0'
    );
    $stmtUpdate->execute([
        ':contract_status' => $status,
        ':updated_by' => current_user_name(),
        ':updated_at' => now_dt(),
        ':contract_code' => $contractCode,
    ]);
}

function fresher_branch_options(): array
{
    $scope = fresher_scope_clause('branch_code', 'fr_br');
    $sql = 'SELECT branch_code, branch_name
            FROM fresher_branches
            WHERE is_deleted = 0 AND is_active = 1' . $scope['sql'] . '
            ORDER BY branch_code';
    $stmt = db()->prepare($sql);
    $stmt->execute($scope['params']);
    return $stmt->fetchAll();
}

function fresher_product_options(): array
{
    $stmt = db()->query(
        'SELECT
             product_code,
             product_name,
             model_name,
             category_name,
             COALESCE(NULLIF(sale_price, 0), default_price, 0) AS sale_price,
             stock_quantity
         FROM fresher_products
         WHERE is_deleted = 0 AND is_active = 1
         ORDER BY product_name, model_name'
    );
    return $stmt->fetchAll();
}

function fresher_product_row(string $productCode, bool $activeOnly = false): ?array
{
    $productCode = strtoupper(trim($productCode));
    if ($productCode === '') {
        return null;
    }

    $sql = 'SELECT
                id,
                product_code,
                product_name,
                model_name,
                category_name,
                default_price,
                sale_price,
                stock_quantity,
                is_active
            FROM fresher_products
            WHERE product_code = :product_code
              AND is_deleted = 0';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute([':product_code' => $productCode]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fresher_adjust_product_stock(string $productCode, int $deltaQty, string $actor, string $at): void
{
    $productCode = strtoupper(trim($productCode));
    if ($productCode === '' || $deltaQty === 0) {
        return;
    }

    $stmt = db()->prepare(
        'SELECT id, stock_quantity, product_name, model_name
         FROM fresher_products
         WHERE product_code = :product_code
           AND is_deleted = 0
         LIMIT 1'
    );
    $stmt->execute([':product_code' => $productCode]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('ไม่พบสินค้า: ' . $productCode);
    }

    $currentQty = fresher_int($row['stock_quantity'] ?? 0);
    $nextQty = $currentQty + $deltaQty;
    if ($nextQty < 0) {
        $name = trim((string)($row['product_name'] ?? '') . ' ' . (string)($row['model_name'] ?? ''));
        throw new RuntimeException('สต็อกสินค้าไม่พอ: ' . trim($productCode . ' ' . $name));
    }

    $stmtUp = db()->prepare(
        'UPDATE fresher_products
         SET stock_quantity = :stock_quantity,
             updated_by = :updated_by,
             updated_at = :updated_at
         WHERE id = :id'
    );
    $stmtUp->execute([
        ':stock_quantity' => $nextQty,
        ':updated_by' => $actor,
        ':updated_at' => $at,
        ':id' => fresher_int($row['id'] ?? 0),
    ]);
}

function fresher_contract_items(string $contractCode): array
{
    $contractCode = strtoupper(trim($contractCode));
    if ($contractCode === '') {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT
             id,
             contract_code,
             branch_code,
             product_code,
             product_name,
             model_name,
             serial_number,
             quantity,
             unit_price,
             line_amount
         FROM fresher_hire_purchase_items
         WHERE contract_code = :contract_code
           AND is_deleted = 0
         ORDER BY id ASC'
    );
    $stmt->execute([':contract_code' => $contractCode]);
    return $stmt->fetchAll();
}

function fresher_collector_options(string $branchCode = ''): array
{
    $scope = fresher_scope_clause('branch_code', 'fr_col');
    $sql = 'SELECT collector_code, collector_name, branch_code
            FROM fresher_collectors
            WHERE is_deleted = 0 AND is_active = 1';
    $params = $scope['params'];
    if ($branchCode !== '') {
        $sql .= ' AND branch_code = :branch_code';
        $params[':branch_code'] = strtoupper(trim($branchCode));
    }
    $sql .= $scope['sql'] . ' ORDER BY collector_name';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fresher_customer_options(): array
{
    $scope = fresher_scope_clause('branch_code', 'fr_cus');
    $stmt = db()->prepare(
        'SELECT customer_code, first_name, last_name, branch_code, monthly_income, attitude_score,
                doc_id_card_path, doc_house_reg_path, doc_vehicle_ownership_path, doc_land_ownership_path
         FROM fresher_customers
         WHERE is_deleted = 0' . $scope['sql'] . '
         ORDER BY id DESC
         LIMIT 5000'
    );
    $stmt->execute($scope['params']);
    return $stmt->fetchAll();
}

function fresher_contract_options(): array
{
    $scope = fresher_scope_clause('branch_code', 'fr_ct');
    $stmt = db()->prepare(
        'SELECT contract_code, customer_code, customer_name, branch_code, contract_status
         FROM fresher_hire_purchase
         WHERE is_deleted = 0' . $scope['sql'] . '
         ORDER BY id DESC
         LIMIT 5000'
    );
    $stmt->execute($scope['params']);
    return $stmt->fetchAll();
}

function fresher_customer_row(string $customerCode): ?array
{
    $customerCode = strtoupper(trim($customerCode));
    if ($customerCode === '') {
        return null;
    }
    $scope = fresher_scope_clause('branch_code', 'fr_crow');
    $stmt = db()->prepare(
        'SELECT *
         FROM fresher_customers
         WHERE customer_code = :customer_code
           AND is_deleted = 0' . $scope['sql'] . '
         LIMIT 1'
    );
    $params = $scope['params'];
    $params[':customer_code'] = $customerCode;
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fresher_customer_document_metrics(array $customer): array
{
    $weights = [
        'doc_id_card_path' => ['label' => 'บัตรประชาชน', 'score' => 35.0],
        'doc_house_reg_path' => ['label' => 'ทะเบียนบ้าน', 'score' => 25.0],
        'doc_vehicle_ownership_path' => ['label' => 'เอกสารครอบครองรถยนต์', 'score' => 20.0],
        'doc_land_ownership_path' => ['label' => 'เอกสารครอบครองที่ดิน', 'score' => 20.0],
    ];

    $score = 0.0;
    $count = 0;
    $labels = [];
    foreach ($weights as $field => $meta) {
        $path = trim((string)($customer[$field] ?? ''));
        if ($path === '') {
            continue;
        }
        $count++;
        $score += (float)$meta['score'];
        $labels[] = (string)$meta['label'];
    }

    $score = round(max(0.0, min(100.0, $score)), 2);
    $factor = round(max(0.75, min(1.20, 0.75 + ($score / 220.0))), 4);

    return [
        'score' => $score,
        'factor' => $factor,
        'count' => $count,
        'max' => count($weights),
        'labels' => $labels,
    ];
}

function fresher_contract_row(string $contractCode): ?array
{
    $contractCode = strtoupper(trim($contractCode));
    if ($contractCode === '') {
        return null;
    }
    $scope = fresher_scope_clause('branch_code', 'fr_hrow');
    $stmt = db()->prepare(
        'SELECT *
         FROM fresher_hire_purchase
         WHERE contract_code = :contract_code
           AND is_deleted = 0' . $scope['sql'] . '
         LIMIT 1'
    );
    $params = $scope['params'];
    $params[':contract_code'] = $contractCode;
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fresher_normalize_date(string $dateValue, string $fallbackDate): string
{
    $dateValue = trim($dateValue);
    if ($dateValue === '') {
        return $fallbackDate;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue) !== 1) {
        return $fallbackDate;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $dateValue));
    if (!checkdate($m, $d, $y)) {
        return $fallbackDate;
    }
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

function fresher_unpaid_installments(string $contractCode): array
{
    $snapshot = fresher_contract_snapshot($contractCode, date('Y-m-d'));
    if (!$snapshot['contract']) {
        return [];
    }

    $rows = [];
    foreach ($snapshot['items'] as $row) {
        $status = strtoupper((string)($row['payment_status'] ?? 'UNPAID'));
        if (in_array($status, ['PAID', 'WAIVED_EARLY'], true)) {
            continue;
        }
        $remainingDue = round(max(0, fresher_decimal($row['installment_amount'] ?? 0) - fresher_decimal($row['paid_amount'] ?? 0)), 2);
        if ($remainingDue <= 0) {
            continue;
        }
        $row['remaining_due'] = $remainingDue;
        $rows[] = $row;
    }

    return $rows;
}

function fresher_process_payment_receipt(
    string $contractCode,
    string $paymentDate,
    string $paymentMethod,
    string $referenceNo,
    string $noteText,
    array $paymentByInstallmentId,
    float $latePenalty = 0.0,
    float $collectionFee = 0.0,
    string $paymentAttachmentPath = ''
): string {
    $contractCode = strtoupper(trim($contractCode));
    if ($contractCode === '') {
        throw new RuntimeException('กรุณาระบุรหัสสัญญา');
    }

    $contract = fresher_contract_row($contractCode);
    if (!$contract) {
        throw new RuntimeException('ไม่พบข้อมูลสัญญา');
    }
    $branchCode = strtoupper(trim((string)($contract['branch_code'] ?? '')));
    assert_branch_in_current_scope($branchCode);

    $paymentDate = fresher_normalize_date($paymentDate, date('Y-m-d'));
    $paymentMethod = strtoupper(trim($paymentMethod));
    if ($paymentMethod === '') {
        $paymentMethod = 'CASH';
    }
    $allowedMethods = ['CASH', 'TRANSFER', 'PROMPTPAY', 'CARD', 'OTHER'];
    if (!in_array($paymentMethod, $allowedMethods, true)) {
        $paymentMethod = 'OTHER';
    }

    $referenceNo = trim($referenceNo);
    $noteText = trim($noteText);
    $paymentAttachmentPath = trim($paymentAttachmentPath);
    $latePenalty = round(max(0, $latePenalty), 2);
    $collectionFee = round(max(0, $collectionFee), 2);

    $normalizedPayments = [];
    foreach ($paymentByInstallmentId as $id => $value) {
        $installmentId = (int)$id;
        $amount = round(max(0, fresher_decimal($value)), 2);
        if ($installmentId > 0 && $amount > 0) {
            $normalizedPayments[$installmentId] = $amount;
        }
    }
    if ($normalizedPayments === [] && ($latePenalty + $collectionFee) <= 0) {
        throw new RuntimeException('กรุณาระบุยอดรับชำระอย่างน้อย 1 รายการ');
    }

    $actor = current_user_name();
    if ($actor === '') {
        $actor = 'system';
    }
    $now = now_dt();

    $receiptCode = fresher_generate_code('FRR');
    $totalPaidAmount = 0.0;
    $totalPrincipalPaid = 0.0;
    $totalInterestPaid = 0.0;
    $itemRows = [];

    db()->beginTransaction();
    try {
        if ($normalizedPayments !== []) {
            $paramsFind = [':contract_code' => $contractCode];
            $placeholders = [];
            $idx = 0;
            foreach (array_keys($normalizedPayments) as $installmentId) {
                $key = ':iid' . $idx++;
                $placeholders[] = $key;
                $paramsFind[$key] = $installmentId;
            }

            $stmtFind = db()->prepare(
                'SELECT *
                 FROM fresher_installments
                 WHERE contract_code = :contract_code
                   AND is_deleted = 0
                   AND id IN (' . implode(', ', $placeholders) . ')
                 FOR UPDATE'
            );
            $stmtFind->execute($paramsFind);
            $installmentRows = $stmtFind->fetchAll();

            $installmentMap = [];
            foreach ($installmentRows as $row) {
                $installmentMap[(int)$row['id']] = $row;
            }

            foreach ($normalizedPayments as $installmentId => $requestedPayAmount) {
                if (!isset($installmentMap[$installmentId])) {
                    throw new RuntimeException('ไม่พบงวดที่เลือกบางรายการ');
                }
                $row = $installmentMap[$installmentId];

                $rowBranchCode = strtoupper(trim((string)($row['branch_code'] ?? '')));
                assert_branch_in_current_scope($rowBranchCode);

                $installmentAmount = round(max(0, fresher_decimal($row['installment_amount'] ?? 0)), 2);
                $paidBefore = round(min($installmentAmount, max(0, fresher_decimal($row['paid_amount'] ?? 0))), 2);
                $remainingDue = round(max(0, $installmentAmount - $paidBefore), 2);
                if ($remainingDue <= 0) {
                    continue;
                }

                $payAmount = round(min($requestedPayAmount, $remainingDue), 2);
                if ($payAmount <= 0) {
                    continue;
                }

                $paidAfter = round(min($installmentAmount, $paidBefore + $payAmount), 2);
                $paymentStatusAfter = $paidAfter >= ($installmentAmount - 0.0001) ? 'PAID' : 'PARTIAL';

                $principalAmount = round(max(0, fresher_decimal($row['principal_amount'] ?? 0)), 2);
                $interestAmount = round(max(0, fresher_decimal($row['interest_amount'] ?? 0)), 2);
                $paidRatioBefore = $installmentAmount > 0 ? min(1, $paidBefore / $installmentAmount) : 0.0;
                $principalPaidBefore = round($principalAmount * $paidRatioBefore, 2);
                $interestPaidBefore = round($interestAmount * $paidRatioBefore, 2);
                $remainingPrincipal = round(max(0, $principalAmount - $principalPaidBefore), 2);
                $remainingInterest = round(max(0, $interestAmount - $interestPaidBefore), 2);
                $remainingAmountForSplit = round($remainingPrincipal + $remainingInterest, 2);

                $principalPaid = 0.0;
                $interestPaid = 0.0;
                if ($remainingAmountForSplit > 0) {
                    $principalPaid = round($payAmount * ($remainingPrincipal / $remainingAmountForSplit), 2);
                    if ($principalPaid > $remainingPrincipal) {
                        $principalPaid = $remainingPrincipal;
                    }
                    $interestPaid = round($payAmount - $principalPaid, 2);
                    if ($interestPaid > $remainingInterest) {
                        $overflow = round($interestPaid - $remainingInterest, 2);
                        $interestPaid = $remainingInterest;
                        $principalPaid = min($remainingPrincipal, round($principalPaid + $overflow, 2));
                    }
                    $splitDelta = round($payAmount - ($principalPaid + $interestPaid), 2);
                    if (abs($splitDelta) >= 0.01) {
                        $adjustPrincipal = round($principalPaid + $splitDelta, 2);
                        if ($adjustPrincipal >= 0 && $adjustPrincipal <= $remainingPrincipal) {
                            $principalPaid = $adjustPrincipal;
                        } else {
                            $interestPaid = round(max(0, $interestPaid + $splitDelta), 2);
                        }
                    }
                }

                $currentNote = trim((string)($row['note_text'] ?? ''));
                $receiptMarker = 'RCPT:' . $receiptCode;
                if ($currentNote === '') {
                    $newNote = $receiptMarker;
                } elseif (strpos($currentNote, $receiptMarker) === false) {
                    $newNote = $currentNote . ' | ' . $receiptMarker;
                } else {
                    $newNote = $currentNote;
                }

                $stmtUpdateInstallment = db()->prepare(
                    'UPDATE fresher_installments
                     SET paid_amount = :paid_amount,
                         paid_date = :paid_date,
                         payment_status = :payment_status,
                         receipt_no = :receipt_no,
                         note_text = :note_text,
                         updated_by = :updated_by,
                         updated_at = :updated_at
                     WHERE id = :id
                       AND is_deleted = 0'
                );
                $stmtUpdateInstallment->execute([
                    ':paid_amount' => $paidAfter,
                    ':paid_date' => $paymentDate,
                    ':payment_status' => $paymentStatusAfter,
                    ':receipt_no' => $receiptCode,
                    ':note_text' => $newNote,
                    ':updated_by' => $actor,
                    ':updated_at' => $now,
                    ':id' => $installmentId,
                ]);

                $totalPaidAmount = round($totalPaidAmount + $payAmount, 2);
                $totalPrincipalPaid = round($totalPrincipalPaid + $principalPaid, 2);
                $totalInterestPaid = round($totalInterestPaid + $interestPaid, 2);

                $itemRows[] = [
                    'installment_id' => $installmentId,
                    'installment_no' => (int)($row['installment_no'] ?? 0),
                    'due_date' => (string)($row['due_date'] ?? ''),
                    'installment_amount' => $installmentAmount,
                    'paid_before' => $paidBefore,
                    'pay_amount' => $payAmount,
                    'paid_after' => $paidAfter,
                    'principal_paid' => $principalPaid,
                    'interest_paid' => $interestPaid,
                    'payment_status_after' => $paymentStatusAfter,
                ];
            }
        }

        if ($totalPaidAmount <= 0 && ($latePenalty + $collectionFee) <= 0) {
            throw new RuntimeException('ยอดรับชำระสุทธิเป็นศูนย์');
        }

        $grandTotal = round($totalPaidAmount + $latePenalty + $collectionFee, 2);

        $stmtInsertReceipt = db()->prepare(
            'INSERT INTO fresher_receipts (
                receipt_code, contract_code, customer_code, customer_name, branch_code,
                payment_date, payment_method, reference_no, payment_attachment_path,
                total_paid_amount, total_principal_paid, total_interest_paid,
                total_late_penalty, total_collection_fee, grand_total,
                note_text, receipt_status,
                is_deleted, created_by, created_at
            ) VALUES (
                :receipt_code, :contract_code, :customer_code, :customer_name, :branch_code,
                :payment_date, :payment_method, :reference_no, :payment_attachment_path,
                :total_paid_amount, :total_principal_paid, :total_interest_paid,
                :total_late_penalty, :total_collection_fee, :grand_total,
                :note_text, :receipt_status,
                0, :created_by, :created_at
            )'
        );
        $stmtInsertReceipt->execute([
            ':receipt_code' => $receiptCode,
            ':contract_code' => $contractCode,
            ':customer_code' => (string)($contract['customer_code'] ?? ''),
            ':customer_name' => (string)($contract['customer_name'] ?? ''),
            ':branch_code' => $branchCode,
            ':payment_date' => $paymentDate,
            ':payment_method' => $paymentMethod,
            ':reference_no' => $referenceNo,
            ':payment_attachment_path' => $paymentAttachmentPath,
            ':total_paid_amount' => $totalPaidAmount,
            ':total_principal_paid' => $totalPrincipalPaid,
            ':total_interest_paid' => $totalInterestPaid,
            ':total_late_penalty' => $latePenalty,
            ':total_collection_fee' => $collectionFee,
            ':grand_total' => $grandTotal,
            ':note_text' => $noteText,
            ':receipt_status' => 'POSTED',
            ':created_by' => $actor,
            ':created_at' => $now,
        ]);

        if ($itemRows !== []) {
            $stmtInsertItem = db()->prepare(
                'INSERT INTO fresher_receipt_items (
                    receipt_code, contract_code,
                    installment_id, installment_no, due_date, installment_amount,
                    paid_before, pay_amount, paid_after,
                    principal_paid, interest_paid,
                    payment_status_after, note_text,
                    is_deleted, created_by, created_at
                ) VALUES (
                    :receipt_code, :contract_code,
                    :installment_id, :installment_no, :due_date, :installment_amount,
                    :paid_before, :pay_amount, :paid_after,
                    :principal_paid, :interest_paid,
                    :payment_status_after, :note_text,
                    0, :created_by, :created_at
                )'
            );

            foreach ($itemRows as $item) {
                $stmtInsertItem->execute([
                    ':receipt_code' => $receiptCode,
                    ':contract_code' => $contractCode,
                    ':installment_id' => (int)$item['installment_id'],
                    ':installment_no' => (int)$item['installment_no'],
                    ':due_date' => $item['due_date'] !== '' ? $item['due_date'] : null,
                    ':installment_amount' => (float)$item['installment_amount'],
                    ':paid_before' => (float)$item['paid_before'],
                    ':pay_amount' => (float)$item['pay_amount'],
                    ':paid_after' => (float)$item['paid_after'],
                    ':principal_paid' => (float)$item['principal_paid'],
                    ':interest_paid' => (float)$item['interest_paid'],
                    ':payment_status_after' => (string)$item['payment_status_after'],
                    ':note_text' => '',
                    ':created_by' => $actor,
                    ':created_at' => $now,
                ]);
            }
        }

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }

    fresher_refresh_contract_status($contractCode);

    return $receiptCode;
}

function fresher_receipt_row(string $receiptCode): ?array
{
    $receiptCode = strtoupper(trim($receiptCode));
    if ($receiptCode === '') {
        return null;
    }

    $scope = fresher_scope_clause('branch_code', 'fr_receipt_row');
    $stmt = db()->prepare(
        'SELECT *
         FROM fresher_receipts
         WHERE receipt_code = :receipt_code
           AND is_deleted = 0' . $scope['sql'] . '
         LIMIT 1'
    );
    $params = $scope['params'];
    $params[':receipt_code'] = $receiptCode;
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fresher_receipt_items(string $receiptCode): array
{
    $receiptCode = strtoupper(trim($receiptCode));
    if ($receiptCode === '') {
        return [];
    }

    $receipt = fresher_receipt_row($receiptCode);
    if (!$receipt) {
        return [];
    }

    $stmt = db()->prepare(
        'SELECT *
         FROM fresher_receipt_items
         WHERE receipt_code = :receipt_code
           AND is_deleted = 0
         ORDER BY installment_no, id'
    );
    $stmt->execute([':receipt_code' => $receiptCode]);
    return $stmt->fetchAll();
}

function fresher_receipt_recent(string $contractCode = '', int $limit = 100): array
{
    $scope = fresher_scope_clause('branch_code', 'fr_receipt_recent');
    $contractCode = strtoupper(trim($contractCode));
    $limit = max(1, min(500, $limit));

    $sql = 'SELECT *
            FROM fresher_receipts
            WHERE is_deleted = 0';
    $params = [];
    if ($contractCode !== '') {
        $sql .= ' AND contract_code = :contract_code';
        $params[':contract_code'] = $contractCode;
    }
    $sql .= $scope['sql'] . '
            ORDER BY id DESC
            LIMIT ' . (int)$limit;
    foreach ($scope['params'] as $k => $v) {
        $params[$k] = $v;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fresher_occupation_options(): array
{
    $stmt = db()->query(
        'SELECT occupation_name
         FROM master_occupation
         WHERE is_latest = 1 AND is_deleted = 0
         ORDER BY occupation_name
         LIMIT 500'
    );
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $name = trim((string)($row['occupation_name'] ?? ''));
        if ($name !== '') {
            $items[$name] = true;
        }
    }
    $list = array_keys($items);
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
    return $list;
}

<?php
declare(strict_types=1);

/**
 * @param mixed $value
 */
function hp_float($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    if (!is_numeric((string)$value)) {
        return 0.0;
    }

    return (float)$value;
}

/**
 * @param mixed $value
 */
function hp_int($value): int
{
    if ($value === null || $value === '') {
        return 0;
    }
    if (!is_numeric((string)$value)) {
        return 0;
    }

    return (int)$value;
}

/**
 * @param mixed $json
 * @return array<string, mixed>
 */
function hp_decode_json_assoc($json): array
{
    if (is_array($json)) {
        return $json;
    }
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param mixed $value
 */
function hp_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric((string)$value)) {
        return (float)$value > 0;
    }
    $text = strtolower(trim((string)$value));
    return in_array($text, ['1', 'true', 'yes', 'y', 'on'], true);
}

function hp_generate_code(string $prefix): string
{
    return strtoupper(trim($prefix)) . date('ymdHis') . str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
}

function hp_parse_date(string $value, ?string $default = null): string
{
    $value = trim($value);
    if ($value === '') {
        $value = $default ?? date('Y-m-d');
    }
    $ts = strtotime($value);
    if ($ts === false) {
        $ts = strtotime($default ?? date('Y-m-d'));
    }

    return date('Y-m-d', $ts);
}

function hp_month_add(string $dateYmd, int $months): string
{
    $base = new DateTimeImmutable($dateYmd);
    return $base->modify('+' . $months . ' month')->format('Y-m-d');
}

/**
 * @return array{
 *   installment_amount:float,
 *   total_interest:float,
 *   payment_history:array<int, array<string,mixed>>
 * }
 */
function hp_build_schedule(float $principal, float $annualRatePct, int $termMonths, string $firstDueDate): array
{
    $principal = round(max(0.0, $principal), 2);
    $annualRatePct = round(max(0.0, $annualRatePct), 6);
    $termMonths = max(1, $termMonths);
    $firstDueDate = hp_parse_date($firstDueDate, date('Y-m-d'));

    $monthlyRate = $annualRatePct / 1200.0;
    if ($monthlyRate > 0.0) {
        $rawInstallment = $principal * $monthlyRate / (1.0 - pow(1.0 + $monthlyRate, -$termMonths));
    } else {
        $rawInstallment = $principal / $termMonths;
    }
    $installmentAmount = round($rawInstallment, 2);

    $rows = [];
    $balance = $principal;
    $totalInterest = 0.0;

    for ($i = 1; $i <= $termMonths; $i++) {
        $dueDate = hp_month_add($firstDueDate, $i - 1);
        $interest = $monthlyRate > 0 ? round($balance * $monthlyRate, 2) : 0.0;
        $principalPart = round($installmentAmount - $interest, 2);

        if ($i === $termMonths || $principalPart > $balance) {
            $principalPart = round($balance, 2);
            $installmentForRow = round($principalPart + $interest, 2);
        } else {
            $installmentForRow = $installmentAmount;
        }

        $balance = round(max(0.0, $balance - $principalPart), 2);
        $totalInterest = round($totalInterest + $interest, 2);

        $rows[] = [
            'installment_no' => $i,
            'due_date' => $dueDate,
            'installment_due' => $installmentForRow,
            'principal' => $principalPart,
            'interest' => $interest,
            'paid_amount' => 0.0,
            'paid_date' => null,
            'payment_status' => 'UNPAID',
            'receipt_no' => '',
            'payment_channel' => '',
            'payment_ref' => '',
            'payment_note' => '',
            'payment_attachment' => '',
            'late_penalty' => 0.0,
            'collection_fee' => 0.0,
            'days_overdue' => 0,
        ];
    }

    return [
        'installment_amount' => $installmentAmount,
        'total_interest' => $totalInterest,
        'payment_history' => $rows,
    ];
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @return array<int, array<string,mixed>>
 */
function hp_fetch_scoring_candidates(
    array $scope,
    string $searchText = '',
    string $branchCode = '',
    bool $requireScoringApproval = false
): array
{
    // 1) ฐานผู้ผ่าน: ใช้คำขอ customer_360 ที่อนุมัติแล้ว (ผ่านการพิจารณาใน Module 2)
    $scopeCustomer = access_scope_sql_clause('c.branch_code', 'hp_scope_cus', $scope);
    $sqlCustomer = '
        SELECT
            c.id,
            c.record_uid,
            c.primary_ref AS customer_code,
            c.primary_name,
            c.branch_code,
            c.data_json,
            c.amount
        FROM workflow_records c
        WHERE c.module_key = "customer_360"
          AND c.is_latest = 1
          AND c.is_deleted = 0
          AND c.record_status = "APPROVED"
          ' . $scopeCustomer['sql'] . '
        ORDER BY c.id DESC
        LIMIT 1200
    ';
    $stmtCustomer = db()->prepare($sqlCustomer);
    $stmtCustomer->execute($scopeCustomer['params']);
    $customerRows = $stmtCustomer->fetchAll() ?: [];

    if ($customerRows === []) {
        return [];
    }

    // 2) ข้อมูลเสริมจาก credit_scoring (ถ้ามี) เพื่อดึงวงเงิน/งวด/ดอกเบี้ย/policy
    $scopeScoring = access_scope_sql_clause('s.branch_code', 'hp_scope_scr', $scope);
    $sqlScoring = '
        SELECT
            s.id,
            s.record_uid,
            s.record_status,
            s.primary_ref,
            s.primary_name,
            s.branch_code,
            s.data_json
        FROM workflow_records s
        WHERE s.module_key = "credit_scoring"
          AND s.is_latest = 1
          AND s.is_deleted = 0
          ' . $scopeScoring['sql'] . '
        ORDER BY s.id DESC
        LIMIT 1200
    ';
    $stmtScoring = db()->prepare($sqlScoring);
    $stmtScoring->execute($scopeScoring['params']);
    $scoringRows = $stmtScoring->fetchAll() ?: [];

    $policyMetaById = [];
    $stmtPolicy = db()->prepare(
        'SELECT id, primary_ref, primary_name, data_json
         FROM workflow_records
         WHERE id = :id
           AND module_key = "credit_policy"
           AND is_latest = 1
           AND is_deleted = 0
         LIMIT 1'
    );

    $scoringByCustomer = [];
    foreach ($scoringRows as $row) {
        $payload = hp_decode_json_assoc((string)($row['data_json'] ?? ''));
        $scoreComponents = hp_decode_json_assoc((string)($payload['score_components'] ?? ''));
        $policyId = max(0, hp_int($payload['policy_id'] ?? $scoreComponents['policy_id'] ?? 0));
        $policyMeta = ['code' => '', 'name' => '', 'payload' => []];
        if ($policyId > 0) {
            if (!array_key_exists($policyId, $policyMetaById)) {
                $stmtPolicy->execute([':id' => $policyId]);
                $policyRow = $stmtPolicy->fetch();
                if ($policyRow) {
                    $policyMetaById[$policyId] = [
                        'code' => trim((string)($policyRow['primary_ref'] ?? '')),
                        'name' => trim((string)($policyRow['primary_name'] ?? '')),
                        'payload' => hp_decode_json_assoc((string)($policyRow['data_json'] ?? '')),
                    ];
                } else {
                    $policyMetaById[$policyId] = ['code' => '', 'name' => '', 'payload' => []];
                }
            }
            $policyMeta = $policyMetaById[$policyId];
        }
        $policyPayload = is_array($policyMeta['payload'] ?? null) ? $policyMeta['payload'] : [];

        $policyPass = hp_bool($payload['policy_pass'] ?? ($scoreComponents['policy_pass'] ?? false));
        $decision = strtoupper(trim((string)($payload['decision'] ?? ($scoreComponents['decision'] ?? ''))));
        $status = strtoupper(trim((string)($row['record_status'] ?? '')));

        if (!$policyPass && $decision !== 'APPROVE' && $status !== 'APPROVED') {
            continue;
        }

        $customerCode = strtoupper(trim((string)(
            $payload['source_customer_code']
            ?? $scoreComponents['source_customer_code']
            ?? $payload['customer_ref']
            ?? $row['primary_name']
            ?? ''
        )));
        if ($customerCode === '') {
            continue;
        }

        // เก็บเฉพาะรายการล่าสุดต่อ 1 ลูกค้า
        if (isset($scoringByCustomer[$customerCode])) {
            continue;
        }

        $scoringByCustomer[$customerCode] = [
            'id' => (int)$row['id'],
            'record_uid' => (string)$row['record_uid'],
            'record_status' => (string)$row['record_status'],
            'application_no' => trim((string)($payload['application_no'] ?? $scoreComponents['application_no'] ?? $row['primary_ref'] ?? '')),
            'policy_code' => trim((string)($payload['policy_code'] ?? $scoreComponents['policy_code'] ?? $policyMeta['code'] ?? '')),
            'policy_name' => trim((string)($payload['policy_name'] ?? $scoreComponents['policy_name'] ?? $policyMeta['name'] ?? '')),
            'recommended_loan_amount' => round(max(0.0, hp_float(
                $payload['recommended_loan_amount']
                ?? $scoreComponents['recommended_loan_amount']
                ?? $payload['loan_limit_by_capacity']
                ?? $scoreComponents['loan_limit_by_capacity']
                ?? $policyPayload['recommended_loan_amount']
                ?? $payload['requested_loan_amount']
                ?? 0
            )), 2),
            'recommended_installment' => round(max(0.0, hp_float(
                $payload['recommended_installment']
                ?? $scoreComponents['recommended_installment']
                ?? $policyPayload['recommended_installment']
                ?? 0
            )), 2),
            'annual_rate_pct' => round(max(0.0, hp_float(
                $payload['annual_rate_pct']
                ?? $scoreComponents['annual_rate_pct']
                ?? $scoreComponents['policy_interest_rate_pct']
                ?? $policyPayload['policy_interest_rate_pct']
                ?? 12.0
            )), 4),
            'term_months' => max(1, hp_int(
                $payload['term_months']
                ?? $scoreComponents['term_months']
                ?? $scoreComponents['policy_max_tenor_month']
                ?? $scoreComponents['max_tenor_month']
                ?? $policyPayload['max_tenor_month']
                ?? 24
            )),
            'payload' => $payload,
            'score_components' => $scoreComponents,
            'policy_payload' => $policyPayload,
        ];
    }

    $normalized = [];
    $seenCustomer = [];
    foreach ($customerRows as $row) {
        $customerCode = strtoupper(trim((string)($row['customer_code'] ?? '')));
        if ($customerCode === '' || isset($seenCustomer[$customerCode])) {
            continue;
        }
        $seenCustomer[$customerCode] = true;

        $customerPayload = hp_decode_json_assoc((string)($row['data_json'] ?? ''));
        $scoring = $scoringByCustomer[$customerCode] ?? null;

        $customerName = trim((string)(
            $customerPayload['customer_name']
            ?? $row['primary_name']
            ?? ''
        ));
        if ($customerName === '' && is_array($scoring)) {
            $customerName = trim((string)($scoring['payload']['source_customer_name'] ?? ''));
        }

        $recommendedLoan = 0.0;
        if (is_array($scoring)) {
            $recommendedLoan = round(max(0.0, hp_float($scoring['recommended_loan_amount'] ?? 0)), 2);
        }
        if ($recommendedLoan <= 0) {
            $recommendedLoan = round(max(0.0, hp_float($customerPayload['requested_loan_amount'] ?? ($row['amount'] ?? 0))), 2);
        }
        if ($recommendedLoan <= 0) {
            $recommendedLoan = 50000.00;
        }

        $recommendedInstallment = is_array($scoring)
            ? round(max(0.0, hp_float($scoring['recommended_installment'] ?? 0)), 2)
            : 0.0;

        $annualRatePct = is_array($scoring)
            ? round(max(0.0, hp_float($scoring['annual_rate_pct'] ?? 12.0)), 4)
            : 12.0;
        if ($annualRatePct <= 0) {
            $annualRatePct = 12.0;
        }

        $termMonths = is_array($scoring)
            ? max(1, hp_int($scoring['term_months'] ?? 24))
            : 24;

        $applicationNo = is_array($scoring)
            ? trim((string)($scoring['application_no'] ?? ''))
            : '';
        if ($applicationNo === '') {
            $applicationNo = 'APP-' . $customerCode;
        }

        $normalized[] = [
            'id' => (int)$row['id'],
            // ใช้ UID ของ customer_360 เพื่อไม่พึ่งว่าต้องมี record credit_scoring ทุกคน
            'record_uid' => (string)$row['record_uid'],
            'record_status' => 'APPROVED',
            'application_no' => $applicationNo,
            'customer_code' => $customerCode,
            'customer_name' => $customerName,
            'branch_code' => trim((string)($row['branch_code'] ?? '')),
            'policy_code' => is_array($scoring) ? trim((string)($scoring['policy_code'] ?? '')) : '',
            'policy_name' => is_array($scoring) ? trim((string)($scoring['policy_name'] ?? '')) : '',
            'recommended_loan_amount' => $recommendedLoan,
            'recommended_installment' => $recommendedInstallment,
            'annual_rate_pct' => $annualRatePct,
            'term_months' => $termMonths,
            'has_scoring_approval' => is_array($scoring),
            'payload' => is_array($scoring) ? (array)($scoring['payload'] ?? []) : [],
            'policy_payload' => is_array($scoring) ? (array)($scoring['policy_payload'] ?? []) : [],
        ];
    }

    $branchCode = strtoupper(trim($branchCode));
    if ($branchCode !== '') {
        $normalized = array_values(array_filter(
            $normalized,
            static function (array $candidate) use ($branchCode): bool {
                return strtoupper(trim((string)($candidate['branch_code'] ?? ''))) === $branchCode;
            }
        ));
    }

    if ($requireScoringApproval) {
        $normalized = array_values(array_filter(
            $normalized,
            static function (array $candidate): bool {
                return (bool)($candidate['has_scoring_approval'] ?? false);
            }
        ));
    }

    // ตัดลูกค้าที่ "เคยมีสัญญาแล้ว" ออกจากรายการเสนอทำสัญญาใหม่
    if ($normalized !== []) {
        $candidateCodes = [];
        foreach ($normalized as $candidate) {
            $code = strtoupper(trim((string)($candidate['customer_code'] ?? '')));
            if ($code !== '') {
                $candidateCodes[$code] = true;
            }
        }

        if ($candidateCodes !== []) {
            $params = [];
            $placeholders = [];
            $codes = array_keys($candidateCodes);
            foreach ($codes as $idx => $code) {
                $key = ':cc' . $idx;
                $placeholders[] = $key;
                $params[$key] = $code;
            }

            $scopeContract = access_scope_sql_clause('m.branch_code', 'hp_scope_con_exists', $scope);
            $sqlContract = '
                SELECT DISTINCT UPPER(TRIM(m.customer_code)) AS customer_code
                FROM master_contract m
                WHERE m.is_latest = 1
                  AND m.is_deleted = 0
                  AND m.customer_code IN (' . implode(', ', $placeholders) . ')
                  ' . $scopeContract['sql'] . '
            ';
            $stmtContract = db()->prepare($sqlContract);
            $stmtContract->execute(array_merge($params, $scopeContract['params']));

            $hasContractLookup = [];
            foreach ($stmtContract->fetchAll() as $row) {
                $code = strtoupper(trim((string)($row['customer_code'] ?? '')));
                if ($code !== '') {
                    $hasContractLookup[$code] = true;
                }
            }

            if ($hasContractLookup !== []) {
                $normalized = array_values(array_filter(
                    $normalized,
                    static function (array $candidate) use ($hasContractLookup): bool {
                        $code = strtoupper(trim((string)($candidate['customer_code'] ?? '')));
                        return $code === '' || !isset($hasContractLookup[$code]);
                    }
                ));
            }
        }
    }

    $searchText = trim($searchText);
    if ($searchText === '') {
        return $normalized;
    }

    $searchTextNorm = function_exists('mb_strtolower')
        ? mb_strtolower($searchText, 'UTF-8')
        : strtolower($searchText);
    $filtered = [];
    foreach ($normalized as $candidate) {
        $haystack = implode(' ', [
            (string)($candidate['customer_code'] ?? ''),
            (string)($candidate['customer_name'] ?? ''),
            (string)($candidate['application_no'] ?? ''),
            (string)($candidate['branch_code'] ?? ''),
            (string)($candidate['policy_code'] ?? ''),
            (string)($candidate['policy_name'] ?? ''),
        ]);
        $haystackNorm = function_exists('mb_strtolower')
            ? mb_strtolower($haystack, 'UTF-8')
            : strtolower($haystack);
        if (str_contains($haystackNorm, $searchTextNorm)) {
            $filtered[] = $candidate;
        }
    }

    return $filtered;
}

/**
 * @return array<string, array<string,mixed>>
 */
function hp_fetch_policy_catalog(): array
{
    $sql = '
        SELECT id, primary_ref, primary_name, data_json
        FROM workflow_records
        WHERE module_key = "credit_policy"
          AND is_latest = 1
          AND is_deleted = 0
          AND record_status = "APPROVED"
        ORDER BY id DESC
        LIMIT 2000
    ';

    $stmt = db()->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];

    $catalog = [];
    foreach ($rows as $row) {
        $policyCode = strtoupper(trim((string)($row['primary_ref'] ?? '')));
        if ($policyCode === '') {
            continue;
        }
        if (isset($catalog[$policyCode])) {
            continue;
        }
        $catalog[$policyCode] = [
            'id' => (int)($row['id'] ?? 0),
            'policy_code' => (string)($row['primary_ref'] ?? ''),
            'policy_name' => (string)($row['primary_name'] ?? ''),
            'payload' => hp_decode_json_assoc((string)($row['data_json'] ?? '')),
        ];
    }

    return $catalog;
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @return array<int, array<string,mixed>>
 */
function hp_fetch_contract_rows(
    array $scope,
    string $search = '',
    string $branchCode = '',
    string $borrowerName = ''
): array
{
    $scopeClause = access_scope_sql_clause('m.branch_code', 'hp_scope_con', $scope);
    $sql = '
        SELECT m.*
        FROM master_contract m
        WHERE m.is_latest = 1
          AND m.is_deleted = 0
          ' . $scopeClause['sql'] . '
    ';
    $params = $scopeClause['params'];
    $branchCode = strtoupper(trim($branchCode));
    if ($branchCode !== '') {
        $sql .= ' AND m.branch_code = :hp_branch_code';
        $params[':hp_branch_code'] = $branchCode;
    }

    $borrowerName = trim($borrowerName);
    if ($borrowerName !== '') {
        $sql .= ' AND JSON_UNQUOTE(JSON_EXTRACT(m.data_json, "$.customer_name")) LIKE :hp_customer_name';
        $params[':hp_customer_name'] = '%' . $borrowerName . '%';
    }

    $search = trim($search);
    if ($search !== '') {
        $sql .= ' AND (
            m.contract_no LIKE :hp_search_contract_no
            OR m.customer_code LIKE :hp_search_customer_code
            OR JSON_UNQUOTE(JSON_EXTRACT(m.data_json, "$.customer_name")) LIKE :hp_search_customer_name
        )';
        $searchLike = '%' . $search . '%';
        $params[':hp_search_contract_no'] = $searchLike;
        $params[':hp_search_customer_code'] = $searchLike;
        $params[':hp_search_customer_name'] = $searchLike;
    }
    $sql .= ' ORDER BY m.id DESC LIMIT 500';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $result = [];
    foreach ($rows as $row) {
        $payload = hp_decode_json_assoc((string)($row['data_json'] ?? ''));
        $result[] = [
            'id' => (int)$row['id'],
            'record_uid' => (string)$row['record_uid'],
            'version_no' => (int)$row['version_no'],
            'contract_no' => (string)$row['contract_no'],
            'customer_code' => (string)$row['customer_code'],
            'branch_code' => (string)$row['branch_code'],
            'product_code' => (string)$row['product_code'],
            'principal_amount' => round(max(0.0, hp_float($row['principal_amount'] ?? 0)), 2),
            'payload' => $payload,
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    return $result;
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @return array<string,mixed>|null
 */
function hp_find_contract_latest(string $contractNo, array $scope): ?array
{
    $contractNo = strtoupper(trim($contractNo));
    if ($contractNo === '') {
        return null;
    }

    $scopeClause = access_scope_sql_clause('m.branch_code', 'hp_scope_find', $scope);
    $sql = '
        SELECT m.*
        FROM master_contract m
        WHERE m.contract_no = :contract_no
          AND m.is_latest = 1
          AND m.is_deleted = 0
          ' . $scopeClause['sql'] . '
        ORDER BY m.id DESC
        LIMIT 1
    ';
    $params = $scopeClause['params'];
    $params[':contract_no'] = $contractNo;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return [
        'id' => (int)$row['id'],
        'record_uid' => (string)$row['record_uid'],
        'version_no' => (int)$row['version_no'],
        'contract_no' => (string)$row['contract_no'],
        'customer_code' => (string)$row['customer_code'],
        'branch_code' => (string)$row['branch_code'],
        'product_code' => (string)$row['product_code'],
        'principal_amount' => round(max(0.0, hp_float($row['principal_amount'] ?? 0)), 2),
        'payload' => hp_decode_json_assoc((string)($row['data_json'] ?? '')),
        'raw_row' => $row,
    ];
}

/**
 * @param array<string,mixed> $contractRow
 * @param array<string,mixed> $newPayload
 */
function hp_update_contract_payload(array $contractRow, array $newPayload, string $actor): void
{
    $now = now_dt();
    $id = (int)($contractRow['id'] ?? 0);
    $recordUid = (string)($contractRow['record_uid'] ?? '');
    $versionNo = (int)($contractRow['version_no'] ?? 1);
    $contractNo = (string)($contractRow['contract_no'] ?? '');
    $customerCode = (string)($contractRow['customer_code'] ?? '');
    $branchCode = (string)($contractRow['branch_code'] ?? '');
    $productCode = (string)($contractRow['product_code'] ?? '');
    $principalAmount = round(max(0.0, hp_float($contractRow['principal_amount'] ?? 0)), 2);
    if ($id <= 0 || $recordUid === '' || $contractNo === '') {
        throw new RuntimeException('ข้อมูลสัญญาไม่ถูกต้อง');
    }

    db()->beginTransaction();
    try {
        $stmtOld = db()->prepare('UPDATE master_contract SET is_latest = 0, updated_by = :updated_by, updated_at = :updated_at WHERE id = :id');
        $stmtOld->execute([
            ':updated_by' => $actor,
            ':updated_at' => $now,
            ':id' => $id,
        ]);

        $stmtNew = db()->prepare(
            'INSERT INTO master_contract (
                record_uid, version_no, is_latest, is_deleted,
                contract_no, customer_code, branch_code, product_code, principal_amount, data_json,
                created_by, created_at, updated_by, updated_at
            ) VALUES (
                :record_uid, :version_no, 1, 0,
                :contract_no, :customer_code, :branch_code, :product_code, :principal_amount, :data_json,
                :created_by, :created_at, :updated_by, :updated_at
            )'
        );
        $stmtNew->execute([
            ':record_uid' => $recordUid,
            ':version_no' => $versionNo + 1,
            ':contract_no' => $contractNo,
            ':customer_code' => $customerCode,
            ':branch_code' => $branchCode,
            ':product_code' => $productCode,
            ':principal_amount' => $principalAmount,
            ':data_json' => json_encode($newPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':created_by' => (string)($contractRow['raw_row']['created_by'] ?? $actor),
            ':created_at' => (string)($contractRow['raw_row']['created_at'] ?? $now),
            ':updated_by' => $actor,
            ':updated_at' => $now,
        ]);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

/**
 * @param array<string,mixed> $candidate
 */
function hp_create_contract_from_candidate(
    array $candidate,
    float $loanAmount,
    float $annualRatePct,
    int $termMonths,
    string $firstDueDate,
    string $note,
    string $actor,
    string $loanContractPdfPath = '',
    string $loanContractPdfName = ''
): string
{
    $contractNo = hp_generate_code('SFCON');
    $recordUid = hp_generate_code('CONRID');
    $branchCode = strtoupper(trim((string)($candidate['branch_code'] ?? '')));
    $customerCode = strtoupper(trim((string)($candidate['customer_code'] ?? '')));
    $customerName = trim((string)($candidate['customer_name'] ?? ''));
    $applicationNo = trim((string)($candidate['application_no'] ?? ''));
    $policyCode = trim((string)($candidate['policy_code'] ?? ''));
    $policyName = trim((string)($candidate['policy_name'] ?? ''));

    if ($branchCode === '') {
        $branchCode = strtoupper(trim((string)(current_user_profile()['branch_code'] ?? '')));
    }
    if ($customerCode === '') {
        throw new RuntimeException('ไม่พบรหัสลูกค้าของคำขอที่เลือก');
    }

    $loanAmount = round(max(0.0, $loanAmount), 2);
    if ($loanAmount <= 0) {
        throw new RuntimeException('วงเงินอนุมัติต้องมากกว่า 0');
    }

    $annualRatePct = round(max(0.0, $annualRatePct), 4);
    if ($annualRatePct <= 0) {
        $annualRatePct = 12.0;
    }
    $termMonths = max(1, $termMonths);
    $firstDueDate = hp_parse_date($firstDueDate, date('Y-m-d'));

    $schedule = hp_build_schedule($loanAmount, $annualRatePct, $termMonths, $firstDueDate);
    $payload = [
        'source_credit_record_uid' => (string)($candidate['record_uid'] ?? ''),
        'application_no' => $applicationNo,
        'customer_name' => $customerName,
        'policy_code' => $policyCode,
        'policy_name' => $policyName,
        'approved_loan_amount' => $loanAmount,
        'annual_rate_pct' => $annualRatePct,
        'term_months' => $termMonths,
        'monthly_installment' => (float)$schedule['installment_amount'],
        'first_due_date' => $firstDueDate,
        'contract_status' => 'ACTIVE',
        'current_status' => 'ACTIVE',
        'dpd_bucket' => 'CURRENT',
        'total_interest' => (float)$schedule['total_interest'],
        'payment_history' => $schedule['payment_history'],
        'loan_contract_pdf_path' => $loanContractPdfPath,
        'loan_contract_pdf_name' => $loanContractPdfName,
        'note' => $note,
        'created_from' => 'module_hire_purchase',
    ];

    $now = now_dt();
    $stmt = db()->prepare(
        'INSERT INTO master_contract (
            record_uid, version_no, is_latest, is_deleted,
            contract_no, customer_code, branch_code, product_code, principal_amount, data_json,
            created_by, created_at, updated_by, updated_at
        ) VALUES (
            :record_uid, 1, 1, 0,
            :contract_no, :customer_code, :branch_code, :product_code, :principal_amount, :data_json,
            :created_by, :created_at, :updated_by, :updated_at
        )'
    );
    $stmt->execute([
        ':record_uid' => $recordUid,
        ':contract_no' => $contractNo,
        ':customer_code' => $customerCode,
        ':branch_code' => $branchCode,
        ':product_code' => 'LOAN',
        ':principal_amount' => $loanAmount,
        ':data_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':created_by' => $actor,
        ':created_at' => $now,
        ':updated_by' => $actor,
        ':updated_at' => $now,
    ]);

    return $contractNo;
}

/**
 * @param array<string,mixed> $contractRow
 */
function hp_soft_delete_contract(array $contractRow, string $actor): void
{
    $now = now_dt();
    $id = (int)($contractRow['id'] ?? 0);
    $recordUid = (string)($contractRow['record_uid'] ?? '');
    $versionNo = (int)($contractRow['version_no'] ?? 1);
    $contractNo = (string)($contractRow['contract_no'] ?? '');
    if ($id <= 0 || $recordUid === '' || $contractNo === '') {
        throw new RuntimeException('ไม่พบข้อมูลสัญญาที่ต้องการลบ');
    }

    $rawPayload = $contractRow['payload'] ?? [];
    if (!is_array($rawPayload)) {
        $rawPayload = [];
    }
    $rawPayload['contract_status'] = 'DELETED';
    $rawPayload['current_status'] = 'DELETED';
    $rawPayload['deleted_reason'] = 'soft_delete';
    $rawPayload['deleted_at'] = $now;
    $rawPayload['deleted_by'] = $actor;

    db()->beginTransaction();
    try {
        $stmtOld = db()->prepare('UPDATE master_contract SET is_latest = 0, updated_by = :updated_by, updated_at = :updated_at WHERE id = :id');
        $stmtOld->execute([
            ':updated_by' => $actor,
            ':updated_at' => $now,
            ':id' => $id,
        ]);

        $stmtNew = db()->prepare(
            'INSERT INTO master_contract (
                record_uid, version_no, is_latest, is_deleted,
                contract_no, customer_code, branch_code, product_code, principal_amount, data_json,
                created_by, created_at, updated_by, updated_at, deleted_by, deleted_at
            ) VALUES (
                :record_uid, :version_no, 1, 1,
                :contract_no, :customer_code, :branch_code, :product_code, :principal_amount, :data_json,
                :created_by, :created_at, :updated_by, :updated_at, :deleted_by, :deleted_at
            )'
        );
        $stmtNew->execute([
            ':record_uid' => $recordUid,
            ':version_no' => $versionNo + 1,
            ':contract_no' => $contractNo,
            ':customer_code' => (string)($contractRow['customer_code'] ?? ''),
            ':branch_code' => (string)($contractRow['branch_code'] ?? ''),
            ':product_code' => (string)($contractRow['product_code'] ?? 'LOAN'),
            ':principal_amount' => round(max(0.0, hp_float($contractRow['principal_amount'] ?? 0)), 2),
            ':data_json' => json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':created_by' => (string)($contractRow['raw_row']['created_by'] ?? $actor),
            ':created_at' => (string)($contractRow['raw_row']['created_at'] ?? $now),
            ':updated_by' => $actor,
            ':updated_at' => $now,
            ':deleted_by' => $actor,
            ':deleted_at' => $now,
        ]);

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

/**
 * @param array<int, array<string,mixed>> $paymentHistory
 * @return array{bucket:string,max_days:int}
 */
function hp_dpd_bucket(array $paymentHistory, string $asOfDate): array
{
    $asOf = new DateTimeImmutable(hp_parse_date($asOfDate, date('Y-m-d')));
    $maxDays = 0;

    foreach ($paymentHistory as $row) {
        $status = strtoupper(trim((string)($row['payment_status'] ?? 'UNPAID')));
        if ($status === 'PAID') {
            continue;
        }
        $dueText = trim((string)($row['due_date'] ?? ''));
        if ($dueText === '') {
            continue;
        }
        try {
            $due = new DateTimeImmutable($dueText);
        } catch (Throwable $e) {
            continue;
        }
        $days = (int)$due->diff($asOf)->format('%r%a');
        if ($days > $maxDays) {
            $maxDays = $days;
        }
    }

    if ($maxDays <= 0) {
        return ['bucket' => 'CURRENT', 'max_days' => 0];
    }
    if ($maxDays <= 7) {
        return ['bucket' => '1-7', 'max_days' => $maxDays];
    }
    if ($maxDays <= 30) {
        return ['bucket' => '8-30', 'max_days' => $maxDays];
    }
    if ($maxDays <= 60) {
        return ['bucket' => '31-60', 'max_days' => $maxDays];
    }
    if ($maxDays <= 90) {
        return ['bucket' => '61-90', 'max_days' => $maxDays];
    }

    return ['bucket' => '90+', 'max_days' => $maxDays];
}

/**
 * @param array<int, array<string,mixed>> $paymentHistory
 */
function hp_contract_status_from_history(array $paymentHistory, string $asOfDate): array
{
    $allPaid = true;
    foreach ($paymentHistory as $row) {
        $status = strtoupper(trim((string)($row['payment_status'] ?? 'UNPAID')));
        if ($status !== 'PAID') {
            $allPaid = false;
            break;
        }
    }

    if ($allPaid && $paymentHistory !== []) {
        return ['current_status' => 'CLOSED', 'dpd_bucket' => 'CURRENT', 'max_days' => 0];
    }

    $bucket = hp_dpd_bucket($paymentHistory, $asOfDate);
    $currentStatus = ((int)$bucket['max_days'] >= 90) ? 'NPL' : 'ACTIVE';

    return [
        'current_status' => $currentStatus,
        'dpd_bucket' => (string)$bucket['bucket'],
        'max_days' => (int)$bucket['max_days'],
    ];
}

/**
 * @return array{path:string,name:string,size:int,mime:string}|null
 */
function hp_upload_file(string $fieldName, array $allowedExt, int $maxSize, string $subDir): ?array
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
        throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ (error ' . $error . ')');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxSize) {
        throw new RuntimeException('ขนาดไฟล์ไม่ถูกต้อง');
    }

    $originalName = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
        throw new RuntimeException('ประเภทไฟล์ไม่รองรับ');
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('ไม่พบไฟล์อัปโหลดชั่วคราว');
    }

    $baseDir = dirname(__DIR__) . '/assets/uploads/' . trim($subDir, '/');
    if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        throw new RuntimeException('ไม่สามารถสร้างโฟลเดอร์อัปโหลดได้');
    }

    $newName = $subDir . '_' . date('Ymd_His') . '_' . str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT) . '.' . $ext;
    $targetPath = $baseDir . '/' . $newName;
    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('บันทึกไฟล์อัปโหลดไม่สำเร็จ');
    }

    return [
        'path' => 'assets/uploads/' . trim($subDir, '/') . '/' . $newName,
        'name' => $originalName,
        'size' => $size,
        'mime' => (string)($file['type'] ?? ''),
    ];
}

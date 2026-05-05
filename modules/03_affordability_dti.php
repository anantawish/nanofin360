<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/hire_purchase_core.php';

$moduleKey = 'affordability_dti';
$module = module_by_key($moduleKey);
if ($module === null) {
    http_response_code(500);
    echo 'Module configuration not found';
    exit;
}

$scope = current_access_scope();
$branchRows = active_branch_rows();
$accessibleBranches = accessible_branch_codes($scope);
$accessibleSet = array_fill_keys($accessibleBranches, true);

$filterBranch = strtoupper(trim((string)($_GET['branch_code'] ?? $_POST['branch_code'] ?? '')));
if ($filterBranch !== '' && !isset($accessibleSet[$filterBranch])) {
    $filterBranch = '';
}

$search = trim((string)($_GET['q'] ?? ''));
$viewContract = trim((string)($_GET['contract_no'] ?? ''));
$scenarioCode = strtoupper(trim((string)($_POST['scenario_code'] ?? 'BASE')));
$selectedLeiId = (int)($_POST['lei_source_id'] ?? $_POST['lei_row_id'] ?? 0);

$flashMessage = null;
$flashType = 'success';
$evaluationResult = null;
$forecastLabels = [];
$forecastValues = [];
$assessModalOpen = false;
$assessModalContext = [
    'contract_no' => '',
    'branch_code' => '',
    'customer_code' => '',
    'customer_name' => '',
    'overdue_count' => 0,
    'late_count' => 0,
    'no_pay_count' => 0,
    'partial_count' => 0,
    'issue_count' => 0,
    'lei_source_id' => 0,
    'scenario_code' => $scenarioCode,
];

function af_parse_date(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'Y-m-d H:i:s'];
    foreach ($formats as $f) {
        $d = DateTimeImmutable::createFromFormat($f, $value);
        if ($d instanceof DateTimeImmutable) {
            return $d;
        }
    }
    try {
        return new DateTimeImmutable($value);
    } catch (Throwable $e) {
        return null;
    }
}

function af_income_to_monthly(array $rows): float
{
    $sum = 0.0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $amount = (float)($row['income_amount'] ?? 0);
        $period = trim((string)($row['income_period'] ?? ''));
        if ($amount <= 0) {
            continue;
        }
        if ($period === 'รายวัน' || strcasecmp($period, 'Daily') === 0) {
            $sum += $amount * 26.0;
        } elseif ($period === 'รายปี' || strcasecmp($period, 'Yearly') === 0) {
            $sum += $amount / 12.0;
        } else {
            $sum += $amount;
        }
    }
    return round($sum, 2);
}

function af_behavior_summary(array $history): array
{
    $today = new DateTimeImmutable('today');
    $late = 0;
    $overdue = 0;
    $noPay = 0;
    $partial = 0;
    $paidCount = 0;
    $onTime = 0;

    foreach ($history as $row) {
        if (!is_array($row)) {
            continue;
        }
        $status = strtoupper(trim((string)($row['payment_status'] ?? 'UNPAID')));
        $dueAmt = (float)($row['installment_due'] ?? 0);
        $paidAmt = (float)($row['paid_amount'] ?? 0);
        $dueDate = af_parse_date((string)($row['due_date'] ?? ''));
        $paidDate = af_parse_date((string)($row['paid_date'] ?? ''));
        $daysOverdue = (int)($row['days_overdue'] ?? 0);
        $eventType = strtoupper(trim((string)($row['payment_event_type'] ?? 'PAY')));

        if ($status === 'PAID') {
            $paidCount++;
            $isLate = false;
            if ($paidDate !== null && $dueDate !== null && $paidDate > $dueDate) {
                $isLate = true;
            }
            if ($daysOverdue > 0) {
                $isLate = true;
            }
            if ($eventType === 'LATE_PAY') {
                $isLate = true;
            }
            if ($isLate) {
                $late++;
            } else {
                $onTime++;
            }
            if ($dueAmt > 0 && $paidAmt + 0.01 < $dueAmt) {
                $partial++;
            }
        } else {
            if ($status === 'NO_PAY') {
                $noPay++;
            }
            if ($dueDate !== null && $dueDate < $today) {
                $overdue++;
            }
        }
    }

    $issues = $late + $overdue + $noPay + $partial;
    $ratio = $paidCount > 0 ? ($onTime / $paidCount) : 0.0;

    return [
        'late_count' => $late,
        'overdue_count' => $overdue,
        'no_pay_count' => $noPay,
        'partial_count' => $partial,
        'manual_flag_count' => $noPay + $late,
        'issue_count' => $issues,
        'on_time_ratio' => round($ratio, 4),
    ];
}

function af_next_installment_due(array $history): float
{
    foreach ($history as $row) {
        if (!is_array($row)) {
            continue;
        }
        $status = strtoupper(trim((string)($row['payment_status'] ?? 'UNPAID')));
        if ($status !== 'PAID') {
            return (float)($row['installment_due'] ?? 0);
        }
    }
    return 0.0;
}

function af_randn(): float
{
    $u = max(1.0e-12, mt_rand() / mt_getrandmax());
    $v = max(1.0e-12, mt_rand() / mt_getrandmax());
    return sqrt(-2.0 * log($u)) * cos(2.0 * M_PI * $v);
}

function af_calculate_result(array $customerPayload, array $contractPayload, array $leiScenario, array $behavior): array
{
    $borrowerIncome = af_income_to_monthly((array)($customerPayload['borrower_occupations'] ?? []));
    $spouseIncome = af_income_to_monthly((array)($customerPayload['spouse_occupations'] ?? []));
    $totalIncome = max(0.0, $borrowerIncome + $spouseIncome);

    $housingExpense = (float)($customerPayload['housing_expense'] ?? 0);
    $liabilities = (array)($customerPayload['liabilities'] ?? []);
    $existingDebtMonthly = 0.0;
    foreach ($liabilities as $item) {
        if (!is_array($item)) {
            continue;
        }
        $outstanding = (float)($item['outstanding_balance'] ?? 0);
        $term = (int)($item['contract_term_months'] ?? 0);
        if ($term <= 0) {
            $term = 24;
        }
        if ($outstanding > 0) {
            $existingDebtMonthly += ($outstanding / $term);
        }
    }

    $householdProfile = (array)($leiScenario['household_profile'] ?? []);
    $leiPerHead = (float)($householdProfile['household_monthly_expense_per_person'] ?? 3500.0);
    $familySize = 1 + max(0, (int)($customerPayload['dependents'] ?? 0));
    $leiHousehold = $leiPerHead * $familySize;

    $costMul = (float)($leiScenario['cost_multiplier'] ?? 1.0);
    $incomeMul = (float)($leiScenario['income_multiplier'] ?? 1.0);
    $incomeAdj = $totalIncome * $incomeMul;
    $expenseAdj = ($housingExpense + $existingDebtMonthly + $leiHousehold) * $costMul;

    $nextInstallment = af_next_installment_due((array)($contractPayload['payment_history'] ?? []));
    $dti = ($incomeAdj > 0.0) ? (($existingDebtMonthly + $nextInstallment) / $incomeAdj) : 9.99;

    $behaviorPenalty = min(0.20, ((int)$behavior['issue_count']) * 0.01);
    $baseCapacity = ($incomeAdj * 0.45) - $expenseAdj;
    $maxInstallment = max(0.0, $baseCapacity * (1.0 - $behaviorPenalty));

    $grade = 'PASS';
    if ($dti >= 0.7 || $maxInstallment <= 0) {
        $grade = 'HIGH RISK';
    } elseif ($dti >= 0.55 || $maxInstallment < $nextInstallment) {
        $grade = 'WATCHLIST';
    }

    return [
        'income_total' => round($totalIncome, 2),
        'income_adjusted' => round($incomeAdj, 2),
        'existing_debt_monthly' => round($existingDebtMonthly, 2),
        'household_expense' => round($expenseAdj, 2),
        'next_installment' => round($nextInstallment, 2),
        'dti' => round($dti, 4),
        'max_installment' => round($maxInstallment, 2),
        'grade' => $grade,
    ];
}

function af_monte_carlo_forecast(array $base, array $scenario, array $behavior, int $rounds = 1000, int $months = 18): array
{
    $rows = [];
    $start = new DateTimeImmutable('first day of this month');
    $issuePenalty = min(0.30, ((int)$behavior['issue_count']) * 0.0125);

    for ($m = 1; $m <= $months; $m++) {
        $sumRisk = 0.0;
        for ($i = 0; $i < $rounds; $i++) {
            $incomeShock = 1.0 + (af_randn() * 0.05) - ($m * 0.0015);
            $costShock = 1.0 + (af_randn() * 0.04) + ($m * 0.0020);
            $income = max(1000.0, (float)$base['income_adjusted'] * $incomeShock);
            $expense = max(0.0, (float)$base['household_expense'] * $costShock);
            $disposable = $income - $expense;
            $dtiSim = ($income > 0.0) ? (((float)$base['existing_debt_monthly'] + (float)$base['next_installment']) / $income) : 9.99;

            $risk = 0.10
                + max(0.0, ($dtiSim - 0.45) * 0.55)
                + max(0.0, -$disposable / max(1.0, $income)) * 0.40
                + $issuePenalty
                + ((float)($scenario['pd_shift_pct'] ?? 0.0) / 100.0);
            $sumRisk += min(0.99, max(0.01, $risk));
        }

        $avgRisk = $sumRisk / max(1, $rounds);
        $monthDate = $start->modify('+' . $m . ' month');
        $rows[] = [
            'month_label' => $monthDate->format('Y-m'),
            'risk_pct' => round($avgRisk * 100, 2),
        ];
    }

    return $rows;
}

function af_fetch_contract_candidates(array $scope, string $branchCode, string $search): array
{
    $scopeClause = access_scope_sql_clause('branch_code', 'ctr_scope', $scope);
    $sql = 'SELECT id, contract_no, customer_code, branch_code, data_json
            FROM master_contract
            WHERE is_latest = 1 AND is_deleted = 0' . $scopeClause['sql'];

    $params = $scopeClause['params'];
    if ($branchCode !== '') {
        $sql .= ' AND branch_code = :branch_code';
        $params[':branch_code'] = $branchCode;
    }
    if ($search !== '') {
        $sql .= ' AND (contract_no LIKE :q OR customer_code LIKE :q OR data_json LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }
    $sql .= ' ORDER BY id DESC LIMIT 500';

    $stmt = db()->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue((string)$k, $v);
    }
    $stmt->execute();

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $payload = json_decode((string)($row['data_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $history = (array)($payload['payment_history'] ?? []);
        $behavior = af_behavior_summary($history);
        $review = (array)($payload['affordability_review'] ?? []);
        $isUnlocked = (bool)($review['is_unlocked'] ?? false);
        $hasManualFlag = ((int)($behavior['manual_flag_count'] ?? 0)) > 0;

        if (((int)$behavior['issue_count'] <= 3 && !$hasManualFlag) || $isUnlocked) {
            continue;
        }

        $rows[] = [
            'id' => (int)$row['id'],
            'contract_no' => (string)$row['contract_no'],
            'customer_code' => (string)$row['customer_code'],
            'branch_code' => (string)$row['branch_code'],
            'customer_name' => (string)($payload['customer_name'] ?? ''),
            'behavior' => $behavior,
            'payload' => $payload,
        ];
    }

    return $rows;
}

function af_fetch_customer_payload(string $customerCode): array
{
    if ($customerCode === '') {
        return [];
    }
    $stmt = db()->prepare(
        "SELECT data_json
         FROM workflow_records
         WHERE module_key = 'customer_360'
           AND primary_ref = :ref
           AND is_latest = 1
           AND is_deleted = 0
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([':ref' => $customerCode]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return [];
    }
    $decoded = json_decode((string)($row['data_json'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function af_fetch_lei_rows(string $branchCode, array $scope): array
{
    if ($branchCode === '') {
        return [];
    }
    if (!is_branch_in_current_scope($branchCode, $scope)) {
        return [];
    }

    $stmt = db()->prepare(
        "SELECT id, primary_ref, data_json, updated_at, created_at
         FROM workflow_records
         WHERE module_key = 'local_economy_lei'
           AND branch_code = :branch_code
           AND is_latest = 1
           AND is_deleted = 0
         ORDER BY id DESC
         LIMIT 50"
    );
    $stmt->execute([':branch_code' => $branchCode]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $payload = json_decode((string)($row['data_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $rows[] = [
            'id' => (int)$row['id'],
            'code' => (string)($row['primary_ref'] ?? ('LEI-' . $row['id'])),
            'payload' => $payload,
            'updated_at' => (string)($row['updated_at'] ?: $row['created_at']),
        ];
    }

    return $rows;
}

/**
 * @param array<string,string> $branchNameMap
 * @return array<int, array{
 *   id:int,
 *   report_no:string,
 *   branch_code:string,
 *   branch_name:string,
 *   label:string,
 *   payload:array<string,mixed>,
 *   scenario_options:array<int, array{
 *     code:string,label:string,cost_multiplier:float,income_multiplier:float,pd_shift_pct:float,npl_shift_pct:float,description:string
 *   }>
 * }>
 */
function af_fetch_lei_sources(array $scope, array $branchNameMap): array
{
    $scopeClause = access_scope_sql_clause('w.branch_code', 'af_lei_source_scope', $scope);
    $sql = '
        SELECT w.id, w.primary_ref, w.branch_code, w.data_json, w.updated_at, w.created_at
        FROM workflow_records w
        WHERE w.module_key = "local_economy_lei"
          AND w.is_latest = 1
          AND w.is_deleted = 0
          ' . $scopeClause['sql'] . '
        ORDER BY w.updated_at DESC, w.id DESC
        LIMIT 500
    ';
    $stmt = db()->prepare($sql);
    $stmt->execute($scopeClause['params']);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $payload = json_decode((string)($row['data_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $branchCode = strtoupper(trim((string)($payload['branch_code'] ?? $row['branch_code'] ?? '')));
        if ($branchCode === '') {
            continue;
        }
        if (!is_branch_in_current_scope($branchCode, $scope)) {
            continue;
        }

        $branchProfile = lei_fetch_branch_household_profile($branchCode, $scope);
        $scenarioOptions = lei_scenario_options_for_select(lei_branch_scenarios($branchProfile));
        $reportNo = trim((string)($payload['lei_report_no'] ?? $row['primary_ref'] ?? ('LEI-' . (int)($row['id'] ?? 0))));
        $branchName = trim((string)($branchNameMap[$branchCode] ?? ''));
        $updatedAt = (string)($row['updated_at'] ?: $row['created_at']);

        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'report_no' => $reportNo,
            'branch_code' => $branchCode,
            'branch_name' => $branchName,
            'label' => $reportNo . ' | ' . $branchCode . ($branchName !== '' ? (' - ' . $branchName) : '') . ' | Updated ' . $updatedAt,
            'payload' => $payload,
            'scenario_options' => $scenarioOptions,
        ];
    }

    return $rows;
}

/**
 * @return array<string,mixed>|null
 */
function af_fetch_lei_source_by_id(int $sourceId, array $scope): ?array
{
    if ($sourceId <= 0) {
        return null;
    }

    $scopeClause = access_scope_sql_clause('w.branch_code', 'af_lei_id_scope', $scope);
    $sql = '
        SELECT w.id, w.primary_ref, w.branch_code, w.data_json
        FROM workflow_records w
        WHERE w.id = :id
          AND w.module_key = "local_economy_lei"
          AND w.is_latest = 1
          AND w.is_deleted = 0
          ' . $scopeClause['sql'] . '
        LIMIT 1
    ';
    $params = $scopeClause['params'];
    $params[':id'] = $sourceId;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }

    $payload = json_decode((string)($row['data_json'] ?? ''), true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $branchCode = strtoupper(trim((string)($payload['branch_code'] ?? $row['branch_code'] ?? '')));

    return [
        'id' => (int)($row['id'] ?? 0),
        'report_no' => trim((string)($payload['lei_report_no'] ?? $row['primary_ref'] ?? ('LEI-' . (int)($row['id'] ?? 0)))),
        'branch_code' => $branchCode,
        'payload' => $payload,
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function af_fetch_branch_household_profiles(array $scope): array
{
    $scopeClause = access_scope_sql_clause('branch_code', 'mb_scope', $scope);
    $sql = 'SELECT branch_code, data_json
            FROM master_branch
            WHERE is_latest = 1 AND is_deleted = 0' . $scopeClause['sql'];
    $stmt = db()->prepare($sql);
    foreach ($scopeClause['params'] as $k => $v) {
        $stmt->bindValue((string)$k, $v);
    }
    $stmt->execute();

    $profiles = [];
    foreach ($stmt->fetchAll() as $row) {
        $branchCode = strtoupper(trim((string)($row['branch_code'] ?? '')));
        if ($branchCode === '') {
            continue;
        }
        $payload = json_decode((string)($row['data_json'] ?? ''), true);
        if (!is_array($payload)) {
            continue;
        }
        $profile = $payload['household_expense_index'] ?? null;
        if (!is_array($profile)) {
            continue;
        }
        $profiles[$branchCode] = $profile;
    }

    return $profiles;
}

/**
 * Convert LEI payload into a profile structure directly usable by the DTI module.
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function af_household_profile_from_lei_payload(array $payload): array
{
    $perPerson = max(
        0.0,
        hp_float($payload['household_monthly_expense_per_person'] ?? 0),
        hp_float($payload['household_per_person_monthly'] ?? 0)
    );

    if ($perPerson <= 0) {
        $rent = max(0.0, hp_float($payload['rent_room_monthly_price'] ?? 0));
        $meal = max(0.0, hp_float($payload['standard_set_meal_price'] ?? 0));
        $fuel = max(0.0, hp_float($payload['fuel_price_per_liter'] ?? 0));
        $elecRate = max(0.0, hp_float($payload['electricity_unit_rate'] ?? 0));
        $waterRate = max(0.0, hp_float($payload['water_unit_rate'] ?? 0));

        // Fallback estimate when LEI has not yet been applied into master_branch.
        $derived = 2800.0
            + ($meal * 14.0)
            + ($fuel * 11.0)
            + ($elecRate * 55.0)
            + ($waterRate * 6.0)
            + ($rent * 0.22);
        $perPerson = max(2500.0, min(12000.0, $derived));
    }

    $baselineMonthly = max(
        0.0,
        hp_float($payload['household_baseline_monthly'] ?? 0),
        $perPerson * max(1.0, hp_float($payload['assumed_household_size'] ?? 2.5))
    );

    return [
        'household_monthly_expense_per_person' => round($perPerson, 2),
        'baseline_monthly' => round($baselineMonthly, 2),
        'source_module' => 'local_economy_lei',
        'source_report_no' => (string)($payload['lei_report_no'] ?? ''),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));

        if ($action === 'unlock_review') {
            $contractNo = trim((string)($_POST['contract_no'] ?? ''));
            $contractRow = hp_find_contract_latest($contractNo, $scope);
            if ($contractRow === null) {
                throw new RuntimeException('Contract not found');
            }
            $payload = json_decode((string)($contractRow['data_json'] ?? ''), true);
            if (!is_array($payload)) {
                $payload = [];
            }
            $review = (array)($payload['affordability_review'] ?? []);
            $review['is_unlocked'] = true;
            $review['unlocked_at'] = now_dt();
            $review['unlocked_by'] = current_user_name();
            $payload['affordability_review'] = $review;
            hp_update_contract_payload($contractRow, $payload, current_user_name());
            add_flash('success', 'Review lock removed successfully');
            redirect_to(app_base_url('modules/03_affordability_dti.php?branch_code=' . rawurlencode($filterBranch) . '&q=' . rawurlencode($search)));
        }

        if ($action === 'run_assessment') {
            $assessModalOpen = true;
            $contractNo = trim((string)($_POST['contract_no'] ?? ''));
            $branchCode = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
            $scenarioCode = strtoupper(trim((string)($_POST['scenario_code'] ?? 'BASE')));

            if ($contractNo === '') {
                throw new RuntimeException('Incomplete input data');
            }

            $contractRow = hp_find_contract_latest($contractNo, $scope);
            if ($contractRow === null) {
                throw new RuntimeException('Contract not found');
            }
            if ($branchCode === '') {
                $branchCode = strtoupper(trim((string)($contractRow['branch_code'] ?? '')));
            }
            if ($branchCode === '') {
                throw new RuntimeException('Contract branch code not found');
            }
            if (!is_branch_in_current_scope($branchCode, $scope)) {
                throw new RuntimeException('You do not have access to this branch');
            }
            $contractPayload = json_decode((string)($contractRow['data_json'] ?? ''), true);
            if (!is_array($contractPayload)) {
                $contractPayload = [];
            }

            $customerCode = trim((string)($contractRow['customer_code'] ?? ''));
            $customerPayload = af_fetch_customer_payload($customerCode);
            $behavior = af_behavior_summary((array)($contractPayload['payment_history'] ?? []));
            $assessModalContext = [
                'contract_no' => $contractNo,
                'branch_code' => $branchCode,
                'customer_code' => $customerCode,
                'customer_name' => (string)($contractPayload['customer_name'] ?? '-'),
                'overdue_count' => (int)($behavior['overdue_count'] ?? 0),
                'late_count' => (int)($behavior['late_count'] ?? 0),
                'no_pay_count' => (int)($behavior['no_pay_count'] ?? 0),
                'partial_count' => (int)($behavior['partial_count'] ?? 0),
                'issue_count' => (int)($behavior['issue_count'] ?? 0),
                'lei_source_id' => $selectedLeiId,
                'scenario_code' => $scenarioCode,
            ];

            $scenarioBranchCode = $branchCode;
            $branchProfile = lei_fetch_branch_household_profile($scenarioBranchCode, $scope);
            $activeHouseholdProfile = is_array($branchProfile) ? $branchProfile : [];

            if ($selectedLeiId > 0) {
                $leiSource = af_fetch_lei_source_by_id($selectedLeiId, $scope);
                if ($leiSource === null) {
                    throw new RuntimeException('Selected LEI source was not found in Local Economy LEI module');
                }
                $leiPayload = (array)($leiSource['payload'] ?? []);
                $scenarioBranchCode = strtoupper(trim((string)($leiSource['branch_code'] ?? '')));
                if ($scenarioBranchCode === '') {
                    $scenarioBranchCode = $branchCode;
                }
                $activeHouseholdProfile = af_household_profile_from_lei_payload($leiPayload);
            } else {
                // Default: use latest branch LEI from module 17 when available.
                $leiRows = af_fetch_lei_rows($branchCode, $scope);
                if ($leiRows !== []) {
                    $activeHouseholdProfile = af_household_profile_from_lei_payload((array)($leiRows[0]['payload'] ?? []));
                }
            }

            $scenarioBranchProfile = lei_fetch_branch_household_profile($scenarioBranchCode, $scope);
            $scenario = lei_scenario_assumption($scenarioCode, $scenarioBranchProfile);
            $scenario['household_profile'] = $activeHouseholdProfile;

            $result = af_calculate_result($customerPayload, $contractPayload, $scenario, $behavior);
            $forecast = af_monte_carlo_forecast($result, $scenario, $behavior, 1000, 18);

            $recordInput = [
                'case_no' => 'AF' . date('ymdHis') . random_int(100, 999),
                'customer_ref' => $customerCode,
                'monthly_income' => (string)$result['income_adjusted'],
                'expense_baseline' => (string)$result['household_expense'],
                'existing_debts' => (string)$result['existing_debt_monthly'],
                'dependents' => (string)max(0, (int)($customerPayload['dependents'] ?? 0)),
                'volatility_factor' => (string)round(1.0 + ((int)$behavior['issue_count'] * 0.03), 4),
                'stress_buffer_pct' => (string)round((float)($scenario['pd_shift_pct'] ?? 0.0), 2),
                'dti_result' => sprintf('DTI %.2f%% | %s', $result['dti'] * 100, $result['grade']),
                'max_installment' => (string)$result['max_installment'],
                'assessment_date' => date('Y-m-d'),
                'branch_code' => $branchCode,
                'primary_ref' => $customerCode,
                'primary_name' => (string)($contractPayload['customer_name'] ?? $customerCode),
                'record_status' => 'PENDING_CHECKER',
                'note_text' => 'Assessment from actual payment history + LEI + Monte Carlo 1000 runs',
                'risk_flags' => ((int)$behavior['issue_count'] > 5 ? 'HIGH_BEHAVIOR_RISK' : ''),
            ];

            $created = module_create_record($module, $recordInput, 'Affordability assessment from payment history and LEI');

            $contractReview = (array)($contractPayload['affordability_review'] ?? []);
            $contractReview['is_unlocked'] = false;
            $contractReview['last_assessed_at'] = now_dt();
            $contractReview['last_assessed_by'] = current_user_name();
            $contractReview['last_case_no'] = (string)($recordInput['case_no']);
            $contractReview['last_result'] = [
                'dti' => $result['dti'],
                'max_installment' => $result['max_installment'],
                'grade' => $result['grade'],
                'scenario' => (string)$scenario['code'],
                'lei_source_id' => $selectedLeiId,
                'behavior' => $behavior,
                'forecast' => $forecast,
            ];
            $contractPayload['affordability_review'] = $contractReview;
            hp_update_contract_payload($contractRow, $contractPayload, current_user_name());

            $evaluationResult = [
                'contract_no' => $contractNo,
                'customer_code' => $customerCode,
                'customer_name' => (string)($contractPayload['customer_name'] ?? '-'),
                'scenario' => $scenario,
                'result' => $result,
                'behavior' => $behavior,
                'record_id' => (int)($created['id'] ?? 0),
            ];

            foreach ($forecast as $point) {
                $forecastLabels[] = (string)$point['month_label'];
                $forecastValues[] = (float)$point['risk_pct'];
            }
            $flashType = 'success';
            $flashMessage = 'Assessment completed and saved successfully';
        }
    } catch (Throwable $e) {
        $flashType = 'danger';
        $flashMessage = 'Save failed: ' . $e->getMessage();
        if ($action === 'run_assessment') {
            $assessModalOpen = true;
            $assessModalContext['contract_no'] = trim((string)($_POST['contract_no'] ?? ''));
            $assessModalContext['branch_code'] = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
            $assessModalContext['customer_code'] = trim((string)($_POST['customer_code'] ?? ''));
            $assessModalContext['customer_name'] = trim((string)($_POST['customer_name'] ?? ''));
            $assessModalContext['lei_source_id'] = (int)($_POST['lei_source_id'] ?? 0);
            $assessModalContext['scenario_code'] = strtoupper(trim((string)($_POST['scenario_code'] ?? 'BASE')));
        }
    }
}

$contractCandidates = af_fetch_contract_candidates($scope, $filterBranch, $search);
$branchHouseholdProfiles = af_fetch_branch_household_profiles($scope);

$branchNameMap = [];
foreach ($branchRows as $b) {
    $bc = strtoupper(trim((string)($b['branch_code'] ?? '')));
    if ($bc === '') {
        continue;
    }
    $branchNameMap[$bc] = trim((string)($b['branch_name'] ?? ''));
}

$leiSourceOptions = af_fetch_lei_sources($scope, $branchNameMap);
$leiSourceById = [];
$leiSourceIdsByBranch = [];
$leiScenarioMap = [];
foreach ($leiSourceOptions as $src) {
    $srcId = (int)($src['id'] ?? 0);
    if ($srcId <= 0) {
        continue;
    }
    $srcBranch = strtoupper(trim((string)($src['branch_code'] ?? '')));
    $leiSourceById[$srcId] = $src;
    if ($srcBranch !== '') {
        if (!isset($leiSourceIdsByBranch[$srcBranch])) {
            $leiSourceIdsByBranch[$srcBranch] = [];
        }
        $leiSourceIdsByBranch[$srcBranch][] = $srcId;
    }
    $leiScenarioMap[(string)$srcId] = is_array($src['scenario_options'] ?? null)
        ? $src['scenario_options']
        : [];
}

$candidateBranches = [];
foreach ($contractCandidates as $row) {
    $bc = strtoupper(trim((string)($row['branch_code'] ?? '')));
    if ($bc !== '') {
        $candidateBranches[$bc] = true;
    }
}
if ($filterBranch !== '') {
    $candidateBranches[$filterBranch] = true;
}

$defaultScenarioOptions = lei_scenario_options_for_select(lei_default_scenarios());

$branchBaseMap = [];
foreach (array_keys($candidateBranches) as $bc) {
    $branchBaseMap[$bc] = isset($branchHouseholdProfiles[$bc]);
}

$leiSourceOptionsJson = json_encode(array_values(array_map(static function (array $src): array {
    return [
        'id' => (int)($src['id'] ?? 0),
        'branch_code' => (string)($src['branch_code'] ?? ''),
        'label' => (string)($src['label'] ?? ''),
    ];
}, $leiSourceOptions)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($leiSourceOptionsJson)) {
    $leiSourceOptionsJson = '[]';
}
$leiSourceIdsByBranchJson = json_encode($leiSourceIdsByBranch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($leiSourceIdsByBranchJson)) {
    $leiSourceIdsByBranchJson = '{}';
}
$leiScenarioMapJson = json_encode($leiScenarioMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($leiScenarioMapJson)) {
    $leiScenarioMapJson = '{}';
}
$defaultScenarioOptionsJson = json_encode($defaultScenarioOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($defaultScenarioOptionsJson)) {
    $defaultScenarioOptionsJson = '[]';
}
$branchBaseMapJson = json_encode($branchBaseMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($branchBaseMapJson)) {
    $branchBaseMapJson = '{}';
}
$assessModalContextJson = json_encode($assessModalContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($assessModalContextJson)) {
    $assessModalContextJson = '{}';
}

$pageTitle = $module['title'];
$currentModule = $moduleKey;
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/menu.php';
?>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h5 class="mb-1">Affordability / DTI Assessment</h5>
        <div class="text-muted small">Shows customers with payment-behavior risk (overdue/late/no payment/partial payment) based on module rules and still under review lock.</div>
    </div>
</div>

<?php if ($flashMessage !== null): ?>
<div class="alert alert-<?php echo h($flashType); ?>"><?php echo h($flashMessage); ?></div>
<?php endif; ?>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-3">
                <label class="form-label">Branch</label>
                <select class="form-select" name="branch_code">
                    <option value="">All accessible branches</option>
                    <?php foreach ($branchRows as $b): ?>
                        <?php if (!isset($accessibleSet[$b['branch_code']])) { continue; } ?>
                        <option value="<?php echo h($b['branch_code']); ?>" <?php echo $filterBranch === $b['branch_code'] ? 'selected' : ''; ?>><?php echo h($b['branch_code'] . ' - ' . $b['branch_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="q" value="<?php echo h($search); ?>" placeholder="Contract / Customer Code / Full Name">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Search</button>
                <a class="btn btn-outline-secondary" href="<?php echo h(app_base_url('modules/03_affordability_dti.php')); ?>">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle" id="affRiskTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Contract No.</th>
                        <th>Customer Code</th>
                        <th>Customer Name</th>
                        <th>Branch</th>
                        <th>Overdue</th>
                        <th>Late</th>
                        <th>No Payment</th>
                        <th>Partial / Mismatch</th>
                        <th>Total</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contractCandidates as $i => $row): ?>
                        <?php $bh = $row['behavior']; ?>
                        <tr>
                            <td><?php echo (int)($i + 1); ?></td>
                            <td><?php echo h($row['contract_no']); ?></td>
                            <td><?php echo h($row['customer_code']); ?></td>
                            <td><?php echo h($row['customer_name'] !== '' ? $row['customer_name'] : '-'); ?></td>
                            <td><?php echo h($row['branch_code']); ?></td>
                            <td><?php echo (int)$bh['overdue_count']; ?></td>
                            <td><?php echo (int)$bh['late_count']; ?></td>
                            <td><?php echo (int)($bh['no_pay_count'] ?? 0); ?></td>
                            <td><?php echo (int)$bh['partial_count']; ?></td>
                            <td><span class="badge text-bg-danger"><?php echo (int)$bh['issue_count']; ?></span></td>
                            <td class="text-nowrap">
                                <button type="button" class="btn btn-sm btn-primary btn-open-assess" onclick="openAssessModal(this); return false;" data-contract="<?php echo h($row['contract_no']); ?>" data-customer="<?php echo h($row['customer_code']); ?>" data-name="<?php echo h($row['customer_name']); ?>" data-branch="<?php echo h($row['branch_code']); ?>" data-overdue="<?php echo (int)$bh['overdue_count']; ?>" data-late="<?php echo (int)$bh['late_count']; ?>" data-no-pay="<?php echo (int)($bh['no_pay_count'] ?? 0); ?>" data-partial="<?php echo (int)$bh['partial_count']; ?>" data-issues="<?php echo (int)$bh['issue_count']; ?>">Assess</button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Confirm unlock from review list?');">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="unlock_review">
                                    <input type="hidden" name="contract_no" value="<?php echo h($row['contract_no']); ?>">
                                    <input type="hidden" name="branch_code" value="<?php echo h($filterBranch); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Unlock</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="assessModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" method="post">
      <div class="modal-header">
        <h5 class="modal-title">Affordability Assessment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" style="max-height:70vh; overflow-y:auto;">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="run_assessment">
        <input type="hidden" name="contract_no" id="assess_contract_no" value="">
        <input type="hidden" name="branch_code" id="assess_branch_code" value="">
        <input type="hidden" name="customer_code" id="assess_customer_code_hidden" value="">
        <input type="hidden" name="customer_name" id="assess_customer_name_hidden" value="">

        <div class="row g-2 mb-2">
            <div class="col-md-4"><label class="form-label">Contract No.</label><input class="form-control" id="assess_contract_display" readonly></div>
            <div class="col-md-4"><label class="form-label">Customer Code</label><input class="form-control" id="assess_customer_display" readonly></div>
            <div class="col-md-4"><label class="form-label">Customer Name</label><input class="form-control" id="assess_name_display" readonly></div>
        </div>
        <div class="row g-2 mb-2">
            <div class="col-md-6">
                <label class="form-label">LEI Source (Module 17)</label>
                <select class="form-select" name="lei_source_id" id="assess_lei_source_id">
                    <option value="0">Use latest LEI of this branch automatically</option>
                    <?php foreach ($leiSourceOptions as $src): ?>
                        <?php $srcId = (int)($src['id'] ?? 0); ?>
                        <option value="<?php echo $srcId; ?>" <?php echo $selectedLeiId === $srcId ? 'selected' : ''; ?>>
                            <?php echo h((string)($src['label'] ?? '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Scenario</label>
                <select class="form-select" name="scenario_code" id="assess_scenario_code">
                    <?php foreach ($defaultScenarioOptions as $opt): ?>
                        <option value="<?php echo h((string)$opt['code']); ?>" <?php echo (string)$opt['code'] === $scenarioCode ? 'selected' : ''; ?>><?php echo h((string)$opt['code'] . ' - ' . (string)$opt['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="alert alert-light border py-2 mb-0 small">
            <div id="assess_payment_behavior">Select a customer from the "Assess" button to load data</div>
            <div id="assess_lei_hint" class="text-muted">Branch LEI will be auto-loaded from module 17</div>
        </div>

        <?php if ($evaluationResult !== null): ?>
        <hr class="my-3">
        <h6 class="mb-2">Latest Assessment Result</h6>
        <div class="row g-2 mb-2">
            <div class="col-md-4"><div class="border rounded p-2"><small class="text-muted">DTI</small><div class="fw-semibold"><?php echo number_format(((float)$evaluationResult['result']['dti']) * 100, 2); ?>%</div></div></div>
            <div class="col-md-4"><div class="border rounded p-2"><small class="text-muted">Max Affordable / Month</small><div class="fw-semibold"><?php echo number_format((float)$evaluationResult['result']['max_installment'], 2); ?></div></div></div>
            <div class="col-md-4"><div class="border rounded p-2"><small class="text-muted">Assessment Grade</small><div class="fw-semibold"><?php echo h((string)$evaluationResult['result']['grade']); ?></div></div></div>
        </div>
        <div class="row g-2 mb-2">
            <div class="col-md-4"><div class="border rounded p-2"><small class="text-muted">Scenario</small><div><?php echo h((string)$evaluationResult['scenario']['code']); ?></div></div></div>
            <div class="col-md-4"><div class="border rounded p-2"><small class="text-muted">Total Risk Events</small><div><?php echo (int)$evaluationResult['behavior']['issue_count']; ?> times</div></div></div>
            <div class="col-md-4"><div class="border rounded p-2"><small class="text-muted">Saved as Case</small><div>#<?php echo (int)$evaluationResult['record_id']; ?></div></div></div>
        </div>
        <div class="border rounded p-2">
            <canvas id="riskForecastChartModal" height="120"></canvas>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary" type="submit">Run Assessment (Monte Carlo 1000 runs)</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function(){
    function initRiskTable() {
        if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.DataTable) {
            return;
        }
        const table = window.jQuery('#affRiskTable');
        if (!table.length) {
            return;
        }
        if (window.jQuery.fn.dataTable && window.jQuery.fn.dataTable.isDataTable && window.jQuery.fn.dataTable.isDataTable(table)) {
            return;
        }
        table.DataTable({
            pageLength: 50,
            lengthChange: false,
            order: [[0, 'asc']],
            language: {
                search: 'Search:',
                zeroRecords: 'No records found',
                paginate: { previous: 'Previous', next: 'Next' },
                info: 'Showing _START_ to _END_ of _TOTAL_ entries',
            }
        });
    }

    if (document.readyState === 'complete') {
        initRiskTable();
    } else {
        window.addEventListener('load', initRiskTable);
    }

    const leiSourceOptions = <?php echo $leiSourceOptionsJson; ?> || [];
    const leiSourceIdsByBranch = <?php echo $leiSourceIdsByBranchJson; ?> || {};
    const leiScenarioMap = <?php echo $leiScenarioMapJson; ?> || {};
    const defaultScenarioOptions = <?php echo $defaultScenarioOptionsJson; ?> || [];
    const branchHasBaseMap = <?php echo $branchBaseMapJson; ?> || {};
    const shouldOpenAssessModal = <?php echo $assessModalOpen ? 'true' : 'false'; ?>;
    const assessModalContext = <?php echo $assessModalContextJson; ?> || {};

    function buildScenarioLabel(row) {
        const code = (row && row.code) ? String(row.code) : 'BASE';
        const label = (row && row.label) ? String(row.label) : code;
        return code + ' - ' + label;
    }

    function renderLeiSourceOptions(branchCode, preferredSourceId) {
        const leiSelect = document.getElementById('assess_lei_source_id');
        if (!leiSelect) {
            return;
        }
        leiSelect.innerHTML = '';

        const baseOpt = document.createElement('option');
        baseOpt.value = '0';
        baseOpt.textContent = 'Use latest LEI of this branch automatically';
        leiSelect.appendChild(baseOpt);

        leiSourceOptions.forEach((row) => {
            const opt = document.createElement('option');
            opt.value = String(row.id || 0);
            opt.textContent = String(row.label || '-');
            leiSelect.appendChild(opt);
        });

        const preferred = parseInt(preferredSourceId || '0', 10) || 0;
        const branchIds = Array.isArray(leiSourceIdsByBranch[branchCode]) ? leiSourceIdsByBranch[branchCode] : [];
        if (preferred > 0 && Array.from(leiSelect.options).some((x) => parseInt(x.value, 10) === preferred)) {
            leiSelect.value = String(preferred);
        } else if (branchIds.length > 0) {
            leiSelect.value = String(branchIds[0]);
        } else {
            leiSelect.value = '0';
        }
    }

    function renderScenarioOptionsByLeiSource(sourceId, preferredScenario) {
        const scenarioSelect = document.getElementById('assess_scenario_code');
        if (!scenarioSelect) {
            return;
        }
        const current = preferredScenario || scenarioSelect.value || 'BASE';
        const key = String(parseInt(sourceId || '0', 10) || 0);
        const rows = Array.isArray(leiScenarioMap[key]) && leiScenarioMap[key].length > 0 ? leiScenarioMap[key] : defaultScenarioOptions;
        scenarioSelect.innerHTML = '';
        rows.forEach((row) => {
            const opt = document.createElement('option');
            opt.value = String(row.code || 'BASE');
            opt.textContent = buildScenarioLabel(row);
            scenarioSelect.appendChild(opt);
        });
        scenarioSelect.value = Array.from(scenarioSelect.options).some((x) => x.value === current) ? current : 'BASE';
    }

    function setModalFromButton(btn) {
        if (!btn) {
            return;
        }
        const d = btn.dataset || {};
        const branchCode = (d.branch || '').toUpperCase();
        document.getElementById('assess_contract_no').value = d.contract || '';
        document.getElementById('assess_branch_code').value = branchCode;
        document.getElementById('assess_contract_display').value = d.contract || '';
        document.getElementById('assess_customer_display').value = d.customer || '';
        document.getElementById('assess_name_display').value = d.name || '';
        document.getElementById('assess_customer_code_hidden').value = d.customer || '';
        document.getElementById('assess_customer_name_hidden').value = d.name || '';

        renderLeiSourceOptions(branchCode, d.leiSourceId || '0');
        const leiSelect = document.getElementById('assess_lei_source_id');
        renderScenarioOptionsByLeiSource(leiSelect ? leiSelect.value : '0', d.scenario || 'BASE');

        const overdue = parseInt(d.overdue || '0', 10) || 0;
        const late = parseInt(d.late || '0', 10) || 0;
        const noPay = parseInt(d.noPay || '0', 10) || 0;
        const partial = parseInt(d.partial || '0', 10) || 0;
        const issues = parseInt(d.issues || '0', 10) || 0;
        const behaviorEl = document.getElementById('assess_payment_behavior');
        if (behaviorEl) {
            behaviorEl.textContent = 'Payment behavior: overdue ' + overdue + ' | late ' + late + ' | no payment ' + noPay + ' | partial/mismatch ' + partial + ' | total ' + issues;
        }

        const hasBase = !!branchHasBaseMap[branchCode];
        const leiCount = (leiSourceIdsByBranch[branchCode] || []).length;
        const leiHintEl = document.getElementById('assess_lei_hint');
        if (leiHintEl) {
            if (leiCount > 0) {
                leiHintEl.textContent = 'Branch ' + branchCode + ' has ' + leiCount + ' LEI record(s) from module 17';
            } else if (hasBase) {
                leiHintEl.textContent = 'Branch ' + branchCode + ' has no LEI in module 17. System will use expense baseline from master_branch';
            } else {
                leiHintEl.textContent = 'Branch ' + branchCode + ' has no LEI in module 17 and no master_branch baseline. System will use default per-person monthly cost';
            }
        }
    }

    function showModalFallback(modalEl) {
        if (!modalEl) {
            return;
        }
        modalEl.style.display = 'block';
        modalEl.classList.add('show');
        modalEl.setAttribute('aria-modal', 'true');
        modalEl.removeAttribute('aria-hidden');
        document.body.classList.add('modal-open');

        let backdrop = document.getElementById('assessModalBackdrop');
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.id = 'assessModalBackdrop';
            backdrop.className = 'modal-backdrop fade show';
            backdrop.addEventListener('click', function () {
                hideModalFallback(modalEl);
            });
            document.body.appendChild(backdrop);
        }
    }

    function hideModalFallback(modalEl) {
        if (!modalEl) {
            return;
        }
        modalEl.classList.remove('show');
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.style.display = 'none';
        document.body.classList.remove('modal-open');
        const backdrop = document.getElementById('assessModalBackdrop');
        if (backdrop && backdrop.parentNode) {
            backdrop.parentNode.removeChild(backdrop);
        }
    }

    const assessModalEl = document.getElementById('assessModal');
    if (assessModalEl) {
        const leiSelectEl = document.getElementById('assess_lei_source_id');
        if (leiSelectEl) {
            leiSelectEl.addEventListener('change', function () {
                renderScenarioOptionsByLeiSource(this.value, 'BASE');
            });
        }
        assessModalEl.querySelectorAll('[data-bs-dismiss="modal"]').forEach((el) => {
            el.addEventListener('click', function () {
                hideModalFallback(assessModalEl);
            });
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                hideModalFallback(assessModalEl);
            }
        });
    }

    window.openAssessModal = function (btn) {
        setModalFromButton(btn);
        const modalEl = document.getElementById('assessModal');
        if (!modalEl) {
            return;
        }

        if (window.bootstrap && window.bootstrap.Modal) {
            try {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
                return;
            } catch (e) {
                showModalFallback(modalEl);
                return;
            }
        }

        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
            try {
                window.jQuery(modalEl).modal('show');
                return;
            } catch (e) {
                showModalFallback(modalEl);
                return;
            }
        }

        showModalFallback(modalEl);
    };

    if (shouldOpenAssessModal) {
        const seed = {
            dataset: {
                contract: String(assessModalContext.contract_no || ''),
                branch: String(assessModalContext.branch_code || ''),
                customer: String(assessModalContext.customer_code || ''),
                name: String(assessModalContext.customer_name || ''),
                overdue: String(assessModalContext.overdue_count || 0),
                late: String(assessModalContext.late_count || 0),
                noPay: String(assessModalContext.no_pay_count || 0),
                partial: String(assessModalContext.partial_count || 0),
                issues: String(assessModalContext.issue_count || 0),
                leiSourceId: String(assessModalContext.lei_source_id || 0),
                scenario: String(assessModalContext.scenario_code || 'BASE'),
            }
        };
        window.openAssessModal(seed);
    }

    <?php if ($evaluationResult !== null): ?>
    const ctx = document.getElementById('riskForecastChartModal');
    if (ctx) {
        const labels = <?php echo json_encode($forecastLabels, JSON_UNESCAPED_UNICODE); ?>;
        const vals = <?php echo json_encode($forecastValues, JSON_UNESCAPED_UNICODE); ?>;
        new Chart(ctx, {
            type: 'line',
            data: { labels: labels, datasets: [{ label: 'Projected Default Risk (%)', data: vals, borderColor: '#0d6efd', tension: 0.25, fill: false }] },
            options: { responsive: true, scales: { y: { beginAtZero: true, max: 100 } } }
        });
    }
    <?php endif; ?>
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>

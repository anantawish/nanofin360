<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/hire_purchase_core.php';
require_once __DIR__ . '/../lib/lei_scenarios.php';

$moduleKey = 'npl_recovery';
$module = module_by_key($moduleKey);
$scope = current_access_scope();

/**
 * @param array<int, array<string,mixed>> $history
 * @return array{
 *   trailing_unpaid_streak:int,
 *   max_unpaid_streak:int,
 *   trailing_no_pay_streak:int,
 *   max_no_pay_streak:int,
 *   eligibility_unpaid_streak:int,
 *   due_installments:int,
 *   paid_installments:int,
 *   unpaid_installments:int,
 *   outstanding_amount:float,
 *   last_due_date:string
 * }
 */
function nplr_history_metrics(array $history, string $asOfDate): array
{
    $rows = [];
    foreach ($history as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    usort($rows, static function (array $a, array $b): int {
        $noA = (int)($a['installment_no'] ?? 0);
        $noB = (int)($b['installment_no'] ?? 0);
        if ($noA !== $noB) {
            return $noA <=> $noB;
        }
        return strcmp((string)($a['due_date'] ?? ''), (string)($b['due_date'] ?? ''));
    });

    $asOf = hp_parse_date($asOfDate, date('Y-m-d'));
    $trailing = 0;
    $maxStreak = 0;
    $dueInstallments = 0;
    $paidInstallments = 0;
    $unpaidInstallments = 0;
    $outstanding = 0.0;
    $lastDueDate = '';
    $trailingNoPay = 0;
    $maxNoPay = 0;
    $currentNoPay = 0;

    foreach ($rows as $row) {
        $dueDate = trim((string)($row['due_date'] ?? ''));
        if ($dueDate === '' || $dueDate > $asOf) {
            continue;
        }
        $lastDueDate = $dueDate;
        $dueInstallments++;

        $status = strtoupper(trim((string)($row['payment_status'] ?? 'UNPAID')));
        $due = round(max(0.0, hp_float($row['installment_due'] ?? 0)), 2);
        $paid = round(max(0.0, hp_float($row['paid_amount'] ?? 0)), 2);
        $remain = round(max(0.0, $due - $paid), 2);

        if ($status === 'PAID') {
            $paidInstallments++;
            $trailing = 0;
        } else {
            $unpaidInstallments++;
            $trailing++;
            if ($remain > 0) {
                $outstanding += $remain;
            }
            if ($trailing > $maxStreak) {
                $maxStreak = $trailing;
            }
        }
    }

    // Respect explicit NO_PAY marks even if installment due date is in future.
    foreach ($rows as $row) {
        $status = strtoupper(trim((string)($row['payment_status'] ?? 'UNPAID')));
        if ($status === 'NO_PAY') {
            $currentNoPay++;
            if ($currentNoPay > $maxNoPay) {
                $maxNoPay = $currentNoPay;
            }
        } else {
            $currentNoPay = 0;
        }
    }
    $trailingNoPay = $currentNoPay;
    $eligibilityStreak = max($trailing, $maxNoPay);

    return [
        'trailing_unpaid_streak' => $trailing,
        'max_unpaid_streak' => $maxStreak,
        'trailing_no_pay_streak' => $trailingNoPay,
        'max_no_pay_streak' => $maxNoPay,
        'eligibility_unpaid_streak' => $eligibilityStreak,
        'due_installments' => $dueInstallments,
        'paid_installments' => $paidInstallments,
        'unpaid_installments' => $unpaidInstallments,
        'outstanding_amount' => round($outstanding, 2),
        'last_due_date' => $lastDueDate,
    ];
}

function nplr_annuity_payment(float $principal, float $annualRatePct, int $months): float
{
    $principal = max(0.0, $principal);
    $months = max(1, $months);
    if ($principal <= 0.0) {
        return 0.0;
    }

    $monthlyRate = max(0.0, $annualRatePct) / 100.0 / 12.0;
    if ($monthlyRate <= 0.0) {
        return round($principal / $months, 2);
    }

    $pow = pow(1.0 + $monthlyRate, $months);
    if ($pow <= 1.0) {
        return round($principal / $months, 2);
    }

    $payment = $principal * (($monthlyRate * $pow) / ($pow - 1.0));
    return round($payment, 2);
}

function nplr_rand_normal(): float
{
    $u1 = max(1.0E-12, mt_rand() / mt_getrandmax());
    $u2 = max(1.0E-12, mt_rand() / mt_getrandmax());
    return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
}

function nplr_clamp(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @param array<string,string> $branchNameMap
 * @return array<int, array{
 *   id:int,
 *   report_no:string,
 *   branch_code:string,
 *   branch_name:string,
 *   label:string,
 *   scenario_options:array<int, array{code:string,label:string,cost_multiplier:float,income_multiplier:float,pd_shift_pct:float,npl_shift_pct:float,description:string}>
 * }>
 */
function nplr_fetch_lei_sources(array $scope, array $branchNameMap): array
{
    $scopeClause = access_scope_sql_clause('w.branch_code', 'npl_lei_source_scope', $scope);
    $sql = '
        SELECT w.id, w.primary_ref, w.branch_code, w.data_json, w.updated_at, w.created_at
        FROM workflow_records w
        WHERE w.module_key = "local_economy_lei"
          AND w.is_latest = 1
          AND w.is_deleted = 0
          ' . $scopeClause['sql'] . '
        ORDER BY w.updated_at DESC, w.id DESC
        LIMIT 300
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

        $reportNo = trim((string)($payload['lei_report_no'] ?? $row['primary_ref'] ?? ('LEI-' . (int)($row['id'] ?? 0))));
        $branchName = trim((string)($branchNameMap[$branchCode] ?? ''));
        $branchProfile = lei_fetch_branch_household_profile($branchCode, $scope);
        $scenarioOptions = lei_scenario_options_for_select(lei_branch_scenarios($branchProfile));

        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'report_no' => $reportNo,
            'branch_code' => $branchCode,
            'branch_name' => $branchName,
            'label' => $reportNo . ' | ' . $branchCode . ($branchName !== '' ? (' - ' . $branchName) : ''),
            'scenario_options' => $scenarioOptions,
        ];
    }

    return $rows;
}

function nplr_first_numeric_value(array $row, array $keys): float
{
    foreach ($keys as $k) {
        if (!array_key_exists($k, $row)) {
            continue;
        }
        $v = hp_float($row[$k]);
        if ($v > 0) {
            return (float)$v;
        }
    }
    return 0.0;
}

function nplr_sum_json_asset_values(array $items, array $valueKeys): float
{
    $sum = 0.0;
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $sum += nplr_first_numeric_value($it, $valueKeys);
    }
    return round(max(0.0, $sum), 2);
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @return array{
 *   houses_count:int,houses_value:float,
 *   lands_count:int,lands_value:float,
 *   cars_count:int,cars_value:float,
 *   guarantors_count:int
 * }
 */
function nplr_customer360_snapshot(string $customerCode, array $scope): array
{
    $blank = [
        'houses_count' => 0,
        'houses_value' => 0.0,
        'lands_count' => 0,
        'lands_value' => 0.0,
        'cars_count' => 0,
        'cars_value' => 0.0,
        'guarantors_count' => 0,
    ];
    $customerCode = strtoupper(trim($customerCode));
    if ($customerCode === '') {
        return $blank;
    }

    $scopeClause = access_scope_sql_clause('w.branch_code', 'npl_cus360_scope', $scope);
    $sql = '
        SELECT w.data_json
        FROM workflow_records w
        WHERE w.module_key = "customer_360"
          AND w.primary_ref = :customer_ref
          AND w.is_latest = 1
          AND w.is_deleted = 0
          ' . $scopeClause['sql'] . '
        ORDER BY w.id DESC
        LIMIT 1
    ';
    $params = $scopeClause['params'];
    $params[':customer_ref'] = $customerCode;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return $blank;
    }

    $payload = json_decode((string)($row['data_json'] ?? ''), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $houses = is_array($payload['houses'] ?? null) ? $payload['houses'] : [];
    $lands = is_array($payload['lands'] ?? null) ? $payload['lands'] : [];
    $cars = is_array($payload['cars'] ?? null) ? $payload['cars'] : [];
    $guarantors = is_array($payload['guarantors'] ?? null) ? $payload['guarantors'] : [];
    $collateralAssets = is_array($payload['collateral_assets'] ?? null) ? $payload['collateral_assets'] : [];

    $houseValue = nplr_sum_json_asset_values($houses, ['appraisal', 'appraised_value', 'asset_value', 'estimated_value', 'value']);
    $landValue = nplr_sum_json_asset_values($lands, ['appraisal', 'appraised_value', 'asset_value', 'estimated_value', 'value']);
    $carValue = nplr_sum_json_asset_values($cars, ['appraisal', 'appraised_value', 'asset_value', 'estimated_value', 'value', 'market_value']);

    foreach ($collateralAssets as $asset) {
        if (!is_array($asset)) {
            continue;
        }
        $type = trim((string)($asset['collateral_type'] ?? ''));
        $value = nplr_first_numeric_value($asset, ['appraisal_value', 'appraisal', 'asset_value', 'estimated_value', 'value']);
        if ($value <= 0) {
            continue;
        }
        if (mb_strpos($type, 'house', 0, 'UTF-8') !== false) {
            $houseValue += $value;
        } elseif (mb_strpos($type, 'land', 0, 'UTF-8') !== false) {
            $landValue += $value;
        } elseif (mb_strpos($type, 'car', 0, 'UTF-8') !== false) {
            $carValue += $value;
        }
    }

    return [
        'houses_count' => count($houses),
        'houses_value' => round(max(0.0, $houseValue), 2),
        'lands_count' => count($lands),
        'lands_value' => round(max(0.0, $landValue), 2),
        'cars_count' => count($cars),
        'cars_value' => round(max(0.0, $carValue), 2),
        'guarantors_count' => count($guarantors),
    ];
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @return array<string,mixed>
 */
function nplr_customer360_payload(string $customerCode, array $scope): array
{
    $customerCode = strtoupper(trim($customerCode));
    if ($customerCode === '') {
        return [];
    }

    $scopeClause = access_scope_sql_clause('w.branch_code', 'npl_cus360_profile_scope', $scope);
    $sql = '
        SELECT w.data_json
        FROM workflow_records w
        WHERE w.module_key = "customer_360"
          AND w.primary_ref = :customer_ref
          AND w.is_latest = 1
          AND w.is_deleted = 0
          ' . $scopeClause['sql'] . '
        ORDER BY w.id DESC
        LIMIT 1
    ';
    $params = $scopeClause['params'];
    $params[':customer_ref'] = $customerCode;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return [];
    }

    $payload = json_decode((string)($row['data_json'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

function nplr_asset_type_code(string $text): string
{
    $t = strtoupper(trim($text));
    if ($t === '') {
        return 'OTHER';
    }
    if (str_contains($t, 'HOUSE') || str_contains($t, 'HOME') || str_contains($t, 'BAN')) {
        return 'HOUSE';
    }
    if (str_contains($t, 'LAND') || str_contains($t, 'DIN')) {
        return 'LAND';
    }
    if (str_contains($t, 'CAR') || str_contains($t, 'AUTO') || str_contains($t, 'TRUCK') || str_contains($t, 'ROD')) {
        return 'CAR';
    }
    if (str_contains($t, 'MOTOR') || str_contains($t, 'BIKE') || str_contains($t, 'MOTORCYCLE')) {
        return 'MOTORCYCLE';
    }
    return 'OTHER';
}

function nplr_asset_type_label(string $typeCode): string
{
    return match (strtoupper(trim($typeCode))) {
        'HOUSE' => 'house',
        'LAND' => 'land',
        'CAR' => 'car',
        'MOTORCYCLE' => 'motorcycle',
        default => 'other',
    };
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @return array{
 *   snapshot:array<string,mixed>,
 *   collateral_items:array<int,array<string,mixed>>,
 *   guarantor_items:array<int,array<string,mixed>>
 * }
 */
function nplr_customer360_profile(string $customerCode, array $scope): array
{
    $snapshot = nplr_customer360_snapshot($customerCode, $scope);
    $payload = nplr_customer360_payload($customerCode, $scope);

    $collateralItems = [];
    $guarantorItems = [];

    $houses = is_array($payload['houses'] ?? null) ? $payload['houses'] : [];
    foreach ($houses as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $value = nplr_first_numeric_value($row, ['appraisal', 'appraised_value', 'asset_value', 'estimated_value', 'value']);
        $ref = trim((string)($row['deed_no'] ?? ''));
        $location = trim(implode(' ', array_filter([
            trim((string)($row['district'] ?? '')),
            trim((string)($row['province'] ?? '')),
        ])));
        $collateralItems[] = [
            'id' => 'HOUSE-' . ((int)$i + 1),
            'source' => 'customer360',
            'type_code' => 'HOUSE',
            'type_label' => nplr_asset_type_label('HOUSE'),
            'reference' => $ref,
            'location' => $location,
            'value' => round(max(0.0, $value), 2),
            'raw' => $row,
        ];
    }

    $lands = is_array($payload['lands'] ?? null) ? $payload['lands'] : [];
    foreach ($lands as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $value = nplr_first_numeric_value($row, ['appraisal', 'appraised_value', 'asset_value', 'estimated_value', 'value']);
        $ref = trim((string)($row['deed_no'] ?? ''));
        $location = trim(implode(' ', array_filter([
            trim((string)($row['district'] ?? '')),
            trim((string)($row['province'] ?? '')),
        ])));
        $collateralItems[] = [
            'id' => 'LAND-' . ((int)$i + 1),
            'source' => 'customer360',
            'type_code' => 'LAND',
            'type_label' => nplr_asset_type_label('LAND'),
            'reference' => $ref,
            'location' => $location,
            'value' => round(max(0.0, $value), 2),
            'raw' => $row,
        ];
    }

    $cars = is_array($payload['cars'] ?? null) ? $payload['cars'] : [];
    foreach ($cars as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $value = nplr_first_numeric_value($row, ['appraisal', 'appraised_value', 'asset_value', 'estimated_value', 'value', 'market_value']);
        $ref = trim((string)($row['plate_no'] ?? ''));
        $location = trim((string)($row['plate_province'] ?? ''));
        if ($ref === '') {
            $ref = trim(implode(' ', array_filter([
                trim((string)($row['brand_name'] ?? '')),
                trim((string)($row['model_name'] ?? '')),
                trim((string)($row['model_year'] ?? '')),
            ])));
        }
        $collateralItems[] = [
            'id' => 'CAR-' . ((int)$i + 1),
            'source' => 'customer360',
            'type_code' => 'CAR',
            'type_label' => nplr_asset_type_label('CAR'),
            'reference' => $ref,
            'location' => $location,
            'value' => round(max(0.0, $value), 2),
            'raw' => $row,
        ];
    }

    $collateralAssets = is_array($payload['collateral_assets'] ?? null) ? $payload['collateral_assets'] : [];
    foreach ($collateralAssets as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $typeText = trim((string)($row['collateral_type'] ?? ''));
        $typeCode = nplr_asset_type_code($typeText);
        $value = nplr_first_numeric_value($row, ['appraisal_value', 'appraisal', 'appraised_value', 'asset_value', 'estimated_value', 'value']);
        $ref = trim((string)($row['asset_ref_no'] ?? ''));
        $location = trim(implode(' ', array_filter([
            trim((string)($row['district'] ?? '')),
            trim((string)($row['province'] ?? '')),
        ])));
        $collateralItems[] = [
            'id' => 'ASSET-' . ((int)$i + 1),
            'source' => 'customer360',
            'type_code' => $typeCode,
            'type_label' => $typeText !== '' ? $typeText : nplr_asset_type_label($typeCode),
            'reference' => $ref,
            'location' => $location,
            'value' => round(max(0.0, $value), 2),
            'raw' => $row,
        ];
    }

    $guarantors = is_array($payload['guarantors'] ?? null) ? $payload['guarantors'] : [];
    foreach ($guarantors as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $fullName = trim(implode(' ', array_filter([
            trim((string)($row['first_name'] ?? '')),
            trim((string)($row['last_name'] ?? '')),
        ])));
        $guarantorItems[] = [
            'id' => 'GUA-' . ((int)$i + 1),
            'source' => 'customer360',
            'full_name' => $fullName,
            'phone' => trim((string)($row['phone_number'] ?? '')),
            'relation' => trim((string)($row['relation'] ?? '')),
            'address' => trim((string)($row['id_card_address'] ?? '')),
            'raw' => $row,
        ];
    }

    return [
        'snapshot' => $snapshot,
        'collateral_items' => $collateralItems,
        'guarantor_items' => $guarantorItems,
    ];
}

/**
 * @param array<string,mixed> $contract
 * @param array<string,mixed> $plan
 * @param array<string,mixed> $leiAssumption
 * @return array<string,mixed>
 */
function nplr_run_bayesian_mc(array $contract, array $plan, array $leiAssumption): array
{
    $payload = is_array($contract['payload'] ?? null) ? $contract['payload'] : [];
    $history = $payload['payment_history'] ?? [];
    if (!is_array($history)) {
        $history = [];
    }

    $metrics = nplr_history_metrics($history, date('Y-m-d'));
    $successObs = (int)$metrics['paid_installments'];
    $failureObs = (int)$metrics['unpaid_installments'];

    $alpha = 1.0 + max(0, $successObs);
    $beta = 1.0 + max(0, $failureObs);

    $betaMean = $alpha / ($alpha + $beta);
    $betaVar = ($alpha * $beta) / (pow($alpha + $beta, 2) * ($alpha + $beta + 1.0));
    $betaStd = sqrt(max(0.000001, $betaVar));

    $principal = round(max(0.0, hp_float($payload['approved_loan_amount'] ?? $contract['principal_amount'] ?? 0)), 2);
    $oldRate = round(max(0.0, hp_float($payload['annual_rate_pct'] ?? 12.0)), 4);
    $oldTerm = max(1, (int)hp_int($payload['term_months'] ?? 24));
    $newRate = round(max(0.0, hp_float($plan['new_interest_rate_pct'] ?? $oldRate)), 4);
    $newTerm = max(1, (int)hp_int($plan['new_tenor_month'] ?? $oldTerm));
    $graceMonths = max(0, min(12, (int)hp_int($plan['grace_months'] ?? 0)));
    $interestOnlyMonths = max(0, min(12, (int)hp_int($plan['interest_only_months'] ?? 0)));
    $addCollateralValue = round(max(0.0, hp_float($plan['add_collateral_value'] ?? 0)), 2);
    $addGuarantorCount = max(0, min(20, (int)hp_int($plan['add_guarantor_count'] ?? 0)));
    $rounds = 1000;

    $oldInstallment = nplr_annuity_payment($principal, $oldRate, $oldTerm);
    $newInstallment = nplr_annuity_payment($principal, $newRate, $newTerm);
    if ($oldInstallment <= 0.0) {
        $oldInstallment = max(1.0, $newInstallment);
    }

    $installmentRelief = ($oldInstallment > 0) ? (($oldInstallment - $newInstallment) / $oldInstallment) : 0.0;
    $interestDelta = $oldRate - $newRate;

    $costMul = (float)($leiAssumption['cost_multiplier'] ?? 1.0);
    $incomeMul = (float)($leiAssumption['income_multiplier'] ?? 1.0);
    $pdShiftPct = (float)($leiAssumption['pd_shift_pct'] ?? 0.0);

    $scoreAdjust = 0.0;
    $scoreAdjust += 0.018 * $interestDelta;
    $scoreAdjust += 0.22 * $installmentRelief;
    $scoreAdjust += 0.010 * min(6, $graceMonths);
    $scoreAdjust += 0.008 * min(6, $interestOnlyMonths);
    $scoreAdjust += 0.006 * min(3, $addGuarantorCount);
    $scoreAdjust += min(0.08, $addCollateralValue / 1000000.0 * 0.02);
    $scoreAdjust += 0.12 * ($incomeMul - 1.0);
    $scoreAdjust -= 0.16 * ($costMul - 1.0);
    $scoreAdjust -= ($pdShiftPct / 100.0) * 0.8;

    $successCase = 0;
    $sumPaidRequired = 0.0;

    for ($i = 0; $i < $rounds; $i++) {
        $betaDraw = nplr_clamp($betaMean + ($betaStd * nplr_rand_normal()), 0.02, 0.98);
        $paidRequired = 0;
        $requiredMonths = max(1, 12 - $graceMonths);
        $missStreak = 0;
        $maxMissStreak = 0;

        for ($m = 1; $m <= 12; $m++) {
            if ($m <= $graceMonths) {
                continue;
            }

            $monthlyNoise = 0.03 * nplr_rand_normal();
            $monthlyP = nplr_clamp($betaDraw + $scoreAdjust + $monthlyNoise, 0.01, 0.99);
            $isPaid = (mt_rand(1, 1000000) / 1000000) <= $monthlyP;

            if ($isPaid) {
                $paidRequired++;
                $missStreak = 0;
            } else {
                $missStreak++;
                if ($missStreak > $maxMissStreak) {
                    $maxMissStreak = $missStreak;
                }
            }
        }

        $sumPaidRequired += $paidRequired;
        $paidRatio = $paidRequired / max(1, $requiredMonths);
        $isSuccess = $paidRatio >= 0.75 && $maxMissStreak < 3;
        if ($isSuccess) {
            $successCase++;
        }
    }

    $probabilityPct = round(($successCase / max(1, $rounds)) * 100.0, 2);
    $expectedPaidMonths = round($sumPaidRequired / max(1, $rounds), 2);

    return [
        'probability_pct' => $probabilityPct,
        'expected_paid_months' => $expectedPaidMonths,
        'beta_mean' => round($betaMean, 4),
        'old_installment' => $oldInstallment,
        'new_installment' => $newInstallment,
        'score_adjust' => round($scoreAdjust, 4),
        'rounds' => $rounds,
    ];
}

/**
 * @param array<string,mixed> $plan
 * @param array<string,mixed> $sim
 * @param array<string,mixed> $leiAssumption
 * @param array<string,mixed>|null $branchProfile
 * @return array<string,mixed>
 */
function nplr_damage_index(array $plan, array $sim, array $leiAssumption, ?array $branchProfile = null): array
{
    $successProb = nplr_clamp(((float)($sim['probability_pct'] ?? 0.0)) / 100.0, 0.0, 1.0);
    $defaultProb = 1.0 - $successProb;
    $outstanding = round(max(0.0, hp_float($plan['outstanding_amount'] ?? 0)), 2);

    $costMul = max(0.5, (float)($leiAssumption['cost_multiplier'] ?? 1.0));
    $nplShiftPct = (float)($leiAssumption['npl_shift_pct'] ?? 0.0);
    $householdIndex = max(0.5, (float)($branchProfile['index_value'] ?? 1.0));
    $householdBaseline = round(max(0.0, hp_float($branchProfile['baseline_monthly'] ?? 0)), 2);
    $scenarioCode = strtoupper(trim((string)($plan['lei_scenario'] ?? 'BASE')));

    $scenarioWeightMap = [
        'VERY_GOOD' => 0.85,
        'GOOD' => 0.92,
        'BASE' => 1.00,
        'WATCH' => 1.08,
        'STRESS' => 1.18,
        'SEVERE' => 1.30,
    ];
    $scenarioWeight = (float)($scenarioWeightMap[$scenarioCode] ?? 1.0);

    $householdPressure = nplr_clamp(1.0 + (($householdIndex - 1.0) * 0.35), 0.75, 1.40);
    $legalRate = nplr_clamp(0.06 * $costMul * $householdPressure * $scenarioWeight, 0.02, 0.20);
    $legalFixed = round(max(0.0, 1500.0 * $costMul * $householdPressure * $scenarioWeight), 2);
    $legalCost = round(max(0.0, ($outstanding * $legalRate) + $legalFixed), 2);

    $manualNumericCollateral = round(max(0.0, hp_float($plan['manual_add_collateral_value'] ?? 0)), 2);
    $selectedCollateral = round(max(0.0, hp_float($plan['selected_customer_collateral_value'] ?? 0)), 2);

    $houseSelected = 0.0;
    $landSelected = 0.0;
    $carSelected = 0.0;
    $selectedOther = 0.0;
    $selectedRows = is_array($plan['customer360_collateral_selected'] ?? null) ? $plan['customer360_collateral_selected'] : [];
    if ($selectedRows !== []) {
        foreach ($selectedRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $v = round(max(0.0, hp_float($row['value'] ?? 0)), 2);
            if ($v <= 0.0) {
                continue;
            }
            $typeCode = strtoupper(trim((string)($row['type_code'] ?? 'OTHER')));
            if ($typeCode === 'HOUSE') {
                $houseSelected += $v;
            } elseif ($typeCode === 'LAND') {
                $landSelected += $v;
            } elseif ($typeCode === 'CAR') {
                $carSelected += $v;
            } else {
                $selectedOther += $v;
            }
        }
    } else {
        $snapshot = is_array($plan['customer_snapshot'] ?? null) ? $plan['customer_snapshot'] : [];
        $houseSelected = !empty($plan['use_house_collateral']) ? round(max(0.0, hp_float($snapshot['houses_value'] ?? 0)), 2) : 0.0;
        $landSelected = !empty($plan['use_land_collateral']) ? round(max(0.0, hp_float($snapshot['lands_value'] ?? 0)), 2) : 0.0;
        $carSelected = !empty($plan['use_car_collateral']) ? round(max(0.0, hp_float($snapshot['cars_value'] ?? 0)), 2) : 0.0;
        $selectedTyped = round($houseSelected + $landSelected + $carSelected, 2);
        $selectedOther = round(max(0.0, $selectedCollateral - $selectedTyped), 2);
    }

    $addedRows = is_array($plan['npl_added_collateral_rows'] ?? null) ? $plan['npl_added_collateral_rows'] : [];
    $manualHouse = 0.0;
    $manualLand = 0.0;
    $manualCar = 0.0;
    $manualOtherTyped = 0.0;
    foreach ($addedRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $v = round(max(0.0, hp_float($row['value'] ?? 0)), 2);
        if ($v <= 0.0) {
            continue;
        }
        $typeCode = strtoupper(trim((string)($row['type_code'] ?? 'OTHER')));
        if ($typeCode === 'HOUSE') {
            $manualHouse += $v;
        } elseif ($typeCode === 'LAND') {
            $manualLand += $v;
        } elseif ($typeCode === 'CAR') {
            $manualCar += $v;
        } else {
            $manualOtherTyped += $v;
        }
    }

    $manualCollateral = round(max(0.0, $manualNumericCollateral + $manualHouse + $manualLand + $manualCar + $manualOtherTyped), 2);
    $totalCollateral = round(max(0.0, $manualCollateral + $selectedCollateral), 2);

    $recoverAfterDep = 0.0;
    $recoverAfterDep += $houseSelected * (1.0 - 0.12);
    $recoverAfterDep += $landSelected * (1.0 - 0.08);
    $recoverAfterDep += $carSelected * (1.0 - 0.28);
    $recoverAfterDep += $selectedOther * (1.0 - 0.20);
    $recoverAfterDep += $manualHouse * (1.0 - 0.12);
    $recoverAfterDep += $manualLand * (1.0 - 0.08);
    $recoverAfterDep += $manualCar * (1.0 - 0.28);
    $recoverAfterDep += $manualOtherTyped * (1.0 - 0.20);
    $recoverAfterDep += $manualNumericCollateral * (1.0 - 0.18);
    $recoverAfterDep = round(max(0.0, $recoverAfterDep), 2);

    $depreciationLoss = round(max(0.0, $totalCollateral - $recoverAfterDep), 2);
    $depreciationRatePct = $totalCollateral > 0 ? round(($depreciationLoss / $totalCollateral) * 100.0, 2) : 0.0;

    $economicRecoveryFactor = nplr_clamp(
        (1.0 / max(0.70, $householdIndex))
        * (1.0 / max(0.75, $costMul))
        * (1.0 - (($nplShiftPct / 100.0) * 0.08))
        * (1.0 / max(0.70, $scenarioWeight)),
        0.50,
        1.10
    );
    $recoverAfterEconomic = round(max(0.0, $recoverAfterDep * $economicRecoveryFactor), 2);
    $economicHaircutLoss = round(max(0.0, $recoverAfterDep - $recoverAfterEconomic), 2);

    $damageIfDefault = round(max(0.0, $outstanding + $legalCost - $recoverAfterEconomic), 2);
    $expectedDamage = round(max(0.0, $damageIfDefault * $defaultProb), 2);
    $damageIndexPct = $outstanding > 0 ? round(nplr_clamp(($expectedDamage / $outstanding) * 100.0, 0.0, 300.0), 2) : 0.0;

    return [
        'default_probability_pct' => round($defaultProb * 100.0, 2),
        'damage_index_pct' => $damageIndexPct,
        'expected_damage_amount' => $expectedDamage,
        'damage_if_default_amount' => $damageIfDefault,
        'outstanding_amount' => $outstanding,
        'legal_cost_estimate' => $legalCost,
        'legal_cost_rate_pct' => round($legalRate * 100.0, 2),
        'collateral_total' => $totalCollateral,
        'collateral_after_depreciation' => $recoverAfterDep,
        'collateral_after_economic' => $recoverAfterEconomic,
        'collateral_depreciation_loss' => $depreciationLoss,
        'collateral_depreciation_rate_pct' => $depreciationRatePct,
        'economic_haircut_loss' => $economicHaircutLoss,
        'economic_recovery_factor' => round($economicRecoveryFactor, 4),
        'household_index' => round($householdIndex, 4),
        'household_baseline_monthly' => $householdBaseline,
        'scenario_weight' => round($scenarioWeight, 4),
        'lei_cost_multiplier' => round($costMul, 4),
        'lei_npl_shift_pct' => round($nplShiftPct, 4),
    ];
}

/**
 * @param array<string,mixed> $plan
 * @param array<string,mixed> $sim
 * @return array<int,array{month:int,label:string,pay_probability_pct:float,expected_paid_amount:float,is_grace:bool}>
 */
function nplr_projection_12m(array $plan, array $sim): array
{
    $graceMonths = max(0, min(12, (int)hp_int($plan['grace_months'] ?? 0)));
    $interestOnlyMonths = max(0, min(12, (int)hp_int($plan['interest_only_months'] ?? 0)));
    $newInstallment = round(max(0.0, hp_float($sim['new_installment'] ?? 0)), 2);
    $baseP = nplr_clamp((float)($sim['beta_mean'] ?? 0.5) + (float)($sim['score_adjust'] ?? 0.0), 0.01, 0.99);

    $rows = [];
    for ($m = 1; $m <= 12; $m++) {
        $seasonal = sin(($m / 12.0) * 2.0 * M_PI) * 0.02;
        $ioBoost = ($m <= $interestOnlyMonths) ? 0.03 : 0.0;
        $monthlyP = nplr_clamp($baseP + $seasonal + $ioBoost, 0.01, 0.99);
        $isGrace = $m <= $graceMonths;
        $effectiveProb = $isGrace ? 0.0 : $monthlyP;
        $rows[] = [
            'month' => $m,
            'label' => 'M' . $m,
            'pay_probability_pct' => round($effectiveProb * 100.0, 2),
            'expected_paid_amount' => round($effectiveProb * $newInstallment, 2),
            'is_grace' => $isGrace,
        ];
    }

    return $rows;
}

/**
 * @param array<string,mixed> $contract
 * @param array<string,mixed> $plan
 * @param array<string,mixed> $sim
 */
function nplr_send_to_legal(array $contract, array $plan, array $sim): string
{
    $legalModule = module_by_key('legal_enforcement');
    $payload = is_array($contract['payload'] ?? null) ? $contract['payload'] : [];
    $contractNo = (string)($contract['contract_no'] ?? '');
    $customerCode = (string)($contract['customer_code'] ?? '');
    $branchCode = (string)($contract['branch_code'] ?? '');

    $legalCaseNo = hp_generate_code('LGL');
    $evidence = [
        'source' => 'npl_recovery_auto_transfer',
        'evaluation' => $sim,
        'plan' => $plan,
        'transferred_at' => now_dt(),
    ];

    module_create_record($legalModule, [
        'legal_case_no' => $legalCaseNo,
        'contract_no' => $contractNo,
        'document_template' => 'NPL_FAIL_TO_LEGAL',
        'notice_type' => 'AUTO_TRANSFER',
        'lawyer_name' => '',
        'evidence_repository' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'court_date' => '',
        'seizure_status' => 'PENDING_REVIEW',
        'enforcement_stage' => 'RECEIVED_FROM_NPL',
        'claim_amount' => round(max(0.0, hp_float($plan['outstanding_amount'] ?? 0)), 2),
        'branch_code' => $branchCode,
        'customer_ref_common' => $customerCode,
        'risk_level' => 'HIGH',
        'amount' => round(max(0.0, hp_float($plan['outstanding_amount'] ?? 0)), 2),
        'event_date' => date('Y-m-d'),
        'record_status' => 'APPROVED',
        'note_text' => 'Auto transfer from NPL recovery simulation fail',
    ], 'Auto transfer from NPL module');

    return $legalCaseNo;
}

/**
 * @param array<string,mixed> $contract
 * @param array<string,mixed> $plan
 * @param array<string,mixed> $sim
 */
function nplr_save_restructure_record(array $contract, array $plan, array $sim): string
{
    $module = module_by_key('npl_recovery');
    $payload = is_array($contract['payload'] ?? null) ? $contract['payload'] : [];
    $contractNo = (string)($contract['contract_no'] ?? '');
    $customerCode = (string)($contract['customer_code'] ?? '');
    $branchCode = (string)($contract['branch_code'] ?? '');

    $nplCaseNo = hp_generate_code('NPL');
    module_create_record($module, [
        'npl_case_no' => $nplCaseNo,
        'contract_no' => $contractNo,
        'npl_status' => 'RESTRUCTURED_APPROVED',
        'collateral_status' => ((float)($plan['add_collateral_value'] ?? 0) > 0) ? 'ADDED_COLLATERAL' : 'UNCHANGED',
        'recovery_strategy' => 'RESTRUCTURE',
        'recovery_cashflow' => (float)($sim['new_installment'] ?? 0),
        'sale_pool_code' => '',
        'buyer_name' => '',
        'legal_stage' => 'NONE',
        'next_action_date' => (new DateTimeImmutable('+30 days'))->format('Y-m-d'),
        'branch_code' => $branchCode,
        'customer_ref_common' => $customerCode,
        'risk_level' => 'MONITORED',
        'amount' => (float)($sim['new_installment'] ?? 0),
        'event_date' => date('Y-m-d'),
        'record_status' => 'APPROVED',
        'note_text' => 'Bayesian+MC passed: ' . number_format((float)($sim['probability_pct'] ?? 0), 2) . '%',
    ], 'Auto save restructure agreement from simulation');

    $contractPayload = is_array($payload) ? $payload : [];
    $contractPayload['npl_restructure_plan'] = [
        'evaluated_at' => now_dt(),
        'new_interest_rate_pct' => (float)($plan['new_interest_rate_pct'] ?? 0),
        'new_tenor_month' => (int)($plan['new_tenor_month'] ?? 0),
        'grace_months' => (int)($plan['grace_months'] ?? 0),
        'manual_add_collateral_value' => (float)($plan['manual_add_collateral_value'] ?? 0),
        'manual_add_guarantor_count' => (int)($plan['manual_add_guarantor_count'] ?? 0),
        'use_house_collateral' => (int)($plan['use_house_collateral'] ?? 0),
        'use_land_collateral' => (int)($plan['use_land_collateral'] ?? 0),
        'use_car_collateral' => (int)($plan['use_car_collateral'] ?? 0),
        'use_existing_guarantors' => (int)($plan['use_existing_guarantors'] ?? 0),
        'selected_customer_collateral_value' => (float)($plan['selected_customer_collateral_value'] ?? 0),
        'selected_customer_guarantor_count' => (int)($plan['selected_customer_guarantor_count'] ?? 0),
        'customer360_collateral_selected' => is_array($plan['customer360_collateral_selected'] ?? null) ? $plan['customer360_collateral_selected'] : [],
        'customer360_guarantors_selected' => is_array($plan['customer360_guarantors_selected'] ?? null) ? $plan['customer360_guarantors_selected'] : [],
        'npl_added_collateral_rows' => is_array($plan['npl_added_collateral_rows'] ?? null) ? $plan['npl_added_collateral_rows'] : [],
        'npl_added_guarantor_rows' => is_array($plan['npl_added_guarantor_rows'] ?? null) ? $plan['npl_added_guarantor_rows'] : [],
        'customer_snapshot' => is_array($plan['customer_snapshot'] ?? null) ? $plan['customer_snapshot'] : [],
        'add_collateral_value' => (float)($plan['add_collateral_value'] ?? 0),
        'add_guarantor_count' => (int)($plan['add_guarantor_count'] ?? 0),
        'interest_only_months' => (int)($plan['interest_only_months'] ?? 0),
        'lei_source_id' => (int)($plan['lei_source_id'] ?? 0),
        'lei_source_report_no' => (string)($plan['lei_source_report_no'] ?? ''),
        'lei_source_branch_code' => (string)($plan['lei_source_branch_code'] ?? ''),
        'lei_scenario' => (string)($plan['lei_scenario'] ?? 'BASE'),
        'pass_threshold_pct' => (float)($plan['pass_threshold_pct'] ?? 70),
        'result_probability_pct' => (float)($sim['probability_pct'] ?? 0),
        'result_expected_paid_months' => (float)($sim['expected_paid_months'] ?? 0),
        'result_damage' => is_array($sim['damage'] ?? null) ? $sim['damage'] : [],
        'result_damage_index_pct' => (float)((is_array($sim['damage'] ?? null) ? ($sim['damage']['damage_index_pct'] ?? 0) : 0)),
        'result_expected_damage_amount' => (float)((is_array($sim['damage'] ?? null) ? ($sim['damage']['expected_damage_amount'] ?? 0) : 0)),
        'agreement_ref' => $nplCaseNo,
    ];
    $contractPayload['annual_rate_pct'] = (float)($plan['new_interest_rate_pct'] ?? ($contractPayload['annual_rate_pct'] ?? 0));
    $contractPayload['term_months'] = (int)($plan['new_tenor_month'] ?? ($contractPayload['term_months'] ?? 0));
    $contractPayload['npl_last_assessment_probability_pct'] = (float)($sim['probability_pct'] ?? 0);

    hp_update_contract_payload($contract, $contractPayload, current_user_name());

    return $nplCaseNo;
}

$branchRows = [];
$branchNameMap = [];
$allowedBranch = array_fill_keys(accessible_branch_codes($scope), true);
foreach (active_branch_rows() as $b) {
    $bc = strtoupper(trim((string)($b['branch_code'] ?? '')));
    if ($scope['scope'] !== 'all' && !isset($allowedBranch[$bc])) {
        continue;
    }
    $branchNameMap[$bc] = trim((string)($b['branch_name'] ?? ''));
    $branchRows[] = $b;
}
$leiSourceOptions = nplr_fetch_lei_sources($scope, $branchNameMap);
$leiSourceById = [];
$leiScenarioMap = [];
foreach ($leiSourceOptions as $src) {
    $id = (int)($src['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }
    $leiSourceById[$id] = $src;
    $leiScenarioMap[(string)$id] = $src['scenario_options'] ?? [];
}

$selectedBranch = strtoupper(trim((string)($_GET['branch_code'] ?? '')));
if ($selectedBranch !== '' && !is_branch_in_current_scope($selectedBranch, $scope)) {
    $selectedBranch = '';
}
$searchText = trim((string)($_GET['q'] ?? ''));
$evaluationPreview = null;
$evaluationProjection12m = [];
$modalStickyState = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $modalStickyState = [
        'contract_no' => strtoupper(trim((string)($_POST['contract_no'] ?? ''))),
        'customer_code' => trim((string)($_POST['customer_code'] ?? '')),
        'customer_name' => trim((string)($_POST['customer_name'] ?? '')),
        'outstanding_amount' => (float)hp_float($_POST['outstanding_amount'] ?? 0),
        'customer_snapshot_json' => trim((string)($_POST['customer_snapshot_json'] ?? '{}')),
        'customer_profile_json' => trim((string)($_POST['customer_profile_json'] ?? '{}')),
        'selected_collateral_items_json' => trim((string)($_POST['selected_collateral_items_json'] ?? '[]')),
        'selected_guarantor_items_json' => trim((string)($_POST['selected_guarantor_items_json'] ?? '[]')),
        'new_collateral_items_json' => trim((string)($_POST['new_collateral_items_json'] ?? '[]')),
        'new_guarantor_items_json' => trim((string)($_POST['new_guarantor_items_json'] ?? '[]')),
        'new_interest_rate_pct' => (float)hp_float($_POST['new_interest_rate_pct'] ?? 0),
        'new_tenor_month' => (int)hp_int($_POST['new_tenor_month'] ?? 0),
        'grace_months' => (int)hp_int($_POST['grace_months'] ?? 0),
        'add_collateral_value' => (float)hp_float($_POST['add_collateral_value'] ?? 0),
        'add_guarantor_count' => (int)hp_int($_POST['add_guarantor_count'] ?? 0),
        'interest_only_months' => (int)hp_int($_POST['interest_only_months'] ?? 0),
        'use_house_collateral' => !empty($_POST['use_house_collateral']) ? 1 : 0,
        'use_land_collateral' => !empty($_POST['use_land_collateral']) ? 1 : 0,
        'use_car_collateral' => !empty($_POST['use_car_collateral']) ? 1 : 0,
        'use_existing_guarantors' => !empty($_POST['use_existing_guarantors']) ? 1 : 0,
        'lei_source_id' => (int)hp_int($_POST['lei_source_id'] ?? 0),
        'lei_scenario' => (string)($_POST['lei_scenario'] ?? 'BASE'),
        'pass_threshold_pct' => (float)hp_float($_POST['pass_threshold_pct'] ?? 70),
        'restructure_note' => trim((string)($_POST['restructure_note'] ?? '')),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));
        $action = trim((string)($_POST['form_action'] ?? ''));
        if ($action === '') {
            $action = 'evaluate_restructure';
        }
        if (!in_array($action, ['evaluate_restructure', 'save_restructure_decision'], true)) {
            throw new RuntimeException('Invalid action');
        }

        $selectedBranch = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
        if ($selectedBranch !== '' && !is_branch_in_current_scope($selectedBranch, $scope)) {
            throw new RuntimeException('Branch permission denied');
        }
        $searchText = trim((string)($_POST['q'] ?? ''));

        $contractNo = strtoupper(trim((string)($_POST['contract_no'] ?? '')));
        if ($contractNo === '') {
            throw new RuntimeException('Missing contract number');
        }

        $contract = hp_find_contract_latest($contractNo, $scope);
        if (!$contract) {
            throw new RuntimeException('Contract not found');
        }

        $contractPayload = is_array($contract['payload'] ?? null) ? $contract['payload'] : [];
        $history = $contractPayload['payment_history'] ?? [];
        $metrics = nplr_history_metrics(is_array($history) ? $history : [], date('Y-m-d'));
        if ((int)$metrics['eligibility_unpaid_streak'] < 3) {
            throw new RuntimeException('Contract does not match 3 consecutive unpaid installments');
        }

        $manualAddCollateral = round(max(0.0, hp_float($_POST['add_collateral_value'] ?? 0)), 2);
        $manualAddGuarantor = max(0, min(10, (int)hp_int($_POST['add_guarantor_count'] ?? 0)));
        $useHouseCollateral = !empty($_POST['use_house_collateral']);
        $useLandCollateral = !empty($_POST['use_land_collateral']);
        $useCarCollateral = !empty($_POST['use_car_collateral']);
        $useExistingGuarantors = !empty($_POST['use_existing_guarantors']);

        $customerProfile = nplr_customer360_profile((string)($contract['customer_code'] ?? ''), $scope);
        $customerSnapshot = is_array($customerProfile['snapshot'] ?? null) ? $customerProfile['snapshot'] : nplr_customer360_snapshot((string)($contract['customer_code'] ?? ''), $scope);

        $selectedCollateralRowsRaw = json_decode((string)($_POST['selected_collateral_items_json'] ?? '[]'), true);
        $selectedGuarantorRowsRaw = json_decode((string)($_POST['selected_guarantor_items_json'] ?? '[]'), true);
        $newCollateralRowsRaw = json_decode((string)($_POST['new_collateral_items_json'] ?? '[]'), true);
        $newGuarantorRowsRaw = json_decode((string)($_POST['new_guarantor_items_json'] ?? '[]'), true);

        $selectedCollateralRows = [];
        $selectedCollateralValue = 0.0;
        if (is_array($selectedCollateralRowsRaw)) {
            foreach ($selectedCollateralRowsRaw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $value = round(max(0.0, hp_float($row['value'] ?? 0)), 2);
                $clean = [
                    'id' => trim((string)($row['id'] ?? '')),
                    'source' => 'customer360',
                    'type_code' => strtoupper(trim((string)($row['type_code'] ?? 'OTHER'))),
                    'type_label' => trim((string)($row['type_label'] ?? '')),
                    'reference' => trim((string)($row['reference'] ?? '')),
                    'location' => trim((string)($row['location'] ?? '')),
                    'value' => $value,
                ];
                $selectedCollateralRows[] = $clean;
                $selectedCollateralValue += $value;
            }
        }
        $selectedCollateralValue = round(max(0.0, $selectedCollateralValue), 2);

        $selectedGuarantorRows = [];
        if (is_array($selectedGuarantorRowsRaw)) {
            foreach ($selectedGuarantorRowsRaw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $selectedGuarantorRows[] = [
                    'id' => trim((string)($row['id'] ?? '')),
                    'source' => 'customer360',
                    'full_name' => trim((string)($row['full_name'] ?? '')),
                    'phone' => trim((string)($row['phone'] ?? '')),
                    'relation' => trim((string)($row['relation'] ?? '')),
                ];
            }
        }
        $selectedGuarantorCount = count($selectedGuarantorRows);

        $newCollateralRows = [];
        $newCollateralValue = 0.0;
        if (is_array($newCollateralRowsRaw)) {
            foreach ($newCollateralRowsRaw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $value = round(max(0.0, hp_float($row['value'] ?? 0)), 2);
                if ($value <= 0) {
                    continue;
                }
                $typeCode = strtoupper(trim((string)($row['type_code'] ?? 'OTHER')));
                $newCollateralRows[] = [
                    'id' => trim((string)($row['id'] ?? '')),
                    'source' => 'npl_new',
                    'type_code' => $typeCode,
                    'type_label' => trim((string)($row['type_label'] ?? nplr_asset_type_label($typeCode))),
                    'reference' => trim((string)($row['reference'] ?? '')),
                    'location' => trim((string)($row['location'] ?? '')),
                    'value' => $value,
                ];
                $newCollateralValue += $value;
            }
        }
        $newCollateralValue = round(max(0.0, $newCollateralValue), 2);

        $newGuarantorRows = [];
        if (is_array($newGuarantorRowsRaw)) {
            foreach ($newGuarantorRowsRaw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = trim((string)($row['full_name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $newGuarantorRows[] = [
                    'id' => trim((string)($row['id'] ?? '')),
                    'source' => 'npl_new',
                    'full_name' => $name,
                    'phone' => trim((string)($row['phone'] ?? '')),
                    'relation' => trim((string)($row['relation'] ?? '')),
                ];
            }
        }
        $newGuarantorCount = count($newGuarantorRows);

        if ($selectedCollateralRows === []) {
            if ($useHouseCollateral) {
                $v = round(max(0.0, hp_float($customerSnapshot['houses_value'] ?? 0)), 2);
                if ($v > 0) {
                    $selectedCollateralRows[] = ['id' => 'SNAP-HOUSE', 'source' => 'customer360', 'type_code' => 'HOUSE', 'type_label' => 'house', 'reference' => '', 'location' => '', 'value' => $v];
                    $selectedCollateralValue += $v;
                }
            }
            if ($useLandCollateral) {
                $v = round(max(0.0, hp_float($customerSnapshot['lands_value'] ?? 0)), 2);
                if ($v > 0) {
                    $selectedCollateralRows[] = ['id' => 'SNAP-LAND', 'source' => 'customer360', 'type_code' => 'LAND', 'type_label' => 'land', 'reference' => '', 'location' => '', 'value' => $v];
                    $selectedCollateralValue += $v;
                }
            }
            if ($useCarCollateral) {
                $v = round(max(0.0, hp_float($customerSnapshot['cars_value'] ?? 0)), 2);
                if ($v > 0) {
                    $selectedCollateralRows[] = ['id' => 'SNAP-CAR', 'source' => 'customer360', 'type_code' => 'CAR', 'type_label' => 'car', 'reference' => '', 'location' => '', 'value' => $v];
                    $selectedCollateralValue += $v;
                }
            }
            $selectedCollateralValue = round(max(0.0, $selectedCollateralValue), 2);
        }

        if ($selectedGuarantorRows === [] && $useExistingGuarantors) {
            $profileGuarantors = is_array($customerProfile['guarantor_items'] ?? null) ? $customerProfile['guarantor_items'] : [];
            foreach ($profileGuarantors as $gr) {
                if (!is_array($gr)) {
                    continue;
                }
                $selectedGuarantorRows[] = [
                    'id' => trim((string)($gr['id'] ?? '')),
                    'source' => 'customer360',
                    'full_name' => trim((string)($gr['full_name'] ?? '')),
                    'phone' => trim((string)($gr['phone'] ?? '')),
                    'relation' => trim((string)($gr['relation'] ?? '')),
                ];
            }
            $selectedGuarantorCount = count($selectedGuarantorRows);
        }

        $plan = [
            'new_interest_rate_pct' => round(max(0.0, hp_float($_POST['new_interest_rate_pct'] ?? 0)), 4),
            'new_tenor_month' => max(1, (int)hp_int($_POST['new_tenor_month'] ?? 1)),
            'grace_months' => max(0, min(12, (int)hp_int($_POST['grace_months'] ?? 0))),
            'manual_add_collateral_value' => $manualAddCollateral,
            'manual_add_guarantor_count' => $manualAddGuarantor,
            'use_house_collateral' => $useHouseCollateral ? 1 : 0,
            'use_land_collateral' => $useLandCollateral ? 1 : 0,
            'use_car_collateral' => $useCarCollateral ? 1 : 0,
            'use_existing_guarantors' => $useExistingGuarantors ? 1 : 0,
            'selected_customer_collateral_value' => $selectedCollateralValue,
            'selected_customer_guarantor_count' => $selectedGuarantorCount,
            'customer360_collateral_selected' => $selectedCollateralRows,
            'customer360_guarantors_selected' => $selectedGuarantorRows,
            'npl_added_collateral_rows' => $newCollateralRows,
            'npl_added_guarantor_rows' => $newGuarantorRows,
            'npl_new_collateral_value' => $newCollateralValue,
            'npl_new_guarantor_count' => $newGuarantorCount,
            'customer_snapshot' => $customerSnapshot,
            'add_collateral_value' => round($manualAddCollateral + $selectedCollateralValue + $newCollateralValue, 2),
            'add_guarantor_count' => max(0, min(20, $manualAddGuarantor + $selectedGuarantorCount + $newGuarantorCount)),
            'interest_only_months' => max(0, min(12, (int)hp_int($_POST['interest_only_months'] ?? 0))),
            'lei_source_id' => max(0, (int)hp_int($_POST['lei_source_id'] ?? 0)),
            'lei_source_report_no' => '',
            'lei_source_branch_code' => '',
            'lei_scenario' => lei_normalize_scenario_code((string)($_POST['lei_scenario'] ?? 'BASE')),
            'pass_threshold_pct' => nplr_clamp((float)hp_float($_POST['pass_threshold_pct'] ?? 70), 1.0, 99.0),
            'outstanding_amount' => (float)$metrics['outstanding_amount'],
            'restructure_note' => trim((string)($_POST['restructure_note'] ?? '')),
        ];

        $leiSourceId = (int)$plan['lei_source_id'];
        if ($leiSourceOptions !== [] && $leiSourceId <= 0) {
            throw new RuntimeException('Please select LEI source from Module 17');
        }
        $leiSource = $leiSourceId > 0 ? ($leiSourceById[$leiSourceId] ?? null) : null;
        if ($leiSourceId > 0 && !is_array($leiSource)) {
            throw new RuntimeException('LEI source not found');
        }

        $leiBranchCode = is_array($leiSource) ? (string)($leiSource['branch_code'] ?? '') : '';
        if ($leiBranchCode === '') {
            $leiBranchCode = (string)($contract['branch_code'] ?? '');
        }
        if ($leiBranchCode !== '' && !is_branch_in_current_scope($leiBranchCode, $scope)) {
            throw new RuntimeException('LEI branch permission denied');
        }
        $plan['lei_source_report_no'] = is_array($leiSource) ? (string)($leiSource['report_no'] ?? '') : '';
        $plan['lei_source_branch_code'] = $leiBranchCode;

        $branchProfile = lei_fetch_branch_household_profile($leiBranchCode, $scope);
        if (!is_array($branchProfile)) {
            $branchProfile = lei_fetch_branch_household_profile((string)($contract['branch_code'] ?? ''), $scope);
        }
        $leiAssumption = lei_scenario_assumption((string)$plan['lei_scenario'], $branchProfile);
        $sim = nplr_run_bayesian_mc($contract, $plan, $leiAssumption);
        $sim['damage'] = nplr_damage_index($plan, $sim, $leiAssumption, $branchProfile);
        $evaluationProjection12m = nplr_projection_12m($plan, $sim);
        $customerSnapshotJson = json_encode($customerSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $customerProfileJson = json_encode($customerProfile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $selectedCollateralRowsJson = json_encode($selectedCollateralRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $selectedGuarantorRowsJson = json_encode($selectedGuarantorRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $newCollateralRowsJson = json_encode($newCollateralRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $newGuarantorRowsJson = json_encode($newGuarantorRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($customerSnapshotJson) || $customerSnapshotJson === '') {
            $customerSnapshotJson = '{}';
        }
        if (!is_string($customerProfileJson) || $customerProfileJson === '') {
            $customerProfileJson = '{}';
        }
        if (!is_string($selectedCollateralRowsJson) || $selectedCollateralRowsJson === '') {
            $selectedCollateralRowsJson = '[]';
        }
        if (!is_string($selectedGuarantorRowsJson) || $selectedGuarantorRowsJson === '') {
            $selectedGuarantorRowsJson = '[]';
        }
        if (!is_string($newCollateralRowsJson) || $newCollateralRowsJson === '') {
            $newCollateralRowsJson = '[]';
        }
        if (!is_string($newGuarantorRowsJson) || $newGuarantorRowsJson === '') {
            $newGuarantorRowsJson = '[]';
        }
        $modalStickyState = [
            'contract_no' => (string)($contract['contract_no'] ?? ''),
            'customer_code' => (string)($contract['customer_code'] ?? ''),
            'customer_name' => trim((string)($contractPayload['customer_name'] ?? '')),
            'outstanding_amount' => (float)$metrics['outstanding_amount'],
            'customer_snapshot_json' => $customerSnapshotJson,
            'customer_profile_json' => $customerProfileJson,
            'selected_collateral_items_json' => $selectedCollateralRowsJson,
            'selected_guarantor_items_json' => $selectedGuarantorRowsJson,
            'new_collateral_items_json' => $newCollateralRowsJson,
            'new_guarantor_items_json' => $newGuarantorRowsJson,
            'new_interest_rate_pct' => (float)$plan['new_interest_rate_pct'],
            'new_tenor_month' => (int)$plan['new_tenor_month'],
            'grace_months' => (int)$plan['grace_months'],
            'add_collateral_value' => (float)$plan['manual_add_collateral_value'],
            'add_guarantor_count' => (int)$plan['manual_add_guarantor_count'],
            'interest_only_months' => (int)$plan['interest_only_months'],
            'use_house_collateral' => (int)$plan['use_house_collateral'],
            'use_land_collateral' => (int)$plan['use_land_collateral'],
            'use_car_collateral' => (int)$plan['use_car_collateral'],
            'use_existing_guarantors' => (int)$plan['use_existing_guarantors'],
            'lei_source_id' => (int)$plan['lei_source_id'],
            'lei_scenario' => (string)$plan['lei_scenario'],
            'pass_threshold_pct' => (float)$plan['pass_threshold_pct'],
            'restructure_note' => (string)$plan['restructure_note'],
        ];
        $evaluationPreview = [
            'contract' => $contract,
            'plan' => $plan,
            'sim' => $sim,
            'lei_assumption' => $leiAssumption,
            'passed' => (float)$sim['probability_pct'] >= (float)$plan['pass_threshold_pct'],
        ];

        if ($action === 'save_restructure_decision') {
            if ((bool)$evaluationPreview['passed']) {
                $nplCaseNo = nplr_save_restructure_record($contract, $plan, $sim);
                add_flash('success', 'Passed the criteria ' . number_format((float)$sim['probability_pct'], 2) . '% and the new plan has been saved (Case: ' . $nplCaseNo . ')');
            } else {
                $legalCaseNo = nplr_send_to_legal($contract, $plan, $sim);
                add_flash('warning', 'Not passing the criteria (' . number_format((float)$sim['probability_pct'], 2) . '%) Law has been forwarded (Case: ' . $legalCaseNo . ')');
            }
        } else {
            add_flash('info', 'After evaluating, you can adjust the numbers/hypotheses further and see the 12 month graph below.');
        }
    } catch (Throwable $e) {
        add_flash('danger', 'Failed to save plan: ' . $e->getMessage());
    }
}

$contractRows = hp_fetch_contract_rows($scope, '', $selectedBranch, '');
$candidates = [];
$autocomplete = [];

foreach ($contractRows as $row) {
    $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
    $customerName = trim((string)($payload['customer_name'] ?? ''));
    $customerCode = (string)($row['customer_code'] ?? '');
    $customerProfile = nplr_customer360_profile($customerCode, $scope);
    $customerSnapshot = is_array($customerProfile['snapshot'] ?? null) ? $customerProfile['snapshot'] : nplr_customer360_snapshot($customerCode, $scope);
    $annualRate = round(max(0.0, hp_float($payload['annual_rate_pct'] ?? 12.0)), 4);
    $termMonths = max(1, (int)hp_int($payload['term_months'] ?? 24));
    $metrics = nplr_history_metrics((array)($payload['payment_history'] ?? []), date('Y-m-d'));

    $tokens = [
        (string)($row['contract_no'] ?? ''),
        $customerCode,
        $customerName,
        (string)($row['branch_code'] ?? ''),
        (string)($branchNameMap[strtoupper(trim((string)($row['branch_code'] ?? '')))] ?? ''),
    ];
    foreach ($tokens as $tk) {
        $tk = trim($tk);
        if ($tk !== '') {
            $autocomplete[$tk] = true;
        }
    }

    if ((int)$metrics['eligibility_unpaid_streak'] < 3) {
        continue;
    }

    if ($searchText !== '') {
        $needle = mb_strtolower($searchText, 'UTF-8');
        $hay = mb_strtolower(implode(' ', $tokens), 'UTF-8');
        if (mb_strpos($hay, $needle, 0, 'UTF-8') === false) {
            continue;
        }
    }

    $candidates[] = [
        'contract_no' => (string)($row['contract_no'] ?? ''),
        'customer_code' => $customerCode,
        'customer_name' => $customerName,
        'branch_code' => (string)($row['branch_code'] ?? ''),
        'annual_rate_pct' => $annualRate,
        'term_months' => $termMonths,
        'outstanding_amount' => (float)$metrics['outstanding_amount'],
        'trailing_unpaid_streak' => (int)$metrics['trailing_unpaid_streak'],
        'eligibility_unpaid_streak' => (int)$metrics['eligibility_unpaid_streak'],
        'max_no_pay_streak' => (int)$metrics['max_no_pay_streak'],
        'due_installments' => (int)$metrics['due_installments'],
        'paid_installments' => (int)$metrics['paid_installments'],
        'unpaid_installments' => (int)$metrics['unpaid_installments'],
        'last_due_date' => (string)$metrics['last_due_date'],
        'customer_snapshot' => $customerSnapshot,
        'customer_profile' => $customerProfile,
        'contract_row' => $row,
    ];
}

$autocompleteList = array_keys($autocomplete);
sort($autocompleteList, SORT_NATURAL | SORT_FLAG_CASE);
$defaultLeiOptions = lei_scenario_options_for_select(lei_default_scenarios());
$leiScenarioMapJson = json_encode($leiScenarioMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($leiScenarioMapJson) || $leiScenarioMapJson === '') {
    $leiScenarioMapJson = '{}';
}
$modalStickyJson = json_encode($modalStickyState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($modalStickyJson) || $modalStickyJson === '') {
    $modalStickyJson = 'null';
}

$pageTitle = (string)($module['title'] ?? 'NPL Recovery');
$currentModule = $moduleKey;

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/menu.php';
?>
<section class="card shadow-sm border-0 mb-4 module-hero">
    <div class="card-body">
        <h1 class="h4 mb-2">NPL Recovery (Bayesian + Monte Carlo)</h1>
        <p class="mb-0 text-muted">Screen debtors with 3 consecutive missed installments and estimate 12-month repayment probability (Monte Carlo, 1,000 runs).</p>
    </div>
</section>

<section class="card shadow-sm border-0 mb-4 module-toolbar">
    <div class="card-body">
        <form class="row g-2 align-items-end module-search" method="get" action="<?php echo h(app_base_url('modules/07_npl_recovery.php')); ?>">
            <div class="col-lg-3 col-md-4">
                <label class="form-label">Branch</label>
                <select class="form-select" name="branch_code">
                    <option value="">All accessible branches</option>
                    <?php foreach ($branchRows as $b): $bc = strtoupper(trim((string)($b['branch_code'] ?? ''))); $bn = trim((string)($b['branch_name'] ?? '')); ?>
                    <option value="<?php echo h($bc); ?>" <?php echo $selectedBranch === $bc ? 'selected' : ''; ?>><?php echo h($bc . ($bn !== '' ? (' - ' . $bn) : '')); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-6 col-md-8">
                <label class="form-label">Search by contract no., customer ID, or full name</label>
                <input class="form-control" list="nplRecoverySearchList" name="q" value="<?php echo h($searchText); ?>" placeholder="Type a keyword and select from suggestions" autocomplete="off">
                <datalist id="nplRecoverySearchList">
                    <?php foreach ($autocompleteList as $item): ?><option value="<?php echo h((string)$item); ?>"></option><?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-lg-3 col-md-12 d-flex gap-2">
                <button class="btn btn-brand flex-grow-1" type="submit">Search</button>
                <a class="btn btn-outline-secondary" href="<?php echo h(app_base_url('modules/07_npl_recovery.php')); ?>">Clear</a>
            </div>
        </form>
    </div>
</section>

<section class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <strong>Debtors with 3 consecutive missed installments</strong>
        <span class="text-muted small">Found <?php echo number_format(count($candidates)); ?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 js-admin-datatable">
            <thead>
            <tr>
                <th>Contract No.</th>
                <th>Customer</th>
                <th>Branch</th>
                <th>Consecutive Missed Cycles</th>
                <th>Outstanding balance</th>
                <th>Current Interest Rate</th>
                <th>Original Term</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($candidates === []): ?>
                <tr><td class="text-center text-muted">No eligible debtors found.</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            <?php else: foreach ($candidates as $c): ?>
                <?php
                $branchProfile = lei_fetch_branch_household_profile((string)$c['branch_code'], $scope);
                $leiOptions = lei_scenario_options_for_select(lei_branch_scenarios($branchProfile));
                $leiJson = json_encode($leiOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $snapshotJson = json_encode((array)($c['customer_snapshot'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $profileJson = json_encode((array)($c['customer_profile'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($leiJson)) { $leiJson = '[]'; }
                if (!is_string($snapshotJson)) { $snapshotJson = '{}'; }
                if (!is_string($profileJson)) { $profileJson = '{}'; }
                ?>
                <tr>
                    <td><code><?php echo h((string)$c['contract_no']); ?></code></td>
                    <td><?php echo h(((string)$c['customer_code']) . ' - ' . ((string)$c['customer_name'] !== '' ? (string)$c['customer_name'] : '-')); ?></td>
                    <td><?php echo h((string)$c['branch_code']); ?></td>
                    <td>
                        <span class="badge text-bg-danger"><?php echo (int)$c['eligibility_unpaid_streak']; ?> cycles</span>
                        <?php if ((int)$c['max_no_pay_streak'] >= 3): ?>
                            <div class="small text-muted">NO_PAY max: <?php echo (int)$c['max_no_pay_streak']; ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo number_format((float)$c['outstanding_amount'], 2); ?></td>
                    <td><?php echo number_format((float)$c['annual_rate_pct'], 4); ?>%</td>
                    <td><?php echo (int)$c['term_months']; ?> months</td>
                    <td>
                        <button type="button"
                                class="btn btn-sm btn-outline-primary btn-open-restructure-modal"
                                data-bs-toggle="modal"
                                data-bs-target="#restructureModal"
                                data-contract="<?php echo h((string)$c['contract_no']); ?>"
                                data-customer="<?php echo h((string)$c['customer_code']); ?>"
                                data-customer-name="<?php echo h((string)$c['customer_name']); ?>"
                                data-branch="<?php echo h((string)$c['branch_code']); ?>"
                                data-rate="<?php echo h((string)$c['annual_rate_pct']); ?>"
                                data-term="<?php echo h((string)$c['term_months']); ?>"
                                data-outstanding="<?php echo h((string)$c['outstanding_amount']); ?>"
                                data-customer-snapshot='<?php echo h($snapshotJson); ?>'
                                data-customer-profile='<?php echo h($profileJson); ?>'
                                data-lei-options='<?php echo h($leiJson); ?>'>Manage Debt</button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="modal fade modal-slide-down" id="restructureModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog sf-resizable-modal modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <form method="post" class="validate-form" novalidate>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="branch_code" value="<?php echo h($selectedBranch); ?>">
                <input type="hidden" name="q" value="<?php echo h($searchText); ?>">
                <input type="hidden" name="contract_no" id="restructure_contract_no" value="<?php echo h((string)($modalStickyState['contract_no'] ?? '')); ?>">
                <input type="hidden" name="customer_code" id="restructure_customer_code" value="<?php echo h((string)($modalStickyState['customer_code'] ?? '')); ?>">
                <input type="hidden" name="customer_name" id="restructure_customer_name" value="<?php echo h((string)($modalStickyState['customer_name'] ?? '')); ?>">
                <input type="hidden" name="outstanding_amount" id="restructure_outstanding_amount" value="<?php echo h((string)($modalStickyState['outstanding_amount'] ?? '0')); ?>">
                <input type="hidden" name="customer_snapshot_json" id="customer_snapshot_json" value="<?php echo h((string)($modalStickyState['customer_snapshot_json'] ?? '{}')); ?>">
                <input type="hidden" name="customer_profile_json" id="customer_profile_json" value="<?php echo h((string)($modalStickyState['customer_profile_json'] ?? '{}')); ?>">
                <input type="hidden" name="selected_collateral_items_json" id="selected_collateral_items_json" value="<?php echo h((string)($modalStickyState['selected_collateral_items_json'] ?? '[]')); ?>">
                <input type="hidden" name="selected_guarantor_items_json" id="selected_guarantor_items_json" value="<?php echo h((string)($modalStickyState['selected_guarantor_items_json'] ?? '[]')); ?>">
                <input type="hidden" name="new_collateral_items_json" id="new_collateral_items_json" value="<?php echo h((string)($modalStickyState['new_collateral_items_json'] ?? '[]')); ?>">
                <input type="hidden" name="new_guarantor_items_json" id="new_guarantor_items_json" value="<?php echo h((string)($modalStickyState['new_guarantor_items_json'] ?? '[]')); ?>">

                <div class="modal-header">
                    <h2 class="h6 mb-0">Restructuring Plan Analysis</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="max-height:72vh;overflow-y:auto;">
                    <div class="row g-2 mb-3">
                        <div class="col-md-4"><label class="form-label">Contract number</label><input class="form-control" id="restructure_contract_display" type="text" readonly></div>
                        <div class="col-md-4"><label class="form-label">Customer</label><input class="form-control" id="restructure_customer_display" type="text" readonly></div>
                        <div class="col-md-4"><label class="form-label">Outstanding balance</label><input class="form-control" id="restructure_outstanding_display" type="text" readonly></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4"><label class="form-label">New interest rate (%) *</label><input class="form-control" type="number" step="0.0001" min="0" name="new_interest_rate_pct" id="restructure_new_rate" value="<?php echo h((string)($modalStickyState['new_interest_rate_pct'] ?? '')); ?>" required></div>
                        <div class="col-md-4"><label class="form-label">New tenor (months) *</label><input class="form-control" type="number" min="1" name="new_tenor_month" id="restructure_new_term" value="<?php echo h((string)($modalStickyState['new_tenor_month'] ?? '')); ?>" required></div>
                        <div class="col-md-4"><label class="form-label">Grace period (months)</label><input class="form-control" type="number" min="0" max="12" name="grace_months" value="<?php echo h((string)($modalStickyState['grace_months'] ?? '0')); ?>"></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4"><label class="form-label">Additional collateral (THB)</label><input class="form-control" type="number" min="0" step="0.01" name="add_collateral_value" id="add_collateral_value" value="<?php echo h((string)($modalStickyState['add_collateral_value'] ?? '0')); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Additional guarantors (people)</label><input class="form-control" type="number" min="0" max="10" name="add_guarantor_count" id="add_guarantor_count" value="<?php echo h((string)($modalStickyState['add_guarantor_count'] ?? '0')); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Interest-only period (months)</label><input class="form-control" type="number" min="0" max="12" name="interest_only_months" value="<?php echo h((string)($modalStickyState['interest_only_months'] ?? '0')); ?>"></div>
                    </div>
                    <div class="border rounded p-2 mb-3">
                        <div class="fw-semibold mb-2">Use data from Customer 360</div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="use_house_collateral" id="use_house_collateral" value="1" <?php echo !empty($modalStickyState['use_house_collateral']) ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Use home as additional collateral</span>
                                </label>
                                <div class="small text-muted" id="house_snapshot_summary">-</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="use_land_collateral" id="use_land_collateral" value="1" <?php echo !empty($modalStickyState['use_land_collateral']) ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Use land as additional collateral</span>
                                </label>
                                <div class="small text-muted" id="land_snapshot_summary">-</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="use_car_collateral" id="use_car_collateral" value="1" <?php echo !empty($modalStickyState['use_car_collateral']) ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Use vehicle as additional collateral</span>
                                </label>
                                <div class="small text-muted" id="car_snapshot_summary">-</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="use_existing_guarantors" id="use_existing_guarantors" value="1" <?php echo !empty($modalStickyState['use_existing_guarantors']) ? 'checked' : ''; ?>>
                                    <span class="form-check-label">Use guarantors from customer profile</span>
                                </label>
                                <div class="small text-muted" id="guarantor_snapshot_summary">-</div>
                            </div>
                        </div>
                        <div class="form-text">
                            Selected collateral values and guarantor counts are automatically added to the manual entries above.
                        </div>
                        <div class="mt-2 p-2 bg-light rounded border">
                            <div class="small text-muted">Effective totals used in calculation (real-time update)</div>
                            <div class="fw-semibold" id="effective_collateral_summary">Total collateral: 0.00 THB</div>
                            <div class="fw-semibold" id="effective_guarantor_summary">Total guarantors: 0 people</div>
                        </div>
                    </div>
                    <div class="card border bg-light-subtle mb-3">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="fw-semibold">Added from Customer 360 data</div>
                                <div class="small text-muted">Select existing entries and add new ones</div>
                            </div>
                            <div class="table-responsive mb-2">
                                <table class="table table-sm align-middle mb-0" id="npl-collateral-table">
                                    <thead>
                                    <tr>
                                        <th style="width:48px;">Use</th>
                                        <th>Type</th>
                                        <th>Reference No.</th>
                                        <th>Location/Details</th>
                                        <th class="text-end">Value</th>
                                    </tr>
                                    </thead>
                                    <tbody id="npl-collateral-body"></tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="btn-add-collateral-row">Add New Collateral</button>

                            <div class="table-responsive mb-2">
                                <table class="table table-sm align-middle mb-0" id="npl-guarantor-table">
                                    <thead>
                                    <tr>
                                        <th style="width:48px;">Use</th>
                                        <th>Guarantor Name</th>
                                        <th>Contact number</th>
                                        <th>Relationship / Notes</th>
                                    </tr>
                                    </thead>
                                    <tbody id="npl-guarantor-body"></tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btn-add-guarantor-row">Add New Guarantor</button>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Select LEI from Module 17 *</label>
                            <select class="form-select" name="lei_source_id" id="restructure_lei_source" required>
                                <?php if ($leiSourceOptions === []): ?>
                                    <option value="">LEI information not found in Module 17.</option>
                                <?php else: foreach ($leiSourceOptions as $src): ?>
                                    <?php $srcId = (int)($src['id'] ?? 0); $isSelSrc = (int)($modalStickyState['lei_source_id'] ?? 0) === $srcId; ?>
                                    <option
                                        value="<?php echo $srcId; ?>"
                                        data-branch="<?php echo h((string)($src['branch_code'] ?? '')); ?>"
                                        <?php echo $isSelSrc ? 'selected' : ''; ?>
                                    ><?php echo h((string)($src['label'] ?? 'LEI Source')); ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">LEI Scenario</label>
                            <select class="form-select" name="lei_scenario" id="restructure_lei_scenario">
                                <?php foreach ($defaultLeiOptions as $opt): ?>
                                    <?php $optCode = (string)($opt['code'] ?? 'BASE'); $isSelScenario = strtoupper((string)($modalStickyState['lei_scenario'] ?? '')) === strtoupper($optCode); ?>
                                    <option value="<?php echo h($optCode); ?>" <?php echo $isSelScenario ? 'selected' : ''; ?>><?php echo h((string)($opt['label'] ?? ($opt['code'] ?? 'BASE'))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Pass threshold (%) *</label>
                            <input class="form-control" type="number" min="1" max="99" step="0.01" name="pass_threshold_pct" value="<?php echo h((string)($modalStickyState['pass_threshold_pct'] ?? '70')); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">MC Rounds</label>
                            <input class="form-control" type="text" value="1000" readonly>
                        </div>
                    </div>
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label">Plan Notes</label>
                            <input class="form-control" type="text" name="restructure_note" maxlength="255" value="<?php echo h((string)($modalStickyState['restructure_note'] ?? '')); ?>">
                            <div class="form-text">The system uses Bayesian prior + 1000 Monte Carlo cycles to estimate the probability of repayment for 12 months.</div>
                        </div>
                    </div>
                    <?php if (is_array($evaluationPreview)): ?>
                    <?php
                        $evPlan = (array)($evaluationPreview['plan'] ?? []);
                        $evSim = (array)($evaluationPreview['sim'] ?? []);
                        $evDamage = is_array($evSim['damage'] ?? null) ? (array)$evSim['damage'] : [];
                        $evPass = (bool)($evaluationPreview['passed'] ?? false);
                    ?>
                    <hr class="my-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong>Latest Evaluation Result</strong>
                        <span class="badge <?php echo $evPass ? 'text-bg-success' : 'text-bg-warning'; ?>">
                            <?php echo $evPass ? 'Passed' : 'Below Threshold'; ?>
                        </span>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6"><div class="small text-muted">Probability of repayment for 12 months</div><div class="fw-semibold"><?php echo number_format((float)($evSim['probability_pct'] ?? 0), 2); ?>%</div></div>
                        <div class="col-md-6"><div class="small text-muted">Pass Threshold</div><div class="fw-semibold"><?php echo number_format((float)($evPlan['pass_threshold_pct'] ?? 70), 2); ?>%</div></div>
                        <div class="col-md-6"><div class="small text-muted">Original installment</div><div class="fw-semibold"><?php echo number_format((float)($evSim['old_installment'] ?? 0), 2); ?></div></div>
                        <div class="col-md-6"><div class="small text-muted">New installment</div><div class="fw-semibold"><?php echo number_format((float)($evSim['new_installment'] ?? 0), 2); ?></div></div>
                    </div>
                    <div class="mb-2 fw-semibold">12-Month Repayment Probability Curve</div>
                    <div class='row g-2 mb-3'>
                        <div class='col-md-6'><div class='small text-muted'>Company Loss Index (if unpaid)</div><div class='fw-semibold text-danger'><?php echo number_format((float)($evDamage['damage_index_pct'] ?? 0), 2); ?>%</div></div>
                        <div class='col-md-6'><div class='small text-muted'>Expected Loss</div><div class='fw-semibold text-danger'><?php echo number_format((float)($evDamage['expected_damage_amount'] ?? 0), 2); ?> baht</div></div>
                        <div class='col-md-6'><div class='small text-muted'>Loss at Actual Default</div><div class='fw-semibold'><?php echo number_format((float)($evDamage['damage_if_default_amount'] ?? 0), 2); ?> baht</div></div>
                        <div class='col-md-6'><div class='small text-muted'>Probability of default from the model</div><div class='fw-semibold'><?php echo number_format((float)($evDamage['default_probability_pct'] ?? 0), 2); ?>%</div></div>
                        <div class='col-md-4'><div class='small text-muted'>Estimated Legal Cost</div><div class='fw-semibold'><?php echo number_format((float)($evDamage['legal_cost_estimate'] ?? 0), 2); ?> baht</div></div>
                        <div class='col-md-4'><div class='small text-muted'>Loss from Asset Depreciation</div><div class='fw-semibold'><?php echo number_format((float)($evDamage['collateral_depreciation_loss'] ?? 0), 2); ?> baht</div></div>
                        <div class='col-md-4'><div class='small text-muted'>Economic Haircut Loss (LEI + Base)</div><div class='fw-semibold'><?php echo number_format((float)($evDamage['economic_haircut_loss'] ?? 0), 2); ?> baht</div></div>
                        <div class='col-md-4'><div class='small text-muted'>LEI Cost Multiplier</div><div class='fw-semibold'><?php echo number_format((float)($evDamage['lei_cost_multiplier'] ?? 1), 4); ?></div></div>
                        <div class='col-md-4'><div class='small text-muted'>Household Index (Base Module 17)</div><div class='fw-semibold'><?php echo number_format((float)($evDamage['household_index'] ?? 1), 4); ?></div></div>
                        <div class='col-md-4'><div class='small text-muted'>Post-Economic Recovery Factor</div><div class='fw-semibold'><?php echo number_format((float)($evDamage['economic_recovery_factor'] ?? 1), 4); ?></div></div>
                    </div>
                    <div class="row g-2">
                        <?php foreach ($evaluationProjection12m as $pt): ?>
                            <?php
                                $pct = (float)($pt['pay_probability_pct'] ?? 0);
                                $isGrace = (bool)($pt['is_grace'] ?? false);
                                $barClass = $isGrace ? 'bg-secondary' : ($pct >= 70 ? 'bg-success' : ($pct >= 50 ? 'bg-warning' : 'bg-danger'));
                            ?>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span><?php echo h((string)($pt['label'] ?? '')); ?><?php echo $isGrace ? ' (Grace period)' : ''; ?></span>
                                    <span><?php echo number_format($pct, 2); ?>%</span>
                                </div>
                                <div class="progress" style="height:10px;">
                                    <div class="progress-bar <?php echo $barClass; ?>" style="width:<?php echo number_format(max(0, min(100, $pct)), 2, '.', ''); ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="form_action" value="evaluate_restructure" class="btn btn-brand">Evaluate</button>
                    <button type="submit" name="form_action" value="save_restructure_decision" class="btn btn-success">Confirm & Save</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var LEI_SCENARIO_MAP = <?php echo $leiScenarioMapJson; ?>;
    var STICKY_MODAL_STATE = <?php echo $modalStickyJson; ?>;

    function toMoney(v) {
        var n = Number(v || 0);
        if (!Number.isFinite(n)) n = 0;
        return n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function toNum(v) {
        var n = Number(v || 0);
        return Number.isFinite(n) ? n : 0;
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function parseJson(raw, fallback) {
        if (!raw) return fallback;
        try {
            var parsed = JSON.parse(raw);
            return parsed == null ? fallback : parsed;
        } catch (e) {
            try {
                var txt = document.createElement('textarea');
                txt.innerHTML = raw;
                var decoded = txt.value || '';
                var parsedDecoded = JSON.parse(decoded);
                return parsedDecoded == null ? fallback : parsedDecoded;
            } catch (e2) {
                return fallback;
            }
        }
    }

    var modalEl = document.getElementById('restructureModal');
    if (!modalEl) return;

    var leiSelect = document.getElementById('restructure_lei_scenario');
    var leiSourceSelect = document.getElementById('restructure_lei_source');
    var snapshotInput = document.getElementById('customer_snapshot_json');
    var profileInput = document.getElementById('customer_profile_json');
    var selectedCollateralInput = document.getElementById('selected_collateral_items_json');
    var selectedGuarantorInput = document.getElementById('selected_guarantor_items_json');
    var newCollateralInput = document.getElementById('new_collateral_items_json');
    var newGuarantorInput = document.getElementById('new_guarantor_items_json');
    var manualCollateralInput = document.getElementById('add_collateral_value');
    var manualGuarantorInput = document.getElementById('add_guarantor_count');
    var houseCheckbox = document.getElementById('use_house_collateral');
    var landCheckbox = document.getElementById('use_land_collateral');
    var carCheckbox = document.getElementById('use_car_collateral');
    var guarantorCheckbox = document.getElementById('use_existing_guarantors');
    var effectiveCollateralSummary = document.getElementById('effective_collateral_summary');
    var effectiveGuarantorSummary = document.getElementById('effective_guarantor_summary');
    var collateralBody = document.getElementById('npl-collateral-body');
    var guarantorBody = document.getElementById('npl-guarantor-body');
    var addCollateralBtn = document.getElementById('btn-add-collateral-row');
    var addGuarantorBtn = document.getElementById('btn-add-guarantor-row');

    var rowState = {
        existingCollaterals: [],
        existingGuarantors: [],
        newCollaterals: [],
        newGuarantors: []
    };

    function setLeiOptions(rows, preferredCode) {
        if (!leiSelect) return;
        leiSelect.innerHTML = '';
        var list = Array.isArray(rows) ? rows : [];
        if (list.length === 0) {
            var fallback = document.createElement('option');
            fallback.value = 'BASE';
            fallback.textContent = 'BASE';
            leiSelect.appendChild(fallback);
            leiSelect.value = 'BASE';
            return;
        }
        list.forEach(function (it) {
            var opt = document.createElement('option');
            opt.value = String(it.code || 'BASE');
            opt.textContent = String(it.label || it.code || 'BASE');
            leiSelect.appendChild(opt);
        });
        var selected = String(preferredCode || '');
        if (selected !== '') {
            leiSelect.value = selected;
        }
        if (!leiSelect.value) {
            leiSelect.value = String((list[0] && list[0].code) || 'BASE');
        }
    }

    function parseLeiOptions(raw) {
        var parsed = parseJson(raw, []);
        return Array.isArray(parsed) ? parsed : [];
    }

    function parseSnapshot(raw) {
        var parsed = parseJson(raw, {});
        return parsed && typeof parsed === 'object' ? parsed : {};
    }

    function parseArrayRaw(raw) {
        var parsed = parseJson(raw, []);
        return Array.isArray(parsed) ? parsed : [];
    }

    function parseProfile(raw) {
        var parsed = parseJson(raw, {});
        if (!parsed || typeof parsed !== 'object') return { collateral_items: [], guarantor_items: [] };
        if (!Array.isArray(parsed.collateral_items)) parsed.collateral_items = [];
        if (!Array.isArray(parsed.guarantor_items)) parsed.guarantor_items = [];
        return parsed;
    }

    function normalizeSnapshot(snapshot) {
        function toInt(v) {
            var n = parseInt(v, 10);
            return Number.isFinite(n) && n > 0 ? n : 0;
        }
        function toFloat(v) {
            var n = Number(v || 0);
            return Number.isFinite(n) && n > 0 ? n : 0;
        }
        var s = snapshot && typeof snapshot === 'object' ? snapshot : {};
        return {
            houses_count: toInt(s.houses_count),
            houses_value: toFloat(s.houses_value),
            lands_count: toInt(s.lands_count),
            lands_value: toFloat(s.lands_value),
            cars_count: toInt(s.cars_count),
            cars_value: toFloat(s.cars_value),
            guarantors_count: toInt(s.guarantors_count)
        };
    }

    function normalizeTypeCode(typeCode) {
        var t = String(typeCode || '').toUpperCase();
        if (t === 'HOUSE' || t === 'LAND' || t === 'CAR' || t === 'MOTORCYCLE' || t === 'OTHER') return t;
        return 'OTHER';
    }

    function typeLabel(typeCode) {
        var t = normalizeTypeCode(typeCode);
        if (t === 'HOUSE') return 'house';
        if (t === 'LAND') return 'land';
        if (t === 'CAR') return 'car';
        if (t === 'MOTORCYCLE') return 'motorcycle';
        return 'other';
    }

    function normalizeCollateralRow(row, source) {
        var r = row && typeof row === 'object' ? row : {};
        var typeCode = normalizeTypeCode(r.type_code);
        return {
            id: String(r.id || (source + '-' + Math.random().toString(16).slice(2))),
            source: source,
            type_code: typeCode,
            type_label: String(r.type_label || typeLabel(typeCode)),
            reference: String(r.reference || ''),
            location: String(r.location || ''),
            value: Math.max(0, toNum(r.value || 0)),
            selected: !!r.selected
        };
    }

    function normalizeGuarantorRow(row, source) {
        var r = row && typeof row === 'object' ? row : {};
        return {
            id: String(r.id || (source + '-' + Math.random().toString(16).slice(2))),
            source: source,
            full_name: String(r.full_name || ''),
            phone: String(r.phone || ''),
            relation: String(r.relation || ''),
            selected: !!r.selected
        };
    }

    function buildRowsFromProfile(profile, selectedCollateralRows, selectedGuarantorRows) {
        var colSel = {};
        var guaSel = {};
        (Array.isArray(selectedCollateralRows) ? selectedCollateralRows : []).forEach(function (r) {
            colSel[String(r && r.id ? r.id : '')] = true;
        });
        (Array.isArray(selectedGuarantorRows) ? selectedGuarantorRows : []).forEach(function (r) {
            guaSel[String(r && r.id ? r.id : '')] = true;
        });

        rowState.existingCollaterals = (Array.isArray(profile.collateral_items) ? profile.collateral_items : []).map(function (r) {
            var row = normalizeCollateralRow(r, 'customer360');
            row.selected = !!colSel[row.id];
            return row;
        });
        rowState.existingGuarantors = (Array.isArray(profile.guarantor_items) ? profile.guarantor_items : []).map(function (r) {
            var row = normalizeGuarantorRow(r, 'customer360');
            row.selected = !!guaSel[row.id];
            return row;
        });
    }

    function applyLegacyCheckboxToRows() {
        var useHouse = !!(houseCheckbox && houseCheckbox.checked);
        var useLand = !!(landCheckbox && landCheckbox.checked);
        var useCar = !!(carCheckbox && carCheckbox.checked);
        var useGua = !!(guarantorCheckbox && guarantorCheckbox.checked);

        rowState.existingCollaterals.forEach(function (r) {
            var t = normalizeTypeCode(r.type_code);
            r.selected = (useHouse && t === 'HOUSE') || (useLand && t === 'LAND') || (useCar && (t === 'CAR' || t === 'MOTORCYCLE'));
        });
        rowState.existingGuarantors.forEach(function (r) {
            r.selected = useGua;
        });
    }

    function syncLegacyCheckboxFromRows() {
        if (houseCheckbox) {
            houseCheckbox.checked = rowState.existingCollaterals.some(function (r) { return r.selected && normalizeTypeCode(r.type_code) === 'HOUSE'; });
        }
        if (landCheckbox) {
            landCheckbox.checked = rowState.existingCollaterals.some(function (r) { return r.selected && normalizeTypeCode(r.type_code) === 'LAND'; });
        }
        if (carCheckbox) {
            carCheckbox.checked = rowState.existingCollaterals.some(function (r) {
                var t = normalizeTypeCode(r.type_code);
                return r.selected && (t === 'CAR' || t === 'MOTORCYCLE');
            });
        }
        if (guarantorCheckbox) {
            guarantorCheckbox.checked = rowState.existingGuarantors.some(function (r) { return r.selected; });
        }
    }

    function hydrateFromRaw(profileRaw, selectedColRaw, selectedGuaRaw, newColRaw, newGuaRaw, useLegacyFallback) {
        var profile = parseProfile(profileRaw || '{}');
        var selectedCollaterals = parseArrayRaw(selectedColRaw || '[]');
        var selectedGuarantors = parseArrayRaw(selectedGuaRaw || '[]');
        var newCollaterals = parseArrayRaw(newColRaw || '[]');
        var newGuarantors = parseArrayRaw(newGuaRaw || '[]');

        if (profileInput) profileInput.value = JSON.stringify(profile);
        buildRowsFromProfile(profile, selectedCollaterals, selectedGuarantors);

        rowState.newCollaterals = newCollaterals.map(function (r) {
            var row = normalizeCollateralRow(r, 'npl_new');
            row.selected = true;
            return row;
        });
        rowState.newGuarantors = newGuarantors.map(function (r) {
            var row = normalizeGuarantorRow(r, 'npl_new');
            row.selected = true;
            return row;
        });

        var hasExplicitSelection = selectedCollaterals.length > 0 || selectedGuarantors.length > 0;
        if (!hasExplicitSelection && useLegacyFallback) {
            applyLegacyCheckboxToRows();
        } else {
            syncLegacyCheckboxFromRows();
        }
    }

    function selectedExistingCollateralRows() {
        return rowState.existingCollaterals.filter(function (r) { return r.selected; }).map(function (r) {
            return {
                id: r.id,
                source: 'customer360',
                type_code: normalizeTypeCode(r.type_code),
                type_label: r.type_label || typeLabel(r.type_code),
                reference: r.reference || '',
                location: r.location || '',
                value: Math.max(0, toNum(r.value))
            };
        });
    }

    function selectedExistingGuarantorRows() {
        return rowState.existingGuarantors.filter(function (r) { return r.selected; }).map(function (r) {
            return {
                id: r.id,
                source: 'customer360',
                full_name: r.full_name || '',
                phone: r.phone || '',
                relation: r.relation || ''
            };
        });
    }

    function selectedNewCollateralRows() {
        return rowState.newCollaterals.filter(function (r) {
            return r.selected && Math.max(0, toNum(r.value)) > 0;
        }).map(function (r) {
            return {
                id: r.id,
                source: 'npl_new',
                type_code: normalizeTypeCode(r.type_code),
                type_label: typeLabel(r.type_code),
                reference: r.reference || '',
                location: r.location || '',
                value: Math.max(0, toNum(r.value))
            };
        });
    }

    function selectedNewGuarantorRows() {
        return rowState.newGuarantors.filter(function (r) {
            return r.selected && String(r.full_name || '').trim() !== '';
        }).map(function (r) {
            return {
                id: r.id,
                source: 'npl_new',
                full_name: String(r.full_name || '').trim(),
                phone: r.phone || '',
                relation: r.relation || ''
            };
        });
    }

    function syncSelectionInputs() {
        if (selectedCollateralInput) selectedCollateralInput.value = JSON.stringify(selectedExistingCollateralRows());
        if (selectedGuarantorInput) selectedGuarantorInput.value = JSON.stringify(selectedExistingGuarantorRows());
        if (newCollateralInput) newCollateralInput.value = JSON.stringify(selectedNewCollateralRows());
        if (newGuarantorInput) newGuarantorInput.value = JSON.stringify(selectedNewGuarantorRows());
    }

    function renderCollateralRows() {
        if (!collateralBody) return;
        var html = [];
        rowState.existingCollaterals.forEach(function (row, idx) {
            html.push('<tr data-source=\"existing\" data-index=\"' + idx + '\">');
            html.push('<td><input type=\"checkbox\" class=\"form-check-input js-col-select\" ' + (row.selected ? 'checked' : '') + '></td>');
            html.push('<td>' + esc(row.type_label || typeLabel(row.type_code)) + '</td>');
            html.push('<td>' + esc(row.reference || '-') + '</td>');
            html.push('<td>' + esc(row.location || '-') + '</td>');
            html.push('<td class=\"text-end\">' + toMoney(row.value || 0) + '</td>');
            html.push('</tr>');
        });
        rowState.newCollaterals.forEach(function (row, idx) {
            html.push('<tr data-source=\"new\" data-index=\"' + idx + '\">');
            html.push('<td><input type=\"checkbox\" class=\"form-check-input js-col-select\" ' + (row.selected ? 'checked' : '') + '></td>');
            html.push('<td><select class=\"form-select form-select-sm js-col-type\">'
                + '<option value=\"HOUSE\"' + (row.type_code === 'HOUSE' ? ' selected' : '') + '>House</option>'
                + '<option value=\"LAND\"' + (row.type_code === 'LAND' ? ' selected' : '') + '>Land</option>'
                + '<option value=\"CAR\"' + (row.type_code === 'CAR' ? ' selected' : '') + '>Car</option>'
                + '<option value=\"MOTORCYCLE\"' + (row.type_code === 'MOTORCYCLE' ? ' selected' : '') + '>Motorcycle</option>'
                + '<option value=\"OTHER\"' + (row.type_code === 'OTHER' ? ' selected' : '') + '>Other</option>'
                + '</select></td>');
            html.push('<td><input type=\"text\" class=\"form-control form-control-sm js-col-ref\" value=\"' + esc(row.reference || '') + '\"></td>');
            html.push('<td><input type=\"text\" class=\"form-control form-control-sm js-col-location\" value=\"' + esc(row.location || '') + '\"></td>');
            html.push('<td class=\"text-end\"><div class=\"input-group input-group-sm\">'
                + '<input type=\"number\" min=\"0\" step=\"0.01\" class=\"form-control text-end js-col-value\" value=\"' + esc(String(row.value || 0)) + '\">'
                + '<button type=\"button\" class=\"btn btn-outline-danger js-col-remove\">Remove</button>'
                + '</div></td>');
            html.push('</tr>');
        });
        if (html.length === 0) {
            html.push('<tr><td colspan=\"5\" class=\"text-center text-muted\">No items yet</td></tr>');
        }
        collateralBody.innerHTML = html.join('');
    }

    function renderGuarantorRows() {
        if (!guarantorBody) return;
        var html = [];
        rowState.existingGuarantors.forEach(function (row, idx) {
            html.push('<tr data-source=\"existing\" data-index=\"' + idx + '\">');
            html.push('<td><input type=\"checkbox\" class=\"form-check-input js-gua-select\" ' + (row.selected ? 'checked' : '') + '></td>');
            html.push('<td>' + esc(row.full_name || '-') + '</td>');
            html.push('<td>' + esc(row.phone || '-') + '</td>');
            html.push('<td>' + esc(row.relation || '-') + '</td>');
            html.push('</tr>');
        });
        rowState.newGuarantors.forEach(function (row, idx) {
            html.push('<tr data-source=\"new\" data-index=\"' + idx + '\">');
            html.push('<td><input type=\"checkbox\" class=\"form-check-input js-gua-select\" ' + (row.selected ? 'checked' : '') + '></td>');
            html.push('<td><input type=\"text\" class=\"form-control form-control-sm js-gua-name\" value=\"' + esc(row.full_name || '') + '\"></td>');
            html.push('<td><input type=\"text\" class=\"form-control form-control-sm js-gua-phone\" value=\"' + esc(row.phone || '') + '\"></td>');
            html.push('<td><div class=\"input-group input-group-sm\">'
                + '<input type=\"text\" class=\"form-control js-gua-relation\" value=\"' + esc(row.relation || '') + '\">'
                + '<button type=\"button\" class=\"btn btn-outline-danger js-gua-remove\">Remove</button>'
                + '</div></td>');
            html.push('</tr>');
        });
        if (html.length === 0) {
            html.push('<tr><td colspan=\"4\" class=\"text-center text-muted\">No items yet</td></tr>');
        }
        guarantorBody.innerHTML = html.join('');
    }

    function renderSnapshotSummary(snapshot) {
        var s = normalizeSnapshot(snapshot);
        var house = document.getElementById('house_snapshot_summary');
        var land = document.getElementById('land_snapshot_summary');
        var car = document.getElementById('car_snapshot_summary');
        var guarantor = document.getElementById('guarantor_snapshot_summary');
        if (house) {
            house.textContent = 'House ' + String(s.houses_count) + ' items | Value ' + toMoney(s.houses_value) + ' THB';
        }
        if (land) {
            land.textContent = 'Land ' + String(s.lands_count) + ' items | Value ' + toMoney(s.lands_value) + ' THB';
        }
        if (car) {
            car.textContent = 'Vehicle ' + String(s.cars_count) + ' items | Value ' + toMoney(s.cars_value) + ' THB';
        }
        if (guarantor) {
            guarantor.textContent = 'Guarantors in customer profile: ' + String(s.guarantors_count);
        }
    }

    function numVal(el) {
        if (!el) return 0;
        var n = Number(el.value || 0);
        return Number.isFinite(n) && n > 0 ? n : 0;
    }

    function renderEffectiveTotals() {
        var manualCollateral = numVal(manualCollateralInput);
        var manualGuarantor = Math.max(0, Math.floor(numVal(manualGuarantorInput)));
        var selectedCollateral = 0;
        rowState.existingCollaterals.forEach(function (r) {
            if (r.selected) selectedCollateral += Math.max(0, toNum(r.value));
        });
        rowState.newCollaterals.forEach(function (r) {
            if (r.selected) selectedCollateral += Math.max(0, toNum(r.value));
        });
        var selectedGuarantor = 0;
        rowState.existingGuarantors.forEach(function (r) {
            if (r.selected) selectedGuarantor += 1;
        });
        rowState.newGuarantors.forEach(function (r) {
            if (r.selected && String(r.full_name || '').trim() !== '') selectedGuarantor += 1;
        });

        var effectiveCollateral = Math.max(0, manualCollateral + selectedCollateral);
        var effectiveGuarantor = Math.max(0, manualGuarantor + selectedGuarantor);

        if (effectiveCollateralSummary) {
            effectiveCollateralSummary.textContent = 'Total collateral: ' + toMoney(effectiveCollateral) + ' THB (manual ' + toMoney(manualCollateral) + ' + selected from Customer360 ' + toMoney(selectedCollateral) + ')';
        }
        if (effectiveGuarantorSummary) {
            effectiveGuarantorSummary.textContent = 'Total guarantors: ' + String(effectiveGuarantor) + ' people (manual ' + String(manualGuarantor) + ' + selected from Customer360 ' + String(selectedGuarantor) + ')';
        }
        syncSelectionInputs();
    }

    function currentSnapshot() {
        var raw = snapshotInput ? snapshotInput.value : '{}';
        return parseSnapshot(raw);
    }

    function refreshCustomer360Summaries() {
        renderCollateralRows();
        renderGuarantorRows();
        renderSnapshotSummary(currentSnapshot());
        renderEffectiveTotals();
    }

    function scenarioRowsByLeiSource(sourceId) {
        var key = String(sourceId || '');
        var rows = LEI_SCENARIO_MAP && LEI_SCENARIO_MAP[key] ? LEI_SCENARIO_MAP[key] : [];
        return Array.isArray(rows) ? rows : [];
    }

    function applyLeiSource(sourceId, fallbackRaw, preferredScenario) {
        var rows = scenarioRowsByLeiSource(sourceId);
        if (rows.length === 0) {
            rows = parseLeiOptions(fallbackRaw || '[]');
        }
        setLeiOptions(rows, preferredScenario);
    }

    function populateRestructureModal(btn) {
        if (!btn) return;
        var contractNo = btn.getAttribute('data-contract') || '';
        var customerCode = btn.getAttribute('data-customer') || '';
        var customerName = btn.getAttribute('data-customer-name') || '';
        var contractBranch = String(btn.getAttribute('data-branch') || '').toUpperCase();
        var oldRate = btn.getAttribute('data-rate') || '12';
        var oldTerm = btn.getAttribute('data-term') || '24';
        var outstanding = btn.getAttribute('data-outstanding') || '0';
        var leiOptionsRaw = btn.getAttribute('data-lei-options') || '[]';
        var snapshotRaw = btn.getAttribute('data-customer-snapshot') || '{}';
        var profileRaw = btn.getAttribute('data-customer-profile') || '{}';

        document.getElementById('restructure_contract_no').value = contractNo;
        if (document.getElementById('restructure_customer_code')) {
            document.getElementById('restructure_customer_code').value = customerCode;
        }
        if (document.getElementById('restructure_customer_name')) {
            document.getElementById('restructure_customer_name').value = customerName;
        }
        if (document.getElementById('restructure_outstanding_amount')) {
            document.getElementById('restructure_outstanding_amount').value = String(outstanding || '0');
        }
        document.getElementById('restructure_contract_display').value = contractNo;
        document.getElementById('restructure_customer_display').value = customerCode + (customerName ? (' - ' + customerName) : '');
        document.getElementById('restructure_outstanding_display').value = toMoney(outstanding);
        document.getElementById('restructure_new_rate').value = oldRate;
        document.getElementById('restructure_new_term').value = oldTerm;
        if (houseCheckbox) houseCheckbox.checked = false;
        if (landCheckbox) landCheckbox.checked = false;
        if (carCheckbox) carCheckbox.checked = false;
        if (guarantorCheckbox) guarantorCheckbox.checked = false;
        if (snapshotInput) {
            snapshotInput.value = snapshotRaw;
        }
        if (profileInput) {
            profileInput.value = profileRaw;
        }
        if (selectedCollateralInput) selectedCollateralInput.value = '[]';
        if (selectedGuarantorInput) selectedGuarantorInput.value = '[]';
        if (newCollateralInput) newCollateralInput.value = '[]';
        if (newGuarantorInput) newGuarantorInput.value = '[]';
        hydrateFromRaw(profileRaw, '[]', '[]', '[]', '[]', true);
        refreshCustomer360Summaries();
        if (leiSourceSelect) {
            if (contractBranch !== '') {
                var matched = Array.prototype.find.call(leiSourceSelect.options, function (opt) {
                    return String(opt.getAttribute('data-branch') || '').toUpperCase() === contractBranch;
                });
                if (matched) {
                    leiSourceSelect.value = matched.value;
                }
            }
            if (!leiSourceSelect.value && leiSourceSelect.options.length > 0) {
                leiSourceSelect.selectedIndex = 0;
            }
            applyLeiSource(leiSourceSelect.value, leiOptionsRaw, '');
        } else {
            applyLeiSource('', leiOptionsRaw, '');
        }
    }

    function getRowMeta(target) {
        var tr = target && target.closest ? target.closest('tr[data-source][data-index]') : null;
        if (!tr) return null;
        var source = tr.getAttribute('data-source');
        var idx = parseInt(tr.getAttribute('data-index') || '-1', 10);
        if (!Number.isFinite(idx) || idx < 0) return null;
        return { source: source, index: idx };
    }

    if (collateralBody) {
        collateralBody.addEventListener('input', function (ev) {
            var target = ev.target;
            var meta = getRowMeta(target);
            if (!meta || meta.source !== 'new') return;
            var row = rowState.newCollaterals[meta.index];
            if (!row) return;

            if (target.classList.contains('js-col-ref')) row.reference = String(target.value || '');
            if (target.classList.contains('js-col-location')) row.location = String(target.value || '');
            if (target.classList.contains('js-col-value')) row.value = Math.max(0, toNum(target.value || 0));

            renderEffectiveTotals();
        });

        collateralBody.addEventListener('change', function (ev) {
            var target = ev.target;
            var meta = getRowMeta(target);
            if (!meta) return;
            var rows = meta.source === 'existing' ? rowState.existingCollaterals : rowState.newCollaterals;
            var row = rows[meta.index];
            if (!row) return;

            if (target.classList.contains('js-col-select')) {
                row.selected = !!target.checked;
                if (meta.source === 'existing') syncLegacyCheckboxFromRows();
            }
            if (target.classList.contains('js-col-type') && meta.source === 'new') {
                row.type_code = normalizeTypeCode(target.value || 'OTHER');
                row.type_label = typeLabel(row.type_code);
            }
            renderEffectiveTotals();
        });

        collateralBody.addEventListener('click', function (ev) {
            var target = ev.target;
            if (!target.classList.contains('js-col-remove')) return;
            var meta = getRowMeta(target);
            if (!meta || meta.source !== 'new') return;
            rowState.newCollaterals.splice(meta.index, 1);
            refreshCustomer360Summaries();
        });
    }

    if (guarantorBody) {
        guarantorBody.addEventListener('input', function (ev) {
            var target = ev.target;
            var meta = getRowMeta(target);
            if (!meta || meta.source !== 'new') return;
            var row = rowState.newGuarantors[meta.index];
            if (!row) return;

            if (target.classList.contains('js-gua-name')) row.full_name = String(target.value || '');
            if (target.classList.contains('js-gua-phone')) row.phone = String(target.value || '');
            if (target.classList.contains('js-gua-relation')) row.relation = String(target.value || '');

            renderEffectiveTotals();
        });

        guarantorBody.addEventListener('change', function (ev) {
            var target = ev.target;
            var meta = getRowMeta(target);
            if (!meta) return;
            var rows = meta.source === 'existing' ? rowState.existingGuarantors : rowState.newGuarantors;
            var row = rows[meta.index];
            if (!row) return;

            if (target.classList.contains('js-gua-select')) {
                row.selected = !!target.checked;
                if (meta.source === 'existing') syncLegacyCheckboxFromRows();
                renderEffectiveTotals();
            }
        });

        guarantorBody.addEventListener('click', function (ev) {
            var target = ev.target;
            if (!target.classList.contains('js-gua-remove')) return;
            var meta = getRowMeta(target);
            if (!meta || meta.source !== 'new') return;
            rowState.newGuarantors.splice(meta.index, 1);
            refreshCustomer360Summaries();
        });
    }

    if (addCollateralBtn) {
        addCollateralBtn.addEventListener('click', function () {
            rowState.newCollaterals.push(normalizeCollateralRow({
                id: 'npl-new-collateral-' + Date.now() + '-' + Math.random().toString(16).slice(2),
                type_code: 'OTHER',
                type_label: typeLabel('OTHER'),
                reference: '',
                location: '',
                value: 0,
                selected: true
            }, 'npl_new'));
            refreshCustomer360Summaries();
        });
    }

    if (addGuarantorBtn) {
        addGuarantorBtn.addEventListener('click', function () {
            rowState.newGuarantors.push(normalizeGuarantorRow({
                id: 'npl-new-guarantor-' + Date.now() + '-' + Math.random().toString(16).slice(2),
                full_name: '',
                phone: '',
                relation: '',
                selected: true
            }, 'npl_new'));
            refreshCustomer360Summaries();
        });
    }

    if (leiSourceSelect) {
        leiSourceSelect.addEventListener('change', function () {
            applyLeiSource(leiSourceSelect.value, '[]', '');
        });
    }

    modalEl.addEventListener('show.bs.modal', function (ev) {
        var trigger = ev.relatedTarget;
        if (trigger && trigger.classList && trigger.classList.contains('btn-open-restructure-modal')) {
            populateRestructureModal(trigger);
        }
    });

    document.addEventListener('click', function (ev) {
        var btn = ev.target && ev.target.closest ? ev.target.closest('.btn-open-restructure-modal') : null;
        if (!btn) return;
        populateRestructureModal(btn);
    });

    if (STICKY_MODAL_STATE && typeof STICKY_MODAL_STATE === 'object' && modalEl) {
        if (document.getElementById('restructure_contract_no')) {
            document.getElementById('restructure_contract_no').value = String(STICKY_MODAL_STATE.contract_no || '');
        }
        if (document.getElementById('restructure_contract_display')) {
            document.getElementById('restructure_contract_display').value = String(STICKY_MODAL_STATE.contract_no || '');
        }
        if (document.getElementById('restructure_customer_code')) {
            document.getElementById('restructure_customer_code').value = String(STICKY_MODAL_STATE.customer_code || '');
        }
        if (document.getElementById('restructure_customer_name')) {
            document.getElementById('restructure_customer_name').value = String(STICKY_MODAL_STATE.customer_name || '');
        }
        if (document.getElementById('restructure_outstanding_amount')) {
            document.getElementById('restructure_outstanding_amount').value = String(STICKY_MODAL_STATE.outstanding_amount || 0);
        }
        if (snapshotInput) {
            snapshotInput.value = String(STICKY_MODAL_STATE.customer_snapshot_json || '{}');
        }
        if (profileInput) {
            profileInput.value = String(STICKY_MODAL_STATE.customer_profile_json || '{}');
        }
        if (document.getElementById('restructure_customer_display')) {
            var sc = String(STICKY_MODAL_STATE.customer_code || '');
            var sn = String(STICKY_MODAL_STATE.customer_name || '');
            document.getElementById('restructure_customer_display').value = sc + (sn ? (' - ' + sn) : '');
        }
        if (document.getElementById('restructure_outstanding_display')) {
            document.getElementById('restructure_outstanding_display').value = toMoney(STICKY_MODAL_STATE.outstanding_amount || 0);
        }
        if (document.getElementById('restructure_new_rate')) {
            document.getElementById('restructure_new_rate').value = String(STICKY_MODAL_STATE.new_interest_rate_pct || '');
        }
        if (document.getElementById('restructure_new_term')) {
            document.getElementById('restructure_new_term').value = String(STICKY_MODAL_STATE.new_tenor_month || '');
        }
        if (houseCheckbox) houseCheckbox.checked = !!STICKY_MODAL_STATE.use_house_collateral;
        if (landCheckbox) landCheckbox.checked = !!STICKY_MODAL_STATE.use_land_collateral;
        if (carCheckbox) carCheckbox.checked = !!STICKY_MODAL_STATE.use_car_collateral;
        if (guarantorCheckbox) guarantorCheckbox.checked = !!STICKY_MODAL_STATE.use_existing_guarantors;

        var stickySelectedColRaw = String(STICKY_MODAL_STATE.selected_collateral_items_json || '[]');
        var stickySelectedGuaRaw = String(STICKY_MODAL_STATE.selected_guarantor_items_json || '[]');
        var stickyNewColRaw = String(STICKY_MODAL_STATE.new_collateral_items_json || '[]');
        var stickyNewGuaRaw = String(STICKY_MODAL_STATE.new_guarantor_items_json || '[]');

        hydrateFromRaw(
            profileInput ? profileInput.value : '{}',
            stickySelectedColRaw,
            stickySelectedGuaRaw,
            stickyNewColRaw,
            stickyNewGuaRaw,
            true
        );
        refreshCustomer360Summaries();
        if (leiSourceSelect) {
            leiSourceSelect.value = String(STICKY_MODAL_STATE.lei_source_id || '');
            if (!leiSourceSelect.value && leiSourceSelect.options.length > 0) {
                leiSourceSelect.selectedIndex = 0;
            }
            applyLeiSource(leiSourceSelect.value, '[]', String(STICKY_MODAL_STATE.lei_scenario || 'BASE'));
        }
        (function reopenWhenBootstrapReady(retry) {
            if (window.bootstrap && window.bootstrap.Modal && typeof window.bootstrap.Modal.getOrCreateInstance === 'function') {
                var modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
                return;
            }
            if (retry > 40) {
                return;
            }
            setTimeout(function () { reopenWhenBootstrapReady(retry + 1); }, 50);
        })(0);
    } else {
        hydrateFromRaw(
            profileInput ? profileInput.value : '{}',
            selectedCollateralInput ? selectedCollateralInput.value : '[]',
            selectedGuarantorInput ? selectedGuarantorInput.value : '[]',
            newCollateralInput ? newCollateralInput.value : '[]',
            newGuarantorInput ? newGuarantorInput.value : '[]',
            true
        );
        refreshCustomer360Summaries();
    }

    [houseCheckbox, landCheckbox, carCheckbox, guarantorCheckbox].forEach(function (el) {
        if (!el || !el.addEventListener) return;
        el.addEventListener('change', function () {
            applyLegacyCheckboxToRows();
            refreshCustomer360Summaries();
        });
    });

    [manualCollateralInput, manualGuarantorInput].forEach(function (el) {
        if (!el || !el.addEventListener) return;
        el.addEventListener('input', function () {
            renderEffectiveTotals();
        });
    });
})();
</script>

<?php include __DIR__ . '/../partials/footer.php';

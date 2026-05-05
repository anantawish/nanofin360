<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * @param mixed $value
 */
function credit_new_positive_number($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    if (!is_numeric((string)$value)) {
        return 0.0;
    }

    return max(0.0, (float)$value);
}

/**
 * @return array<int, array<string, mixed>>
 */
function credit_new_json_list(array $payload, string $key): array
{
    $value = $payload[$key] ?? [];
    if (is_array($value)) {
        return array_values(array_filter($value, static fn($row): bool => is_array($row)));
    }

    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, static fn($row): bool => is_array($row)));
        }
    }

    return [];
}

function credit_new_income_period_factor(string $period): float
{
    $period = trim($period);
    if ($period === 'Daily') {
        return 30.0;
    }
    if ($period === 'Yearly') {
        return 1.0 / 12.0;
    }

    return 1.0;
}

/**
 * @param array<int, array<string, mixed>> $occupationRows
 */
function credit_new_sum_monthly_income_from_occupations(array $occupationRows): float
{
    $total = 0.0;
    foreach ($occupationRows as $row) {
        $income = credit_new_positive_number($row['income_amount'] ?? 0);
        if ($income <= 0) {
            continue;
        }
        $period = (string)($row['income_period'] ?? 'Monthly');
        $total += $income * credit_new_income_period_factor($period);
    }

    return $total;
}

/**
 * @param array<int, array<string, mixed>> $occupationRows
 */
function credit_new_first_occupation_name(array $occupationRows): string
{
    foreach ($occupationRows as $row) {
        $name = trim((string)($row['occupation_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }

    return '';
}

/**
 * @param array<int, array<string, mixed>> $items
 */
function credit_new_sum_appraisal(array $items): float
{
    $total = 0.0;
    foreach ($items as $item) {
        $total += credit_new_positive_number(
            $item['appraisal']
            ?? $item['appraised_value']
            ?? $item['appraisal_value']
            ?? 0
        );
    }

    return $total;
}

/**
 * @param array<int, array<string, mixed>> $cars
 */
function credit_new_estimate_car_value(array $cars): float
{
    $total = 0.0;
    $currentYear = (int)(new DateTimeImmutable('now'))->format('Y');

    foreach ($cars as $car) {
        $year = (int)credit_new_positive_number($car['model_year'] ?? 0);
        if ($year < 1985 || $year > ($currentYear + 1)) {
            $year = $currentYear - 8;
        }

        $age = max(0, $currentYear - $year);
        $estimated = 800000.0 * pow(0.86, $age);
        $estimated = min(800000.0, max(50000.0, $estimated));
        $total += $estimated;
    }

    return $total;
}

function credit_new_attitude_factor(float $attitudeIndex, string $attitudeClass): float
{
    $attitudeClass = strtolower(trim($attitudeClass));
    if ($attitudeIndex >= 80 || $attitudeClass === 'high') {
        return 1.15;
    }
    if ($attitudeIndex >= 65) {
        return 1.08;
    }
    if ($attitudeIndex >= 50 || $attitudeClass === 'mid') {
        return 1.00;
    }
    if ($attitudeIndex >= 35) {
        return 0.85;
    }

    return 0.72;
}

function credit_new_attitude_label(string $attitudeClass): string
{
    $value = strtolower(trim($attitudeClass));
    if ($value === 'high') {
        return 'High';
    }
    if ($value === 'mid') {
        return 'Medium';
    }
    if ($value === 'low') {
        return 'Low';
    }

    return '-';
}

function credit_new_principal_from_payment(float $payment, float $annualRatePct, int $months): float
{
    if ($payment <= 0 || $months <= 0) {
        return 0.0;
    }

    $monthlyRate = $annualRatePct / 1200.0;
    if ($monthlyRate <= 0.0) {
        return $payment * $months;
    }

    $factor = (1.0 - pow(1.0 + $monthlyRate, -$months)) / $monthlyRate;

    return $payment * $factor;
}

function credit_new_income_band(float $income): string
{
    if ($income > 100000) {
        return 'GT_100000';
    }
    if ($income > 90000) {
        return 'GT_90000';
    }
    if ($income > 80000) {
        return 'GT_80000';
    }
    if ($income > 70000) {
        return 'GT_70000';
    }
    if ($income > 60000) {
        return 'GT_60000';
    }
    if ($income > 50000) {
        return 'GT_50000';
    }
    if ($income > 40000) {
        return 'GT_40000';
    }
    if ($income > 30000) {
        return 'GT_30000';
    }
    if ($income > 20000) {
        return 'GT_20000';
    }
    if ($income > 10000) {
        return 'GT_10000';
    }

    return 'LE_10000';
}

function credit_new_income_threshold(string $incomeBand): ?float
{
    $normalized = strtoupper(str_replace([',', ' '], '', trim($incomeBand)));
    if ($normalized === '') {
        return null;
    }

    if (preg_match('/^GT_(\d+)$/', $normalized, $match) === 1) {
        return (float)$match[1];
    }
    if (preg_match('/^(\d+)-(\d+)$/', $normalized, $match) === 1) {
        return (float)$match[1];
    }
    if (preg_match('/^(\d+)\+$/', $normalized, $match) === 1) {
        return (float)$match[1];
    }

    return null;
}

function credit_new_is_all_occupation_policy(string $policyJob): bool
{
    $value = trim($policyJob);
    if ($value === '') {
        return false;
    }

    $normalized = strtoupper(str_replace([' ', '-', '_'], '', $value));
    if (
        $normalized === '*'
        || $normalized === 'ALL'
        || $normalized === 'ANY'
        || $normalized === 'ALLOCCUPATION'
        || $normalized === 'ALLOCCUPATIONS'
        || $normalized === 'ANYOCCUPATION'
        || $normalized === 'ANYOCCUPATIONS'
    ) {
        return true;
    }

    $thaiAllOccupation = "\u{0E17}\u{0E38}\u{0E01}\u{0E2D}\u{0E32}\u{0E0A}\u{0E35}\u{0E1E}";
    if (function_exists('mb_strpos') && mb_strpos($value, $thaiAllOccupation) !== false) {
        return true;
    }
    if (strpos($value, $thaiAllOccupation) !== false) {
        return true;
    }

    // Backward compatibility for previously corrupted legacy encoding values
    if (strpos($value, 'All Occupations') !== false) {
        return true;
    }

    return false;
}

/**
 * @param array<int, string> $types
 */
function credit_new_collateral_type_match(string $policyType, array $types): bool
{
    $policyType = strtoupper(trim($policyType));
    $normalizedTypes = array_values(array_filter(
        array_map(static fn(string $t): string => strtoupper(trim($t)), $types),
        static fn(string $t): bool => $t !== '' && $t !== 'NONE'
    ));
    $map = array_fill_keys($normalizedTypes, true);

    // Empty/NONE = no collateral requirement (optional).
    if ($policyType === '' || $policyType === 'NONE') {
        return true;
    }
    if ($policyType === 'MIXED') {
        return $normalizedTypes !== [];
    }
    if ($policyType === 'HOUSE') {
        return isset($map['HOUSE']);
    }
    if ($policyType === 'LAND') {
        return isset($map['LAND']);
    }
    if ($policyType === 'CAR') {
        return isset($map['CAR']);
    }
    if ($policyType === 'MOTORCYCLE') {
        return isset($map['MOTORCYCLE']);
    }

    return true;
}

function credit_new_collateral_type_code_from_text(string $typeText): string
{
    $raw = trim($typeText);
    if ($raw === '') {
        return '';
    }

    $upper = strtoupper($raw);
    if ($upper === 'HOUSE' || $upper === 'HOUSE/BUILDING' || $upper === 'HOME') {
        return 'HOUSE';
    }
    if ($upper === 'LAND') {
        return 'LAND';
    }
    if ($upper === 'CAR' || $upper === 'AUTO') {
        return 'CAR';
    }
    if ($upper === 'MOTORCYCLE' || $upper === 'MOTORBIKE' || $upper === 'BIKE') {
        return 'MOTORCYCLE';
    }

    $thaiHouse = "\u{0E1A}\u{0E49}\u{0E32}\u{0E19}";
    $thaiLand = "\u{0E17}\u{0E35}\u{0E48}\u{0E14}\u{0E34}\u{0E19}";
    $thaiCar = "\u{0E23}\u{0E16}\u{0E22}\u{0E19}\u{0E15}\u{0E4C}";
    $thaiMotorcycle = "\u{0E21}\u{0E2D}\u{0E40}\u{0E15}\u{0E2D}\u{0E23}\u{0E4C}\u{0E44}\u{0E0B}\u{0E04}\u{0E4C}";

    if ($raw === $thaiHouse) {
        return 'HOUSE';
    }
    if ($raw === $thaiLand) {
        return 'LAND';
    }
    if ($raw === $thaiCar) {
        return 'CAR';
    }
    if ($raw === $thaiMotorcycle) {
        return 'MOTORCYCLE';
    }

    return '';
}

function credit_new_collateral_type_label(string $typeText): string
{
    $code = credit_new_collateral_type_code_from_text($typeText);
    if ($code === 'HOUSE') {
        return 'House/Building';
    }
    if ($code === 'LAND') {
        return 'Land';
    }
    if ($code === 'CAR') {
        return 'Car';
    }
    if ($code === 'MOTORCYCLE') {
        return 'Motorcycle';
    }

    $trimmed = trim($typeText);
    return $trimmed !== '' ? $trimmed : 'Collateral Asset';
}

/**
 * @return array<int, array<string, mixed>>
 */
function credit_new_collect_asset_rows(array $payload): array
{
    $rows = [];

    foreach (credit_new_json_list($payload, 'houses') as $item) {
        $rows[] = [
            'type' => 'House/Building',
            'district' => (string)($item['district'] ?? ''),
            'province' => (string)($item['province'] ?? ''),
            'ref_no' => (string)($item['deed_no'] ?? ''),
            'appraisal' => credit_new_positive_number($item['appraisal'] ?? 0),
        ];
    }

    foreach (credit_new_json_list($payload, 'lands') as $item) {
        $rows[] = [
            'type' => 'Land',
            'district' => (string)($item['district'] ?? ''),
            'province' => (string)($item['province'] ?? ''),
            'ref_no' => (string)($item['deed_no'] ?? ''),
            'appraisal' => credit_new_positive_number($item['appraisal'] ?? 0),
        ];
    }

    foreach (credit_new_json_list($payload, 'cars') as $item) {
        $rows[] = [
            'type' => 'Car',
            'district' => '',
            'province' => (string)($item['plate_province'] ?? ''),
            'ref_no' => (string)($item['plate_no'] ?? ''),
            'appraisal' => 0.0,
        ];
    }

    foreach (credit_new_json_list($payload, 'collateral_assets') as $item) {
        $typeText = trim((string)($item['collateral_type'] ?? ''));
        $rows[] = [
            'type' => credit_new_collateral_type_label($typeText),
            'district' => (string)($item['district'] ?? ''),
            'province' => (string)($item['province'] ?? ''),
            'ref_no' => (string)($item['asset_ref_no'] ?? ''),
            'appraisal' => credit_new_positive_number($item['appraisal_value'] ?? 0),
            'attachment_file' => (string)($item['attachment_file'] ?? ''),
        ];
    }

    return $rows;
}

/**
 * @return array<int, string>
 */
function credit_new_collect_collateral_type_codes(array $payload): array
{
    $types = [];
    $set = [];

    if (credit_new_json_list($payload, 'houses') !== []) {
        $types[] = 'HOUSE';
        $set['HOUSE'] = true;
    }
    if (credit_new_json_list($payload, 'lands') !== []) {
        $types[] = 'LAND';
        $set['LAND'] = true;
    }
    if (credit_new_json_list($payload, 'cars') !== []) {
        $types[] = 'CAR';
        $set['CAR'] = true;
    }

    foreach (credit_new_json_list($payload, 'collateral_assets') as $row) {
        $typeText = trim((string)($row['collateral_type'] ?? ''));
        $code = credit_new_collateral_type_code_from_text($typeText);
        if ($code !== '' && !isset($set[$code])) {
            $set[$code] = true;
            $types[] = $code;
        }
    }

    return $types;
}

/**
 * @return array<string, mixed>|null
 */
function credit_new_find_occupation_profile(string $occupationName, string $provinceName): ?array
{
    $occupationName = trim($occupationName);
    if ($occupationName === '') {
        return null;
    }

    $sql = '
        SELECT occupation_name, employment_type, province_name, avg_income_min, avg_income_default, avg_income_max
        FROM master_occupation
        WHERE is_latest = 1
          AND is_deleted = 0
          AND occupation_name = :occupation_name
    ';
    $params = [':occupation_name' => $occupationName];

    if ($provinceName !== '') {
        $sql .= ' ORDER BY CASE WHEN province_name = :province_name THEN 0 ELSE 1 END, id DESC';
        $params[':province_name'] = trim($provinceName);
    } else {
        $sql .= ' ORDER BY id DESC';
    }
    $sql .= ' LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @return array<string, mixed>|null
 */
function credit_new_fetch_branch_household_profile(string $branchCode): ?array
{
    return lei_fetch_branch_household_profile($branchCode);
}

/**
 * @param array<string, mixed>|null $profile
 * @return array{
 *   per_person_total:float,
 *   per_person_utility:float,
 *   per_person_non_utility:float,
 *   assumed_household_size:float,
 *   electricity_unit_rate:float,
 *   water_unit_rate:float,
 *   internet_mobile_monthly_fee:float
 * }
 */
function credit_new_lei_household_cost_per_person(?array $profile): array
{
    if (!is_array($profile)) {
        $profile = [];
    }

    $assumedHouseholdSize = credit_new_positive_number($profile['assumed_household_size'] ?? 0);
    if ($assumedHouseholdSize <= 0) {
        $assumedHouseholdSize = 2.5;
    }

    $electricityUnitRate = credit_new_positive_number($profile['electricity_unit_rate'] ?? 0);
    if ($electricityUnitRate <= 0) {
        $electricityUnitRate = 3.95;
    }

    $waterUnitRate = credit_new_positive_number($profile['water_unit_rate'] ?? 0);
    if ($waterUnitRate <= 0) {
        $waterUnitRate = 12.0;
    }

    $internetMobileFee = credit_new_positive_number($profile['internet_mobile_monthly_fee'] ?? 0);
    if ($internetMobileFee <= 0) {
        $internetMobileFee = 650.0;
    }

    $electricityUnitsPerPerson = credit_new_positive_number($profile['electricity_units_per_person'] ?? 0);
    if ($electricityUnitsPerPerson <= 0) {
        $electricityUnitsPerPerson = 45.0;
    }
    $waterUnitsPerPerson = credit_new_positive_number($profile['water_units_per_person'] ?? 0);
    if ($waterUnitsPerPerson <= 0) {
        $waterUnitsPerPerson = 4.5;
    }

    $utilityPerPerson = credit_new_positive_number($profile['utility_per_person_monthly'] ?? 0);
    if ($utilityPerPerson <= 0) {
        $utilityPerPerson = ($electricityUnitRate * $electricityUnitsPerPerson)
            + ($waterUnitRate * $waterUnitsPerPerson)
            + $internetMobileFee;
    }
    $utilityPerPerson = min(3000.0, max(600.0, $utilityPerPerson));

    $perPersonTotal = credit_new_positive_number($profile['household_per_person_monthly'] ?? 0);
    if ($perPersonTotal <= 0) {
        $baselineMonthly = credit_new_positive_number($profile['baseline_monthly'] ?? 0);
        if ($baselineMonthly > 0) {
            $perPersonTotal = $baselineMonthly / $assumedHouseholdSize;
        }
    }
    if ($perPersonTotal <= 0) {
        $perPersonTotal = 3200.0;
    }
    $perPersonTotal = min(12000.0, max($utilityPerPerson + 900.0, $perPersonTotal));

    $nonUtilityPerPerson = credit_new_positive_number($profile['non_utility_per_person_monthly'] ?? 0);
    if ($nonUtilityPerPerson <= 0) {
        $nonUtilityPerPerson = max(900.0, $perPersonTotal - $utilityPerPerson);
    }

    return [
        'per_person_total' => round($perPersonTotal, 2),
        'per_person_utility' => round($utilityPerPerson, 2),
        'per_person_non_utility' => round($nonUtilityPerPerson, 2),
        'assumed_household_size' => round($assumedHouseholdSize, 2),
        'electricity_unit_rate' => round($electricityUnitRate, 4),
        'water_unit_rate' => round($waterUnitRate, 4),
        'internet_mobile_monthly_fee' => round($internetMobileFee, 2),
    ];
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @return array<int, array<string, mixed>>
 */
function credit_new_fetch_pending_customers(array $scope, string $branchCode): array
{
    $scopeClause = access_scope_sql_clause('c.branch_code', 'scope_credit_new', $scope);
    $sql = '
        SELECT
            c.id,
            c.primary_ref AS customer_code,
            c.primary_name AS customer_name,
            c.branch_code,
            c.record_status AS customer_status,
            c.data_json,
            c.amount,
            COALESCE(a.overall_index, 0) AS attitude_index,
            COALESCE(a.overall_class, "") AS attitude_class
        FROM workflow_records c
        LEFT JOIN attitude_assessments a
            ON a.module_key = "customer_360"
           AND a.workflow_source_id = c.id
           AND a.is_latest = 1
           AND a.is_deleted = 0
        WHERE c.module_key = "customer_360"
          AND c.is_latest = 1
          AND c.is_deleted = 0
          AND c.record_status = "PENDING_CHECKER"
          ' . $scopeClause['sql'] . '
    ';

    $params = $scopeClause['params'];
    if ($branchCode !== '') {
        $sql .= ' AND c.branch_code = :branch_code';
        $params[':branch_code'] = $branchCode;
    }

    $limit = (int)(app_config()['credit_candidate_limit'] ?? 10000);
    if ($limit < 500) {
        $limit = 500;
    }
    $sql .= ' ORDER BY c.id DESC LIMIT ' . $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}


/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @return array<string, mixed>|null
 */
function credit_new_fetch_customer_by_code(array $scope, string $customerCode): ?array
{
    $customerCode = strtoupper(trim($customerCode));
    if ($customerCode === '') {
        return null;
    }

    $scopeClause = access_scope_sql_clause('c.branch_code', 'scope_credit_new_pick_one', $scope);
    $sql = '
        SELECT
            c.id,
            c.primary_ref AS customer_code,
            c.primary_name AS customer_name,
            c.branch_code,
            c.record_status AS customer_status,
            c.data_json,
            c.amount,
            COALESCE(a.overall_index, 0) AS attitude_index,
            COALESCE(a.overall_class, "") AS attitude_class
        FROM workflow_records c
        LEFT JOIN attitude_assessments a
            ON a.module_key = "customer_360"
           AND a.workflow_source_id = c.id
           AND a.is_latest = 1
           AND a.is_deleted = 0
        WHERE c.module_key = "customer_360"
          AND c.is_latest = 1
          AND c.is_deleted = 0
          AND c.primary_ref = :customer_code
          ' . $scopeClause['sql'] . '
        ORDER BY c.id DESC
        LIMIT 1
    ';

    $params = $scopeClause['params'];
    $params[':customer_code'] = $customerCode;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}
/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @return array<int, array<string, mixed>>
 */
function credit_new_fetch_assessed_customers(array $scope, string $branchCode): array
{
    $scopeClause = access_scope_sql_clause('c.branch_code', 'scope_credit_new_assessed', $scope);
    $sql = '
        SELECT
            c.id,
            c.primary_ref AS customer_code,
            c.primary_name AS customer_name,
            c.branch_code,
            c.record_status AS customer_status,
            c.data_json,
            c.amount,
            COALESCE(a.overall_index, 0) AS attitude_index,
            COALESCE(a.overall_class, "") AS attitude_class,
            s.record_uid AS assessment_record_uid
        FROM workflow_records s
        INNER JOIN workflow_records c
            ON c.module_key = "customer_360"
           AND c.is_latest = 1
           AND c.is_deleted = 0
           AND (
                c.primary_ref = s.customer_ref
                OR c.primary_ref = s.primary_name
           )
        LEFT JOIN attitude_assessments a
            ON a.module_key = "customer_360"
           AND a.workflow_source_id = c.id
           AND a.is_latest = 1
           AND a.is_deleted = 0
        WHERE s.module_key = "credit_scoring"
          AND s.is_latest = 1
          AND s.is_deleted = 0
          ' . $scopeClause['sql'] . '
    ';

    $params = $scopeClause['params'];
    if ($branchCode !== '') {
        $sql .= ' AND c.branch_code = :branch_code';
        $params[':branch_code'] = $branchCode;
    }

    $limit = (int)(app_config()['credit_candidate_limit'] ?? 10000);
    if ($limit < 500) {
        $limit = 500;
    }
    $sql .= ' ORDER BY s.id DESC LIMIT ' . $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @return array<string, mixed>|null
 */
function credit_new_find_pending_customer_by_code(array $scope, string $customerCode, string $branchCode): ?array
{
    $customerCode = strtoupper(trim($customerCode));
    if ($customerCode === '') {
        return null;
    }

    $scopeClause = access_scope_sql_clause('c.branch_code', 'scope_credit_pick', $scope);
    $sql = '
        SELECT
            c.*,
            COALESCE(a.overall_index, 0) AS attitude_index,
            COALESCE(a.overall_class, "") AS attitude_class
        FROM workflow_records c
        LEFT JOIN attitude_assessments a
            ON a.module_key = "customer_360"
           AND a.workflow_source_id = c.id
           AND a.is_latest = 1
           AND a.is_deleted = 0
        WHERE c.module_key = "customer_360"
          AND c.is_latest = 1
          AND c.is_deleted = 0
          AND c.record_status = "PENDING_CHECKER"
          ' . $scopeClause['sql'] . '
          AND c.primary_ref = :customer_code
    ';

    $params = $scopeClause['params'];
    $params[':customer_code'] = $customerCode;
    if ($branchCode !== '') {
        $sql .= ' AND c.branch_code = :branch_code';
        $params[':branch_code'] = $branchCode;
    }
    $sql .= ' ORDER BY c.id DESC LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @return array<string, mixed>|null
 */
function credit_new_find_customer_by_code_any_status(array $scope, string $customerCode, string $branchCode): ?array
{
    $customerCode = strtoupper(trim($customerCode));
    if ($customerCode === '') {
        return null;
    }

    $scopeClause = access_scope_sql_clause('c.branch_code', 'scope_credit_pick_any_status', $scope);
    $sql = '
        SELECT
            c.*,
            COALESCE(a.overall_index, 0) AS attitude_index,
            COALESCE(a.overall_class, "") AS attitude_class
        FROM workflow_records c
        LEFT JOIN attitude_assessments a
            ON a.module_key = "customer_360"
           AND a.workflow_source_id = c.id
           AND a.is_latest = 1
           AND a.is_deleted = 0
        WHERE c.module_key = "customer_360"
          AND c.is_latest = 1
          AND c.is_deleted = 0
          ' . $scopeClause['sql'] . '
          AND c.primary_ref = :customer_code
    ';

    $params = $scopeClause['params'];
    $params[':customer_code'] = $customerCode;
    if ($branchCode !== '') {
        $sql .= ' AND c.branch_code = :branch_code';
        $params[':branch_code'] = $branchCode;
    }

    $sql .= ' ORDER BY c.id DESC LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @return array<string, mixed>|null
 */
function credit_new_find_customer_request_for_review(array $scope, string $customerCode, string $branchCode): ?array
{
    $customerCode = strtoupper(trim($customerCode));
    if ($customerCode === '') {
        return null;
    }

    $scopeClause = access_scope_sql_clause('c.branch_code', 'scope_credit_review_pick', $scope);
    $sql = '
        SELECT c.*
        FROM workflow_records c
        WHERE c.module_key = "customer_360"
          AND c.is_latest = 1
          AND c.is_deleted = 0
          AND c.record_status = "PENDING_CHECKER"
          ' . $scopeClause['sql'] . '
          AND c.primary_ref = :customer_code
    ';
    $params = $scopeClause['params'];
    $params[':customer_code'] = $customerCode;
    if ($branchCode !== '') {
        $sql .= ' AND c.branch_code = :branch_code';
        $params[':branch_code'] = $branchCode;
    }
    $sql .= ' ORDER BY c.id DESC LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * @param array<string, mixed> $sourceCustomer
 */
function credit_new_set_customer_request_status(array $sourceCustomer, string $targetStatus, string $reason): void
{
    $targetStatus = strtoupper(trim($targetStatus));
    if (!in_array($targetStatus, ['APPROVED', 'REJECTED'], true)) {
        throw new RuntimeException('Invalid target status for update');
    }

    $customerModule = module_by_key('customer_360');
    if (!is_array($customerModule)) {
        throw new RuntimeException('customer_360 module not found');
    }

    $sourceId = (int)($sourceCustomer['id'] ?? 0);
    if ($sourceId <= 0) {
        throw new RuntimeException('Request record to update not found');
    }

    $latest = module_find_latest_by_id('customer_360', $sourceId);
    if ($latest === null) {
        throw new RuntimeException('Latest request version for status update not found');
    }
    if ((int)($latest['is_deleted'] ?? 0) === 1) {
        throw new RuntimeException('Request has already been soft-deleted');
    }
    if (strtoupper(trim((string)($latest['record_status'] ?? ''))) !== 'PENDING_CHECKER') {
        throw new RuntimeException('Request is no longer in pending checker status');
    }

    $payload = json_decode((string)($latest['data_json'] ?? ''), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $common = normalize_common_payload($latest, $latest, $customerModule);
    $common['record_status'] = $targetStatus;

    $actionType = $targetStatus === 'APPROVED' ? 'APPROVE' : 'REJECT';
    module_persist_new_version(
        'customer_360',
        (string)$latest['record_uid'],
        ((int)$latest['version_no']) + 1,
        $payload,
        $common,
        $actionType,
        $reason !== '' ? $reason : ($targetStatus === 'APPROVED' ? 'Approved request from Module 2' : 'Rejected request from Module 2'),
        $latest,
        false,
        $targetStatus
    );
}

/**
 * @return array<int, string>
 */
function credit_new_candidate_search_options(array $candidateRows): array
{
    $set = [];
    foreach ($candidateRows as $row) {
        $code = strtoupper(trim((string)($row['customer_code'] ?? '')));
        if ($code === '') {
            continue;
        }

        $fullName = trim((string)($row['customer_name'] ?? ''));
        $payload = json_decode((string)($row['data_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $firstName = trim((string)($payload['first_name'] ?? ''));
        $lastName = trim((string)($payload['last_name'] ?? ''));

        $set[$code . ' | ' . $fullName] = true;
        $name = trim($firstName . ' ' . $lastName);
        if ($name !== '') {
            $set[$name . ' | ' . $code] = true;
            $set[$lastName . ' ' . $firstName . ' | ' . $code] = true;
        } elseif ($fullName !== '') {
            $set[$fullName . ' | ' . $code] = true;
        }
    }

    $options = array_keys($set);
    natcasesort($options);
    return array_values($options);
}

/**
 * @return array<int, string>
 */
function credit_new_find_customer_codes_by_lookup(string $lookupText, array $candidateRows): array
{
    $lookupText = trim($lookupText);
    if ($lookupText === '') {
        return [];
    }

    $matches = [];
    if (preg_match('/(CUS[0-9A-Z]+)/i', $lookupText, $m) === 1) {
        $matches[] = strtoupper((string)$m[1]);
        return $matches;
    }

    $needle = mb_strtolower($lookupText, 'UTF-8');
    foreach ($candidateRows as $row) {
        $code = strtoupper(trim((string)($row['customer_code'] ?? '')));
        if ($code === '') {
            continue;
        }

        $payload = json_decode((string)($row['data_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $firstName = trim((string)($payload['first_name'] ?? ''));
        $lastName = trim((string)($payload['last_name'] ?? ''));
        $fullName = trim((string)($row['customer_name'] ?? ''));
        $tokens = [
            $code,
            $fullName,
            $firstName,
            $lastName,
            trim($firstName . ' ' . $lastName),
            trim($lastName . ' ' . $firstName),
        ];

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (mb_stripos(mb_strtolower($token, 'UTF-8'), $needle, 0, 'UTF-8') !== false) {
                $matches[$code] = true;
                break;
            }
        }
    }

    return array_values(array_keys($matches));
}

/**
 * @return array<int, array<string, mixed>>
 */
function credit_new_fetch_policy_rows(): array
{
    $sql = '
        SELECT id, record_uid, primary_ref, primary_name, data_json, record_status, updated_at
        FROM workflow_records
        WHERE module_key = "credit_policy"
          AND is_latest = 1
          AND is_deleted = 0
          AND record_status = "APPROVED"
        ORDER BY updated_at DESC, id DESC
        LIMIT 300
    ';

    $rows = db()->query($sql)->fetchAll();
    if ($rows !== []) {
        return $rows;
    }

    $fallbackSql = '
        SELECT id, record_uid, primary_ref, primary_name, data_json, record_status, updated_at
        FROM workflow_records
        WHERE module_key = "credit_policy"
          AND is_latest = 1
          AND is_deleted = 0
        ORDER BY updated_at DESC, id DESC
        LIMIT 300
    ';

    return db()->query($fallbackSql)->fetchAll();
}

/**
 * @param array<int, array<string, mixed>> $policyRows
 * @return array<string, mixed>|null
 */
function credit_new_find_policy_by_id(array $policyRows, int $policyId): ?array
{
    if ($policyId <= 0) {
        return null;
    }

    foreach ($policyRows as $row) {
        if ((int)($row['id'] ?? 0) === $policyId) {
            return $row;
        }
    }

    return null;
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @return array<int, array<string, mixed>>
 */
function credit_new_fetch_lei_rows(array $scope, string $branchCode): array
{
    $scopeClause = access_scope_sql_clause('l.branch_code', 'scope_credit_lei_rows', $scope);
    $sql = '
        SELECT
            l.id,
            l.primary_ref,
            l.primary_name,
            l.branch_code,
            l.event_date,
            l.updated_at,
            l.data_json,
            l.record_status
        FROM workflow_records l
        WHERE l.module_key = "local_economy_lei"
          AND l.is_latest = 1
          AND l.is_deleted = 0
          ' . $scopeClause['sql'] . '
    ';
    $params = $scopeClause['params'];

    if ($branchCode !== '') {
        $sql .= ' AND l.branch_code = :branch_code';
        $params[':branch_code'] = $branchCode;
    }

    $sql .= ' ORDER BY l.event_date DESC, l.updated_at DESC, l.id DESC LIMIT 300';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @return array<string, mixed>|null
 */
function credit_new_find_lei_row_by_id(array $scope, int $leiRecordId, string $branchCode): ?array
{
    if ($leiRecordId <= 0) {
        return null;
    }

    $scopeClause = access_scope_sql_clause('l.branch_code', 'scope_credit_lei_pick', $scope);
    $sql = '
        SELECT
            l.id,
            l.primary_ref,
            l.primary_name,
            l.branch_code,
            l.event_date,
            l.updated_at,
            l.data_json,
            l.record_status
        FROM workflow_records l
        WHERE l.module_key = "local_economy_lei"
          AND l.is_latest = 1
          AND l.is_deleted = 0
          ' . $scopeClause['sql'] . '
          AND l.id = :lei_id
    ';
    $params = $scopeClause['params'];
    $params[':lei_id'] = $leiRecordId;

    if ($branchCode !== '') {
        $sql .= ' AND l.branch_code = :branch_code';
        $params[':branch_code'] = $branchCode;
    }

    $sql .= ' ORDER BY l.id DESC LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @param array<string, mixed>|null $leiRow
 * @return array<string, mixed>
 */
function credit_new_extract_lei_payload(?array $leiRow): array
{
    if (!is_array($leiRow)) {
        return [];
    }

    $payload = json_decode((string)($leiRow['data_json'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

function credit_new_score_clamp(float $value): float
{
    return round(max(0.0, min(100.0, $value)), 2);
}

/**
 * @param array<string, mixed> $leiPayload
 * @return array{
 *   household_index:float,
 *   household_baseline_monthly:float,
 *   assumed_household_size:float,
 *   household_per_person_monthly:float,
 *   utility_per_person_monthly:float,
 *   non_utility_per_person_monthly:float,
 *   electricity_unit_rate:float,
 *   water_unit_rate:float,
 *   internet_mobile_monthly_fee:float
 * }
 */
function credit_new_lei_profile_from_payload(array $leiPayload): array
{
    $referenceMap = [
        'rice_5kg_price' => ['base' => 180.0, 'weight' => 0.035],
        'egg_no3_tray_price' => ['base' => 120.0, 'weight' => 0.035],
        'pork_red_meat_price_kg' => ['base' => 165.0, 'weight' => 0.04],
        'chicken_breast_price_kg' => ['base' => 95.0, 'weight' => 0.035],
        'cooking_oil_1l_price' => ['base' => 55.0, 'weight' => 0.03],
        'sugar_price_kg' => ['base' => 28.0, 'weight' => 0.02],
        'instant_noodle_pack_price' => ['base' => 55.0, 'weight' => 0.02],
        'kitchen_veg_price' => ['base' => 20.0, 'weight' => 0.02],
        'street_food_plate_price' => ['base' => 55.0, 'weight' => 0.04],
        'curry_rice_two_item_price' => ['base' => 65.0, 'weight' => 0.04],
        'thai_tea_price' => ['base' => 30.0, 'weight' => 0.02],
        'bottled_water_600ml_price' => ['base' => 12.0, 'weight' => 0.02],
        'standard_set_meal_price' => ['base' => 129.0, 'weight' => 0.03],
        'fuel_price_per_liter' => ['base' => 38.0, 'weight' => 0.06],
        'motorbike_taxi_fare' => ['base' => 35.0, 'weight' => 0.03],
        'public_transport_fare' => ['base' => 18.0, 'weight' => 0.03],
        'parking_hourly_fee' => ['base' => 25.0, 'weight' => 0.015],
        'lpg_15kg_price' => ['base' => 430.0, 'weight' => 0.03],
        'men_haircut_price' => ['base' => 120.0, 'weight' => 0.015],
        'laundry_service_price' => ['base' => 35.0, 'weight' => 0.015],
        'internet_mobile_monthly_fee' => ['base' => 650.0, 'weight' => 0.04],
        'electricity_unit_rate' => ['base' => 4.2, 'weight' => 0.03],
        'water_unit_rate' => ['base' => 12.0, 'weight' => 0.025],
        'soap_shampoo_price' => ['base' => 95.0, 'weight' => 0.015],
        'detergent_medium_bag_price' => ['base' => 85.0, 'weight' => 0.015],
        'tissue_pack_24_roll_price' => ['base' => 220.0, 'weight' => 0.015],
        'beer_can_price' => ['base' => 60.0, 'weight' => 0.01],
        'cigarette_pack_price' => ['base' => 125.0, 'weight' => 0.01],
        'movie_ticket_price' => ['base' => 220.0, 'weight' => 0.01],
        'rent_room_monthly_price' => ['base' => 4500.0, 'weight' => 0.15],
        'actual_daily_wage' => ['base' => 350.0, 'weight' => 0.06],
    ];

    $weightedRatioSum = 0.0;
    $weightSum = 0.0;
    foreach ($referenceMap as $field => $cfg) {
        $value = credit_new_positive_number($leiPayload[$field] ?? 0);
        if ($value <= 0) {
            continue;
        }
        $base = (float)$cfg['base'];
        $weight = (float)$cfg['weight'];
        if ($base <= 0 || $weight <= 0) {
            continue;
        }
        $ratio = $value / $base;
        $ratio = max(0.40, min(3.00, $ratio));
        $weightedRatioSum += $ratio * $weight;
        $weightSum += $weight;
    }

    $indexRatio = $weightSum > 0 ? ($weightedRatioSum / $weightSum) : 1.0;
    $indexRatio = max(0.70, min(3.00, $indexRatio));
    $householdIndex = round($indexRatio * 100, 2);
    $householdBaseline = round(max(3500.0, min(50000.0, 7800.0 * $indexRatio)), 2);

    $assumedHouseholdSize = 2.5;
    $electricityUnitRate = credit_new_positive_number($leiPayload['electricity_unit_rate'] ?? 0);
    if ($electricityUnitRate <= 0) {
        $electricityUnitRate = 3.95;
    }
    $waterUnitRate = credit_new_positive_number($leiPayload['water_unit_rate'] ?? 0);
    if ($waterUnitRate <= 0) {
        $waterUnitRate = 12.0;
    }
    $internetMobileMonthlyFee = credit_new_positive_number($leiPayload['internet_mobile_monthly_fee'] ?? 0);
    if ($internetMobileMonthlyFee <= 0) {
        $internetMobileMonthlyFee = 650.0;
    }

    $electricityUnitsPerPerson = 45.0;
    $waterUnitsPerPerson = 4.5;
    $utilityPerPerson = ($electricityUnitRate * $electricityUnitsPerPerson)
        + ($waterUnitRate * $waterUnitsPerPerson)
        + $internetMobileMonthlyFee;
    $utilityPerPerson = max(600.0, min(3000.0, $utilityPerPerson));

    $perPersonTotal = max($utilityPerPerson + 900.0, $householdBaseline / $assumedHouseholdSize);
    $perPersonTotal = min(12000.0, max(1200.0, $perPersonTotal));
    $nonUtilityPerPerson = max(900.0, $perPersonTotal - $utilityPerPerson);

    return [
        'household_index' => $householdIndex,
        'household_baseline_monthly' => $householdBaseline,
        'assumed_household_size' => $assumedHouseholdSize,
        'household_per_person_monthly' => round($perPersonTotal, 2),
        'utility_per_person_monthly' => round($utilityPerPerson, 2),
        'non_utility_per_person_monthly' => round($nonUtilityPerPerson, 2),
        'electricity_unit_rate' => round($electricityUnitRate, 4),
        'water_unit_rate' => round($waterUnitRate, 4),
        'internet_mobile_monthly_fee' => round($internetMobileMonthlyFee, 2),
    ];
}

/**
 * @param array<string, mixed> $leiPayload
 * @param array<string, float|int> $context
 * @return array<string, float>
 */
function credit_new_build_lei_indices(array $leiPayload, array $context): array
{
    $weightedBases = [
        'rice_5kg_price' => ['base' => 195.0, 'weight' => 0.12],
        'egg_no3_tray_price' => ['base' => 110.0, 'weight' => 0.10],
        'pork_red_meat_price_kg' => ['base' => 145.0, 'weight' => 0.10],
        'chicken_breast_price_kg' => ['base' => 80.0, 'weight' => 0.08],
        'cooking_oil_1l_price' => ['base' => 49.0, 'weight' => 0.08],
        'sugar_price_kg' => ['base' => 27.5, 'weight' => 0.06],
        'instant_noodle_pack_price' => ['base' => 36.0, 'weight' => 0.06],
        'kitchen_veg_price' => ['base' => 10.0, 'weight' => 0.10],
        'fuel_price_per_liter' => ['base' => 40.0, 'weight' => 0.12],
        'soap_shampoo_price' => ['base' => 100.0, 'weight' => 0.10],
        'tissue_pack_24_roll_price' => ['base' => 190.0, 'weight' => 0.08],
    ];

    $costRatio = 0.0;
    $weightSum = 0.0;
    foreach ($weightedBases as $field => $cfg) {
        $value = credit_new_positive_number($leiPayload[$field] ?? 0);
        if ($value <= 0) {
            continue;
        }
        $ratio = $value / (float)$cfg['base'];
        $ratio = max(0.30, min(3.00, $ratio));
        $weight = (float)$cfg['weight'];
        $costRatio += $ratio * $weight;
        $weightSum += $weight;
    }
    $bciRatio = $weightSum > 0 ? ($costRatio / $weightSum) : 1.0;
    $bciScore = credit_new_score_clamp(100.0 / max(0.35, $bciRatio));

    $dailyFood = (
        credit_new_positive_number($leiPayload['street_food_plate_price'] ?? 0)
        + credit_new_positive_number($leiPayload['curry_rice_two_item_price'] ?? 0)
        + credit_new_positive_number($leiPayload['thai_tea_price'] ?? 0)
    ) / 3.0;
    if ($dailyFood <= 0) {
        $dailyFood = 55.0;
    }

    $rent = credit_new_positive_number($leiPayload['rent_room_monthly_price'] ?? 0);
    if ($rent <= 0) {
        $rent = 4500.0;
    }

    $utilities = credit_new_positive_number($leiPayload['internet_mobile_monthly_fee'] ?? 0)
        + (credit_new_positive_number($leiPayload['electricity_unit_rate'] ?? 0) * 90.0)
        + (credit_new_positive_number($leiPayload['water_unit_rate'] ?? 0) * 12.0);
    if ($utilities <= 0) {
        $utilities = 1200.0;
    }

    $transport = (credit_new_positive_number($leiPayload['fuel_price_per_liter'] ?? 0) * 18.0)
        + (credit_new_positive_number($leiPayload['public_transport_fare'] ?? 0) * 40.0)
        + (credit_new_positive_number($leiPayload['motorbike_taxi_fare'] ?? 0) * 16.0);
    if ($transport <= 0) {
        $transport = 1400.0;
    }

    $socialCost = (credit_new_positive_number($leiPayload['beer_can_price'] ?? 0) * 4.0)
        + (credit_new_positive_number($leiPayload['movie_ticket_price'] ?? 0) * 1.0)
        + (credit_new_positive_number($leiPayload['cigarette_pack_price'] ?? 0) * 2.0);

    $mliMonthly = ($dailyFood * 30.0) + $rent + $utilities + $transport + $socialCost;
    $incomeScenario = max(1.0, (float)($context['income_total_scenario'] ?? 0));
    $mliBurden = $mliMonthly / $incomeScenario;
    $mliScore = credit_new_score_clamp((1.20 - $mliBurden) * (100.0 / 1.20));

    $actualDailyWage = credit_new_positive_number($leiPayload['actual_daily_wage'] ?? 0);
    if ($actualDailyWage <= 0) {
        $actualDailyWage = 380.0;
    }
    $localPop = max(1.0, credit_new_positive_number($leiPayload['local_population_count'] ?? 0));
    $floatingPop = credit_new_positive_number($leiPayload['floating_population_count'] ?? 0);
    $latentPop = $localPop + $floatingPop;
    $jobOpenings = credit_new_positive_number($leiPayload['job_opening_count'] ?? 0);

    $factoryWorkers = credit_new_positive_number($leiPayload['factory_worker_count'] ?? 0);
    $retailWorkers = credit_new_positive_number($leiPayload['retail_worker_count'] ?? 0);
    $companyWorkers = credit_new_positive_number($leiPayload['company_worker_count'] ?? 0);
    $partnershipWorkers = credit_new_positive_number($leiPayload['partnership_worker_count'] ?? 0);
    $hospitalWorkers = credit_new_positive_number($leiPayload['hospital_worker_count'] ?? 0);
    $govProjectWorkers = credit_new_positive_number($leiPayload['government_project_worker_count'] ?? 0);
    $nonAgriWorkers = $factoryWorkers + $retailWorkers + $companyWorkers + $partnershipWorkers + $hospitalWorkers + $govProjectWorkers;

    $wageScore = credit_new_score_clamp(($actualDailyWage / 450.0) * 100.0);
    $nonAgriRatio = $nonAgriWorkers / $localPop;
    $employmentScore = credit_new_score_clamp(($nonAgriRatio / 0.45) * 100.0);
    $nawiScore = credit_new_score_clamp(($wageScore * 0.55) + ($employmentScore * 0.45));

    $agriArea = credit_new_positive_number($leiPayload['agricultural_area_rai'] ?? 0);
    $agriAreaPerCapita = $agriArea / $localPop;
    $agriAreaScore = credit_new_score_clamp(($agriAreaPerCapita / 2.0) * 100.0);
    $agriWageProxy = max(280.0, min(420.0, $actualDailyWage * 0.82));
    $agriWageScore = credit_new_score_clamp(($agriWageProxy / 350.0) * 100.0);
    $awiScore = credit_new_score_clamp(($agriWageScore * 0.60) + ($agriAreaScore * 0.40));

    $joiRaw = $jobOpenings / max(1.0, $latentPop);
    $newJobScore = credit_new_score_clamp(($joiRaw / 0.05) * 100.0);
    $unemploymentSecurityScore = credit_new_score_clamp(($newJobScore * 0.45) + ($nawiScore * 0.35) + ($awiScore * 0.20));

    $familyExpense = max(0.0, (float)($context['family_expense'] ?? 0));
    $familyExpenseRatio = $familyExpense / $incomeScenario;
    $familySupportScore = credit_new_score_clamp((0.60 - $familyExpenseRatio) * (100.0 / 0.60));

    $customerExpenseScore = credit_new_score_clamp(($bciScore * 0.45) + ($mliScore * 0.55));

    $nawiIncomeMonthly = $actualDailyWage * 26.0;
    $lppiValue = $nawiIncomeMonthly - $mliMonthly;
    $lppiScore = credit_new_score_clamp(50.0 + (($lppiValue / 10000.0) * 50.0));

    $businessCount = max(1.0, credit_new_positive_number($leiPayload['business_count'] ?? 0));
    $avgSpending = max(2500.0, $mliMonthly / 2.5);
    $marketSize = $latentPop * $avgSpending;
    $iviPerBusiness = $marketSize / $businessCount;
    $iviScore = credit_new_score_clamp(($iviPerBusiness / 40000.0) * 100.0);

    $rentMonthly = credit_new_positive_number($leiPayload['rent_room_monthly_price'] ?? 0);
    if ($rentMonthly <= 0) {
        $rentMonthly = 4500.0;
    }
    $propertyPrice = credit_new_positive_number($leiPayload['property_price_estimate'] ?? 0);
    if ($propertyPrice <= 0) {
        $propertyPrice = credit_new_positive_number($leiPayload['property_value_estimate'] ?? 0);
    }
    if ($propertyPrice <= 0) {
        $propertyPrice = 1800000.0;
    }
    $reyYieldPct = (($rentMonthly * 12.0) / max(1.0, $propertyPrice)) * 100.0;
    $reyScore = credit_new_score_clamp(($reyYieldPct / 8.0) * 100.0);

    return [
        'bci_score' => $bciScore,
        'mli_score' => $mliScore,
        'nawi_score' => $nawiScore,
        'awi_score' => $awiScore,
        'joi_score' => $newJobScore,
        'rey_score' => $reyScore,
        'lppi_score' => $lppiScore,
        'ivi_score' => $iviScore,
        'customer_expense_index' => $customerExpenseScore,
        'family_support_index' => $familySupportScore,
        'unemployment_index' => $unemploymentSecurityScore,
        'new_job_index' => $newJobScore,
        'rey_yield_pct' => round($reyYieldPct, 4),
        'bci_ratio' => round($bciRatio, 4),
        'mli_monthly' => round($mliMonthly, 2),
        'mli_burden_ratio' => round($mliBurden, 4),
        'joi_raw' => round($joiRaw, 6),
    ];
}
/**
 * @param array<string, mixed> $row
 * @param array{code:string,label:string,cost_multiplier:float,income_multiplier:float,pd_shift_pct:float,npl_shift_pct:float,description:string} $leiScenario
 * @param array<string, mixed>|null $selectedLeiRow
 * @return array<string, mixed>
 */
function credit_new_calculate_capacity(array $row, array $leiScenario, ?array $selectedLeiRow = null): array
{
    $payload = json_decode((string)($row['data_json'] ?? ''), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $borrowerOccupations = credit_new_json_list($payload, 'borrower_occupations');
    $spouseOccupations = credit_new_json_list($payload, 'spouse_occupations');

    $borrowerIncome = credit_new_sum_monthly_income_from_occupations($borrowerOccupations);
    $spouseIncome = credit_new_sum_monthly_income_from_occupations($spouseOccupations);
    $incomeTotal = $borrowerIncome + $spouseIncome;
    if ($incomeTotal <= 0) {
        $incomeTotal = credit_new_positive_number($payload['monthly_income'] ?? ($row['amount'] ?? 0));
    }
    if ($incomeTotal <= 0) {
        $incomeTotal = 10000.0;
    }

    $scenarioCostMultiplier = credit_new_positive_number($leiScenario['cost_multiplier'] ?? 1.0);
    if ($scenarioCostMultiplier <= 0) {
        $scenarioCostMultiplier = 1.0;
    }
    $scenarioIncomeMultiplier = credit_new_positive_number($leiScenario['income_multiplier'] ?? 1.0);
    if ($scenarioIncomeMultiplier <= 0) {
        $scenarioIncomeMultiplier = 1.0;
    }
    $scenarioPdShiftPct = (float)($leiScenario['pd_shift_pct'] ?? 0.0);

    $incomeTotalScenario = max(0.0, $incomeTotal * $scenarioIncomeMultiplier);

    $occupationName = credit_new_first_occupation_name($borrowerOccupations);
    if ($occupationName === '') {
        $occupationName = trim((string)($payload['occupation'] ?? ''));
    }
    $provinceName = trim((string)($payload['province'] ?? ''));
    $occupationProfile = credit_new_find_occupation_profile($occupationName, $provinceName);
    $employmentType = strtoupper(trim((string)($occupationProfile['employment_type'] ?? 'OTHER')));
    $avgIncomeDefault = credit_new_positive_number($occupationProfile['avg_income_default'] ?? 0);

    $expenseRatioMap = [
        'GOVERNMENT' => 0.43,
        'PRIVATE' => 0.50,
        'AGRICULTURE' => 0.58,
    ];
    $borrowerExpenseRatio = $expenseRatioMap[$employmentType] ?? 0.56;
    if ($avgIncomeDefault > 0 && $borrowerIncome > 0 && $borrowerIncome < ($avgIncomeDefault * 0.70)) {
        $borrowerExpenseRatio += 0.03;
    } elseif ($avgIncomeDefault > 0 && $borrowerIncome > ($avgIncomeDefault * 1.50)) {
        $borrowerExpenseRatio -= 0.02;
    }
    $borrowerExpenseRatio = min(0.75, max(0.35, $borrowerExpenseRatio));
    $spouseExpenseRatio = $spouseIncome > 0 ? 0.50 : 0.00;
    $occupationExpenseBase = ($borrowerIncome * $borrowerExpenseRatio) + ($spouseIncome * $spouseExpenseRatio);
    $occupationExpense = $occupationExpenseBase * $scenarioCostMultiplier;

    $spouseName = trim(
        (string)($payload['spouse_name'] ?? (trim((string)($payload['spouse_first_name'] ?? '')) . ' ' . trim((string)($payload['spouse_last_name'] ?? ''))))
    );
    $spouseCount = trim($spouseName) !== '' ? 1 : 0;
    $children = credit_new_json_list($payload, 'children');
    $childrenCount = count($children);
    $dependents = (int)floor(credit_new_positive_number($payload['dependents'] ?? 0));
    if ($dependents < 0) {
        $dependents = 0;
    }

    $childSupportExpense = ($childrenCount * 2500.0) + ($dependents * 2000.0);
    $spouseExpense = $spouseCount > 0 ? 3500.0 : 0.0;
    $familyExpenseBase = $childSupportExpense + $spouseExpense;
    $familyExpense = $familyExpenseBase * $scenarioCostMultiplier;

    $debtBurden = credit_new_positive_number($payload['debt_burden'] ?? 0);
    $liabilities = credit_new_json_list($payload, 'liabilities');
    $liabilityInstallment = 0.0;
    foreach ($liabilities as $liability) {
        $outstanding = credit_new_positive_number($liability['outstanding_balance'] ?? 0);
        if ($outstanding <= 0) {
            continue;
        }

        $termMonths = (int)floor(credit_new_positive_number($liability['contract_term_months'] ?? 0));
        if ($termMonths > 0) {
            $liabilityInstallment += $outstanding / max(6, min(120, $termMonths));
        } else {
            $liabilityInstallment += $outstanding * 0.03;
        }
    }
    $existingDebtInstallment = max($debtBurden, $liabilityInstallment);

    $branchHouseholdProfile = credit_new_fetch_branch_household_profile((string)($row['branch_code'] ?? ''));
    $selectedLeiPayload = credit_new_extract_lei_payload($selectedLeiRow);
    $selectedLeiProfile = credit_new_lei_profile_from_payload($selectedLeiPayload);

    $branchHouseholdBaseline = credit_new_positive_number($branchHouseholdProfile['baseline_monthly'] ?? 0);
    $profileAssumedSize = credit_new_positive_number($branchHouseholdProfile['assumed_household_size'] ?? 0);
    $profilePerPerson = credit_new_positive_number($branchHouseholdProfile['household_per_person_monthly'] ?? 0);
    $profileUtility = credit_new_positive_number($branchHouseholdProfile['utility_per_person_monthly'] ?? 0);
    $profileNonUtility = credit_new_positive_number($branchHouseholdProfile['non_utility_per_person_monthly'] ?? 0);
    $profileIndex = credit_new_positive_number($branchHouseholdProfile['index_value'] ?? 0);
    $profileElecRate = credit_new_positive_number($branchHouseholdProfile['electricity_unit_rate'] ?? 0);
    $profileWaterRate = credit_new_positive_number($branchHouseholdProfile['water_unit_rate'] ?? 0);
    $profileInternetFee = credit_new_positive_number($branchHouseholdProfile['internet_mobile_monthly_fee'] ?? 0);

    if ($selectedLeiPayload !== []) {
        if ($profileIndex <= 0) {
            $profileIndex = credit_new_positive_number($selectedLeiProfile['household_index'] ?? 0);
        }
        if ($branchHouseholdBaseline <= 0) {
            $branchHouseholdBaseline = credit_new_positive_number($selectedLeiProfile['household_baseline_monthly'] ?? 0);
        }
        if ($profileAssumedSize <= 0) {
            $profileAssumedSize = credit_new_positive_number($selectedLeiProfile['assumed_household_size'] ?? 0);
        }
        if ($profilePerPerson <= 0) {
            $profilePerPerson = credit_new_positive_number($selectedLeiProfile['household_per_person_monthly'] ?? 0);
        }
        if ($profileUtility <= 0) {
            $profileUtility = credit_new_positive_number($selectedLeiProfile['utility_per_person_monthly'] ?? 0);
        }
        if ($profileNonUtility <= 0) {
            $profileNonUtility = credit_new_positive_number($selectedLeiProfile['non_utility_per_person_monthly'] ?? 0);
        }
        if ($profileElecRate <= 0) {
            $profileElecRate = credit_new_positive_number($selectedLeiProfile['electricity_unit_rate'] ?? 0);
        }
        if ($profileWaterRate <= 0) {
            $profileWaterRate = credit_new_positive_number($selectedLeiProfile['water_unit_rate'] ?? 0);
        }
        if ($profileInternetFee <= 0) {
            $profileInternetFee = credit_new_positive_number($selectedLeiProfile['internet_mobile_monthly_fee'] ?? 0);
        }
    }

    $effectiveHouseholdProfile = [
        'baseline_monthly' => $branchHouseholdBaseline,
        'assumed_household_size' => $profileAssumedSize,
        'household_per_person_monthly' => $profilePerPerson,
        'utility_per_person_monthly' => $profileUtility,
        'non_utility_per_person_monthly' => $profileNonUtility,
        'index_value' => $profileIndex,
        'electricity_unit_rate' => $profileElecRate,
        'water_unit_rate' => $profileWaterRate,
        'internet_mobile_monthly_fee' => $profileInternetFee,
    ];
    $leiPerPersonProfile = credit_new_lei_household_cost_per_person($effectiveHouseholdProfile);
    $familyMemberCount = $spouseCount + $childrenCount + $dependents;
    $householdMemberCount = 1 + $familyMemberCount;
    $familyFactor = 1.0 + min(0.45, max(0.0, $familyMemberCount * 0.08));

    $householdExpense = credit_new_positive_number($payload['household_expense'] ?? 0);
    $householdExpenseSource = 'manual';
    if ($householdExpense <= 0) {
        $householdByPerson = $leiPerPersonProfile['per_person_total'] * max(1, $householdMemberCount);
        $householdByIndex = $branchHouseholdBaseline > 0 ? ($branchHouseholdBaseline * $familyFactor) : 0.0;
        $householdExpense = max($householdByPerson, $householdByIndex);
        if ($householdExpense > 0) {
            $householdExpenseSource = 'branch_lei_per_person';
        }
    }
    if ($householdExpense <= 0) {
        $householdExpense = max(3500.0, $incomeTotal * 0.18);
        $householdExpenseSource = 'income_ratio_fallback';
    }
    $householdExpense = $householdExpense * $scenarioCostMultiplier;

    $houseValue = credit_new_sum_appraisal(credit_new_json_list($payload, 'houses'));
    $landValue = credit_new_sum_appraisal(credit_new_json_list($payload, 'lands'));
    $carValue = credit_new_estimate_car_value(credit_new_json_list($payload, 'cars'));
    $collateralValue = credit_new_sum_appraisal(credit_new_json_list($payload, 'collateral_assets'));
    $assetTotal = $houseValue + $landValue + $carValue + $collateralValue;
    $assetSupportMonthly = min($incomeTotalScenario * 0.25, $assetTotal * 0.0025);

    $attitudeIndex = credit_new_positive_number($row['attitude_index'] ?? 0);
    $attitudeClass = trim((string)($row['attitude_class'] ?? ''));
    $attitudeFactor = credit_new_attitude_factor($attitudeIndex, $attitudeClass);

    $netDisposable = max(0.0, $incomeTotalScenario - $occupationExpense - $familyExpense - $householdExpense - $existingDebtInstallment);
    $rawCapacity = max(0.0, ($netDisposable * $attitudeFactor) + $assetSupportMonthly);
    $monthlyCapacity = floor($rawCapacity * 0.85);

    $debtRatio = $incomeTotalScenario > 0 ? ($existingDebtInstallment / $incomeTotalScenario) : 1.0;
    $projectedDsr = $incomeTotalScenario > 0 ? (($existingDebtInstallment + $monthlyCapacity) / $incomeTotalScenario) * 100.0 : 100.0;
    $projectedDsr = min(999.0, max(0.0, $projectedDsr));

    $recommendedRate = 12.0
        + max(0.0, (1.0 - ($attitudeIndex / 100.0)) * 5.5)
        + max(0.0, $debtRatio - 0.20) * 8.0;
    $recommendedRate = min(18.0, max(8.0, $recommendedRate));

    $tenorMonths = 36;
    $principalByCapacity = credit_new_principal_from_payment($monthlyCapacity, $recommendedRate, $tenorMonths);
    $assetCap = $assetTotal > 0 ? ($assetTotal * 0.70) : 250000.0;
    $incomeCap = $incomeTotalScenario * 18.0;
    $loanLimitRaw = min(500000.0, $principalByCapacity, $assetCap, $incomeCap);
    $loanLimit = $loanLimitRaw >= 20000.0 ? floor($loanLimitRaw / 1000.0) * 1000.0 : 0.0;

    $estimatedPd = 2.5
        + max(0.0, (1.0 - ($attitudeIndex / 100.0)) * 9.0)
        + max(0.0, $debtRatio - 0.25) * 15.0
        + $scenarioPdShiftPct;
    $estimatedPd = min(35.0, max(1.0, $estimatedPd));

    $leiIndexPack = credit_new_build_lei_indices(
        $selectedLeiPayload,
        [
            'income_total_scenario' => $incomeTotalScenario,
            'family_expense' => $familyExpense,
        ]
    );

    $assetRows = credit_new_collect_asset_rows($payload);
    $collateralTypes = credit_new_collect_collateral_type_codes($payload);

    return [
        'payload' => $payload,
        'borrower_income' => $borrowerIncome,
        'spouse_income' => $spouseIncome,
        'income_total' => $incomeTotal,
        'income_total_scenario' => $incomeTotalScenario,
        'income_band' => credit_new_income_band($incomeTotal),
        'occupation_name' => $occupationName,
        'occupation_type' => $employmentType !== '' ? $employmentType : 'OTHER',
        'occupation_expense_ratio' => $borrowerExpenseRatio,
        'occupation_expense_base' => $occupationExpenseBase,
        'occupation_expense' => $occupationExpense,
        'family_responsibility_count' => $spouseCount + $childrenCount + $dependents,
        'household_member_count' => $householdMemberCount,
        'children_count' => $childrenCount,
        'child_support_expense' => $childSupportExpense,
        'family_expense_base' => $familyExpenseBase,
        'family_expense' => $familyExpense,
        'household_expense' => $householdExpense,
        'household_expense_source' => $householdExpenseSource,
        'lei_scenario_code' => (string)($leiScenario['code'] ?? 'BASE'),
        'lei_scenario_label' => (string)($leiScenario['label'] ?? 'Base Case'),
        'lei_scenario_cost_multiplier' => $scenarioCostMultiplier,
        'lei_scenario_income_multiplier' => $scenarioIncomeMultiplier,
        'lei_scenario_pd_shift_pct' => $scenarioPdShiftPct,
        'branch_household_index' => $profileIndex,
        'branch_household_baseline' => $branchHouseholdBaseline,
        'branch_household_assumed_size' => $profileAssumedSize,
        'lei_household_per_person' => $leiPerPersonProfile['per_person_total'],
        'lei_utility_per_person' => $leiPerPersonProfile['per_person_utility'],
        'lei_non_utility_per_person' => $leiPerPersonProfile['per_person_non_utility'],
        'lei_electricity_unit_rate' => $leiPerPersonProfile['electricity_unit_rate'],
        'lei_water_unit_rate' => $leiPerPersonProfile['water_unit_rate'],
        'lei_internet_mobile_monthly_fee' => $leiPerPersonProfile['internet_mobile_monthly_fee'],
        'lei_record_id' => (int)($selectedLeiRow['id'] ?? 0),
        'lei_record_no' => (string)($selectedLeiRow['primary_ref'] ?? ''),
        'lei_record_date' => (string)($selectedLeiRow['event_date'] ?? ''),
        'lei_index_pack' => $leiIndexPack,
        'lei_customer_expense_index' => (float)($leiIndexPack['customer_expense_index'] ?? 0),
        'lei_family_support_index' => (float)($leiIndexPack['family_support_index'] ?? 0),
        'lei_unemployment_index' => (float)($leiIndexPack['unemployment_index'] ?? 0),
        'lei_new_job_index' => (float)($leiIndexPack['new_job_index'] ?? 0),
        'existing_debt_installment' => $existingDebtInstallment,
        'asset_total' => $assetTotal,
        'house_value' => $houseValue,
        'land_value' => $landValue,
        'car_value' => $carValue,
        'collateral_value' => $collateralValue,
        'asset_support_monthly' => $assetSupportMonthly,
        'attitude_index' => $attitudeIndex,
        'attitude_class' => $attitudeClass,
        'attitude_factor' => $attitudeFactor,
        'net_disposable' => $netDisposable,
        'raw_capacity' => $rawCapacity,
        'monthly_capacity' => $monthlyCapacity,
        'recommended_rate' => $recommendedRate,
        'tenor_months' => $tenorMonths,
        'principal_by_capacity' => $principalByCapacity,
        'loan_limit' => $loanLimit,
        'projected_dsr_pct' => $projectedDsr,
        'estimated_pd_pct' => $estimatedPd,
        'asset_rows' => $assetRows,
        'collateral_types' => $collateralTypes,
        'collateral_type_text' => $collateralTypes === [] ? 'NONE' : implode(', ', $collateralTypes),
    ];
}

/**
 * @param array<string, mixed> $assessment
 * @param array<string, mixed> $policyRow
 * @return array<string, mixed>
 */
function credit_new_evaluate_policy(array $assessment, array $policyRow): array
{
    $policyPayload = json_decode((string)($policyRow['data_json'] ?? ''), true);
    if (!is_array($policyPayload)) {
        $policyPayload = [];
    }

    $customerPayload = $assessment['payload'];
    if (!is_array($customerPayload)) {
        $customerPayload = [];
    }
    $borrowerOccupations = credit_new_json_list($customerPayload, 'borrower_occupations');
    $occupationNames = array_values(array_filter(array_map(
        static fn(array $row): string => trim((string)($row['occupation_name'] ?? '')),
        $borrowerOccupations
    ), static fn(string $name): bool => $name !== ''));

    $policyJob = trim((string)($policyPayload['customer_job_ref'] ?? ''));
    $isAllOccupationPolicy = credit_new_is_all_occupation_policy($policyJob);
    $jobPass = $policyJob === '' || $isAllOccupationPolicy || in_array($policyJob, $occupationNames, true);
    $jobDetail = ($policyJob === '' || $isAllOccupationPolicy)
        ? 'No mandatory occupation policy configured'
        : ('Policy occupation: ' . $policyJob . ' | Customer: ' . ($occupationNames === [] ? '-' : implode(', ', $occupationNames)));

    $policyIncomeBand = trim((string)($policyPayload['income_band_ref'] ?? ''));
    $customerIncomeBand = (string)($assessment['income_band'] ?? '');
    $customerIncomeTotal = credit_new_positive_number($assessment['income_total'] ?? 0);
    $policyIncomeThreshold = credit_new_income_threshold($policyIncomeBand);
    $incomePass = $policyIncomeBand === '' || $policyIncomeBand === $customerIncomeBand;
    $incomeDetail = $policyIncomeBand === ''
        ? 'No mandatory income band configured'
        : ('Policy: ' . $policyIncomeBand . ' | Customer: ' . $customerIncomeBand);

    if ($policyIncomeBand !== '' && $policyIncomeThreshold !== null) {
        $incomePass = $customerIncomeTotal > $policyIncomeThreshold;
        $incomeDetail = 'Policy: ' . thai_option_label($policyIncomeBand)
            . ' (must be greater than ' . number_format($policyIncomeThreshold, 2) . ' THB/month)'
            . ' | Customer: ' . number_format($customerIncomeTotal, 2) . ' THB/month';
    }

    $policyCollateral = strtoupper(trim((string)($policyPayload['collateral_type_ref'] ?? '')));
    $customerCollateralTypes = $assessment['collateral_types'];
    if (!is_array($customerCollateralTypes)) {
        $customerCollateralTypes = [];
    }
    $collateralPass = credit_new_collateral_type_match($policyCollateral, $customerCollateralTypes);
    $collateralDetail = ($policyCollateral === '' ? 'No collateral type requirement configured' : ('Policy: ' . $policyCollateral))
        . ' | Customer: ' . ($customerCollateralTypes === [] ? 'NONE' : implode(', ', $customerCollateralTypes));

    $maxDsrPct = credit_new_positive_number($policyPayload['max_dsr_pct'] ?? 0);
    $projectedDsrPct = credit_new_positive_number($assessment['projected_dsr_pct'] ?? 0);
    $dsrPass = $maxDsrPct <= 0 ? true : ($projectedDsrPct <= $maxDsrPct);
    $dsrDetail = $maxDsrPct <= 0
        ? 'No maximum DSR configured'
        : ('Customer DSR ' . number_format($projectedDsrPct, 2) . '% / limit <= ' . number_format($maxDsrPct, 2) . '%');

    $pdTargetPct = credit_new_positive_number($policyPayload['pd_target_pct'] ?? 0);
    $estimatedPd = credit_new_positive_number($assessment['estimated_pd_pct'] ?? 0);
    $pdPass = $pdTargetPct <= 0 ? true : ($estimatedPd <= $pdTargetPct);
    $pdDetail = $pdTargetPct <= 0
        ? 'No PD target configured'
        : ('Estimated PD ' . number_format($estimatedPd, 2) . '% / target <= ' . number_format($pdTargetPct, 2) . '%');

    $policyLoan = credit_new_positive_number($policyPayload['recommended_loan_amount'] ?? 0);
    $capacityLoan = credit_new_positive_number($assessment['loan_limit'] ?? 0);
    $recommendedLoan = $policyLoan > 0 ? min($policyLoan, $capacityLoan) : $capacityLoan;
    $loanPass = $recommendedLoan >= 20000.0;
    $loanDetail = 'Capacity-based ' . number_format($capacityLoan, 2) . ' THB';
    if ($policyLoan > 0) {
        $loanDetail .= ' | Policy cap ' . number_format($policyLoan, 2) . ' THB';
    }

    $reasons = [
        ['label' => 'Occupation Policy', 'pass' => $jobPass, 'detail' => $jobDetail],
        ['label' => 'Income Band', 'pass' => $incomePass, 'detail' => $incomeDetail],
        ['label' => 'Collateral Asset', 'pass' => $collateralPass, 'detail' => $collateralDetail],
        ['label' => 'DSR Threshold', 'pass' => $dsrPass, 'detail' => $dsrDetail],
        ['label' => 'PD Threshold', 'pass' => $pdPass, 'detail' => $pdDetail],
        ['label' => 'Recommended Loan Amount', 'pass' => $loanPass, 'detail' => $loanDetail],
    ];

    $pass = $jobPass && $incomePass && $collateralPass && $dsrPass && $pdPass && $loanPass;

    return [
        'pass' => $pass,
        'decision_code' => $pass ? 'PASS' : 'FAIL',
        'decision_th' => $pass ? 'Policy Criteria Passed' : 'Policy Criteria Failed',
        'policy_payload' => $policyPayload,
        'recommended_loan_amount' => $recommendedLoan,
        'recommended_installment' => credit_new_positive_number($assessment['monthly_capacity'] ?? 0),
        'estimated_pd_pct' => $estimatedPd,
        'reasons' => $reasons,
    ];
}

function credit_new_pd_band(float $pdPct): string
{
    if ($pdPct <= 3.0) {
        return 'LOW';
    }
    if ($pdPct <= 7.0) {
        return 'MEDIUM';
    }
    if ($pdPct <= 12.0) {
        return 'HIGH';
    }

    return 'VERY_HIGH';
}

/**
 * @param array<string, mixed> $assessment
 * @param array<string, mixed> $policyEvaluation
 */
function credit_new_score_total(array $assessment, array $policyEvaluation): float
{
    $attitude = credit_new_positive_number($assessment['attitude_index'] ?? 0);
    $dsr = credit_new_positive_number($assessment['projected_dsr_pct'] ?? 100);
    $pd = credit_new_positive_number($policyEvaluation['estimated_pd_pct'] ?? 20);
    $assetTotal = credit_new_positive_number($assessment['asset_total'] ?? 0);
    $incomeTotal = credit_new_positive_number($assessment['income_total'] ?? 1);
    $assetCoverage = $incomeTotal > 0 ? min(6.0, $assetTotal / ($incomeTotal * 6.0)) : 0.0;

    $score = 55.0
        + ($attitude * 0.25)
        - ($dsr * 0.35)
        - ($pd * 1.15)
        + ($assetCoverage * 8.0);

    if (($policyEvaluation['pass'] ?? false) === true) {
        $score += 8.0;
    }

    $score = max(0.0, min(100.0, $score));

    return round($score, 2);
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @return array<int, array<string, mixed>>
 */
function credit_new_fetch_recent_assessments(array $scope, string $branchCode): array
{
    $scopeClause = access_scope_sql_clause('s.branch_code', 'scope_credit_scoring_list', $scope);
    $sql = '
        SELECT
            s.id,
            s.record_uid,
            s.primary_ref,
            s.customer_ref,
            s.branch_code,
            s.record_status,
            s.updated_at,
            s.updated_by,
            s.data_json
        FROM workflow_records s
        WHERE s.module_key = "credit_scoring"
          AND s.is_latest = 1
          AND s.is_deleted = 0
          ' . $scopeClause['sql'] . '
    ';
    $params = $scopeClause['params'];

    if ($branchCode !== '') {
        $sql .= ' AND s.branch_code = :branch_code';
        $params[':branch_code'] = $branchCode;
    }

    $sql .= ' ORDER BY s.id DESC LIMIT 300';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * @param array<string, mixed> $assessmentRow
 */
function credit_new_extract_policy_id_from_assessment_row(array $assessmentRow): int
{
    $payload = json_decode((string)($assessmentRow['data_json'] ?? ''), true);
    if (!is_array($payload)) {
        return 0;
    }

    $components = $payload['score_components'] ?? null;
    if (is_string($components) && trim($components) !== '') {
        $decoded = json_decode($components, true);
        if (is_array($decoded)) {
            return (int)($decoded['policy_id'] ?? 0);
        }
    }

    if (is_array($components)) {
        return (int)($components['policy_id'] ?? 0);
    }

    return (int)($payload['policy_id'] ?? 0);
}
$moduleKey = 'credit_scoring';
$module = module_by_key($moduleKey);
if ($module === null) {
    throw new RuntimeException('credit_scoring module not found');
}

$module['title'] = 'New Customer Credit Assessment';
$module['description'] = 'Select pending customers and assess using income, expense burden, collateral, attitude, and loan policy.';

$scope = current_access_scope();
$branchOptions = [];
$allowedCodes = accessible_branch_codes($scope);
$allowedLookup = array_fill_keys(array_map(static fn(string $code): string => strtoupper(trim($code)), $allowedCodes), true);

foreach (active_branch_rows() as $branchRow) {
    $code = strtoupper(trim((string)($branchRow['branch_code'] ?? '')));
    if ($code === '') {
        continue;
    }
    if ($scope['scope'] !== 'all' && !isset($allowedLookup[$code])) {
        continue;
    }
    $branchOptions[] = [
        'branch_code' => $code,
        'branch_name' => trim((string)($branchRow['branch_name'] ?? '')),
    ];
}

$selfPath = basename((string)($_SERVER['PHP_SELF'] ?? '02_credit_scoring.php'));
$selectedBranch = strtoupper(trim((string)($_GET['branch_code'] ?? '')));
if ($selectedBranch !== '' && !isset($allowedLookup[$selectedBranch]) && $scope['scope'] !== 'all') {
    $selectedBranch = '';
}
$mustChooseBranch = $scope['scope'] === 'all';
$assessmentStateOptions = [
    'pending' => 'Not Assessed',
    'assessed' => 'Assessed',
];
$selectedAssessmentState = strtolower(trim((string)($_GET['assessment_state'] ?? 'pending')));
if (!isset($assessmentStateOptions[$selectedAssessmentState])) {
    $selectedAssessmentState = 'pending';
}

$selectedLeiScenario = lei_normalize_scenario_code((string)($_GET['lei_scenario'] ?? 'BASE'));
$branchProfileForScenario = $selectedBranch !== '' ? lei_fetch_branch_household_profile($selectedBranch, $scope) : null;
$leiScenarioMap = lei_branch_scenarios($branchProfileForScenario);
if (!isset($leiScenarioMap[$selectedLeiScenario])) {
    $selectedLeiScenario = 'BASE';
}
$selectedLeiScenarioAssumption = lei_scenario_assumption($selectedLeiScenario, $branchProfileForScenario);
$leiScenarioOptions = lei_scenario_options_for_select($leiScenarioMap);

$leiRows = credit_new_fetch_lei_rows($scope, $selectedBranch);
$selectedLeiRecordId = (int)($_GET['lei_record_id'] ?? 0);
if ($selectedLeiRecordId <= 0 && $leiRows !== []) {
    $selectedLeiRecordId = (int)($leiRows[0]['id'] ?? 0);
}
$selectedLeiRow = credit_new_find_lei_row_by_id($scope, $selectedLeiRecordId, $selectedBranch);
if ($selectedLeiRow === null && $leiRows !== []) {
    $selectedLeiRecordId = (int)($leiRows[0]['id'] ?? 0);
    $selectedLeiRow = credit_new_find_lei_row_by_id($scope, $selectedLeiRecordId, $selectedBranch);
}
if ($selectedLeiRow !== null) {
    $selectedLeiRecordId = (int)($selectedLeiRow['id'] ?? 0);
}

$policyRows = credit_new_fetch_policy_rows();
$selectedPolicyId = (int)($_GET['policy_id'] ?? 0);

$selectionWarning = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['credit_new_action'] ?? '') === 'save_assessment') {
    try {
        verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));

        $postBranch = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
        if ($postBranch !== '' && $selectedBranch === '') {
            $selectedBranch = $postBranch;
        }
        $postAssessmentState = strtolower(trim((string)($_POST['assessment_state'] ?? '')));
        if (isset($assessmentStateOptions[$postAssessmentState])) {
            $selectedAssessmentState = $postAssessmentState;
        }
        $postLeiScenario = lei_normalize_scenario_code((string)($_POST['lei_scenario'] ?? $selectedLeiScenario));
        if (isset($leiScenarioMap[$postLeiScenario])) {
            $selectedLeiScenario = $postLeiScenario;
        }
        $postLeiRecordId = (int)($_POST['lei_record_id'] ?? $selectedLeiRecordId);
        if ($postLeiRecordId > 0) {
            $selectedLeiRecordId = $postLeiRecordId;
        }

        $customerCodeForSave = strtoupper(trim((string)($_POST['customer_code'] ?? '')));
        $policyIdForSave = (int)($_POST['policy_id'] ?? 0);
        if ($customerCodeForSave === '') {
            throw new RuntimeException('Customer code for assessment save not found');
        }
        if ($policyIdForSave <= 0) {
            throw new RuntimeException('Please select a loan policy before saving');
        }

        $saveCustomer = $selectedAssessmentState === 'assessed'
            ? credit_new_find_customer_by_code_any_status($scope, $customerCodeForSave, $selectedBranch)
            : credit_new_find_pending_customer_by_code($scope, $customerCodeForSave, $selectedBranch);
        if ($saveCustomer === null) {
            throw new RuntimeException('Customer not found in pending group, or already approved');
        }

        $savePolicy = credit_new_find_policy_by_id($policyRows, $policyIdForSave);
        if ($savePolicy === null) {
            throw new RuntimeException('Selected loan policy not found');
        }

        $saveBranchProfile = lei_fetch_branch_household_profile((string)($saveCustomer['branch_code'] ?? ''), $scope);
        $saveLeiScenario = lei_scenario_assumption($selectedLeiScenario, $saveBranchProfile);
        $saveLeiRow = credit_new_find_lei_row_by_id(
            $scope,
            $selectedLeiRecordId,
            (string)($saveCustomer['branch_code'] ?? '')
        );
        $saveAssessment = credit_new_calculate_capacity($saveCustomer, $saveLeiScenario, $saveLeiRow);
        $saveEvaluation = credit_new_evaluate_policy($saveAssessment, $savePolicy);

        $applicationNo = 'APP' . date('ymdHis') . random_int(100, 999);
        $scoreTotal = credit_new_score_total($saveAssessment, $saveEvaluation);
        $pdBand = credit_new_pd_band((float)$saveEvaluation['estimated_pd_pct']);
        $decision = ($saveEvaluation['pass'] ?? false) ? 'APPROVE' : 'REJECT';
        $policyPayload = is_array($saveEvaluation['policy_payload'] ?? null) ? $saveEvaluation['policy_payload'] : [];

        $policyAnnualRatePct = credit_new_positive_number($policyPayload['policy_interest_rate_pct'] ?? 0);
        $policyTermMonths = (int)floor(credit_new_positive_number($policyPayload['max_tenor_month'] ?? 0));
        $assessmentAnnualRatePct = credit_new_positive_number($saveAssessment['recommended_rate'] ?? 0);
        $assessmentTermMonths = (int)floor(credit_new_positive_number($saveAssessment['tenor_months'] ?? 0));

        $annualRatePctForContract = $policyAnnualRatePct > 0
            ? round($policyAnnualRatePct, 4)
            : round(($assessmentAnnualRatePct > 0 ? $assessmentAnnualRatePct : 12.0), 4);
        if ($annualRatePctForContract <= 0) {
            $annualRatePctForContract = 12.0;
        }

        $termMonthsForContract = $policyTermMonths > 0
            ? $policyTermMonths
            : ($assessmentTermMonths > 0 ? $assessmentTermMonths : 24);
        if ($termMonthsForContract <= 0) {
            $termMonthsForContract = 24;
        }

        $recommendedLoanForContract = credit_new_positive_number(
            $saveEvaluation['recommended_loan_amount'] ?? ($saveAssessment['loan_limit'] ?? 0)
        );
        $recommendedInstallmentForContract = credit_new_positive_number(
            $saveEvaluation['recommended_installment'] ?? ($saveAssessment['monthly_capacity'] ?? 0)
        );

        $policyReasons = is_array($saveEvaluation['reasons'] ?? null) ? $saveEvaluation['reasons'] : [];
        $failedLabels = array_values(array_filter(array_map(
            static fn(array $reason): string => (bool)($reason['pass'] ?? false) ? '' : (string)($reason['label'] ?? ''),
            $policyReasons
        ), static fn(string $label): bool => $label !== ''));

        $components = [
            'source_customer_code' => (string)($saveCustomer['primary_ref'] ?? ''),
            'source_customer_name' => (string)($saveCustomer['primary_name'] ?? ''),
            'income_total' => (float)$saveAssessment['income_total'],
            'existing_debt_installment' => (float)$saveAssessment['existing_debt_installment'],
            'household_expense' => (float)$saveAssessment['household_expense'],
            'household_expense_source' => (string)($saveAssessment['household_expense_source'] ?? ''),
            'branch_household_index' => (float)($saveAssessment['branch_household_index'] ?? 0),
            'branch_household_baseline' => (float)($saveAssessment['branch_household_baseline'] ?? 0),
            'household_member_count' => (int)($saveAssessment['household_member_count'] ?? 1),
            'lei_household_per_person' => (float)($saveAssessment['lei_household_per_person'] ?? 0),
            'lei_utility_per_person' => (float)($saveAssessment['lei_utility_per_person'] ?? 0),
            'lei_non_utility_per_person' => (float)($saveAssessment['lei_non_utility_per_person'] ?? 0),
            'income_total_scenario' => (float)($saveAssessment['income_total_scenario'] ?? 0),
            'occupation_expense_base' => (float)($saveAssessment['occupation_expense_base'] ?? 0),
            'family_expense_base' => (float)($saveAssessment['family_expense_base'] ?? 0),
            'lei_electricity_unit_rate' => (float)($saveAssessment['lei_electricity_unit_rate'] ?? 0),
            'lei_water_unit_rate' => (float)($saveAssessment['lei_water_unit_rate'] ?? 0),
            'lei_internet_mobile_monthly_fee' => (float)($saveAssessment['lei_internet_mobile_monthly_fee'] ?? 0),
            'lei_scenario_code' => (string)($saveAssessment['lei_scenario_code'] ?? 'BASE'),
            'lei_scenario_label' => (string)($saveAssessment['lei_scenario_label'] ?? ''),
            'lei_scenario_cost_multiplier' => (float)($saveAssessment['lei_scenario_cost_multiplier'] ?? 1),
            'lei_scenario_income_multiplier' => (float)($saveAssessment['lei_scenario_income_multiplier'] ?? 1),
            'lei_scenario_pd_shift_pct' => (float)($saveAssessment['lei_scenario_pd_shift_pct'] ?? 0),
            'lei_record_id' => (int)($saveAssessment['lei_record_id'] ?? 0),
            'lei_record_no' => (string)($saveAssessment['lei_record_no'] ?? ''),
            'lei_record_date' => (string)($saveAssessment['lei_record_date'] ?? ''),
            'lei_indices' => is_array($saveAssessment['lei_index_pack'] ?? null) ? $saveAssessment['lei_index_pack'] : [],
            'lei_customer_expense_index' => (float)($saveAssessment['lei_customer_expense_index'] ?? 0),
            'lei_family_support_index' => (float)($saveAssessment['lei_family_support_index'] ?? 0),
            'lei_unemployment_index' => (float)($saveAssessment['lei_unemployment_index'] ?? 0),
            'lei_new_job_index' => (float)($saveAssessment['lei_new_job_index'] ?? 0),
            'family_expense' => (float)$saveAssessment['family_expense'],
            'monthly_capacity' => (float)$saveAssessment['monthly_capacity'],
            'loan_limit_by_capacity' => (float)$saveAssessment['loan_limit'],
            'policy_id' => (int)($savePolicy['id'] ?? 0),
            'policy_code' => (string)($savePolicy['primary_ref'] ?? ''),
            'policy_name' => (string)($savePolicy['primary_name'] ?? ''),
            'policy_pass' => (bool)($saveEvaluation['pass'] ?? false),
            'policy_reasons' => $policyReasons,
            'recommended_loan_amount' => round(max(0.0, $recommendedLoanForContract), 2),
            'recommended_installment' => round(max(0.0, $recommendedInstallmentForContract), 2),
            'annual_rate_pct' => round(max(0.0, $annualRatePctForContract), 4),
            'term_months' => max(1, $termMonthsForContract),
            'policy_interest_rate_pct' => round(max(0.0, $policyAnnualRatePct), 4),
            'policy_max_tenor_month' => max(0, $policyTermMonths),
            'decision' => $decision,
        ];

        $moduleInput = [
            'application_no' => $applicationNo,
            'customer_ref' => (string)($saveCustomer['primary_ref'] ?? ''),
            'score_total' => $scoreTotal,
            'score_components' => json_encode(
                $components,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ),
            'bureau_summary' => 'Internal data from Customer 360 + attitude questionnaire',
            'model_version' => 'CREDIT_NEW_POLICY_V2',
            'cutoff_value' => 60,
            'pd_band' => $pdBand,
            'decision' => $decision,
            'override_reason' => ($saveEvaluation['pass'] ?? false)
                ? 'Passed the selected loan policy criteria'
                : 'FailCriterionPolicy: ' . ($failedLabels === [] ? '-' : implode(', ', $failedLabels)),
            'decision_date' => date('Y-m-d'),
            'branch_code' => (string)($saveCustomer['branch_code'] ?? ''),
            'customer_ref_common' => (string)($saveCustomer['primary_ref'] ?? ''),
            'risk_level' => $pdBand,
            'amount' => $scoreTotal,
            'event_date' => date('Y-m-d'),
            'record_status' => 'PENDING_CHECKER',
            'primary_ref' => $applicationNo,
            'primary_name' => (string)($saveCustomer['primary_ref'] ?? ''),
        ];

        module_create_record($module, $moduleInput, 'New customer credit assessment with loan policy');
        add_flash('success', 'Credit assessment saved successfully');

        $qs = ['open' => '1', 'customer_code' => $customerCodeForSave, 'policy_id' => (string)$policyIdForSave];
        if ($selectedBranch !== '') {
            $qs['branch_code'] = $selectedBranch;
        }
        if (isset($assessmentStateOptions[$selectedAssessmentState])) {
            $qs['assessment_state'] = $selectedAssessmentState;
        }
        if ($selectedLeiScenario !== 'BASE') {
            $qs['lei_scenario'] = $selectedLeiScenario;
        }
        if ($selectedLeiRecordId > 0) {
            $qs['lei_record_id'] = (string)$selectedLeiRecordId;
        }
        redirect_to($selfPath . '?' . http_build_query($qs));
    } catch (Throwable $e) {
        add_flash('danger', 'Failed to save credit assessment: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['credit_new_action'] ?? '') === 'review_customer_request') {
    try {
        verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));

        $reviewCustomerCode = strtoupper(trim((string)($_POST['customer_code'] ?? '')));
        $reviewDecision = strtoupper(trim((string)($_POST['review_decision'] ?? '')));
        $reviewReason = trim((string)($_POST['review_reason'] ?? ''));
        $postBranch = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
        if ($postBranch !== '' && $selectedBranch === '') {
            $selectedBranch = $postBranch;
        }
        $postAssessmentState = strtolower(trim((string)($_POST['assessment_state'] ?? '')));
        if (isset($assessmentStateOptions[$postAssessmentState])) {
            $selectedAssessmentState = $postAssessmentState;
        }
        $postLeiScenario = lei_normalize_scenario_code((string)($_POST['lei_scenario'] ?? $selectedLeiScenario));
        if (isset($leiScenarioMap[$postLeiScenario])) {
            $selectedLeiScenario = $postLeiScenario;
        }
        $postLeiRecordId = (int)($_POST['lei_record_id'] ?? $selectedLeiRecordId);
        if ($postLeiRecordId > 0) {
            $selectedLeiRecordId = $postLeiRecordId;
        }

        if ($reviewCustomerCode === '') {
            throw new RuntimeException('Customer code for request review not found');
        }
        if (!in_array($reviewDecision, ['APPROVED', 'REJECTED'], true)) {
            throw new RuntimeException('Invalid review decision format');
        }

        $reviewSource = credit_new_find_customer_request_for_review($scope, $reviewCustomerCode, $selectedBranch);
        if ($reviewSource === null) {
            throw new RuntimeException('Pending request from Module 1 not found');
        }

        credit_new_set_customer_request_status($reviewSource, $reviewDecision, $reviewReason);
        add_flash('success', $reviewDecision === 'APPROVED' ? 'Module 1 request approved successfully' : 'Module 1 rejection saved successfully');

        $qs = [];
        if ($selectedBranch !== '') {
            $qs['branch_code'] = $selectedBranch;
        }
        if ($selectedPolicyId > 0) {
            $qs['policy_id'] = (string)$selectedPolicyId;
        }
        if (isset($assessmentStateOptions[$selectedAssessmentState])) {
            $qs['assessment_state'] = $selectedAssessmentState;
        }
        if ($selectedLeiScenario !== 'BASE') {
            $qs['lei_scenario'] = $selectedLeiScenario;
        }
        if ($selectedLeiRecordId > 0) {
            $qs['lei_record_id'] = (string)$selectedLeiRecordId;
        }
        redirect_to($selfPath . ($qs === [] ? '' : ('?' . http_build_query($qs))));
    } catch (Throwable $e) {
        add_flash('danger', 'Failed to update request: ' . $e->getMessage());
    }
}

$candidateRows = [];
if (!$mustChooseBranch || $selectedBranch !== '') {
    if ($selectedAssessmentState === 'assessed') {
        $candidateRows = credit_new_fetch_assessed_customers($scope, $selectedBranch);
    } else {
        $candidateRows = credit_new_fetch_pending_customers($scope, $selectedBranch);
    }
} else {
    $selectionWarning = 'Please select a branch first; only records from that branch will be shown.';
}
if ($selectedAssessmentState === 'assessed' && $candidateRows === [] && $selectionWarning === '') {
    $selectionWarning = 'No assessed customers found in selected branch/filter.';
}
$assessmentLookupByCustomer = [];
if ($candidateRows !== []) {
    foreach (credit_new_fetch_recent_assessments($scope, $selectedBranch) as $assessmentRow) {
        $assessmentPayload = json_decode((string)($assessmentRow['data_json'] ?? ''), true);
        if (!is_array($assessmentPayload)) {
            $assessmentPayload = [];
        }
        $scoreComponents = $assessmentPayload['score_components'] ?? null;
        if (is_string($scoreComponents)) {
            $decodedScoreComponents = json_decode($scoreComponents, true);
            $scoreComponents = is_array($decodedScoreComponents) ? $decodedScoreComponents : [];
        }
        if (!is_array($scoreComponents)) {
            $scoreComponents = [];
        }
        $customerRef = strtoupper(trim((string)($assessmentRow['customer_ref'] ?? '')));
        if ($customerRef === '') {
            $customerRef = strtoupper(trim((string)($assessmentPayload['customer_ref'] ?? '')));
        }
        if ($customerRef === '') {
            $customerRef = strtoupper(trim((string)($scoreComponents['source_customer_code'] ?? '')));
        }
        if ($customerRef === '') {
            $customerRef = strtoupper(trim((string)($assessmentRow['primary_name'] ?? '')));
        }
        if ($customerRef === '' || isset($assessmentLookupByCustomer[$customerRef])) {
            continue;
        }
        $assessmentLookupByCustomer[$customerRef] = [
            'record_uid' => (string)($assessmentRow['record_uid'] ?? ''),
            'policy_id' => credit_new_extract_policy_id_from_assessment_row($assessmentRow),
        ];
    }
}

foreach ($candidateRows as &$candidateRow) {
    $candidateCode = strtoupper(trim((string)($candidateRow['customer_code'] ?? $candidateRow['primary_ref'] ?? '')));
    $assessmentInfo = $assessmentLookupByCustomer[$candidateCode] ?? null;
    $candidateRow['has_assessment'] = $assessmentInfo !== null;
    $candidateRow['assessment_record_uid'] = (string)($assessmentInfo['record_uid'] ?? '');
    $candidateRow['assessment_policy_id'] = (int)($assessmentInfo['policy_id'] ?? 0);
}
unset($candidateRow);

if ($selectedAssessmentState === 'assessed') {
    $candidateRows = array_values(array_filter(
        $candidateRows,
        static fn(array $row): bool => (bool)($row['has_assessment'] ?? false)
    ));
} else {
    $candidateRows = array_values(array_filter(
        $candidateRows,
        static fn(array $row): bool => !(bool)($row['has_assessment'] ?? false)
    ));
}

$candidateLookup = [];
foreach ($candidateRows as $candidateRow) {
    $code = strtoupper(trim((string)($candidateRow['customer_code'] ?? $candidateRow['primary_ref'] ?? '')));
    if ($code !== '') {
        $candidateLookup[$code] = true;
    }
}
$customerSearchOptions = credit_new_candidate_search_options($candidateRows);

$selectedCustomerCode = strtoupper(trim((string)($_GET['customer_code'] ?? '')));
$customerLookupText = trim((string)($_GET['customer_lookup'] ?? ''));
if ($selectedCustomerCode === '' && $customerLookupText !== '') {
    $lookupCodes = credit_new_find_customer_codes_by_lookup($customerLookupText, $candidateRows);
    if (count($lookupCodes) === 1) {
        $selectedCustomerCode = strtoupper((string)$lookupCodes[0]);
    } elseif (count($lookupCodes) > 1) {
        $selectionWarning = 'Multiple customers matched your search. Please be more specific.';
    } else {
        $selectionWarning = 'No customer found. Try searching by code, first name, or last name.';
    }
}
if ($selectedCustomerCode !== '' && !isset($candidateLookup[$selectedCustomerCode])) {
    if ($selectionWarning === '') {
        $selectionWarning = 'This customer is outside the current filter (pending/assessed), but assessment is still available.';
    }
}
if ($customerLookupText === '' && $selectedCustomerCode !== '') {
    foreach ($candidateRows as $candidateRow) {
        $code = strtoupper(trim((string)($candidateRow['customer_code'] ?? '')));
        if ($code === $selectedCustomerCode) {
            $customerLookupText = trim((string)($candidateRow['customer_name'] ?? '')) . ' | ' . $code;
            break;
        }
    }
}

$selectedCustomer = null;
$assessment = null;
if ($selectedCustomerCode !== '') {
    foreach ($candidateRows as $candidateRow) {
        $candidateCode = strtoupper(trim((string)($candidateRow['customer_code'] ?? $candidateRow['primary_ref'] ?? '')));
        if ($candidateCode === $selectedCustomerCode) {
            $selectedCustomer = $candidateRow;
            break;
        }
    }
    if ($selectedCustomer === null) {
        $selectedCustomer = credit_new_fetch_customer_by_code($scope, $selectedCustomerCode);
    }
    if ($selectedCustomer !== null) {
        $selectedCustomerBranchProfile = lei_fetch_branch_household_profile((string)($selectedCustomer['branch_code'] ?? ''), $scope);
        $selectedScenarioAssumption = lei_scenario_assumption($selectedLeiScenario, $selectedCustomerBranchProfile);
        $selectedLeiForCustomer = credit_new_find_lei_row_by_id(
            $scope,
            $selectedLeiRecordId,
            (string)($selectedCustomer['branch_code'] ?? '')
        );
        $assessment = credit_new_calculate_capacity($selectedCustomer, $selectedScenarioAssumption, $selectedLeiForCustomer);
    } else {
        $selectionWarning = 'No customers available for assessment under current criteria.';
    }
}

$selectedPolicy = credit_new_find_policy_by_id($policyRows, $selectedPolicyId);
$policyEvaluation = null;
if ($selectedPolicy !== null && is_array($assessment)) {
    $policyEvaluation = credit_new_evaluate_policy($assessment, $selectedPolicy);
}

$selectedCustomerCodeForModal = '';
$selectedCustomerNameForModal = '';
if (is_array($selectedCustomer)) {
    $selectedCustomerCodeForModal = strtoupper(trim((string)($selectedCustomer['primary_ref'] ?? $selectedCustomer['customer_code'] ?? '')));
    $selectedCustomerNameForModal = trim((string)($selectedCustomer['primary_name'] ?? $selectedCustomer['customer_name'] ?? ''));
}
if ($selectedCustomerCodeForModal === '') {
    $selectedCustomerCodeForModal = strtoupper(trim($selectedCustomerCode));
}
if ($selectedCustomerNameForModal === '' && is_array($assessment)) {
    $selectedCustomerNameForModal = trim((string)($assessment['source_customer_name'] ?? ''));
}

$summaryTotal = count($candidateRows);
$summaryWithAttitude = count(array_filter(
    $candidateRows,
    static fn(array $row): bool => credit_new_positive_number($row['attitude_index'] ?? 0) > 0
));
$summaryNoAttitude = $summaryTotal - $summaryWithAttitude;

$pageTitle = (string)$module['title'];
$currentModule = $moduleKey;
$shouldOpenModal = (string)($_GET['open'] ?? '') === '1';

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/menu.php';
?><section class="mb-4">
    <div class="card shadow-sm border-0 module-hero">
        <div class="card-body">
            <h2 class="h5 mb-1"><?php echo h((string)$module['title']); ?></h2>
            <p class="text-muted mb-0">Load pending requests from Module 1 for review and approve/reject on this page.</p>
        </div>
    </div>
</section>

<section class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-lg-2 col-md-4">
                <label class="form-label">Branch</label>
                <select class="form-select" name="branch_code" <?php echo $mustChooseBranch ? 'required' : ''; ?>>
                    <option value=""><?php echo $mustChooseBranch ? '-- Please select a branch --' : 'All accessible branches'; ?></option>
                    <?php foreach ($branchOptions as $branchItem): ?>
                        <?php
                            $code = strtoupper(trim((string)($branchItem['branch_code'] ?? '')));
                            if ($code === '') {
                                continue;
                            }
                            $label = $code . ' - ' . trim((string)($branchItem['branch_name'] ?? ''));
                        ?>
                        <option value="<?php echo h($code); ?>" <?php echo $selectedBranch === $code ? 'selected' : ''; ?>>
                            <?php echo h($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3 col-md-4">
                <label class="form-label">LEI Report</label>
                <select class="form-select" name="lei_record_id">
                    <option value="">-- Select LEI --</option>
                    <?php foreach ($leiRows as $leiRow): ?>
                        <?php
                            $leiId = (int)($leiRow['id'] ?? 0);
                            if ($leiId <= 0) {
                                continue;
                            }
                            $leiNo = trim((string)($leiRow['primary_ref'] ?? 'LEI'));
                            $leiDate = trim((string)($leiRow['event_date'] ?? ''));
                            $leiBranch = trim((string)($leiRow['branch_code'] ?? ''));
                            $leiLabel = $leiNo . ($leiDate !== '' ? (' | ' . $leiDate) : '') . ($leiBranch !== '' ? (' | ' . $leiBranch) : '');
                        ?>
                        <option value="<?php echo $leiId; ?>" <?php echo $selectedLeiRecordId === $leiId ? 'selected' : ''; ?>>
                            <?php echo h($leiLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label">LEI Scenario</label>
                <select class="form-select" name="lei_scenario">
                    <?php foreach ($leiScenarioOptions as $scenarioOption): ?>
                        <?php
                            $scenarioCode = (string)($scenarioOption['code'] ?? 'BASE');
                            $scenarioLabel = (string)($scenarioOption['label'] ?? $scenarioCode);
                        ?>
                        <option value="<?php echo h($scenarioCode); ?>" <?php echo $selectedLeiScenario === $scenarioCode ? 'selected' : ''; ?>>
                            <?php echo h($scenarioLabel . ' | Expense x' . number_format((float)$scenarioOption['cost_multiplier'], 2) . ' | Income x' . number_format((float)$scenarioOption['income_multiplier'], 2)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label">Assessment Status</label>
                <select class="form-select" name="assessment_state">
                    <?php foreach ($assessmentStateOptions as $stateValue => $stateLabel): ?>
                        <option value="<?php echo h($stateValue); ?>" <?php echo $selectedAssessmentState === $stateValue ? 'selected' : ''; ?>>
                            <?php echo h($stateLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-4 col-md-8">
                <label class="form-label">Customer Search (Autocomplete)</label>
                <input
                    class="form-control"
                    type="text"
                    name="customer_lookup"
                    list="creditCandidateLookupList"
                    value="<?php echo h($customerLookupText); ?>"
                    placeholder="CUS... / first name / last name"
                >
            </div>
            <div class="col-lg-3 col-md-8 d-flex gap-2">
                <button type="submit" class="btn btn-brand">Search</button>
                <?php if ($selectedBranch !== '' || $selectedAssessmentState !== 'pending' || $selectedLeiScenario !== 'BASE' || $selectedLeiRecordId > 0): ?>
                    <a class="btn btn-outline-secondary" href="<?php echo h($selfPath); ?>">Reset Filters</a>
                <?php endif; ?>
            </div>
        </form>
        <datalist id="creditCandidateLookupList">
            <?php foreach ($customerSearchOptions as $lookupOption): ?>
                <option value="<?php echo h($lookupOption); ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <div class="small text-muted mt-2">
            Active Scenario: <strong><?php echo h((string)$selectedLeiScenarioAssumption['label']); ?></strong>
            | Expense x<?php echo number_format((float)$selectedLeiScenarioAssumption['cost_multiplier'], 2); ?>
            | Income x<?php echo number_format((float)$selectedLeiScenarioAssumption['income_multiplier'], 2); ?>
            | PD +<?php echo number_format((float)$selectedLeiScenarioAssumption['pd_shift_pct'], 2); ?>%
            <?php if ($selectedLeiRow !== null): ?>
                | LEI: <strong><?php echo h((string)($selectedLeiRow['primary_ref'] ?? '')); ?></strong>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($selectionWarning !== ''): ?>
    <section class="alert alert-warning mb-4"><?php echo h($selectionWarning); ?></section>
<?php endif; ?>

<section class="row g-3 mb-4">
    <div class="col-md-4"><div class="stat-card"><span>Pending Customers (Filtered)</span><strong><?php echo number_format($summaryTotal); ?></strong></div></div>
    <div class="col-md-4"><div class="stat-card"><span>With Attitude Score</span><strong><?php echo number_format($summaryWithAttitude); ?></strong></div></div>
    <div class="col-md-4"><div class="stat-card"><span>Without Attitude Questionnaire</span><strong><?php echo number_format($summaryNoAttitude); ?></strong></div></div>
</section>

<section class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white">
        <h3 class="h6 mb-0">Pending Customer List (click "Assess" to open popup)</h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 js-admin-datatable">
                <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Customer Code</th>
                    <th>Customer Name</th>
                    <th>Branch</th>
                    <th>Total Income/Month</th>
                    <th>Attitude Score</th>
                    <th>Customer Status</th>
                    <th>Assessment Status</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($candidateRows as $row): ?>
                    <?php
                        $rowCode = strtoupper(trim((string)($row['customer_code'] ?? '')));
                        $rowPayload = json_decode((string)($row['data_json'] ?? ''), true);
                        if (!is_array($rowPayload)) {
                            $rowPayload = [];
                        }
                        $incomePreview = credit_new_sum_monthly_income_from_occupations(credit_new_json_list($rowPayload, 'borrower_occupations'))
                            + credit_new_sum_monthly_income_from_occupations(credit_new_json_list($rowPayload, 'spouse_occupations'));
                        if ($incomePreview <= 0) {
                            $incomePreview = credit_new_positive_number($rowPayload['monthly_income'] ?? ($row['amount'] ?? 0));
                        }
                        $rowLinkParams = [
                            'open=1',
                            'customer_code=' . rawurlencode($rowCode),
                        ];
                        if ($selectedBranch !== '') {
                            $rowLinkParams[] = 'branch_code=' . rawurlencode($selectedBranch);
                        }
                        $rowLinkParams[] = 'assessment_state=' . rawurlencode($selectedAssessmentState);
                        if ($selectedLeiScenario !== 'BASE') {
                            $rowLinkParams[] = 'lei_scenario=' . rawurlencode($selectedLeiScenario);
                        }
                        if ($selectedLeiRecordId > 0) {
                            $rowLinkParams[] = 'lei_record_id=' . rawurlencode((string)$selectedLeiRecordId);
                        }
                        if ($selectedPolicyId > 0) {
                            $rowLinkParams[] = 'policy_id=' . rawurlencode((string)$selectedPolicyId);
                        }
                        $rowLink = $selfPath . '?' . implode('&', $rowLinkParams);
                        $hasAssessment = (bool)($row['has_assessment'] ?? false);
                        $editPolicyId = (int)($row['assessment_policy_id'] ?? 0);
                        $rowEditParams = [
                            'open=1',
                            'customer_code=' . rawurlencode($rowCode),
                        ];
                        if ($selectedBranch !== '') {
                            $rowEditParams[] = 'branch_code=' . rawurlencode($selectedBranch);
                        }
                        $rowEditParams[] = 'assessment_state=' . rawurlencode($selectedAssessmentState);
                        if ($selectedLeiScenario !== 'BASE') {
                            $rowEditParams[] = 'lei_scenario=' . rawurlencode($selectedLeiScenario);
                        }
                        if ($selectedLeiRecordId > 0) {
                            $rowEditParams[] = 'lei_record_id=' . rawurlencode((string)$selectedLeiRecordId);
                        }
                        if ($editPolicyId > 0) {
                            $rowEditParams[] = 'policy_id=' . rawurlencode((string)$editPolicyId);
                        } elseif ($selectedPolicyId > 0) {
                            $rowEditParams[] = 'policy_id=' . rawurlencode((string)$selectedPolicyId);
                        }
                        $rowEditLink = $selfPath . '?' . implode('&', $rowEditParams);
                    ?>
                    <tr>
                        <td><?php echo (int)$row['id']; ?></td>
                        <td><code><?php echo h($rowCode); ?></code></td>
                        <td><?php echo h((string)($row['customer_name'] ?? '')); ?></td>
                        <td><?php echo h((string)($row['branch_code'] ?? '')); ?></td>
                        <td><?php echo number_format((float)$incomePreview, 2); ?></td>
                        <td>
                            <?php $attIdx = credit_new_positive_number($row['attitude_index'] ?? 0); ?>
                            <?php if ($attIdx > 0): ?>
                                <?php echo number_format($attIdx, 2); ?> (<?php echo h(credit_new_attitude_label((string)($row['attitude_class'] ?? ''))); ?>)
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge text-bg-<?php echo h(badge_class_for_status((string)($row['customer_status'] ?? 'PENDING_CHECKER'))); ?>">
                                <?php echo h(thai_status_label((string)($row['customer_status'] ?? 'PENDING_CHECKER'))); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge text-bg-<?php echo $hasAssessment ? 'success' : 'secondary'; ?>">
                                <?php echo $hasAssessment ? 'Assessed' : 'Not Assessed'; ?>
                            </span>
                        </td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <?php if ($hasAssessment): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo h($rowEditLink); ?>">Edit</a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Edit</button>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?php echo h($rowLink); ?>">Assess</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div class="modal fade" id="creditNewAssessmentModal" tabindex="-1" aria-labelledby="creditNewAssessmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h3 class="h6 mb-0" id="creditNewAssessmentModalLabel">New Customer Credit Assessment</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if ($selectedCustomer !== null && is_array($assessment)): ?>
                    <form method="get" class="row g-3 align-items-end mb-3">
                        <input type="hidden" name="open" value="1">
                        <input type="hidden" name="customer_code" value="<?php echo h($selectedCustomerCodeForModal); ?>">
                        <input type="hidden" name="assessment_state" value="<?php echo h($selectedAssessmentState); ?>">
                        <input type="hidden" name="lei_scenario" value="<?php echo h($selectedLeiScenario); ?>">
                        <?php if ($selectedBranch !== ''): ?>
                            <input type="hidden" name="branch_code" value="<?php echo h($selectedBranch); ?>">
                        <?php endif; ?>
                        <div class="col-lg-5">
                            <label class="form-label">Loan Policy (from Module 8)</label>
                            <select class="form-select" name="policy_id" required>
                                <option value="">-- Select a policy before assessment --</option>
                                <?php foreach ($policyRows as $row): ?>
                                    <?php
                                        $pid = (int)($row['id'] ?? 0);
                                        $label = trim((string)($row['primary_ref'] ?? '')) . ' - ' . trim((string)($row['primary_name'] ?? ''));
                                    ?>
                                    <option value="<?php echo $pid; ?>" <?php echo $pid === $selectedPolicyId ? 'selected' : ''; ?>>
                                        <?php echo h($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label">LEI Report</label>
                            <select class="form-select" name="lei_record_id">
                                <option value="">-- Select LEI --</option>
                                <?php foreach ($leiRows as $leiRow): ?>
                                    <?php
                                        $leiId = (int)($leiRow['id'] ?? 0);
                                        if ($leiId <= 0) {
                                            continue;
                                        }
                                        $leiNo = trim((string)($leiRow['primary_ref'] ?? 'LEI'));
                                        $leiDate = trim((string)($leiRow['event_date'] ?? ''));
                                        $leiBranch = trim((string)($leiRow['branch_code'] ?? ''));
                                        $leiLabel = $leiNo . ($leiDate !== '' ? (' | ' . $leiDate) : '') . ($leiBranch !== '' ? (' | ' . $leiBranch) : '');
                                    ?>
                                    <option value="<?php echo $leiId; ?>" <?php echo $selectedLeiRecordId === $leiId ? 'selected' : ''; ?>>
                                        <?php echo h($leiLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-4 d-flex gap-2">
                            <button type="submit" class="btn btn-brand">Evaluate Pass/Fail</button>
                            <?php
                                $closeQs = [];
                                if ($selectedBranch !== '') {
                                    $closeQs['branch_code'] = $selectedBranch;
                                }
                                if (isset($assessmentStateOptions[$selectedAssessmentState])) {
                                    $closeQs['assessment_state'] = $selectedAssessmentState;
                                }
                                if ($selectedLeiScenario !== 'BASE') {
                                    $closeQs['lei_scenario'] = $selectedLeiScenario;
                                }
                                if ($selectedLeiRecordId > 0) {
                                    $closeQs['lei_record_id'] = (string)$selectedLeiRecordId;
                                }
                                $closeLink = $selfPath . ($closeQs === [] ? '' : ('?' . http_build_query($closeQs)));
                            ?>
                            <a href="<?php echo h($closeLink); ?>" class="btn btn-outline-secondary">Clear Selection</a>
                        </div>
                    </form>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4"><div class="stat-card"><span>Customer Code</span><strong><?php echo h($selectedCustomerCodeForModal); ?></strong></div></div>
                        <div class="col-md-4"><div class="stat-card"><span>Customer Name</span><strong><?php echo h($selectedCustomerNameForModal); ?></strong></div></div>
                        <div class="col-md-4"><div class="stat-card"><span>Branch</span><strong><?php echo h((string)($selectedCustomer['branch_code'] ?? '-')); ?></strong></div></div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-3"><div class="stat-card"><span>Borrower Income/Month</span><strong><?php echo number_format((float)$assessment['borrower_income'], 2); ?></strong></div></div>
                        <div class="col-md-3"><div class="stat-card"><span>Spouse Income/Month</span><strong><?php echo number_format((float)$assessment['spouse_income'], 2); ?></strong></div></div>
                        <div class="col-md-3"><div class="stat-card"><span>Total Income/Month</span><strong><?php echo number_format((float)$assessment['income_total'], 2); ?></strong></div></div>
                        <div class="col-md-3"><div class="stat-card"><span>Attitude Score</span><strong><?php echo number_format((float)$assessment['attitude_index'], 2); ?> (<?php echo h(credit_new_attitude_label((string)$assessment['attitude_class'])); ?>)</strong></div></div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4"><div class="stat-card"><span>LEI Scenario</span><strong><?php echo h((string)($assessment['lei_scenario_label'] ?? 'Base Case')); ?></strong></div></div>
                        <div class="col-md-4"><div class="stat-card"><span>Income After LEI Adjustment</span><strong><?php echo number_format((float)($assessment['income_total_scenario'] ?? 0), 2); ?></strong></div></div>
                        <div class="col-md-4"><div class="stat-card"><span>LEI Multipliers</span><strong>Expense x<?php echo number_format((float)($assessment['lei_scenario_cost_multiplier'] ?? 1), 2); ?> | Income x<?php echo number_format((float)($assessment['lei_scenario_income_multiplier'] ?? 1), 2); ?></strong></div></div>
                    </div>

                    <?php $leiIndexPack = is_array($assessment['lei_index_pack'] ?? null) ? $assessment['lei_index_pack'] : []; ?>
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white fw-semibold">LEI Indexes for Credit Assessment (% higher = better)</div>
                        <div class="card-body">
                            <div class="row g-3 mb-2">
                                <div class="col-md-3"><div class="stat-card"><span>Customer Expense Index</span><strong><?php echo number_format((float)($assessment['lei_customer_expense_index'] ?? 0), 2); ?>%</strong></div></div>
                                <div class="col-md-3"><div class="stat-card"><span>Family Support Index</span><strong><?php echo number_format((float)($assessment['lei_family_support_index'] ?? 0), 2); ?>%</strong></div></div>
                                <div class="col-md-3"><div class="stat-card"><span>Unemployment Risk Index</span><strong><?php echo number_format((float)($assessment['lei_unemployment_index'] ?? 0), 2); ?>%</strong></div></div>
                                <div class="col-md-3"><div class="stat-card"><span>New Job Opportunity Index</span><strong><?php echo number_format((float)($assessment['lei_new_job_index'] ?? 0), 2); ?>%</strong></div></div>
                            </div>
                            <div class="row g-3">
                                <div class="col-lg-3 col-md-4"><div class="stat-card"><span> BCI - Business Confidence Index </span><strong><?php echo number_format((float)($leiIndexPack['bci_score'] ?? 0), 2); ?>%</strong></div></div>
                                <div class="col-lg-3 col-md-4"><div class="stat-card"><span> MLI - Macro Leading Index</span><strong><?php echo number_format((float)($leiIndexPack['mli_score'] ?? 0), 2); ?>%</strong></div></div>
                                <div class="col-lg-3 col-md-4"><div class="stat-card"><span> NAWI - Non-Agricultural Wage Index</span><strong><?php echo number_format((float)($leiIndexPack['nawi_score'] ?? 0), 2); ?>%</strong></div></div>
                                <div class="col-lg-3 col-md-4"><div class="stat-card"><span> AWI - Agricultural Wage Index </span><strong><?php echo number_format((float)($leiIndexPack['awi_score'] ?? 0), 2); ?>%</strong></div></div>
                                <div class="col-lg-3 col-md-4"><div class="stat-card"><span> JOI - Job Openings Index </span><strong><?php echo number_format((float)($leiIndexPack['joi_score'] ?? 0), 2); ?>%</strong></div></div>
                                <div class="col-lg-3 col-md-4"><div class="stat-card"><span> REY - Real Estate Yield Index </span><strong><?php echo number_format((float)($leiIndexPack['rey_score'] ?? 0), 2); ?>%</strong></div></div>
                                <div class="col-lg-3 col-md-4"><div class="stat-card"><span> LPPI - Local Property Price Index </span><strong><?php echo number_format((float)($leiIndexPack['lppi_score'] ?? 0), 2); ?>%</strong></div></div>
                                <div class="col-lg-3 col-md-4"><div class="stat-card"><span> IVI - Investment Volume Index </span><strong><?php echo number_format((float)($leiIndexPack['ivi_score'] ?? 0), 2); ?>%</strong></div></div>
                            </div>
                            <small class="text-muted d-block mt-2">Higher percentage values are better; lower values indicate higher vulnerability in expenses and labor-market conditions.</small>
                        </div>
                    </div>
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white fw-semibold">Expenses and Liabilities Used in Assessment</div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-3"><div class="stat-card"><span>Existing Debt Installment/Month</span><strong><?php echo number_format((float)$assessment['existing_debt_installment'], 2); ?></strong></div></div>
                                <div class="col-md-3"><div class="stat-card"><span>Family Support Expense</span><strong><?php echo number_format((float)$assessment['family_expense'], 2); ?></strong></div></div>
                                <div class="col-md-3"><div class="stat-card"><span>Household Expense</span><strong><?php echo number_format((float)$assessment['household_expense'], 2); ?></strong></div></div>
                                <div class="col-md-3"><div class="stat-card"><span>Affordable Installment/Month</span><strong><?php echo number_format((float)$assessment['monthly_capacity'], 2); ?></strong></div></div>
                            </div>
                            <div class="row g-3 mt-1">
                                <div class="col-md-4"><div class="stat-card"><span>Household Members Used</span><strong><?php echo number_format((float)$assessment['household_member_count'], 0); ?> persons</strong></div></div>
                                <div class="col-md-4"><div class="stat-card"><span>LEI Cost of Living per Person (Medium Quality of Life)</span><strong><?php echo number_format((float)$assessment['lei_household_per_person'], 2); ?></strong></div></div>
                                <div class="col-md-4"><div class="stat-card"><span>LEI Utility Cost per Person (Water+Electricity+Phone/Internet)</span><strong><?php echo number_format((float)$assessment['lei_utility_per_person'], 2); ?></strong></div></div>
                            </div>
                            <div class="row g-3 mt-1">
                                <div class="col-md-4"><div class="stat-card"><span>Projected DSR After New Loan</span><strong><?php echo number_format((float)$assessment['projected_dsr_pct'], 2); ?>%</strong></div></div>
                                <div class="col-md-4"><div class="stat-card"><span>Capacity-Based Loan Limit</span><strong><?php echo number_format((float)$assessment['loan_limit'], 2); ?></strong></div></div>
                                <div class="col-md-4"><div class="stat-card"><span>Estimated PD</span><strong><?php echo number_format((float)$assessment['estimated_pd_pct'], 2); ?>%</strong></div></div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white fw-semibold">Attached Asset/Collateral Details</div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th>Type</th>
                                        <th>District</th>
                                        <th>Province</th>
                                        <th>Reference No.</th>
                                        <th>Appraisal Value</th>
                                        <th>Attachment</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php $assetRows = is_array($assessment['asset_rows']) ? $assessment['asset_rows'] : []; ?>
                                    <?php if ($assetRows === []): ?>
                                        <tr><td colspan="6" class="text-center text-muted">No attached asset data.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($assetRows as $asset): ?>
                                            <?php $attachment = trim((string)($asset['attachment_file'] ?? '')); ?>
                                            <tr>
                                                <td><?php echo h((string)($asset['type'] ?? '')); ?></td>
                                                <td><?php echo h((string)($asset['district'] ?? '')); ?></td>
                                                <td><?php echo h((string)($asset['province'] ?? '')); ?></td>
                                                <td><?php echo h((string)($asset['ref_no'] ?? '')); ?></td>
                                                <td><?php echo number_format((float)credit_new_positive_number($asset['appraisal'] ?? 0), 2); ?></td>
                                                <td>
                                                    <?php if ($attachment !== ''): ?>
                                                        <a href="<?php echo h($attachment); ?>" target="_blank" rel="noopener">Open File</a>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <?php if ($selectedPolicy !== null && is_array($policyEvaluation)): ?>
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div class="fw-semibold">
                                    Policy Evaluation:
                                    <?php echo h((string)($selectedPolicy['primary_ref'] ?? '')); ?>
                                    - <?php echo h((string)($selectedPolicy['primary_name'] ?? '')); ?>
                                </div>
                                <span class="badge text-bg-<?php echo ($policyEvaluation['pass'] ?? false) ? 'success' : 'danger'; ?>">
                                    <?php echo h((string)$policyEvaluation['decision_th']); ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4"><div class="stat-card"><span>Recommended Loan After Policy Match</span><strong><?php echo number_format((float)$policyEvaluation['recommended_loan_amount'], 2); ?></strong></div></div>
                                    <div class="col-md-4"><div class="stat-card"><span>Recommended Installment</span><strong><?php echo number_format((float)$policyEvaluation['recommended_installment'], 2); ?></strong></div></div>
                                    <div class="col-md-4"><div class="stat-card"><span>Estimated PD</span><strong><?php echo number_format((float)$policyEvaluation['estimated_pd_pct'], 2); ?>%</strong></div></div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead class="table-light">
                                        <tr>
                                            <th>Criterion</th>
                                            <th style="width: 140px;">Result</th>
                                            <th>Details</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach (($policyEvaluation['reasons'] ?? []) as $reason): ?>
                                            <tr>
                                                <td><?php echo h((string)($reason['label'] ?? '')); ?></td>
                                                <td>
                                                    <span class="badge text-bg-<?php echo (bool)($reason['pass'] ?? false) ? 'success' : 'danger'; ?>">
                                                        <?php echo (bool)($reason['pass'] ?? false) ? 'Pass' : 'Fail'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo h((string)($reason['detail'] ?? '')); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning mb-0">No loan policy available for assessment. Please create one in Module 8 first.</div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-info mb-0">Please select a pending customer from the table and open the popup to assess.</div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <?php if ($selectedPolicy !== null && is_array($policyEvaluation)): ?>
                    <?php $finalDecisionCode = (bool)($policyEvaluation['pass'] ?? false) ? 'APPROVE' : 'REJECT'; ?>
                    <div class="me-auto">
                        <small class="text-muted d-block">Policy Result (Recommendation)</small>
                        <span class="badge text-bg-<?php echo $finalDecisionCode === 'APPROVE' ? 'success' : 'danger'; ?> fs-6">
                            <?php echo h($finalDecisionCode); ?>
                        </span>
                    </div>
                <?php endif; ?>
                <?php if ($selectedCustomer !== null && $selectedPolicy !== null && is_array($policyEvaluation)): ?>
                    <form method="post" class="d-flex gap-2">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="credit_new_action" value="save_assessment">
                        <input type="hidden" name="customer_code" value="<?php echo h($selectedCustomerCodeForModal); ?>">
                        <input type="hidden" name="policy_id" value="<?php echo (int)$selectedPolicyId; ?>">
                        <input type="hidden" name="branch_code" value="<?php echo h($selectedBranch); ?>">
                        <input type="hidden" name="assessment_state" value="<?php echo h($selectedAssessmentState); ?>">
                        <input type="hidden" name="lei_scenario" value="<?php echo h($selectedLeiScenario); ?>">
                        <input type="hidden" name="lei_record_id" value="<?php echo (int)$selectedLeiRecordId; ?>">
                        <button type="submit" class="btn btn-outline-primary">Save Assessment (Module 2)</button>
                    </form>
                    <form method="post" class="d-flex gap-2">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="credit_new_action" value="review_customer_request">
                        <input type="hidden" name="review_decision" value="APPROVED">
                        <input type="hidden" name="review_reason" value="Approved request from Module 2">
                        <input type="hidden" name="customer_code" value="<?php echo h($selectedCustomerCodeForModal); ?>">
                        <input type="hidden" name="branch_code" value="<?php echo h($selectedBranch); ?>">
                        <input type="hidden" name="assessment_state" value="<?php echo h($selectedAssessmentState); ?>">
                        <input type="hidden" name="lei_scenario" value="<?php echo h($selectedLeiScenario); ?>">
                        <input type="hidden" name="lei_record_id" value="<?php echo (int)$selectedLeiRecordId; ?>">
                        <button type="submit" class="btn btn-success">Approve Request (Module 1)</button>
                    </form>
                    <form method="post" class="d-flex gap-2">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="credit_new_action" value="review_customer_request">
                        <input type="hidden" name="review_decision" value="REJECTED">
                        <input type="hidden" name="review_reason" value="Rejected request from Module 2">
                        <input type="hidden" name="customer_code" value="<?php echo h($selectedCustomerCodeForModal); ?>">
                        <input type="hidden" name="branch_code" value="<?php echo h($selectedBranch); ?>">
                        <input type="hidden" name="assessment_state" value="<?php echo h($selectedAssessmentState); ?>">
                        <input type="hidden" name="lei_scenario" value="<?php echo h($selectedLeiScenario); ?>">
                        <input type="hidden" name="lei_record_id" value="<?php echo (int)$selectedLeiRecordId; ?>">
                        <button type="submit" class="btn btn-outline-danger">Reject Request (Module 1)</button>
                    </form>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php if ($shouldOpenModal): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
    if (!window.bootstrap) {
        return;
    }
    var modalEl = document.getElementById('creditNewAssessmentModal');
    if (!modalEl) {
        return;
    }
    var modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
});
</script>
<?php endif; ?>
<?php
include __DIR__ . '/../partials/footer.php';



<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/**
 * @param mixed $value
 */
function lei_number_or_null($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric((string)$value)) {
        return null;
    }

    return (float)$value;
}

function lei_input_number(array $payload, string $key): string
{
    $value = lei_number_or_null($payload[$key] ?? null);
    return $value === null ? '' : number_format($value, 2, '.', '');
}

/**
 * @return array<string, mixed>
 */
function lei_decode_json_array(?string $json): array
{
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @return array{
 *   household_index:float,
 *   household_baseline_monthly:float,
 *   input_count:int,
 *   socio_factor:float,
 *   assumed_household_size:float,
 *   utility_per_person_monthly:float,
 *   non_utility_per_person_monthly:float,
 *   household_per_person_monthly:float,
 *   electricity_unit_rate:float,
 *   water_unit_rate:float,
 *   internet_mobile_monthly_fee:float,
 *   electricity_units_per_person:float,
 *   water_units_per_person:float
 * }
 */
function lei_compute_household_profile(array $payload): array
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
    $inputCount = 0;

    foreach ($referenceMap as $field => $config) {
        $value = lei_number_or_null($payload[$field] ?? null);
        if ($value === null || $value <= 0) {
            continue;
        }

        $base = (float)$config['base'];
        $weight = (float)$config['weight'];
        if ($base <= 0 || $weight <= 0) {
            continue;
        }

        $ratio = $value / $base;
        $ratio = max(0.40, min(3.00, $ratio));

        $weightedRatioSum += $ratio * $weight;
        $weightSum += $weight;
        $inputCount++;
    }

    $indexRatio = $weightSum > 0 ? ($weightedRatioSum / $weightSum) : 1.0;

    $population = max(0.0, lei_number_or_null($payload['local_population_count'] ?? null) ?? 0.0);
    $floatingPopulation = max(0.0, lei_number_or_null($payload['floating_population_count'] ?? null) ?? 0.0);
    $passThroughPopulation = max(0.0, lei_number_or_null($payload['pass_through_population_count'] ?? null) ?? 0.0);
    $jobOpenings = max(0.0, lei_number_or_null($payload['job_opening_count'] ?? null) ?? 0.0);
    $factoryWorkers = max(0.0, lei_number_or_null($payload['factory_worker_count'] ?? null) ?? 0.0);
    $retailWorkers = max(0.0, lei_number_or_null($payload['retail_worker_count'] ?? null) ?? 0.0);
    $hospitalWorkers = max(0.0, lei_number_or_null($payload['hospital_worker_count'] ?? null) ?? 0.0);
    $companyWorkers = max(0.0, lei_number_or_null($payload['company_worker_count'] ?? null) ?? 0.0);
    $partnershipWorkers = max(0.0, lei_number_or_null($payload['partnership_worker_count'] ?? null) ?? 0.0);
    $governmentProjectWorkers = max(0.0, lei_number_or_null($payload['government_project_worker_count'] ?? null) ?? 0.0);

    $businessCount = max(0.0, lei_number_or_null($payload['business_count'] ?? null) ?? 0.0);
    $restaurantCount = max(0.0, lei_number_or_null($payload['restaurant_count'] ?? null) ?? 0.0);
    $governmentOfficeCount = max(0.0, lei_number_or_null($payload['government_office_count'] ?? null) ?? 0.0);
    $schoolCount = max(0.0, lei_number_or_null($payload['school_count'] ?? null) ?? 0.0);
    $governmentProjectCount = max(0.0, lei_number_or_null($payload['government_project_count'] ?? null) ?? 0.0);
    $gasStationCount = max(0.0, lei_number_or_null($payload['gas_station_count'] ?? null) ?? 0.0);
    $touristAttractionCount = max(0.0, lei_number_or_null($payload['tourist_attraction_count'] ?? null) ?? 0.0);
    $largeMallCount = max(0.0, lei_number_or_null($payload['large_mall_count'] ?? null) ?? 0.0);
    $marketCount = max(0.0, lei_number_or_null($payload['market_count'] ?? null) ?? 0.0);
    $agriculturalAreaRai = max(0.0, lei_number_or_null($payload['agricultural_area_rai'] ?? null) ?? 0.0);
    $hospitalCount = max(0.0, lei_number_or_null($payload['hospital_count'] ?? null) ?? 0.0);
    $industrialEstateCount = max(0.0, lei_number_or_null($payload['industrial_estate_count'] ?? null) ?? 0.0);
    $warehouseLogisticsCount = max(0.0, lei_number_or_null($payload['warehouse_logistics_count'] ?? null) ?? 0.0);
    $hotelLodgingCount = max(0.0, lei_number_or_null($payload['hotel_lodging_count'] ?? null) ?? 0.0);
    $bankBranchCount = max(0.0, lei_number_or_null($payload['bank_branch_count'] ?? null) ?? 0.0);
    $atmCount = max(0.0, lei_number_or_null($payload['atm_count'] ?? null) ?? 0.0);
    $convenienceStoreCount = max(0.0, lei_number_or_null($payload['convenience_store_count'] ?? null) ?? 0.0);
    $transportHubCount = max(0.0, lei_number_or_null($payload['transport_hub_count'] ?? null) ?? 0.0);
    $universityVocationalCount = max(0.0, lei_number_or_null($payload['university_vocational_count'] ?? null) ?? 0.0);
    $constructionProjectCount = max(0.0, lei_number_or_null($payload['construction_project_count'] ?? null) ?? 0.0);
    $rentalHousingUnitCount = max(0.0, lei_number_or_null($payload['rental_housing_unit_count'] ?? null) ?? 0.0);
    $nightMarketCount = max(0.0, lei_number_or_null($payload['night_market_count'] ?? null) ?? 0.0);

    $activeEconomicPeople = $jobOpenings
        + $factoryWorkers
        + $retailWorkers
        + $hospitalWorkers
        + $companyWorkers
        + $partnershipWorkers
        + $governmentProjectWorkers;

    $basePopulation = max(1.0, $population);
    $laborPressure = max(0.05, min(1.80, $activeEconomicPeople / $basePopulation));
    $floatingPressure = max(0.0, min(2.0, $floatingPopulation / $basePopulation));
    $passThroughPressure = max(0.0, min(2.0, $passThroughPopulation / $basePopulation));

    $economicNodeWeightedCount =
        ($businessCount * 1.00) +
        ($restaurantCount * 0.80) +
        ($governmentOfficeCount * 2.00) +
        ($schoolCount * 1.50) +
        ($governmentProjectCount * 2.50) +
        ($gasStationCount * 1.20) +
        ($touristAttractionCount * 2.20) +
        ($largeMallCount * 6.00) +
        ($marketCount * 2.00) +
        ($hospitalCount * 2.50) +
        ($industrialEstateCount * 4.00) +
        ($warehouseLogisticsCount * 2.00) +
        ($hotelLodgingCount * 1.60) +
        ($bankBranchCount * 1.70) +
        ($atmCount * 0.35) +
        ($convenienceStoreCount * 0.75) +
        ($transportHubCount * 2.20) +
        ($universityVocationalCount * 2.40) +
        ($constructionProjectCount * 1.80) +
        ($rentalHousingUnitCount * 0.025) +
        ($nightMarketCount * 1.40);
    $economicNodePressure = max(0.0, min(2.0, $economicNodeWeightedCount / max(1.0, $basePopulation / 1200.0)));
    $agriAreaPressure = max(0.0, min(1.5, $agriculturalAreaRai / max(1.0, $basePopulation / 3.0)));

    $socioFactor = 1.0
        + (($laborPressure - 0.45) * 0.20)
        + ($floatingPressure * 0.08)
        + ($passThroughPressure * 0.05)
        + ($economicNodePressure * 0.07)
        + ($agriAreaPressure * 0.04);
    $socioFactor = max(0.85, min(1.30, $socioFactor));

    $indexRatio = max(0.70, min(3.00, $indexRatio * $socioFactor));
    $householdIndex = round($indexRatio * 100, 2);
    $householdBaseline = round(max(3500.0, min(50000.0, 7800.0 * $indexRatio)), 2);

    // Assumption for medium quality-of-life cost baseline in Thailand (per person / month).
    $assumedHouseholdSize = 2.5;
    $electricityUnitsPerPerson = 45.0;
    $waterUnitsPerPerson = 4.5;

    $electricityUnitRate = lei_number_or_null($payload['electricity_unit_rate'] ?? null);
    if ($electricityUnitRate === null || $electricityUnitRate <= 0) {
        $electricityUnitRate = 3.95;
    }

    $waterUnitRate = lei_number_or_null($payload['water_unit_rate'] ?? null);
    if ($waterUnitRate === null || $waterUnitRate <= 0) {
        $waterUnitRate = 12.0;
    }

    $internetMobileMonthlyFee = lei_number_or_null($payload['internet_mobile_monthly_fee'] ?? null);
    if ($internetMobileMonthlyFee === null || $internetMobileMonthlyFee <= 0) {
        $internetMobileMonthlyFee = 650.0;
    }

    $utilityPerPerson = ($electricityUnitRate * $electricityUnitsPerPerson)
        + ($waterUnitRate * $waterUnitsPerPerson)
        + $internetMobileMonthlyFee;
    $utilityPerPerson = max(600.0, min(3000.0, $utilityPerPerson));

    $baselinePerPerson = $householdBaseline / $assumedHouseholdSize;
    $nonUtilityPerPerson = max(900.0, $baselinePerPerson - $utilityPerPerson);
    $householdPerPerson = $utilityPerPerson + $nonUtilityPerPerson;

    $socioInputCount = 0;
    foreach ([
        $population,
        $floatingPopulation,
        $passThroughPopulation,
        $jobOpenings,
        $factoryWorkers,
        $retailWorkers,
        $hospitalWorkers,
        $companyWorkers,
        $partnershipWorkers,
        $governmentProjectWorkers,
        $businessCount,
        $restaurantCount,
        $governmentOfficeCount,
        $schoolCount,
        $governmentProjectCount,
        $gasStationCount,
        $touristAttractionCount,
        $largeMallCount,
        $marketCount,
        $agriculturalAreaRai,
        $hospitalCount,
        $industrialEstateCount,
        $warehouseLogisticsCount,
        $hotelLodgingCount,
        $bankBranchCount,
        $atmCount,
        $convenienceStoreCount,
        $transportHubCount,
        $universityVocationalCount,
        $constructionProjectCount,
        $rentalHousingUnitCount,
        $nightMarketCount,
    ] as $numVal) {
        if ($numVal > 0) {
            $socioInputCount++;
        }
    }

    return [
        'household_index' => $householdIndex,
        'household_baseline_monthly' => $householdBaseline,
        'input_count' => $inputCount + $socioInputCount,
        'socio_factor' => round($socioFactor, 4),
        'assumed_household_size' => $assumedHouseholdSize,
        'utility_per_person_monthly' => round($utilityPerPerson, 2),
        'non_utility_per_person_monthly' => round($nonUtilityPerPerson, 2),
        'household_per_person_monthly' => round($householdPerPerson, 2),
        'electricity_unit_rate' => round($electricityUnitRate, 4),
        'water_unit_rate' => round($waterUnitRate, 4),
        'internet_mobile_monthly_fee' => round($internetMobileMonthlyFee, 2),
        'electricity_units_per_person' => $electricityUnitsPerPerson,
        'water_units_per_person' => $waterUnitsPerPerson,
    ];
}
/**
 * @return array<string, array<string, mixed>>
 */
function lei_fetch_branch_household_profiles(): array
{
    $stmt = db()->query(
        'SELECT branch_code, data_json
         FROM master_branch
         WHERE is_latest = 1 AND is_deleted = 0'
    );

    $profiles = [];
    foreach ($stmt->fetchAll() as $row) {
        $branchCode = strtoupper(trim((string)($row['branch_code'] ?? '')));
        if ($branchCode === '') {
            continue;
        }

        $meta = lei_decode_json_array((string)($row['data_json'] ?? ''));
        $profile = $meta['household_expense_index'] ?? null;
        if (!is_array($profile)) {
            continue;
        }

        $profiles[$branchCode] = $profile;
    }

    return $profiles;
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @return array{branch_code:string, household_index:float, household_baseline_monthly:float}
 */
function lei_apply_household_profile_from_row(int $sourceId, array $scope): array
{
    if ($sourceId <= 0) {
        throw new RuntimeException('LEI record to apply index was not found');
    }

    $scopeClause = access_scope_sql_clause('w.branch_code', 'scope_lei_apply', $scope);
    $sql = '
        SELECT w.*
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
        throw new RuntimeException('LEI record not found or access denied');
    }

    $payload = lei_decode_json_array((string)($row['data_json'] ?? ''));
    $branchCode = strtoupper(trim((string)($payload['branch_code'] ?? $row['branch_code'] ?? '')));
    if ($branchCode === '') {
        throw new RuntimeException('LEI record does not contain branch code');
    }
    assert_branch_in_current_scope($branchCode);

    $household = lei_compute_household_profile($payload);

    $stmtBranch = db()->prepare(
        'SELECT *
         FROM master_branch
         WHERE branch_code = :branch_code
           AND is_latest = 1
           AND is_deleted = 0
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmtBranch->execute([':branch_code' => $branchCode]);
    $branchRow = $stmtBranch->fetch();
    if (!is_array($branchRow)) {
        throw new RuntimeException('Branch data not found in master_branch');
    }

    $branchMeta = lei_decode_json_array((string)($branchRow['data_json'] ?? ''));
    $branchMeta['household_expense_index'] = [
        'method' => 'LEI_WEIGHTED_V2',
        'index_value' => (float)$household['household_index'],
        'baseline_monthly' => (float)$household['household_baseline_monthly'],
        'assumed_household_size' => (float)($household['assumed_household_size'] ?? 2.5),
        'utility_per_person_monthly' => (float)($household['utility_per_person_monthly'] ?? 0),
        'non_utility_per_person_monthly' => (float)($household['non_utility_per_person_monthly'] ?? 0),
        'household_per_person_monthly' => (float)($household['household_per_person_monthly'] ?? 0),
        'electricity_unit_rate' => (float)($household['electricity_unit_rate'] ?? 0),
        'water_unit_rate' => (float)($household['water_unit_rate'] ?? 0),
        'internet_mobile_monthly_fee' => (float)($household['internet_mobile_monthly_fee'] ?? 0),
        'electricity_units_per_person' => (float)($household['electricity_units_per_person'] ?? 45),
        'water_units_per_person' => (float)($household['water_units_per_person'] ?? 4.5),
        'socio_factor' => (float)($household['socio_factor'] ?? 1.0),
        'source_module' => 'local_economy_lei',
        'source_record_id' => (int)($row['id'] ?? 0),
        'source_record_uid' => (string)($row['record_uid'] ?? ''),
        'source_report_no' => (string)($payload['lei_report_no'] ?? $row['primary_ref'] ?? ''),
        'source_period_date' => (string)($payload['period_date'] ?? $row['event_date'] ?? ''),
        'input_count' => (int)$household['input_count'],
        'updated_at' => now_dt(),
        'updated_by' => current_user_name(),
    ];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmtFlag = $pdo->prepare(
            'UPDATE master_branch
             SET is_latest = 0
             WHERE record_uid = :record_uid
               AND is_latest = 1'
        );
        $stmtFlag->execute([':record_uid' => (string)$branchRow['record_uid']]);

        $stmtInsert = $pdo->prepare(
            'INSERT INTO master_branch (
                record_uid, version_no, is_latest, is_deleted, branch_code, branch_name, region_name, data_json,
                created_by, created_at, updated_by, updated_at, deleted_by, deleted_at
            ) VALUES (
                :record_uid, :version_no, 1, 0, :branch_code, :branch_name, :region_name, :data_json,
                :created_by, :created_at, :updated_by, :updated_at, NULL, NULL
            )'
        );
        $stmtInsert->execute([
            ':record_uid' => (string)$branchRow['record_uid'],
            ':version_no' => ((int)($branchRow['version_no'] ?? 1)) + 1,
            ':branch_code' => (string)$branchRow['branch_code'],
            ':branch_name' => (string)$branchRow['branch_name'],
            ':region_name' => (string)$branchRow['region_name'],
            ':data_json' => json_encode($branchMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':created_by' => (string)$branchRow['created_by'],
            ':created_at' => (string)$branchRow['created_at'],
            ':updated_by' => current_user_name(),
            ':updated_at' => now_dt(),
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'branch_code' => $branchCode,
        'household_index' => (float)$household['household_index'],
        'household_baseline_monthly' => (float)$household['household_baseline_monthly'],
    ];
}

/**
 * @param array<string, array<string, string>> $branchMap
 */
function lei_branch_label(string $branchCode, array $branchMap): string
{
    $branchCode = strtoupper(trim($branchCode));
    if ($branchCode === '') {
        return '-';
    }

    $name = trim((string)($branchMap[$branchCode]['branch_name'] ?? ''));
    return $name === '' ? $branchCode : ($branchCode . ' - ' . $name);
}

$moduleKey = 'local_economy_lei';
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string)($_POST['module_key'] ?? '') === $moduleKey
    && (string)($_POST['action'] ?? '') === 'save_household_index'
) {
    try {
        verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));
        $saved = lei_apply_household_profile_from_row((int)($_POST['source_id'] ?? 0), current_access_scope());
        add_flash(
            'success',
            sprintf(
                'Saved household expense index for branch %s (index %.2f | baseline %.2f THB/month)',
                (string)$saved['branch_code'],
                (float)$saved['household_index'],
                (float)$saved['household_baseline_monthly']
            )
        );
    } catch (Throwable $e) {
        add_flash('danger', 'Failed to save household expense index: ' . $e->getMessage());
    }

    $target = app_base_url('modules/17_local_economy_lei.php');
    $q = trim((string)($_POST['q'] ?? ''));
    if ($q !== '') {
        $target .= '?q=' . rawurlencode($q);
    }
    redirect_to($target);
}

$context = handle_module_request($moduleKey);
$module = $context['module'];
$rows = $context['rows'];
$summary = is_array($context['summary'] ?? null) ? $context['summary'] : [];
$edit = $context['edit'];
$searchTerm = trim((string)($context['search_term'] ?? ''));
$isEdit = is_array($edit);
$payload = ($isEdit && is_array($edit['payload'] ?? null)) ? $edit['payload'] : [];

$scope = current_access_scope();
$branchMap = active_branch_map();
$branchHouseholdProfiles = lei_fetch_branch_household_profiles();
$allowedBranchCodes = accessible_branch_codes($scope);
$allowedLookup = [];
foreach ($allowedBranchCodes as $allowedCode) {
    $allowedLookup[strtoupper(trim((string)$allowedCode))] = true;
}

$branchOptions = [];
foreach ($branchMap as $branchCode => $branchItem) {
    $branchCode = strtoupper(trim((string)$branchCode));
    if ($branchCode === '') {
        continue;
    }
    if ($scope['scope'] !== 'all' && !isset($allowedLookup[$branchCode])) {
        continue;
    }
    $branchOptions[$branchCode] = [
        'branch_code' => $branchCode,
        'branch_name' => trim((string)($branchItem['branch_name'] ?? '')),
    ];
}
ksort($branchOptions);

$defaultReportNo = 'LEI' . date('ymdHis') . sprintf('%03d', random_int(100, 999));
$reportNoValue = trim((string)($payload['lei_report_no'] ?? ''));
if ($reportNoValue === '') {
    $reportNoValue = $defaultReportNo;
}

$branchCodeValue = strtoupper(trim((string)($payload['branch_code'] ?? ($edit['branch_code'] ?? ''))));
if ($branchCodeValue === '' && $scope['scope'] === 'branch') {
    $branchCodeValue = strtoupper(trim((string)($scope['branch_code'] ?? '')));
}
$branchReadonly = ($scope['scope'] === 'branch');

$periodTypeValue = strtoupper(trim((string)($payload['period_type'] ?? 'MONTHLY')));
if (!in_array($periodTypeValue, ['WEEKLY', 'MONTHLY'], true)) {
    $periodTypeValue = 'MONTHLY';
}

$periodDateValue = trim((string)($payload['period_date'] ?? date('Y-m-d')));
if ($periodDateValue === '') {
    $periodDateValue = date('Y-m-d');
}

$leiGroups = [
    [
        'title' => 'Fresh Food & Ingredients',
        'fields' => [
            ['key' => 'rice_5kg_price', 'label' => 'Rice 5kg (THB/bag)'],
            ['key' => 'egg_no3_tray_price', 'label' => 'Eggs No.3 (THB/tray)'],
            ['key' => 'pork_red_meat_price_kg', 'label' => 'Red pork meat (THB/kg)'],
            ['key' => 'chicken_breast_price_kg', 'label' => 'Chicken breast (THB/kg)'],
            ['key' => 'cooking_oil_1l_price', 'label' => 'Cooking oil 1L (THB/bottle)'],
            ['key' => 'sugar_price_kg', 'label' => 'Sugar (THB/kg)'],
            ['key' => 'instant_noodle_pack_price', 'label' => 'Instant noodles (THB/pack)'],
            ['key' => 'kitchen_veg_price', 'label' => 'Common vegetables (THB/100g)'],
        ],
    ],
    [
        'title' => 'Ready-to-Eat Meals',
        'fields' => [
            ['key' => 'street_food_plate_price', 'label' => 'Street food / made-to-order (THB/plate)'],
            ['key' => 'curry_rice_two_item_price', 'label' => 'Curry rice (2 items) (THB/plate)'],
            ['key' => 'thai_tea_price', 'label' => 'Thai tea / traditional coffee (THB/cup)'],
            ['key' => 'bottled_water_600ml_price', 'label' => 'Bottled water 600ml (THB/bottle)'],
            ['key' => 'standard_set_meal_price', 'label' => 'Standard set meal (THB/set)'],
        ],
    ],
    [
        'title' => 'Transport & Energy',
        'fields' => [
            ['key' => 'fuel_price_per_liter', 'label' => 'Gasoline/Diesel (THB/liter)'],
            ['key' => 'motorbike_taxi_fare', 'label' => 'Motorbike taxi (THB/trip)'],
            ['key' => 'public_transport_fare', 'label' => 'Public transport fare (THB/trip)'],
            ['key' => 'parking_hourly_fee', 'label' => 'Parking fee (THB/hour)'],
            ['key' => 'lpg_15kg_price', 'label' => 'LPG 15kg tank (THB/tank)'],
        ],
    ],
    [
        'title' => 'Daily Expenses & Services',
        'fields' => [
            ['key' => 'men_haircut_price', 'label' => 'Men haircut (THB/time)'],
            ['key' => 'laundry_service_price', 'label' => 'Laundry service (THB/item/load)'],
            ['key' => 'internet_mobile_monthly_fee', 'label' => 'Internet/Mobile (THB/month)'],
            ['key' => 'electricity_unit_rate', 'label' => 'Electricity rate (THB/unit)'],
            ['key' => 'water_unit_rate', 'label' => 'Water rate (THB/unit)'],
        ],
    ],
    [
        'title' => 'Population & Purchasing Power',
        'fields' => [
            ['key' => 'local_population_count', 'label' => 'Local population (people)'],
            ['key' => 'floating_population_count', 'label' => 'Floating population (people)'],
            ['key' => 'pass_through_population_count', 'label' => 'Pass-through commuters (people)'],
            ['key' => 'job_opening_count', 'label' => 'Open positions / labor demand (people)'],
            ['key' => 'factory_worker_count', 'label' => 'Factory workers (people)'],
            ['key' => 'retail_worker_count', 'label' => 'Retail workers (people)'],
            ['key' => 'hospital_worker_count', 'label' => 'Hospital workers (people)'],
            ['key' => 'company_worker_count', 'label' => 'Company employees (people)'],
            ['key' => 'partnership_worker_count', 'label' => 'Partnership employees (people)'],
            ['key' => 'government_project_worker_count', 'label' => 'Government project workers (people)'],
            ['key' => 'business_count', 'label' => 'Businesses in area (sites)'],
            ['key' => 'restaurant_count', 'label' => 'Restaurants (sites)'],
            ['key' => 'government_office_count', 'label' => 'Government offices (sites)'],
            ['key' => 'school_count', 'label' => 'Schools / educational institutes (sites)'],
            ['key' => 'government_project_count', 'label' => 'Active government projects (projects)'],
            ['key' => 'gas_station_count', 'label' => 'Gas stations (sites)'],
            ['key' => 'tourist_attraction_count', 'label' => 'Tourist attractions (sites)'],
            ['key' => 'large_mall_count', 'label' => 'Large malls (sites)'],
            ['key' => 'market_count', 'label' => 'Markets (sites)'],
            ['key' => 'agricultural_area_rai', 'label' => 'Agricultural area (rai)'],
            ['key' => 'hospital_count', 'label' => 'Hospitals/clinics (sites)'],
            ['key' => 'industrial_estate_count', 'label' => 'Industrial estates/zones (sites)'],
            ['key' => 'warehouse_logistics_count', 'label' => 'Distribution centers/warehouses (sites)'],
            ['key' => 'hotel_lodging_count', 'label' => 'Hotels/lodging sites (sites)'],
            ['key' => 'bank_branch_count', 'label' => 'Bank branches (sites)'],
            ['key' => 'atm_count', 'label' => 'ATM/CDM points (points)'],
            ['key' => 'convenience_store_count', 'label' => 'Convenience stores (sites)'],
            ['key' => 'transport_hub_count', 'label' => 'Major transport hubs (sites)'],
            ['key' => 'university_vocational_count', 'label' => 'Universities/vocational institutes (sites)'],
            ['key' => 'construction_project_count', 'label' => 'Active construction projects (projects)'],
            ['key' => 'rental_housing_unit_count', 'label' => 'Rental housing units (units)'],
            ['key' => 'night_market_count', 'label' => 'Night markets/walking streets (sites)'],
        ],
    ],
    [
        'title' => 'Household Consumer Goods',
        'fields' => [
            ['key' => 'soap_shampoo_price', 'label' => 'Soap/Shampoo (THB/standard size)'],
            ['key' => 'detergent_medium_bag_price', 'label' => 'Detergent (THB/medium bag)'],
            ['key' => 'tissue_pack_24_roll_price', 'label' => 'Tissue 24-roll pack (THB/pack)'],
        ],
    ],
    [
        'title' => 'Lifestyle & Special Indicators',
        'fields' => [
            ['key' => 'beer_can_price', 'label' => 'Beer can (THB/can)'],
            ['key' => 'cigarette_pack_price', 'label' => 'Cigarettes (THB/pack)'],
            ['key' => 'movie_ticket_price', 'label' => 'Movie ticket (THB/seat)'],
            ['key' => 'rent_room_monthly_price', 'label' => 'Room/house rent (THB/month)'],
            ['key' => 'actual_daily_wage', 'label' => 'Actual paid daily wage (THB/day)'],
        ],
    ],
];

$viewLabelMap = [];
foreach ($leiGroups as $group) {
    foreach ($group['fields'] as $field) {
        $viewLabelMap[(string)$field['key']] = (string)$field['label'];
    }
}

$pageTitle = (string)$module['title'];
$currentModule = $moduleKey;

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/menu.php';
?>
<style>
    #leiEntryModal .modal-dialog {
        max-width: min(96vw, 1280px);
    }
    #leiEntryModal .modal-content {
        height: min(92vh, 980px);
        overflow: hidden;
    }
    #leiEntryForm {
        display: flex;
        flex-direction: column;
        height: 100%;
        min-height: 0;
    }
    #leiEntryModal .modal-body {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        padding-bottom: 1rem;
    }
    #leiEntryModal .modal-footer {
        flex: 0 0 auto;
        background: #ffffff;
        border-top: 1px solid var(--border);
    }
    .lei-columns-scroll {
        overflow-x: auto;
        overflow-y: visible;
        padding-bottom: 0.25rem;
    }
    .lei-columns-grid {
        display: grid;
        grid-template-columns: repeat(5, 220px);
        gap: 0.75rem;
        min-width: 1168px;
    }
    .lei-col-card {
        border: 1px solid var(--border);
        border-radius: 0.75rem;
        background: #ffffff;
        padding: 0.75rem;
    }
    .lei-col-title {
        font-size: 0.9rem;
        font-weight: 700;
        color: #1e3a8a;
        border-bottom: 1px dashed #cfe0ff;
        padding-bottom: 0.35rem;
        margin-bottom: 0.5rem;
    }
    .lei-col-card .form-label {
        font-size: 0.78rem;
        margin-bottom: 0.2rem;
        color: #475569;
    }
    .lei-col-card .form-control {
        font-size: 0.85rem;
    }
    .lei-mini-note {
        font-size: 0.75rem;
        color: #64748b;
    }
    @media (max-width: 992px) {
        #leiEntryModal .modal-dialog {
            max-width: 100vw;
            margin: 0.5rem;
        }
        #leiEntryModal .modal-content {
            height: calc(100vh - 1rem);
        }
    }
</style>

<section class="card shadow-sm border-0 mb-4 module-hero">
    <div class="card-body">
        <h1 class="h4 mb-2"><?php echo h((string)$module['title']); ?></h1>
        <p class="mb-0 text-muted"><?php echo h((string)$module['description']); ?></p>
    </div>
</section>

<section class="card shadow-sm border-0 mb-4 module-toolbar">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <button id="openLeiCreate" class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#leiEntryModal">
                + Add LEI Price Entry
            </button>
            <form class="d-flex gap-2 module-search" method="get" action="<?php echo h(app_base_url('modules/17_local_economy_lei.php')); ?>">
                <input class="form-control" type="text" name="q" value="<?php echo h($searchTerm); ?>" placeholder="Search report code, branch, or survey date">
                <button class="btn btn-outline-primary" type="submit">Search</button>
            </form>
        </div>
    </div>
</section>

<section class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>Total</span><strong><?php echo number_format((int)($summary['total_rows'] ?? 0)); ?></strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>Pending Review</span><strong><?php echo number_format((int)($summary['pending_rows'] ?? 0)); ?></strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>Approved</span><strong><?php echo number_format((int)($summary['approved_rows'] ?? 0)); ?></strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>Soft Deleted</span><strong><?php echo number_format((int)($summary['deleted_rows'] ?? 0)); ?></strong></div></div>
</section>

<section class="card shadow-sm border-0">
    <div class="card-header bg-white fw-semibold">Local Economy Market Price Records (LEI)</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 js-admin-datatable">
            <thead class="table-light">
            <tr>
                <th>ID</th>
                <th>Report Code</th>
                <th>Branch</th>
                <th>Survey Date</th>
                <th>Rice 5kg</th>
                <th>Eggs No.3</th>
                <th>Red Pork</th>
                <th>Fuel</th>
                <th>Actual Wage</th>
                <th>Household Index</th>
                <th>Status</th>
                <th>Last Updated</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $rowPayload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
                $status = strtoupper(trim((string)($row['record_status'] ?? 'DRAFT')));
                $branchCode = strtoupper(trim((string)($rowPayload['branch_code'] ?? $row['branch_code'] ?? '')));
                $branchHouseholdProfile = is_array($branchHouseholdProfiles[$branchCode] ?? null)
                    ? $branchHouseholdProfiles[$branchCode]
                    : null;
                $activeSourceId = (int)($branchHouseholdProfile['source_record_id'] ?? 0);
                $isActiveIndexSource = $activeSourceId > 0 && $activeSourceId === (int)$row['id'];

                $viewRows = [];
                foreach ($viewLabelMap as $key => $label) {
                    $viewRows[] = [
                        'label' => $label,
                        'value' => lei_number_or_null($rowPayload[$key] ?? null),
                    ];
                }
                $viewData = [
                    'report_no' => (string)($rowPayload['lei_report_no'] ?? $row['primary_ref'] ?? ''),
                    'branch' => lei_branch_label($branchCode, $branchMap),
                    'period_type' => (string)($rowPayload['period_type'] ?? ''),
                    'period_date' => (string)($rowPayload['period_date'] ?? ''),
                    'note' => (string)($rowPayload['lei_note'] ?? ''),
                    'rows' => $viewRows,
                ];
                ?>
                <tr>
                    <td><?php echo (int)$row['id']; ?></td>
                    <td><code><?php echo h((string)($rowPayload['lei_report_no'] ?? $row['primary_ref'] ?? '-')); ?></code></td>
                    <td><?php echo h(lei_branch_label($branchCode, $branchMap)); ?></td>
                    <td><?php echo h((string)($rowPayload['period_date'] ?? $row['event_date'] ?? '-')); ?></td>
                    <td><?php echo number_format((float)($rowPayload['rice_5kg_price'] ?? 0), 2); ?></td>
                    <td><?php echo number_format((float)($rowPayload['egg_no3_tray_price'] ?? 0), 2); ?></td>
                    <td><?php echo number_format((float)($rowPayload['pork_red_meat_price_kg'] ?? 0), 2); ?></td>
                    <td><?php echo number_format((float)($rowPayload['fuel_price_per_liter'] ?? 0), 2); ?></td>
                    <td><?php echo number_format((float)($rowPayload['actual_daily_wage'] ?? 0), 2); ?></td>
                    <td>
                        <?php if (is_array($branchHouseholdProfile)): ?>
                            <?php
                            $indexValue = (float)($branchHouseholdProfile['index_value'] ?? 0);
                            $baselineMonthly = (float)($branchHouseholdProfile['baseline_monthly'] ?? 0);
                            ?>
                            <div><?php echo number_format($indexValue, 2); ?></div>
                            <div class="small text-muted"><?php echo number_format($baselineMonthly, 2); ?> THB/month</div>
                            <?php if ($isActiveIndexSource): ?>
                                <div class="small text-success fw-semibold">Active Source</div>
                            <?php endif; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><span class="badge text-bg-<?php echo h(badge_class_for_status($status)); ?>"><?php echo h($status); ?></span></td>
                    <td><?php echo h((string)($row['updated_at'] ?: $row['created_at'])); ?></td>
                    <td class="text-nowrap">
                        <button
                            class="btn btn-sm btn-outline-info js-lei-view-btn"
                            type="button"
                            data-bs-toggle="modal"
                            data-bs-target="#leiViewModal"
                            data-view='<?php echo h((string)json_encode($viewData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>'
                        >
                            View
                        </button>
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo h(app_base_url('modules/17_local_economy_lei.php?edit=' . (int)$row['id'] . ($searchTerm !== '' ? '&q=' . rawurlencode($searchTerm) : ''))); ?>">Edit</a>
                        <form method="post" class="d-inline">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="module_key" value="<?php echo h($moduleKey); ?>">
                            <input type="hidden" name="action" value="save_household_index">
                            <input type="hidden" name="source_id" value="<?php echo (int)$row['id']; ?>">
                            <input type="hidden" name="q" value="<?php echo h($searchTerm); ?>">
                            <button class="btn btn-sm btn-outline-secondary" type="submit">Save Household Index</button>
                        </form>

                        <?php if ($status !== 'APPROVED' && (int)$row['is_deleted'] === 0 && role_can_approve(current_role_name())): ?>
                            <form method="post" class="d-inline">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="module_key" value="<?php echo h($moduleKey); ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="source_id" value="<?php echo (int)$row['id']; ?>">
                                <input type="hidden" name="reason" value="Approve LEI record">
                                <button class="btn btn-sm btn-outline-success" type="submit">Approve</button>
                            </form>
                        <?php endif; ?>

                        <?php if ((int)$row['is_deleted'] === 0): ?>
                            <form method="post" class="d-inline needs-confirm-delete">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="module_key" value="<?php echo h($moduleKey); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="source_id" value="<?php echo (int)$row['id']; ?>">
                                <input type="hidden" name="reason" value="Delete LEI record by user">
                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<div class="modal fade modal-slide-down" id="leiEntryModal" tabindex="-1" aria-labelledby="leiEntryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <form id="leiEntryForm" method="post" class="validate-form" novalidate>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="module_key" value="<?php echo h($moduleKey); ?>">
                <input type="hidden" name="action" value="<?php echo $isEdit ? 'update' : 'create'; ?>">
                <?php if ($isEdit): ?>
                    <input type="hidden" name="source_id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
                <?php endif; ?>
                <input type="hidden" name="reason" value="<?php echo $isEdit ? 'Update LEI market price record' : 'Create LEI market price record'; ?>">

                <div class="modal-header">
                    <h2 class="h6 mb-0" id="leiEntryModalLabel"><?php echo $isEdit ? 'Edit LEI Price Record' : 'Add LEI Price Record'; ?></h2>
                    <div class="d-flex align-items-center gap-2">
                        <button class="btn btn-sm btn-brand" type="submit" form="leiEntryForm">Save</button>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-lg-3">
                            <label class="form-label">LEI Report Code *</label>
                            <input id="leiReportNoInput" class="form-control" name="lei_report_no" value="<?php echo h($reportNoValue); ?>" required readonly>
                            <div class="lei-mini-note">Auto-generated by system</div>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label">Branch *</label>
                            <select class="form-select" name="branch_code" <?php echo $branchReadonly ? 'disabled' : ''; ?> required>
                                <option value="">-- Select branch --</option>
                                <?php foreach ($branchOptions as $branchOption): ?>
                                    <?php $selected = $branchCodeValue === (string)$branchOption['branch_code']; ?>
                                    <option value="<?php echo h((string)$branchOption['branch_code']); ?>" <?php echo $selected ? 'selected' : ''; ?>>
                                        <?php echo h((string)$branchOption['branch_code'] . ' - ' . (string)$branchOption['branch_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($branchReadonly): ?>
                                <input type="hidden" name="branch_code" value="<?php echo h($branchCodeValue); ?>">
                            <?php endif; ?>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label">Data Frequency *</label>
                            <select class="form-select" name="period_type" required>
                                <option value="WEEKLY" <?php echo $periodTypeValue === 'WEEKLY' ? 'selected' : ''; ?>>WEEKLY</option>
                                <option value="MONTHLY" <?php echo $periodTypeValue === 'MONTHLY' ? 'selected' : ''; ?>>MONTHLY</option>
                            </select>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label">Survey / Period End Date *</label>
                            <input class="form-control" type="date" name="period_date" value="<?php echo h($periodDateValue); ?>" required>
                        </div>
                    </div>

                    <div class="lei-columns-scroll">
                        <div class="lei-columns-grid">
                            <?php foreach ($leiGroups as $group): ?>
                                <div class="lei-col-card">
                                    <div class="lei-col-title"><?php echo h((string)$group['title']); ?></div>
                                    <?php foreach ($group['fields'] as $field): ?>
                                        <div class="mb-2">
                                            <label class="form-label"><?php echo h((string)$field['label']); ?></label>
                                            <input
                                                class="form-control"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                name="<?php echo h((string)$field['key']); ?>"
                                                value="<?php echo h(lei_input_number($payload, (string)$field['key'])); ?>"
                                                placeholder="0.00"
                                            >
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label">Area Note</label>
                        <textarea class="form-control" name="lei_note" rows="3" placeholder="e.g. temporary market closure, heavy rain, festival event"><?php echo h((string)($payload['lei_note'] ?? '')); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-brand" type="submit">Save Record</button>
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade modal-slide-down" id="leiViewModal" tabindex="-1" aria-labelledby="leiViewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h2 class="h6 mb-0" id="leiViewModalLabel">LEI Price Record Details</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-4"><strong>Report Code:</strong> <span id="leiViewReportNo">-</span></div>
                    <div class="col-md-4"><strong>Branch:</strong> <span id="leiViewBranch">-</span></div>
                    <div class="col-md-4"><strong>Survey Date:</strong> <span id="leiViewPeriodDate">-</span></div>
                </div>
                <div class="small text-muted mb-2">Frequency: <span id="leiViewPeriodType">-</span></div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th style="width: 70%;">Item</th>
                            <th style="width: 30%;">Price (THB)</th>
                        </tr>
                        </thead>
                        <tbody id="leiViewRows"></tbody>
                    </table>
                </div>
                <div class="mt-3">
                    <label class="form-label mb-1">Note</label>
                    <div id="leiViewNote" class="small text-muted">-</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var viewButtons = document.querySelectorAll('.js-lei-view-btn');
    var reportNoEl = document.getElementById('leiViewReportNo');
    var branchEl = document.getElementById('leiViewBranch');
    var periodDateEl = document.getElementById('leiViewPeriodDate');
    var periodTypeEl = document.getElementById('leiViewPeriodType');
    var noteEl = document.getElementById('leiViewNote');
    var rowsEl = document.getElementById('leiViewRows');

    function formatMoney(value) {
        if (value === null || value === undefined || value === '' || isNaN(Number(value))) {
            return '-';
        }
        return Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    viewButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var raw = btn.getAttribute('data-view') || '{}';
            var data = {};
            try {
                data = JSON.parse(raw);
            } catch (err) {
                data = {};
            }

            reportNoEl.textContent = data.report_no || '-';
            branchEl.textContent = data.branch || '-';
            periodDateEl.textContent = data.period_date || '-';
            periodTypeEl.textContent = data.period_type || '-';
            noteEl.textContent = data.note || '-';

            rowsEl.innerHTML = '';
            if (Array.isArray(data.rows)) {
                data.rows.forEach(function (row) {
                    var tr = document.createElement('tr');
                    var td1 = document.createElement('td');
                    var td2 = document.createElement('td');
                    td1.textContent = row.label || '-';
                    td2.textContent = formatMoney(row.value);
                    tr.appendChild(td1);
                    tr.appendChild(td2);
                    rowsEl.appendChild(tr);
                });
            }
        });
    });

    var leiEntryModal = document.getElementById('leiEntryModal');
    if (leiEntryModal) {
        leiEntryModal.addEventListener('shown.bs.modal', function () {
            var modalBody = leiEntryModal.querySelector('.modal-body');
            if (modalBody) {
                modalBody.scrollTop = 0;
            }
        });
    }

    <?php if ($isEdit): ?>
    window.addEventListener('DOMContentLoaded', function () {
        if (!window.bootstrap) {
            return;
        }
        var modalEl = document.getElementById('leiEntryModal');
        if (!modalEl) {
            return;
        }
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });
    <?php endif; ?>
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>



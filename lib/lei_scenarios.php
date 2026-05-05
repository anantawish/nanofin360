<?php
declare(strict_types=1);

/**
 * @return array<string, array{code:string,label:string,cost_multiplier:float,income_multiplier:float,pd_shift_pct:float,npl_shift_pct:float,description:string}>
 */
function lei_default_scenarios(): array
{
    return [
        'BASE' => [
            'code' => 'BASE',
            'label' => 'ฐานปัจจุบัน',
            'cost_multiplier' => 1.00,
            'income_multiplier' => 1.00,
            'pd_shift_pct' => 0.00,
            'npl_shift_pct' => 0.00,
            'description' => 'ใช้ข้อมูล LEI ปัจจุบันของสาขาโดยไม่ช็อกเพิ่ม',
        ],
        'GOOD' => [
            'code' => 'GOOD',
            'label' => 'ดี',
            'cost_multiplier' => 0.96,
            'income_multiplier' => 1.04,
            'pd_shift_pct' => -0.80,
            'npl_shift_pct' => -0.50,
            'description' => 'ต้นทุนผ่อนคลายขึ้นเล็กน้อย รายได้ดีขึ้นเล็กน้อย',
        ],
        'VERY_GOOD' => [
            'code' => 'VERY_GOOD',
            'label' => 'ดีมาก',
            'cost_multiplier' => 0.92,
            'income_multiplier' => 1.08,
            'pd_shift_pct' => -1.60,
            'npl_shift_pct' => -1.00,
            'description' => 'เศรษฐกิจพื้นที่เป็นบวกชัดเจน ใช้ทดสอบกรณีแนวโน้มดีมาก',
        ],
        'WATCH' => [
            'code' => 'WATCH',
            'label' => 'เฝ้าระวัง',
            'cost_multiplier' => 1.08,
            'income_multiplier' => 0.96,
            'pd_shift_pct' => 1.20,
            'npl_shift_pct' => 0.80,
            'description' => 'ค่าครองชีพเพิ่มเล็กน้อย รายได้ลดเล็กน้อย เหมาะสำหรับ Early Warning',
        ],
        'STRESS' => [
            'code' => 'STRESS',
            'label' => 'ตึงตัว',
            'cost_multiplier' => 1.18,
            'income_multiplier' => 0.90,
            'pd_shift_pct' => 3.00,
            'npl_shift_pct' => 2.00,
            'description' => 'สถานการณ์ตึงตัว ค่าครองชีพสูงขึ้นและรายได้หดตัวชัดเจน',
        ],
        'SEVERE' => [
            'code' => 'SEVERE',
            'label' => 'วิกฤต',
            'cost_multiplier' => 1.30,
            'income_multiplier' => 0.82,
            'pd_shift_pct' => 5.50,
            'npl_shift_pct' => 3.80,
            'description' => 'สถานการณ์วิกฤต ใช้ทดสอบขีดความทนทานของพอร์ต',
        ],
    ];
}

function lei_normalize_scenario_code(string $code): string
{
    $normalized = strtoupper(trim($code));
    $defaults = lei_default_scenarios();
    if ($normalized === '' || !isset($defaults[$normalized])) {
        return 'BASE';
    }

    return $normalized;
}

/**
 * @param array<string, mixed>|null $branchProfile
 * @return array<string, array{code:string,label:string,cost_multiplier:float,income_multiplier:float,pd_shift_pct:float,npl_shift_pct:float,description:string}>
 */
function lei_branch_scenarios(?array $branchProfile): array
{
    $scenarios = lei_default_scenarios();
    if (!is_array($branchProfile)) {
        return $scenarios;
    }

    $overrides = $branchProfile['scenario_overrides'] ?? null;
    if (!is_array($overrides)) {
        return $scenarios;
    }

    foreach ($scenarios as $code => $scenario) {
        $override = $overrides[$code] ?? null;
        if (!is_array($override)) {
            continue;
        }

        $cost = isset($override['cost_multiplier']) && is_numeric((string)$override['cost_multiplier'])
            ? (float)$override['cost_multiplier']
            : (float)$scenario['cost_multiplier'];
        $income = isset($override['income_multiplier']) && is_numeric((string)$override['income_multiplier'])
            ? (float)$override['income_multiplier']
            : (float)$scenario['income_multiplier'];
        $pdShift = isset($override['pd_shift_pct']) && is_numeric((string)$override['pd_shift_pct'])
            ? (float)$override['pd_shift_pct']
            : (float)$scenario['pd_shift_pct'];
        $nplShift = isset($override['npl_shift_pct']) && is_numeric((string)$override['npl_shift_pct'])
            ? (float)$override['npl_shift_pct']
            : (float)$scenario['npl_shift_pct'];

        $scenarios[$code]['cost_multiplier'] = max(0.50, min(2.00, $cost));
        $scenarios[$code]['income_multiplier'] = max(0.30, min(1.50, $income));
        $scenarios[$code]['pd_shift_pct'] = max(-5.00, min(30.00, $pdShift));
        $scenarios[$code]['npl_shift_pct'] = max(-5.00, min(30.00, $nplShift));

        $label = trim((string)($override['label'] ?? ''));
        if ($label !== '') {
            $scenarios[$code]['label'] = $label;
        }
        $description = trim((string)($override['description'] ?? ''));
        if ($description !== '') {
            $scenarios[$code]['description'] = $description;
        }
    }

    return $scenarios;
}

/**
 * @param array<string, mixed>|null $branchProfile
 * @return array{code:string,label:string,cost_multiplier:float,income_multiplier:float,pd_shift_pct:float,npl_shift_pct:float,description:string}
 */
function lei_scenario_assumption(string $scenarioCode, ?array $branchProfile = null): array
{
    $normalized = lei_normalize_scenario_code($scenarioCode);
    $scenarios = lei_branch_scenarios($branchProfile);

    return $scenarios[$normalized] ?? $scenarios['BASE'];
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string}|null $scope
 */
function lei_fetch_branch_household_profile(string $branchCode, ?array $scope = null): ?array
{
    $branchCode = strtoupper(trim($branchCode));
    if ($branchCode === '') {
        return null;
    }

    if ($scope === null) {
        $scope = current_access_scope();
    }
    if (!is_branch_in_current_scope($branchCode, $scope)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT data_json
         FROM master_branch
         WHERE branch_code = :branch_code
           AND is_latest = 1
           AND is_deleted = 0
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([':branch_code' => $branchCode]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }

    $decoded = json_decode((string)($row['data_json'] ?? ''), true);
    if (!is_array($decoded)) {
        return null;
    }

    $profile = $decoded['household_expense_index'] ?? null;
    return is_array($profile) ? $profile : null;
}

/**
 * @param array<string, array{code:string,label:string,cost_multiplier:float,income_multiplier:float,pd_shift_pct:float,npl_shift_pct:float,description:string}> $scenarios
 * @return array<int, array{code:string,label:string,cost_multiplier:float,income_multiplier:float,pd_shift_pct:float,npl_shift_pct:float,description:string}>
 */
function lei_scenario_options_for_select(array $scenarios): array
{
    $rows = [];
    foreach ($scenarios as $code => $scenario) {
        $rows[] = [
            'code' => (string)$code,
            'label' => (string)($scenario['label'] ?? $code),
            'cost_multiplier' => (float)($scenario['cost_multiplier'] ?? 1.0),
            'income_multiplier' => (float)($scenario['income_multiplier'] ?? 1.0),
            'pd_shift_pct' => (float)($scenario['pd_shift_pct'] ?? 0.0),
            'npl_shift_pct' => (float)($scenario['npl_shift_pct'] ?? 0.0),
            'description' => (string)($scenario['description'] ?? ''),
        ];
    }

    return $rows;
}

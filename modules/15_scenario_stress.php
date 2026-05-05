<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function stress_positive_number(mixed $value): float
{
    if ($value === null || $value === '' || !is_numeric((string)$value)) {
        return 0.0;
    }

    return max(0.0, (float)$value);
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string} $scope
 * @return array<int, array<string, mixed>>
 */
function stress_fetch_credit_scoring_rows(array $scope, string $branchCode = ''): array
{
    $scopeClause = access_scope_sql_clause('w.branch_code', 'stress_scope', $scope);
    $sql = '
        SELECT w.id, w.primary_ref, w.customer_ref, w.branch_code, w.data_json, w.updated_at
        FROM workflow_records w
        WHERE w.module_key = "credit_scoring"
          AND w.is_latest = 1
          AND w.is_deleted = 0
          ' . $scopeClause['sql'] . '
    ';
    $params = $scopeClause['params'];

    $branchCode = strtoupper(trim($branchCode));
    if ($branchCode !== '') {
        $sql .= ' AND w.branch_code = :branch_code';
        $params[':branch_code'] = $branchCode;
    }

    $sql .= ' ORDER BY w.id DESC LIMIT 5000';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function stress_extract_components(array $row): ?array
{
    $payload = json_decode((string)($row['data_json'] ?? ''), true);
    if (!is_array($payload)) {
        return null;
    }

    $components = $payload['score_components'] ?? null;
    if (is_string($components) && trim($components) !== '') {
        $decoded = json_decode($components, true);
        if (is_array($decoded)) {
            $components = $decoded;
        }
    }
    if (!is_array($components)) {
        return null;
    }

    $monthlyCapacity = stress_positive_number($components['monthly_capacity'] ?? 0);
    $incomeTotal = stress_positive_number($components['income_total'] ?? 0);
    $debtInstallment = stress_positive_number($components['existing_debt_installment'] ?? 0);
    $householdExpense = stress_positive_number($components['household_expense'] ?? 0);
    $recommendedInstallment = stress_positive_number($components['recommended_installment'] ?? 0);
    if ($recommendedInstallment <= 0) {
        $recommendedInstallment = $monthlyCapacity;
    }
    $recommendedLoan = stress_positive_number($components['recommended_loan_amount'] ?? 0);
    $pdPct = stress_positive_number($components['estimated_pd_pct'] ?? 0);
    if ($pdPct <= 0) {
        $pdBand = strtoupper(trim((string)($payload['pd_band'] ?? '')));
        $pdPct = match ($pdBand) {
            'LOW' => 2.5,
            'MEDIUM' => 6.0,
            'HIGH' => 10.0,
            'VERY_HIGH' => 15.0,
            default => 7.0,
        };
    }

    return [
        'application_no' => (string)($row['primary_ref'] ?? ''),
        'customer_ref' => (string)($row['customer_ref'] ?? ''),
        'branch_code' => (string)($row['branch_code'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? ''),
        'income_total' => $incomeTotal,
        'monthly_capacity' => $monthlyCapacity,
        'existing_debt_installment' => $debtInstallment,
        'household_expense' => $householdExpense,
        'recommended_installment' => $recommendedInstallment,
        'recommended_loan_amount' => $recommendedLoan,
        'estimated_pd_pct' => $pdPct,
    ];
}

/**
 * @param array{code:string,label:string,cost_multiplier:float,income_multiplier:float,pd_shift_pct:float,npl_shift_pct:float,description:string} $scenario
 * @param array<string, mixed> $component
 * @return array<string, mixed>
 */
function stress_simulate_row(array $component, array $scenario): array
{
    $incomeBase = stress_positive_number($component['income_total'] ?? 0);
    if ($incomeBase <= 0) {
        $incomeBase = 10000.0;
    }
    $capacityBase = stress_positive_number($component['monthly_capacity'] ?? 0);
    $debtInstallment = stress_positive_number($component['existing_debt_installment'] ?? 0);
    $householdExpense = stress_positive_number($component['household_expense'] ?? 0);
    $pdBase = stress_positive_number($component['estimated_pd_pct'] ?? 0);
    $targetInstallment = stress_positive_number($component['recommended_installment'] ?? $capacityBase);
    $targetLoan = stress_positive_number($component['recommended_loan_amount'] ?? 0);

    $costMultiplier = max(0.50, min(2.00, (float)($scenario['cost_multiplier'] ?? 1.0)));
    $incomeMultiplier = max(0.30, min(1.50, (float)($scenario['income_multiplier'] ?? 1.0)));
    $pdShift = max(-5.0, min(30.0, (float)($scenario['pd_shift_pct'] ?? 0.0)));
    $nplShift = max(-5.0, min(30.0, (float)($scenario['npl_shift_pct'] ?? 0.0)));

    $incomeStress = $incomeBase * $incomeMultiplier;
    $costIncrease = $householdExpense * max(0.0, $costMultiplier - 1.0);
    $capacityStress = max(0.0, ($capacityBase * $incomeMultiplier) - $costIncrease);

    $installmentGap = max(0.0, $targetInstallment - $capacityStress);
    $installmentCoveragePct = $targetInstallment > 0 ? ($capacityStress / $targetInstallment) * 100.0 : 100.0;
    $dsrStress = $incomeStress > 0
        ? (($debtInstallment + $targetInstallment) / $incomeStress) * 100.0
        : 999.0;

    $pdStress = $pdBase
        + $pdShift
        + max(0.0, 100.0 - $installmentCoveragePct) * 0.06
        + max(0.0, $dsrStress - 55.0) * 0.06;
    $pdStress = max(0.0, min(99.0, $pdStress));

    $projectedNpl = $pdStress + $nplShift;
    $projectedNpl = max(0.0, min(99.0, $projectedNpl));

    $status = 'SAFE';
    if ($projectedNpl >= 18.0 || $installmentCoveragePct < 50.0) {
        $status = 'SEVERE';
    } elseif ($projectedNpl >= 12.0 || $installmentCoveragePct < 70.0) {
        $status = 'HIGH_RISK';
    } elseif ($projectedNpl >= 8.0 || $installmentCoveragePct < 85.0) {
        $status = 'WATCH';
    }

    $loanCapacityAfterStress = 0.0;
    if ($targetInstallment > 0 && $targetLoan > 0) {
        $loanCapacityAfterStress = $targetLoan * min(1.0, max(0.0, $installmentCoveragePct / 100.0));
    }

    return [
        'application_no' => (string)($component['application_no'] ?? ''),
        'customer_ref' => (string)($component['customer_ref'] ?? ''),
        'branch_code' => (string)($component['branch_code'] ?? ''),
        'updated_at' => (string)($component['updated_at'] ?? ''),
        'income_base' => $incomeBase,
        'income_stress' => $incomeStress,
        'capacity_base' => $capacityBase,
        'capacity_stress' => $capacityStress,
        'installment_target' => $targetInstallment,
        'installment_gap' => $installmentGap,
        'installment_coverage_pct' => $installmentCoveragePct,
        'pd_base' => $pdBase,
        'pd_stress' => $pdStress,
        'projected_npl' => $projectedNpl,
        'loan_capacity_after_stress' => $loanCapacityAfterStress,
        'status' => $status,
    ];
}

$moduleKey = 'scenario_stress';
$context = handle_module_request($moduleKey);
$module = $context['module'];

$scope = current_access_scope();
$selectedBranch = strtoupper(trim((string)($_GET['sim_branch'] ?? '')));
$allowedBranches = accessible_branch_codes($scope);
$allowedLookup = array_fill_keys(array_map(static fn(string $code): string => strtoupper(trim($code)), $allowedBranches), true);
if ($selectedBranch !== '' && !isset($allowedLookup[$selectedBranch]) && $scope['scope'] !== 'all') {
    $selectedBranch = '';
}

$branchOptions = [];
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

$selectedScenarioCode = lei_normalize_scenario_code((string)($_GET['lei_scenario'] ?? 'STRESS'));
$branchProfile = $selectedBranch !== '' ? lei_fetch_branch_household_profile($selectedBranch, $scope) : null;
$scenarioMap = lei_branch_scenarios($branchProfile);
if (!isset($scenarioMap[$selectedScenarioCode])) {
    $selectedScenarioCode = 'STRESS';
}
$selectedScenario = lei_scenario_assumption($selectedScenarioCode, $branchProfile);
$scenarioOptions = lei_scenario_options_for_select($scenarioMap);

$components = [];
foreach (stress_fetch_credit_scoring_rows($scope, $selectedBranch) as $row) {
    $component = stress_extract_components($row);
    if (is_array($component)) {
        $components[] = $component;
    }
}

$simRows = [];
foreach ($components as $component) {
    $simRows[] = stress_simulate_row($component, $selectedScenario);
}

usort($simRows, static function (array $a, array $b): int {
    $order = ['SEVERE' => 4, 'HIGH_RISK' => 3, 'WATCH' => 2, 'SAFE' => 1];
    $sa = $order[(string)($a['status'] ?? 'SAFE')] ?? 0;
    $sb = $order[(string)($b['status'] ?? 'SAFE')] ?? 0;
    if ($sb !== $sa) {
        return $sb <=> $sa;
    }
    return ((float)($b['projected_npl'] ?? 0)) <=> ((float)($a['projected_npl'] ?? 0));
});

$simRows = array_slice($simRows, 0, 500);

$portfolioCount = count($simRows);
$severeCount = 0;
$highRiskCount = 0;
$watchCount = 0;
$sumProjectedNpl = 0.0;
$sumBaseCapacity = 0.0;
$sumStressCapacity = 0.0;
$sumInstallmentGap = 0.0;
$sumLoanCapacityAfterStress = 0.0;

foreach ($simRows as $row) {
    $status = (string)($row['status'] ?? 'SAFE');
    if ($status === 'SEVERE') {
        $severeCount++;
    } elseif ($status === 'HIGH_RISK') {
        $highRiskCount++;
    } elseif ($status === 'WATCH') {
        $watchCount++;
    }
    $sumProjectedNpl += (float)($row['projected_npl'] ?? 0);
    $sumBaseCapacity += (float)($row['capacity_base'] ?? 0);
    $sumStressCapacity += (float)($row['capacity_stress'] ?? 0);
    $sumInstallmentGap += (float)($row['installment_gap'] ?? 0);
    $sumLoanCapacityAfterStress += (float)($row['loan_capacity_after_stress'] ?? 0);
}

$avgProjectedNpl = $portfolioCount > 0 ? ($sumProjectedNpl / $portfolioCount) : 0.0;
$capacityDropTotal = max(0.0, $sumBaseCapacity - $sumStressCapacity);
$capacityDropPct = $sumBaseCapacity > 0 ? ($capacityDropTotal / $sumBaseCapacity) * 100.0 : 0.0;

$pageTitle = (string)$module['title'];
$currentModule = $moduleKey;

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/menu.php';
?>
<section class="card shadow-sm border-0 mb-4 module-hero">
    <div class="card-body">
        <h1 class="h4 mb-2"><?php echo h((string)$module['title']); ?></h1>
        <p class="mb-0 text-muted"><?php echo h((string)$module['description']); ?></p>
    </div>
</section>

<section class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-lg-4">
                <label class="form-label">LEI Simulation Branch</label>
                <select class="form-select" name="sim_branch">
                    <option value="">All accessible branches</option>
                    <?php foreach ($branchOptions as $branch): ?>
                        <?php
                        $code = (string)$branch['branch_code'];
                        $label = $code . ' - ' . (string)$branch['branch_name'];
                        ?>
                        <option value="<?php echo h($code); ?>" <?php echo $selectedBranch === $code ? 'selected' : ''; ?>>
                            <?php echo h($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-6">
                <label class="form-label">LEI Hypothesis for Stress Test</label>
                <select class="form-select" name="lei_scenario">
                    <?php foreach ($scenarioOptions as $scenario): ?>
                        <?php
                        $code = (string)$scenario['code'];
                        $label = (string)$scenario['label'];
                        ?>
                        <option value="<?php echo h($code); ?>" <?php echo $selectedScenarioCode === $code ? 'selected' : ''; ?>>
                            <?php echo h($label . ' | Cost x' . number_format((float)$scenario['cost_multiplier'], 2) . ' | Income x' . number_format((float)$scenario['income_multiplier'], 2) . ' | PD +' . number_format((float)$scenario['pd_shift_pct'], 2) . '% | NPL +' . number_format((float)$scenario['npl_shift_pct'], 2) . '%'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 d-flex gap-2">
                <button class="btn btn-brand" type="submit">Simulate</button>
                <a class="btn btn-outline-secondary" href="<?php echo h(app_base_url('modules/15_scenario_stress.php')); ?>">Clear</a>
            </div>
        </form>
        <div class="small text-muted mt-2">
            <?php echo h((string)$selectedScenario['description']); ?>
        </div>
    </div>
</section>

<section class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>Number of simulated accounts</span><strong><?php echo number_format($portfolioCount); ?></strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>SEVERE</span><strong><?php echo number_format($severeCount); ?></strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>HIGH RISK</span><strong><?php echo number_format($highRiskCount); ?></strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>WATCH</span><strong><?php echo number_format($watchCount); ?></strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>Average Projected NPL</span><strong><?php echo number_format($avgProjectedNpl, 2); ?>%</strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>Repayment Capacity Loss (THB)</span><strong><?php echo number_format($capacityDropTotal, 2); ?></strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>Repayment Capacity Drop (%)</span><strong><?php echo number_format($capacityDropPct, 2); ?>%</strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>Total Installment Gap</span><strong><?php echo number_format($sumInstallmentGap, 2); ?></strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>Post-Stress Loan Capacity</span><strong><?php echo number_format($sumLoanCapacityAfterStress, 2); ?></strong></div></div>
</section>

<section class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white fw-semibold">Account-Level Impact under LEI Scenario</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 js-admin-datatable">
            <thead class="table-light">
            <tr>
                <th>Application</th>
                <th>Customer</th>
                <th>Branch</th>
                <th>Target installment</th>
                <th>Repayment Capacity Before/After</th>
                <th>Coverage</th>
                <th>Installment Gap</th>
                <th>PD before/after</th>
                <th>Projected NPL</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($simRows as $row): ?>
                <?php
                $status = (string)($row['status'] ?? 'SAFE');
                $badge = match ($status) {
                    'SEVERE' => 'danger',
                    'HIGH_RISK' => 'warning',
                    'WATCH' => 'info',
                    default => 'success',
                };
                ?>
                <tr>
                    <td><code><?php echo h((string)$row['application_no']); ?></code></td>
                    <td><?php echo h((string)$row['customer_ref']); ?></td>
                    <td><?php echo h((string)$row['branch_code']); ?></td>
                    <td><?php echo number_format((float)$row['installment_target'], 2); ?></td>
                    <td>
                        <?php echo number_format((float)$row['capacity_base'], 2); ?>
                        /
                        <?php echo number_format((float)$row['capacity_stress'], 2); ?>
                    </td>
                    <td><?php echo number_format((float)$row['installment_coverage_pct'], 2); ?>%</td>
                    <td><?php echo number_format((float)$row['installment_gap'], 2); ?></td>
                    <td>
                        <?php echo number_format((float)$row['pd_base'], 2); ?>%
                        /
                        <?php echo number_format((float)$row['pd_stress'], 2); ?>%
                    </td>
                    <td><?php echo number_format((float)$row['projected_npl'], 2); ?>%</td>
                    <td><span class="badge text-bg-<?php echo h($badge); ?>"><?php echo h($status); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php render_module_page($context); ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function ews_positive_number(mixed $value): float
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
function ews_fetch_credit_scoring_rows(array $scope, string $branchCode = ''): array
{
    $scopeClause = access_scope_sql_clause('w.branch_code', 'ews_scope', $scope);
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

    $sql .= ' ORDER BY w.id DESC LIMIT 3000';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function ews_extract_components(array $row): ?array
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

    $monthlyCapacity = ews_positive_number($components['monthly_capacity'] ?? 0);
    $incomeTotal = ews_positive_number($components['income_total'] ?? 0);
    $existingDebt = ews_positive_number($components['existing_debt_installment'] ?? 0);
    $householdExpense = ews_positive_number($components['household_expense'] ?? 0);
    $pdPct = ews_positive_number($payload['score_components_pd_pct'] ?? 0);
    if ($pdPct <= 0) {
        $pdPct = ews_positive_number($components['estimated_pd_pct'] ?? 0);
    }
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
        'monthly_capacity' => $monthlyCapacity,
        'income_total' => $incomeTotal,
        'existing_debt_installment' => $existingDebt,
        'household_expense' => $householdExpense,
        'estimated_pd_pct' => $pdPct,
    ];
}

/**
 * @param array{code:string,label:string,cost_multiplier:float,income_multiplier:float,pd_shift_pct:float,npl_shift_pct:float,description:string} $scenario
 * @param array<string, mixed> $component
 * @return array<string, mixed>
 */
function ews_simulate_row(array $component, array $scenario): array
{
    $incomeBase = ews_positive_number($component['income_total'] ?? 0);
    if ($incomeBase <= 0) {
        $incomeBase = 10000.0;
    }
    $capacityBase = ews_positive_number($component['monthly_capacity'] ?? 0);
    $debtInstallment = ews_positive_number($component['existing_debt_installment'] ?? 0);
    $householdExpense = ews_positive_number($component['household_expense'] ?? 0);
    $pdBase = ews_positive_number($component['estimated_pd_pct'] ?? 0);

    $costMultiplier = max(0.50, min(2.00, (float)($scenario['cost_multiplier'] ?? 1.0)));
    $incomeMultiplier = max(0.30, min(1.50, (float)($scenario['income_multiplier'] ?? 1.0)));
    $pdShift = max(-5.0, min(30.0, (float)($scenario['pd_shift_pct'] ?? 0.0)));

    $incomeStress = $incomeBase * $incomeMultiplier;
    $extraCost = $householdExpense * max(0.0, $costMultiplier - 1.0);
    $capacityStress = max(0.0, ($capacityBase * $incomeMultiplier) - $extraCost);
    $capacityDropPct = $capacityBase > 0 ? (($capacityBase - $capacityStress) / $capacityBase) * 100.0 : 0.0;

    $dsrStress = $incomeStress > 0
        ? (($debtInstallment + $capacityStress) / $incomeStress) * 100.0
        : 999.0;
    $pdStress = $pdBase + $pdShift + max(0.0, $capacityDropPct - 15.0) * 0.08 + max(0.0, $dsrStress - 55.0) * 0.05;
    $pdStress = max(0.0, min(99.0, $pdStress));

    $severity = 'LOW';
    if ($pdStress >= 18.0 || $capacityDropPct >= 55.0) {
        $severity = 'CRITICAL';
    } elseif ($pdStress >= 12.0 || $capacityDropPct >= 35.0) {
        $severity = 'HIGH';
    } elseif ($pdStress >= 8.0 || $capacityDropPct >= 20.0) {
        $severity = 'MEDIUM';
    }

    $action = match ($severity) {
        'CRITICAL' => 'Immediate call, field visit, and restructuring offer',
        'HIGH' => 'Proactive follow-up call within 3 days',
        'MEDIUM' => 'Early alert and payment behavior review',
        default => 'Standard monthly monitoring',
    };

    return [
        'application_no' => (string)($component['application_no'] ?? ''),
        'customer_ref' => (string)($component['customer_ref'] ?? ''),
        'branch_code' => (string)($component['branch_code'] ?? ''),
        'updated_at' => (string)($component['updated_at'] ?? ''),
        'income_base' => $incomeBase,
        'income_stress' => $incomeStress,
        'capacity_base' => $capacityBase,
        'capacity_stress' => $capacityStress,
        'capacity_drop_pct' => $capacityDropPct,
        'dsr_stress' => $dsrStress,
        'pd_base' => $pdBase,
        'pd_stress' => $pdStress,
        'severity' => $severity,
        'watchlist' => in_array($severity, ['HIGH', 'CRITICAL'], true) ? 'YES' : 'NO',
        'recommended_action' => $action,
    ];
}

$moduleKey = 'early_warning';
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

$selectedScenarioCode = lei_normalize_scenario_code((string)($_GET['lei_scenario'] ?? 'WATCH'));
$branchProfile = $selectedBranch !== '' ? lei_fetch_branch_household_profile($selectedBranch, $scope) : null;
$scenarioMap = lei_branch_scenarios($branchProfile);
if (!isset($scenarioMap[$selectedScenarioCode])) {
    $selectedScenarioCode = 'WATCH';
}
$selectedScenario = lei_scenario_assumption($selectedScenarioCode, $branchProfile);
$scenarioOptions = lei_scenario_options_for_select($scenarioMap);

$components = [];
foreach (ews_fetch_credit_scoring_rows($scope, $selectedBranch) as $row) {
    $component = ews_extract_components($row);
    if (is_array($component)) {
        $components[] = $component;
    }
}

$simRows = [];
foreach ($components as $component) {
    $simRows[] = ews_simulate_row($component, $selectedScenario);
}

usort($simRows, static function (array $a, array $b): int {
    $sevOrder = ['CRITICAL' => 4, 'HIGH' => 3, 'MEDIUM' => 2, 'LOW' => 1];
    $sa = $sevOrder[(string)($a['severity'] ?? 'LOW')] ?? 0;
    $sb = $sevOrder[(string)($b['severity'] ?? 'LOW')] ?? 0;
    if ($sb !== $sa) {
        return $sb <=> $sa;
    }
    return ((float)($b['pd_stress'] ?? 0.0)) <=> ((float)($a['pd_stress'] ?? 0.0));
});

$simRows = array_slice($simRows, 0, 400);

$summaryTotal = count($simRows);
$summaryCritical = 0;
$summaryHigh = 0;
$summaryWatchlist = 0;
$summaryAvgPd = 0.0;
foreach ($simRows as $simRow) {
    $severity = (string)($simRow['severity'] ?? 'LOW');
    if ($severity === 'CRITICAL') {
        $summaryCritical++;
    } elseif ($severity === 'HIGH') {
        $summaryHigh++;
    }
    if ((string)($simRow['watchlist'] ?? 'NO') === 'YES') {
        $summaryWatchlist++;
    }
    $summaryAvgPd += (float)($simRow['pd_stress'] ?? 0);
}
if ($summaryTotal > 0) {
    $summaryAvgPd /= $summaryTotal;
}

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
                <label class="form-label">LEI Scenario for Early Warning</label>
                <select class="form-select" name="lei_scenario">
                    <?php foreach ($scenarioOptions as $scenario): ?>
                        <?php
                        $code = (string)$scenario['code'];
                        $label = (string)$scenario['label'];
                        ?>
                        <option value="<?php echo h($code); ?>" <?php echo $selectedScenarioCode === $code ? 'selected' : ''; ?>>
                            <?php echo h($label . ' | Cost x' . number_format((float)$scenario['cost_multiplier'], 2) . ' | Income x' . number_format((float)$scenario['income_multiplier'], 2) . ' | PD +' . number_format((float)$scenario['pd_shift_pct'], 2) . '%'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 d-flex gap-2">
                <button class="btn btn-brand" type="submit">Simulate</button>
                <a class="btn btn-outline-secondary" href="<?php echo h(app_base_url('modules/09_early_warning.php')); ?>">Clear</a>
            </div>
        </form>
        <div class="small text-muted mt-2">
            <?php echo h((string)$selectedScenario['description']); ?>
        </div>
    </div>
</section>

<section class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>Analyzed Accounts</span><strong><?php echo number_format($summaryTotal); ?></strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>Critical</span><strong><?php echo number_format($summaryCritical); ?></strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>High</span><strong><?php echo number_format($summaryHigh); ?></strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>Watchlist (High+Critical)</span><strong><?php echo number_format($summaryWatchlist); ?></strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card"><span>Average PD after shock</span><strong><?php echo number_format($summaryAvgPd, 2); ?>%</strong></div></div>
</section>

<section class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white fw-semibold">Early Warning Results under LEI Scenario</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 js-admin-datatable">
            <thead class="table-light">
            <tr>
                <th>Application</th>
                <th>Customer</th>
                <th>Branch</th>
                <th>Income before/after shock</th>
                <th>Repayment Capacity Before/After Shock</th>
                <th>PD before/after</th>
                <th>Severity</th>
                <th>Watchlist</th>
                <th>Recommended Action</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($simRows as $row): ?>
                <?php $severity = (string)($row['severity'] ?? 'LOW'); ?>
                <tr>
                    <td><code><?php echo h((string)$row['application_no']); ?></code></td>
                    <td><?php echo h((string)$row['customer_ref']); ?></td>
                    <td><?php echo h((string)$row['branch_code']); ?></td>
                    <td>
                        <?php echo number_format((float)$row['income_base'], 2); ?>
                        /
                        <?php echo number_format((float)$row['income_stress'], 2); ?>
                    </td>
                    <td>
                        <?php echo number_format((float)$row['capacity_base'], 2); ?>
                        /
                        <?php echo number_format((float)$row['capacity_stress'], 2); ?>
                        <div class="small text-muted">Drop <?php echo number_format((float)$row['capacity_drop_pct'], 2); ?>%</div>
                    </td>
                    <td>
                        <?php echo number_format((float)$row['pd_base'], 2); ?>%
                        /
                        <?php echo number_format((float)$row['pd_stress'], 2); ?>%
                    </td>
                    <td>
                        <span class="badge text-bg-<?php echo h(badge_class_for_status($severity)); ?>">
                            <?php echo h($severity); ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge text-bg-<?php echo (string)$row['watchlist'] === 'YES' ? 'danger' : 'secondary'; ?>">
                            <?php echo h((string)$row['watchlist']); ?>
                        </span>
                    </td>
                    <td><?php echo h((string)$row['recommended_action']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php render_module_page($context); ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>

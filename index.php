<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$modules = all_modules();
foreach ($modules as $moduleKey => $moduleData) {
    if (!is_array($moduleData)) {
        continue;
    }
    $localized = ui_localize_module_definition($moduleData + ['key' => $moduleKey]);
    $modules[$moduleKey]['title'] = (string)($localized['title'] ?? (string)($moduleData['title'] ?? ''));
}
$scope = current_access_scope();

$moduleCounts = [];
$scopeWorkflow = access_scope_sql_clause('wr.branch_code', 'scope_wr', $scope);
$stmtCount = db()->prepare(
    'SELECT wr.module_key, COUNT(*) AS total
     FROM workflow_records wr
     WHERE wr.is_latest = 1' . $scopeWorkflow['sql'] . '
     GROUP BY wr.module_key'
);
$stmtCount->execute($scopeWorkflow['params']);
foreach ($stmtCount->fetchAll() as $row) {
    $moduleCounts[(string)$row['module_key']] = (int)$row['total'];
}

$events = [];
if (nanfin_table_exists(db(), 'event_ledger')) {
    $scopeEvent = access_scope_sql_clause('wr.branch_code', 'scope_evt', $scope);
    $eventStmt = db()->prepare(
        'SELECT e.event_type, e.module_key, e.record_uid, e.version_no, e.actor_name, e.created_at
         FROM event_ledger e
         LEFT JOIN workflow_records wr
           ON wr.module_key = e.module_key
          AND wr.record_uid = e.record_uid
          AND wr.is_latest = 1
         WHERE 1 = 1' . $scopeEvent['sql'] . '
         ORDER BY e.id DESC
         LIMIT 20'
    );
    $eventStmt->execute($scopeEvent['params']);
    $events = $eventStmt->fetchAll();
}

$scopeNotif = access_scope_sql_clause('wr.branch_code', 'scope_noti', $scope);
$notifStmt = db()->prepare(
    'SELECT n.module_key, n.level_name, n.message_text, n.user_name, n.created_at
     FROM notification_logs n
     LEFT JOIN workflow_records wr
       ON wr.module_key = n.module_key
      AND wr.record_uid = n.record_uid
      AND wr.is_latest = 1
     WHERE 1 = 1' . $scopeNotif['sql'] . '
     ORDER BY n.id DESC
     LIMIT 10'
);
$notifStmt->execute($scopeNotif['params']);
$notifs = $notifStmt->fetchAll();

$branchOptions = db()->query('SELECT branch_code, branch_name, region_name FROM master_branch WHERE is_latest = 1 AND is_deleted = 0 ORDER BY branch_code')->fetchAll();
$allowedBranchCodes = accessible_branch_codes($scope);
$allowedBranchLookup = array_fill_keys($allowedBranchCodes, true);
if ($scope['scope'] !== 'all') {
    $branchOptions = array_values(array_filter(
        $branchOptions,
        static fn(array $branchRow): bool => isset($allowedBranchLookup[(string)$branchRow['branch_code']])
    ));
}

$productOptions = db()->query('SELECT product_code, product_name FROM master_product WHERE is_latest = 1 AND is_deleted = 0 ORDER BY product_code')->fetchAll();

$branchMap = [];
foreach ($branchOptions as $branchRow) {
    $branchMap[(string)$branchRow['branch_code']] = (string)$branchRow['branch_name'];
}

$productMap = [];
foreach ($productOptions as $productRow) {
    $productMap[(string)$productRow['product_code']] = (string)$productRow['product_name'];
}

$branchCode = trim((string)($_GET['branch_code'] ?? ''));
$productCode = trim((string)($_GET['product_code'] ?? ''));
$startMonth = trim((string)($_GET['start_month'] ?? ''));
$endMonth = trim((string)($_GET['end_month'] ?? ''));

if (!isset($branchMap[$branchCode])) {
    $branchCode = '';
}
if (!isset($productMap[$productCode])) {
    $productCode = '';
}

$disbursementExpr = "STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.disbursement_date')), '%Y-%m-%d')";
$scopeRange = access_scope_sql_clause('branch_code', 'scope_range', $scope);
$dateRangeStmt = db()->prepare(
    "SELECT
        DATE_FORMAT(MIN({$disbursementExpr}), '%Y-%m') AS min_month,
        DATE_FORMAT(MAX({$disbursementExpr}), '%Y-%m') AS max_month
     FROM master_contract
     WHERE is_latest = 1 AND is_deleted = 0{$scopeRange['sql']}"
);
$dateRangeStmt->execute($scopeRange['params']);
$dateRange = $dateRangeStmt->fetch() ?: [];

if (!preg_match('/^\d{4}-\d{2}$/', $startMonth)) {
    $startMonth = (string)($dateRange['min_month'] ?? '');
}
if (!preg_match('/^\d{4}-\d{2}$/', $endMonth)) {
    $endMonth = (string)($dateRange['max_month'] ?? '');
}
if ($startMonth !== '' && $endMonth !== '' && $startMonth > $endMonth) {
    [$startMonth, $endMonth] = [$endMonth, $startMonth];
}

$whereSql = ['is_latest = 1', 'is_deleted = 0'];
$queryParams = [];

if ($scope['scope'] !== 'all') {
    if ($allowedBranchCodes === []) {
        $whereSql[] = '1 = 0';
    } else {
        $scopePlaceholders = [];
        foreach (array_values($allowedBranchCodes) as $idx => $scopeBranchCode) {
            $key = ':scope_branch_' . $idx;
            $scopePlaceholders[] = $key;
            $queryParams[$key] = $scopeBranchCode;
        }
        $whereSql[] = 'branch_code IN (' . implode(', ', $scopePlaceholders) . ')';
    }
}

if ($branchCode !== '') {
    $whereSql[] = 'branch_code = :branch_code';
    $queryParams[':branch_code'] = $branchCode;
}
if ($productCode !== '') {
    $whereSql[] = 'product_code = :product_code';
    $queryParams[':product_code'] = $productCode;
}
if ($startMonth !== '') {
    $whereSql[] = "{$disbursementExpr} >= :start_date";
    $queryParams[':start_date'] = $startMonth . '-01';
}
if ($endMonth !== '') {
    $whereSql[] = "{$disbursementExpr} <= :end_date";
    $queryParams[':end_date'] = (new DateTimeImmutable($endMonth . '-01'))->format('Y-m-t');
}

$whereClause = implode(' AND ', $whereSql);

$portfolioTotalStmt = db()->prepare(
    "SELECT
        COUNT(DISTINCT customer_code) AS borrower_total,
        COUNT(*) AS contract_total,
        COALESCE(SUM(JSON_LENGTH(JSON_EXTRACT(data_json, '$.payment_history'))), 0) AS installment_total,
        SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.dpd_bucket')) <> 'CURRENT' THEN 1 ELSE 0 END) AS delinquency_total,
        SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.current_status')) = 'NPL' THEN 1 ELSE 0 END) AS npl_total
    FROM master_contract
    WHERE {$whereClause}"
);
$portfolioTotalStmt->execute($queryParams);
$portfolioTotals = $portfolioTotalStmt->fetch() ?: [];

$riskTrendStmt = db()->prepare(
    "SELECT
        DATE_FORMAT({$disbursementExpr}, '%Y-%m') AS cohort_month,
        SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.dpd_bucket')) = '1-7' THEN 1 ELSE 0 END) AS bucket_1_7,
        SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.dpd_bucket')) = '8-30' THEN 1 ELSE 0 END) AS bucket_8_30,
        SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.dpd_bucket')) = '31-60' THEN 1 ELSE 0 END) AS bucket_31_60,
        SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.dpd_bucket')) = '61-90' THEN 1 ELSE 0 END) AS bucket_61_90,
        SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.dpd_bucket')) = '90+' THEN 1 ELSE 0 END) AS bucket_90_plus
    FROM master_contract
    WHERE {$whereClause}
    GROUP BY cohort_month
    HAVING cohort_month IS NOT NULL
    ORDER BY cohort_month"
);
$riskTrendStmt->execute($queryParams);
$riskTrendRows = $riskTrendStmt->fetchAll();

$riskTrendChart = [
    'labels' => [],
    'datasets' => [
        ['label' => '1-7 Days', 'data' => [], 'borderColor' => '#86bc25', 'backgroundColor' => 'rgba(134, 188, 37, 0.12)'],
        ['label' => '8-30 Days', 'data' => [], 'borderColor' => '#0f766e', 'backgroundColor' => 'rgba(15, 118, 110, 0.12)'],
        ['label' => '31-60 Days', 'data' => [], 'borderColor' => '#f59e0b', 'backgroundColor' => 'rgba(245, 158, 11, 0.12)'],
        ['label' => '61-90 Days', 'data' => [], 'borderColor' => '#ef4444', 'backgroundColor' => 'rgba(239, 68, 68, 0.12)'],
        ['label' => '90+ Days', 'data' => [], 'borderColor' => '#7c2d12', 'backgroundColor' => 'rgba(124, 45, 18, 0.14)'],
    ],
];

foreach ($riskTrendRows as $row) {
    $riskTrendChart['labels'][] = (string)$row['cohort_month'];
    $riskTrendChart['datasets'][0]['data'][] = (int)$row['bucket_1_7'];
    $riskTrendChart['datasets'][1]['data'][] = (int)$row['bucket_8_30'];
    $riskTrendChart['datasets'][2]['data'][] = (int)$row['bucket_31_60'];
    $riskTrendChart['datasets'][3]['data'][] = (int)$row['bucket_61_90'];
    $riskTrendChart['datasets'][4]['data'][] = (int)$row['bucket_90_plus'];
}

if ($branchCode !== '') {
    $selectedBranchLabel = $branchMap[$branchCode] ?? $branchCode;
} elseif ($scope['scope'] === 'branch') {
    $selectedBranchLabel = $branchMap[(string)$scope['branch_code']] ?? (string)$scope['branch_code'];
} elseif ($scope['scope'] === 'region') {
    $selectedBranchLabel = 'Region ' . ((string)$scope['region_name'] !== '' ? (string)$scope['region_name'] : '-');
} else {
    $selectedBranchLabel = 'All Branches';
}
$selectedProductLabel = $productCode !== '' ? ($productMap[$productCode] ?? $productCode) : 'All Products';
$selectedPeriodLabel = ($startMonth !== '' && $endMonth !== '') ? ($startMonth . ' to ' . $endMonth) : 'All Month Ranges';

$pageTitle = 'Smart Finance 360 Dashboard';
$currentModule = '';

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/menu.php';
?>
<section class="card shadow-sm border-0 mb-4 module-hero">
    <div class="card-body">
        <h1 class="h4 mb-2">Executive Dashboard + Integration Hub</h1>
        <p class="mb-0 text-muted">Portfolio overview, default risk, and system activities based on user access scope.</p>
    </div>
</section>

<section class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6"><div class="stat-card stat-card-kpi"><span>Total Borrowers</span><strong><?php echo number_format((int)($portfolioTotals['borrower_total'] ?? 0)); ?></strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card stat-card-kpi"><span>Total Contracts</span><strong><?php echo number_format((int)($portfolioTotals['contract_total'] ?? 0)); ?></strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card stat-card-kpi"><span>Total Installments</span><strong><?php echo number_format((int)($portfolioTotals['installment_total'] ?? 0)); ?></strong></div></div>
    <div class="col-xl-3 col-md-6"><div class="stat-card stat-card-kpi"><span>Delinquent / NPL</span><strong><?php echo number_format((int)($portfolioTotals['delinquency_total'] ?? 0)); ?> / <?php echo number_format((int)($portfolioTotals['npl_total'] ?? 0)); ?></strong></div></div>
</section>

<section class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 class="h6 mb-1">Default Risk Trend</h2>
                <div class="small text-muted"><?php echo h($selectedBranchLabel); ?> | <?php echo h($selectedProductLabel); ?> | <?php echo h($selectedPeriodLabel); ?></div>
            </div>
        </div>
        <form method="get" action="<?php echo h(app_base_url('index.php')); ?>" class="row g-3 chart-filter-form">
            <div class="col-xl-3 col-md-6">
                <label class="form-label" for="branch_code">Branch</label>
                <select class="form-select" id="branch_code" name="branch_code">
                    <option value="">All Branches</option>
                    <?php foreach ($branchOptions as $branchRow): ?>
                        <option value="<?php echo h((string)$branchRow['branch_code']); ?>" <?php echo $branchCode === (string)$branchRow['branch_code'] ? 'selected' : ''; ?>>
                            <?php echo h((string)$branchRow['branch_code'] . ' - ' . (string)$branchRow['branch_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-3 col-md-6">
                <label class="form-label" for="product_code">Product</label>
                <select class="form-select" id="product_code" name="product_code">
                    <option value="">All Products</option>
                    <?php foreach ($productOptions as $productRow): ?>
                        <option value="<?php echo h((string)$productRow['product_code']); ?>" <?php echo $productCode === (string)$productRow['product_code'] ? 'selected' : ''; ?>>
                            <?php echo h((string)$productRow['product_code'] . ' - ' . (string)$productRow['product_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-xl-2 col-md-6">
                <label class="form-label" for="start_month">Start Month</label>
                <input class="form-control" id="start_month" name="start_month" type="month" value="<?php echo h($startMonth); ?>">
            </div>
            <div class="col-xl-2 col-md-6">
                <label class="form-label" for="end_month">End Month</label>
                <input class="form-control" id="end_month" name="end_month" type="month" value="<?php echo h($endMonth); ?>">
            </div>
            <div class="col-xl-2 col-md-12 d-flex align-items-end gap-2">
                <button class="btn btn-brand w-100" type="submit">Filter Chart</button>
                <a class="btn btn-outline-secondary w-100" href="<?php echo h(app_base_url('index.php')); ?>">Clear</a>
            </div>
        </form>
    </div>
    <div class="card-body">
        <?php if ($riskTrendChart['labels'] === []): ?>
            <div class="text-muted">No data matched the selected filters.</div>
        <?php else: ?>
            <div class="chart-panel"><canvas id="riskTrendChart" height="120"></canvas></div>
        <?php endif; ?>
    </div>
</section>

<section class="row g-3 mb-4">
    <?php foreach ($modules as $key => $mod): ?>
        <div class="col-xl-3 col-lg-4 col-md-6">
            <a href="<?php echo h(app_base_url('modules/' . $mod['file'])); ?>" class="text-decoration-none">
                <div class="stat-card">
                    <span>Module <?php echo h((string)$mod['id']); ?></span>
                    <strong><?php echo (int)($moduleCounts[$key] ?? 0); ?></strong>
                    <div class="text-muted small mt-1"><?php echo h($mod['title']); ?></div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</section>

<section class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Latest Event Ledger</h2></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-sm mb-0">
                        <thead class="table-light"><tr><th>Date/Time</th><th>Module</th><th>Event</th><th>Record ID</th><th>Version</th><th>Actor</th></tr></thead>
                        <tbody>
                        <?php if ($events === []): ?>
                            <tr><td colspan="6" class="text-center py-3 text-muted">No events yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?php echo h((string)$event['created_at']); ?></td>
                                <td><?php echo h($modules[(string)$event['module_key']]['title'] ?? (string)$event['module_key']); ?></td>
                                <td><?php echo h(thai_action_label((string)$event['event_type'])); ?></td>
                                <td><code><?php echo h((string)$event['record_uid']); ?></code></td>
                                <td><?php echo (int)$event['version_no']; ?></td>
                                <td><?php echo h((string)$event['actor_name']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Latest Notifications</h2></div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php if ($notifs === []): ?>
                        <li class="list-group-item text-muted">No notifications.</li>
                    <?php endif; ?>
                    <?php foreach ($notifs as $notif): ?>
                        <li class="list-group-item">
                            <div class="small text-muted"><?php echo h((string)$notif['created_at']); ?> | <?php echo h($modules[(string)$notif['module_key']]['title'] ?? (string)$notif['module_key']); ?> | <?php echo h(thai_level_label((string)$notif['level_name'])); ?></div>
                            <div><?php echo h((string)$notif['message_text']); ?></div>
                            <div class="small text-muted">By <?php echo h((string)$notif['user_name']); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</section>

<?php if ($riskTrendChart['labels'] !== []): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script>
        window.smartFinanceRiskTrend = <?php echo json_encode($riskTrendChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    </script>
<?php endif; ?>
<?php include __DIR__ . '/partials/footer.php'; ?>

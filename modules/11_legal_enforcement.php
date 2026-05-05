<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/hire_purchase_core.php';

$moduleKey = 'legal_enforcement';
$module = module_by_key($moduleKey);
$scope = current_access_scope();

$branchRows = [];
$allowedBranch = array_fill_keys(accessible_branch_codes($scope), true);
foreach (active_branch_rows() as $b) {
    $bc = strtoupper(trim((string)($b['branch_code'] ?? '')));
    if ($scope['scope'] !== 'all' && !isset($allowedBranch[$bc])) {
        continue;
    }
    $branchRows[] = $b;
}

$selectedBranch = strtoupper(trim((string)($_GET['branch_code'] ?? '')));
if ($selectedBranch !== '' && !is_branch_in_current_scope($selectedBranch, $scope)) {
    $selectedBranch = '';
}
$searchText = trim((string)($_GET['q'] ?? ''));

$scopeClause = access_scope_sql_clause('wr.branch_code', 'lgl_scope', $scope);
$sql = 'SELECT wr.* FROM workflow_records wr WHERE wr.module_key = :module_key AND wr.is_latest = 1 AND wr.is_deleted = 0' . $scopeClause['sql'];
$params = $scopeClause['params'];
$params[':module_key'] = $moduleKey;

if ($selectedBranch !== '') {
    $sql .= ' AND wr.branch_code = :branch_code';
    $params[':branch_code'] = $selectedBranch;
}

if ($searchText !== '') {
    $sql .= ' AND (wr.primary_ref LIKE :search OR wr.primary_name LIKE :search OR wr.customer_ref LIKE :search OR wr.data_json LIKE :search)';
    $params[':search'] = '%' . $searchText . '%';
}

$sql .= ' ORDER BY wr.id DESC LIMIT 800';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll() ?: [];

$cases = [];
foreach ($rows as $row) {
    $payload = json_decode((string)($row['data_json'] ?? ''), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $cases[] = [
        'id' => (int)$row['id'],
        'legal_case_no' => trim((string)($payload['legal_case_no'] ?? ($row['primary_ref'] ?? ''))),
        'contract_no' => trim((string)($payload['contract_no'] ?? ($row['primary_name'] ?? ''))),
        'customer_ref' => trim((string)($row['customer_ref'] ?? '')),
        'branch_code' => trim((string)($row['branch_code'] ?? '')),
        'notice_type' => trim((string)($payload['notice_type'] ?? '')),
        'lawyer_name' => trim((string)($payload['lawyer_name'] ?? '')),
        'enforcement_stage' => trim((string)($payload['enforcement_stage'] ?? ($row['risk_level'] ?? ''))),
        'claim_amount' => round(max(0.0, hp_float($payload['claim_amount'] ?? ($row['amount'] ?? 0))), 2),
        'court_date' => trim((string)($payload['court_date'] ?? '')),
        'record_status' => trim((string)($row['record_status'] ?? '')),
        'updated_at' => trim((string)($row['updated_at'] ?? '')),
    ];
}

$pageTitle = (string)($module['title'] ?? 'Legal Enforcement');
$currentModule = $moduleKey;

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/menu.php';
?>
<section class="card shadow-sm border-0 mb-4 module-hero">
    <div class="card-body">
        <h1 class="h4 mb-2">Legal & Enforcement Queue</h1>
        <p class="mb-0 text-muted">Legal case table from the NPL Recovery module and enforcement follow-up list</p>
    </div>
</section>

<section class="card shadow-sm border-0 mb-4 module-toolbar">
    <div class="card-body">
        <form class="row g-2 align-items-end module-search" method="get" action="<?php echo h(app_base_url('modules/11_legal_enforcement.php')); ?>">
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
                <label class="form-label">Search for case number / contract number / customer code</label>
                <input class="form-control" name="q" value="<?php echo h($searchText); ?>" placeholder="Type a search term.">
            </div>
            <div class="col-lg-3 col-md-12 d-flex gap-2">
                <button class="btn btn-brand flex-grow-1" type="submit">Search</button>
                <a class="btn btn-outline-secondary" href="<?php echo h(app_base_url('modules/11_legal_enforcement.php')); ?>">Clear</a>
            </div>
        </form>
    </div>
</section>

<section class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <strong>Legal case table</strong>
        <span class="text-muted small">Found <?php echo number_format(count($cases)); ?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 js-admin-datatable">
            <thead>
            <tr>
                <th>Case number</th>
                <th>Contract number</th>
                <th>Customer</th>
                <th>Branch</th>
                <th>Case procedure</th>
                <th>Notice Type</th>
                <th>Claim Amount</th>
                <th>Court date</th>
                <th>Status</th>
                <th>Last Updated</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($cases === []): ?>
                <tr><td class="text-center text-muted">No case information found</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            <?php else: foreach ($cases as $case): ?>
                <?php
                $status = strtoupper((string)$case['record_status']);
                $statusClass = $status === 'APPROVED' ? 'success' : ($status === 'PENDING_CHECKER' ? 'warning text-dark' : 'secondary');
                ?>
                <tr>
                    <td><code><?php echo h((string)$case['legal_case_no']); ?></code></td>
                    <td><?php echo h((string)$case['contract_no']); ?></td>
                    <td><?php echo h((string)$case['customer_ref']); ?></td>
                    <td><?php echo h((string)$case['branch_code']); ?></td>
                    <td><?php echo h((string)$case['enforcement_stage']); ?></td>
                    <td><?php echo h((string)$case['notice_type']); ?></td>
                    <td><?php echo number_format((float)$case['claim_amount'], 2); ?></td>
                    <td><?php echo h((string)$case['court_date']); ?></td>
                    <td><span class="badge text-bg-<?php echo h($statusClass); ?>"><?php echo h($status !== '' ? $status : '-'); ?></span></td>
                    <td><?php echo h((string)$case['updated_at']); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../partials/footer.php';

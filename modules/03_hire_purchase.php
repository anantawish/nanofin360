<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/hire_purchase_core.php';

$moduleKey = 'hire_purchase';
$module = module_by_key($moduleKey);
if ($module === null) {
    http_response_code(500);
    echo 'Hire purchase module configuration not found';
    exit;
}

$scope = current_access_scope();
$searchText = trim((string)($_GET['search'] ?? ''));
$candidateSearchText = trim((string)($_GET['candidate_search'] ?? ''));
$borrowerNameText = trim((string)($_GET['borrower_name'] ?? ''));
$selectedBranchCode = strtoupper(trim((string)($_GET['branch_code'] ?? '')));
$openCreateModal = ((string)($_GET['open_create'] ?? '') === '1');
$requestedCandidateUid = trim((string)($_GET['candidate_uid'] ?? ''));

$allowedCodes = accessible_branch_codes($scope);
$allowedLookup = array_fill_keys(array_map(static fn(string $code): string => strtoupper(trim($code)), $allowedCodes), true);
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
if ($selectedBranchCode !== '' && $scope['scope'] !== 'all' && !isset($allowedLookup[$selectedBranchCode])) {
    $selectedBranchCode = '';
}
if ($selectedBranchCode === '' && $scope['scope'] === 'branch' && (string)($scope['branch_code'] ?? '') !== '') {
    $selectedBranchCode = strtoupper(trim((string)$scope['branch_code']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));
        $action = trim((string)($_POST['hp_action'] ?? ''));
        $actor = current_user_name();

        if ($action === 'create_contract') {
            $openCreateModal = true;
            $candidateUid = trim((string)($_POST['candidate_uid'] ?? ''));
            $loanAmount = round(max(0.0, hp_float($_POST['approved_loan_amount'] ?? 0)), 2);
            $annualRateInputPct = round(max(0.0, hp_float($_POST['annual_rate_pct'] ?? 0)), 4);
            $termInputMonths = hp_int($_POST['term_months'] ?? 0);
            $firstDueDate = hp_parse_date((string)($_POST['first_due_date'] ?? ''), date('Y-m-d'));
            $candidateSearchText = trim((string)($_POST['candidate_search'] ?? ''));
            $borrowerNameText = trim((string)($_POST['borrower_name'] ?? $borrowerNameText));
            $selectedBranchCode = strtoupper(trim((string)($_POST['branch_code'] ?? $selectedBranchCode)));
            if ($selectedBranchCode !== '' && $scope['scope'] !== 'all' && !isset($allowedLookup[$selectedBranchCode])) {
                $selectedBranchCode = '';
            }

            $candidateFilterText = trim($candidateSearchText . ' ' . $borrowerNameText);
            $candidateRows = hp_fetch_scoring_candidates($scope, $candidateFilterText, $selectedBranchCode, true);
            $selectedCandidate = null;
            foreach ($candidateRows as $candidateRow) {
                if ((string)$candidateRow['record_uid'] === $candidateUid) {
                    $selectedCandidate = $candidateRow;
                    break;
                }
            }
            if (!is_array($selectedCandidate)) {
                throw new RuntimeException('No eligible customer found for contract creation');
            }

            if ($loanAmount <= 0) {
                $loanAmount = round(max(0.0, hp_float($selectedCandidate['recommended_loan_amount'] ?? 0)), 2);
            }
            if ($loanAmount <= 0) {
                throw new RuntimeException('Approved loan amount must be greater than 0');
            }

            // Use interest rate/term from the approved policy result (Module 2).
            $annualRatePct = round(max(0.0, hp_float($selectedCandidate['annual_rate_pct'] ?? 0)), 4);
            if ($annualRatePct <= 0) {
                $annualRatePct = $annualRateInputPct > 0 ? $annualRateInputPct : 12.0;
            }

            $termMonths = max(0, hp_int($selectedCandidate['term_months'] ?? 0));
            if ($termMonths <= 0) {
                $termMonths = $termInputMonths > 0 ? $termInputMonths : 24;
            }

            $contractPdfUpload = hp_upload_file('loan_contract_pdf', ['pdf'], 20 * 1024 * 1024, 'contracts');
            if ($contractPdfUpload === null) {
                throw new RuntimeException('Please attach the loan contract document (PDF)');
            }

            $contractNo = hp_create_contract_from_candidate(
                $selectedCandidate,
                $loanAmount,
                $annualRatePct,
                $termMonths,
                $firstDueDate,
                '',
                $actor,
                (string)($contractPdfUpload['path'] ?? ''),
                (string)($contractPdfUpload['name'] ?? '')
            );

            add_flash('success', 'Contract created successfully: ' . $contractNo);
            redirect_to(app_base_url('modules/03_hire_purchase.php'));
        }

        if ($action === 'delete_contract') {
            $contractNo = strtoupper(trim((string)($_POST['contract_no'] ?? '')));
            if ($contractNo === '') {
                throw new RuntimeException('Target contract for deletion not found');
            }
            $contractRow = hp_find_contract_latest($contractNo, $scope);
            if ($contractRow === null) {
                throw new RuntimeException('Contract data not found or access denied');
            }
            hp_soft_delete_contract($contractRow, $actor);
            add_flash('warning', 'Contract soft-deleted successfully: ' . $contractNo);
            redirect_to(app_base_url('modules/03_hire_purchase.php'));
        }
    } catch (Throwable $e) {
        add_flash('danger', 'Unable to save data: ' . $e->getMessage());
    }
}

$candidateFilterText = trim($candidateSearchText . ' ' . $borrowerNameText);
$candidateRows = hp_fetch_scoring_candidates($scope, $candidateFilterText, $selectedBranchCode, true);
$contractRows = hp_fetch_contract_rows($scope, $searchText, $selectedBranchCode, $borrowerNameText);
$policyCatalog = hp_fetch_policy_catalog();

$autocompleteBuckets = [
    'global' => [],
    'borrower' => [],
    'candidate' => [],
];

$pushAutocomplete = static function (array &$bucket, string $value): void {
    $normalized = trim(preg_replace('/\s+/', ' ', $value));
    if ($normalized === '') {
        return;
    }
    $bucket[$normalized] = true;
};

foreach ($candidateRows as $candidateRow) {
    $customerCode = trim((string)($candidateRow['customer_code'] ?? ''));
    $customerName = trim((string)($candidateRow['customer_name'] ?? ''));
    $applicationNo = trim((string)($candidateRow['application_no'] ?? ''));
    $branchCode = trim((string)($candidateRow['branch_code'] ?? ''));

    if ($customerCode !== '') {
        $pushAutocomplete($autocompleteBuckets['global'], $customerCode);
        $pushAutocomplete($autocompleteBuckets['candidate'], $customerCode);
    }
    if ($customerName !== '') {
        $pushAutocomplete($autocompleteBuckets['global'], $customerName);
        $pushAutocomplete($autocompleteBuckets['borrower'], $customerName);
        $pushAutocomplete($autocompleteBuckets['candidate'], $customerName);
    }
    if ($applicationNo !== '') {
        $pushAutocomplete($autocompleteBuckets['global'], $applicationNo);
        $pushAutocomplete($autocompleteBuckets['candidate'], $applicationNo);
    }
    if ($branchCode !== '') {
        $pushAutocomplete($autocompleteBuckets['global'], $branchCode);
    }
    if ($customerCode !== '' || $customerName !== '') {
        $label = trim($customerCode . ' - ' . $customerName . ($branchCode !== '' ? (' (' . $branchCode . ')') : ''));
        $pushAutocomplete($autocompleteBuckets['candidate'], $label);
    }
}

foreach ($contractRows as $contractRow) {
    $customerCode = trim((string)($contractRow['customer_code'] ?? ''));
    $contractNo = trim((string)($contractRow['contract_no'] ?? ''));
    $branchCode = trim((string)($contractRow['branch_code'] ?? ''));
    $payload = is_array($contractRow['payload'] ?? null) ? $contractRow['payload'] : [];
    $customerName = trim((string)($payload['customer_name'] ?? ''));

    if ($contractNo !== '') {
        $pushAutocomplete($autocompleteBuckets['global'], $contractNo);
    }
    if ($customerCode !== '') {
        $pushAutocomplete($autocompleteBuckets['global'], $customerCode);
    }
    if ($customerName !== '') {
        $pushAutocomplete($autocompleteBuckets['global'], $customerName);
        $pushAutocomplete($autocompleteBuckets['borrower'], $customerName);
    }
    if ($branchCode !== '') {
        $pushAutocomplete($autocompleteBuckets['global'], $branchCode);
    }
}

$autocompleteGlobal = array_keys($autocompleteBuckets['global']);
$autocompleteBorrower = array_keys($autocompleteBuckets['borrower']);
$autocompleteCandidate = array_keys($autocompleteBuckets['candidate']);
sort($autocompleteGlobal, SORT_NATURAL | SORT_FLAG_CASE);
sort($autocompleteBorrower, SORT_NATURAL | SORT_FLAG_CASE);
sort($autocompleteCandidate, SORT_NATURAL | SORT_FLAG_CASE);

$policyCatalogForJs = [];
foreach ($policyCatalog as $policyCodeKey => $policyRow) {
    $policyCatalogForJs[$policyCodeKey] = [
        'id' => (int)($policyRow['id'] ?? 0),
        'policy_code' => (string)($policyRow['policy_code'] ?? ''),
        'policy_name' => (string)($policyRow['policy_name'] ?? ''),
        'payload' => is_array($policyRow['payload'] ?? null) ? $policyRow['payload'] : [],
    ];
}

$candidatePolicyForJs = [];
foreach ($candidateRows as $candidateRow) {
    $recordUid = (string)($candidateRow['record_uid'] ?? '');
    if ($recordUid === '') {
        continue;
    }
    $policyCode = trim((string)($candidateRow['policy_code'] ?? ''));
    $policyName = trim((string)($candidateRow['policy_name'] ?? ''));
    $policyCodeKey = strtoupper($policyCode);
    $policyPayload = is_array($candidateRow['policy_payload'] ?? null) ? $candidateRow['policy_payload'] : [];

    if ($policyPayload === [] && $policyCodeKey !== '' && isset($policyCatalog[$policyCodeKey])) {
        $catalogRow = $policyCatalog[$policyCodeKey];
        $policyPayload = is_array($catalogRow['payload'] ?? null) ? $catalogRow['payload'] : [];
        if ($policyName === '') {
            $policyName = trim((string)($catalogRow['policy_name'] ?? ''));
        }
    }

    $candidatePolicyForJs[$recordUid] = [
        'policy_code' => $policyCode,
        'policy_name' => $policyName,
        'payload' => $policyPayload,
    ];
}

$totals = [
    'contract_count' => count($contractRows),
    'principal_total' => 0.0,
    'active_count' => 0,
];

foreach ($contractRows as $row) {
    $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
    $totals['principal_total'] += (float)($row['principal_amount'] ?? 0);
    $status = strtoupper(trim((string)($payload['current_status'] ?? 'ACTIVE')));
    if ($status === 'ACTIVE') {
        $totals['active_count']++;
    }
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
        <div class="d-flex flex-wrap justify-content-end align-items-center gap-3">
            <form class="d-flex gap-2" method="get" action="<?php echo h(app_base_url('modules/03_hire_purchase.php')); ?>">
                <input type="hidden" name="branch_code" value="<?php echo h($selectedBranchCode); ?>">
                <input type="hidden" name="borrower_name" value="<?php echo h($borrowerNameText); ?>">
                <input type="hidden" name="candidate_search" value="<?php echo h($candidateSearchText); ?>">
                <input class="form-control" type="text" name="search" list="hpSearchList" value="<?php echo h($searchText); ?>" placeholder="Search contract no. / customer code / name">
                <button class="btn btn-outline-primary" type="submit">Search</button>
            </form>
        </div>

        <hr class="my-3">

        <form class="row g-2 align-items-end" method="get" action="<?php echo h(app_base_url('modules/03_hire_purchase.php')); ?>">
            <div class="col-lg-3">
                <label class="form-label">Branch</label>
                <select class="form-select" name="branch_code">
                    <option value="">All accessible branches</option>
                    <?php foreach ($branchOptions as $branch): ?>
                        <?php $code = strtoupper(trim((string)$branch['branch_code'])); ?>
                        <option value="<?php echo h($code); ?>" <?php echo $selectedBranchCode === $code ? 'selected' : ''; ?>>
                            <?php echo h($code . ' - ' . (string)$branch['branch_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3">
                <label class="form-label">Borrower Name from Module 2</label>
                <input class="form-control" type="text" name="borrower_name" list="hpBorrowerList" value="<?php echo h($borrowerNameText); ?>" placeholder="First name or last name">
            </div>
            <div class="col-lg-4">
                <label class="form-label">Additional Search</label>
                <input class="form-control" type="text" name="candidate_search" list="hpCandidateList" value="<?php echo h($candidateSearchText); ?>" placeholder="CUS..., application no., other keywords">
            </div>
            <div class="col-lg-2 d-grid gap-2">
                <input type="hidden" name="search" value="<?php echo h($searchText); ?>">
                <button class="btn btn-outline-primary" type="submit">Search</button>
            </div>
        </form>

        <datalist id="hpSearchList">
            <?php foreach ($autocompleteGlobal as $item): ?>
                <option value="<?php echo h($item); ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <datalist id="hpBorrowerList">
            <?php foreach ($autocompleteBorrower as $item): ?>
                <option value="<?php echo h($item); ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <datalist id="hpCandidateList">
            <?php foreach ($autocompleteCandidate as $item): ?>
                <option value="<?php echo h($item); ?>"></option>
            <?php endforeach; ?>
        </datalist>
    </div>
</section>
<section class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white fw-semibold">Customers Without Contracts</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 js-admin-datatable">
            <thead class="table-light">
            <tr>
                <th>ID</th>
                <th>Customer Code</th>
                <th>Customer Name</th>
                <th>Application No.</th>
                <th>Branch</th>
                <th>Recommended Amount</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($candidateRows as $candidate): ?>
                <?php
                $candidateUid = (string)($candidate['record_uid'] ?? '');
                $candidateName = trim((string)($candidate['customer_name'] ?? ''));
                $candidateOpenUrl = app_base_url('modules/03_hire_purchase.php?' . http_build_query([
                    'open_create' => 1,
                    'candidate_uid' => $candidateUid,
                    'branch_code' => $selectedBranchCode,
                    'borrower_name' => $borrowerNameText,
                    'candidate_search' => $candidateSearchText,
                    'search' => $searchText,
                ]));
                ?>
                <tr>
                    <td><?php echo (int)($candidate['id'] ?? 0); ?></td>
                    <td><?php echo h((string)($candidate['customer_code'] ?? '-')); ?></td>
                    <td><?php echo h($candidateName !== '' ? $candidateName : '-'); ?></td>
                    <td><?php echo h((string)($candidate['application_no'] ?? '-')); ?></td>
                    <td><?php echo h((string)($candidate['branch_code'] ?? '-')); ?></td>
                    <td><?php echo number_format((float)($candidate['recommended_loan_amount'] ?? 0), 2); ?></td>
                    <td>
                        <a class="btn btn-sm btn-brand" href="<?php echo h($candidateOpenUrl); ?>">Create Contract</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($candidateRows === []): ?>
        <div class="px-3 py-2 text-muted small">No customers waiting for contract creation.</div>
    <?php endif; ?>
</section>
<section class="row g-3 mb-4">
    <div class="col-xl-4 col-md-6"><div class="stat-card"><span>Total Contracts</span><strong><?php echo number_format((int)$totals['contract_count']); ?></strong></div></div>
    <div class="col-xl-4 col-md-6"><div class="stat-card"><span>Contracts in ACTIVE Status</span><strong><?php echo number_format((int)$totals['active_count']); ?></strong></div></div>
    <div class="col-xl-4 col-md-12"><div class="stat-card"><span>Total Principal (THB)</span><strong><?php echo number_format((float)$totals['principal_total'], 2); ?></strong></div></div>
</section>

<section class="card shadow-sm border-0">
    <div class="card-header bg-white fw-semibold">Borrowers and Contracts</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 js-admin-datatable">
            <thead class="table-light">
            <tr>
                <th>ID</th>
                <th>Contract No.</th>
                <th>Customer Code</th>
                <th>Customer Name</th>
                <th>Branch</th>
                <th>Approved Amount</th>
                <th>Policy</th>
                <th>Annual Interest</th>
                <th>Term</th>
                <th>Installment/Month</th>
                <th>Contract File</th>
                <th>Status</th>
                <th>Last Updated</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($contractRows as $row): ?>
                <?php
                $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
                $customerName = trim((string)($payload['customer_name'] ?? ''));
                $status = strtoupper(trim((string)($payload['current_status'] ?? 'ACTIVE')));
                $contractPdfPath = trim((string)($payload['loan_contract_pdf_path'] ?? ''));
                $contractPdfName = trim((string)($payload['loan_contract_pdf_name'] ?? ''));
                $policyCode = trim((string)($payload['policy_code'] ?? ''));
                $policyName = trim((string)($payload['policy_name'] ?? ''));
                ?>
                <tr>
                    <td><?php echo (int)$row['id']; ?></td>
                    <td><code><?php echo h((string)$row['contract_no']); ?></code></td>
                    <td><?php echo h((string)$row['customer_code']); ?></td>
                    <td><?php echo h($customerName !== '' ? $customerName : '-'); ?></td>
                    <td><?php echo h((string)$row['branch_code']); ?></td>
                    <td><?php echo number_format((float)($row['principal_amount'] ?? 0), 2); ?></td>
                    <td>
                        <?php if ($policyCode !== ''): ?>
                            <button
                                type="button"
                                class="btn btn-link p-0 text-start policy-detail-trigger"
                                data-policy-code="<?php echo h($policyCode); ?>"
                                data-policy-name="<?php echo h($policyName); ?>"
                            >
                                <?php echo h($policyCode); ?><?php echo $policyName !== '' ? (' - ' . h($policyName)) : ''; ?>
                            </button>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo number_format((float)($payload['annual_rate_pct'] ?? 0), 2); ?>%</td>
                    <td><?php echo number_format((int)($payload['term_months'] ?? 0)); ?></td>
                    <td><?php echo number_format((float)($payload['monthly_installment'] ?? 0), 2); ?></td>
                    <td>
                        <?php if ($contractPdfPath !== ''): ?>
                            <a href="<?php echo h(app_base_url($contractPdfPath)); ?>" target="_blank" rel="noopener">
                                <?php echo h($contractPdfName !== '' ? $contractPdfName : 'Open PDF'); ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge text-bg-<?php echo $status === 'CLOSED' ? 'secondary' : ($status === 'NPL' ? 'danger' : 'success'); ?>"><?php echo h($status); ?></span></td>
                    <td><?php echo h((string)($row['updated_at'] ?: $row['created_at'])); ?></td>
                    <td>
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo h(app_base_url('modules/04_installments.php?contract_no=' . rawurlencode((string)$row['contract_no']))); ?>">Installment Plan</a>
                        <form method="post" class="d-inline needs-confirm-delete">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="hp_action" value="delete_contract">
                            <input type="hidden" name="contract_no" value="<?php echo h((string)$row['contract_no']); ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="modal fade" id="createContractModal" tabindex="-1" aria-labelledby="createContractModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable sf-resizable-modal hp-create-contract-dialog">
        <div class="modal-content border-0 shadow">
            <form method="post" enctype="multipart/form-data" class="validate-form" novalidate>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="hp_action" value="create_contract">
                <input type="hidden" name="candidate_search" value="<?php echo h($candidateSearchText); ?>">
                <input type="hidden" name="borrower_name" value="<?php echo h($borrowerNameText); ?>">
                <input type="hidden" name="branch_code" value="<?php echo h($selectedBranchCode); ?>">
                <div class="modal-header">
                    <h2 class="h6 mb-0" id="createContractModalLabel">Add Borrower and Contract</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Search Eligible Customers (name/surname/code/branch)</label>
                            <input class="form-control" type="text" id="hpCandidateQuickSearch" list="hpCandidateList" placeholder="Type to filter options in the customer selector">
                            <div class="form-text">Search by customer code, full name, application number, or branch.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Select Eligible Customer *</label>
                            <select class="form-select" name="candidate_uid" id="hpCandidateUid" required>
                                <option value="">-- Select Customer --</option>
                                <?php foreach ($candidateRows as $candidate): ?>
                                    <?php
                                    $label = trim((string)$candidate['customer_code']) . ' | '
                                        . trim((string)$candidate['customer_name']) . ' | '
                                        . trim((string)$candidate['application_no']) . ' | '
                                        . trim((string)$candidate['branch_code']);
                                    ?>
                                    <option
                                        value="<?php echo h((string)$candidate['record_uid']); ?>"
                                        <?php echo $requestedCandidateUid !== '' && $requestedCandidateUid === (string)$candidate['record_uid'] ? 'selected' : ''; ?>
                                        data-customer-code="<?php echo h((string)$candidate['customer_code']); ?>"
                                        data-customer-name="<?php echo h((string)$candidate['customer_name']); ?>"
                                        data-application-no="<?php echo h((string)$candidate['application_no']); ?>"
                                        data-branch-code="<?php echo h((string)$candidate['branch_code']); ?>"
                                        data-policy-code="<?php echo h((string)$candidate['policy_code']); ?>"
                                        data-policy-name="<?php echo h((string)$candidate['policy_name']); ?>"
                                        data-loan="<?php echo h((string)$candidate['recommended_loan_amount']); ?>"
                                        data-rate="<?php echo h((string)$candidate['annual_rate_pct']); ?>"
                                        data-term="<?php echo h((string)$candidate['term_months']); ?>"
                                    >
                                        <?php echo h($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Customer Code</label>
                            <input class="form-control" type="text" id="hpCustomerCode" readonly>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Customer Name</label>
                            <input class="form-control" type="text" id="hpCustomerName" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Application Reference No.</label>
                            <input class="form-control" type="text" id="hpApplicationNo" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Branch</label>
                            <input class="form-control" type="text" id="hpBranchCode" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Policy Code</label>
                            <input class="form-control" type="text" id="hpPolicyCode" readonly>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Policy Name</label>
                            <input class="form-control" type="text" id="hpPolicyName" readonly>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button class="btn btn-outline-primary w-100" type="button" id="hpViewPolicyBtn" disabled>
                                View Policy Details
                            </button>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Approved Loan Amount *</label>
                            <input class="form-control" type="number" step="0.01" min="1" name="approved_loan_amount" id="hpLoanAmount" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Annual Interest Rate (%) *</label>
                            <input class="form-control" type="number" step="0.01" min="0" name="annual_rate_pct" id="hpAnnualRate" required readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Installments (Months) *</label>
                            <input class="form-control" type="number" min="1" max="120" name="term_months" id="hpTermMonths" required readonly>
                            <div class="form-text">Auto-populated from the policy passed in Module 2.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">First Due Date *</label>
                            <input class="form-control" type="date" name="first_due_date" value="<?php echo h(date('Y-m-d', strtotime('+1 month'))); ?>" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Loan Contract Document (PDF) *</label>
                            <input class="form-control" type="file" name="loan_contract_pdf" accept=".pdf,application/pdf" required>
                            <div class="form-text">One PDF file is required for each contract.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-brand" type="submit">Save Contract</button>
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="policyDetailModal" tabindex="-1" aria-labelledby="policyDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable sf-resizable-modal hp-policy-detail-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h2 class="h6 mb-0" id="policyDetailModalLabel">Policy Details</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2"><strong>Code:</strong> <span id="policyDetailCode">-</span></div>
                <div class="mb-3"><strong>Name:</strong> <span id="policyDetailName">-</span></div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th style="width: 40%;">Field</th>
                            <th>Value</th>
                        </tr>
                        </thead>
                        <tbody id="policyDetailRows">
                        <tr><td colspan="2" class="text-muted">No details found</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var policyCatalog = <?php echo json_encode($policyCatalogForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); ?> || {};
    var candidatePolicyMap = <?php echo json_encode($candidatePolicyForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); ?> || {};

    var select = document.getElementById('hpCandidateUid');
    var quickSearch = document.getElementById('hpCandidateQuickSearch');
    var viewPolicyBtn = document.getElementById('hpViewPolicyBtn');
    var policyModalEl = document.getElementById('policyDetailModal');
    var policyModal = null;
    var detailCode = document.getElementById('policyDetailCode');
    var detailName = document.getElementById('policyDetailName');
    var detailRows = document.getElementById('policyDetailRows');
    if (!select) {
        return;
    }

    var setValue = function (id, value) {
        var el = document.getElementById(id);
        if (!el) {
            return;
        }
        el.value = value || '';
    };

    var getPolicyModal = function () {
        if (!policyModalEl || !window.bootstrap) {
            return null;
        }
        if (!policyModal) {
            policyModal = window.bootstrap.Modal.getOrCreateInstance(policyModalEl);
        }
        return policyModal;
    };

    var formatNumber = function (value, digits) {
        var n = Number(value);
        if (!Number.isFinite(n)) {
            return '-';
        }
        return n.toLocaleString(undefined, { minimumFractionDigits: digits, maximumFractionDigits: digits });
    };

    var policyFieldLabels = {
        customer_job_ref: 'Reference Occupation',
        income_band_ref: 'Reference Income Band',
        collateral_type_ref: 'Reference Collateral Type',
        max_dsr_pct: 'Maximum DSR (%)',
        pd_target_pct: 'Maximum PD Target (%)',
        policy_interest_rate_pct: 'Reference Annual Interest Rate (%)',
        max_tenor_month: 'Maximum Tenor (Months)',
        recommended_loan_amount: 'Recommended Loan Amount (THB)',
        recommended_installment: 'Recommended Installment (THB/Month)'
    };

    var buildPolicyRows = function (payload) {
        if (!detailRows) {
            return;
        }
        var rowsHtml = '';
        var keys = Object.keys(policyFieldLabels);
        for (var i = 0; i < keys.length; i++) {
            var k = keys[i];
            if (!Object.prototype.hasOwnProperty.call(payload, k)) {
                continue;
            }
            var raw = payload[k];
            var text = '-';
            if (raw !== null && raw !== undefined && String(raw).trim() !== '') {
                if (k === 'max_dsr_pct' || k === 'pd_target_pct' || k === 'policy_interest_rate_pct') {
                    text = formatNumber(raw, 2);
                } else if (k === 'recommended_loan_amount' || k === 'recommended_installment') {
                    text = formatNumber(raw, 2);
                } else if (k === 'max_tenor_month') {
                    text = formatNumber(raw, 0);
                } else {
                    text = String(raw);
                }
            }
            rowsHtml += '<tr><td>' + policyFieldLabels[k] + '</td><td>' + text + '</td></tr>';
        }

        if (rowsHtml === '') {
            rowsHtml = '<tr><td colspan="2" class="text-muted">No details found</td></tr>';
        }
        detailRows.innerHTML = rowsHtml;
    };

    var openPolicyModal = function (policyCode, policyName, payload) {
        var modal = getPolicyModal();
        if (!modal || !detailCode || !detailName) {
            return;
        }
        detailCode.textContent = policyCode || '-';
        detailName.textContent = policyName || '-';
        buildPolicyRows(payload || {});
        modal.show();
    };

    var syncPolicyPreviewForSelection = function (opt) {
        if (!opt || !opt.value) {
            setValue('hpPolicyCode', '');
            setValue('hpPolicyName', '');
            if (viewPolicyBtn) {
                viewPolicyBtn.disabled = true;
                viewPolicyBtn.setAttribute('data-record-uid', '');
            }
            return;
        }
        var recordUid = opt.value;
        var policyCode = (opt.getAttribute('data-policy-code') || '').trim();
        var policyName = (opt.getAttribute('data-policy-name') || '').trim();
        var info = candidatePolicyMap[recordUid] || null;
        if (info) {
            if (!policyCode) {
                policyCode = String(info.policy_code || '').trim();
            }
            if (!policyName) {
                policyName = String(info.policy_name || '').trim();
            }
        }

        setValue('hpPolicyCode', policyCode);
        setValue('hpPolicyName', policyName);

        if (viewPolicyBtn) {
            viewPolicyBtn.setAttribute('data-record-uid', recordUid);
            viewPolicyBtn.disabled = !policyCode;
        }
    };

    var applyOption = function () {
        var opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) {
            setValue('hpCustomerCode', '');
            setValue('hpCustomerName', '');
            setValue('hpApplicationNo', '');
            setValue('hpBranchCode', '');
            syncPolicyPreviewForSelection(null);
            return;
        }
        setValue('hpCustomerCode', opt.getAttribute('data-customer-code') || '');
        setValue('hpCustomerName', opt.getAttribute('data-customer-name') || '');
        setValue('hpApplicationNo', opt.getAttribute('data-application-no') || '');
        setValue('hpBranchCode', opt.getAttribute('data-branch-code') || '');
        setValue('hpLoanAmount', opt.getAttribute('data-loan') || '');
        setValue('hpAnnualRate', opt.getAttribute('data-rate') || '');
        setValue('hpTermMonths', opt.getAttribute('data-term') || '');
        syncPolicyPreviewForSelection(opt);
    };

    var norm = function (text) {
        return String(text || '').toLowerCase();
    };

    var filterOptions = function () {
        if (!quickSearch) {
            return;
        }
        var query = norm(quickSearch.value);
        var visibleCount = 0;

        for (var i = 0; i < select.options.length; i++) {
            var opt = select.options[i];
            if (!opt.value) {
                opt.hidden = false;
                continue;
            }
            var key = [
                opt.getAttribute('data-customer-code') || '',
                opt.getAttribute('data-customer-name') || '',
                opt.getAttribute('data-application-no') || '',
                opt.getAttribute('data-branch-code') || '',
                opt.text || ''
            ].join(' ');

            var isMatch = (query === '' || norm(key).indexOf(query) !== -1);
            opt.hidden = !isMatch;
            if (isMatch) {
                visibleCount++;
            }
        }

        if (visibleCount === 0) {
            select.value = '';
            applyOption();
            return;
        }

        if (select.selectedOptions.length > 0 && select.selectedOptions[0].hidden) {
            select.value = '';
            applyOption();
        }
    };

    select.addEventListener('change', applyOption);
    if (quickSearch) {
        quickSearch.addEventListener('input', filterOptions);
    }
    if (viewPolicyBtn) {
        viewPolicyBtn.addEventListener('click', function () {
            var recordUid = String(viewPolicyBtn.getAttribute('data-record-uid') || '');
            if (!recordUid) {
                return;
            }
            var info = candidatePolicyMap[recordUid] || {};
            var policyCode = String(info.policy_code || document.getElementById('hpPolicyCode').value || '').trim();
            var policyName = String(info.policy_name || document.getElementById('hpPolicyName').value || '').trim();
            var payload = (info.payload && typeof info.payload === 'object') ? info.payload : {};
            if (Object.keys(payload).length === 0 && policyCode) {
                var key = policyCode.toUpperCase();
                if (policyCatalog[key] && policyCatalog[key].payload) {
                    payload = policyCatalog[key].payload;
                    if (!policyName) {
                        policyName = String(policyCatalog[key].policy_name || '');
                    }
                }
            }
            openPolicyModal(policyCode, policyName, payload);
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target) {
            return;
        }
        var trigger = target.closest('.policy-detail-trigger');
        if (!trigger) {
            return;
        }
        var policyCode = String(trigger.getAttribute('data-policy-code') || '').trim();
        var policyName = String(trigger.getAttribute('data-policy-name') || '').trim();
        if (!policyCode) {
            return;
        }
        var key = policyCode.toUpperCase();
        var payload = {};
        if (policyCatalog[key] && policyCatalog[key].payload) {
            payload = policyCatalog[key].payload;
            if (!policyName) {
                policyName = String(policyCatalog[key].policy_name || '');
            }
        }
        openPolicyModal(policyCode, policyName, payload);
    });

    applyOption();
})();
</script>

<?php if ($openCreateModal): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
    if (!window.bootstrap) {
        return;
    }
    var modalEl = document.getElementById('createContractModal');
    if (!modalEl) {
        return;
    }
    window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
});
</script>
<?php endif; ?>

<script>
window.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('createContractModal');
    if (!modalEl) {
        return;
    }

    var enforceCreateModalScroll = function () {
        var dialog = modalEl.querySelector('.modal-dialog');
        var content = modalEl.querySelector('.modal-content');
        var header = modalEl.querySelector('.modal-header');
        var footer = modalEl.querySelector('.modal-footer');
        var body = modalEl.querySelector('.modal-body');
        if (!dialog || !content || !body) {
            return;
        }

        var viewport = window.innerHeight || document.documentElement.clientHeight || 800;
        var frameGap = 16; // top+bottom breathing space
        var headerHeight = header ? header.offsetHeight : 0;
        var footerHeight = footer ? footer.offsetHeight : 0;
        var bodyMax = Math.max(180, viewport - frameGap - headerHeight - footerHeight - 24);

        dialog.style.maxHeight = 'calc(100dvh - 0.75rem)';
        content.style.maxHeight = 'calc(100dvh - 0.75rem)';
        content.style.display = 'flex';
        content.style.flexDirection = 'column';
        content.style.overflow = 'hidden';

        body.style.minHeight = '0';
        body.style.flex = '1 1 auto';
        body.style.maxHeight = bodyMax + 'px';
        body.style.overflowY = 'auto';
        body.style.overflowX = 'hidden';
    };

    modalEl.addEventListener('shown.bs.modal', enforceCreateModalScroll);
    window.addEventListener('resize', enforceCreateModalScroll);

    if (modalEl.classList.contains('show')) {
        enforceCreateModalScroll();
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>


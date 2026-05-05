<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/hire_purchase_core.php';

$moduleKey = 'installments';
$module = module_by_key($moduleKey);
if ($module === null) {
    http_response_code(500);
    echo 'Module configuration not found';
    exit;
}

/** @param array<string,mixed> $row */
function hp_installment_quote(array $row, string $asOf, float $annualRatePct): array
{
    $due = round(max(0.0, hp_float($row['installment_due'] ?? 0)), 2);
    $paid = round(max(0.0, hp_float($row['paid_amount'] ?? 0)), 2);
    $remain = round(max(0.0, $due - $paid), 2);
    $status = strtoupper(trim((string)($row['payment_status'] ?? 'UNPAID')));

    $days = 0;
    $dueDate = trim((string)($row['due_date'] ?? ''));
    if ($status !== 'PAID' && $remain > 0 && $dueDate !== '') {
        try {
            $d1 = new DateTimeImmutable(hp_parse_date($dueDate, $asOf));
            $d2 = new DateTimeImmutable(hp_parse_date($asOf, $asOf));
            $diff = (int)$d1->diff($d2)->format('%r%a');
            if ($diff > 0) {
                $days = $diff;
            }
        } catch (Throwable $e) {
            $days = 0;
        }
    }

    $principal = round(max(0.0, hp_float($row['principal'] ?? $remain)), 2);
    $lateRate = max(0.0, $annualRatePct + 3.0) / 100.0;
    $late = ($days > 0 && $principal > 0) ? round($principal * $lateRate * ($days / 365), 2) : 0.0;
    $follow = ($days > 0 && $remain > 0) ? 100.0 : 0.0;

    return [
        'due' => $due,
        'paid' => $paid,
        'remain' => $remain,
        'days' => $days,
        'late' => $late,
        'follow' => $follow,
        'total' => round($remain + $late + $follow, 2),
    ];
}

function hp_channel_label(string $value): string
{
    return match (strtoupper(trim($value))) {
        'CASH' => 'Cash',
        'BANK_TRANSFER' => 'Bank Transfer',
        'QR' => 'QR',
        'AUTO_DEBIT' => 'Auto Debit',
        default => $value !== '' ? $value : '-',
    };
}

/** @param array<string,mixed> $tx */
function hp_render_receipt(array $tx, string $contractNo, string $customerCode, string $customerName): void
{
    ?>
    <section class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <strong>Payment Receipt</strong>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.print()">Print Receipt</button>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3"><div class="text-muted small">Receipt No.</div><div class="fw-semibold"><?php echo h((string)($tx['receipt_no'] ?? '-')); ?></div></div>
                <div class="col-md-3"><div class="text-muted small">Contract No.</div><div class="fw-semibold"><?php echo h($contractNo); ?></div></div>
                <div class="col-md-3"><div class="text-muted small">Customer Code</div><div class="fw-semibold"><?php echo h($customerCode); ?></div></div>
                <div class="col-md-3"><div class="text-muted small">Customer Name</div><div class="fw-semibold"><?php echo h($customerName); ?></div></div>
                <div class="col-md-3"><div class="text-muted small">Installment No.</div><div><?php echo h((string)($tx['installment_no'] ?? '-')); ?></div></div>
                <div class="col-md-3"><div class="text-muted small">Payment Date</div><div><?php echo h((string)($tx['payment_date'] ?? '-')); ?></div></div>
                <div class="col-md-3"><div class="text-muted small">Channel</div><div><?php echo h(hp_channel_label((string)($tx['payment_channel'] ?? ''))); ?></div></div>
                <div class="col-md-3"><div class="text-muted small">Total Paid</div><div class="fw-semibold text-primary"><?php echo number_format((float)($tx['total_paid'] ?? 0), 2); ?></div></div>
            </div>
        </div>
    </section>
    <?php
}

$scope = current_access_scope();
$codes = accessible_branch_codes($scope);
$branchRows = [];
foreach (active_branch_rows() as $b) {
    $bc = strtoupper(trim((string)($b['branch_code'] ?? '')));
    if ($scope['scope'] !== 'all' && !in_array($bc, $codes, true)) {
        continue;
    }
    $branchRows[] = $b;
}

$selectedBranch = strtoupper(trim((string)($_GET['branch_code'] ?? '')));
if ($selectedBranch !== '' && !is_branch_in_current_scope($selectedBranch, $scope)) {
    $selectedBranch = '';
}
$searchText = trim((string)($_GET['q'] ?? ''));
$selectedContractNo = strtoupper(trim((string)($_GET['contract_no'] ?? '')));
$openScheduleModal = ((string)($_GET['open_schedule'] ?? '0')) === '1';
$printReceiptNo = trim((string)($_GET['print_receipt_no'] ?? ''));
$printAuto = ((string)($_GET['auto'] ?? '0')) === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));
        $formAction = trim((string)($_POST['form_action'] ?? ''));
        if (!in_array($formAction, ['pay_installment', 'mark_no_pay'], true)) {
            throw new RuntimeException('Invalid action');
        }
        $selectedBranch = strtoupper(trim((string)($_POST['branch_code'] ?? $selectedBranch)));
        if ($selectedBranch !== '' && !is_branch_in_current_scope($selectedBranch, $scope)) {
            throw new RuntimeException('You do not have permission for this branch');
        }
        $searchText = trim((string)($_POST['q'] ?? $searchText));
        $selectedContractNo = strtoupper(trim((string)($_POST['contract_no'] ?? '')));
        $installmentNo = max(1, hp_int($_POST['installment_no'] ?? 0));
        if ($selectedContractNo === '' || $installmentNo <= 0) {
            throw new RuntimeException('Incomplete payment data');
        }

        $contract = hp_find_contract_latest($selectedContractNo, $scope);
        if (!$contract) {
            throw new RuntimeException('Contract not found');
        }
        assert_branch_in_current_scope((string)($contract['branch_code'] ?? ''));

        $paymentDate = hp_parse_date((string)($_POST['payment_date'] ?? date('Y-m-d')), date('Y-m-d'));
        $paymentChannel = strtoupper(trim((string)($_POST['payment_channel'] ?? 'CASH')));
        $paymentRef = trim((string)($_POST['payment_ref'] ?? ''));
        $paymentNote = trim((string)($_POST['payment_note'] ?? ''));
        $paidInput = round(max(0.0, hp_float($_POST['paid_amount'] ?? 0)), 2);
        $latePaymentMode = ((string)($_POST['late_payment_mode'] ?? '0')) === '1';
        $paymentEventType = strtoupper(trim((string)($_POST['payment_event_type'] ?? 'PAY')));
        if ($latePaymentMode) {
            $paymentEventType = 'LATE_PAY';
        } elseif ($paymentEventType !== 'PAY') {
            $paymentEventType = 'PAY';
        }

        $payload = is_array($contract['payload'] ?? null) ? $contract['payload'] : [];
        $annualRatePct = round(max(0.0, hp_float($payload['annual_rate_pct'] ?? 0)), 4);
        $history = $payload['payment_history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }

        $targetIndex = -1;
        $targetRow = null;
        foreach (array_values($history) as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int)($row['installment_no'] ?? 0) === $installmentNo) {
                $targetIndex = (int)$idx;
                $targetRow = $row;
                break;
            }
        }
        if ($targetIndex < 0 || !is_array($targetRow)) {
            throw new RuntimeException('Selected installment not found');
        }
        if (strtoupper(trim((string)($targetRow['payment_status'] ?? 'UNPAID'))) === 'PAID') {
            throw new RuntimeException('This installment has already been paid');
        }

        if ($formAction === 'mark_no_pay') {
            $noPayDate = hp_parse_date((string)($_POST['no_pay_date'] ?? date('Y-m-d')), date('Y-m-d'));
            $noPayReason = trim((string)($_POST['no_pay_reason'] ?? ''));
            $quote = hp_installment_quote($targetRow, $noPayDate, $annualRatePct);
            $actor = current_user_name();

            $history = array_values(array_map(static fn($r) => is_array($r) ? $r : [], $history));
            $history[$targetIndex]['payment_status'] = 'NO_PAY';
            $history[$targetIndex]['paid_amount'] = 0.0;
            $history[$targetIndex]['paid_date'] = '';
            $history[$targetIndex]['no_pay_date'] = $noPayDate;
            $history[$targetIndex]['no_pay_reason'] = $noPayReason;
            $history[$targetIndex]['no_pay_by'] = $actor;
            $history[$targetIndex]['receipt_no'] = '';
            $history[$targetIndex]['payment_channel'] = '';
            $history[$targetIndex]['payment_ref'] = '';
            $history[$targetIndex]['payment_attachment'] = '';
            $history[$targetIndex]['payment_attachment_name'] = '';
            $history[$targetIndex]['late_penalty'] = (float)$quote['late'];
            $history[$targetIndex]['collection_fee'] = (float)$quote['follow'];
            $history[$targetIndex]['days_overdue'] = (int)$quote['days'];

            $tx = $payload['payment_transactions'] ?? [];
            if (!is_array($tx)) {
                $tx = [];
            }
            $tx[] = [
                'receipt_no' => '',
                'event_type' => 'NO_PAY',
                'installment_no' => $installmentNo,
                'payment_date' => $noPayDate,
                'payment_channel' => '',
                'payment_ref' => '',
                'payment_note' => $noPayReason,
                'installment_due' => (float)$quote['due'],
                'late_penalty' => (float)$quote['late'],
                'collection_fee' => (float)$quote['follow'],
                'total_paid' => 0.0,
                'created_by' => $actor,
                'created_at' => now_dt(),
            ];

            $status = hp_contract_status_from_history($history, $noPayDate);
            $payload['payment_history'] = $history;
            $payload['payment_transactions'] = array_slice($tx, -500);
            $payload['current_status'] = (string)($status['current_status'] ?? 'ACTIVE');
            $payload['contract_status'] = $payload['current_status'];
            $payload['dpd_bucket'] = (string)($status['dpd_bucket'] ?? 'CURRENT');
            $payload['max_overdue_days'] = (int)($status['max_days'] ?? 0);

            hp_update_contract_payload($contract, $payload, $actor);
            add_flash('success', 'Marked installment as NO_PAY successfully');
            redirect_to(app_base_url('modules/04_installments.php?branch_code=' . rawurlencode($selectedBranch) . '&q=' . rawurlencode($searchText) . '&contract_no=' . rawurlencode($selectedContractNo) . '&open_schedule=1'));
        }

        $quote = hp_installment_quote($targetRow, $paymentDate, $annualRatePct);
        if ((float)$quote['total'] <= 0) {
            throw new RuntimeException('No outstanding amount found for this installment');
        }
        if ($paidInput < (float)$quote['total']) {
            throw new RuntimeException('Paid amount must be at least ' . number_format((float)$quote['total'], 2));
        }

        if ($latePaymentMode && $paymentNote === '') {
            $paymentNote = 'LATE_PAY';
        }

        $upload = hp_upload_file('payment_attachment', ['pdf', 'jpg', 'jpeg', 'png', 'webp'], 12 * 1024 * 1024, 'installments');
        $receiptNo = hp_generate_code('RCP');
        $actor = current_user_name();

        $history = array_values(array_map(static fn($r) => is_array($r) ? $r : [], $history));
        $history[$targetIndex]['paid_amount'] = round(max(0.0, hp_float($targetRow['installment_due'] ?? 0)), 2);
        $history[$targetIndex]['paid_date'] = $paymentDate;
        $history[$targetIndex]['payment_status'] = 'PAID';
        $history[$targetIndex]['receipt_no'] = $receiptNo;
        $history[$targetIndex]['payment_channel'] = $paymentChannel;
        $history[$targetIndex]['payment_ref'] = $paymentRef;
        $history[$targetIndex]['payment_note'] = $paymentNote;
        $history[$targetIndex]['payment_event_type'] = $paymentEventType;
        $history[$targetIndex]['payment_attachment'] = (string)($upload['path'] ?? '');
        $history[$targetIndex]['payment_attachment_name'] = (string)($upload['name'] ?? '');
        $history[$targetIndex]['late_penalty'] = (float)$quote['late'];
        $history[$targetIndex]['collection_fee'] = (float)$quote['follow'];
        $history[$targetIndex]['days_overdue'] = (int)$quote['days'];

        $tx = $payload['payment_transactions'] ?? [];
        if (!is_array($tx)) {
            $tx = [];
        }
        $tx[] = [
            'event_type' => $paymentEventType,
            'receipt_no' => $receiptNo,
            'installment_no' => $installmentNo,
            'payment_date' => $paymentDate,
            'payment_channel' => $paymentChannel,
            'payment_ref' => $paymentRef,
            'payment_note' => $paymentNote,
            'installment_due' => (float)$quote['due'],
            'late_penalty' => (float)$quote['late'],
            'collection_fee' => (float)$quote['follow'],
            'total_paid' => (float)$quote['total'],
            'attachment_path' => (string)($upload['path'] ?? ''),
            'attachment_name' => (string)($upload['name'] ?? ''),
            'created_by' => $actor,
            'created_at' => now_dt(),
        ];

        $status = hp_contract_status_from_history($history, $paymentDate);
        $payload['payment_history'] = $history;
        $payload['payment_transactions'] = array_slice($tx, -500);
        $payload['current_status'] = (string)($status['current_status'] ?? 'ACTIVE');
        $payload['contract_status'] = $payload['current_status'];
        $payload['dpd_bucket'] = (string)($status['dpd_bucket'] ?? 'CURRENT');
        $payload['max_overdue_days'] = (int)($status['max_days'] ?? 0);

        hp_update_contract_payload($contract, $payload, $actor);
        add_flash('success', 'Payment received successfully. Receipt No.: ' . $receiptNo);

        $url = app_base_url('modules/04_installments.php?branch_code=' . rawurlencode($selectedBranch) . '&q=' . rawurlencode($searchText) . '&contract_no=' . rawurlencode($selectedContractNo) . '&open_schedule=1');
        if (((string)($_POST['print_receipt'] ?? '0')) === '1') {
            $url .= '&print_receipt_no=' . rawurlencode($receiptNo) . '&auto=1';
        }
        redirect_to($url);
    } catch (Throwable $e) {
        add_flash('danger', 'Failed to save payment: ' . $e->getMessage());
        redirect_to(app_base_url('modules/04_installments.php?branch_code=' . rawurlencode($selectedBranch) . '&q=' . rawurlencode($searchText) . '&contract_no=' . rawurlencode($selectedContractNo) . '&open_schedule=1'));
    }
}

$hasSearch = ($searchText !== '' || $selectedBranch !== '' || $selectedContractNo !== '');
$listRows = $hasSearch ? hp_fetch_contract_rows($scope, $searchText, $selectedBranch, '') : [];

$selectedContract = null;
if ($selectedContractNo !== '') {
    $selectedContract = hp_find_contract_latest($selectedContractNo, $scope);
}
if (!$selectedContract && $hasSearch && count($listRows) === 1) {
    $selectedContract = $listRows[0];
    $selectedContractNo = strtoupper(trim((string)($selectedContract['contract_no'] ?? '')));
}

$customerName = '';
$customerCode = '';
$annualRatePct = 0.0;
$scheduleRows = [];
$summary = ['all' => 0, 'paid' => 0, 'unpaid' => 0, 'remain' => 0.0, 'late' => 0.0, 'follow' => 0.0];
$receiptForPrint = null;
if ($selectedContract) {
    $payload = is_array($selectedContract['payload'] ?? null) ? $selectedContract['payload'] : [];
    $customerName = trim((string)($payload['customer_name'] ?? ''));
    $customerCode = trim((string)($selectedContract['customer_code'] ?? ''));
    $annualRatePct = round(max(0.0, hp_float($payload['annual_rate_pct'] ?? 0)), 4);
    $history = $payload['payment_history'] ?? [];
    if (is_array($history)) {
        foreach ($history as $row) {
            if (!is_array($row)) {
                continue;
            }
            $quote = hp_installment_quote($row, date('Y-m-d'), $annualRatePct);
            $status = strtoupper(trim((string)($row['payment_status'] ?? 'UNPAID')));
            $scheduleRows[] = ['row' => $row, 'quote' => $quote, 'status' => $status];
            $summary['all']++;
            $summary['remain'] += (float)$quote['remain'];
            $summary['late'] += (float)$quote['late'];
            $summary['follow'] += (float)$quote['follow'];
            if ($status === 'PAID') {
                $summary['paid']++;
            } else {
                $summary['unpaid']++;
            }
        }
    }
    if ($printReceiptNo !== '') {
        $txRows = $payload['payment_transactions'] ?? [];
        if (is_array($txRows)) {
            foreach (array_reverse($txRows) as $txRow) {
                if (!is_array($txRow)) {
                    continue;
                }
                if (trim((string)($txRow['receipt_no'] ?? '')) === $printReceiptNo) {
                    $receiptForPrint = $txRow;
                    break;
                }
            }
        }
    }
}

$acRows = hp_fetch_contract_rows($scope, '', $selectedBranch, '');
$ac = [];
foreach ($acRows as $r) {
    $p = is_array($r['payload'] ?? null) ? $r['payload'] : [];
    foreach ([trim((string)($r['contract_no'] ?? '')), trim((string)($r['customer_code'] ?? '')), trim((string)($p['customer_name'] ?? ''))] as $token) {
        if ($token !== '') {
            $ac[$token] = true;
        }
    }
}
$acList = array_keys($ac);
sort($acList, SORT_NATURAL | SORT_FLAG_CASE);

$pageTitle = (string)($module['title'] ?? 'Installments');
$currentModule = $moduleKey;
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/menu.php';
?>
<section class="card shadow-sm border-0 mb-4 module-hero">
    <div class="card-body">
        <h1 class="h4 mb-2">Installments Management</h1>
        <p class="mb-0 text-muted">Start by selecting a branch and searching contract number, borrower name, or customer code, then open the schedule in a popup.</p>
    </div>
</section>

<?php if ($receiptForPrint !== null && $selectedContractNo !== '') { hp_render_receipt($receiptForPrint, $selectedContractNo, $customerCode, $customerName);} ?>

<section class="card shadow-sm border-0 mb-4 module-toolbar">
    <div class="card-body">
        <form class="row g-2 align-items-end module-search" method="get" action="<?php echo h(app_base_url('modules/04_installments.php')); ?>">
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
                <label class="form-label">Search Contract No. / Customer Code / Full Name</label>
                <input class="form-control" list="installmentSearchList" name="q" value="<?php echo h($searchText); ?>" placeholder="Type keyword and choose from autocomplete suggestions" autocomplete="off">
                <datalist id="installmentSearchList">
                    <?php foreach ($acList as $item): ?><option value="<?php echo h((string)$item); ?>"></option><?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-lg-3 col-md-12 d-flex gap-2">
                <button class="btn btn-brand flex-grow-1" type="submit">Search</button>
                <a class="btn btn-outline-secondary" href="<?php echo h(app_base_url('modules/04_installments.php')); ?>">Reset</a>
            </div>
        </form>
    </div>
</section>

<section class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-light d-flex justify-content-between align-items-center"><strong>Contracts Found</strong><span class="text-muted small"><?php echo number_format(count($listRows)); ?> records</span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 js-admin-datatable" id="installmentContractTable">
            <thead><tr><th>ID</th><th>Contract No.</th><th>Customer Code</th><th>Customer Name</th><th>Branch</th><th>Status</th><th>Last Updated</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$hasSearch): ?>
                <tr><td class="text-center text-muted">Select a branch or enter a search first</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            <?php elseif ($listRows === []): ?>
                <tr><td class="text-center text-muted">No data found for the current search filters</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            <?php else: foreach ($listRows as $row): $p = is_array($row['payload'] ?? null) ? $row['payload'] : []; $st = strtoupper(trim((string)($p['current_status'] ?? 'ACTIVE'))); $cn = trim((string)($p['customer_name'] ?? '-')) ?: '-'; ?>
                <tr>
                    <td><?php echo (int)($row['id'] ?? 0); ?></td>
                    <td><code><?php echo h((string)($row['contract_no'] ?? '')); ?></code></td>
                    <td><?php echo h((string)($row['customer_code'] ?? '-')); ?></td>
                    <td><?php echo h($cn); ?></td>
                    <td><?php echo h((string)($row['branch_code'] ?? '-')); ?></td>
                    <td><span class="badge text-bg-<?php echo $st === 'NPL' ? 'danger' : ($st === 'CLOSED' ? 'secondary' : 'success'); ?>"><?php echo h($st); ?></span></td>
                    <td><?php echo h((string)($row['updated_at'] ?? '')); ?></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="<?php echo h(app_base_url('modules/04_installments.php?branch_code=' . rawurlencode($selectedBranch) . '&q=' . rawurlencode($searchText) . '&contract_no=' . rawurlencode((string)($row['contract_no'] ?? '')) . '&open_schedule=1')); ?>">View Schedule</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($selectedContract === null): ?>
<section class="alert alert-info">No contract selected yet. Click "View Schedule" to open the installment table in a popup with scrolling.</section>
<?php else: ?>
<?php endif; ?>
<?php if ($selectedContract !== null): ?>
<div class="modal fade modal-slide-down" id="scheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog sf-resizable-modal modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h2 class="h6 mb-0">Installment Schedule: <?php echo h($selectedContractNo); ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 text-muted small">Customer: <?php echo h($customerName !== '' ? $customerName : $customerCode); ?> | Contract interest: <?php echo number_format($annualRatePct, 4); ?>% / year</div>
                <div class="table-responsive" style="max-height:62vh; overflow:auto;">
                    <table class="table table-striped align-middle mb-0" id="installmentScheduleTable">
                        <thead><tr><th>Installment</th><th>Due Date</th><th>Installment Due</th><th>Principal</th><th>Interest</th><th>Status</th><th>Paid</th><th>Outstanding</th><th>Days Overdue</th><th>Late Penalty</th><th>Follow-up Fee</th><th>Payment Document</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($scheduleRows as $item): $row = $item['row']; $quote = $item['quote']; $status = (string)$item['status']; $isPaid = $status === 'PAID'; $isNoPay = $status === 'NO_PAY'; $badge = $isPaid ? 'success' : ($isNoPay ? 'danger' : ((int)$quote['days'] > 0 ? 'warning text-dark' : 'secondary')); $attach = trim((string)($row['payment_attachment'] ?? '')); $attachName = trim((string)($row['payment_attachment_name'] ?? '')); $no = (int)($row['installment_no'] ?? 0); ?>
                            <tr>
                                <td><?php echo $no; ?></td><td><?php echo h((string)($row['due_date'] ?? '-')); ?></td><td><?php echo number_format((float)$quote['due'], 2); ?></td><td><?php echo number_format((float)($row['principal'] ?? 0), 2); ?></td><td><?php echo number_format((float)($row['interest'] ?? 0), 2); ?></td><td><span class="badge text-bg-<?php echo h($badge); ?>"><?php echo h($status); ?></span></td><td><?php echo number_format((float)$quote['paid'], 2); ?></td><td><?php echo number_format((float)$quote['remain'], 2); ?></td><td><?php echo (int)$quote['days']; ?></td><td><?php echo number_format((float)$quote['late'], 2); ?></td><td><?php echo number_format((float)$quote['follow'], 2); ?></td>
                                <td><?php if ($attach !== ''): ?><a href="<?php echo h(app_base_url($attach)); ?>" target="_blank" rel="noopener"><?php echo h($attachName !== '' ? $attachName : basename($attach)); ?></a><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                                <td>
                                    <?php if ($isPaid): ?>
                                        <span class="text-success small">PAID</span>
                                    <?php else: ?>
                                        <div class="d-flex flex-column gap-1">
                                            <button type="button" class="btn btn-sm btn-brand btn-open-pay-modal" data-bs-toggle="modal" data-bs-target="#payInstallmentModal" data-contract="<?php echo h($selectedContractNo); ?>" data-installment="<?php echo $no; ?>" data-due-date="<?php echo h((string)($row['due_date'] ?? '')); ?>" data-due="<?php echo h((string)$quote['due']); ?>" data-remain="<?php echo h((string)$quote['remain']); ?>" data-late="<?php echo h((string)$quote['late']); ?>" data-follow="<?php echo h((string)$quote['follow']); ?>" data-total="<?php echo h((string)$quote['total']); ?>" data-days="<?php echo h((string)$quote['days']); ?>" data-principal="<?php echo h((string)($row['principal'] ?? $quote['remain'])); ?>" data-annual="<?php echo h((string)$annualRatePct); ?>">PAY</button>
                                                <button type="button" class="btn btn-sm btn-outline-warning btn-open-late-pay-modal" data-bs-toggle="modal" data-bs-target="#payInstallmentModal" data-contract="<?php echo h($selectedContractNo); ?>" data-installment="<?php echo $no; ?>" data-due-date="<?php echo h((string)($row['due_date'] ?? '')); ?>" data-due="<?php echo h((string)$quote['due']); ?>" data-remain="<?php echo h((string)$quote['remain']); ?>" data-late="<?php echo h((string)$quote['late']); ?>" data-follow="<?php echo h((string)$quote['follow']); ?>" data-total="<?php echo h((string)$quote['total']); ?>" data-days="<?php echo h((string)$quote['days']); ?>" data-principal="<?php echo h((string)($row['principal'] ?? $quote['remain'])); ?>" data-annual="<?php echo h((string)$annualRatePct); ?>">Late Payment</button>
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-open-no-pay-modal" data-bs-toggle="modal" data-bs-target="#noPayInstallmentModal" data-contract="<?php echo h($selectedContractNo); ?>" data-installment="<?php echo $no; ?>" data-due-date="<?php echo h((string)($row['due_date'] ?? '')); ?>">No Payment</button>
                                            <?php if ($isNoPay): ?><span class="text-danger small">No-payment status already recorded</span><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade modal-slide-down" id="payInstallmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog sf-resizable-modal">
        <div class="modal-content border-0 shadow">
            <form method="post" enctype="multipart/form-data" class="validate-form" novalidate>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="form_action" value="pay_installment">
                <input type="hidden" name="branch_code" value="<?php echo h($selectedBranch); ?>">
                <input type="hidden" name="q" value="<?php echo h($searchText); ?>">
                <input type="hidden" name="contract_no" id="pay_contract_no" value="<?php echo h($selectedContractNo); ?>">
                <input type="hidden" name="installment_no" id="pay_installment_no" value="">
                <input type="hidden" name="print_receipt" id="pay_print_receipt" value="0">
                <input type="hidden" name="late_payment_mode" id="pay_late_mode" value="0">
                <input type="hidden" name="payment_event_type" id="pay_event_type" value="PAY">
                <div class="modal-header"><h2 class="h6 mb-0">Receive Installment Payment</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body">
                    <div class="row g-2 mb-3"><div class="col-md-4"><label class="form-label">Contract No.</label><input class="form-control" id="pay_contract_display" type="text" readonly></div><div class="col-md-2"><label class="form-label">Installment</label><input class="form-control" id="pay_installment_display" type="text" readonly></div><div class="col-md-3"><label class="form-label">Due Date</label><input class="form-control" id="pay_due_date" type="text" readonly></div><div class="col-md-3"><label class="form-label">Payment Date *</label><input class="form-control" type="date" name="payment_date" value="<?php echo h(date('Y-m-d')); ?>" required></div></div>
                    <div class="row g-2 mb-3"><div class="col-md-3"><label class="form-label">Payment Channel *</label><select class="form-select" name="payment_channel" required><option value="CASH">Cash</option><option value="BANK_TRANSFER">Bank Transfer</option><option value="QR">QR</option><option value="AUTO_DEBIT">Auto Debit</option><option value="OTHER">Other</option></select></div><div class="col-md-4"><label class="form-label">Reference / Slip No.</label><input class="form-control" name="payment_ref" maxlength="120"></div><div class="col-md-5"><label class="form-label">Attach Payment Document (PDF/JPG/PNG/WEBP)</label><input class="form-control" type="file" name="payment_attachment" accept=".pdf,.jpg,.jpeg,.png,.webp"></div></div>
                    <div class="row g-2 mb-3"><div class="col-12"><label class="form-label">Note</label><input class="form-control" name="payment_note" id="pay_note" maxlength="255"></div></div>
                    <div class="row g-2 mb-3"><div class="col-md-3"><label class="form-label">Outstanding Installment Due</label><input class="form-control" id="pay_amount_remain" type="text" readonly></div><div class="col-md-3"><label class="form-label">Late Penalty</label><input class="form-control" id="pay_amount_late" type="text" readonly></div><div class="col-md-3"><label class="form-label">Follow-up Fee</label><input class="form-control" id="pay_amount_follow" type="text" readonly></div><div class="col-md-3"><label class="form-label">Total Amount Due</label><input class="form-control fw-semibold" id="pay_amount_total" type="text" readonly></div></div>
                    <div class="row g-2"><div class="col-md-4"><label class="form-label">Paid Amount *</label><input class="form-control" id="pay_amount_input" type="number" name="paid_amount" step="0.01" min="0" required><div class="form-text" id="pay_days_hint"></div></div></div>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-brand" data-print="0">Receive Payment</button><button type="submit" class="btn btn-outline-primary" data-print="1">Receive & Print Receipt</button><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade modal-slide-down" id="noPayInstallmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="post" class="validate-form" novalidate>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="form_action" value="mark_no_pay">
                <input type="hidden" name="branch_code" value="<?php echo h($selectedBranch); ?>">
                <input type="hidden" name="q" value="<?php echo h($searchText); ?>">
                <input type="hidden" name="contract_no" id="no_pay_contract_no" value="<?php echo h($selectedContractNo); ?>">
                <input type="hidden" name="installment_no" id="no_pay_installment_no" value="">
                <div class="modal-header">
                    <h2 class="h6 mb-0">Mark Installment as NO PAY</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Contract No</label>
                            <input class="form-control" id="no_pay_contract_display" type="text" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Installment</label>
                            <input class="form-control" id="no_pay_installment_display" type="text" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Due Date</label>
                            <input class="form-control" id="no_pay_due_date" type="text" readonly>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Action Date *</label>
                            <input class="form-control" type="date" name="no_pay_date" value="<?php echo h(date('Y-m-d')); ?>" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Reason / Note</label>
                            <input class="form-control" type="text" name="no_pay_reason" id="no_pay_reason" maxlength="255" placeholder="Unable to contact customer, customer not ready to pay, etc.">
                        </div>
                    </div>
                    <p class="small text-muted mb-0">This action will be saved into database with status NO_PAY.</p>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-danger">Confirm No Payment</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    function toMoney(v) {
        var n = Number(v || 0);
        if (!Number.isFinite(n)) n = 0;
        return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    var payModalEl = document.getElementById('payInstallmentModal');
    if (payModalEl) {
        var dueDateInput = payModalEl.querySelector('input[name="payment_date"]');
        var amountInput = document.getElementById('pay_amount_input');
        var runtime = {
            dueDate: '',
            remain: 0,
            principal: 0,
            annualRate: 0
        };

        function parseYmd(ymd) {
            if (!ymd || !/^\d{4}-\d{2}-\d{2}$/.test(ymd)) return null;
            var d = new Date(ymd + 'T00:00:00');
            return isNaN(d.getTime()) ? null : d;
        }

        function round2(n) {
            return Math.round((Number(n) + Number.EPSILON) * 100) / 100;
        }

        function diffDaysOverdue(dueDateYmd, payDateYmd) {
            var due = parseYmd(dueDateYmd);
            var pay = parseYmd(payDateYmd);
            if (!due || !pay) return 0;
            var ms = pay.getTime() - due.getTime();
            var days = Math.floor(ms / 86400000);
            return days > 0 ? days : 0;
        }

        function recomputePaymentTotals() {
            var payDate = dueDateInput ? dueDateInput.value : '';
            var days = diffDaysOverdue(runtime.dueDate, payDate);
            var lateRate = Math.max(0, runtime.annualRate + 3) / 100;
            var late = days > 0 && runtime.principal > 0 ? round2(runtime.principal * lateRate * (days / 365)) : 0;
            var follow = days > 0 && runtime.remain > 0 ? 100 : 0;
            var total = round2(runtime.remain + late + follow);

            document.getElementById('pay_amount_remain').value = toMoney(runtime.remain);
            document.getElementById('pay_amount_late').value = toMoney(late);
            document.getElementById('pay_amount_follow').value = toMoney(follow);
            document.getElementById('pay_amount_total').value = toMoney(total);

            if (amountInput) {
                amountInput.min = total.toFixed(2);
                amountInput.value = total.toFixed(2);
            }

            document.getElementById('pay_days_hint').textContent = days > 0
                ? ('Overdue by ' + days + ' day(s) (late penalty + follow-up fee 100.00)')
                : 'Not overdue yet';
        }

        if (dueDateInput) {
            dueDateInput.addEventListener('change', recomputePaymentTotals);
            dueDateInput.addEventListener('input', recomputePaymentTotals);
        }

        function openPayModalFromButton(btn, lateMode) {
            var c = btn.getAttribute('data-contract') || '';
            var no = btn.getAttribute('data-installment') || '';
            runtime.dueDate = btn.getAttribute('data-due-date') || '';
            runtime.remain = Number(btn.getAttribute('data-remain') || 0);
            runtime.principal = Number(btn.getAttribute('data-principal') || runtime.remain || 0);
            runtime.annualRate = Number(btn.getAttribute('data-annual') || 0);

            document.getElementById('pay_contract_no').value = c;
            document.getElementById('pay_installment_no').value = no;
            document.getElementById('pay_contract_display').value = c;
            document.getElementById('pay_installment_display').value = no;
            document.getElementById('pay_due_date').value = runtime.dueDate;
            document.getElementById('pay_note').value = lateMode ? 'LATE_PAY' : '';
            document.getElementById('pay_print_receipt').value = '0';

            var lateModeInput = document.getElementById('pay_late_mode');
            var eventTypeInput = document.getElementById('pay_event_type');
            if (lateModeInput) {
                lateModeInput.value = lateMode ? '1' : '0';
            }
            if (eventTypeInput) {
                eventTypeInput.value = lateMode ? 'LATE_PAY' : 'PAY';
            }

            if (dueDateInput) {
                dueDateInput.value = '<?php echo h(date('Y-m-d')); ?>';
            }
            recomputePaymentTotals();
        }

        document.querySelectorAll('.btn-open-pay-modal').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openPayModalFromButton(btn, false);
            });
        });

        document.querySelectorAll('.btn-open-late-pay-modal').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openPayModalFromButton(btn, true);
            });
        });

        payModalEl.querySelectorAll('button[data-print]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('pay_print_receipt').value = btn.getAttribute('data-print') === '1' ? '1' : '0';
            });
        });
    }

    var noPayModalEl = document.getElementById('noPayInstallmentModal');
    if (noPayModalEl) {
        document.querySelectorAll('.btn-open-no-pay-modal').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var c = btn.getAttribute('data-contract') || '';
                var no = btn.getAttribute('data-installment') || '';
                var due = btn.getAttribute('data-due-date') || '';

                document.getElementById('no_pay_contract_no').value = c;
                document.getElementById('no_pay_installment_no').value = no;
                document.getElementById('no_pay_contract_display').value = c;
                document.getElementById('no_pay_installment_display').value = no;
                document.getElementById('no_pay_due_date').value = due;
                document.getElementById('no_pay_reason').value = '';

                var noPayDateInput = noPayModalEl.querySelector('input[name="no_pay_date"]');
                if (noPayDateInput) {
                    noPayDateInput.value = '<?php echo h(date('Y-m-d')); ?>';
                }
            });
        });
    }

    <?php if ($selectedContract !== null && $openScheduleModal): ?>
    var scheduleEl = document.getElementById('scheduleModal');
    function openScheduleModal(retry) {
        if (!scheduleEl) return;
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(scheduleEl).show();
            return;
        }
        if (retry < 30) {
            window.setTimeout(function(){ openScheduleModal(retry + 1); }, 120);
        }
    }
    if (document.readyState === 'complete') {
        openScheduleModal(0);
    } else {
        window.addEventListener('load', function(){ openScheduleModal(0); }, { once: true });
    }
    <?php endif; ?>

    <?php if ($printAuto && $receiptForPrint !== null): ?>
    window.setTimeout(function () { window.print(); }, 350);
    <?php endif; ?>
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>

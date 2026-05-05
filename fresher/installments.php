<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$selectedContract = strtoupper(trim((string)($_GET['contract_code'] ?? $_POST['contract_code'] ?? '')));
$postedContract = strtoupper(trim((string)($_POST['contract_code'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));

        $action = trim((string)($_POST['action'] ?? ''));
        $contractCode = $postedContract;
        if ($contractCode === '') {
            throw new RuntimeException('Contract code is required.');
        }

        $actor = current_user_name();
        $now = now_dt();

        if (in_array($action, ['pay_installment', 'reset_unpaid'], true)) {
            $id = fresher_int($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Installment row is required.');
            }

            $scope = fresher_scope_clause('branch_code', 'fr_ins_post');
            $stmtFind = db()->prepare(
                'SELECT *
                 FROM fresher_installments
                 WHERE id = :id
                   AND contract_code = :contract_code
                   AND is_deleted = 0' . $scope['sql'] . '
                 LIMIT 1'
            );
            $params = $scope['params'];
            $params[':id'] = $id;
            $params[':contract_code'] = $contractCode;
            $stmtFind->execute($params);
            $row = $stmtFind->fetch();
            if (!$row) {
                throw new RuntimeException('Installment not found or out of scope.');
            }
            assert_branch_in_current_scope((string)$row['branch_code']);

            if ($action === 'pay_installment') {
                $paymentDate = fresher_normalize_date(trim((string)($_POST['payment_date'] ?? date('Y-m-d'))), date('Y-m-d'));
                $paymentMethod = strtoupper(trim((string)($_POST['payment_method'] ?? 'CASH')));
                $referenceNo = trim((string)($_POST['reference_no'] ?? ''));
                $noteText = trim((string)($_POST['note_text'] ?? ''));
                $followupEnabled = ((string)($_POST['followup_enabled'] ?? '0')) === '1';
                $collectionFee = $followupEnabled ? 100.0 : 0.0;

                $upload = fresher_upload_file(
                    'payment_attachment',
                    'documents',
                    ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
                    12 * 1024 * 1024
                );
                $attachmentPath = $upload['file_path'] ?? '';

                $installmentAmount = round(max(0, fresher_decimal($row['installment_amount'] ?? 0)), 2);
                $paidBefore = round(max(0, fresher_decimal($row['paid_amount'] ?? 0)), 2);
                $remainingDue = round(max(0, $installmentAmount - $paidBefore), 2);
                if ($remainingDue <= 0) {
                    throw new RuntimeException('Installment already paid.');
                }

                $snapshotAtPayDate = fresher_contract_snapshot($contractCode, $paymentDate);
                $latePenalty = 0.0;
                foreach (($snapshotAtPayDate['items'] ?? []) as $snapshotItem) {
                    if ((int)($snapshotItem['id'] ?? 0) !== $id) {
                        continue;
                    }
                    $latePenalty = round(max(0, fresher_decimal($snapshotItem['late_penalty'] ?? 0)), 2);
                    break;
                }

                $paymentMap = [$id => $remainingDue];
                $receiptCode = fresher_process_payment_receipt(
                    $contractCode,
                    $paymentDate,
                    $paymentMethod,
                    $referenceNo,
                    $noteText,
                    $paymentMap,
                    $latePenalty,
                    $collectionFee,
                    $attachmentPath
                );

                $printReceipt = ((string)($_POST['print_receipt'] ?? '0')) === '1';
                if ($printReceipt) {
                    redirect_to(fresher_base_url('receipt_print.php?receipt_code=' . rawurlencode($receiptCode) . '&auto=1'));
                }

                add_flash(
                    'success',
                    sprintf(
                        'Payment accepted. Receipt: %s | Installment: %.2f | Late penalty: %.2f | Collection fee: %.2f',
                        $receiptCode,
                        $remainingDue,
                        $latePenalty,
                        $collectionFee
                    )
                );
            } else {
                $stmt = db()->prepare(
                    'UPDATE fresher_installments
                     SET paid_amount = 0,
                         paid_date = NULL,
                         payment_status = "UNPAID",
                         receipt_no = "",
                         note_text = "",
                         updated_by = :updated_by,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':updated_by' => $actor,
                    ':updated_at' => $now,
                    ':id' => $id,
                ]);
                add_flash('warning', 'Installment was reset to UNPAID.');
            }

            fresher_refresh_contract_status($contractCode);
        } elseif ($action === 'close_early') {
            $contract = fresher_contract_row($contractCode);
            if (!$contract) {
                throw new RuntimeException('Contract not found.');
            }
            assert_branch_in_current_scope((string)$contract['branch_code']);

            $payoffDate = trim((string)($_POST['payoff_date'] ?? date('Y-m-d')));
            $receiptNo = trim((string)($_POST['receipt_no'] ?? ''));
            $note = trim((string)($_POST['note_text'] ?? ''));

            $quote = fresher_early_payoff_quote($contractCode, $payoffDate);
            $payoffAmount = max(0, fresher_decimal($quote['payoff_amount'] ?? 0));
            if ($payoffAmount <= 0) {
                throw new RuntimeException('Early payoff amount is not available.');
            }

            db()->beginTransaction();
            try {
                $settlementCode = fresher_generate_code('FRPO');
                $stmtSettlement = db()->prepare(
                    'INSERT INTO fresher_payoff_settlements (
                        settlement_code, contract_code, customer_code, customer_name, branch_code,
                        quote_date, paid_ratio, discount_tier, discount_rate,
                        remaining_principal, remaining_interest, discount_interest, payable_interest, payoff_amount,
                        receipt_no, note_text,
                        is_deleted, created_by, created_at
                     ) VALUES (
                        :settlement_code, :contract_code, :customer_code, :customer_name, :branch_code,
                        :quote_date, :paid_ratio, :discount_tier, :discount_rate,
                        :remaining_principal, :remaining_interest, :discount_interest, :payable_interest, :payoff_amount,
                        :receipt_no, :note_text,
                        0, :created_by, :created_at
                     )'
                );
                $stmtSettlement->execute([
                    ':settlement_code' => $settlementCode,
                    ':contract_code' => $contractCode,
                    ':customer_code' => (string)($contract['customer_code'] ?? ''),
                    ':customer_name' => (string)($contract['customer_name'] ?? ''),
                    ':branch_code' => (string)($contract['branch_code'] ?? ''),
                    ':quote_date' => $payoffDate,
                    ':paid_ratio' => (float)($quote['paid_ratio'] ?? 0),
                    ':discount_tier' => (string)($quote['discount_tier'] ?? ''),
                    ':discount_rate' => (float)($quote['discount_rate'] ?? 0),
                    ':remaining_principal' => (float)($quote['remaining_principal'] ?? 0),
                    ':remaining_interest' => (float)($quote['remaining_interest'] ?? 0),
                    ':discount_interest' => (float)($quote['discount_interest'] ?? 0),
                    ':payable_interest' => (float)($quote['payable_interest'] ?? 0),
                    ':payoff_amount' => $payoffAmount,
                    ':receipt_no' => $receiptNo,
                    ':note_text' => $note,
                    ':created_by' => $actor,
                    ':created_at' => $now,
                ]);

                $stmtCloseInstallments = db()->prepare(
                    'UPDATE fresher_installments
                     SET payment_status = "WAIVED_EARLY",
                         note_text = CONCAT(IFNULL(note_text, ""), " | EARLY_CLOSE:", :settlement_code),
                         updated_by = :updated_by,
                         updated_at = :updated_at
                     WHERE contract_code = :contract_code
                       AND is_deleted = 0
                       AND payment_status NOT IN ("PAID", "WAIVED_EARLY")'
                );
                $stmtCloseInstallments->execute([
                    ':settlement_code' => $settlementCode,
                    ':updated_by' => $actor,
                    ':updated_at' => $now,
                    ':contract_code' => $contractCode,
                ]);

                $stmtCloseContract = db()->prepare(
                    'UPDATE fresher_hire_purchase
                     SET contract_status = "CLOSED",
                         updated_by = :updated_by,
                         updated_at = :updated_at
                     WHERE contract_code = :contract_code
                       AND is_deleted = 0'
                );
                $stmtCloseContract->execute([
                    ':updated_by' => $actor,
                    ':updated_at' => $now,
                    ':contract_code' => $contractCode,
                ]);

                db()->commit();
                add_flash('success', sprintf('Contract closed early. Payoff amount: %.2f', $payoffAmount));
            } catch (Throwable $txError) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                throw $txError;
            }
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        add_flash('danger', 'Could not process installment action: ' . $e->getMessage());
    }

    $redirectContract = $selectedContract !== '' ? $selectedContract : $postedContract;
    redirect_to(fresher_base_url('installments.php?contract_code=' . rawurlencode($redirectContract)));
}

$currentFresherPage = 'installments';
$pageTitle = 'Installment Schedule';

$contractOptions = fresher_contract_options();
if ($selectedContract === '' && $contractOptions !== []) {
    $selectedContract = strtoupper(trim((string)($contractOptions[0]['contract_code'] ?? '')));
}

$contract = $selectedContract !== '' ? fresher_contract_row($selectedContract) : null;
$rows = [];
$summary = [
    'total_installment' => 0.0,
    'total_paid' => 0.0,
    'remaining' => 0.0,
    'paid_count' => 0,
    'all_count' => 0,
];
$payoffQuote = null;
$penaltyQuote = null;
$collectionFeeQuote = null;
$settlements = [];
$customerPhone = '';

if ($contract) {
    $snapshot = fresher_contract_snapshot($selectedContract, date('Y-m-d'));
    $rows = $snapshot['items'] ?? [];
    $totals = $snapshot['totals'] ?? [];

    $summary['all_count'] = count($rows);
    $summary['total_installment'] = (float)($totals['total_installment'] ?? 0);
    $summary['total_paid'] = (float)($totals['paid_total'] ?? 0);
    $summary['remaining'] = (float)($totals['remaining_principal'] ?? 0) + (float)($totals['remaining_interest'] ?? 0);
    foreach ($rows as $row) {
        if (strtoupper((string)($row['payment_status'] ?? '')) === 'PAID') {
            $summary['paid_count']++;
        }
    }

    $payoffQuote = fresher_early_payoff_quote($selectedContract, date('Y-m-d'));
    $penaltyQuote = fresher_late_penalty_quote($selectedContract, date('Y-m-d'));
    $collectionFeeQuote = fresher_collection_fee_quote($selectedContract, date('Y-m-d'));

    $customer = fresher_customer_row((string)($contract['customer_code'] ?? ''));
    if ($customer) {
        $customerPhone = trim((string)($customer['phone_number'] ?? ''));
    }

    $stmtSettle = db()->prepare(
        'SELECT *
         FROM fresher_payoff_settlements
         WHERE contract_code = :contract_code
           AND is_deleted = 0
         ORDER BY id DESC
         LIMIT 10'
    );
    $stmtSettle->execute([':contract_code' => $selectedContract]);
    $settlements = $stmtSettle->fetchAll();
}

include __DIR__ . '/partials/head.php';
?>

<section class="card fr-card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-7">
                <label class="form-label">Contract</label>
                <select class="form-select" name="contract_code">
                    <?php foreach ($contractOptions as $option): ?>
                        <?php $code = strtoupper((string)($option['contract_code'] ?? '')); ?>
                        <option value="<?php echo h($code); ?>" <?php echo $code === $selectedContract ? 'selected' : ''; ?>>
                            <?php echo h($code . ' - ' . (string)$option['customer_name'] . ' (' . (string)$option['contract_status'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5 fr-actions">
                <button class="btn btn-primary" type="submit">Load Schedule</button>
                <a class="btn btn-outline-secondary" href="<?php echo h(fresher_base_url('hire_purchase.php')); ?>">Back To Contracts</a>
            </div>
        </form>
    </div>
</section>

<?php if ($contract): ?>
    <section class="row g-3 mb-4">
        <div class="col-md-3"><div class="fr-stat"><span>Total Contract Amount</span><strong><?php echo number_format($summary['total_installment'], 2); ?></strong></div></div>
        <div class="col-md-3"><div class="fr-stat"><span>Total Paid</span><strong><?php echo number_format($summary['total_paid'], 2); ?></strong></div></div>
        <div class="col-md-3"><div class="fr-stat"><span>Remaining</span><strong><?php echo number_format($summary['remaining'], 2); ?></strong></div></div>
        <div class="col-md-3"><div class="fr-stat"><span>Paid Installments</span><strong><?php echo number_format($summary['paid_count']) . ' / ' . number_format($summary['all_count']); ?></strong></div></div>
        <div class="col-md-3"><div class="fr-stat"><span>Late Penalty (Today)</span><strong><?php echo number_format((float)($penaltyQuote['late_penalty_amount'] ?? 0), 2); ?></strong></div></div>
        <div class="col-md-3"><div class="fr-stat"><span>Penalty Rate</span><strong><?php echo number_format((float)($penaltyQuote['late_penalty_rate'] ?? 0), 4); ?>%</strong></div></div>
        <div class="col-md-3"><div class="fr-stat"><span>Collection Fee Cap</span><strong><?php echo number_format((float)($collectionFeeQuote['recommended_fee'] ?? 0), 2); ?></strong></div></div>
        <div class="col-md-3"><div class="fr-stat"><span>Early Payoff</span><strong><?php echo number_format((float)($payoffQuote['payoff_amount'] ?? 0), 2); ?></strong></div></div>
    </section>

    <section class="card fr-card mb-4">
        <div class="card-header bg-white">
            <strong>Early Payoff Quote</strong>
        </div>
        <div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-md-3"><div class="fr-stat"><span>Paid Ratio</span><strong><?php echo number_format(((float)($payoffQuote['paid_ratio'] ?? 0)) * 100, 2); ?>%</strong></div></div>
                <div class="col-md-3"><div class="fr-stat"><span>Discount Tier</span><strong><?php echo h((string)($payoffQuote['discount_tier'] ?? '-')); ?></strong></div></div>
                <div class="col-md-3"><div class="fr-stat"><span>Interest Discount</span><strong><?php echo number_format((float)($payoffQuote['discount_interest'] ?? 0), 2); ?></strong></div></div>
                <div class="col-md-3"><div class="fr-stat"><span>Payoff Amount</span><strong><?php echo number_format((float)($payoffQuote['payoff_amount'] ?? 0), 2); ?></strong></div></div>
            </div>
            <form method="post" class="row g-2 align-items-end">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="close_early">
                <input type="hidden" name="contract_code" value="<?php echo h($selectedContract); ?>">
                <div class="col-md-3">
                    <label class="form-label">Payoff Date</label>
                    <input class="form-control" type="date" name="payoff_date" value="<?php echo h(date('Y-m-d')); ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Receipt No</label>
                    <input class="form-control" name="receipt_no" value="">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Note</label>
                    <input class="form-control" name="note_text" value="">
                </div>
                <div class="col-md-2 fr-actions">
                    <button class="btn btn-danger" type="submit" onclick="return confirm('Confirm early payoff close?')">Close Early</button>
                </div>
            </form>
        </div>
    </section>

    <?php if ($settlements !== []): ?>
    <section class="card fr-card mb-4">
        <div class="card-header bg-white"><strong>Payoff History</strong></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 js-fresher-datatable">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Date</th>
                        <th>Tier</th>
                        <th>Discount %</th>
                        <th>Payoff Amount</th>
                        <th>Receipt</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($settlements as $s): ?>
                    <tr>
                        <td><code><?php echo h((string)$s['settlement_code']); ?></code></td>
                        <td><?php echo h((string)$s['quote_date']); ?></td>
                        <td><?php echo h((string)$s['discount_tier']); ?></td>
                        <td><?php echo number_format((float)$s['discount_rate'] * 100, 2); ?></td>
                        <td><?php echo number_format((float)$s['payoff_amount'], 2); ?></td>
                        <td><?php echo h((string)$s['receipt_no']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <section class="card fr-card mb-4">
        <div class="card-header bg-white">
            <strong>
                Contract <?php echo h((string)$contract['contract_code']); ?> |
                Customer <?php echo h((string)$contract['customer_name']); ?> |
                Status <?php echo h((string)$contract['contract_status']); ?>
            </strong>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 js-fresher-datatable">
                    <thead>
                    <tr>
                        <th>No.</th>
                        <th>Due Date</th>
                        <th>Installment</th>
                        <th>Principal</th>
                        <th>Interest</th>
                        <th>Status</th>
                        <th>Paid Amount</th>
                        <th>Days Overdue</th>
                        <th>Late Penalty</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $status = strtoupper((string)($row['payment_status'] ?? 'UNPAID'));
                            $dueDate = (string)($row['due_date'] ?? '');
                            if ($status !== 'PAID' && $status !== 'WAIVED_EARLY' && $dueDate !== '' && $dueDate < date('Y-m-d')) {
                                $status = 'OVERDUE';
                            }
                            $installmentAmountRow = round(max(0, fresher_decimal($row['installment_amount'] ?? 0)), 2);
                            $paidAmountRow = round(max(0, fresher_decimal($row['paid_amount'] ?? 0)), 2);
                            $remainingDueRow = round(max(0, $installmentAmountRow - $paidAmountRow), 2);
                            $latePenaltyRow = round(max(0, fresher_decimal($row['late_penalty'] ?? 0)), 2);
                        ?>
                        <tr>
                            <td><?php echo number_format((int)$row['installment_no']); ?></td>
                            <td><?php echo h($dueDate); ?></td>
                            <td><?php echo number_format($installmentAmountRow, 2); ?></td>
                            <td><?php echo number_format((float)$row['principal_amount'], 2); ?></td>
                            <td><?php echo number_format((float)$row['interest_amount'], 2); ?></td>
                            <td><?php echo h($status); ?></td>
                            <td><?php echo number_format($paidAmountRow, 2); ?></td>
                            <td><?php echo number_format((int)($row['days_overdue'] ?? 0)); ?></td>
                            <td><?php echo number_format($latePenaltyRow, 2); ?></td>
                            <td class="fr-actions">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-primary js-open-pay-modal"
                                    data-id="<?php echo (int)$row['id']; ?>"
                                    data-contract-code="<?php echo h($selectedContract); ?>"
                                    data-installment-no="<?php echo h((string)$row['installment_no']); ?>"
                                    data-due-date="<?php echo h($dueDate); ?>"
                                    data-status="<?php echo h($status); ?>"
                                    data-remaining-due="<?php echo number_format($remainingDueRow, 2, '.', ''); ?>"
                                    data-late-penalty="<?php echo number_format($latePenaltyRow, 2, '.', ''); ?>"
                                    data-note="<?php echo h((string)$row['note_text']); ?>"
                                    data-phone="<?php echo h($customerPhone); ?>"
                                    <?php echo $remainingDueRow <= 0 ? 'disabled' : ''; ?>
                                >
                                    <?php echo $remainingDueRow <= 0 ? 'ชำระแล้ว' : 'ชำระเงิน'; ?>
                                </button>
                                <form method="post" class="d-inline" onsubmit="return confirm('ยืนยันรีเซ็ตงวดนี้เป็นยังไม่ชำระ?');">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="action" value="reset_unpaid">
                                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                    <input type="hidden" name="contract_code" value="<?php echo h($selectedContract); ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit">รีเซ็ตงวด</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <div class="modal fade" id="installmentPayModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data" id="installmentPayForm">
                    <div class="modal-header">
                        <h5 class="modal-title mb-0">รับชำระค่างวด</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="action" value="pay_installment">
                        <input type="hidden" name="id" id="pay_installment_id" value="">
                        <input type="hidden" name="contract_code" id="pay_contract_code" value="<?php echo h($selectedContract); ?>">
                        <input type="hidden" name="followup_enabled" value="0" id="pay_followup_enabled">

                        <div class="row g-2 mb-3">
                            <div class="col-md-4"><div class="small text-muted">เลขสัญญา</div><div id="pay_info_contract"><?php echo h($selectedContract); ?></div></div>
                            <div class="col-md-2"><div class="small text-muted">งวดที่</div><div id="pay_info_installment_no">-</div></div>
                            <div class="col-md-3"><div class="small text-muted">วันครบกำหนด</div><div id="pay_info_due_date">-</div></div>
                            <div class="col-md-3"><div class="small text-muted">สถานะ</div><div id="pay_info_status">-</div></div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">วันที่รับชำระ</label>
                                <input class="form-control" type="date" name="payment_date" id="pay_payment_date" value="<?php echo h(date('Y-m-d')); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">ช่องทางชำระ</label>
                                <select class="form-select" name="payment_method" id="pay_payment_method">
                                    <option value="CASH">เงินสด</option>
                                    <option value="TRANSFER">โอนเงิน</option>
                                    <option value="PROMPTPAY">พร้อมเพย์ / QR</option>
                                    <option value="CARD">บัตร</option>
                                    <option value="OTHER">อื่นๆ</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">เลขอ้างอิง / Payin</label>
                                <input class="form-control" name="reference_no" id="pay_reference_no" value="" placeholder="Ref / Payin no">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">ยอดงวดคงค้าง</label>
                                <input class="form-control" id="pay_remaining_due" value="0.00" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">ดอกเบี้ยผิดนัด</label>
                                <input class="form-control" id="pay_late_penalty" value="0.00" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">ค่าติดตามทวงถาม</label>
                                <div class="input-group">
                                    <input class="form-control" id="pay_collection_fee" value="0.00" readonly>
                                    <button type="button" class="btn btn-outline-danger" id="pay_toggle_followup" data-phone="">ติดตาม (+100)</button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">ยอดค่าติดตาม + ดอกเบี้ยผิดนัด</label>
                                <input class="form-control" id="pay_extra_total" value="0.00" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">ยอดรับชำระรวม</label>
                                <input class="form-control" id="pay_grand_total" value="0.00" readonly>
                            </div>

                            <div class="col-12">
                                <label class="form-label">แนบเอกสารชำระ</label>
                                <input class="form-control" type="file" name="payment_attachment" id="pay_attachment" accept=".pdf,.jpg,.jpeg,.png,.webp">
                            </div>
                            <div class="col-12">
                                <label class="form-label">หมายเหตุ</label>
                                <input class="form-control" name="note_text" id="pay_note_text" value="" placeholder="Note">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer fr-actions">
                        <button class="btn btn-primary" type="submit" name="print_receipt" value="0">รับชำระ</button>
                        <button class="btn btn-outline-primary" type="submit" name="print_receipt" value="1">รับชำระและพิมพ์ใบเสร็จ</button>
                        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">ปิด</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('installmentPayModal');
    const form = document.getElementById('installmentPayForm');
    if (!modalEl || !form || !window.bootstrap) {
        return;
    }

    const modal = new bootstrap.Modal(modalEl);
    const idInput = document.getElementById('pay_installment_id');
    const contractInput = document.getElementById('pay_contract_code');
    const followupInput = document.getElementById('pay_followup_enabled');
    const payDateInput = document.getElementById('pay_payment_date');
    const methodInput = document.getElementById('pay_payment_method');
    const refInput = document.getElementById('pay_reference_no');
    const noteInput = document.getElementById('pay_note_text');
    const attachmentInput = document.getElementById('pay_attachment');

    const infoContract = document.getElementById('pay_info_contract');
    const infoNo = document.getElementById('pay_info_installment_no');
    const infoDue = document.getElementById('pay_info_due_date');
    const infoStatus = document.getElementById('pay_info_status');

    const dueInput = document.getElementById('pay_remaining_due');
    const penaltyInput = document.getElementById('pay_late_penalty');
    const feeInput = document.getElementById('pay_collection_fee');
    const extraInput = document.getElementById('pay_extra_total');
    const grandInput = document.getElementById('pay_grand_total');
    const followupBtn = document.getElementById('pay_toggle_followup');

    function toNum(v) {
        const n = parseFloat(String(v || '0').replace(/,/g, ''));
        return Number.isFinite(n) ? n : 0;
    }

    function recalcTotals() {
        const due = toNum(dueInput.value);
        const penalty = toNum(penaltyInput.value);
        const fee = toNum(feeInput.value);
        extraInput.value = (penalty + fee).toFixed(2);
        grandInput.value = (due + penalty + fee).toFixed(2);
    }

    function setFollowup(enabled) {
        followupInput.value = enabled ? '1' : '0';
        feeInput.value = enabled ? '100.00' : '0.00';
        followupBtn.classList.toggle('btn-danger', enabled);
        followupBtn.classList.toggle('btn-outline-danger', !enabled);
        followupBtn.textContent = enabled ? 'ยกเลิกติดตาม' : 'ติดตาม (+100)';
        recalcTotals();
    }

    document.querySelectorAll('.js-open-pay-modal').forEach((btn) => {
        btn.addEventListener('click', function () {
            idInput.value = String(this.getAttribute('data-id') || '');
            contractInput.value = String(this.getAttribute('data-contract-code') || '');

            infoContract.textContent = String(this.getAttribute('data-contract-code') || '-');
            infoNo.textContent = String(this.getAttribute('data-installment-no') || '-');
            infoDue.textContent = String(this.getAttribute('data-due-date') || '-');
            infoStatus.textContent = String(this.getAttribute('data-status') || '-');

            dueInput.value = toNum(this.getAttribute('data-remaining-due') || '0').toFixed(2);
            penaltyInput.value = toNum(this.getAttribute('data-late-penalty') || '0').toFixed(2);
            noteInput.value = String(this.getAttribute('data-note') || '');
            followupBtn.setAttribute('data-phone', String(this.getAttribute('data-phone') || ''));

            payDateInput.value = "<?php echo h(date('Y-m-d')); ?>";
            methodInput.value = 'CASH';
            refInput.value = '';
            attachmentInput.value = '';

            setFollowup(false);
            modal.show();
        });
    });

    followupBtn.addEventListener('click', function () {
        const enable = followupInput.value !== '1';
        setFollowup(enable);
        if (enable) {
            const phone = String(this.getAttribute('data-phone') || '').trim();
            if (phone !== '') {
                alert('เบอร์โทรลูกค้า: ' + phone);
            } else {
                alert('ไม่พบเบอร์โทรลูกค้า');
            }
        }
    });
});
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>

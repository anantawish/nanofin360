<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));
        $action = trim((string)($_POST['action'] ?? ''));
        $actor = current_user_name();
        $now = now_dt();
        $today = date('Y-m-d');

        if ($action !== 'confirm_followup') {
            throw new RuntimeException('คำสั่งไม่ถูกต้อง');
        }

        $contractCode = strtoupper(trim((string)($_POST['contract_code'] ?? '')));
        $outcome = trim((string)($_POST['outcome'] ?? 'โทรติดตามลูกค้าแล้ว'));
        $note = trim((string)($_POST['note_text'] ?? ''));

        if ($contractCode === '') {
            throw new RuntimeException('ไม่พบเลขสัญญาที่ต้องการยืนยันติดตาม');
        }

        $contract = fresher_contract_row($contractCode);
        if (!$contract) {
            throw new RuntimeException('ไม่พบข้อมูลสัญญาหรือไม่มีสิทธิ์เข้าถึง');
        }
        $branchCode = strtoupper(trim((string)($contract['branch_code'] ?? '')));
        assert_branch_in_current_scope($branchCode);

        $dpdDays = fresher_contract_dpd_days($contractCode);
        if ($dpdDays <= 0) {
            throw new RuntimeException('สัญญานี้ไม่อยู่ในสถานะเกินกำหนดชำระแล้ว');
        }

        $stmtExists = db()->prepare(
            'SELECT id
             FROM fresher_collections
             WHERE contract_code = :contract_code
               AND is_deleted = 0
               AND followup_date = :followup_date
             LIMIT 1'
        );
        $stmtExists->execute([
            ':contract_code' => $contractCode,
            ':followup_date' => $today,
        ]);
        $existsId = (int)$stmtExists->fetchColumn();
        if ($existsId > 0) {
            throw new RuntimeException('สัญญานี้ถูกยืนยันติดตามแล้วในวันนี้');
        }

        $feeQuote = fresher_collection_fee_quote($contractCode, $today);
        $penaltyQuote = fresher_late_penalty_quote($contractCode, $today);

        $overdueInstallments = max(0, fresher_int($feeQuote['overdue_installments'] ?? 0));
        $overduePrincipalAmount = max(0, fresher_decimal($feeQuote['overdue_principal_amount'] ?? 0));
        $overdueDueAmount = max(0, fresher_decimal($feeQuote['overdue_due_amount'] ?? 0));
        $collectionFeeAmount = max(0, fresher_decimal($feeQuote['recommended_fee'] ?? 0));
        $collectionFeeNote = trim((string)($feeQuote['reason'] ?? ''));
        $contractInterestRate = max(0, fresher_decimal($penaltyQuote['contract_interest_rate'] ?? 0));
        $latePenaltyRate = max(0, fresher_decimal($penaltyQuote['late_penalty_rate'] ?? 0));
        $latePenaltyAmount = max(0, fresher_decimal($penaltyQuote['late_penalty_amount'] ?? 0));

        $followupCode = fresher_generate_code('FRCOL');
        $stmtInsert = db()->prepare(
            'INSERT INTO fresher_collections (
                followup_code, contract_code, customer_code, customer_name, branch_code,
                dpd_days, followup_date, channel, outcome,
                promise_date, promise_amount, next_action_date,
                collector_code, collector_name,
                overdue_installments, overdue_principal_amount, overdue_due_amount,
                requested_collection_fee, collection_fee_amount, collection_fee_note,
                contract_interest_rate, late_penalty_rate, late_penalty_amount,
                collection_status, note_text,
                is_deleted, created_by, created_at
            ) VALUES (
                :followup_code, :contract_code, :customer_code, :customer_name, :branch_code,
                :dpd_days, :followup_date, :channel, :outcome,
                NULL, 0, NULL,
                "", :collector_name,
                :overdue_installments, :overdue_principal_amount, :overdue_due_amount,
                0, :collection_fee_amount, :collection_fee_note,
                :contract_interest_rate, :late_penalty_rate, :late_penalty_amount,
                :collection_status, :note_text,
                0, :created_by, :created_at
            )'
        );
        $stmtInsert->execute([
            ':followup_code' => $followupCode,
            ':contract_code' => $contractCode,
            ':customer_code' => (string)($contract['customer_code'] ?? ''),
            ':customer_name' => (string)($contract['customer_name'] ?? ''),
            ':branch_code' => $branchCode,
            ':dpd_days' => $dpdDays,
            ':followup_date' => $today,
            ':channel' => 'CALL',
            ':outcome' => $outcome !== '' ? $outcome : 'โทรติดตามลูกค้าแล้ว',
            ':collector_name' => $actor,
            ':overdue_installments' => $overdueInstallments,
            ':overdue_principal_amount' => $overduePrincipalAmount,
            ':overdue_due_amount' => $overdueDueAmount,
            ':collection_fee_amount' => $collectionFeeAmount,
            ':collection_fee_note' => $collectionFeeNote,
            ':contract_interest_rate' => $contractInterestRate,
            ':late_penalty_rate' => $latePenaltyRate,
            ':late_penalty_amount' => $latePenaltyAmount,
            ':collection_status' => 'DONE',
            ':note_text' => $note,
            ':created_by' => $actor,
            ':created_at' => $now,
        ]);

        add_flash('success', 'ยืนยันติดตามสำเร็จ: ' . $contractCode);
    } catch (Throwable $e) {
        add_flash('danger', 'บันทึกการติดตามไม่สำเร็จ: ' . $e->getMessage());
    }

    redirect_to(fresher_base_url('collections.php'));
}

$currentFresherPage = 'collections';
$pageTitle = 'ระบบติดตามทวงถามหนี้';
$today = date('Y-m-d');

$scope = fresher_scope_clause('hp.branch_code', 'fr_col_overdue');
$sql = 'SELECT
            hp.contract_code,
            hp.customer_code,
            hp.customer_name,
            hp.branch_code,
            COALESCE(c.phone_number, "") AS phone_number,
            MIN(CASE WHEN fi.payment_status NOT IN ("PAID", "WAIVED_EARLY") AND fi.due_date < CURDATE() THEN fi.due_date END) AS first_overdue_date,
            MAX(CASE WHEN fi.payment_status NOT IN ("PAID", "WAIVED_EARLY") AND fi.due_date < CURDATE() THEN DATEDIFF(CURDATE(), fi.due_date) ELSE 0 END) AS max_dpd,
            SUM(CASE WHEN fi.payment_status NOT IN ("PAID", "WAIVED_EARLY") AND fi.due_date < CURDATE()
                     THEN GREATEST(fi.installment_amount - fi.paid_amount, 0) ELSE 0 END) AS overdue_due_amount,
            SUM(CASE WHEN fi.payment_status NOT IN ("PAID", "WAIVED_EARLY") AND fi.due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_installments
        FROM fresher_hire_purchase hp
        JOIN fresher_installments fi
          ON fi.contract_code = hp.contract_code
         AND fi.is_deleted = 0
        LEFT JOIN fresher_customers c
          ON c.customer_code = hp.customer_code
         AND c.is_deleted = 0
        WHERE hp.is_deleted = 0' . $scope['sql'] . '
        GROUP BY hp.contract_code, hp.customer_code, hp.customer_name, hp.branch_code, c.phone_number
        HAVING overdue_installments > 0
        ORDER BY max_dpd DESC, overdue_due_amount DESC';
$stmtOverdue = db()->prepare($sql);
$stmtOverdue->execute($scope['params']);
$overdueRows = $stmtOverdue->fetchAll();

$todayScope = fresher_scope_clause('branch_code', 'fr_col_today');
$stmtTodayFollowups = db()->prepare(
    'SELECT contract_code, COUNT(*) AS followup_count, MAX(followup_date) AS last_followup_date
     FROM fresher_collections
     WHERE is_deleted = 0
       AND followup_date = :today' . $todayScope['sql'] . '
     GROUP BY contract_code'
);
$todayParams = $todayScope['params'];
$todayParams[':today'] = $today;
$stmtTodayFollowups->execute($todayParams);
$todayMap = [];
foreach ($stmtTodayFollowups->fetchAll() as $item) {
    $todayMap[(string)$item['contract_code']] = [
        'count' => (int)($item['followup_count'] ?? 0),
        'date' => (string)($item['last_followup_date'] ?? ''),
    ];
}

$rows = [];
foreach ($overdueRows as $row) {
    $contractCode = (string)$row['contract_code'];
    $feeQuote = fresher_collection_fee_quote($contractCode, $today);
    $penaltyQuote = fresher_late_penalty_quote($contractCode, $today);
    $collectionFeeAmount = max(0, fresher_decimal($feeQuote['recommended_fee'] ?? 0));
    $latePenaltyAmount = max(0, fresher_decimal($penaltyQuote['late_penalty_amount'] ?? 0));
    $overdueDueAmount = max(0, fresher_decimal($row['overdue_due_amount'] ?? 0));
    $todayFollowup = $todayMap[$contractCode] ?? ['count' => 0, 'date' => ''];

    $rows[] = $row + [
        'collection_fee_amount' => $collectionFeeAmount,
        'late_penalty_amount' => $latePenaltyAmount,
        'total_due_today' => round($overdueDueAmount + $collectionFeeAmount + $latePenaltyAmount, 2),
        'today_followup_count' => (int)$todayFollowup['count'],
        'today_followup_date' => (string)$todayFollowup['date'],
    ];
}

include __DIR__ . '/partials/head.php';
?>

<section class="card fr-card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3"><div class="fr-stat"><span>ลูกหนี้เกินกำหนดทั้งหมด</span><strong><?php echo number_format(count($rows)); ?></strong></div></div>
            <div class="col-md-3"><div class="fr-stat"><span>ยืนยันติดตามแล้ววันนี้</span><strong><?php echo number_format(array_sum(array_map(static fn($r) => $r['today_followup_count'] > 0 ? 1 : 0, $rows))); ?></strong></div></div>
            <div class="col-md-3"><div class="fr-stat"><span>ยอดค้างชำระรวม</span><strong><?php echo number_format(array_sum(array_map(static fn($r) => (float)$r['overdue_due_amount'], $rows)), 2); ?></strong></div></div>
            <div class="col-md-3"><div class="fr-stat"><span>รวมยอดต้องชำระวันนี้</span><strong><?php echo number_format(array_sum(array_map(static fn($r) => (float)$r['total_due_today'], $rows)), 2); ?></strong></div></div>
        </div>
    </div>
</section>

<section class="card fr-card mb-4">
    <div class="card-header bg-white">
        <strong>รายการติดตามทวงถามอัตโนมัติ (เฉพาะคนที่เกินกำหนดชำระ)</strong>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle js-fresher-datatable">
                <thead>
                <tr>
                    <th>เลขสัญญา</th>
                    <th>ลูกค้า</th>
                    <th>เบอร์โทร</th>
                    <th>สาขา</th>
                    <th>งวดค้าง</th>
                    <th>DPD สูงสุด</th>
                    <th>ค้างชำระ</th>
                    <th>ค่าทวงถาม</th>
                    <th>ดอกเบี้ยผิดนัด</th>
                    <th>รวมต้องชำระ</th>
                    <th>สถานะวันนี้</th>
                    <th>ยืนยัน</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                        $payload = [
                            'contract_code' => (string)$row['contract_code'],
                            'customer_name' => (string)$row['customer_name'],
                            'customer_code' => (string)$row['customer_code'],
                            'phone_number' => (string)$row['phone_number'],
                            'branch_code' => (string)$row['branch_code'],
                            'overdue_installments' => (int)$row['overdue_installments'],
                            'max_dpd' => (int)$row['max_dpd'],
                            'overdue_due_amount' => (float)$row['overdue_due_amount'],
                            'collection_fee_amount' => (float)$row['collection_fee_amount'],
                            'late_penalty_amount' => (float)$row['late_penalty_amount'],
                            'total_due_today' => (float)$row['total_due_today'],
                            'today_followup_count' => (int)$row['today_followup_count'],
                        ];
                        $payloadJson = h((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $alreadyFollowupToday = (int)$row['today_followup_count'] > 0;
                    ?>
                    <tr>
                        <td><code><?php echo h((string)$row['contract_code']); ?></code></td>
                        <td><?php echo h((string)$row['customer_name']); ?></td>
                        <td><?php echo h((string)($row['phone_number'] !== '' ? $row['phone_number'] : '-')); ?></td>
                        <td><?php echo h((string)$row['branch_code']); ?></td>
                        <td><?php echo number_format((int)$row['overdue_installments']); ?></td>
                        <td><?php echo number_format((int)$row['max_dpd']); ?> วัน</td>
                        <td><?php echo number_format((float)$row['overdue_due_amount'], 2); ?></td>
                        <td><?php echo number_format((float)$row['collection_fee_amount'], 2); ?></td>
                        <td><?php echo number_format((float)$row['late_penalty_amount'], 2); ?></td>
                        <td><strong><?php echo number_format((float)$row['total_due_today'], 2); ?></strong></td>
                        <td>
                            <?php if ($alreadyFollowupToday): ?>
                                <span class="badge text-bg-success">ยืนยันแล้ววันนี้</span>
                            <?php else: ?>
                                <span class="badge text-bg-warning">รอติดตาม</span>
                            <?php endif; ?>
                        </td>
                        <td class="fr-actions">
                            <button
                                class="btn btn-sm <?php echo $alreadyFollowupToday ? 'btn-outline-secondary' : 'btn-primary'; ?> js-btn-confirm-followup"
                                type="button"
                                data-followup="<?php echo $payloadJson; ?>"
                                <?php echo $alreadyFollowupToday ? 'disabled' : ''; ?>
                            >
                                ยืนยันติดตาม
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div class="modal fade" id="confirmFollowupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" id="confirmFollowupForm">
                <div class="modal-header">
                    <h5 class="modal-title">ยืนยันการติดตามทวงถาม</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="confirm_followup">
                    <input type="hidden" name="contract_code" id="followupContractCode">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">เลขสัญญา</label>
                            <input class="form-control" id="followupContractCodeView" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ลูกค้า</label>
                            <input class="form-control" id="followupCustomerNameView" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">เบอร์โทรสำหรับติดตาม</label>
                            <input class="form-control" id="followupPhoneView" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">DPD สูงสุด</label>
                            <input class="form-control" id="followupDpdView" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">ยอดรวมต้องชำระ</label>
                            <input class="form-control" id="followupTotalDueView" readonly>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">ผลการติดตาม</label>
                            <input class="form-control" name="outcome" id="followupOutcome" placeholder="เช่น โทรติดตามแล้ว ลูกค้ารับสาย">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">บันทึก Note การติดตาม</label>
                            <textarea class="form-control" name="note_text" id="followupNote" rows="3" placeholder="บันทึกรายละเอียดการโทรติดตาม/ข้อตกลง"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" type="submit">ยืนยันและบันทึกการติดตาม</button>
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">ปิด</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('confirmFollowupModal');
    const modal = modalElement ? new bootstrap.Modal(modalElement) : null;

    const followupContractCode = document.getElementById('followupContractCode');
    const followupContractCodeView = document.getElementById('followupContractCodeView');
    const followupCustomerNameView = document.getElementById('followupCustomerNameView');
    const followupPhoneView = document.getElementById('followupPhoneView');
    const followupDpdView = document.getElementById('followupDpdView');
    const followupTotalDueView = document.getElementById('followupTotalDueView');
    const followupOutcome = document.getElementById('followupOutcome');
    const followupNote = document.getElementById('followupNote');

    function formatMoney(value) {
        const amount = Number(value || 0);
        if (!Number.isFinite(amount)) {
            return '0.00';
        }
        return amount.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    document.querySelectorAll('.js-btn-confirm-followup').forEach(function (button) {
        button.addEventListener('click', function () {
            const payload = this.getAttribute('data-followup') || '{}';
            let data = {};
            try {
                data = JSON.parse(payload);
            } catch (error) {
                data = {};
            }

            if (followupContractCode) followupContractCode.value = String(data.contract_code || '');
            if (followupContractCodeView) followupContractCodeView.value = String(data.contract_code || '');
            if (followupCustomerNameView) followupCustomerNameView.value = String(data.customer_name || '');
            if (followupPhoneView) followupPhoneView.value = String(data.phone_number || '-');
            if (followupDpdView) followupDpdView.value = String(data.max_dpd || 0) + ' วัน';
            if (followupTotalDueView) followupTotalDueView.value = formatMoney(data.total_due_today || 0);
            if (followupOutcome) followupOutcome.value = 'โทรติดตามลูกค้าแล้ว';
            if (followupNote) followupNote.value = '';

            if (modal) {
                modal.show();
            }
        });
    });
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>

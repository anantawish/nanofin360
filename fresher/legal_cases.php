<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));
        $action = trim((string)($_POST['action'] ?? 'save'));
        $actor = current_user_name();
        $now = now_dt();

        if ($action === 'delete') {
            $id = fresher_int($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('ไม่พบรายการคดีที่ต้องการลบ');
            }
            $scope = fresher_scope_clause('branch_code', 'fr_case_del');
            $stmtFind = db()->prepare(
                'SELECT branch_code
                 FROM fresher_legal_cases
                 WHERE id = :id AND is_deleted = 0' . $scope['sql'] . '
                 LIMIT 1'
            );
            $params = $scope['params'];
            $params[':id'] = $id;
            $stmtFind->execute($params);
            $row = $stmtFind->fetch();
            if (!$row) {
                throw new RuntimeException('ไม่พบรายการคดีหรือไม่มีสิทธิ์');
            }
            assert_branch_in_current_scope((string)$row['branch_code']);

            $stmt = db()->prepare(
                'UPDATE fresher_legal_cases
                 SET is_deleted = 1,
                     deleted_by = :deleted_by,
                     deleted_at = :deleted_at,
                     updated_by = :updated_by,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                ':deleted_by' => $actor,
                ':deleted_at' => $now,
                ':updated_by' => $actor,
                ':updated_at' => $now,
                ':id' => $id,
            ]);
            add_flash('warning', 'ลบรายการคดีแบบ soft delete เรียบร้อย');
        } else {
            $id = fresher_int($_POST['id'] ?? 0);
            $contractCode = strtoupper(trim((string)($_POST['contract_code'] ?? '')));
            $filingDate = trim((string)($_POST['filing_date'] ?? date('Y-m-d')));
            $courtName = trim((string)($_POST['court_name'] ?? ''));
            $caseNo = trim((string)($_POST['case_no'] ?? ''));
            $claimAmount = max(0, fresher_decimal($_POST['claim_amount'] ?? 0));
            $paidAmount = max(0, fresher_decimal($_POST['paid_amount'] ?? 0));
            $paidDate = trim((string)($_POST['paid_date'] ?? ''));
            $caseStatus = strtoupper(trim((string)($_POST['case_status'] ?? 'OPEN')));
            $note = trim((string)($_POST['note_text'] ?? ''));

            if ($contractCode === '') {
                throw new RuntimeException('กรุณาเลือกสัญญาที่ต้องการฟ้องคดี');
            }
            $contract = fresher_contract_row($contractCode);
            if (!$contract) {
                throw new RuntimeException('ไม่พบข้อมูลสัญญาหรือไม่มีสิทธิ์');
            }
            $branchCode = strtoupper(trim((string)$contract['branch_code']));
            assert_branch_in_current_scope($branchCode);

            if ($id > 0) {
                $scope = fresher_scope_clause('branch_code', 'fr_case_up');
                $stmtFind = db()->prepare(
                    'SELECT branch_code
                     FROM fresher_legal_cases
                     WHERE id = :id AND is_deleted = 0' . $scope['sql'] . '
                     LIMIT 1'
                );
                $params = $scope['params'];
                $params[':id'] = $id;
                $stmtFind->execute($params);
                $existing = $stmtFind->fetch();
                if (!$existing) {
                    throw new RuntimeException('ไม่พบรายการคดีที่ต้องการแก้ไข');
                }
                assert_branch_in_current_scope((string)$existing['branch_code']);

                $stmt = db()->prepare(
                    'UPDATE fresher_legal_cases
                     SET contract_code = :contract_code,
                         customer_code = :customer_code,
                         customer_name = :customer_name,
                         branch_code = :branch_code,
                         filing_date = :filing_date,
                         court_name = :court_name,
                         case_no = :case_no,
                         claim_amount = :claim_amount,
                         paid_amount = :paid_amount,
                         paid_date = :paid_date,
                         case_status = :case_status,
                         note_text = :note_text,
                         updated_by = :updated_by,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':contract_code' => $contractCode,
                    ':customer_code' => (string)$contract['customer_code'],
                    ':customer_name' => (string)$contract['customer_name'],
                    ':branch_code' => $branchCode,
                    ':filing_date' => $filingDate !== '' ? $filingDate : null,
                    ':court_name' => $courtName,
                    ':case_no' => $caseNo,
                    ':claim_amount' => $claimAmount,
                    ':paid_amount' => $paidAmount,
                    ':paid_date' => $paidDate !== '' ? $paidDate : null,
                    ':case_status' => $caseStatus,
                    ':note_text' => $note,
                    ':updated_by' => $actor,
                    ':updated_at' => $now,
                    ':id' => $id,
                ]);
                add_flash('success', 'แก้ไขข้อมูลคดีเรียบร้อย');
            } else {
                $caseCode = fresher_generate_code('FRCASE');
                $stmt = db()->prepare(
                    'INSERT INTO fresher_legal_cases (
                        case_code, contract_code, customer_code, customer_name, branch_code,
                        filing_date, court_name, case_no, claim_amount,
                        paid_amount, paid_date, case_status, note_text,
                        is_deleted, created_by, created_at
                     ) VALUES (
                        :case_code, :contract_code, :customer_code, :customer_name, :branch_code,
                        :filing_date, :court_name, :case_no, :claim_amount,
                        :paid_amount, :paid_date, :case_status, :note_text,
                        0, :created_by, :created_at
                     )'
                );
                $stmt->execute([
                    ':case_code' => $caseCode,
                    ':contract_code' => $contractCode,
                    ':customer_code' => (string)$contract['customer_code'],
                    ':customer_name' => (string)$contract['customer_name'],
                    ':branch_code' => $branchCode,
                    ':filing_date' => $filingDate !== '' ? $filingDate : null,
                    ':court_name' => $courtName,
                    ':case_no' => $caseNo,
                    ':claim_amount' => $claimAmount,
                    ':paid_amount' => $paidAmount,
                    ':paid_date' => $paidDate !== '' ? $paidDate : null,
                    ':case_status' => $caseStatus,
                    ':note_text' => $note,
                    ':created_by' => $actor,
                    ':created_at' => $now,
                ]);
                add_flash('success', 'เพิ่มรายการคดีเรียบร้อย');
            }
        }
    } catch (Throwable $e) {
        add_flash('danger', 'บันทึกข้อมูลคดีไม่สำเร็จ: ' . $e->getMessage());
    }

    redirect_to(fresher_base_url('legal_cases.php'));
}

$currentFresherPage = 'legal_cases';
$pageTitle = 'ระบบฟ้อง/ชำระคดี';

$contractOptions = fresher_contract_options();

$editId = fresher_int($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $scope = fresher_scope_clause('branch_code', 'fr_case_edit');
    $stmtEdit = db()->prepare(
        'SELECT *
         FROM fresher_legal_cases
         WHERE id = :id AND is_deleted = 0' . $scope['sql'] . '
         LIMIT 1'
    );
    $params = $scope['params'];
    $params[':id'] = $editId;
    $stmtEdit->execute($params);
    $editRow = $stmtEdit->fetch() ?: null;
}

$scope = fresher_scope_clause('branch_code', 'fr_case_list');
$stmtRows = db()->prepare(
    'SELECT *
     FROM fresher_legal_cases
     WHERE is_deleted = 0' . $scope['sql'] . '
     ORDER BY id DESC'
);
$stmtRows->execute($scope['params']);
$rows = $stmtRows->fetchAll();

include __DIR__ . '/partials/head.php';
?>

<section class="card fr-card mb-4">
    <div class="card-header bg-white"><strong><?php echo $editRow ? 'แก้ไขรายการคดี' : 'เพิ่มรายการฟ้อง/ชำระคดี'; ?></strong></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="save">
            <?php if ($editRow): ?>
                <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
            <?php endif; ?>

            <div class="col-md-4">
                <label class="form-label">สัญญา *</label>
                <select class="form-select" name="contract_code" required>
                    <option value="">-- เลือกสัญญา --</option>
                    <?php foreach ($contractOptions as $contract): ?>
                        <?php $code = (string)$contract['contract_code']; ?>
                        <option value="<?php echo h($code); ?>" <?php echo $code === (string)($editRow['contract_code'] ?? '') ? 'selected' : ''; ?>>
                            <?php echo h($code . ' - ' . (string)$contract['customer_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">วันที่ยื่นฟ้อง</label>
                <input class="form-control" type="date" name="filing_date" value="<?php echo h((string)($editRow['filing_date'] ?? date('Y-m-d'))); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">ศาล</label>
                <input class="form-control" name="court_name" value="<?php echo h((string)($editRow['court_name'] ?? '')); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">เลขคดี</label>
                <input class="form-control" name="case_no" value="<?php echo h((string)($editRow['case_no'] ?? '')); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">ยอดฟ้อง</label>
                <input class="form-control" type="number" step="0.01" name="claim_amount" value="<?php echo h((string)($editRow['claim_amount'] ?? '0')); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">ยอดชำระคดี</label>
                <input class="form-control" type="number" step="0.01" name="paid_amount" value="<?php echo h((string)($editRow['paid_amount'] ?? '0')); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">วันที่ชำระคดี</label>
                <input class="form-control" type="date" name="paid_date" value="<?php echo h((string)($editRow['paid_date'] ?? '')); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">สถานะคดี</label>
                <select class="form-select" name="case_status">
                    <?php $statusValue = strtoupper((string)($editRow['case_status'] ?? 'OPEN')); ?>
                    <?php foreach (['OPEN', 'IN_COURT', 'SETTLED', 'PAID', 'CLOSED'] as $status): ?>
                        <option value="<?php echo h($status); ?>" <?php echo $statusValue === $status ? 'selected' : ''; ?>><?php echo h($status); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">หมายเหตุ</label>
                <input class="form-control" name="note_text" value="<?php echo h((string)($editRow['note_text'] ?? '')); ?>">
            </div>

            <div class="col-12 fr-actions">
                <button class="btn btn-primary" type="submit"><?php echo $editRow ? 'บันทึกการแก้ไข' : 'เพิ่มรายการคดี'; ?></button>
                <?php if ($editRow): ?>
                    <a class="btn btn-outline-secondary" href="<?php echo h(fresher_base_url('legal_cases.php')); ?>">ยกเลิกแก้ไข</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</section>

<section class="card fr-card mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 js-fresher-datatable">
            <thead>
            <tr>
                <th>ID</th>
                <th>รหัสคดี</th>
                <th>สัญญา</th>
                <th>ลูกค้า</th>
                <th>ศาล/เลขคดี</th>
                <th>ยอดฟ้อง</th>
                <th>ชำระคดี</th>
                <th>สถานะคดี</th>
                <th>จัดการ</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?php echo (int)$row['id']; ?></td>
                    <td><code><?php echo h((string)$row['case_code']); ?></code></td>
                    <td><?php echo h((string)$row['contract_code']); ?></td>
                    <td><?php echo h((string)$row['customer_name']); ?></td>
                    <td><?php echo h((string)$row['court_name'] . ' / ' . (string)$row['case_no']); ?></td>
                    <td><?php echo number_format((float)$row['claim_amount'], 2); ?></td>
                    <td><?php echo number_format((float)$row['paid_amount'], 2); ?></td>
                    <td><?php echo h((string)$row['case_status']); ?></td>
                    <td class="fr-actions">
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo h(fresher_base_url('legal_cases.php?edit=' . (int)$row['id'])); ?>">แก้ไข</a>
                        <form method="post" class="js-confirm-delete">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit">ลบ</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>

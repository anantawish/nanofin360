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
                throw new RuntimeException('ไม่พบรายการประเมินที่ต้องการลบ');
            }

            $scope = fresher_scope_clause('branch_code', 'fr_aff_del');
            $stmtFind = db()->prepare(
                'SELECT branch_code
                 FROM fresher_affordability
                 WHERE id = :id AND is_deleted = 0' . $scope['sql'] . '
                 LIMIT 1'
            );
            $params = $scope['params'];
            $params[':id'] = $id;
            $stmtFind->execute($params);
            $row = $stmtFind->fetch();
            if (!$row) {
                throw new RuntimeException('ไม่พบรายการประเมินหรือไม่มีสิทธิ์ลบ');
            }
            assert_branch_in_current_scope((string)$row['branch_code']);

            $stmtDel = db()->prepare(
                'UPDATE fresher_affordability
                 SET is_deleted = 1,
                     deleted_by = :deleted_by,
                     deleted_at = :deleted_at,
                     updated_by = :updated_by,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmtDel->execute([
                ':deleted_by' => $actor,
                ':deleted_at' => $now,
                ':updated_by' => $actor,
                ':updated_at' => $now,
                ':id' => $id,
            ]);
            add_flash('warning', 'ลบรายการประเมินแบบ soft delete เรียบร้อย');
        } else {
            $id = fresher_int($_POST['id'] ?? 0);
            $customerCode = strtoupper(trim((string)($_POST['customer_code'] ?? '')));
            $assessmentDate = trim((string)($_POST['assessment_date'] ?? date('Y-m-d')));
            $income = max(0, fresher_decimal($_POST['monthly_income'] ?? 0));
            $occupationExpense = max(0, fresher_decimal($_POST['occupation_expense'] ?? 0));
            $familyExpense = max(0, fresher_decimal($_POST['family_expense'] ?? 0));
            $existingDebt = max(0, fresher_decimal($_POST['existing_debt'] ?? 0));
            $attitudeScore = max(0, min(100, fresher_decimal($_POST['attitude_score'] ?? 0)));
            $note = trim((string)($_POST['note_text'] ?? ''));

            if ($customerCode === '') {
                throw new RuntimeException('กรุณาเลือกรหัสลูกค้าที่ต้องการประเมิน');
            }

            $customer = fresher_customer_row($customerCode);
            if (!$customer) {
                throw new RuntimeException('ไม่พบข้อมูลลูกค้าในระบบ');
            }

            $branchCode = strtoupper(trim((string)($customer['branch_code'] ?? '')));
            assert_branch_in_current_scope($branchCode);

            if ($income <= 0) {
                $income = max(0, fresher_decimal($customer['monthly_income'] ?? 0));
            }
            if ($attitudeScore <= 0) {
                $attitudeScore = max(0, min(100, fresher_decimal($customer['attitude_score'] ?? 0)));
            }

            $documentMetrics = fresher_customer_document_metrics($customer);
            $documentScore = (float)$documentMetrics['score'];
            $collateralFactor = (float)$documentMetrics['factor'];

            $netCapacity = max(0, $income - $occupationExpense - $familyExpense - $existingDebt);
            $attitudeFactor = max(0.65, min(1.15, 0.65 + ($attitudeScore / 200)));
            $recommendedInstallment = round($netCapacity * $attitudeFactor * $collateralFactor * 0.8, 2);
            $recommendedLimit = round($recommendedInstallment * 24, 2);

            $resultStatus = 'REVIEW';
            if ($recommendedInstallment < 1500 || $recommendedLimit < 20000) {
                $resultStatus = 'REJECT';
            } elseif ($recommendedInstallment >= 3000 && $attitudeScore >= 50 && $documentScore >= 40) {
                $resultStatus = 'APPROVE';
            }

            if ($id > 0) {
                $scope = fresher_scope_clause('branch_code', 'fr_aff_up');
                $stmtFind = db()->prepare(
                    'SELECT branch_code
                     FROM fresher_affordability
                     WHERE id = :id AND is_deleted = 0' . $scope['sql'] . '
                     LIMIT 1'
                );
                $params = $scope['params'];
                $params[':id'] = $id;
                $stmtFind->execute($params);
                $existing = $stmtFind->fetch();
                if (!$existing) {
                    throw new RuntimeException('ไม่พบรายการประเมินที่ต้องการแก้ไข');
                }
                assert_branch_in_current_scope((string)$existing['branch_code']);

                $stmt = db()->prepare(
                    'UPDATE fresher_affordability
                     SET customer_code = :customer_code,
                         customer_name = :customer_name,
                         branch_code = :branch_code,
                         monthly_income = :monthly_income,
                         occupation_expense = :occupation_expense,
                         family_expense = :family_expense,
                         existing_debt = :existing_debt,
                         attitude_score = :attitude_score,
                         document_score = :document_score,
                         collateral_factor = :collateral_factor,
                         net_capacity = :net_capacity,
                         recommended_installment = :recommended_installment,
                         recommended_limit = :recommended_limit,
                         result_status = :result_status,
                         note_text = :note_text,
                         assessment_date = :assessment_date,
                         updated_by = :updated_by,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':customer_code' => $customerCode,
                    ':customer_name' => trim((string)$customer['first_name'] . ' ' . (string)$customer['last_name']),
                    ':branch_code' => $branchCode,
                    ':monthly_income' => $income,
                    ':occupation_expense' => $occupationExpense,
                    ':family_expense' => $familyExpense,
                    ':existing_debt' => $existingDebt,
                    ':attitude_score' => $attitudeScore,
                    ':document_score' => $documentScore,
                    ':collateral_factor' => $collateralFactor,
                    ':net_capacity' => $netCapacity,
                    ':recommended_installment' => $recommendedInstallment,
                    ':recommended_limit' => $recommendedLimit,
                    ':result_status' => $resultStatus,
                    ':note_text' => $note,
                    ':assessment_date' => $assessmentDate !== '' ? $assessmentDate : null,
                    ':updated_by' => $actor,
                    ':updated_at' => $now,
                    ':id' => $id,
                ]);
                add_flash('success', 'แก้ไขผลประเมินความสามารถผ่อนชำระเรียบร้อย');
            } else {
                $assessmentCode = fresher_generate_code('FRAFF');
                $stmt = db()->prepare(
                    'INSERT INTO fresher_affordability (
                        assessment_code, customer_code, customer_name, branch_code,
                        monthly_income, occupation_expense, family_expense, existing_debt,
                        attitude_score, document_score, collateral_factor,
                        net_capacity, recommended_installment, recommended_limit,
                        result_status, note_text, assessment_date,
                        is_deleted, created_by, created_at
                     ) VALUES (
                        :assessment_code, :customer_code, :customer_name, :branch_code,
                        :monthly_income, :occupation_expense, :family_expense, :existing_debt,
                        :attitude_score, :document_score, :collateral_factor,
                        :net_capacity, :recommended_installment, :recommended_limit,
                        :result_status, :note_text, :assessment_date,
                        0, :created_by, :created_at
                     )'
                );
                $stmt->execute([
                    ':assessment_code' => $assessmentCode,
                    ':customer_code' => $customerCode,
                    ':customer_name' => trim((string)$customer['first_name'] . ' ' . (string)$customer['last_name']),
                    ':branch_code' => $branchCode,
                    ':monthly_income' => $income,
                    ':occupation_expense' => $occupationExpense,
                    ':family_expense' => $familyExpense,
                    ':existing_debt' => $existingDebt,
                    ':attitude_score' => $attitudeScore,
                    ':document_score' => $documentScore,
                    ':collateral_factor' => $collateralFactor,
                    ':net_capacity' => $netCapacity,
                    ':recommended_installment' => $recommendedInstallment,
                    ':recommended_limit' => $recommendedLimit,
                    ':result_status' => $resultStatus,
                    ':note_text' => $note,
                    ':assessment_date' => $assessmentDate !== '' ? $assessmentDate : null,
                    ':created_by' => $actor,
                    ':created_at' => $now,
                ]);
                add_flash('success', 'เพิ่มผลประเมินความสามารถผ่อนชำระเรียบร้อย');
            }
        }
    } catch (Throwable $e) {
        add_flash('danger', 'บันทึกข้อมูลไม่สำเร็จ: ' . $e->getMessage());
    }

    redirect_to(fresher_base_url('affordability.php'));
}

$currentFresherPage = 'affordability';
$pageTitle = 'ระบบประเมินความสามารถผ่อนชำระ';

$customerOptions = fresher_customer_options();
$customerMap = [];
foreach ($customerOptions as $customer) {
    $code = strtoupper(trim((string)($customer['customer_code'] ?? '')));
    if ($code !== '') {
        $customerMap[$code] = $customer;
    }
}

$customerLookupData = [];
foreach ($customerMap as $code => $customer) {
    $docMetrics = fresher_customer_document_metrics($customer);
    $customerLookupData[$code] = [
        'name' => trim((string)($customer['first_name'] ?? '') . ' ' . (string)($customer['last_name'] ?? '')),
        'branch' => (string)($customer['branch_code'] ?? ''),
        'income' => (float)($customer['monthly_income'] ?? 0),
        'attitude' => (float)($customer['attitude_score'] ?? 0),
        'document_score' => (float)$docMetrics['score'],
        'collateral_factor' => (float)$docMetrics['factor'],
        'document_count' => (int)$docMetrics['count'],
        'document_max' => (int)$docMetrics['max'],
    ];
}

$editId = fresher_int($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $scope = fresher_scope_clause('branch_code', 'fr_aff_edit');
    $stmtEdit = db()->prepare(
        'SELECT *
         FROM fresher_affordability
         WHERE id = :id AND is_deleted = 0' . $scope['sql'] . '
         LIMIT 1'
    );
    $params = $scope['params'];
    $params[':id'] = $editId;
    $stmtEdit->execute($params);
    $editRow = $stmtEdit->fetch() ?: null;
}

$customerFilter = strtoupper(trim((string)($_GET['customer_filter'] ?? ($_GET['customer_code'] ?? ''))));
$scope = fresher_scope_clause('branch_code', 'fr_aff_list');
$sqlRows = 'SELECT *
            FROM fresher_affordability
            WHERE is_deleted = 0' . $scope['sql'];
$paramsRows = $scope['params'];
if ($customerFilter !== '') {
    $sqlRows .= ' AND (customer_code LIKE :customer_filter OR customer_name LIKE :customer_filter)';
    $paramsRows[':customer_filter'] = '%' . $customerFilter . '%';
}
$sqlRows .= ' ORDER BY id DESC';
$stmtRows = db()->prepare($sqlRows);
$stmtRows->execute($paramsRows);
$rows = $stmtRows->fetchAll();

include __DIR__ . '/partials/head.php';
?>

<section class="card fr-card mb-4">
    <div class="card-body d-flex flex-wrap gap-2">
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#affordabilityModal">
            + เพิ่มผลประเมิน
        </button>
        <?php if ($editRow): ?>
            <a class="btn btn-outline-secondary" href="<?php echo h(fresher_base_url('affordability.php')); ?>">ยกเลิกแก้ไข</a>
        <?php endif; ?>
    </div>
</section>

<div class="modal fade" id="affordabilityModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5 mb-0"><?php echo $editRow ? 'แก้ไขผลประเมิน' : 'เพิ่มผลประเมินความสามารถผ่อนชำระ'; ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
        <form method="post" class="row g-3">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="save">
            <?php if ($editRow): ?>
                <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
            <?php endif; ?>

            <div class="col-md-4">
                <label class="form-label">รหัสลูกค้า *</label>
                <select class="form-select js-aff-customer-autocomplete" name="customer_code" id="aff_customer_code" required>
                    <option value="">-- เลือกลูกค้า --</option>
                    <?php foreach ($customerOptions as $customer): ?>
                        <?php
                            $code = (string)$customer['customer_code'];
                            $fullName = trim((string)$customer['first_name'] . ' ' . (string)$customer['last_name']);
                            $cid = trim((string)($customer['cid_tax_id'] ?? ''));
                            $optionText = $code . ' - ' . $fullName . ($cid !== '' ? ' - ' . $cid : '');
                        ?>
                        <option
                            value="<?php echo h($code); ?>"
                            <?php echo ((string)($editRow['customer_code'] ?? '') === $code) ? 'selected' : ''; ?>
                        >
                            <?php echo h($optionText); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">พิมพ์ค้นหาได้ทั้งรหัสลูกค้า ชื่อ นามสกุล หรือเลขบัตรประชาชน</small>
            </div>
            <div class="col-md-3">
                <label class="form-label">ชื่อลูกค้า (จากระบบ)</label>
                <input class="form-control" id="aff_customer_name_preview" value="<?php echo h((string)($editRow['customer_name'] ?? '')); ?>" readonly>
            </div>
            <div class="col-md-2">
                <label class="form-label">สาขา</label>
                <input class="form-control" id="aff_customer_branch_preview" value="<?php echo h((string)($editRow['branch_code'] ?? '')); ?>" readonly>
            </div>
            <div class="col-md-2">
                <label class="form-label">วันที่ประเมิน</label>
                <input class="form-control" type="date" name="assessment_date" value="<?php echo h((string)($editRow['assessment_date'] ?? date('Y-m-d'))); ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label">รายได้/เดือน</label>
                <input class="form-control" id="aff_monthly_income" type="number" step="0.01" name="monthly_income" value="<?php echo h((string)($editRow['monthly_income'] ?? '0')); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">ค่าใช้จ่ายอาชีพ</label>
                <input class="form-control" type="number" step="0.01" name="occupation_expense" value="<?php echo h((string)($editRow['occupation_expense'] ?? '0')); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">ค่าใช้จ่ายครอบครัว</label>
                <input class="form-control" type="number" step="0.01" name="family_expense" value="<?php echo h((string)($editRow['family_expense'] ?? '0')); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">หนี้เดิม/เดือน</label>
                <input class="form-control" type="number" step="0.01" name="existing_debt" value="<?php echo h((string)($editRow['existing_debt'] ?? '0')); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">คะแนนทัศนคติ</label>
                <input class="form-control" id="aff_attitude_score" type="number" step="0.01" min="0" max="100" name="attitude_score" value="<?php echo h((string)($editRow['attitude_score'] ?? '0')); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">คะแนนเอกสาร</label>
                <input class="form-control" id="aff_document_score" type="number" step="0.01" min="0" max="100" value="<?php echo h((string)($editRow['document_score'] ?? '0')); ?>" readonly>
            </div>
            <div class="col-md-2">
                <label class="form-label">ปัจจัยหลักประกัน</label>
                <input class="form-control" id="aff_collateral_factor" type="number" step="0.0001" value="<?php echo h((string)($editRow['collateral_factor'] ?? '0.75')); ?>" readonly>
            </div>
            <div class="col-md-4">
                <label class="form-label">หมายเหตุ</label>
                <input class="form-control" name="note_text" value="<?php echo h((string)($editRow['note_text'] ?? '')); ?>">
            </div>

            <div class="col-12 fr-actions">
                <button class="btn btn-primary" type="submit"><?php echo $editRow ? 'บันทึกการแก้ไข' : 'คำนวณและบันทึก'; ?></button>
                <?php if ($editRow): ?>
                    <a class="btn btn-outline-secondary" href="<?php echo h(fresher_base_url('affordability.php')); ?>">ปิดการแก้ไข</a>
                <?php endif; ?>
                <button class="btn btn-outline-dark" type="button" data-bs-dismiss="modal">ปิด</button>
            </div>
        </form>
            </div>
        </div>
    </div>
</div>

<section class="card fr-card mb-4">
    <div class="card-body border-bottom">
        <form method="get" class="row g-2">
            <div class="col-md-6">
                <label class="form-label">ค้นหาผลประเมินจากรหัสลูกค้า / ชื่อ / นามสกุล</label>
                <select class="form-select js-aff-filter-autocomplete" name="customer_filter" id="aff_customer_filter">
                    <option value="">-- เลือกลูกค้า --</option>
                    <?php foreach ($customerOptions as $customer): ?>
                        <?php
                            $code = strtoupper(trim((string)($customer['customer_code'] ?? '')));
                            if ($code === '') { continue; }
                            $fullName = trim((string)($customer['first_name'] ?? '') . ' ' . (string)($customer['last_name'] ?? ''));
                            $cid = trim((string)($customer['cid_tax_id'] ?? ''));
                            $optionText = $code . ' - ' . $fullName . ($cid !== '' ? ' - ' . $cid : '');
                        ?>
                        <option value="<?php echo h($code); ?>" <?php echo $customerFilter === $code ? 'selected' : ''; ?>>
                            <?php echo h($optionText); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end fr-actions">
                <button class="btn btn-outline-primary" type="submit">ค้นหา</button>
                <?php if ($customerFilter !== ''): ?>
                    <a class="btn btn-outline-secondary" href="<?php echo h(fresher_base_url('affordability.php')); ?>">ล้างตัวกรอง</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 js-fresher-datatable">
            <thead>
            <tr>
                <th>ID</th>
                <th>รหัสประเมิน</th>
                <th>ลูกค้า</th>
                <th>สาขา</th>
                <th>รายได้/เดือน</th>
                <th>ความสามารถสุทธิ</th>
                <th>ค่างวดแนะนำ</th>
                <th>วงเงินแนะนำ</th>
                <th>คะแนนเอกสาร</th>
                <th>ผลประเมิน</th>
                <th>จัดการ</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?php echo (int)$row['id']; ?></td>
                    <td><code><?php echo h((string)$row['assessment_code']); ?></code></td>
                    <td><?php echo h((string)$row['customer_code'] . ' - ' . (string)$row['customer_name']); ?></td>
                    <td><?php echo h((string)$row['branch_code']); ?></td>
                    <td><?php echo number_format((float)$row['monthly_income'], 2); ?></td>
                    <td><?php echo number_format((float)$row['net_capacity'], 2); ?></td>
                    <td><?php echo number_format((float)$row['recommended_installment'], 2); ?></td>
                    <td><?php echo number_format((float)$row['recommended_limit'], 2); ?></td>
                    <td><?php echo number_format((float)$row['document_score'], 2); ?></td>
                    <td><?php echo h((string)$row['result_status']); ?></td>
                    <td class="fr-actions">
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo h(fresher_base_url('affordability.php?edit=' . (int)$row['id'])); ?>">แก้ไข</a>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('affordabilityModal');
    if (modalElement && typeof bootstrap !== 'undefined') {
        const affModal = new bootstrap.Modal(modalElement);
        const shouldOpenModal = <?php echo $editRow ? 'true' : 'false'; ?>;
        if (shouldOpenModal) {
            affModal.show();
        }
    }

    const customerMap = <?php echo json_encode($customerLookupData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const codeInput = document.getElementById('aff_customer_code');
    const modalElementJq = window.jQuery ? window.jQuery('#affordabilityModal') : null;
    const namePreview = document.getElementById('aff_customer_name_preview');
    const branchPreview = document.getElementById('aff_customer_branch_preview');
    const incomeInput = document.getElementById('aff_monthly_income');
    const attitudeInput = document.getElementById('aff_attitude_score');
    const documentScoreInput = document.getElementById('aff_document_score');
    const collateralFactorInput = document.getElementById('aff_collateral_factor');

    if (!codeInput) {
        return;
    }

    function initCustomerAutocomplete() {
        if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.select2) {
            return;
        }
        const $input = window.jQuery(codeInput);
        if ($input.data('select2')) {
            return;
        }
        $input.select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: modalElementJq,
            placeholder: 'พิมพ์ค้นหารหัสลูกค้า/ชื่อ/นามสกุล',
            allowClear: true,
            language: {
                noResults: function () { return 'ไม่พบข้อมูลลูกค้า'; },
                searching: function () { return 'กำลังค้นหา...'; },
                inputTooShort: function () { return 'พิมพ์เพื่อค้นหา'; }
            }
        });
    }

    function initFilterAutocomplete() {
        if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.select2) {
            return;
        }
        const $filter = window.jQuery('#aff_customer_filter');
        if ($filter.length === 0 || $filter.data('select2')) {
            return;
        }
        $filter.select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'พิมพ์ชื่อ/นามสกุล หรือรหัสลูกค้า',
            allowClear: true,
            language: {
                noResults: function () { return 'ไม่พบลูกค้า'; },
                searching: function () { return 'กำลังค้นหา...'; },
                inputTooShort: function () { return 'พิมพ์เพื่อค้นหา'; }
            }
        });
    }

    function setDefaultPreview() {
        if (namePreview) {
            namePreview.value = '';
        }
        if (branchPreview) {
            branchPreview.value = '';
        }
        if (documentScoreInput) {
            documentScoreInput.value = '0.00';
        }
        if (collateralFactorInput) {
            collateralFactorInput.value = '0.7500';
        }
    }

    function updateCustomerPreview() {
        const code = String(codeInput.value || '').trim().toUpperCase();
        if (code === '') {
            codeInput.setCustomValidity('');
            setDefaultPreview();
            return;
        }

        const customer = customerMap[code] || null;
        if (!customer) {
            codeInput.setCustomValidity('ไม่พบรหัสลูกค้าในระบบ');
            setDefaultPreview();
            return;
        }

        codeInput.value = code;
        codeInput.setCustomValidity('');

        if (namePreview) {
            namePreview.value = customer.name || '';
        }
        if (branchPreview) {
            branchPreview.value = customer.branch || '';
        }
        if (incomeInput && (String(incomeInput.value || '').trim() === '' || Number(incomeInput.value) === 0)) {
            incomeInput.value = Number(customer.income || 0).toFixed(2);
        }
        if (attitudeInput && (String(attitudeInput.value || '').trim() === '' || Number(attitudeInput.value) === 0)) {
            attitudeInput.value = Number(customer.attitude || 0).toFixed(2);
        }
        if (documentScoreInput) {
            documentScoreInput.value = Number(customer.document_score || 0).toFixed(2);
        }
        if (collateralFactorInput) {
            collateralFactorInput.value = Number(customer.collateral_factor || 0.75).toFixed(4);
        }
    }

    codeInput.addEventListener('change', updateCustomerPreview);
    if (modalElement) {
        modalElement.addEventListener('shown.bs.modal', function () {
            initCustomerAutocomplete();
            updateCustomerPreview();
        });
    }
    initCustomerAutocomplete();
    initFilterAutocomplete();
    updateCustomerPreview();
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>

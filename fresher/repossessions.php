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
                throw new RuntimeException('ไม่พบรายการยึดคืนที่ต้องการลบ');
            }

            $scope = fresher_scope_clause('branch_code', 'fr_repo_del');
            $stmtFind = db()->prepare(
                'SELECT branch_code
                 FROM fresher_repossessions
                 WHERE id = :id AND is_deleted = 0' . $scope['sql'] . '
                 LIMIT 1'
            );
            $params = $scope['params'];
            $params[':id'] = $id;
            $stmtFind->execute($params);
            $row = $stmtFind->fetch();
            if (!$row) {
                throw new RuntimeException('ไม่พบรายการยึดคืนหรือไม่มีสิทธิ์ลบ');
            }
            assert_branch_in_current_scope((string)$row['branch_code']);

            $stmt = db()->prepare(
                'UPDATE fresher_repossessions
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
            add_flash('warning', 'ลบรายการยึดคืนแบบ soft delete เรียบร้อย');
        } else {
            $id = fresher_int($_POST['id'] ?? 0);
            $contractCode = strtoupper(trim((string)($_POST['contract_code'] ?? '')));
            $repossessionDate = trim((string)($_POST['repossession_date'] ?? date('Y-m-d')));
            $assetCondition = trim((string)($_POST['asset_condition'] ?? ''));
            $storageLocation = trim((string)($_POST['storage_location'] ?? ''));
            $appraisedValue = max(0, fresher_decimal($_POST['appraised_value'] ?? 0));
            $saleValue = max(0, fresher_decimal($_POST['sale_value'] ?? 0));
            $repoStatus = strtoupper(trim((string)($_POST['repo_status'] ?? 'PENDING')));
            $note = trim((string)($_POST['note_text'] ?? ''));

            if ($contractCode === '') {
                throw new RuntimeException('กรุณาเลือกสัญญาที่ต้องการยึดคืน');
            }

            $contract = fresher_contract_row($contractCode);
            if (!$contract) {
                throw new RuntimeException('ไม่พบข้อมูลสัญญาหรือไม่มีสิทธิ์ใช้งาน');
            }

            $branchCode = strtoupper(trim((string)$contract['branch_code']));
            assert_branch_in_current_scope($branchCode);

            if ($id > 0) {
                $scope = fresher_scope_clause('branch_code', 'fr_repo_up');
                $stmtFind = db()->prepare(
                    'SELECT branch_code
                     FROM fresher_repossessions
                     WHERE id = :id AND is_deleted = 0' . $scope['sql'] . '
                     LIMIT 1'
                );
                $params = $scope['params'];
                $params[':id'] = $id;
                $stmtFind->execute($params);
                $existing = $stmtFind->fetch();
                if (!$existing) {
                    throw new RuntimeException('ไม่พบรายการยึดคืนที่ต้องการแก้ไข');
                }
                assert_branch_in_current_scope((string)$existing['branch_code']);

                $stmt = db()->prepare(
                    'UPDATE fresher_repossessions
                     SET contract_code = :contract_code,
                         customer_code = :customer_code,
                         customer_name = :customer_name,
                         branch_code = :branch_code,
                         repossession_date = :repossession_date,
                         asset_condition = :asset_condition,
                         storage_location = :storage_location,
                         appraised_value = :appraised_value,
                         sale_value = :sale_value,
                         repo_status = :repo_status,
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
                    ':repossession_date' => $repossessionDate !== '' ? $repossessionDate : null,
                    ':asset_condition' => $assetCondition,
                    ':storage_location' => $storageLocation,
                    ':appraised_value' => $appraisedValue,
                    ':sale_value' => $saleValue,
                    ':repo_status' => $repoStatus,
                    ':note_text' => $note,
                    ':updated_by' => $actor,
                    ':updated_at' => $now,
                    ':id' => $id,
                ]);
                add_flash('success', 'แก้ไขรายการยึดคืนเรียบร้อย');
            } else {
                $repoCode = fresher_generate_code('FRREP');
                $stmt = db()->prepare(
                    'INSERT INTO fresher_repossessions (
                        repo_code, contract_code, customer_code, customer_name, branch_code,
                        repossession_date, asset_condition, storage_location, appraised_value,
                        sale_value, repo_status, note_text, is_deleted, created_by, created_at
                     ) VALUES (
                        :repo_code, :contract_code, :customer_code, :customer_name, :branch_code,
                        :repossession_date, :asset_condition, :storage_location, :appraised_value,
                        :sale_value, :repo_status, :note_text, 0, :created_by, :created_at
                     )'
                );
                $stmt->execute([
                    ':repo_code' => $repoCode,
                    ':contract_code' => $contractCode,
                    ':customer_code' => (string)$contract['customer_code'],
                    ':customer_name' => (string)$contract['customer_name'],
                    ':branch_code' => $branchCode,
                    ':repossession_date' => $repossessionDate !== '' ? $repossessionDate : null,
                    ':asset_condition' => $assetCondition,
                    ':storage_location' => $storageLocation,
                    ':appraised_value' => $appraisedValue,
                    ':sale_value' => $saleValue,
                    ':repo_status' => $repoStatus,
                    ':note_text' => $note,
                    ':created_by' => $actor,
                    ':created_at' => $now,
                ]);
                add_flash('success', 'เพิ่มรายการยึดคืนเรียบร้อย');
            }
        }
    } catch (Throwable $e) {
        add_flash('danger', 'บันทึกข้อมูลยึดคืนไม่สำเร็จ: ' . $e->getMessage());
    }

    redirect_to(fresher_base_url('repossessions.php'));
}

$currentFresherPage = 'repossessions';
$pageTitle = 'ระบบข้อมูลยึดคืน';

$contractOptions = fresher_contract_options();
$search = trim((string)($_GET['q'] ?? ''));

$scope = fresher_scope_clause('branch_code', 'fr_repo_list');
$sql = 'SELECT *
        FROM fresher_repossessions
        WHERE is_deleted = 0' . $scope['sql'];
$params = $scope['params'];
if ($search !== '') {
    $sql .= ' AND (
        repo_code LIKE :q OR
        contract_code LIKE :q OR
        customer_code LIKE :q OR
        customer_name LIKE :q
    )';
    $params[':q'] = '%' . $search . '%';
}
$sql .= ' ORDER BY id DESC';
$stmtRows = db()->prepare($sql);
$stmtRows->execute($params);
$rows = $stmtRows->fetchAll();

$searchScope = fresher_scope_clause('branch_code', 'fr_repo_search_opt');
$stmtSearchOptions = db()->prepare(
    'SELECT repo_code, contract_code, customer_code, customer_name
     FROM fresher_repossessions
     WHERE is_deleted = 0' . $searchScope['sql'] . '
     ORDER BY id DESC
     LIMIT 5000'
);
$stmtSearchOptions->execute($searchScope['params']);
$searchOptions = $stmtSearchOptions->fetchAll();

include __DIR__ . '/partials/head.php';
?>

<section class="card fr-card mb-4">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-3 fr-actions">
                <button class="btn btn-primary" type="button" id="btnAddRepo" data-bs-toggle="modal" data-bs-target="#repoFormModal">+ เพิ่มรายการยึดคืน</button>
            </div>
            <div class="col-md-9">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-9">
                        <label class="form-label">ค้นหา (รหัสยึดคืน / เลขสัญญา / รหัสลูกค้า / ชื่อ-นามสกุล)</label>
                        <select class="form-select js-repo-search-autocomplete" name="q" id="repoSearchSelect">
                            <option value="">-- พิมพ์ค้นหา --</option>
                            <?php foreach ($searchOptions as $option): ?>
                                <?php
                                    $label = (string)$option['repo_code']
                                        . ' | สัญญา: ' . (string)$option['contract_code']
                                        . ' | ' . (string)$option['customer_name']
                                        . ' | ลูกค้า: ' . (string)$option['customer_code'];
                                ?>
                                <option value="<?php echo h((string)$option['contract_code']); ?>" <?php echo $search === (string)$option['contract_code'] ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 fr-actions">
                        <button class="btn btn-outline-primary" type="submit">ค้นหา</button>
                        <?php if ($search !== ''): ?>
                            <a class="btn btn-outline-secondary" href="<?php echo h(fresher_base_url('repossessions.php')); ?>">ล้าง</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

<section class="card fr-card mb-4">
    <div class="card-header bg-white"><strong>รายการยึดคืน</strong></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle js-fresher-datatable">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>รหัสยึดคืน</th>
                    <th>เลขสัญญา</th>
                    <th>ลูกค้า</th>
                    <th>สาขา</th>
                    <th>วันที่ยึดคืน</th>
                    <th>ราคาประเมิน</th>
                    <th>ราคาขาย</th>
                    <th>สถานะ</th>
                    <th>การจัดการ</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                        $payload = [
                            'id' => (int)$row['id'],
                            'repo_code' => (string)$row['repo_code'],
                            'contract_code' => (string)$row['contract_code'],
                            'customer_code' => (string)$row['customer_code'],
                            'customer_name' => (string)$row['customer_name'],
                            'branch_code' => (string)$row['branch_code'],
                            'repossession_date' => (string)$row['repossession_date'],
                            'asset_condition' => (string)$row['asset_condition'],
                            'storage_location' => (string)$row['storage_location'],
                            'appraised_value' => (string)$row['appraised_value'],
                            'sale_value' => (string)$row['sale_value'],
                            'repo_status' => (string)$row['repo_status'],
                            'note_text' => (string)$row['note_text'],
                        ];
                        $payloadJson = h((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    ?>
                    <tr>
                        <td><?php echo (int)$row['id']; ?></td>
                        <td><code><?php echo h((string)$row['repo_code']); ?></code></td>
                        <td><?php echo h((string)$row['contract_code']); ?></td>
                        <td><?php echo h((string)$row['customer_name'] . ' (' . (string)$row['customer_code'] . ')'); ?></td>
                        <td><?php echo h((string)$row['branch_code']); ?></td>
                        <td><?php echo h((string)$row['repossession_date']); ?></td>
                        <td><?php echo number_format((float)$row['appraised_value'], 2); ?></td>
                        <td><?php echo number_format((float)$row['sale_value'], 2); ?></td>
                        <td><?php echo h((string)$row['repo_status']); ?></td>
                        <td class="fr-actions">
                            <button class="btn btn-sm btn-outline-info js-btn-view-repo" type="button" data-repo="<?php echo $payloadJson; ?>">ดูข้อมูล</button>
                            <button class="btn btn-sm btn-outline-primary js-btn-edit-repo" type="button" data-repo="<?php echo $payloadJson; ?>">แก้ไข</button>
                            <form method="post" class="js-confirm-delete" onsubmit="return confirm('ยืนยันลบรายการยึดคืนนี้?');">
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
    </div>
</section>

<div class="modal fade" id="repoFormModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" id="repoForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="repoFormModalTitle">เพิ่มรายการยึดคืน</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" id="repoFormId" value="0">

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">รหัสยึดคืน</label>
                            <input class="form-control" id="repoFormCode" value="ระบบสร้างอัตโนมัติ" readonly>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">สัญญา *</label>
                            <select class="form-select" name="contract_code" id="repoContractCode" required>
                                <option value="">-- เลือกสัญญา --</option>
                                <?php foreach ($contractOptions as $contract): ?>
                                    <?php $code = (string)$contract['contract_code']; ?>
                                    <option value="<?php echo h($code); ?>">
                                        <?php echo h($code . ' | ' . (string)$contract['customer_name'] . ' | ลูกค้า: ' . (string)$contract['customer_code']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">วันที่ยึดคืน</label>
                            <input class="form-control" type="date" name="repossession_date" id="repoDate" value="<?php echo h(date('Y-m-d')); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">สถานะยึดคืน</label>
                            <select class="form-select" name="repo_status" id="repoStatus">
                                <option value="PENDING">PENDING</option>
                                <option value="IN_PROCESS">IN_PROCESS</option>
                                <option value="STORED">STORED</option>
                                <option value="SOLD">SOLD</option>
                                <option value="CLOSED">CLOSED</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">ราคาประเมิน</label>
                            <input class="form-control" type="number" step="0.01" name="appraised_value" id="repoAppraisedValue" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">ราคาขายได้</label>
                            <input class="form-control" type="number" step="0.01" name="sale_value" id="repoSaleValue" value="0">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">สภาพทรัพย์</label>
                            <input class="form-control" name="asset_condition" id="repoAssetCondition">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">สถานที่เก็บ</label>
                            <input class="form-control" name="storage_location" id="repoStorageLocation">
                        </div>

                        <div class="col-12">
                            <label class="form-label">หมายเหตุ</label>
                            <input class="form-control" name="note_text" id="repoNoteText">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" type="submit" id="repoFormSubmitBtn">บันทึกข้อมูล</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="repoViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">รายละเอียดรายการยึดคืน</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 small">
                    <div class="col-md-6"><strong>รหัสยึดคืน:</strong> <span id="view_repo_code">-</span></div>
                    <div class="col-md-6"><strong>เลขสัญญา:</strong> <span id="view_repo_contract_code">-</span></div>
                    <div class="col-md-6"><strong>ลูกค้า:</strong> <span id="view_repo_customer_name">-</span></div>
                    <div class="col-md-6"><strong>รหัสลูกค้า:</strong> <span id="view_repo_customer_code">-</span></div>
                    <div class="col-md-6"><strong>สาขา:</strong> <span id="view_repo_branch_code">-</span></div>
                    <div class="col-md-6"><strong>วันที่ยึดคืน:</strong> <span id="view_repo_date">-</span></div>
                    <div class="col-md-6"><strong>ราคาประเมิน:</strong> <span id="view_repo_appraised_value">0.00</span></div>
                    <div class="col-md-6"><strong>ราคาขาย:</strong> <span id="view_repo_sale_value">0.00</span></div>
                    <div class="col-md-6"><strong>สถานะ:</strong> <span id="view_repo_status">-</span></div>
                    <div class="col-md-6"><strong>สถานที่เก็บ:</strong> <span id="view_repo_storage_location">-</span></div>
                    <div class="col-12"><strong>สภาพทรัพย์:</strong> <span id="view_repo_asset_condition">-</span></div>
                    <div class="col-12"><strong>หมายเหตุ:</strong> <span id="view_repo_note_text">-</span></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchSelect = $('#repoSearchSelect');
    if (searchSelect.length > 0) {
        searchSelect.select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'พิมพ์ค้นหาเลขสัญญา/รหัสยึดคืน/ชื่อลูกค้า',
            allowClear: true,
            language: {
                noResults: function () { return 'ไม่พบข้อมูลที่ค้นหา'; },
                searching: function () { return 'กำลังค้นหา...'; }
            }
        });
    }

    const repoFormModal = document.getElementById('repoFormModal');
    const repoViewModal = document.getElementById('repoViewModal');
    const repoModal = repoFormModal ? new bootstrap.Modal(repoFormModal) : null;
    const viewModal = repoViewModal ? new bootstrap.Modal(repoViewModal) : null;

    const repoFormTitle = document.getElementById('repoFormModalTitle');
    const repoFormSubmitBtn = document.getElementById('repoFormSubmitBtn');
    const repoFormId = document.getElementById('repoFormId');
    const repoFormCode = document.getElementById('repoFormCode');
    const repoContractCode = document.getElementById('repoContractCode');
    const repoDate = document.getElementById('repoDate');
    const repoAssetCondition = document.getElementById('repoAssetCondition');
    const repoStorageLocation = document.getElementById('repoStorageLocation');
    const repoAppraisedValue = document.getElementById('repoAppraisedValue');
    const repoSaleValue = document.getElementById('repoSaleValue');
    const repoStatus = document.getElementById('repoStatus');
    const repoNoteText = document.getElementById('repoNoteText');

    const todayValue = '<?php echo h(date('Y-m-d')); ?>';

    function formatAmount(value) {
        const parsed = Number(value || 0);
        if (!Number.isFinite(parsed)) {
            return '0.00';
        }
        return parsed.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function resetRepoForm() {
        if (!repoFormId) {
            return;
        }
        repoFormId.value = '0';
        if (repoFormTitle) {
            repoFormTitle.textContent = 'เพิ่มรายการยึดคืน';
        }
        if (repoFormSubmitBtn) {
            repoFormSubmitBtn.textContent = 'บันทึกข้อมูล';
        }
        if (repoFormCode) {
            repoFormCode.value = 'ระบบสร้างอัตโนมัติ';
        }
        if (repoDate) {
            repoDate.value = todayValue;
        }
        if (repoAssetCondition) {
            repoAssetCondition.value = '';
        }
        if (repoStorageLocation) {
            repoStorageLocation.value = '';
        }
        if (repoAppraisedValue) {
            repoAppraisedValue.value = '0';
        }
        if (repoSaleValue) {
            repoSaleValue.value = '0';
        }
        if (repoStatus) {
            repoStatus.value = 'PENDING';
        }
        if (repoNoteText) {
            repoNoteText.value = '';
        }
        if (repoContractCode) {
            repoContractCode.value = '';
            $(repoContractCode).trigger('change');
        }
    }

    function fillRepoForm(data) {
        if (!repoFormId) {
            return;
        }
        repoFormId.value = String(data.id || 0);
        if (repoFormTitle) {
            repoFormTitle.textContent = 'แก้ไขรายการยึดคืน';
        }
        if (repoFormSubmitBtn) {
            repoFormSubmitBtn.textContent = 'บันทึกการแก้ไข';
        }
        if (repoFormCode) {
            repoFormCode.value = String(data.repo_code || '');
        }
        if (repoContractCode) {
            repoContractCode.value = String(data.contract_code || '');
            $(repoContractCode).trigger('change');
        }
        if (repoDate) {
            repoDate.value = String(data.repossession_date || todayValue);
        }
        if (repoAssetCondition) {
            repoAssetCondition.value = String(data.asset_condition || '');
        }
        if (repoStorageLocation) {
            repoStorageLocation.value = String(data.storage_location || '');
        }
        if (repoAppraisedValue) {
            repoAppraisedValue.value = String(data.appraised_value || '0');
        }
        if (repoSaleValue) {
            repoSaleValue.value = String(data.sale_value || '0');
        }
        if (repoStatus) {
            repoStatus.value = String(data.repo_status || 'PENDING');
        }
        if (repoNoteText) {
            repoNoteText.value = String(data.note_text || '');
        }
    }

    const addRepoBtn = document.getElementById('btnAddRepo');
    if (addRepoBtn) {
        addRepoBtn.addEventListener('click', function () {
            resetRepoForm();
        });
    }

    document.querySelectorAll('.js-btn-edit-repo').forEach(function (button) {
        button.addEventListener('click', function () {
            const payload = this.getAttribute('data-repo') || '{}';
            let data = {};
            try {
                data = JSON.parse(payload);
            } catch (error) {
                data = {};
            }
            fillRepoForm(data);
            if (repoModal) {
                repoModal.show();
            }
        });
    });

    function setViewText(id, value) {
        const element = document.getElementById(id);
        if (!element) {
            return;
        }
        element.textContent = value && value !== '' ? value : '-';
    }

    document.querySelectorAll('.js-btn-view-repo').forEach(function (button) {
        button.addEventListener('click', function () {
            const payload = this.getAttribute('data-repo') || '{}';
            let data = {};
            try {
                data = JSON.parse(payload);
            } catch (error) {
                data = {};
            }
            setViewText('view_repo_code', String(data.repo_code || ''));
            setViewText('view_repo_contract_code', String(data.contract_code || ''));
            setViewText('view_repo_customer_name', String(data.customer_name || ''));
            setViewText('view_repo_customer_code', String(data.customer_code || ''));
            setViewText('view_repo_branch_code', String(data.branch_code || ''));
            setViewText('view_repo_date', String(data.repossession_date || ''));
            setViewText('view_repo_status', String(data.repo_status || ''));
            setViewText('view_repo_storage_location', String(data.storage_location || ''));
            setViewText('view_repo_asset_condition', String(data.asset_condition || ''));
            setViewText('view_repo_note_text', String(data.note_text || ''));
            setViewText('view_repo_appraised_value', formatAmount(data.appraised_value));
            setViewText('view_repo_sale_value', formatAmount(data.sale_value));
            if (viewModal) {
                viewModal.show();
            }
        });
    });
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>

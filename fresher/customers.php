<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));
        $action = trim((string)($_POST['action'] ?? ''));
        $actor = current_user_name();
        $now = now_dt();

        if ($action === 'delete') {
            $id = fresher_int($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('ไม่พบลูกค้าที่ต้องการลบ');
            }

            $scope = fresher_scope_clause('branch_code', 'fr_cus_del');
            $stmt = db()->prepare(
                'SELECT branch_code FROM fresher_customers
                 WHERE id = :id AND is_deleted = 0' . $scope['sql'] . ' LIMIT 1'
            );
            $params = $scope['params'];
            $params[':id'] = $id;
            $stmt->execute($params);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException('ไม่พบข้อมูลลูกค้าหรือไม่มีสิทธิ์ลบ');
            }
            assert_branch_in_current_scope((string)$row['branch_code']);

            $stmt = db()->prepare(
                'UPDATE fresher_customers
                 SET is_deleted = 1, deleted_by = :deleted_by, deleted_at = :deleted_at,
                     updated_by = :updated_by, updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                ':deleted_by' => $actor,
                ':deleted_at' => $now,
                ':updated_by' => $actor,
                ':updated_at' => $now,
                ':id' => $id,
            ]);
            add_flash('warning', 'ลบข้อมูลลูกค้าแบบ soft delete เรียบร้อย');
        }

        if ($action === 'save') {
            $id = fresher_int($_POST['id'] ?? 0);
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $phone = trim((string)($_POST['phone_number'] ?? ''));
            $cid = trim((string)($_POST['cid_tax_id'] ?? ''));
            $occupation = trim((string)($_POST['occupation'] ?? ''));
            $monthlyIncome = max(0, fresher_decimal($_POST['monthly_income'] ?? 0));
            $branchCode = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
            $status = strtoupper(trim((string)($_POST['customer_status'] ?? 'ACTIVE')));
            $note = trim((string)($_POST['note_text'] ?? ''));

            if ($firstName === '' || $lastName === '' || $branchCode === '') {
                throw new RuntimeException('กรุณากรอกข้อมูลให้ครบ (ชื่อ/นามสกุล/สาขา)');
            }
            assert_branch_in_current_scope($branchCode);

            if ($id > 0) {
                $scope = fresher_scope_clause('branch_code', 'fr_cus_up');
                $stmt = db()->prepare(
                    'SELECT branch_code FROM fresher_customers
                     WHERE id = :id AND is_deleted = 0' . $scope['sql'] . ' LIMIT 1'
                );
                $params = $scope['params'];
                $params[':id'] = $id;
                $stmt->execute($params);
                $row = $stmt->fetch();
                if (!$row) {
                    throw new RuntimeException('ไม่พบข้อมูลลูกค้าที่ต้องการแก้ไข');
                }
                assert_branch_in_current_scope((string)$row['branch_code']);

                $stmt = db()->prepare(
                    'UPDATE fresher_customers
                     SET first_name = :first_name, last_name = :last_name, phone_number = :phone_number,
                         cid_tax_id = :cid_tax_id, occupation = :occupation, monthly_income = :monthly_income,
                         branch_code = :branch_code, customer_status = :customer_status, note_text = :note_text,
                         updated_by = :updated_by, updated_at = :updated_at
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':first_name' => $firstName,
                    ':last_name' => $lastName,
                    ':phone_number' => $phone,
                    ':cid_tax_id' => $cid,
                    ':occupation' => $occupation,
                    ':monthly_income' => $monthlyIncome,
                    ':branch_code' => $branchCode,
                    ':customer_status' => $status !== '' ? $status : 'ACTIVE',
                    ':note_text' => $note,
                    ':updated_by' => $actor,
                    ':updated_at' => $now,
                    ':id' => $id,
                ]);
                add_flash('success', 'แก้ไขข้อมูลลูกค้าเรียบร้อย');
            } else {
                $customerCode = fresher_generate_code('FRCUS');
                $stmt = db()->prepare(
                    'INSERT INTO fresher_customers (
                        customer_code, first_name, last_name, phone_number, cid_tax_id, occupation, monthly_income,
                        branch_code, customer_status, note_text, is_deleted, created_by, created_at
                    ) VALUES (
                        :customer_code, :first_name, :last_name, :phone_number, :cid_tax_id, :occupation, :monthly_income,
                        :branch_code, :customer_status, :note_text, 0, :created_by, :created_at
                    )'
                );
                $stmt->execute([
                    ':customer_code' => $customerCode,
                    ':first_name' => $firstName,
                    ':last_name' => $lastName,
                    ':phone_number' => $phone,
                    ':cid_tax_id' => $cid,
                    ':occupation' => $occupation,
                    ':monthly_income' => $monthlyIncome,
                    ':branch_code' => $branchCode,
                    ':customer_status' => $status !== '' ? $status : 'ACTIVE',
                    ':note_text' => $note,
                    ':created_by' => $actor,
                    ':created_at' => $now,
                ]);
                add_flash('success', 'เพิ่มลูกค้าเรียบร้อย รหัส: ' . $customerCode);
            }
        }
    } catch (Throwable $e) {
        add_flash('danger', 'บันทึกข้อมูลไม่สำเร็จ: ' . $e->getMessage());
    }

    redirect_to(fresher_base_url('customers.php'));
}

$currentFresherPage = 'customers';
$pageTitle = 'ข้อมูลลูกค้าเช่าซื้อ';

$branchOptions = fresher_branch_options();
$search = trim((string)($_GET['q'] ?? ''));

$scope = fresher_scope_clause('c.branch_code', 'fr_cus_list');
$sql = 'SELECT c.*,
               COALESCE((
                   SELECT GROUP_CONCAT(DISTINCT hp.contract_code ORDER BY hp.id DESC SEPARATOR ", ")
                   FROM fresher_hire_purchase hp
                   WHERE hp.customer_code = c.customer_code
                     AND hp.is_deleted = 0
               ), "") AS contract_codes
        FROM fresher_customers c
        WHERE c.is_deleted = 0' . $scope['sql'];
$params = $scope['params'];
if ($search !== '') {
    $sql .= ' AND (
        c.customer_code LIKE :q OR c.first_name LIKE :q OR c.last_name LIKE :q
        OR CONCAT(c.first_name, " ", c.last_name) LIKE :q
        OR c.cid_tax_id LIKE :q
        OR EXISTS (
            SELECT 1 FROM fresher_hire_purchase hp2
            WHERE hp2.customer_code = c.customer_code
              AND hp2.is_deleted = 0
              AND hp2.contract_code LIKE :q
        )
    )';
    $params[':q'] = '%' . $search . '%';
}
$sql .= ' ORDER BY c.id DESC';
$stmtRows = db()->prepare($sql);
$stmtRows->execute($params);
$rows = $stmtRows->fetchAll();

include __DIR__ . '/partials/head.php';
?>

<section class="card fr-card mb-4">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-3 fr-actions">
                <button class="btn btn-primary" type="button" id="btnAddCustomer" data-bs-toggle="modal" data-bs-target="#customerFormModal">+ เพิ่มลูกค้า</button>
            </div>
            <div class="col-md-9">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-9">
                        <label class="form-label">ค้นหา (เลขสัญญา / ชื่อ-นามสกุล / เลขบัตร / รหัสลูกค้า)</label>
                        <input class="form-control" name="q" value="<?php echo h($search); ?>" placeholder="พิมพ์คำค้นหาได้ทันที">
                    </div>
                    <div class="col-md-3 fr-actions">
                        <button class="btn btn-outline-primary" type="submit">ค้นหา</button>
                        <?php if ($search !== ''): ?>
                            <a class="btn btn-outline-secondary" href="<?php echo h(fresher_base_url('customers.php')); ?>">ล้าง</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

<section class="card fr-card mb-4">
    <div class="card-header bg-white"><strong>ผลลัพธ์รายการลูกค้า</strong></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle js-fresher-datatable">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>รหัสลูกค้า</th>
                    <th>เลขสัญญา</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>เลขบัตร</th>
                    <th>สาขา</th>
                    <th>อาชีพ</th>
                    <th>รายได้</th>
                    <th>สถานะ</th>
                    <th>การจัดการ</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                        $payload = [
                            'id' => (int)$row['id'],
                            'customer_code' => (string)$row['customer_code'],
                            'first_name' => (string)$row['first_name'],
                            'last_name' => (string)$row['last_name'],
                            'phone_number' => (string)$row['phone_number'],
                            'cid_tax_id' => (string)$row['cid_tax_id'],
                            'occupation' => (string)$row['occupation'],
                            'monthly_income' => (string)$row['monthly_income'],
                            'branch_code' => (string)$row['branch_code'],
                            'customer_status' => (string)$row['customer_status'],
                            'note_text' => (string)$row['note_text'],
                            'contract_codes' => (string)($row['contract_codes'] ?? ''),
                        ];
                        $payloadJson = h((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    ?>
                    <tr>
                        <td><?php echo (int)$row['id']; ?></td>
                        <td><code><?php echo h((string)$row['customer_code']); ?></code></td>
                        <td><?php echo h((string)($row['contract_codes'] !== '' ? $row['contract_codes'] : '-')); ?></td>
                        <td><?php echo h((string)$row['first_name'] . ' ' . (string)$row['last_name']); ?></td>
                        <td><?php echo h((string)($row['cid_tax_id'] !== '' ? $row['cid_tax_id'] : '-')); ?></td>
                        <td><?php echo h((string)$row['branch_code']); ?></td>
                        <td><?php echo h((string)$row['occupation']); ?></td>
                        <td><?php echo number_format((float)$row['monthly_income'], 2); ?></td>
                        <td><?php echo h((string)$row['customer_status']); ?></td>
                        <td class="fr-actions">
                            <button class="btn btn-sm btn-outline-info js-btn-view" type="button" data-customer="<?php echo $payloadJson; ?>">ดูข้อมูล</button>
                            <button class="btn btn-sm btn-outline-primary js-btn-edit" type="button" data-customer="<?php echo $payloadJson; ?>">แก้ไข</button>
                            <form method="post" class="js-confirm-delete" onsubmit="return confirm('ยืนยันลบข้อมูลลูกค้ารายการนี้?');">
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

<div class="modal fade" id="customerFormModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="customerForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="customerFormTitle">เพิ่มลูกค้า</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" id="f_id" value="0">
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label">ชื่อ *</label><input class="form-control" name="first_name" id="f_first_name" required></div>
                        <div class="col-md-6"><label class="form-label">นามสกุล *</label><input class="form-control" name="last_name" id="f_last_name" required></div>
                        <div class="col-md-6"><label class="form-label">โทรศัพท์</label><input class="form-control" name="phone_number" id="f_phone_number"></div>
                        <div class="col-md-6"><label class="form-label">เลขบัตรประชาชน/ภาษี</label><input class="form-control" name="cid_tax_id" id="f_cid_tax_id"></div>
                        <div class="col-md-6"><label class="form-label">อาชีพ</label><input class="form-control" name="occupation" id="f_occupation"></div>
                        <div class="col-md-6"><label class="form-label">รายได้/เดือน</label><input class="form-control" type="number" step="0.01" name="monthly_income" id="f_monthly_income" value="0"></div>
                        <div class="col-md-6">
                            <label class="form-label">สาขา *</label>
                            <select class="form-select" name="branch_code" id="f_branch_code" required>
                                <option value="">-- เลือกสาขา --</option>
                                <?php foreach ($branchOptions as $branch): ?>
                                    <option value="<?php echo h((string)$branch['branch_code']); ?>"><?php echo h((string)$branch['branch_code'] . ' - ' . (string)$branch['branch_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">สถานะลูกค้า</label>
                            <select class="form-select" name="customer_status" id="f_customer_status">
                                <?php foreach (['ACTIVE', 'WATCH', 'BLOCKED', 'CLOSED'] as $status): ?>
                                    <option value="<?php echo h($status); ?>"><?php echo h($status); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12"><label class="form-label">หมายเหตุ</label><input class="form-control" name="note_text" id="f_note_text"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" type="submit" id="customerSaveBtn">บันทึก</button>
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">ปิด</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('customerFormModal');
    const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
    const formTitle = document.getElementById('customerFormTitle');
    const saveBtn = document.getElementById('customerSaveBtn');

    function fill(data) {
        document.getElementById('f_id').value = String(data.id || 0);
        document.getElementById('f_first_name').value = String(data.first_name || '');
        document.getElementById('f_last_name').value = String(data.last_name || '');
        document.getElementById('f_phone_number').value = String(data.phone_number || '');
        document.getElementById('f_cid_tax_id').value = String(data.cid_tax_id || '');
        document.getElementById('f_occupation').value = String(data.occupation || '');
        document.getElementById('f_monthly_income').value = String(data.monthly_income || '0');
        document.getElementById('f_branch_code').value = String(data.branch_code || '');
        document.getElementById('f_customer_status').value = String(data.customer_status || 'ACTIVE');
        document.getElementById('f_note_text').value = String(data.note_text || '');
    }

    document.getElementById('btnAddCustomer')?.addEventListener('click', function () {
        fill({});
        if (formTitle) formTitle.textContent = 'เพิ่มลูกค้า';
        if (saveBtn) saveBtn.textContent = 'บันทึก';
    });

    document.querySelectorAll('.js-btn-edit').forEach(function (button) {
        button.addEventListener('click', function () {
            const payload = this.getAttribute('data-customer') || '{}';
            let data = {};
            try { data = JSON.parse(payload); } catch (e) { data = {}; }
            fill(data);
            if (formTitle) formTitle.textContent = 'แก้ไขข้อมูลลูกค้า';
            if (saveBtn) saveBtn.textContent = 'บันทึกการแก้ไข';
            if (modal) modal.show();
        });
    });

    document.querySelectorAll('.js-btn-view').forEach(function (button) {
        button.addEventListener('click', function () {
            const payload = this.getAttribute('data-customer') || '{}';
            let data = {};
            try { data = JSON.parse(payload); } catch (e) { data = {}; }
            alert(
                'รหัสลูกค้า: ' + String(data.customer_code || '-') + '\\n' +
                'ชื่อ: ' + String(data.first_name || '') + ' ' + String(data.last_name || '') + '\\n' +
                'เลขสัญญา: ' + String(data.contract_codes || '-') + '\\n' +
                'โทร: ' + String(data.phone_number || '-') + '\\n' +
                'เลขบัตร: ' + String(data.cid_tax_id || '-') + '\\n' +
                'สาขา: ' + String(data.branch_code || '-') + '\\n' +
                'อาชีพ: ' + String(data.occupation || '-') + '\\n' +
                'รายได้: ' + String(data.monthly_income || '0')
            );
        });
    });
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>

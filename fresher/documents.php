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
                throw new RuntimeException('ไม่พบเอกสารที่ต้องการลบ');
            }
            $scope = fresher_scope_clause('branch_code', 'fr_doc_del');
            $stmtFind = db()->prepare(
                'SELECT branch_code
                 FROM fresher_documents
                 WHERE id = :id AND is_deleted = 0' . $scope['sql'] . '
                 LIMIT 1'
            );
            $params = $scope['params'];
            $params[':id'] = $id;
            $stmtFind->execute($params);
            $row = $stmtFind->fetch();
            if (!$row) {
                throw new RuntimeException('ไม่พบเอกสารหรือไม่มีสิทธิ์');
            }
            assert_branch_in_current_scope((string)$row['branch_code']);

            $stmt = db()->prepare(
                'UPDATE fresher_documents
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
            add_flash('warning', 'ลบเอกสารแบบ soft delete เรียบร้อย');
        } else {
            $contractCode = strtoupper(trim((string)($_POST['contract_code'] ?? '')));
            $documentType = trim((string)($_POST['document_type'] ?? ''));
            $note = trim((string)($_POST['note_text'] ?? ''));
            if ($contractCode === '') {
                throw new RuntimeException('กรุณาเลือกสัญญาเช่าซื้อ');
            }
            if ($documentType === '') {
                throw new RuntimeException('กรุณาระบุประเภทเอกสาร');
            }

            $contract = fresher_contract_row($contractCode);
            if (!$contract) {
                throw new RuntimeException('ไม่พบสัญญาหรือไม่มีสิทธิ์');
            }
            $branchCode = strtoupper(trim((string)$contract['branch_code']));
            assert_branch_in_current_scope($branchCode);

            $upload = fresher_upload_file('document_file', 'documents', ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx'], 20 * 1024 * 1024);
            if (!is_array($upload)) {
                throw new RuntimeException('กรุณาเลือกไฟล์เอกสารก่อนบันทึก');
            }

            $documentCode = fresher_generate_code('FRDOC');
            $stmt = db()->prepare(
                'INSERT INTO fresher_documents (
                    document_code, contract_code, customer_code, customer_name, branch_code,
                    document_type, file_name, file_path, note_text,
                    is_deleted, created_by, created_at
                 ) VALUES (
                    :document_code, :contract_code, :customer_code, :customer_name, :branch_code,
                    :document_type, :file_name, :file_path, :note_text,
                    0, :created_by, :created_at
                 )'
            );
            $stmt->execute([
                ':document_code' => $documentCode,
                ':contract_code' => $contractCode,
                ':customer_code' => (string)$contract['customer_code'],
                ':customer_name' => (string)$contract['customer_name'],
                ':branch_code' => $branchCode,
                ':document_type' => $documentType,
                ':file_name' => (string)$upload['file_name'],
                ':file_path' => (string)$upload['file_path'],
                ':note_text' => $note,
                ':created_by' => $actor,
                ':created_at' => $now,
            ]);
            add_flash('success', 'อัปโหลดเอกสารเช่าซื้อเรียบร้อย');
        }
    } catch (Throwable $e) {
        add_flash('danger', 'บันทึกเอกสารไม่สำเร็จ: ' . $e->getMessage());
    }

    redirect_to(fresher_base_url('documents.php'));
}

$currentFresherPage = 'documents';
$pageTitle = 'ระบบเก็บเอกสารเช่าซื้อ';

$contractOptions = fresher_contract_options();
$scope = fresher_scope_clause('branch_code', 'fr_doc_list');
$stmtRows = db()->prepare(
    'SELECT *
     FROM fresher_documents
     WHERE is_deleted = 0' . $scope['sql'] . '
     ORDER BY id DESC'
);
$stmtRows->execute($scope['params']);
$rows = $stmtRows->fetchAll();

include __DIR__ . '/partials/head.php';
?>

<section class="card fr-card mb-4">
    <div class="card-header bg-white"><strong>อัปโหลดเอกสารเช่าซื้อ</strong></div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-3">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="save">
            <div class="col-md-5">
                <label class="form-label">สัญญา *</label>
                <select class="form-select" name="contract_code" required>
                    <option value="">-- เลือกสัญญา --</option>
                    <?php foreach ($contractOptions as $contract): ?>
                        <?php $code = (string)$contract['contract_code']; ?>
                        <option value="<?php echo h($code); ?>"><?php echo h($code . ' - ' . (string)$contract['customer_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">ประเภทเอกสาร *</label>
                <select class="form-select" name="document_type" required>
                    <?php foreach (['สัญญาเช่าซื้อ', 'บัตรประชาชน', 'ทะเบียนบ้าน', 'หลักฐานรายได้', 'ใบเสร็จชำระ', 'เอกสารคดี', 'อื่นๆ'] as $type): ?>
                        <option value="<?php echo h($type); ?>"><?php echo h($type); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">ไฟล์เอกสาร *</label>
                <input class="form-control" type="file" name="document_file" required>
            </div>
            <div class="col-md-8">
                <label class="form-label">หมายเหตุ</label>
                <input class="form-control" name="note_text">
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button class="btn btn-primary" type="submit">อัปโหลดเอกสาร</button>
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
                <th>รหัสเอกสาร</th>
                <th>สัญญา</th>
                <th>ลูกค้า</th>
                <th>ประเภท</th>
                <th>ไฟล์</th>
                <th>บันทึกล่าสุด</th>
                <th>จัดการ</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?php echo (int)$row['id']; ?></td>
                    <td><code><?php echo h((string)$row['document_code']); ?></code></td>
                    <td><?php echo h((string)$row['contract_code']); ?></td>
                    <td><?php echo h((string)$row['customer_name']); ?></td>
                    <td><?php echo h((string)$row['document_type']); ?></td>
                    <td>
                        <?php if (!empty($row['file_path'])): ?>
                            <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?php echo h(fresher_base_url((string)$row['file_path'])); ?>">
                                เปิดไฟล์
                            </a>
                            <small class="d-block text-muted"><?php echo h((string)$row['file_name']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?php echo h((string)$row['created_at']); ?></td>
                    <td>
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

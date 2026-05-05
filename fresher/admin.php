<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!fresher_is_admin()) {
    add_flash('warning', 'สิทธิ์ไม่เพียงพอสำหรับเมนู Admin');
    redirect_to(fresher_base_url('index.php'));
}

$roleOptions = [
    'user' => 'ผู้ใช้งาน',
    'admin' => 'ผู้ดูแลระบบ',
    'director' => 'ผู้อำนวยการ',
];

$actor = current_user_name();
$now = now_dt();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'user_add') {
            $userName = strtolower(trim((string)($_POST['user_name'] ?? '')));
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $roleName = trim((string)($_POST['role_name'] ?? ''));
            $branchCode = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
            $password = (string)($_POST['password'] ?? '');

            if (!preg_match('/^[a-z0-9_.-]{3,50}$/', $userName)) {
                throw new RuntimeException('ชื่อผู้ใช้ต้องเป็น a-z, 0-9, จุด, ขีดล่าง หรือขีดกลาง ความยาว 3-50 ตัวอักษร');
            }
            if ($displayName === '') {
                throw new RuntimeException('กรุณากรอกชื่อแสดงผล');
            }
            if (!isset($roleOptions[$roleName])) {
                throw new RuntimeException('ระดับผู้ใช้งานไม่ถูกต้อง');
            }
            if ($branchCode === '') {
                throw new RuntimeException('กรุณาเลือกสาขา');
            }
            if ($password === '' || strlen($password) < 3) {
                throw new RuntimeException('รหัสผ่านเริ่มต้นต้องมีอย่างน้อย 3 ตัวอักษร');
            }
            assert_branch_in_current_scope($branchCode);

            $stmtDup = db()->prepare('SELECT COUNT(*) FROM system_users WHERE user_name = :user_name');
            $stmtDup->execute([':user_name' => $userName]);
            if ((int)$stmtDup->fetchColumn() > 0) {
                throw new RuntimeException('ชื่อผู้ใช้นี้มีอยู่แล้ว');
            }

            $profile = [
                'branch_code' => $branchCode,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'created_via' => 'fresher_admin',
            ];

            $stmtInsert = db()->prepare(
                'INSERT INTO system_users (
                    user_name, display_name, role_name,
                    is_latest, is_deleted,
                    created_by, created_at, updated_by, updated_at,
                    profile_json
                 ) VALUES (
                    :user_name, :display_name, :role_name,
                    1, 0,
                    :created_by, :created_at, :updated_by, :updated_at,
                    :profile_json
                 )'
            );
            $stmtInsert->execute([
                ':user_name' => $userName,
                ':display_name' => $displayName,
                ':role_name' => $roleName,
                ':created_by' => $actor,
                ':created_at' => $now,
                ':updated_by' => $actor,
                ':updated_at' => $now,
                ':profile_json' => json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            add_flash('success', 'เพิ่มผู้ใช้งานเรียบร้อย');
        } elseif ($action === 'user_update') {
            $id = fresher_int($_POST['id'] ?? 0);
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $roleName = trim((string)($_POST['role_name'] ?? ''));
            $branchCode = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
            $password = (string)($_POST['password'] ?? '');

            if ($id <= 0) {
                throw new RuntimeException('ไม่พบผู้ใช้งานที่ต้องการแก้ไข');
            }
            if ($displayName === '') {
                throw new RuntimeException('กรุณากรอกชื่อแสดงผล');
            }
            if (!isset($roleOptions[$roleName])) {
                throw new RuntimeException('ระดับผู้ใช้งานไม่ถูกต้อง');
            }
            if ($branchCode === '') {
                throw new RuntimeException('กรุณาเลือกสาขา');
            }
            assert_branch_in_current_scope($branchCode);

            $stmtUser = db()->prepare('SELECT profile_json, is_deleted FROM system_users WHERE id = :id AND is_latest = 1 LIMIT 1');
            $stmtUser->execute([':id' => $id]);
            $row = $stmtUser->fetch();
            if (!$row || (int)($row['is_deleted'] ?? 0) === 1) {
                throw new RuntimeException('ไม่พบข้อมูลผู้ใช้งานที่ใช้งานอยู่');
            }

            $profile = json_decode((string)($row['profile_json'] ?? ''), true);
            if (!is_array($profile)) {
                $profile = [];
            }
            $profile['branch_code'] = $branchCode;
            if ($password !== '') {
                if (strlen($password) < 3) {
                    throw new RuntimeException('รหัสผ่านใหม่ต้องมีอย่างน้อย 3 ตัวอักษร');
                }
                $profile['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $profile['password_changed_by_admin'] = $actor;
                $profile['password_changed_at'] = $now;
            }

            $stmt = db()->prepare(
                'UPDATE system_users
                 SET display_name = :display_name,
                     role_name = :role_name,
                     profile_json = :profile_json,
                     updated_by = :updated_by,
                     updated_at = :updated_at
                 WHERE id = :id AND is_latest = 1'
            );
            $stmt->execute([
                ':display_name' => $displayName,
                ':role_name' => $roleName,
                ':profile_json' => json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':updated_by' => $actor,
                ':updated_at' => $now,
                ':id' => $id,
            ]);

            add_flash('success', 'แก้ไขผู้ใช้งานเรียบร้อย');
        } elseif ($action === 'user_delete') {
            $id = fresher_int($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('ไม่พบผู้ใช้งานที่ต้องการลบ');
            }

            $stmtUser = db()->prepare('SELECT user_name, is_deleted FROM system_users WHERE id = :id AND is_latest = 1 LIMIT 1');
            $stmtUser->execute([':id' => $id]);
            $row = $stmtUser->fetch();
            if (!$row || (int)($row['is_deleted'] ?? 0) === 1) {
                throw new RuntimeException('ไม่พบข้อมูลผู้ใช้งานที่ใช้งานอยู่');
            }
            if (strtolower(trim((string)$row['user_name'])) === strtolower($actor)) {
                throw new RuntimeException('ไม่สามารถลบบัญชีที่กำลังใช้งานอยู่');
            }

            $stmt = db()->prepare(
                'UPDATE system_users
                 SET is_deleted = 1,
                     deleted_by = :deleted_by,
                     deleted_at = :deleted_at,
                     updated_by = :updated_by,
                     updated_at = :updated_at
                 WHERE id = :id AND is_latest = 1'
            );
            $stmt->execute([
                ':deleted_by' => $actor,
                ':deleted_at' => $now,
                ':updated_by' => $actor,
                ':updated_at' => $now,
                ':id' => $id,
            ]);

            add_flash('warning', 'ลบผู้ใช้งานแบบ soft delete เรียบร้อย');
        } elseif ($action === 'product_add') {
            $productCodeInput = strtoupper(trim((string)($_POST['product_code'] ?? '')));
            $productName = trim((string)($_POST['product_name'] ?? ''));
            $modelName = trim((string)($_POST['model_name'] ?? ''));
            $categoryName = trim((string)($_POST['category_name'] ?? ''));
            $salePrice = max(0, fresher_decimal($_POST['sale_price'] ?? 0));
            $stockQuantity = max(0, fresher_int($_POST['stock_quantity'] ?? 0));
            $isActive = fresher_int($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

            if ($productName === '' || $modelName === '') {
                throw new RuntimeException('กรุณากรอกชื่อสินค้าและรุ่นสินค้า');
            }

            $productCode = $productCodeInput !== '' ? $productCodeInput : fresher_generate_code('FRPRD');

            $stmtDup = db()->prepare('SELECT COUNT(*) FROM fresher_products WHERE product_code = :product_code AND is_deleted = 0');
            $stmtDup->execute([':product_code' => $productCode]);
            if ((int)$stmtDup->fetchColumn() > 0) {
                throw new RuntimeException('รหัสสินค้านี้มีอยู่แล้ว: ' . $productCode);
            }

            $stmt = db()->prepare(
                'INSERT INTO fresher_products (
                    product_code, product_name, model_name, category_name,
                    default_price, sale_price, stock_quantity, is_active, is_deleted,
                    created_by, created_at
                 ) VALUES (
                    :product_code, :product_name, :model_name, :category_name,
                    :default_price, :sale_price, :stock_quantity, :is_active, 0,
                    :created_by, :created_at
                 )'
            );
            $stmt->execute([
                ':product_code' => $productCode,
                ':product_name' => $productName,
                ':model_name' => $modelName,
                ':category_name' => $categoryName,
                ':default_price' => $salePrice,
                ':sale_price' => $salePrice,
                ':stock_quantity' => $stockQuantity,
                ':is_active' => $isActive,
                ':created_by' => $actor,
                ':created_at' => $now,
            ]);

            add_flash('success', 'เพิ่มสินค้าเรียบร้อย: ' . $productCode);
        } elseif ($action === 'product_update') {
            $id = fresher_int($_POST['id'] ?? 0);
            $productName = trim((string)($_POST['product_name'] ?? ''));
            $modelName = trim((string)($_POST['model_name'] ?? ''));
            $categoryName = trim((string)($_POST['category_name'] ?? ''));
            $salePrice = max(0, fresher_decimal($_POST['sale_price'] ?? 0));
            $stockQuantity = max(0, fresher_int($_POST['stock_quantity'] ?? 0));
            $isActive = fresher_int($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

            if ($id <= 0) {
                throw new RuntimeException('ไม่พบสินค้าที่ต้องการแก้ไข');
            }
            if ($productName === '' || $modelName === '') {
                throw new RuntimeException('กรุณากรอกชื่อสินค้าและรุ่นสินค้า');
            }

            $stmt = db()->prepare(
                'UPDATE fresher_products
                 SET product_name = :product_name,
                     model_name = :model_name,
                     category_name = :category_name,
                     default_price = :default_price,
                     sale_price = :sale_price,
                     stock_quantity = :stock_quantity,
                     is_active = :is_active,
                     updated_by = :updated_by,
                     updated_at = :updated_at
                 WHERE id = :id
                   AND is_deleted = 0'
            );
            $stmt->execute([
                ':product_name' => $productName,
                ':model_name' => $modelName,
                ':category_name' => $categoryName,
                ':default_price' => $salePrice,
                ':sale_price' => $salePrice,
                ':stock_quantity' => $stockQuantity,
                ':is_active' => $isActive,
                ':updated_by' => $actor,
                ':updated_at' => $now,
                ':id' => $id,
            ]);

            add_flash('success', 'แก้ไขข้อมูลสินค้าเรียบร้อย');
        } elseif ($action === 'product_delete') {
            $id = fresher_int($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('ไม่พบสินค้าที่ต้องการลบ');
            }

            $stmt = db()->prepare(
                'UPDATE fresher_products
                 SET is_deleted = 1,
                     deleted_by = :deleted_by,
                     deleted_at = :deleted_at,
                     updated_by = :updated_by,
                     updated_at = :updated_at
                 WHERE id = :id
                   AND is_deleted = 0'
            );
            $stmt->execute([
                ':deleted_by' => $actor,
                ':deleted_at' => $now,
                ':updated_by' => $actor,
                ':updated_at' => $now,
                ':id' => $id,
            ]);

            add_flash('warning', 'ลบสินค้าแบบ soft delete เรียบร้อย');
        } else {
            throw new RuntimeException('ไม่พบคำสั่งที่ต้องการทำงาน');
        }
    } catch (Throwable $e) {
        add_flash('danger', 'บันทึกข้อมูลไม่สำเร็จ: ' . $e->getMessage());
    }

    redirect_to(fresher_base_url('admin.php'));
}

$currentFresherPage = 'admin';
$pageTitle = 'Admin จัดการผู้ใช้งานและสินค้า';

$branchOptions = fresher_branch_options();
$branchNameMap = [];
foreach ($branchOptions as $branch) {
    $code = strtoupper(trim((string)($branch['branch_code'] ?? '')));
    if ($code !== '') {
        $branchNameMap[$code] = trim((string)($branch['branch_name'] ?? ''));
    }
}

$userRows = db()->query(
    'SELECT id, user_name, display_name, role_name, is_deleted, created_at, updated_at, profile_json
     FROM system_users
     WHERE is_latest = 1
     ORDER BY is_deleted ASC, user_name ASC'
)->fetchAll();

$productRows = db()->query(
    'SELECT id, product_code, product_name, model_name, category_name,
            COALESCE(NULLIF(sale_price, 0), default_price, 0) AS sale_price,
            stock_quantity, is_active, updated_at, created_at
     FROM fresher_products
     WHERE is_deleted = 0
     ORDER BY product_name ASC, model_name ASC'
)->fetchAll();

include __DIR__ . '/partials/head.php';
?>
<section class="card fr-card mb-4">
    <div class="card-header bg-white"><strong>จัดการผู้ใช้งาน</strong></div>
    <div class="card-body">
        <form method="post" class="row g-2 mb-3">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="user_add">
            <div class="col-md-2"><input class="form-control" name="user_name" placeholder="username" required></div>
            <div class="col-md-3"><input class="form-control" name="display_name" placeholder="ชื่อแสดงผล" required></div>
            <div class="col-md-2"><select class="form-select" name="role_name" required><option value="">-- เลือก role --</option><?php foreach ($roleOptions as $roleKey => $roleLabel): ?><option value="<?php echo h($roleKey); ?>"><?php echo h($roleLabel); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><select class="form-select" name="branch_code" required><option value="">-- เลือกสาขา --</option><?php foreach ($branchOptions as $branch): ?><option value="<?php echo h((string)$branch['branch_code']); ?>"><?php echo h((string)$branch['branch_code'] . ' - ' . (string)$branch['branch_name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><input class="form-control" name="password" type="password" placeholder="รหัสผ่านเริ่มต้น" required></div>
            <div class="col-md-12 mt-2"><button class="btn btn-primary" type="submit">เพิ่มผู้ใช้งาน</button></div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm table-hover js-fresher-datatable">
                <thead><tr><th>Username</th><th>ชื่อแสดงผล</th><th>Role</th><th>สาขา</th><th>สถานะ</th><th>อัปเดตล่าสุด</th><th>จัดการ</th></tr></thead>
                <tbody>
                <?php foreach ($userRows as $row): ?>
                    <?php $profile = json_decode((string)($row['profile_json'] ?? ''), true); if (!is_array($profile)) { $profile = []; } $branchCode = strtoupper(trim((string)($profile['branch_code'] ?? ''))); $branchLabel = $branchCode; if ($branchCode !== '' && isset($branchNameMap[$branchCode])) { $branchLabel = $branchCode . ' - ' . $branchNameMap[$branchCode]; } $deleted = (int)($row['is_deleted'] ?? 0) === 1; ?>
                    <tr>
                        <td><?php echo h((string)$row['user_name']); ?></td>
                        <td><?php echo h((string)$row['display_name']); ?></td>
                        <td><?php echo h($roleOptions[(string)$row['role_name']] ?? thai_role_label((string)$row['role_name'])); ?></td>
                        <td><?php echo h($branchLabel); ?></td>
                        <td><?php if ($deleted): ?><span class="badge text-bg-secondary">ลบแล้ว</span><?php else: ?><span class="badge text-bg-success">ใช้งาน</span><?php endif; ?></td>
                        <td class="small text-muted"><?php echo h((string)($row['updated_at'] ?: $row['created_at'])); ?></td>
                        <td class="fr-actions"><?php if (!$deleted): ?><button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#editUserModal" data-id="<?php echo (int)$row['id']; ?>" data-user-name="<?php echo h((string)$row['user_name']); ?>" data-display-name="<?php echo h((string)$row['display_name']); ?>" data-role-name="<?php echo h((string)$row['role_name']); ?>" data-branch-code="<?php echo h($branchCode); ?>">แก้ไข</button><form method="post" class="js-confirm-delete"><?php echo csrf_input(); ?><input type="hidden" name="action" value="user_delete"><input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>"><button class="btn btn-sm btn-outline-danger" type="submit">ลบ</button></form><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<section class="card fr-card mb-4">
    <div class="card-header bg-white"><strong>จัดการสินค้าและคงคลัง</strong></div>
    <div class="card-body">
        <form method="post" class="row g-2 mb-3">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="action" value="product_add">
            <div class="col-md-2"><label class="form-label">รหัสสินค้า (ไม่กรอก = auto)</label><input class="form-control" name="product_code" placeholder="FRPRD..."></div>
            <div class="col-md-3"><label class="form-label">ชื่อสินค้า *</label><input class="form-control" name="product_name" required></div>
            <div class="col-md-3"><label class="form-label">รุ่นสินค้า *</label><input class="form-control" name="model_name" required></div>
            <div class="col-md-2"><label class="form-label">หมวดสินค้า</label><input class="form-control" name="category_name"></div>
            <div class="col-md-1"><label class="form-label">ราคาขาย</label><input class="form-control" name="sale_price" type="number" step="0.01" min="0" value="0"></div>
            <div class="col-md-1"><label class="form-label">คงคลัง</label><input class="form-control" name="stock_quantity" type="number" step="1" min="0" value="0"></div>
            <div class="col-md-2"><label class="form-label">สถานะ</label><select class="form-select" name="is_active"><option value="1">ใช้งาน</option><option value="0">ปิดใช้งาน</option></select></div>
            <div class="col-md-12 mt-2"><button class="btn btn-primary" type="submit">เพิ่มสินค้า</button></div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm table-hover js-fresher-datatable">
                <thead><tr><th>รหัส</th><th>ชื่อสินค้า</th><th>รุ่น</th><th>หมวด</th><th>ราคาขาย</th><th>คงคลัง</th><th>สถานะ</th><th>อัปเดตล่าสุด</th><th>จัดการ</th></tr></thead>
                <tbody>
                <?php foreach ($productRows as $row): ?>
                    <?php $active = (int)($row['is_active'] ?? 0) === 1; ?>
                    <tr>
                        <td><code><?php echo h((string)$row['product_code']); ?></code></td>
                        <td><?php echo h((string)$row['product_name']); ?></td>
                        <td><?php echo h((string)$row['model_name']); ?></td>
                        <td><?php echo h((string)$row['category_name']); ?></td>
                        <td><?php echo number_format((float)$row['sale_price'], 2); ?></td>
                        <td><?php echo number_format((int)$row['stock_quantity']); ?></td>
                        <td><?php if ($active): ?><span class="badge text-bg-success">ใช้งาน</span><?php else: ?><span class="badge text-bg-secondary">ปิดใช้งาน</span><?php endif; ?></td>
                        <td class="small text-muted"><?php echo h((string)($row['updated_at'] ?: $row['created_at'])); ?></td>
                        <td class="fr-actions"><button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#editProductModal" data-id="<?php echo (int)$row['id']; ?>" data-product-code="<?php echo h((string)$row['product_code']); ?>" data-product-name="<?php echo h((string)$row['product_name']); ?>" data-model-name="<?php echo h((string)$row['model_name']); ?>" data-category-name="<?php echo h((string)$row['category_name']); ?>" data-sale-price="<?php echo h((string)$row['sale_price']); ?>" data-stock-quantity="<?php echo h((string)$row['stock_quantity']); ?>" data-is-active="<?php echo $active ? '1' : '0'; ?>">แก้ไข</button><form method="post" class="js-confirm-delete"><?php echo csrf_input(); ?><input type="hidden" name="action" value="product_delete"><input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>"><button class="btn btn-sm btn-outline-danger" type="submit">ลบ</button></form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form method="post" class="modal-content"><div class="modal-header"><h2 class="modal-title fs-6 mb-0">แก้ไขผู้ใช้งาน</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><?php echo csrf_input(); ?><input type="hidden" name="action" value="user_update"><input type="hidden" name="id" id="user_edit_id"><div class="mb-3"><label class="form-label">Username</label><input class="form-control" id="user_edit_user_name" type="text" readonly></div><div class="mb-3"><label class="form-label">ชื่อแสดงผล</label><input class="form-control" name="display_name" id="user_edit_display_name" required></div><div class="mb-3"><label class="form-label">Role</label><select class="form-select" name="role_name" id="user_edit_role_name" required><?php foreach ($roleOptions as $roleKey => $roleLabel): ?><option value="<?php echo h($roleKey); ?>"><?php echo h($roleLabel); ?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label">สาขา</label><select class="form-select" name="branch_code" id="user_edit_branch_code" required><option value="">-- เลือกสาขา --</option><?php foreach ($branchOptions as $branch): ?><option value="<?php echo h((string)$branch['branch_code']); ?>"><?php echo h((string)$branch['branch_code'] . ' - ' . (string)$branch['branch_name']); ?></option><?php endforeach; ?></select></div><div class="mb-0"><label class="form-label">รหัสผ่านใหม่ (ถ้าไม่เปลี่ยนให้เว้นว่าง)</label><input class="form-control" name="password" type="password"></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-primary" type="submit">บันทึกการแก้ไข</button></div></form></div></div>

<div class="modal fade" id="editProductModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form method="post" class="modal-content"><div class="modal-header"><h2 class="modal-title fs-6 mb-0">แก้ไขสินค้า</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><?php echo csrf_input(); ?><input type="hidden" name="action" value="product_update"><input type="hidden" name="id" id="product_edit_id"><div class="mb-3"><label class="form-label">รหัสสินค้า</label><input class="form-control" id="product_edit_code" type="text" readonly></div><div class="mb-3"><label class="form-label">ชื่อสินค้า *</label><input class="form-control" name="product_name" id="product_edit_name" required></div><div class="mb-3"><label class="form-label">รุ่นสินค้า *</label><input class="form-control" name="model_name" id="product_edit_model" required></div><div class="mb-3"><label class="form-label">หมวดสินค้า</label><input class="form-control" name="category_name" id="product_edit_category"></div><div class="row g-2"><div class="col-md-6"><label class="form-label">ราคาขาย</label><input class="form-control" name="sale_price" id="product_edit_sale_price" type="number" min="0" step="0.01" required></div><div class="col-md-6"><label class="form-label">คงคลัง</label><input class="form-control" name="stock_quantity" id="product_edit_stock_quantity" type="number" min="0" step="1" required></div></div><div class="mt-3 mb-0"><label class="form-label">สถานะ</label><select class="form-select" name="is_active" id="product_edit_is_active" required><option value="1">ใช้งาน</option><option value="0">ปิดใช้งาน</option></select></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-primary" type="submit">บันทึกการแก้ไข</button></div></form></div></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const userModal = document.getElementById('editUserModal');
    if (userModal) {
        userModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) return;
            document.getElementById('user_edit_id').value = button.getAttribute('data-id') || '';
            document.getElementById('user_edit_user_name').value = button.getAttribute('data-user-name') || '';
            document.getElementById('user_edit_display_name').value = button.getAttribute('data-display-name') || '';
            document.getElementById('user_edit_role_name').value = button.getAttribute('data-role-name') || '';
            document.getElementById('user_edit_branch_code').value = button.getAttribute('data-branch-code') || '';
        });
    }

    const productModal = document.getElementById('editProductModal');
    if (productModal) {
        productModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) return;
            document.getElementById('product_edit_id').value = button.getAttribute('data-id') || '';
            document.getElementById('product_edit_code').value = button.getAttribute('data-product-code') || '';
            document.getElementById('product_edit_name').value = button.getAttribute('data-product-name') || '';
            document.getElementById('product_edit_model').value = button.getAttribute('data-model-name') || '';
            document.getElementById('product_edit_category').value = button.getAttribute('data-category-name') || '';
            document.getElementById('product_edit_sale_price').value = button.getAttribute('data-sale-price') || '0';
            document.getElementById('product_edit_stock_quantity').value = button.getAttribute('data-stock-quantity') || '0';
            document.getElementById('product_edit_is_active').value = button.getAttribute('data-is-active') || '1';
        });
    }
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>

<?php
declare(strict_types=1);

$title = (string)($pageTitle ?? 'ระบบเช่าซื้อ Fresher');
$active = (string)($currentFresherPage ?? '');
$effectiveRole = fresher_effective_role();
$themeCssVersion = @filemtime(__DIR__ . '/../../assets/css/theme.css');
if ($themeCssVersion === false) {
    $themeCssVersion = time();
}

$titleFallbacks = [
    'index' => 'แดชบอร์ดระบบเช่าซื้อ',
    'executive_dashboard' => 'แดชบอร์ดผู้บริหารลูกหนี้',
    'admin' => 'จัดการข้อมูลตั้งต้น',
    'customers' => 'ข้อมูลลูกค้าเช่าซื้อ',
    'affordability' => 'ประเมินความสามารถผ่อนชำระ',
    'hire_purchase' => 'ข้อมูลผู้เช่าซื้อและสัญญา',
    'installments' => 'ตารางงวดผ่อนชำระ',
    'collections' => 'ติดตามทวงถามหนี้',
    'repossessions' => 'ข้อมูลยึดคืน',
    'legal_cases' => 'ฟ้องและชำระคดี',
    'documents' => 'เอกสารเช่าซื้อ',
];
if (preg_match('/\x{FFFD}|\?\?\?|Ã|\x{00E0}\x{00B8}/u', $title) === 1) {
    $title = $titleFallbacks[$active] ?? 'ระบบเช่าซื้อ Fresher';
}

$navItems = [
    ['key' => 'index', 'href' => fresher_base_url('index.php'), 'th' => 'ภาพรวมระบบ'],
    ['key' => 'executive_dashboard', 'href' => fresher_base_url('executive_dashboard.php'), 'th' => 'แดชบอร์ดผู้บริหารลูกหนี้'],
    ['key' => 'customers', 'href' => fresher_base_url('customers.php'), 'th' => 'ข้อมูลลูกค้าเช่าซื้อ'],
    ['key' => 'affordability', 'href' => fresher_base_url('affordability.php'), 'th' => 'ประเมินความสามารถผ่อน'],
    ['key' => 'hire_purchase', 'href' => fresher_base_url('hire_purchase.php'), 'th' => 'ผู้เช่าซื้อและสัญญา'],
    ['key' => 'installments', 'href' => fresher_base_url('installments.php'), 'th' => 'ตารางงวดผ่อนชำระ'],
    ['key' => 'collections', 'href' => fresher_base_url('collections.php'), 'th' => 'ติดตามทวงถามหนี้'],
    ['key' => 'repossessions', 'href' => fresher_base_url('repossessions.php'), 'th' => 'ข้อมูลยึดคืน'],
    ['key' => 'legal_cases', 'href' => fresher_base_url('legal_cases.php'), 'th' => 'ฟ้องและชำระคดี'],
    ['key' => 'documents', 'href' => fresher_base_url('documents.php'), 'th' => 'เอกสารเช่าซื้อ'],
    ['key' => 'admin', 'href' => fresher_base_url('admin.php'), 'th' => 'จัดการข้อมูลตั้งต้น'],
];

// Reorder menu:
// 3 = hire_purchase (ผู้กู้และสัญญา), 4 = installments (ตารางผ่อนชำระ), old 3 moved to 5.
$navOrder = [
    'index',
    'executive_dashboard',
    'hire_purchase',
    'installments',
    'customers',
    'affordability',
    'collections',
    'repossessions',
    'legal_cases',
    'documents',
    'admin',
];
$navMap = [];
foreach ($navItems as $item) {
    $itemKey = (string)($item['key'] ?? '');
    if ($itemKey !== '') {
        $navMap[$itemKey] = $item;
    }
}
$sortedNav = [];
foreach ($navOrder as $itemKey) {
    if (isset($navMap[$itemKey])) {
        $sortedNav[] = $navMap[$itemKey];
        unset($navMap[$itemKey]);
    }
}
if ($navMap !== []) {
    foreach ($navMap as $item) {
        $sortedNav[] = $item;
    }
}
$navItems = $sortedNav;
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($title); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link href="<?php echo h(app_base_url('assets/css/theme.css?v=' . (string)$themeCssVersion)); ?>" rel="stylesheet">
    <style>
        body { font-family: "Prompt", sans-serif; background: #eef2f6; }
        .fr-layout { display: grid; grid-template-columns: 320px 1fr; min-height: 100vh; }
        .fr-side { background: #0f172a; color: #d1d5db; padding: 1rem; }
        .fr-brand { color: #fff; text-decoration: none; font-weight: 600; letter-spacing: .2px; }
        .fr-sub { font-size: .82rem; color: #93c5fd; margin-top: .2rem; }
        .fr-nav a { display: block; margin-top: .45rem; padding: .55rem .7rem; border-radius: .55rem; color: #cbd5e1; text-decoration: none; }
        .fr-nav a.active, .fr-nav a:hover { background: #1e293b; color: #fff; }
        .fr-nav-th { display: block; font-size: .92rem; line-height: 1.15; }
        .fr-main { padding: 1rem 1.25rem; }
        .fr-top { display: flex; justify-content: space-between; align-items: center; gap: .8rem; margin-bottom: 1rem; }
        .fr-card { border: 0; box-shadow: 0 6px 20px rgba(15, 23, 42, .06); }
        .fr-stat { border: 1px solid #dbe7f5; border-radius: .75rem; background: #fff; padding: .85rem 1rem; }
        .fr-stat span { font-size: .86rem; color: #64748b; display: block; }
        .fr-stat strong { font-size: 1.35rem; color: #0f172a; }
        .fr-actions { display: flex; flex-wrap: wrap; gap: .35rem; }
        .fr-thumb { width: 64px; height: 64px; object-fit: cover; border-radius: .5rem; border: 1px solid #cbd5e1; }
        .table thead th { white-space: nowrap; }
        @media (max-width: 991.98px) {
            .fr-layout { grid-template-columns: 1fr; }
            .fr-side { position: sticky; top: 0; z-index: 1000; }
        }
    </style>
</head>
<body>
<div class="fr-layout">
    <aside class="fr-side">
        <a class="fr-brand" href="<?php echo h(fresher_base_url('index.php')); ?>">ระบบเช่าซื้อ Fresher</a>
        <div class="fr-sub">ระบบเช่าซื้อสินค้า + ติดตามหนี้ + ยึดคืน + คดี</div>
        <hr class="border-secondary-subtle">
        <nav class="fr-nav">
            <?php foreach ($navItems as $item): ?>
                <?php if (!fresher_nav_can_see((string)$item['key'])) { continue; } ?>
                <a class="<?php echo $active === $item['key'] ? 'active' : ''; ?>" href="<?php echo h((string)$item['href']); ?>">
                    <span class="fr-nav-th"><?php echo h((string)$item['th']); ?></span>
                </a>
            <?php endforeach; ?>
            <a href="<?php echo h(app_base_url('index.php')); ?>">
                <span class="fr-nav-th">กลับระบบหลัก</span>
            </a>
        </nav>
    </aside>
    <main class="fr-main">
        <div class="fr-top">
            <div>
                <h1 class="h5 mb-0"><?php echo h($title); ?></h1>
                <div class="text-muted small">
                    ผู้ใช้: <?php echo h(current_user_name()); ?> |
                    สิทธิ์: <?php echo h(thai_role_label($effectiveRole)); ?>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-success" href="<?php echo h(app_base_url('login.php')); ?>">เข้าสู่ระบบ</a>
                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#changePasswordModal">เปลี่ยนรหัสผ่าน</button>
                <form method="post">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="__action" value="logout">
                    <button class="btn btn-sm btn-outline-secondary" type="submit">ออกจากระบบ</button>
                </form>
            </div>
        </div>
        <?php include __DIR__ . '/../../partials/notifications.php'; ?>

        <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="post" class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title fs-6 mb-0">เปลี่ยนรหัสผ่าน</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                    </div>
                    <div class="modal-body">
                        <?php echo csrf_input(); ?>
                        <input type="hidden" name="__action" value="change_password">
                        <div class="mb-3"><label class="form-label">รหัสผ่านปัจจุบัน</label><input class="form-control" type="password" name="current_password" required></div>
                        <div class="mb-3"><label class="form-label">รหัสผ่านใหม่</label><input class="form-control" type="password" name="new_password" required></div>
                        <div class="mb-0"><label class="form-label">ยืนยันรหัสผ่านใหม่</label><input class="form-control" type="password" name="confirm_password" required></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">บันทึกรหัสผ่านใหม่</button>
                    </div>
                </form>
            </div>
        </div>

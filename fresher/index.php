<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$currentFresherPage = 'index';
$pageTitle = 'แดชบอร์ดระบบเช่าซื้อ Fresher';

$scope = fresher_scope_clause('branch_code', 'fr_dash');
$params = $scope['params'];

$tables = [
    'customers' => 'fresher_customers',
    'affordability' => 'fresher_affordability',
    'contracts' => 'fresher_hire_purchase',
    'installments' => 'fresher_installments',
    'collections' => 'fresher_collections',
    'repossessions' => 'fresher_repossessions',
    'legal_cases' => 'fresher_legal_cases',
    'documents' => 'fresher_documents',
];

$stats = [];
foreach ($tables as $key => $table) {
    $stmt = db()->prepare(
        'SELECT COUNT(*)
         FROM ' . $table . '
         WHERE is_deleted = 0' . $scope['sql']
    );
    $stmt->execute($params);
    $stats[$key] = (int)$stmt->fetchColumn();
}

$overdueStmt = db()->prepare(
    'SELECT COUNT(*)
     FROM fresher_installments
     WHERE is_deleted = 0
       AND payment_status NOT IN ("PAID", "WAIVED_EARLY")
       AND due_date < CURDATE()' . $scope['sql']
);
$overdueStmt->execute($params);
$stats['overdue'] = (int)$overdueStmt->fetchColumn();

$delinquentStmt = db()->prepare(
    'SELECT COUNT(*)
     FROM fresher_hire_purchase
     WHERE is_deleted = 0
       AND contract_status IN ("DELINQUENT", "NPL")' . $scope['sql']
);
$delinquentStmt->execute($params);
$stats['delinquent_contracts'] = (int)$delinquentStmt->fetchColumn();

$dueAmountStmt = db()->prepare(
    'SELECT COALESCE(SUM(GREATEST(installment_amount - paid_amount, 0)), 0)
     FROM fresher_installments
     WHERE is_deleted = 0
       AND payment_status NOT IN ("PAID", "WAIVED_EARLY")
       AND due_date < CURDATE()' . $scope['sql']
);
$dueAmountStmt->execute($params);
$stats['overdue_due_amount'] = (float)$dueAmountStmt->fetchColumn();

include __DIR__ . '/partials/head.php';
?>

<section class="row g-3 mb-4">
    <div class="col-xl-3 col-md-4 col-6"><div class="fr-stat"><span>ลูกค้าเช่าซื้อ</span><strong><?php echo number_format($stats['customers'] ?? 0); ?></strong></div></div>
    <div class="col-xl-3 col-md-4 col-6"><div class="fr-stat"><span>สัญญาเช่าซื้อ</span><strong><?php echo number_format($stats['contracts'] ?? 0); ?></strong></div></div>
    <div class="col-xl-3 col-md-4 col-6"><div class="fr-stat"><span>งวดผ่อนทั้งหมด</span><strong><?php echo number_format($stats['installments'] ?? 0); ?></strong></div></div>
    <div class="col-xl-3 col-md-4 col-6"><div class="fr-stat"><span>งวดค้างชำระ</span><strong><?php echo number_format($stats['overdue'] ?? 0); ?></strong></div></div>
    <div class="col-xl-3 col-md-4 col-6"><div class="fr-stat"><span>งานติดตามหนี้</span><strong><?php echo number_format($stats['collections'] ?? 0); ?></strong></div></div>
    <div class="col-xl-3 col-md-4 col-6"><div class="fr-stat"><span>รายการยึดคืน</span><strong><?php echo number_format($stats['repossessions'] ?? 0); ?></strong></div></div>
    <div class="col-xl-3 col-md-4 col-6"><div class="fr-stat"><span>ฟ้อง/ชำระคดี</span><strong><?php echo number_format($stats['legal_cases'] ?? 0); ?></strong></div></div>
    <div class="col-xl-3 col-md-4 col-6"><div class="fr-stat"><span>เอกสารเช่าซื้อ</span><strong><?php echo number_format($stats['documents'] ?? 0); ?></strong></div></div>
</section>

<section class="card fr-card mb-4">
    <div class="card-body">
        <h2 class="h6 mb-3">ทางลัดการทำงาน</h2>
        <div class="fr-actions">
            <a class="btn btn-primary btn-sm" href="<?php echo h(fresher_base_url('executive_dashboard.php')); ?>">Dashboard ผู้บริหารลูกหนี้</a>
            <a class="btn btn-outline-primary btn-sm" href="<?php echo h(fresher_base_url('admin.php')); ?>">หน้าตั้งค่าระบบ</a>
            <a class="btn btn-outline-primary btn-sm" href="<?php echo h(fresher_base_url('customers.php')); ?>">เพิ่มลูกค้าเช่าซื้อ</a>
            <a class="btn btn-outline-primary btn-sm" href="<?php echo h(fresher_base_url('hire_purchase.php')); ?>">สร้างสัญญาเช่าซื้อ</a>
            <a class="btn btn-outline-primary btn-sm" href="<?php echo h(fresher_base_url('installments.php')); ?>">บันทึกชำระงวด</a>
            <a class="btn btn-outline-primary btn-sm" href="<?php echo h(fresher_base_url('collections.php')); ?>">ติดตามทวงถามหนี้</a>
            <a class="btn btn-outline-primary btn-sm" href="<?php echo h(fresher_base_url('repossessions.php')); ?>">บันทึกยึดคืน</a>
            <a class="btn btn-outline-primary btn-sm" href="<?php echo h(fresher_base_url('legal_cases.php')); ?>">จัดการฟ้อง/ชำระคดี</a>
            <a class="btn btn-outline-primary btn-sm" href="<?php echo h(fresher_base_url('documents.php')); ?>">เก็บเอกสารเช่าซื้อ</a>
        </div>
    </div>
</section>

<section class="card fr-card">
    <div class="card-body">
        <h2 class="h6 mb-2">สรุปความเสี่ยงพอร์ต</h2>
        <div class="row g-3">
            <div class="col-lg-4">
                <div class="border rounded p-3 bg-light-subtle">
                    <div class="small text-muted">สัญญาเสี่ยง (Delinquent + NPL)</div>
                    <div class="h4 mb-0"><?php echo number_format($stats['delinquent_contracts'] ?? 0); ?></div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="border rounded p-3 bg-light-subtle">
                    <div class="small text-muted">ยอดค้างชำระรวม</div>
                    <div class="h4 mb-0"><?php echo number_format($stats['overdue_due_amount'] ?? 0, 2); ?></div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="border rounded p-3 bg-light-subtle">
                    <div class="small text-muted">แนะนำ</div>
                    <div class="small mb-0">ใช้เมนู Dashboard ผู้บริหารลูกหนี้ เพื่อดู DPD bucket, สาขาเสี่ยง และผลงานติดตามแบบละเอียด</div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>

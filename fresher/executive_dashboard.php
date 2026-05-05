<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$currentFresherPage = 'executive_dashboard';
$pageTitle = 'แดชบอร์ดผู้บริหารลูกหนี้และติดตามหนี้';

$defaultFromMonth = date('Y-m', strtotime('-11 months'));
$defaultToMonth = date('Y-m');

$fromMonth = trim((string)($_GET['from_month'] ?? $defaultFromMonth));
$toMonth = trim((string)($_GET['to_month'] ?? $defaultToMonth));

if (!preg_match('/^\d{4}-\d{2}$/', $fromMonth)) {
    $fromMonth = $defaultFromMonth;
}
if (!preg_match('/^\d{4}-\d{2}$/', $toMonth)) {
    $toMonth = $defaultToMonth;
}
if ($fromMonth > $toMonth) {
    $tmp = $fromMonth;
    $fromMonth = $toMonth;
    $toMonth = $tmp;
}

$periodStart = $fromMonth . '-01';
$periodEnd = date('Y-m-t', strtotime($toMonth . '-01'));

$stats = [
    'contracts_total' => 0,
    'contracts_active' => 0,
    'contracts_delinquent' => 0,
    'contracts_npl' => 0,
    'contracts_closed' => 0,
    'overdue_contracts' => 0,
    'overdue_due_amount' => 0.0,
    'remaining_principal' => 0.0,
    'remaining_interest' => 0.0,
    'followups_period' => 0,
    'done_followups_period' => 0,
    'month_collection_fee' => 0.0,
    'month_late_penalty' => 0.0,
    'promise_due_7d' => 0,
];

// Dashboard นี้ใช้ข้อมูลรวมทั้งบริษัท (ไม่กรองตามสาขา)
$paramsContract = [];
$whereContract = 'hp.is_deleted = 0';

$paramsInstallment = [];
$whereInstallment = 'fi.is_deleted = 0';

$paramsCollection = [];
$whereCollection = 'fc.is_deleted = 0';

$sqlContractStat = 'SELECT
    COUNT(*) AS contracts_total,
    SUM(CASE WHEN hp.contract_status = "ACTIVE" THEN 1 ELSE 0 END) AS contracts_active,
    SUM(CASE WHEN hp.contract_status = "DELINQUENT" THEN 1 ELSE 0 END) AS contracts_delinquent,
    SUM(CASE WHEN hp.contract_status = "NPL" THEN 1 ELSE 0 END) AS contracts_npl,
    SUM(CASE WHEN hp.contract_status = "CLOSED" THEN 1 ELSE 0 END) AS contracts_closed
FROM fresher_hire_purchase hp
WHERE ' . $whereContract;
$stmtContractStat = db()->prepare($sqlContractStat);
$stmtContractStat->execute($paramsContract);
$contractStat = $stmtContractStat->fetch() ?: [];
$stats['contracts_total'] = (int)($contractStat['contracts_total'] ?? 0);
$stats['contracts_active'] = (int)($contractStat['contracts_active'] ?? 0);
$stats['contracts_delinquent'] = (int)($contractStat['contracts_delinquent'] ?? 0);
$stats['contracts_npl'] = (int)($contractStat['contracts_npl'] ?? 0);
$stats['contracts_closed'] = (int)($contractStat['contracts_closed'] ?? 0);

$sqlOverdue = 'SELECT
    COUNT(DISTINCT fi.contract_code) AS overdue_contracts,
    SUM(GREATEST(fi.installment_amount - fi.paid_amount, 0)) AS overdue_due_amount
FROM fresher_installments fi
WHERE ' . $whereInstallment . '
  AND fi.payment_status NOT IN ("PAID", "WAIVED_EARLY")
  AND fi.due_date < CURDATE()';
$stmtOverdue = db()->prepare($sqlOverdue);
$stmtOverdue->execute($paramsInstallment);
$overdue = $stmtOverdue->fetch() ?: [];
$stats['overdue_contracts'] = (int)($overdue['overdue_contracts'] ?? 0);
$stats['overdue_due_amount'] = (float)($overdue['overdue_due_amount'] ?? 0);

$sqlRemaining = 'SELECT
    SUM(
        GREATEST(
            fi.principal_amount - (
                CASE WHEN fi.installment_amount > 0
                     THEN LEAST(fi.paid_amount / fi.installment_amount, 1) * fi.principal_amount
                     ELSE 0 END
            ),
            0
        )
    ) AS remaining_principal,
    SUM(
        GREATEST(
            fi.interest_amount - (
                CASE WHEN fi.installment_amount > 0
                     THEN LEAST(fi.paid_amount / fi.installment_amount, 1) * fi.interest_amount
                     ELSE 0 END
            ),
            0
        )
    ) AS remaining_interest
FROM fresher_installments fi
WHERE ' . $whereInstallment . '
  AND fi.payment_status NOT IN ("PAID", "WAIVED_EARLY")';
$stmtRemaining = db()->prepare($sqlRemaining);
$stmtRemaining->execute($paramsInstallment);
$remaining = $stmtRemaining->fetch() ?: [];
$stats['remaining_principal'] = (float)($remaining['remaining_principal'] ?? 0);
$stats['remaining_interest'] = (float)($remaining['remaining_interest'] ?? 0);

$paramsCollectionPeriod = $paramsCollection;
$paramsCollectionPeriod[':period_start'] = $periodStart;
$paramsCollectionPeriod[':period_end'] = $periodEnd;
$sqlCollectionPeriod = 'SELECT
    COUNT(*) AS followups_period,
    SUM(CASE WHEN fc.collection_status = "DONE" THEN 1 ELSE 0 END) AS done_followups_period,
    SUM(fc.collection_fee_amount) AS month_collection_fee,
    SUM(fc.late_penalty_amount) AS month_late_penalty
FROM fresher_collections fc
WHERE ' . $whereCollection . '
  AND fc.followup_date IS NOT NULL
  AND fc.followup_date BETWEEN :period_start AND :period_end';
$stmtCollectionPeriod = db()->prepare($sqlCollectionPeriod);
$stmtCollectionPeriod->execute($paramsCollectionPeriod);
$collectionPeriod = $stmtCollectionPeriod->fetch() ?: [];
$stats['followups_period'] = (int)($collectionPeriod['followups_period'] ?? 0);
$stats['done_followups_period'] = (int)($collectionPeriod['done_followups_period'] ?? 0);
$stats['month_collection_fee'] = (float)($collectionPeriod['month_collection_fee'] ?? 0);
$stats['month_late_penalty'] = (float)($collectionPeriod['month_late_penalty'] ?? 0);

$paramsPromise = $paramsCollection;
$sqlPromise = 'SELECT COUNT(*)
FROM fresher_collections fc
WHERE ' . $whereCollection . '
  AND fc.promise_date IS NOT NULL
  AND fc.promise_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
  AND fc.collection_status IN ("OPEN", "FOLLOWUP", "PENDING")';
$stmtPromise = db()->prepare($sqlPromise);
$stmtPromise->execute($paramsPromise);
$stats['promise_due_7d'] = (int)$stmtPromise->fetchColumn();

$sqlBucket = 'SELECT
    CASE
        WHEN DATEDIFF(CURDATE(), fi.due_date) BETWEEN 1 AND 7 THEN "DPD 1-7"
        WHEN DATEDIFF(CURDATE(), fi.due_date) BETWEEN 8 AND 30 THEN "DPD 8-30"
        WHEN DATEDIFF(CURDATE(), fi.due_date) BETWEEN 31 AND 60 THEN "DPD 31-60"
        WHEN DATEDIFF(CURDATE(), fi.due_date) BETWEEN 61 AND 90 THEN "DPD 61-90"
        WHEN DATEDIFF(CURDATE(), fi.due_date) > 90 THEN "DPD 90+"
        ELSE "CURRENT"
    END AS dpd_bucket,
    COUNT(*) AS installments,
    SUM(GREATEST(fi.installment_amount - fi.paid_amount, 0)) AS overdue_due_amount
FROM fresher_installments fi
WHERE ' . $whereInstallment . '
  AND fi.payment_status NOT IN ("PAID", "WAIVED_EARLY")
  AND fi.due_date < CURDATE()
GROUP BY dpd_bucket
ORDER BY MIN(DATEDIFF(CURDATE(), fi.due_date))';
$stmtBucket = db()->prepare($sqlBucket);
$stmtBucket->execute($paramsInstallment);
$dpdBucketRows = $stmtBucket->fetchAll();

$paramsBranchSub = [];
$whereBranchSub = 'fi.is_deleted = 0';
$paramsBranchMain = [];
$whereBranchMain = 'hp.is_deleted = 0';

$sqlBranch = 'SELECT
    hp.branch_code,
    COUNT(*) AS contracts,
    SUM(CASE WHEN hp.contract_status = "DELINQUENT" THEN 1 ELSE 0 END) AS delinquent_contracts,
    SUM(CASE WHEN hp.contract_status = "NPL" THEN 1 ELSE 0 END) AS npl_contracts,
    SUM(COALESCE(ov.overdue_due_amount, 0)) AS overdue_due_amount
FROM fresher_hire_purchase hp
LEFT JOIN (
    SELECT fi.contract_code, fi.branch_code,
           SUM(GREATEST(fi.installment_amount - fi.paid_amount, 0)) AS overdue_due_amount
    FROM fresher_installments fi
    WHERE ' . $whereBranchSub . '
      AND fi.payment_status NOT IN ("PAID", "WAIVED_EARLY")
      AND fi.due_date < CURDATE()
    GROUP BY fi.contract_code, fi.branch_code
) ov ON ov.contract_code = hp.contract_code
WHERE ' . $whereBranchMain . '
GROUP BY hp.branch_code
ORDER BY overdue_due_amount DESC
LIMIT 30';
$stmtBranch = db()->prepare($sqlBranch);
$stmtBranch->execute(array_merge($paramsBranchSub, $paramsBranchMain));
$branchRows = $stmtBranch->fetchAll();

$sqlCollector = 'SELECT
    fc.collector_code,
    fc.collector_name,
    COUNT(*) AS followups,
    SUM(CASE WHEN fc.collection_status = "DONE" THEN 1 ELSE 0 END) AS done_count,
    SUM(fc.collection_fee_amount) AS total_fee,
    SUM(fc.late_penalty_amount) AS total_penalty
FROM fresher_collections fc
WHERE ' . $whereCollection . '
  AND fc.followup_date IS NOT NULL
  AND fc.followup_date BETWEEN :period_start AND :period_end
GROUP BY fc.collector_code, fc.collector_name
ORDER BY followups DESC
LIMIT 30';
$stmtCollector = db()->prepare($sqlCollector);
$stmtCollector->execute($paramsCollectionPeriod);
$collectorRows = $stmtCollector->fetchAll();

$sqlUrgent = 'SELECT
    fi.contract_code,
    fi.customer_name,
    fi.branch_code,
    MAX(DATEDIFF(CURDATE(), fi.due_date)) AS max_dpd,
    SUM(GREATEST(fi.installment_amount - fi.paid_amount, 0)) AS overdue_due_amount
FROM fresher_installments fi
WHERE ' . $whereInstallment . '
  AND fi.payment_status NOT IN ("PAID", "WAIVED_EARLY")
  AND fi.due_date < CURDATE()
GROUP BY fi.contract_code, fi.customer_name, fi.branch_code
ORDER BY max_dpd DESC, overdue_due_amount DESC
LIMIT 100';
$stmtUrgent = db()->prepare($sqlUrgent);
$stmtUrgent->execute($paramsInstallment);
$urgentRows = $stmtUrgent->fetchAll();

$monthKeys = [];
$monthLabels = [];
$monthCursor = new DateTimeImmutable($fromMonth . '-01');
$monthEnd = new DateTimeImmutable($toMonth . '-01');
while ($monthCursor <= $monthEnd) {
    $key = $monthCursor->format('Y-m');
    $monthKeys[] = $key;
    $monthLabels[] = $monthCursor->format('m/Y');
    $monthCursor = $monthCursor->modify('+1 month');
}

$paramsTrendOverdue = $paramsInstallment;
$paramsTrendOverdue[':period_start'] = $periodStart;
$paramsTrendOverdue[':period_end'] = $periodEnd;
$sqlOverdueTrend = 'SELECT
    DATE_FORMAT(fi.due_date, "%Y-%m") AS ym,
    SUM(GREATEST(fi.installment_amount - fi.paid_amount, 0)) AS overdue_amount,
    COUNT(DISTINCT fi.contract_code) AS overdue_contracts
FROM fresher_installments fi
WHERE ' . $whereInstallment . '
  AND fi.payment_status NOT IN ("PAID", "WAIVED_EARLY")
  AND fi.due_date BETWEEN :period_start AND :period_end
GROUP BY ym
ORDER BY ym';
$stmtOverdueTrend = db()->prepare($sqlOverdueTrend);
$stmtOverdueTrend->execute($paramsTrendOverdue);
$overdueTrendRows = $stmtOverdueTrend->fetchAll();
$overdueTrendMap = [];
$overdueContractMap = [];
foreach ($overdueTrendRows as $row) {
    $key = (string)($row['ym'] ?? '');
    if ($key === '') {
        continue;
    }
    $overdueTrendMap[$key] = (float)($row['overdue_amount'] ?? 0);
    $overdueContractMap[$key] = (int)($row['overdue_contracts'] ?? 0);
}

$paramsTrendFollowup = $paramsCollectionPeriod;
$sqlFollowupTrend = 'SELECT
    DATE_FORMAT(fc.followup_date, "%Y-%m") AS ym,
    COUNT(*) AS followups,
    SUM(CASE WHEN fc.collection_status = "DONE" THEN 1 ELSE 0 END) AS done_count
FROM fresher_collections fc
WHERE ' . $whereCollection . '
  AND fc.followup_date IS NOT NULL
  AND fc.followup_date BETWEEN :period_start AND :period_end
GROUP BY ym
ORDER BY ym';
$stmtFollowupTrend = db()->prepare($sqlFollowupTrend);
$stmtFollowupTrend->execute($paramsTrendFollowup);
$followupTrendRows = $stmtFollowupTrend->fetchAll();
$followupTrendMap = [];
$followupDoneTrendMap = [];
foreach ($followupTrendRows as $row) {
    $key = (string)($row['ym'] ?? '');
    if ($key === '') {
        continue;
    }
    $followupTrendMap[$key] = (int)($row['followups'] ?? 0);
    $followupDoneTrendMap[$key] = (int)($row['done_count'] ?? 0);
}

$overdueTrendData = [];
$overdueContractTrendData = [];
$followupTrendData = [];
$followupDoneTrendData = [];
foreach ($monthKeys as $key) {
    $overdueTrendData[] = (float)($overdueTrendMap[$key] ?? 0);
    $overdueContractTrendData[] = (int)($overdueContractMap[$key] ?? 0);
    $followupTrendData[] = (int)($followupTrendMap[$key] ?? 0);
    $followupDoneTrendData[] = (int)($followupDoneTrendMap[$key] ?? 0);
}

$overdueRate = 0.0;
if ($stats['contracts_total'] > 0) {
    $overdueRate = ($stats['overdue_contracts'] / $stats['contracts_total']) * 100;
}
$followupSuccessRate = 0.0;
if ($stats['followups_period'] > 0) {
    $followupSuccessRate = ($stats['done_followups_period'] / $stats['followups_period']) * 100;
}

include __DIR__ . '/partials/head.php';
?>

<section class="card fr-card mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-xl-3 col-md-4 col-6">
                <label class="form-label mb-1">เดือนเริ่ม</label>
                <input class="form-control" type="month" name="from_month" value="<?php echo h($fromMonth); ?>">
            </div>
            <div class="col-xl-3 col-md-4 col-6">
                <label class="form-label mb-1">เดือนสิ้นสุด</label>
                <input class="form-control" type="month" name="to_month" value="<?php echo h($toMonth); ?>">
            </div>
            <div class="col-xl-6 col-md-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit">แสดงผล Dashboard</button>
                <a class="btn btn-outline-secondary" href="<?php echo h(fresher_base_url('executive_dashboard.php')); ?>">รีเซ็ต</a>
                <a class="btn btn-outline-primary" href="<?php echo h(fresher_base_url('collections.php')); ?>">ไปหน้าติดตามหนี้</a>
            </div>
        </form>
        <div class="small text-muted mt-2">
            ช่วงรายงาน: <?php echo h(date('d/m/Y', strtotime($periodStart))); ?> - <?php echo h(date('d/m/Y', strtotime($periodEnd))); ?> (ข้อมูลรวมทั้งบริษัท)
        </div>
    </div>
</section>

<section class="row g-3 mb-3">
    <div class="col-xl-2 col-md-4 col-6"><div class="fr-stat"><span>สัญญาทั้งหมด</span><strong><?php echo number_format($stats['contracts_total']); ?></strong></div></div>
    <div class="col-xl-2 col-md-4 col-6"><div class="fr-stat"><span>ค้างชำระ</span><strong><?php echo number_format($stats['overdue_contracts']); ?></strong></div></div>
    <div class="col-xl-2 col-md-4 col-6"><div class="fr-stat"><span>อัตราค้างชำระ</span><strong><?php echo number_format($overdueRate, 2); ?>%</strong></div></div>
    <div class="col-xl-2 col-md-4 col-6"><div class="fr-stat"><span>DELINQUENT</span><strong><?php echo number_format($stats['contracts_delinquent']); ?></strong></div></div>
    <div class="col-xl-2 col-md-4 col-6"><div class="fr-stat"><span>NPL</span><strong><?php echo number_format($stats['contracts_npl']); ?></strong></div></div>
    <div class="col-xl-2 col-md-4 col-6"><div class="fr-stat"><span>ปิดสัญญาแล้ว</span><strong><?php echo number_format($stats['contracts_closed']); ?></strong></div></div>
    <div class="col-xl-3 col-md-6 col-6"><div class="fr-stat"><span>ยอดค้างชำระรวม</span><strong><?php echo number_format($stats['overdue_due_amount'], 2); ?></strong></div></div>
    <div class="col-xl-3 col-md-6 col-6"><div class="fr-stat"><span>เงินต้นคงเหลือ</span><strong><?php echo number_format($stats['remaining_principal'], 2); ?></strong></div></div>
    <div class="col-xl-3 col-md-6 col-6"><div class="fr-stat"><span>ดอกเบี้ยคงเหลือ</span><strong><?php echo number_format($stats['remaining_interest'], 2); ?></strong></div></div>
    <div class="col-xl-3 col-md-6 col-6"><div class="fr-stat"><span>PTP ครบกำหนด 7 วัน</span><strong><?php echo number_format($stats['promise_due_7d']); ?></strong></div></div>
    <div class="col-xl-3 col-md-6 col-6"><div class="fr-stat"><span>งานติดตามในช่วง</span><strong><?php echo number_format($stats['followups_period']); ?></strong></div></div>
    <div class="col-xl-3 col-md-6 col-6"><div class="fr-stat"><span>ติดตามสำเร็จ (DONE)</span><strong><?php echo number_format($stats['done_followups_period']); ?></strong></div></div>
    <div class="col-xl-3 col-md-6 col-6"><div class="fr-stat"><span>อัตราสำเร็จติดตาม</span><strong><?php echo number_format($followupSuccessRate, 2); ?>%</strong></div></div>
    <div class="col-xl-3 col-md-6 col-6"><div class="fr-stat"><span>Penalty + Fee</span><strong><?php echo number_format($stats['month_collection_fee'] + $stats['month_late_penalty'], 2); ?></strong></div></div>
</section>

<section class="row g-3 mb-3">
    <div class="col-lg-6">
        <section class="card fr-card h-100">
            <div class="card-header bg-white"><strong>แนวโน้มยอดค้างชำระรายเดือน</strong></div>
            <div class="card-body"><canvas id="overdueTrendChart" height="120"></canvas></div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="card fr-card h-100">
            <div class="card-header bg-white"><strong>แนวโน้มงานติดตามรายเดือน</strong></div>
            <div class="card-body"><canvas id="followupTrendChart" height="120"></canvas></div>
        </section>
    </div>
</section>

<section class="card fr-card mb-3">
    <div class="card-header bg-white"><strong>สรุป Bucket DPD</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 js-fresher-datatable">
            <thead>
            <tr>
                <th>Bucket</th>
                <th>จำนวนงวดค้าง</th>
                <th>ยอดค้างรวม</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($dpdBucketRows as $row): ?>
                <tr>
                    <td><?php echo h((string)$row['dpd_bucket']); ?></td>
                    <td><?php echo number_format((int)$row['installments']); ?></td>
                    <td><?php echo number_format((float)$row['overdue_due_amount'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="row g-3 mb-3">
    <div class="col-xl-6">
        <section class="card fr-card h-100">
            <div class="card-header bg-white"><strong>ความเสี่ยงรายสาขา</strong></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 js-fresher-datatable">
                    <thead>
                    <tr>
                        <th>สาขา</th>
                        <th>สัญญา</th>
                        <th>Delinquent</th>
                        <th>NPL</th>
                        <th>ยอดค้าง</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($branchRows as $row): ?>
                        <tr>
                            <td><?php echo h((string)$row['branch_code']); ?></td>
                            <td><?php echo number_format((int)$row['contracts']); ?></td>
                            <td><?php echo number_format((int)$row['delinquent_contracts']); ?></td>
                            <td><?php echo number_format((int)$row['npl_contracts']); ?></td>
                            <td><?php echo number_format((float)$row['overdue_due_amount'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-xl-6">
        <section class="card fr-card h-100">
            <div class="card-header bg-white"><strong>ผลงานพนักงานติดตาม</strong></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 js-fresher-datatable">
                    <thead>
                    <tr>
                        <th>ผู้ติดตาม</th>
                        <th>งานติดตาม</th>
                        <th>DONE</th>
                        <th>ค่าทวงถาม</th>
                        <th>ค่าปรับล่าช้า</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($collectorRows as $row): ?>
                        <tr>
                            <td><?php echo h((string)$row['collector_name']); ?></td>
                            <td><?php echo number_format((int)$row['followups']); ?></td>
                            <td><?php echo number_format((int)$row['done_count']); ?></td>
                            <td><?php echo number_format((float)$row['total_fee'], 2); ?></td>
                            <td><?php echo number_format((float)$row['total_penalty'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</section>

<section class="card fr-card mb-4">
    <div class="card-header bg-white"><strong>รายการค้างชำระเร่งด่วน</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 js-fresher-datatable">
            <thead>
            <tr>
                <th>รหัสสัญญา</th>
                <th>ลูกค้า</th>
                <th>สาขา</th>
                <th>DPD สูงสุด</th>
                <th>ยอดค้าง</th>
                <th>การจัดการ</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($urgentRows as $row): ?>
                <tr>
                    <td><code><?php echo h((string)$row['contract_code']); ?></code></td>
                    <td><?php echo h((string)$row['customer_name']); ?></td>
                    <td><?php echo h((string)$row['branch_code']); ?></td>
                    <td><?php echo number_format((int)$row['max_dpd']); ?></td>
                    <td><?php echo number_format((float)$row['overdue_due_amount'], 2); ?></td>
                    <td>
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo h(fresher_base_url('installments.php?contract_code=' . rawurlencode((string)$row['contract_code']))); ?>">งวดชำระ</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo h(fresher_base_url('collections.php')); ?>">ติดตามหนี้</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    const labels = <?php echo json_encode($monthLabels, JSON_UNESCAPED_UNICODE); ?>;
    const overdueAmount = <?php echo json_encode($overdueTrendData, JSON_UNESCAPED_UNICODE); ?>;
    const overdueContracts = <?php echo json_encode($overdueContractTrendData, JSON_UNESCAPED_UNICODE); ?>;
    const followups = <?php echo json_encode($followupTrendData, JSON_UNESCAPED_UNICODE); ?>;
    const doneFollowups = <?php echo json_encode($followupDoneTrendData, JSON_UNESCAPED_UNICODE); ?>;

    const overdueCtx = document.getElementById('overdueTrendChart');
    if (overdueCtx && typeof Chart !== 'undefined') {
        new Chart(overdueCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'ยอดค้างชำระ',
                        data: overdueAmount,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37,99,235,0.12)',
                        yAxisID: 'y',
                        tension: 0.28,
                        fill: true
                    },
                    {
                        label: 'จำนวนสัญญาค้าง',
                        data: overdueContracts,
                        borderColor: '#dc2626',
                        backgroundColor: 'rgba(220,38,38,0.08)',
                        yAxisID: 'y1',
                        tension: 0.2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: {
                        position: 'left',
                        ticks: {
                            callback: function(value) { return Number(value).toLocaleString(); }
                        }
                    },
                    y1: {
                        position: 'right',
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
    }

    const followupCtx = document.getElementById('followupTrendChart');
    if (followupCtx && typeof Chart !== 'undefined') {
        new Chart(followupCtx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'งานติดตามทั้งหมด',
                        data: followups,
                        backgroundColor: 'rgba(59,130,246,0.65)'
                    },
                    {
                        label: 'งานที่ปิดแล้ว (DONE)',
                        data: doneFollowups,
                        backgroundColor: 'rgba(22,163,74,0.65)'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>

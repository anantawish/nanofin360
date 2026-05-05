<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/hire_purchase_core.php';

$moduleKey = 'collections_workflow';
$module = module_by_key($moduleKey);
if ($module === null) {
    http_response_code(500);
    echo 'Module not found';
    exit;
}

function cw_quote(array $row, string $asOf, float $annualRatePct): array
{
    $due = round(max(0.0, hp_float($row['installment_due'] ?? 0)), 2);
    $paid = round(max(0.0, hp_float($row['paid_amount'] ?? 0)), 2);
    $remain = round(max(0.0, $due - $paid), 2);
    $status = strtoupper(trim((string)($row['payment_status'] ?? 'UNPAID')));

    $days = 0;
    $dueDate = trim((string)($row['due_date'] ?? ''));
    if ($status !== 'PAID' && $remain > 0 && $dueDate !== '') {
        try {
            $d1 = new DateTimeImmutable(hp_parse_date($dueDate, $asOf));
            $d2 = new DateTimeImmutable(hp_parse_date($asOf, $asOf));
            $diff = (int)$d1->diff($d2)->format('%r%a');
            $days = $diff > 0 ? $diff : 0;
        } catch (Throwable $e) {
            $days = 0;
        }
    }

    $principal = round(max(0.0, hp_float($row['principal'] ?? $remain)), 2);
    $lateRate = max(0.0, $annualRatePct + 3.0) / 100.0;
    $late = ($days > 0 && $principal > 0) ? round($principal * $lateRate * ($days / 365), 2) : 0.0;
    $follow = ($days > 0 && $remain > 0) ? 100.0 : 0.0;

    return ['remain' => $remain, 'days' => $days, 'late' => $late, 'follow' => $follow];
}

function cw_bucket(int $days): string
{
    return $days <= 0 ? 'CURRENT' : ($days <= 7 ? '1-7' : ($days <= 30 ? '8-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '90+'))));
}

function cw_source_label(string $source): string
{
    return match (strtoupper(trim($source))) {
        'SCHEDULE' => 'เลยกำหนดจากตารางชำระ',
        'NO_PAY' => 'ถูกบันทึกไม่ชำระ',
        'BOTH' => 'ทั้ง 2 แบบ',
        default => '-',
    };
}

function cw_overdue_summary(array $contract, string $asOf): array
{
    $payload = is_array($contract['payload'] ?? null) ? $contract['payload'] : [];
    $annualRatePct = round(max(0.0, hp_float($payload['annual_rate_pct'] ?? 0)), 4);
    $history = $payload['payment_history'] ?? [];
    if (!is_array($history)) {
        $history = [];
    }

    $pending = 0;
    $schedule = 0;
    $noPay = 0;
    $maxDays = 0;
    $firstDue = '';
    $firstNo = 0;
    $totalRemain = 0.0;

    foreach ($history as $row) {
        if (!is_array($row)) {
            continue;
        }
        $quote = cw_quote($row, $asOf, $annualRatePct);
        $status = strtoupper(trim((string)($row['payment_status'] ?? 'UNPAID')));
        if ($status === 'PAID' || (float)$quote['remain'] <= 0) {
            continue;
        }
        $isSchedule = (int)$quote['days'] > 0;
        $isNoPay = $status === 'NO_PAY';
        if (!$isSchedule && !$isNoPay) {
            continue;
        }

        $pending++;
        if ($isSchedule) {
            $schedule++;
        }
        if ($isNoPay) {
            $noPay++;
        }
        $maxDays = max($maxDays, (int)$quote['days']);
        $totalRemain += (float)$quote['remain'];

        $dueDate = trim((string)($row['due_date'] ?? ''));
        if ($dueDate !== '' && ($firstDue === '' || strcmp($dueDate, $firstDue) < 0)) {
            $firstDue = $dueDate;
            $firstNo = max(0, (int)($row['installment_no'] ?? 0));
        }
    }

    $source = 'NONE';
    if ($schedule > 0 && $noPay > 0) {
        $source = 'BOTH';
    } elseif ($schedule > 0) {
        $source = 'SCHEDULE';
    } elseif ($noPay > 0) {
        $source = 'NO_PAY';
    }

    $bucket = cw_bucket($maxDays);
    if ($maxDays <= 0) {
        $payloadBucket = strtoupper(trim((string)($payload['dpd_bucket'] ?? '')));
        if ($payloadBucket !== '') {
            $bucket = $payloadBucket;
        }
    }

    return [
        'source' => $source,
        'source_label' => cw_source_label($source),
        'pending' => $pending,
        'max_days' => $maxDays,
        'first_due' => $firstDue,
        'first_no' => $firstNo,
        'total_remain' => round($totalRemain, 2),
        'bucket' => $bucket,
    ];
}

function cw_contact_map(array $customerCodes, array $scope): array
{
    $codes = [];
    foreach ($customerCodes as $code) {
        $code = strtoupper(trim((string)$code));
        if ($code !== '') {
            $codes[$code] = true;
        }
    }
    $codes = array_keys($codes);
    if ($codes === []) {
        return [];
    }

    $scopeClause = access_scope_sql_clause('wr.branch_code', 'cw_scope_cus', $scope);
    $params = $scopeClause['params'];
    $ph = [];
    foreach (array_values($codes) as $i => $code) {
        $key = ':c' . $i;
        $ph[] = $key;
        $params[$key] = $code;
    }

    $sql = 'SELECT wr.primary_ref, wr.primary_name, wr.data_json
            FROM workflow_records wr
            WHERE wr.module_key = "customer_360"
              AND wr.is_latest = 1
              AND wr.is_deleted = 0
              ' . $scopeClause['sql'] . '
              AND wr.primary_ref IN (' . implode(',', $ph) . ')';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $map = [];
    foreach ($rows as $row) {
        $code = strtoupper(trim((string)($row['primary_ref'] ?? '')));
        if ($code === '') {
            continue;
        }
        $payload = hp_decode_json_assoc((string)($row['data_json'] ?? ''));
        $phone = trim((string)($payload['phone_number'] ?? ''));
        if ($phone === '') {
            $phone = trim((string)($payload['mobile_number'] ?? ''));
        }
        if ($phone === '') {
            $phone = trim((string)($payload['telephone'] ?? ''));
        }
        $name = trim((string)($payload['customer_name'] ?? ($row['primary_name'] ?? '')));
        $map[$code] = ['phone' => $phone, 'name' => $name];
    }
    return $map;
}

function cw_history_map(array $contractNos, array $scope, string $branchCode): array
{
    $set = [];
    foreach ($contractNos as $no) {
        $no = strtoupper(trim((string)$no));
        if ($no !== '') {
            $set[$no] = true;
        }
    }
    $contractNos = array_keys($set);
    if ($contractNos === []) {
        return [];
    }

    $scopeClause = access_scope_sql_clause('wr.branch_code', 'cw_scope_hist', $scope);
    $params = $scopeClause['params'];
    $ph = [];
    foreach (array_values($contractNos) as $i => $no) {
        $key = ':h' . $i;
        $ph[] = $key;
        $params[$key] = $no;
    }

    $sql = 'SELECT wr.id, wr.primary_name, wr.created_by, wr.created_at, wr.data_json
            FROM workflow_records wr
            WHERE wr.module_key = "collections_workflow"
              AND wr.is_latest = 1
              AND wr.is_deleted = 0
              ' . $scopeClause['sql'] . '
              AND wr.primary_name IN (' . implode(',', $ph) . ')';
    if ($branchCode !== '') {
        $sql .= ' AND wr.branch_code = :b';
        $params[':b'] = $branchCode;
    }
    $sql .= ' ORDER BY wr.id DESC LIMIT 5000';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $map = [];
    foreach ($rows as $row) {
        $payload = hp_decode_json_assoc((string)($row['data_json'] ?? ''));
        $contractNo = strtoupper(trim((string)($payload['contract_no'] ?? ($row['primary_name'] ?? ''))));
        if ($contractNo === '' || !isset($set[$contractNo])) {
            continue;
        }
        $source = strtoupper(trim((string)($payload['overdue_source'] ?? '')));
        $map[$contractNo][] = [
            'created_at' => (string)($row['created_at'] ?? ''),
            'collector' => trim((string)($payload['collector_name'] ?? ($row['created_by'] ?? ''))),
            'channel' => strtoupper(trim((string)($payload['contact_channel'] ?? 'CALL'))),
            'note' => trim((string)($payload['contact_outcome'] ?? '')),
            'next_contact_date' => trim((string)($payload['ptp_date'] ?? '')),
            'source_label' => cw_source_label($source),
        ];
    }
    return $map;
}

function cw_save_followup(array $contract, array $summary, string $channel, string $note, string $nextDate, string $phone): string
{
    $contractNo = strtoupper(trim((string)($contract['contract_no'] ?? '')));
    $customerCode = strtoupper(trim((string)($contract['customer_code'] ?? '')));
    $branchCode = strtoupper(trim((string)($contract['branch_code'] ?? '')));
    $payloadContract = is_array($contract['payload'] ?? null) ? $contract['payload'] : [];
    $customerName = trim((string)($payloadContract['customer_name'] ?? ''));

    $caseNo = hp_generate_code('COL');
    $now = now_dt();
    $actor = current_user_name();
    $role = current_role_name();
    $ip = request_ip();
    $device = request_device();

    $payload = [
        'collection_case_no' => $caseNo,
        'contract_no' => $contractNo,
        'customer_code' => $customerCode,
        'customer_name' => $customerName,
        'phone_number' => $phone,
        'dpd_days' => (int)($summary['max_days'] ?? 0),
        'bucket_name' => (string)($summary['bucket'] ?? ''),
        'overdue_source' => (string)($summary['source'] ?? 'NONE'),
        'contact_channel' => $channel,
        'contact_outcome' => $note,
        'ptp_date' => $nextDate,
        'collector_name' => $actor,
        'first_due_date' => (string)($summary['first_due'] ?? ''),
        'first_overdue_installment_no' => (int)($summary['first_no'] ?? 0),
        'pending_installment_count' => (int)($summary['pending'] ?? 0),
        'total_remain' => round(max(0.0, hp_float($summary['total_remain'] ?? 0)), 2),
        'created_from' => 'collections_workflow_modal',
    ];

    $stmt = db()->prepare(
        'INSERT INTO workflow_records (
            module_key, record_uid, version_no, is_latest, is_deleted, record_status,
            primary_name, primary_ref, customer_ref, branch_code, risk_level, amount, event_date,
            data_json, consent_flag, risk_flags, note_text,
            created_by, created_role, created_at, created_ip, created_device,
            updated_by, updated_role, updated_at, updated_ip, updated_device,
            checker_by, checker_at, deleted_by, deleted_at
        ) VALUES (
            :module_key, :record_uid, 1, 1, 0, "APPROVED",
            :primary_name, :primary_ref, :customer_ref, :branch_code, :risk_level, :amount, :event_date,
            :data_json, 0, "", :note_text,
            :created_by, :created_role, :created_at, :created_ip, :created_device,
            :updated_by, :updated_role, :updated_at, :updated_ip, :updated_device,
            :checker_by, :checker_at, NULL, NULL
        )'
    );
    $stmt->execute([
        ':module_key' => 'collections_workflow',
        ':record_uid' => hp_generate_code('CWF'),
        ':primary_name' => $contractNo,
        ':primary_ref' => $caseNo,
        ':customer_ref' => $customerCode,
        ':branch_code' => $branchCode,
        ':risk_level' => (string)($summary['bucket'] ?? ''),
        ':amount' => round(max(0.0, hp_float($summary['total_remain'] ?? 0)), 2),
        ':event_date' => $nextDate !== '' ? $nextDate : null,
        ':data_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':note_text' => $note,
        ':created_by' => $actor,
        ':created_role' => $role,
        ':created_at' => $now,
        ':created_ip' => $ip,
        ':created_device' => $device,
        ':updated_by' => $actor,
        ':updated_role' => $role,
        ':updated_at' => $now,
        ':updated_ip' => $ip,
        ':updated_device' => $device,
        ':checker_by' => $actor,
        ':checker_at' => $now,
    ]);
    return $caseNo;
}

$scope = current_access_scope();
$allowCodes = accessible_branch_codes($scope);
$branchRows = [];
foreach (active_branch_rows() as $b) {
    $bc = strtoupper(trim((string)($b['branch_code'] ?? '')));
    if ($scope['scope'] !== 'all' && !in_array($bc, $allowCodes, true)) {
        continue;
    }
    $branchRows[] = $b;
}

$selectedBranch = strtoupper(trim((string)($_GET['branch_code'] ?? '')));
if ($selectedBranch !== '' && !is_branch_in_current_scope($selectedBranch, $scope)) {
    $selectedBranch = '';
}
$searchText = trim((string)($_GET['q'] ?? ''));
$openContractNo = strtoupper(trim((string)($_GET['open_contract_no'] ?? '')));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $selectedBranch = strtoupper(trim((string)($_POST['branch_code'] ?? $selectedBranch)));
    $searchText = trim((string)($_POST['q'] ?? $searchText));
    $openContractNo = strtoupper(trim((string)($_POST['contract_no'] ?? '')));
    try {
        verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));
        if ((string)($_POST['form_action'] ?? '') !== 'save_collection_followup') {
            throw new RuntimeException('Invalid action');
        }
        if ($selectedBranch !== '' && !is_branch_in_current_scope($selectedBranch, $scope)) {
            throw new RuntimeException('No permission for branch');
        }

        $contractNo = strtoupper(trim((string)($_POST['contract_no'] ?? '')));
        $channel = strtoupper(trim((string)($_POST['contact_channel'] ?? 'CALL')));
        $note = trim((string)($_POST['contact_outcome'] ?? ''));
        $nextDateRaw = trim((string)($_POST['next_contact_date'] ?? ''));
        $nextDate = $nextDateRaw !== '' ? hp_parse_date($nextDateRaw, date('Y-m-d')) : '';
        $phone = trim((string)($_POST['phone_number'] ?? ''));
        if ($contractNo === '' || $note === '') {
            throw new RuntimeException('ข้อมูลไม่ครบ');
        }
        if (!in_array($channel, ['CALL', 'SMS', 'LINE', 'VISIT'], true)) {
            $channel = 'CALL';
        }

        $contract = hp_find_contract_latest($contractNo, $scope);
        if (!$contract) {
            throw new RuntimeException('ไม่พบสัญญา');
        }
        assert_branch_in_current_scope((string)($contract['branch_code'] ?? ''));
        $summary = cw_overdue_summary($contract, date('Y-m-d'));
        if ((string)($summary['source'] ?? 'NONE') === 'NONE') {
            throw new RuntimeException('สัญญานี้ไม่ได้อยู่ในสถานะค้างชำระแล้ว');
        }
        if ($phone === '') {
            $map = cw_contact_map([(string)($contract['customer_code'] ?? '')], $scope);
            $customerCode = strtoupper(trim((string)($contract['customer_code'] ?? '')));
            if ($customerCode !== '' && isset($map[$customerCode])) {
                $phone = (string)($map[$customerCode]['phone'] ?? '');
            }
        }

        $caseNo = cw_save_followup($contract, $summary, $channel, $note, $nextDate, $phone);
        add_flash('success', 'บันทึกการติดตามสำเร็จ เลขเคส: ' . $caseNo);
        redirect_to(app_base_url('modules/04_collections_workflow.php?branch_code=' . rawurlencode($selectedBranch) . '&q=' . rawurlencode($searchText) . '&open_contract_no=' . rawurlencode($contractNo)));
    } catch (Throwable $e) {
        add_flash('danger', 'บันทึกการติดตามไม่สำเร็จ: ' . $e->getMessage());
        redirect_to(app_base_url('modules/04_collections_workflow.php?branch_code=' . rawurlencode($selectedBranch) . '&q=' . rawurlencode($searchText) . '&open_contract_no=' . rawurlencode($openContractNo)));
    }
}

$hasSearch = ($selectedBranch !== '' || $searchText !== '');
$contracts = $hasSearch ? hp_fetch_contract_rows($scope, $searchText, $selectedBranch, '') : [];
$cases = [];
$contractNos = [];
$customerCodes = [];
foreach ($contracts as $contract) {
    $summary = cw_overdue_summary($contract, date('Y-m-d'));
    if ((string)($summary['source'] ?? 'NONE') === 'NONE') {
        continue;
    }
    $payload = is_array($contract['payload'] ?? null) ? $contract['payload'] : [];
    $contractNo = strtoupper(trim((string)($contract['contract_no'] ?? '')));
    $customerCode = strtoupper(trim((string)($contract['customer_code'] ?? '')));
    if ($contractNo === '') {
        continue;
    }
    $cases[] = [
        'contract_no' => $contractNo,
        'customer_code' => $customerCode,
        'customer_name' => trim((string)($payload['customer_name'] ?? $customerCode)),
        'branch_code' => strtoupper(trim((string)($contract['branch_code'] ?? ''))),
        'phone_number' => '',
        'source' => (string)$summary['source'],
        'source_label' => (string)$summary['source_label'],
        'pending' => (int)$summary['pending'],
        'max_days' => (int)$summary['max_days'],
        'first_due' => (string)$summary['first_due'],
        'first_no' => (int)$summary['first_no'],
        'total_remain' => (float)$summary['total_remain'],
        'last_at' => '',
        'last_by' => '',
    ];
    $contractNos[] = $contractNo;
    if ($customerCode !== '') {
        $customerCodes[] = $customerCode;
    }
}

usort($cases, static function (array $a, array $b): int {
    $cmp = ((int)$b['max_days']) <=> ((int)$a['max_days']);
    if ($cmp !== 0) {
        return $cmp;
    }
    $cmp = strcmp((string)$a['first_due'], (string)$b['first_due']);
    if ($cmp !== 0) {
        return $cmp;
    }
    return strcmp((string)$a['contract_no'], (string)$b['contract_no']);
});

$contactMap = cw_contact_map($customerCodes, $scope);
foreach ($cases as &$case) {
    $code = strtoupper(trim((string)$case['customer_code']));
    if ($code !== '' && isset($contactMap[$code])) {
        $case['phone_number'] = (string)($contactMap[$code]['phone'] ?? '');
        if (trim((string)$case['customer_name']) === '' && trim((string)($contactMap[$code]['name'] ?? '')) !== '') {
            $case['customer_name'] = (string)$contactMap[$code]['name'];
        }
    }
}
unset($case);

$historyMap = cw_history_map($contractNos, $scope, $selectedBranch);
foreach ($cases as &$case) {
    $rows = $historyMap[(string)$case['contract_no']] ?? [];
    if ($rows !== []) {
        $case['last_at'] = (string)($rows[0]['created_at'] ?? '');
        $case['last_by'] = (string)($rows[0]['collector'] ?? '');
    }
}
unset($case);

$pageTitle = (string)($module['title'] ?? 'Collections Workflow');
$currentModule = $moduleKey;
include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/menu.php';
?>
<section class="card shadow-sm border-0 mb-4 module-hero">
    <div class="card-body">
        <h1 class="h4 mb-2">ระบบติดตามทวงถามหนี้ (Collections Workflow)</h1>
        <p class="mb-0 text-muted">แสดงลูกหนี้ค้างชำระจาก 2 เงื่อนไข: เลยกำหนดตามตารางชำระ และถูกบันทึก NO_PAY จากโมดูลค่างวด</p>
    </div>
</section>

<section class="card shadow-sm border-0 mb-4 module-toolbar">
    <div class="card-body">
        <form class="row g-2 align-items-end module-search" method="get" action="<?php echo h(app_base_url('modules/04_collections_workflow.php')); ?>">
            <div class="col-lg-3 col-md-4">
                <label class="form-label">สาขา</label>
                <select class="form-select" name="branch_code">
                    <option value="">ทุกสาขาในสิทธิ์</option>
                    <?php foreach ($branchRows as $b): $bc = strtoupper(trim((string)($b['branch_code'] ?? ''))); $bn = trim((string)($b['branch_name'] ?? '')); ?>
                    <option value="<?php echo h($bc); ?>" <?php echo $selectedBranch === $bc ? 'selected' : ''; ?>><?php echo h($bc . ($bn !== '' ? (' - ' . $bn) : '')); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-6 col-md-8">
                <label class="form-label">ค้นหาเลขสัญญา / รหัสลูกค้า / ชื่อลูกค้า</label>
                <input class="form-control" name="q" value="<?php echo h($searchText); ?>" placeholder="พิมพ์คำค้นหา">
            </div>
            <div class="col-lg-3 col-md-12 d-flex gap-2">
                <button class="btn btn-brand flex-grow-1" type="submit">ค้นหา</button>
                <a class="btn btn-outline-secondary" href="<?php echo h(app_base_url('modules/04_collections_workflow.php')); ?>">ล้าง</a>
            </div>
        </form>
    </div>
</section>

<section class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <strong>รายการลูกหนี้ค้างชำระ</strong>
        <span class="text-muted small">พบ <?php echo number_format(count($cases)); ?> รายการ</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 js-admin-datatable">
            <thead>
            <tr>
                <th>เลขสัญญา</th>
                <th>รหัสลูกค้า</th>
                <th>ชื่อลูกค้า</th>
                <th>เบอร์โทร</th>
                <th>สาขา</th>
                <th>ประเภทค้าง</th>
                <th>งวดค้าง</th>
                <th>วันค้างสูงสุด</th>
                <th>กำหนดชำระแรก</th>
                <th>ยอดค้างรวม</th>
                <th>ติดตามล่าสุด</th>
                <th>การจัดการ</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$hasSearch): ?>
                <tr><td class="text-center text-muted">เลือกสาขาแล้วกดค้นหา</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            <?php elseif ($cases === []): ?>
                <tr><td class="text-center text-muted">ไม่พบรายการค้างชำระ</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            <?php else: foreach ($cases as $case): $src = strtoupper((string)$case['source']); $srcClass = $src === 'BOTH' ? 'danger' : ($src === 'SCHEDULE' ? 'warning text-dark' : 'secondary'); ?>
                <tr>
                    <td><code><?php echo h((string)$case['contract_no']); ?></code></td>
                    <td><?php echo h((string)$case['customer_code']); ?></td>
                    <td><?php echo h((string)$case['customer_name']); ?></td>
                    <td><?php echo h((string)$case['phone_number'] !== '' ? (string)$case['phone_number'] : '-'); ?></td>
                    <td><?php echo h((string)$case['branch_code']); ?></td>
                    <td><span class="badge text-bg-<?php echo h($srcClass); ?>"><?php echo h((string)$case['source_label']); ?></span></td>
                    <td><?php echo number_format((int)$case['pending']); ?></td>
                    <td><?php echo number_format((int)$case['max_days']); ?></td>
                    <td><?php echo h((string)$case['first_due'] !== '' ? ((string)$case['first_due'] . ((int)$case['first_no'] > 0 ? (' (งวด ' . (int)$case['first_no'] . ')') : '')) : '-'); ?></td>
                    <td><?php echo number_format((float)$case['total_remain'], 2); ?></td>
                    <td class="small"><?php echo h((string)$case['last_at'] !== '' ? ((string)$case['last_at'] . ((string)$case['last_by'] !== '' ? (' / ' . (string)$case['last_by']) : '')) : '-'); ?></td>
                    <td>
                        <div class="d-flex flex-column gap-1">
                            <button type="button" class="btn btn-sm btn-brand btn-open-followup-modal" data-bs-toggle="modal" data-bs-target="#followupModal" data-contract="<?php echo h((string)$case['contract_no']); ?>" data-customer-code="<?php echo h((string)$case['customer_code']); ?>" data-customer-name="<?php echo h((string)$case['customer_name']); ?>" data-phone="<?php echo h((string)$case['phone_number']); ?>" data-source-label="<?php echo h((string)$case['source_label']); ?>">ติดตามทวงถาม</button>
                            <a class="btn btn-sm btn-outline-primary" href="<?php echo h(app_base_url('modules/04_installments.php?branch_code=' . rawurlencode((string)$selectedBranch) . '&q=' . rawurlencode((string)$searchText) . '&contract_no=' . rawurlencode((string)$case['contract_no']) . '&open_schedule=1')); ?>">ดูตารางชำระ</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="modal fade modal-slide-down" id="followupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog sf-resizable-modal modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <form method="post" class="validate-form" novalidate>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="form_action" value="save_collection_followup">
                <input type="hidden" name="branch_code" value="<?php echo h($selectedBranch); ?>">
                <input type="hidden" name="q" value="<?php echo h($searchText); ?>">
                <input type="hidden" name="contract_no" id="follow_contract_no" value="">
                <input type="hidden" name="phone_number" id="follow_phone_hidden" value="">
                <div class="modal-header">
                    <h2 class="h6 mb-0">ติดตามทวงถามลูกหนี้</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-3"><label class="form-label">เลขสัญญา</label><input class="form-control" id="follow_contract_display" type="text" readonly></div>
                        <div class="col-md-4"><label class="form-label">ลูกค้า</label><input class="form-control" id="follow_customer_display" type="text" readonly></div>
                        <div class="col-md-3"><label class="form-label">เบอร์โทร</label><input class="form-control" id="follow_phone_display" type="text" readonly></div>
                        <div class="col-md-2"><label class="form-label">ประเภทค้าง</label><input class="form-control" id="follow_source_display" type="text" readonly></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-3"><label class="form-label">ช่องทางติดต่อ *</label><select class="form-select" name="contact_channel" id="follow_channel" required><option value="CALL">โทร</option><option value="SMS">SMS</option><option value="LINE">LINE</option><option value="VISIT">ลงพื้นที่</option></select></div>
                        <div class="col-md-3"><label class="form-label">วันนัดการสนทนา</label><input class="form-control" type="date" name="next_contact_date" id="follow_next_date"></div>
                        <div class="col-md-6"><label class="form-label">ข้อความล่าสุด *</label><input class="form-control" type="text" name="contact_outcome" id="follow_note" maxlength="255" required></div>
                    </div>
                    <hr class="my-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h3 class="h6 mb-0">ประวัติโทรติดตามทั้งหมด</h3>
                        <span class="text-muted small">ล่าสุดขึ้นก่อน</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover align-middle mb-0" id="followHistoryTable">
                            <thead><tr><th>เวลาบันทึก</th><th>ผู้บันทึก</th><th>ช่องทาง</th><th>ข้อความ</th><th>วันนัดสนทนา</th><th>แหล่งค้างชำระ</th></tr></thead>
                            <tbody id="followHistoryBody"><tr><td class="text-center text-muted" colspan="6">ยังไม่มีประวัติการติดตาม</td></tr></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-brand">บันทึกการติดตาม</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var historyMap = <?php echo json_encode($historyMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var openContractNo = <?php echo json_encode($openContractNo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var modalEl = document.getElementById('followupModal');
    var tableSelector = '#followHistoryTable';

    function esc(v) {
        return String(v || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderHistory(contractNo) {
        var rows = historyMap[contractNo] || [];
        var body = document.getElementById('followHistoryBody');
        if (!body) return;

        if (window.jQuery && jQuery.fn && jQuery.fn.DataTable && jQuery.fn.DataTable.isDataTable(tableSelector)) {
            jQuery(tableSelector).DataTable().destroy();
        }

        if (!rows.length) {
            // Leave tbody empty and let DataTables render its emptyTable message.
            body.innerHTML = '';
        } else {
            var html = '';
            rows.forEach(function (r) {
                html += '<tr><td>' + esc(r.created_at || '-') + '</td><td>' + esc(r.collector || '-') + '</td><td>' + esc(r.channel || '-') + '</td><td>' + esc(r.note || '-') + '</td><td>' + esc(r.next_contact_date || '-') + '</td><td>' + esc(r.source_label || '-') + '</td></tr>';
            });
            body.innerHTML = html;
        }

        if (window.jQuery && jQuery.fn && jQuery.fn.DataTable) {
            jQuery(tableSelector).DataTable({
                pageLength: 10,
                lengthChange: false,
                searching: false,
                info: true,
                autoWidth: false,
                order: [[0, 'desc']],
                language: {
                    emptyTable: 'ยังไม่มีประวัติการติดตาม'
                }
            });
        }
    }

    function openFromButton(btn) {
        var contractNo = btn.getAttribute('data-contract') || '';
        var customerCode = btn.getAttribute('data-customer-code') || '';
        var customerName = btn.getAttribute('data-customer-name') || '';
        var phone = btn.getAttribute('data-phone') || '';
        var sourceLabel = btn.getAttribute('data-source-label') || '';

        document.getElementById('follow_contract_no').value = contractNo;
        document.getElementById('follow_phone_hidden').value = phone;
        document.getElementById('follow_contract_display').value = contractNo;
        document.getElementById('follow_customer_display').value = customerName + (customerCode ? (' (' + customerCode + ')') : '');
        document.getElementById('follow_phone_display').value = phone || '-';
        document.getElementById('follow_source_display').value = sourceLabel || '-';
        document.getElementById('follow_channel').value = 'CALL';
        document.getElementById('follow_next_date').value = '';
        document.getElementById('follow_note').value = '';

        renderHistory(contractNo);
    }

    document.querySelectorAll('.btn-open-followup-modal').forEach(function (btn) {
        btn.addEventListener('click', function () { openFromButton(btn); });
    });

    if (modalEl && openContractNo) {
        var autoBtn = document.querySelector('.btn-open-followup-modal[data-contract="' + openContractNo + '"]');
        if (autoBtn) {
            openFromButton(autoBtn);
            function showModal(retry) {
                if (window.bootstrap && window.bootstrap.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    return;
                }
                if (retry < 30) {
                    window.setTimeout(function () { showModal(retry + 1); }, 120);
                }
            }
            if (document.readyState === 'complete') {
                showModal(0);
            } else {
                window.addEventListener('load', function () { showModal(0); }, { once: true });
            }
        }
    }
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>

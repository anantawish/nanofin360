<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$receiptCode = strtoupper(trim((string)($_GET['receipt_code'] ?? '')));
if ($receiptCode === '') {
    add_flash('warning', 'กรุณาระบุเลขที่ใบเสร็จ');
    redirect_to(fresher_base_url('installments.php'));
}

$receipt = fresher_receipt_row($receiptCode);
if (!$receipt) {
    add_flash('danger', 'ไม่พบข้อมูลใบเสร็จที่ระบุ');
    redirect_to(fresher_base_url('installments.php'));
}

assert_branch_in_current_scope((string)($receipt['branch_code'] ?? ''));
$items = fresher_receipt_items($receiptCode);
$contract = fresher_contract_row((string)($receipt['contract_code'] ?? ''));
$autoPrint = ((string)($_GET['auto'] ?? '')) === '1';

$methodLabels = [
    'CASH' => 'เงินสด',
    'TRANSFER' => 'โอนเงิน',
    'PROMPTPAY' => 'พร้อมเพย์/QR',
    'CARD' => 'บัตร',
    'OTHER' => 'อื่น ๆ',
];

function fr_fmt_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '-';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }
    return date('d/m/Y', $ts);
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ใบเสร็จรับเงิน <?php echo h((string)$receipt['receipt_code']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f6f8; font-family: "Prompt", Tahoma, sans-serif; }
        .receipt-wrap { max-width: 980px; margin: 20px auto; }
        .receipt-paper { background: #fff; border: 1px solid #d5dbe6; border-radius: 10px; }
        .receipt-head { border-bottom: 2px solid #2f6fed; }
        .mono { font-family: Consolas, Menlo, monospace; }
        .table th, .table td { vertical-align: middle; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .receipt-wrap { max-width: 100%; margin: 0; }
            .receipt-paper { border: 0; border-radius: 0; }
        }
    </style>
</head>
<body>
<div class="receipt-wrap">
    <div class="d-flex justify-content-between align-items-center mb-2 no-print">
        <div>
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo h(fresher_base_url('installments.php?contract_code=' . rawurlencode((string)$receipt['contract_code']))); ?>">กลับหน้าตารางงวด</a>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">พิมพ์ใบเสร็จ</button>
        </div>
    </div>

    <section class="receipt-paper p-3 p-md-4">
        <div class="receipt-head pb-3 mb-3">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <h4 class="mb-1">SMART FINANCE 360</h4>
                    <div class="text-muted">ระบบเช่าซื้อ Fresher</div>
                    <div class="small text-muted">สาขา: <?php echo h((string)($receipt['branch_code'] ?? '-')); ?></div>
                </div>
                <div class="text-end">
                    <div class="h5 mb-1">ใบเสร็จรับเงิน</div>
                    <div class="mono fw-semibold"><?php echo h((string)$receipt['receipt_code']); ?></div>
                    <div class="small text-muted">วันที่รับชำระ: <?php echo h(fr_fmt_date((string)($receipt['payment_date'] ?? ''))); ?></div>
                </div>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-md-6">
                <div class="border rounded p-2">
                    <div><strong>รหัสสัญญา:</strong> <span class="mono"><?php echo h((string)($receipt['contract_code'] ?? '')); ?></span></div>
                    <div><strong>ลูกค้า:</strong> <?php echo h((string)($receipt['customer_name'] ?? '')); ?></div>
                    <div><strong>รหัสลูกค้า:</strong> <?php echo h((string)($receipt['customer_code'] ?? '')); ?></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-2">
                    <?php $methodCode = strtoupper((string)($receipt['payment_method'] ?? '')); ?>
                    <div><strong>ช่องทางชำระ:</strong> <?php echo h($methodLabels[$methodCode] ?? $methodCode); ?></div>
                    <div><strong>เลขอ้างอิง:</strong> <?php echo h((string)($receipt['reference_no'] ?? '-')); ?></div>
                    <div><strong>ผู้บันทึก:</strong> <?php echo h((string)($receipt['created_by'] ?? '-')); ?></div>
                </div>
            </div>
        </div>

        <div class="table-responsive mb-3">
            <table class="table table-sm table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>งวด</th>
                        <th>วันครบกำหนด</th>
                        <th>ค่างวด</th>
                        <th>ชำระก่อน</th>
                        <th>รับครั้งนี้</th>
                        <th>ชำระสะสมหลัง</th>
                        <th>สถานะหลังชำระ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($items === []): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">ไม่มีรายการงวด (ชำระเฉพาะค่าปรับ/ค่าทวงถาม)</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?php echo number_format((int)($item['installment_no'] ?? 0)); ?></td>
                                <td><?php echo h(fr_fmt_date((string)($item['due_date'] ?? ''))); ?></td>
                                <td class="text-end"><?php echo number_format((float)($item['installment_amount'] ?? 0), 2); ?></td>
                                <td class="text-end"><?php echo number_format((float)($item['paid_before'] ?? 0), 2); ?></td>
                                <td class="text-end fw-semibold"><?php echo number_format((float)($item['pay_amount'] ?? 0), 2); ?></td>
                                <td class="text-end"><?php echo number_format((float)($item['paid_after'] ?? 0), 2); ?></td>
                                <td><?php echo h((string)($item['payment_status_after'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="row g-2 justify-content-end">
            <div class="col-md-5">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr>
                            <td>ยอดรับชำระงวด</td>
                            <td class="text-end"><?php echo number_format((float)($receipt['total_paid_amount'] ?? 0), 2); ?></td>
                        </tr>
                        <tr>
                            <td>ค่าปรับล่าช้า</td>
                            <td class="text-end"><?php echo number_format((float)($receipt['total_late_penalty'] ?? 0), 2); ?></td>
                        </tr>
                        <tr>
                            <td>ค่าทวงถาม</td>
                            <td class="text-end"><?php echo number_format((float)($receipt['total_collection_fee'] ?? 0), 2); ?></td>
                        </tr>
                        <tr class="border-top">
                            <td><strong>รวมรับชำระสุทธิ</strong></td>
                            <td class="text-end"><strong><?php echo number_format((float)($receipt['grand_total'] ?? 0), 2); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4 small text-muted">
            หมายเหตุ: <?php echo h((string)($receipt['note_text'] ?? '-')); ?>
        </div>
        <?php if ($contract): ?>
            <div class="small text-muted mt-1">สถานะสัญญาปัจจุบัน: <?php echo h((string)($contract['contract_status'] ?? '')); ?></div>
        <?php endif; ?>
    </section>
</div>

<?php if ($autoPrint): ?>
<script>
window.addEventListener('load', function () {
    window.print();
});
</script>
<?php endif; ?>
</body>
</html>

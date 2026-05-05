<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$contractCode = strtoupper(trim((string)($_GET['contract_code'] ?? $_POST['contract_code'] ?? '')));

add_flash('warning', 'ยกเลิกใช้งานหน้ารับชำระแล้ว กรุณาใช้งานผ่านหน้าตารางงวด');

$target = 'installments.php';
if ($contractCode !== '') {
    $target .= '?contract_code=' . rawurlencode($contractCode);
}

redirect_to(fresher_base_url($target));

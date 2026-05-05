<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** @return array<int, array<string, mixed>> */
function hp_parse_items(array $post): array
{
    $codes = is_array($post['item_product_code'] ?? null) ? $post['item_product_code'] : [];
    $qtys = is_array($post['item_qty'] ?? null) ? $post['item_qty'] : [];
    $prices = is_array($post['item_price'] ?? null) ? $post['item_price'] : [];
    $serials = is_array($post['item_serial'] ?? null) ? $post['item_serial'] : [];

    $max = max(count($codes), count($qtys), count($prices), count($serials));
    $items = [];
    $seenSerials = [];

    for ($i = 0; $i < $max; $i++) {
        $code = strtoupper(trim((string)($codes[$i] ?? '')));
        $qty = max(0, fresher_int($qtys[$i] ?? 0));
        $priceInput = max(0, fresher_decimal($prices[$i] ?? 0));
        $serialText = trim((string)($serials[$i] ?? ''));

        if ($code === '' && $qty <= 0 && $priceInput <= 0 && $serialText === '') {
            continue;
        }
        if ($code === '') {
            throw new RuntimeException('กรุณาเลือกสินค้าให้ครบ');
        }
        if ($serialText === '') {
            throw new RuntimeException('กรุณากรอก Serial Number ทุกชิ้น');
        }

        $product = fresher_product_row($code, true);
        if (!$product) {
            throw new RuntimeException('ไม่พบสินค้า/รุ่น หรือสินค้าถูกปิดใช้งาน: ' . $code);
        }

        $serialList = array_values(array_filter(array_map(
            static fn ($v): string => trim((string)$v),
            preg_split('/[\r\n,;]+/', $serialText) ?: []
        ), static fn (string $v): bool => $v !== ''));
        if ($serialList === []) {
            throw new RuntimeException('กรุณากรอก Serial Number ทุกชิ้น');
        }

        if ($qty <= 0) {
            $qty = count($serialList);
        }
        if (count($serialList) !== $qty) {
            throw new RuntimeException('จำนวน Serial ไม่ตรงกับจำนวนสินค้า (' . $code . ')');
        }

        $salePrice = max(0, fresher_decimal($product['sale_price'] ?? 0));
        if ($salePrice <= 0) {
            $salePrice = max(0, fresher_decimal($product['default_price'] ?? 0));
        }
        $unitPrice = $priceInput > 0 ? $priceInput : $salePrice;
        if ($unitPrice <= 0) {
            throw new RuntimeException('ราคาสินค้าต้องมากกว่า 0: ' . $code);
        }

        foreach ($serialList as $serial) {
            $serialKey = strtoupper($serial);
            if (isset($seenSerials[$serialKey])) {
                throw new RuntimeException('พบ Serial ซ้ำในฟอร์ม: ' . $serial);
            }
            $seenSerials[$serialKey] = true;

            $items[] = [
                'product_code' => $code,
                'product_name' => (string)($product['product_name'] ?? ''),
                'model_name' => (string)($product['model_name'] ?? ''),
                'serial_number' => $serial,
                'qty' => 1,
                'unit_price' => round($unitPrice, 2),
                'line_total' => round($unitPrice, 2),
            ];
        }
    }

    if ($items === []) {
        throw new RuntimeException('กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ');
    }

    return $items;
}

/** @return array<int, string> */
function hp_collect_serials(array $items): array
{
    $serials = [];
    foreach ($items as $item) {
        $serial = trim((string)($item['serial_number'] ?? ''));
        if ($serial === '') {
            continue;
        }
        $serials[] = $serial;
    }
    return array_values(array_unique($serials));
}

function hp_assert_serials_not_used(array $serials, string $excludeContractCode = ''): void
{
    if ($serials === []) {
        return;
    }

    $placeholders = [];
    $params = [];
    foreach ($serials as $idx => $serial) {
        $key = ':s' . $idx;
        $placeholders[] = $key;
        $params[$key] = $serial;
    }

    $sql = 'SELECT serial_number, contract_code
            FROM fresher_hire_purchase_items
            WHERE is_deleted = 0
              AND serial_number IN (' . implode(', ', $placeholders) . ')';
    if ($excludeContractCode !== '') {
        $sql .= ' AND contract_code <> :exclude_contract_code';
        $params[':exclude_contract_code'] = $excludeContractCode;
    }
    $sql .= ' LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if ($row) {
        throw new RuntimeException('Serial ถูกใช้งานแล้ว: ' . (string)$row['serial_number'] . ' (สัญญา ' . (string)$row['contract_code'] . ')');
    }
}

/** @return array<int, array<string, mixed>> */
function hp_get_contract_items(string $contractCode): array
{
    $contractCode = strtoupper(trim($contractCode));
    if ($contractCode === '') {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT product_code, product_name, model_name, serial_number, quantity AS qty, unit_price, line_amount AS line_total
         FROM fresher_hire_purchase_items
         WHERE contract_code = :contract_code
           AND is_deleted = 0
         ORDER BY id ASC'
    );
    $stmt->execute([':contract_code' => $contractCode]);
    return $stmt->fetchAll() ?: [];
}

function hp_soft_delete_items(string $contractCode, string $actor, string $now): void
{
    $stmt = db()->prepare(
        'UPDATE fresher_hire_purchase_items
         SET is_deleted = 1,
             deleted_by = :deleted_by,
             deleted_at = :deleted_at,
             updated_by = :updated_by,
             updated_at = :updated_at
         WHERE contract_code = :contract_code
           AND is_deleted = 0'
    );
    $stmt->execute([
        ':deleted_by' => $actor,
        ':deleted_at' => $now,
        ':updated_by' => $actor,
        ':updated_at' => $now,
        ':contract_code' => $contractCode,
    ]);
}

function hp_insert_items(string $contractCode, string $branchCode, array $items, string $actor, string $now): void
{
    $stmt = db()->prepare(
        'INSERT INTO fresher_hire_purchase_items (
            contract_code, branch_code, product_code, product_name, model_name,
            serial_number, quantity, unit_price, line_amount,
            is_deleted, created_by, created_at
         ) VALUES (
            :contract_code, :branch_code, :product_code, :product_name, :model_name,
            :serial_number, :quantity, :unit_price, :line_amount,
            0, :created_by, :created_at
         )'
    );

    foreach ($items as $item) {
        $stmt->execute([
            ':contract_code' => $contractCode,
            ':branch_code' => $branchCode,
            ':product_code' => (string)$item['product_code'],
            ':product_name' => (string)$item['product_name'],
            ':model_name' => (string)$item['model_name'],
            ':serial_number' => (string)$item['serial_number'],
            ':quantity' => fresher_int($item['qty'] ?? 1),
            ':unit_price' => fresher_decimal($item['unit_price'] ?? 0),
            ':line_amount' => fresher_decimal($item['line_total'] ?? 0),
            ':created_by' => $actor,
            ':created_at' => $now,
        ]);
    }
}

function hp_adjust_stock_by_items(array $items, int $direction, string $actor, string $now): void
{
    foreach ($items as $item) {
        $code = strtoupper(trim((string)($item['product_code'] ?? '')));
        $qty = max(0, fresher_int($item['qty'] ?? $item['quantity'] ?? 0));
        if ($code === '' || $qty <= 0) {
            continue;
        }
        fresher_adjust_product_stock($code, $direction * $qty, $actor, $now);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));
        $action = trim((string)($_POST['action'] ?? 'save'));
        $actor = current_user_name();
        $now = now_dt();

        if ($action === 'delete') {
            $id = fresher_int($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('ไม่พบสัญญาที่ต้องการลบ');
            }

            $scope = fresher_scope_clause('branch_code', 'fr_hp_del');
            $stmt = db()->prepare(
                'SELECT contract_code, branch_code
                 FROM fresher_hire_purchase
                 WHERE id = :id
                   AND is_deleted = 0' . $scope['sql'] . '
                 LIMIT 1'
            );
            $params = $scope['params'];
            $params[':id'] = $id;
            $stmt->execute($params);
            $contract = $stmt->fetch();
            if (!$contract) {
                throw new RuntimeException('ไม่พบสัญญาหรือไม่มีสิทธิ์ลบ');
            }

            $contractCode = (string)$contract['contract_code'];
            $items = hp_get_contract_items($contractCode);

            db()->beginTransaction();
            hp_adjust_stock_by_items($items, +1, $actor, $now);

            $stmtDel = db()->prepare(
                'UPDATE fresher_hire_purchase
                 SET is_deleted = 1,
                     deleted_by = :deleted_by,
                     deleted_at = :deleted_at,
                     updated_by = :updated_by,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmtDel->execute([
                ':deleted_by' => $actor,
                ':deleted_at' => $now,
                ':updated_by' => $actor,
                ':updated_at' => $now,
                ':id' => $id,
            ]);

            hp_soft_delete_items($contractCode, $actor, $now);

            $stmtIns = db()->prepare(
                'UPDATE fresher_installments
                 SET is_deleted = 1,
                     deleted_by = :deleted_by,
                     deleted_at = :deleted_at,
                     updated_by = :updated_by,
                     updated_at = :updated_at
                 WHERE contract_code = :contract_code
                   AND is_deleted = 0'
            );
            $stmtIns->execute([
                ':deleted_by' => $actor,
                ':deleted_at' => $now,
                ':updated_by' => $actor,
                ':updated_at' => $now,
                ':contract_code' => $contractCode,
            ]);

            db()->commit();
            add_flash('warning', 'ลบสัญญาและคืนสต็อกเรียบร้อย');
        } else {
            $id = fresher_int($_POST['id'] ?? 0);
            $customerCode = strtoupper(trim((string)($_POST['customer_code'] ?? '')));
            $downPayment = max(0, fresher_decimal($_POST['down_payment'] ?? 0));
            $flatRate = max(0, fresher_decimal($_POST['flat_interest_rate'] ?? $_POST['annual_interest_rate'] ?? 0));
            $installmentCount = max(1, fresher_int($_POST['installment_count'] ?? 0));
            $startDate = trim((string)($_POST['start_date'] ?? date('Y-m-d')));
            $installmentAmountInput = max(0, fresher_decimal($_POST['installment_amount'] ?? 0));
            $note = trim((string)($_POST['note_text'] ?? ''));
            $contractStatus = strtoupper(trim((string)($_POST['contract_status'] ?? 'ACTIVE')));
            $rebuildSchedule = fresher_int($_POST['rebuild_schedule'] ?? 0) === 1;

            if ($customerCode === '') {
                throw new RuntimeException('กรุณาเลือกลูกค้า');
            }

            $customer = fresher_customer_row($customerCode);
            if (!$customer) {
                throw new RuntimeException('ไม่พบลูกค้าหรือไม่มีสิทธิ์ใช้งาน');
            }

            $branchCode = strtoupper(trim((string)$customer['branch_code']));
            assert_branch_in_current_scope($branchCode);
            $customerName = trim((string)$customer['first_name'] . ' ' . (string)$customer['last_name']);

            try {
                $dueDay = (int)(new DateTimeImmutable($startDate))->format('d');
            } catch (Throwable $e) {
                throw new RuntimeException('วันที่เริ่มงวดไม่ถูกต้อง');
            }

            $items = hp_parse_items($_POST);
            $serials = hp_collect_serials($items);
            $contractAmount = 0.0;
            foreach ($items as $item) {
                $contractAmount += max(0, fresher_decimal($item['line_total'] ?? 0));
            }
            $contractAmount = round($contractAmount, 2);

            $financedAmount = round(max(0, $contractAmount - $downPayment), 2);
            if ($financedAmount <= 0) {
                throw new RuntimeException('ยอดจัดเช่าซื้อต้องมากกว่า 0');
            }

            $loanPlan = fresher_calculate_loan_plan($financedAmount, $flatRate, $installmentCount, $installmentAmountInput);
            $installmentAmount = max(0, fresher_decimal($loanPlan['installment_amount'] ?? 0));
            $eirRate = max(0, fresher_decimal($loanPlan['eir_interest_rate'] ?? 0));
            if ($installmentAmount <= 0) {
                throw new RuntimeException('คำนวณค่างวดไม่สำเร็จ');
            }

            $firstItem = $items[0];
            $mainProductCode = (string)$firstItem['product_code'];
            $mainProductName = (string)$firstItem['product_name'];
            $mainModelName = (string)$firstItem['model_name'];
            $mainSerial = (string)$firstItem['serial_number'];
            if (count($items) > 1) {
                $mainProductName .= ' + ' . (count($items) - 1) . ' รายการ';
            }

            $photo = fresher_upload_file('product_image', 'images', ['jpg', 'jpeg', 'png', 'webp'], 8 * 1024 * 1024);
            $imagePath = '';

            db()->beginTransaction();

            if ($id > 0) {
                $scope = fresher_scope_clause('branch_code', 'fr_hp_up');
                $stmtFind = db()->prepare(
                    'SELECT contract_code, product_image_path, branch_code
                     FROM fresher_hire_purchase
                     WHERE id = :id
                       AND is_deleted = 0' . $scope['sql'] . '
                     LIMIT 1'
                );
                $params = $scope['params'];
                $params[':id'] = $id;
                $stmtFind->execute($params);
                $old = $stmtFind->fetch();
                if (!$old) {
                    throw new RuntimeException('ไม่พบสัญญาที่ต้องการแก้ไข');
                }

                $contractCode = (string)$old['contract_code'];
                $imagePath = (string)$old['product_image_path'];
                if (is_array($photo)) {
                    $imagePath = (string)$photo['file_path'];
                }

                hp_assert_serials_not_used($serials, $contractCode);

                $oldItems = hp_get_contract_items($contractCode);
                hp_adjust_stock_by_items($oldItems, +1, $actor, $now);
                hp_adjust_stock_by_items($items, -1, $actor, $now);

                $stmtUp = db()->prepare(
                    'UPDATE fresher_hire_purchase
                     SET customer_code = :customer_code,
                         customer_name = :customer_name,
                         branch_code = :branch_code,
                         product_code = :product_code,
                         product_name = :product_name,
                         model_name = :model_name,
                         serial_number = :serial_number,
                         product_image_path = :product_image_path,
                         contract_amount = :contract_amount,
                         down_payment = :down_payment,
                         financed_amount = :financed_amount,
                         flat_interest_rate = :flat_interest_rate,
                         eir_interest_rate = :eir_interest_rate,
                         calculation_method = :calculation_method,
                         annual_interest_rate = :annual_interest_rate,
                         installment_amount = :installment_amount,
                         installment_count = :installment_count,
                         start_date = :start_date,
                         due_day = :due_day,
                         contract_status = :contract_status,
                         note_text = :note_text,
                         updated_by = :updated_by,
                         updated_at = :updated_at
                     WHERE id = :id'
                );
                $stmtUp->execute([
                    ':customer_code' => $customerCode,
                    ':customer_name' => $customerName,
                    ':branch_code' => $branchCode,
                    ':product_code' => $mainProductCode,
                    ':product_name' => $mainProductName,
                    ':model_name' => $mainModelName,
                    ':serial_number' => $mainSerial,
                    ':product_image_path' => $imagePath,
                    ':contract_amount' => $contractAmount,
                    ':down_payment' => $downPayment,
                    ':financed_amount' => $financedAmount,
                    ':flat_interest_rate' => $flatRate,
                    ':eir_interest_rate' => $eirRate,
                    ':calculation_method' => 'FLAT_TO_EIR',
                    ':annual_interest_rate' => $eirRate,
                    ':installment_amount' => $installmentAmount,
                    ':installment_count' => $installmentCount,
                    ':start_date' => $startDate,
                    ':due_day' => $dueDay,
                    ':contract_status' => $contractStatus,
                    ':note_text' => $note,
                    ':updated_by' => $actor,
                    ':updated_at' => $now,
                    ':id' => $id,
                ]);

                hp_soft_delete_items($contractCode, $actor, $now);
                hp_insert_items($contractCode, $branchCode, $items, $actor, $now);

                if ($rebuildSchedule) {
                    $stmtPaid = db()->prepare(
                        'SELECT COUNT(*)
                         FROM fresher_installments
                         WHERE contract_code = :contract_code
                           AND is_deleted = 0
                           AND payment_status IN ("PAID", "PARTIAL")'
                    );
                    $stmtPaid->execute([':contract_code' => $contractCode]);
                    if ((int)$stmtPaid->fetchColumn() > 0) {
                        throw new RuntimeException('ไม่สามารถสร้างตารางงวดใหม่ได้ เพราะมีประวัติชำระแล้ว');
                    }

                    $stmtDelSchedule = db()->prepare(
                        'UPDATE fresher_installments
                         SET is_deleted = 1,
                             deleted_by = :deleted_by,
                             deleted_at = :deleted_at,
                             updated_by = :updated_by,
                             updated_at = :updated_at
                         WHERE contract_code = :contract_code
                           AND is_deleted = 0'
                    );
                    $stmtDelSchedule->execute([
                        ':deleted_by' => $actor,
                        ':deleted_at' => $now,
                        ':updated_by' => $actor,
                        ':updated_at' => $now,
                        ':contract_code' => $contractCode,
                    ]);

                    $scheduleRows = fresher_generate_installment_rows(
                        $contractCode,
                        $customerCode,
                        $customerName,
                        $branchCode,
                        $startDate,
                        $installmentCount,
                        $installmentAmount,
                        $financedAmount,
                        $eirRate
                    );

                    $stmtIns = db()->prepare(
                        'INSERT INTO fresher_installments (
                            contract_code, customer_code, customer_name, branch_code,
                            installment_no, due_date, installment_amount, principal_amount, interest_amount,
                            payment_status, is_deleted, created_by, created_at
                         ) VALUES (
                            :contract_code, :customer_code, :customer_name, :branch_code,
                            :installment_no, :due_date, :installment_amount, :principal_amount, :interest_amount,
                            :payment_status, 0, :created_by, :created_at
                         )'
                    );
                    foreach ($scheduleRows as $r) {
                        $stmtIns->execute([
                            ':contract_code' => $r['contract_code'],
                            ':customer_code' => $r['customer_code'],
                            ':customer_name' => $r['customer_name'],
                            ':branch_code' => $r['branch_code'],
                            ':installment_no' => $r['installment_no'],
                            ':due_date' => $r['due_date'],
                            ':installment_amount' => $r['installment_amount'],
                            ':principal_amount' => $r['principal_amount'],
                            ':interest_amount' => $r['interest_amount'],
                            ':payment_status' => $r['payment_status'],
                            ':created_by' => $actor,
                            ':created_at' => $now,
                        ]);
                    }
                }

                fresher_refresh_contract_status($contractCode);
                db()->commit();
                add_flash('success', 'แก้ไขสัญญาและรายการสินค้าเรียบร้อย');
            } else {
                if (is_array($photo)) {
                    $imagePath = (string)$photo['file_path'];
                }

                hp_assert_serials_not_used($serials);
                hp_adjust_stock_by_items($items, -1, $actor, $now);

                $contractCode = fresher_generate_code('FRCON');
                $stmtInsContract = db()->prepare(
                    'INSERT INTO fresher_hire_purchase (
                        contract_code, customer_code, customer_name, branch_code,
                        product_code, product_name, model_name, serial_number, product_image_path,
                        contract_amount, down_payment, financed_amount,
                        flat_interest_rate, eir_interest_rate, calculation_method, annual_interest_rate,
                        installment_amount, installment_count, start_date, due_day,
                        contract_status, note_text, is_deleted, created_by, created_at
                     ) VALUES (
                        :contract_code, :customer_code, :customer_name, :branch_code,
                        :product_code, :product_name, :model_name, :serial_number, :product_image_path,
                        :contract_amount, :down_payment, :financed_amount,
                        :flat_interest_rate, :eir_interest_rate, :calculation_method, :annual_interest_rate,
                        :installment_amount, :installment_count, :start_date, :due_day,
                        :contract_status, :note_text, 0, :created_by, :created_at
                     )'
                );
                $stmtInsContract->execute([
                    ':contract_code' => $contractCode,
                    ':customer_code' => $customerCode,
                    ':customer_name' => $customerName,
                    ':branch_code' => $branchCode,
                    ':product_code' => $mainProductCode,
                    ':product_name' => $mainProductName,
                    ':model_name' => $mainModelName,
                    ':serial_number' => $mainSerial,
                    ':product_image_path' => $imagePath,
                    ':contract_amount' => $contractAmount,
                    ':down_payment' => $downPayment,
                    ':financed_amount' => $financedAmount,
                    ':flat_interest_rate' => $flatRate,
                    ':eir_interest_rate' => $eirRate,
                    ':calculation_method' => 'FLAT_TO_EIR',
                    ':annual_interest_rate' => $eirRate,
                    ':installment_amount' => $installmentAmount,
                    ':installment_count' => $installmentCount,
                    ':start_date' => $startDate,
                    ':due_day' => $dueDay,
                    ':contract_status' => $contractStatus,
                    ':note_text' => $note,
                    ':created_by' => $actor,
                    ':created_at' => $now,
                ]);

                hp_insert_items($contractCode, $branchCode, $items, $actor, $now);

                $scheduleRows = fresher_generate_installment_rows(
                    $contractCode,
                    $customerCode,
                    $customerName,
                    $branchCode,
                    $startDate,
                    $installmentCount,
                    $installmentAmount,
                    $financedAmount,
                    $eirRate
                );
                $stmtIns = db()->prepare(
                    'INSERT INTO fresher_installments (
                        contract_code, customer_code, customer_name, branch_code,
                        installment_no, due_date, installment_amount, principal_amount, interest_amount,
                        payment_status, is_deleted, created_by, created_at
                     ) VALUES (
                        :contract_code, :customer_code, :customer_name, :branch_code,
                        :installment_no, :due_date, :installment_amount, :principal_amount, :interest_amount,
                        :payment_status, 0, :created_by, :created_at
                     )'
                );
                foreach ($scheduleRows as $r) {
                    $stmtIns->execute([
                        ':contract_code' => $r['contract_code'],
                        ':customer_code' => $r['customer_code'],
                        ':customer_name' => $r['customer_name'],
                        ':branch_code' => $r['branch_code'],
                        ':installment_no' => $r['installment_no'],
                        ':due_date' => $r['due_date'],
                        ':installment_amount' => $r['installment_amount'],
                        ':principal_amount' => $r['principal_amount'],
                        ':interest_amount' => $r['interest_amount'],
                        ':payment_status' => $r['payment_status'],
                        ':created_by' => $actor,
                        ':created_at' => $now,
                    ]);
                }

                fresher_refresh_contract_status($contractCode);
                db()->commit();
                add_flash('success', 'เพิ่มสัญญาและรายการสินค้าเรียบร้อย');
            }
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        add_flash('danger', 'บันทึกข้อมูลไม่สำเร็จ: ' . $e->getMessage());
    }

    redirect_to(fresher_base_url('hire_purchase.php'));
}

$currentFresherPage = 'hire_purchase';
$pageTitle = 'Hire Purchase Contracts';

$customerOptions = fresher_customer_options();
$productOptions = fresher_product_options();

$editId = fresher_int($_GET['edit'] ?? 0);
$editRow = null;
$editItems = [];
if ($editId > 0) {
    $scope = fresher_scope_clause('branch_code', 'fr_hp_edit');
    $stmtEdit = db()->prepare(
        'SELECT * FROM fresher_hire_purchase WHERE id = :id AND is_deleted = 0' . $scope['sql'] . ' LIMIT 1'
    );
    $params = $scope['params'];
    $params[':id'] = $editId;
    $stmtEdit->execute($params);
    $editRow = $stmtEdit->fetch() ?: null;
    if ($editRow) {
        $editItems = hp_get_contract_items((string)$editRow['contract_code']);
    }
}
if ($editItems === []) {
    $editItems = [[
        'product_code' => '',
        'qty' => 1,
        'serial_number' => '',
        'unit_price' => 0,
        'line_total' => 0,
    ]];
}

$search = trim((string)($_GET['q'] ?? ''));
$scope = fresher_scope_clause('branch_code', 'fr_hp_list');
$sql = 'SELECT * FROM fresher_hire_purchase WHERE is_deleted = 0' . $scope['sql'];
$params = $scope['params'];
if ($search !== '') {
    $sql .= ' AND (contract_code LIKE :q OR customer_code LIKE :q OR customer_name LIKE :q OR product_name LIKE :q OR serial_number LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
$sql .= ' ORDER BY id DESC';
$stmtRows = db()->prepare($sql);
$stmtRows->execute($params);
$rows = $stmtRows->fetchAll();

$itemsByContract = [];
$contractCodes = array_values(array_unique(array_filter(array_map(
    static fn(array $row): string => (string)($row['contract_code'] ?? ''),
    $rows
))));
if ($contractCodes !== []) {
    $placeholders = [];
    $itemParams = [];
    foreach ($contractCodes as $i => $contractCode) {
        $key = ':cc' . $i;
        $placeholders[] = $key;
        $itemParams[$key] = $contractCode;
    }

    $stmtItemMap = db()->prepare(
        'SELECT contract_code, product_code, product_name, model_name, serial_number,
                quantity AS qty, unit_price, line_amount AS line_total
         FROM fresher_hire_purchase_items
         WHERE is_deleted = 0
           AND contract_code IN (' . implode(', ', $placeholders) . ')
         ORDER BY id ASC'
    );
    $stmtItemMap->execute($itemParams);
    foreach ($stmtItemMap->fetchAll() as $itemRow) {
        $contractCode = (string)($itemRow['contract_code'] ?? '');
        if ($contractCode === '') {
            continue;
        }
        $itemsByContract[$contractCode][] = $itemRow;
    }
}

$editPayload = null;
if ($editRow) {
    $editPayload = [
        'id' => (int)$editRow['id'],
        'contract_code' => (string)$editRow['contract_code'],
        'customer_code' => (string)$editRow['customer_code'],
        'note_text' => (string)($editRow['note_text'] ?? ''),
        'down_payment' => (float)($editRow['down_payment'] ?? 0),
        'flat_interest_rate' => (float)($editRow['flat_interest_rate'] ?? 12),
        'installment_count' => (int)($editRow['installment_count'] ?? 12),
        'start_date' => (string)($editRow['start_date'] ?? date('Y-m-d')),
        'installment_amount' => (float)($editRow['installment_amount'] ?? 0),
        'contract_status' => (string)($editRow['contract_status'] ?? 'ACTIVE'),
        'items' => $editItems,
    ];
}

include __DIR__ . '/partials/head.php';
?>

<section class="card fr-card mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-3 fr-actions">
                <button class="btn btn-primary" type="button" id="btnOpenAddContractModal">+ Add Hire Purchase Contract</button>
            </div>
            <div class="col-md-9">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-9">
                        <label class="form-label">Search (Contract / Customer / Serial)</label>
                        <input class="form-control" name="q" value="<?php echo h($search); ?>" placeholder="Type and search instantly">
                    </div>
                    <div class="col-md-3 fr-actions">
                        <button class="btn btn-outline-primary" type="submit">Search</button>
                        <?php if ($search !== ''): ?>
                            <a class="btn btn-outline-secondary" href="<?php echo h(fresher_base_url('hire_purchase.php')); ?>">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

<section class="card fr-card mb-4">
    <div class="card-body">
        <div class="mb-2"><strong>Hire Purchase Contracts</strong></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle js-fresher-datatable">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Contract Code</th>
                    <th>Product</th>
                    <th>Customer</th>
                    <th>Financed</th>
                    <th>Installment</th>
                    <th>Terms</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                        $contractCode = (string)$r['contract_code'];
                        $payload = [
                            'id' => (int)$r['id'],
                            'contract_code' => $contractCode,
                            'customer_code' => (string)$r['customer_code'],
                            'note_text' => (string)($r['note_text'] ?? ''),
                            'down_payment' => (float)($r['down_payment'] ?? 0),
                            'flat_interest_rate' => (float)($r['flat_interest_rate'] ?? 12),
                            'installment_count' => (int)($r['installment_count'] ?? 12),
                            'start_date' => (string)($r['start_date'] ?? date('Y-m-d')),
                            'installment_amount' => (float)($r['installment_amount'] ?? 0),
                            'contract_status' => (string)($r['contract_status'] ?? 'ACTIVE'),
                            'items' => $itemsByContract[$contractCode] ?? [],
                        ];
                        $payloadJson = h((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
                    ?>
                    <tr>
                        <td><?php echo (int)$r['id']; ?></td>
                        <td><code><?php echo h($contractCode); ?></code></td>
                        <td><?php echo h((string)$r['product_name'] . ' / ' . (string)$r['model_name']); ?></td>
                        <td><?php echo h((string)$r['customer_code'] . ' - ' . (string)$r['customer_name']); ?></td>
                        <td><?php echo number_format((float)$r['financed_amount'], 2); ?></td>
                        <td><?php echo number_format((float)$r['installment_amount'], 2); ?></td>
                        <td><?php echo number_format((int)$r['installment_count']); ?></td>
                        <td><?php echo h((string)$r['contract_status']); ?></td>
                        <td class="fr-actions">
                            <button class="btn btn-sm btn-outline-info js-hp-view" type="button" data-contract="<?php echo $payloadJson; ?>">View</button>
                            <button class="btn btn-sm btn-outline-primary js-hp-edit" type="button" data-contract="<?php echo $payloadJson; ?>">Edit</button>
                            <a class="btn btn-sm btn-outline-success" href="<?php echo h(fresher_base_url('installments.php?contract_code=' . rawurlencode($contractCode))); ?>">Installments</a>
                            <form method="post" class="js-confirm-delete">
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div class="modal fade" id="hpContractModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data" id="hpForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="hpModalTitle">Add Hire Purchase Contract</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" id="hp_id" value="0">

                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Contract Code</label>
                            <input class="form-control" id="hp_contract_code_display" value="Auto generated on save" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Customer *</label>
                            <select class="form-select js-hp-customer-autocomplete" name="customer_code" id="hp_customer_code" required>
                                <option value="">-- Select --</option>
                                <?php foreach ($customerOptions as $c): ?>
                                    <?php $code = (string)$c['customer_code']; ?>
                                    <option value="<?php echo h($code); ?>">
                                        <?php echo h($code . ' - ' . (string)$c['first_name'] . ' ' . (string)$c['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Product Image</label>
                            <input class="form-control" type="file" name="product_image" id="hp_product_image" accept=".jpg,.jpeg,.png,.webp">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Note</label>
                            <input class="form-control" name="note_text" id="hp_note_text">
                        </div>

                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label mb-0">Contract Items (multiple rows allowed)</label>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddRow">+ Add Item</button>
                            </div>
                            <div class="table-responsive border rounded">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th>Product / Model</th>
                                        <th>Qty</th>
                                        <th>Serial Number</th>
                                        <th>Unit Price</th>
                                        <th>Line Total</th>
                                        <th>Action</th>
                                    </tr>
                                    </thead>
                                    <tbody id="itemBody"></tbody>
                                </table>
                            </div>
                            <small class="text-muted">Every unit needs serial numbers and count must equal qty.</small>
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">Contract Amount</label>
                            <input class="form-control" id="contractAmount" name="contract_amount" readonly>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Down Payment</label>
                            <input class="form-control" id="downPayment" type="number" name="down_payment" step="0.01" min="0" value="0">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Financed Amount</label>
                            <input class="form-control" id="financedAmount" readonly>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Flat % / Year</label>
                            <input class="form-control" type="number" id="flatInterestRate" name="flat_interest_rate" step="0.0001" value="12">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Installment Count</label>
                            <input class="form-control" type="number" id="installmentCount" name="installment_count" min="1" value="12">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Start Date</label>
                            <input class="form-control" type="date" id="startDate" name="start_date" value="<?php echo h(date('Y-m-d')); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Installment (blank = auto)</label>
                            <input class="form-control" type="number" step="0.01" id="installmentAmount" name="installment_amount">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="contract_status" id="contractStatus">
                                <?php foreach (['ACTIVE', 'DELINQUENT', 'NPL', 'REPOSSESSED', 'CLOSED'] as $st): ?>
                                    <option value="<?php echo h($st); ?>"><?php echo h($st); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 d-none" id="hpRebuildWrap">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="rebuild_schedule" value="1" id="hp_rebuild_schedule">
                                <label class="form-check-label" for="hp_rebuild_schedule">Rebuild installment schedule after edit</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" type="submit" id="hpSubmitBtn">Save Contract</button>
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<template id="itemRowTemplate">
    <tr class="item-row">
        <td>
            <select class="form-select item-code" name="item_product_code[]" required>
                <option value="">-- Select Product --</option>
                <?php foreach ($productOptions as $p): ?>
                    <?php
                        $pc = (string)$p['product_code'];
                        $sp = max(0, fresher_decimal($p['sale_price'] ?? 0));
                        if ($sp <= 0) {
                            $sp = max(0, fresher_decimal($p['default_price'] ?? 0));
                        }
                    ?>
                    <option value="<?php echo h($pc); ?>" data-price="<?php echo h((string)$sp); ?>" data-stock="<?php echo h((string)fresher_int($p['stock_quantity'] ?? 0)); ?>">
                        <?php echo h($pc . ' - ' . (string)$p['product_name'] . ' / ' . (string)$p['model_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input class="form-control item-qty" type="number" name="item_qty[]" min="1" step="1" value="1"></td>
        <td><input class="form-control item-serial" name="item_serial[]" placeholder="SN001 or SN001,SN002" required></td>
        <td><input class="form-control item-price" type="number" name="item_price[]" min="0" step="0.01" value="0.00"></td>
        <td><input class="form-control item-total" type="text" value="0.00" readonly></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-row">Remove</button></td>
    </tr>
</template>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('hpContractModal');
    const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
    const form = document.getElementById('hpForm');
    const titleEl = document.getElementById('hpModalTitle');
    const submitBtn = document.getElementById('hpSubmitBtn');
    const btnOpenAdd = document.getElementById('btnOpenAddContractModal');
    const body = document.getElementById('itemBody');
    const rowTpl = document.getElementById('itemRowTemplate');
    const add = document.getElementById('btnAddRow');
    const amt = document.getElementById('contractAmount');
    const down = document.getElementById('downPayment');
    const fin = document.getElementById('financedAmount');
    const idInput = document.getElementById('hp_id');
    const customerInput = document.getElementById('hp_customer_code');
    const noteInput = document.getElementById('hp_note_text');
    const rateInput = document.getElementById('flatInterestRate');
    const countInput = document.getElementById('installmentCount');
    const startInput = document.getElementById('startDate');
    const installmentInput = document.getElementById('installmentAmount');
    const statusInput = document.getElementById('contractStatus');
    const contractDisplayInput = document.getElementById('hp_contract_code_display');
    const rebuildWrap = document.getElementById('hpRebuildWrap');
    const rebuildCheckbox = document.getElementById('hp_rebuild_schedule');
    const imageInput = document.getElementById('hp_product_image');
    const editFromQuery = <?php echo $editPayload ? json_encode($editPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : 'null'; ?>;
    let currentMode = 'add';

    if (!body || !rowTpl || !form) {
        return;
    }

    function num(v) {
        const n = parseFloat(v);
        return Number.isFinite(n) ? n : 0;
    }

    function fmt(v) {
        return num(v).toFixed(2);
    }

    function splitSerials(text) {
        return String(text || '')
            .split(/[\r\n,;]+/)
            .map((v) => v.trim())
            .filter((v) => v !== '');
    }

    function initCustomerAutocomplete() {
        if (!customerInput || !window.jQuery || !window.jQuery.fn || !window.jQuery.fn.select2) {
            return;
        }
        const $customer = window.jQuery(customerInput);
        if ($customer.data('select2')) {
            return;
        }
        $customer.select2({
            theme: 'bootstrap-5',
            width: '100%',
            dropdownParent: window.jQuery(modalEl),
            placeholder: 'Search customer id / first name / last name',
            allowClear: true
        });
    }

    function setSelectValue(selectEl, value) {
        if (!selectEl) {
            return;
        }
        const val = String(value || '');
        const hasValue = Array.from(selectEl.options).some((opt) => opt.value === val);
        const finalVal = hasValue ? val : '';
        selectEl.value = finalVal;
        if (window.jQuery && window.jQuery(selectEl).data('select2')) {
            window.jQuery(selectEl).val(finalVal).trigger('change.select2');
        }
    }

    function recalcRow(row, fromProduct) {
        const sel = row.querySelector('.item-code');
        const qty = row.querySelector('.item-qty');
        const price = row.querySelector('.item-price');
        const total = row.querySelector('.item-total');
        const serial = row.querySelector('.item-serial');
        if (!sel || !qty || !price || !total || !serial) {
            return;
        }

        const opt = sel.options[sel.selectedIndex];
        if (fromProduct && opt && opt.value) {
            const p = num(opt.getAttribute('data-price') || '0');
            if (p > 0) {
                price.value = fmt(p);
            }
        }

        const q = Math.max(1, Math.floor(num(qty.value || '1')));
        qty.value = String(q);

        const p2 = Math.max(0, num(price.value || '0'));
        price.value = fmt(p2);
        total.value = fmt(q * p2);

        const serials = splitSerials(serial.value);
        if (serials.length === 0) {
            serial.setCustomValidity('Please provide serial number(s).');
        } else if (serials.length !== q) {
            serial.setCustomValidity('Serial count must equal qty.');
        } else {
            serial.setCustomValidity('');
        }
    }

    function recalcAll() {
        let t = 0;
        body.querySelectorAll('.item-row').forEach((r) => {
            recalcRow(r, false);
            const x = r.querySelector('.item-total');
            t += num(x ? x.value : 0);
        });
        amt.value = fmt(t);

        const d = Math.max(0, num(down.value || '0'));
        down.value = fmt(d);
        fin.value = fmt(Math.max(0, t - d));
    }

    function bindRow(row) {
        row.querySelectorAll('.item-code, .item-qty, .item-price, .item-serial').forEach((el) => {
            el.addEventListener('change', () => {
                recalcRow(row, el.classList.contains('item-code'));
                recalcAll();
            });
            el.addEventListener('input', () => {
                recalcRow(row, false);
                recalcAll();
            });
        });

        const rm = row.querySelector('.btn-remove-row');
        if (rm) {
            rm.addEventListener('click', () => {
                if (currentMode === 'view') {
                    return;
                }
                if (body.querySelectorAll('.item-row').length <= 1) {
                    return;
                }
                row.remove();
                recalcAll();
            });
        }
    }

    function createRow(item) {
        const row = rowTpl.content.firstElementChild.cloneNode(true);
        const code = row.querySelector('.item-code');
        const qty = row.querySelector('.item-qty');
        const serial = row.querySelector('.item-serial');
        const price = row.querySelector('.item-price');

        const qtyValue = Math.max(1, parseInt(String(item?.qty ?? item?.quantity ?? 1), 10) || 1);
        const priceValue = Math.max(0, num(item?.unit_price ?? 0));

        setSelectValue(code, item?.product_code ?? '');
        qty.value = String(qtyValue);
        serial.value = String(item?.serial_number ?? '');
        price.value = fmt(priceValue);

        bindRow(row);
        recalcRow(row, true);
        return row;
    }

    function loadRows(items) {
        const useItems = Array.isArray(items) && items.length ? items : [{}];
        body.innerHTML = '';
        useItems.forEach((item) => {
            body.appendChild(createRow(item));
        });
        recalcAll();
    }

    function setReadOnlyMode(enabled) {
        form.querySelectorAll('input, select, textarea').forEach((el) => {
            if (el.type === 'hidden') {
                return;
            }
            if (el === contractDisplayInput) {
                return;
            }
            el.disabled = enabled;
        });

        if (add) {
            add.classList.toggle('d-none', enabled);
        }

        body.querySelectorAll('.btn-remove-row').forEach((btn) => {
            btn.classList.toggle('d-none', enabled);
            btn.disabled = enabled;
        });

        if (submitBtn) {
            submitBtn.classList.toggle('d-none', enabled);
        }
    }

    function fillCommon(data) {
        idInput.value = String(data?.id ?? 0);
        contractDisplayInput.value = data?.contract_code ? String(data.contract_code) : 'Auto generated on save';
        setSelectValue(customerInput, data?.customer_code ?? '');
        noteInput.value = String(data?.note_text ?? '');
        down.value = fmt(data?.down_payment ?? 0);
        rateInput.value = String(data?.flat_interest_rate ?? 12);
        countInput.value = String(data?.installment_count ?? 12);
        startInput.value = String(data?.start_date ?? '<?php echo h(date('Y-m-d')); ?>');
        installmentInput.value = data?.installment_amount ? String(data.installment_amount) : '';
        setSelectValue(statusInput, data?.contract_status ?? 'ACTIVE');
        if (rebuildCheckbox) {
            rebuildCheckbox.checked = false;
        }
        if (imageInput) {
            imageInput.value = '';
        }
        loadRows(data?.items ?? []);
    }

    function openAddModal() {
        currentMode = 'add';
        fillCommon({
            id: 0,
            contract_code: '',
            customer_code: '',
            note_text: '',
            down_payment: 0,
            flat_interest_rate: 12,
            installment_count: 12,
            start_date: '<?php echo h(date('Y-m-d')); ?>',
            installment_amount: '',
            contract_status: 'ACTIVE',
            items: [{}]
        });
        if (titleEl) {
            titleEl.textContent = 'Add Hire Purchase Contract';
        }
        if (submitBtn) {
            submitBtn.textContent = 'Save Contract';
        }
        if (rebuildWrap) {
            rebuildWrap.classList.add('d-none');
        }
        setReadOnlyMode(false);
        if (modal) {
            modal.show();
        }
    }

    function openEditModal(data) {
        currentMode = 'edit';
        fillCommon(data || {});
        if (titleEl) {
            titleEl.textContent = 'Edit Hire Purchase Contract';
        }
        if (submitBtn) {
            submitBtn.textContent = 'Save Changes';
        }
        if (rebuildWrap) {
            rebuildWrap.classList.remove('d-none');
        }
        setReadOnlyMode(false);
        if (modal) {
            modal.show();
        }
    }

    function openViewModal(data) {
        currentMode = 'view';
        fillCommon(data || {});
        if (titleEl) {
            titleEl.textContent = 'View Hire Purchase Contract';
        }
        if (rebuildWrap) {
            rebuildWrap.classList.add('d-none');
        }
        setReadOnlyMode(true);
        if (modal) {
            modal.show();
        }
    }

    if (add) {
        add.addEventListener('click', () => {
            if (currentMode === 'view') {
                return;
            }
            body.appendChild(createRow({}));
            recalcAll();
        });
    }

    if (down) {
        down.addEventListener('input', recalcAll);
    }

    btnOpenAdd?.addEventListener('click', openAddModal);
    modalEl?.addEventListener('shown.bs.modal', initCustomerAutocomplete);
    initCustomerAutocomplete();

    document.querySelectorAll('.js-hp-edit').forEach((btn) => {
        btn.addEventListener('click', () => {
            const raw = btn.getAttribute('data-contract') || '{}';
            let data = {};
            try {
                data = JSON.parse(raw);
            } catch (err) {
                data = {};
            }
            openEditModal(data);
        });
    });

    document.querySelectorAll('.js-hp-view').forEach((btn) => {
        btn.addEventListener('click', () => {
            const raw = btn.getAttribute('data-contract') || '{}';
            let data = {};
            try {
                data = JSON.parse(raw);
            } catch (err) {
                data = {};
            }
            openViewModal(data);
        });
    });

    if (editFromQuery && typeof editFromQuery === 'object') {
        openEditModal(editFromQuery);
    } else {
        loadRows([{}]);
    }
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>

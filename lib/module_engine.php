<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_occupation.php';
require_once __DIR__ . '/admin_vehicle.php';
require_once __DIR__ . '/attitude_assessment.php';
require_once __DIR__ . '/statement_ocr.php';

final class ModuleValidationException extends RuntimeException
{
    /** @var array<string, array<int,string>> */
    private array $fieldErrors;

    /**
     * @param array<int,string> $messages
     * @param array<string, array<int,string>> $fieldErrors
     */
    public function __construct(array $messages, array $fieldErrors = [])
    {
        $cleanMessages = [];
        foreach ($messages as $message) {
            $text = trim((string)$message);
            if ($text !== '') {
                $cleanMessages[] = $text;
            }
        }
        if ($cleanMessages === []) {
            $cleanMessages[] = 'เธเนเธญเธกเธนเธฅเนเธกเนเธ–เธนเธเธ•เนเธญเธ';
        }

        parent::__construct(implode(' | ', $cleanMessages));
        $this->fieldErrors = $fieldErrors;
    }

    /**
     * @return array<string, array<int,string>>
     */
    public function getFieldErrors(): array
    {
        return $this->fieldErrors;
    }
}

/**
 * @param array<string, array<int,string>> $fieldErrors
 */
function module_set_validation_state(string $moduleKey, array $fieldErrors): void
{
    if ($moduleKey === '') {
        return;
    }

    $normalized = [];
    foreach ($fieldErrors as $fieldKey => $messages) {
        $fieldName = trim((string)$fieldKey);
        if ($fieldName === '') {
            continue;
        }
        if (!is_array($messages)) {
            $messages = [$messages];
        }

        foreach ($messages as $message) {
            $text = trim((string)$message);
            if ($text === '') {
                continue;
            }
            if (!isset($normalized[$fieldName])) {
                $normalized[$fieldName] = [];
            }
            if (!in_array($text, $normalized[$fieldName], true)) {
                $normalized[$fieldName][] = $text;
            }
        }
    }

    if (!isset($_SESSION['_module_validation']) || !is_array($_SESSION['_module_validation'])) {
        $_SESSION['_module_validation'] = [];
    }

    $_SESSION['_module_validation'][$moduleKey] = [
        'fields' => $normalized,
        'created_at' => date('Y-m-d H:i:s'),
    ];
}

/**
 * @return array{fields:array<string, array<int,string>>, created_at:string}|array{}
 */
function module_consume_validation_state(string $moduleKey): array
{
    if ($moduleKey === '') {
        return [];
    }

    $all = $_SESSION['_module_validation'] ?? [];
    if (!is_array($all) || !isset($all[$moduleKey]) || !is_array($all[$moduleKey])) {
        return [];
    }

    $state = $all[$moduleKey];
    unset($all[$moduleKey]);
    $_SESSION['_module_validation'] = $all;

    $fields = $state['fields'] ?? [];
    if (!is_array($fields)) {
        $fields = [];
    }

    $normalizedFields = [];
    foreach ($fields as $fieldKey => $messages) {
        $fieldName = trim((string)$fieldKey);
        if ($fieldName === '') {
            continue;
        }
        if (!is_array($messages)) {
            $messages = [$messages];
        }

        foreach ($messages as $message) {
            $text = trim((string)$message);
            if ($text === '') {
                continue;
            }
            if (!isset($normalizedFields[$fieldName])) {
                $normalizedFields[$fieldName] = [];
            }
            if (!in_array($text, $normalizedFields[$fieldName], true)) {
                $normalizedFields[$fieldName][] = $text;
            }
        }
    }

    return [
        'fields' => $normalizedFields,
        'created_at' => (string)($state['created_at'] ?? ''),
    ];
}

function handle_module_request(string $moduleKey): array
{
    $module = module_by_key($moduleKey);
    $includeDeleted = module_should_include_deleted_rows_from_request();
    $canViewDeleted = module_can_view_deleted_rows();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['module_key'] ?? '') === $moduleKey) {
        try {
            verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));
            $action = (string)($_POST['action'] ?? 'create');
            $reason = trim((string)($_POST['reason'] ?? ''));

            if ($action === 'create') {
                $attitudeBundle = null;
                $attitudePrepareError = null;
                if ((string)($module['key'] ?? '') === 'customer_360' && module_has_any_attitude_answer($_POST)) {
                    try {
                        $attitudeBundle = module_prepare_customer_attitude_bundle($_POST);
                    } catch (Throwable $attitudeError) {
                        // Do not block main customer save when survey is incomplete.
                        $attitudePrepareError = $attitudeError;
                    }
                }

                $created = module_create_record($module, $_POST, $reason);
                module_add_statement_ocr_flash(module_sync_customer_statement_ocr($module, $created));

                if ((string)($module['key'] ?? '') === 'customer_360' && is_array($attitudeBundle)) {
                    $savedAttitude = module_persist_customer_attitude_bundle($module, $created, $attitudeBundle);
                    if ((int)($savedAttitude['id'] ?? 0) > 0) {
                        add_flash('info', 'เธเธฑเธเธ—เธถเธเนเธเธเธชเธญเธเธ–เธฒเธกเธ—เธฑเธจเธเธเธ•เธดเน€เธฃเธตเธขเธเธฃเนเธญเธข (เธเธนเธเธฃเธซเธฑเธชเธฅเธนเธเธเนเธฒเน€เธ”เธตเธขเธงเธเธฑเธ)');
                    }
                } elseif ($attitudePrepareError instanceof Throwable) {
                    add_flash('warning', 'เธเธฑเธเธ—เธถเธเธฃเธฒเธขเธเธฒเธฃเธฅเธนเธเธเนเธฒเน€เธฃเธตเธขเธเธฃเนเธญเธข เนเธ•เนเนเธเธเธชเธญเธเธ–เธฒเธกเธขเธฑเธเนเธกเนเธเธฃเธ');
                }
                add_flash('success', 'เธเธฑเธเธ—เธถเธเธฃเธฒเธขเธเธฒเธฃเนเธซเธกเนเน€เธฃเธตเธขเธเธฃเนเธญเธข');
            } elseif ($action === 'update') {
                $updated = module_update_record($module, $_POST, $reason);
                module_add_statement_ocr_flash(module_sync_customer_statement_ocr($module, $updated));
                add_flash('success', 'เนเธเนเนเธเธเนเธญเธกเธนเธฅเน€เธฃเธตเธขเธเธฃเนเธญเธขเนเธฅเธฐเน€เธเนเธเน€เธงเธญเธฃเนเธเธฑเธเน€เธ”เธดเธกเนเธงเนเนเธฅเนเธง');
            } elseif ($action === 'approve') {
                module_approve_record($module, $_POST, $reason);
                add_flash('success', 'เธเธนเนเธ•เธฃเธงเธเธชเธญเธเธญเธเธธเธกเธฑเธ•เธดเธฃเธฒเธขเธเธฒเธฃเน€เธฃเธตเธขเธเธฃเนเธญเธข');
            } elseif ($action === 'delete') {
                module_delete_record($module, $_POST, $reason);
                add_flash('warning', 'เธฅเธเนเธเธเธ•เธฃเธฃเธเธฐเน€เธฃเธตเธขเธเธฃเนเธญเธข เธฃเธฐเธเธเนเธกเนเนเธ”เนเธฅเธเธเนเธญเธกเธนเธฅเธเธฃเธดเธ');
            } else {
                throw new RuntimeException('เนเธกเนเธฃเธนเนเธเธฑเธเธเธณเธชเธฑเนเธเธ—เธตเนเธชเนเธเธกเธฒ');
            }
        } catch (ModuleValidationException $e) {
            module_set_validation_state($moduleKey, $e->getFieldErrors());
            add_flash('danger', 'เธเธฑเธเธ—เธถเธเนเธกเนเธชเธณเน€เธฃเนเธ: ' . $e->getMessage());
        } catch (Throwable $e) {
            add_flash('danger', 'เธเธฑเธเธ—เธถเธเนเธกเนเธชเธณเน€เธฃเนเธ: ' . $e->getMessage());
        }

        $target = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
        redirect_to($target ?: '/index.php');
    }

    $editRecord = null;
    if (!empty($_GET['edit'])) {
        $editRecord = module_find_latest_by_id($moduleKey, (int)$_GET['edit'], $includeDeleted);
        if ($editRecord === null) {
            add_flash('danger', 'เนเธกเนเธเธเธฃเธฒเธขเธเธฒเธฃเธฅเนเธฒเธชเธธเธ”เธชเธณเธซเธฃเธฑเธเธเธฒเธฃเนเธเนเนเธ');
        }
    }

    $searchTerm = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($searchTerm) > 120) {
        $searchTerm = mb_substr($searchTerm, 0, 120);
    }

    return [
        'module' => $module,
        'rows' => module_list_latest($moduleKey, $searchTerm, $includeDeleted),
        'edit' => $editRecord,
        'search_term' => $searchTerm,
        'show_deleted' => $includeDeleted,
        'can_view_deleted' => $canViewDeleted,
        'validation' => module_consume_validation_state($moduleKey),
    ];
}

function module_can_view_deleted_rows(): bool
{
    // เธ•เธฒเธกเธเนเธขเธเธฒเธขเธฅเนเธฒเธชเธธเธ”: เธฃเธฒเธขเธเธฒเธฃเธ—เธตเน soft delete เธ•เนเธญเธเนเธกเนเนเธชเธ”เธเนเธเธซเธเนเธฒเนเธกเธ”เธนเธฅเนเธ” เน
    // (เธชเธดเธ—เธเธดเนเน€เธฃเธตเธขเธเธ”เธนเธเนเธญเธกเธนเธฅเธ—เธตเนเธฅเธเธเธฐเธเธฑเธ”เธเธฒเธฃเธเนเธฒเธเธซเธเนเธฒ admin เนเธขเธเธ•เนเธฒเธเธซเธฒเธ)
    return false;
}

function module_should_include_deleted_rows_from_request(): bool
{
    return false;
}
/**
 * @param array<string,mixed> $input
 */
function module_has_any_attitude_answer(array $input): bool
{
    foreach ($input as $key => $value) {
        $questionCode = strtoupper(trim((string)$key));
        if (!preg_match('/^Q\d{2}$/', $questionCode)) {
            continue;
        }
        $answer = (int)$value;
        if ($answer >= 1 && $answer <= 5) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string,mixed> $module
 * @param array<string,mixed> $record
 * @return array{processed:int,success:int,failed:int,skipped:int,messages:array<int,string>}
 */
function module_sync_customer_statement_ocr(array $module, array $record): array
{
    if ((string)($module['key'] ?? '') !== 'customer_360') {
        return [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'messages' => [],
        ];
    }

    try {
        return statement_ocr_scan_customer_bank_statements($module, $record);
    } catch (Throwable $e) {
        return [
            'processed' => 0,
            'success' => 0,
            'failed' => 1,
            'skipped' => 0,
            'messages' => ['OCR statement error: ' . $e->getMessage()],
        ];
    }
}

/**
 * @param array{processed:int,success:int,failed:int,skipped:int,messages:array<int,string>} $summary
 */
function module_add_statement_ocr_flash(array $summary): void
{
    $processed = (int)($summary['processed'] ?? 0);
    $success = (int)($summary['success'] ?? 0);
    $failed = (int)($summary['failed'] ?? 0);
    $skipped = (int)($summary['skipped'] ?? 0);
    $messages = $summary['messages'] ?? [];
    if (!is_array($messages)) {
        $messages = [];
    }

    if ($processed > 0 && $success > 0) {
        add_flash('info', 'OCR Statement เธชเธณเน€เธฃเนเธ ' . $success . ' เนเธเธฅเน');
    }

    if ($processed > 0 && $skipped > 0) {
        add_flash('info', 'OCR Statement เธเนเธฒเธกเนเธเธฅเนเธ—เธตเนเธชเนเธเธเนเธฅเนเธง ' . $skipped . ' เนเธเธฅเน');
    }

    if ($failed > 0) {
        $firstError = trim((string)($messages[0] ?? ''));
        $errorText = $firstError !== '' ? $firstError : 'เนเธกเนเธชเธฒเธกเธฒเธฃเธ– OCR statement เธเธฒเธเนเธเธฅเนเนเธ”เน';
        add_flash('warning', 'OCR Statement เธกเธตเธเนเธญเธเธดเธ”เธเธฅเธฒเธ” ' . $failed . ' เนเธเธฅเน: ' . $errorText);
    }
}

/**
 * @param array<string,mixed> $input
 * @return array{
 *   question_set:array<string,mixed>,
 *   question_items:array<int,array<string,mixed>>,
 *   answers:array<string,int>,
 *   score:array<string,mixed>
 * }
 */
function module_prepare_customer_attitude_bundle(array $input): array
{
    $pdo = db();
    attitude_bootstrap($pdo, current_user_name() !== '' ? current_user_name() : 'system_seed');
    $questionSet = attitude_fetch_latest_question_set($pdo);
    $questionItems = attitude_fetch_question_items($pdo, (int)$questionSet['id']);
    if ($questionItems === []) {
        throw new RuntimeException('เนเธกเนเธเธเธเธธเธ”เนเธเธเธชเธญเธเธ–เธฒเธกเธ—เธฑเธจเธเธเธ•เธด');
    }

    $answersByCode = attitude_collect_answers_from_input($questionItems, $input);
    $scoreResult = attitude_calculate_scores($questionItems, $answersByCode);

    return [
        'question_set' => $questionSet,
        'question_items' => $questionItems,
        'answers' => $answersByCode,
        'score' => $scoreResult,
    ];
}

function module_normalize_attitude_gender(string $raw): string
{
    $value = strtolower(trim($raw));
    if ($value === '') {
        return 'unknown';
    }
    if (in_array($value, ['male', 'm', 'เธเธฒเธข'], true)) {
        return 'male';
    }
    if (in_array($value, ['female', 'f', 'เธซเธเธดเธ'], true)) {
        return 'female';
    }
    if (in_array($value, ['other', 'unknown'], true)) {
        return $value;
    }
    return 'unknown';
}

/**
 * @param array<string,mixed> $module
 * @param array<string,mixed> $source
 * @param array{
 *   question_set:array<string,mixed>,
 *   question_items:array<int,array<string,mixed>>,
 *   answers:array<string,int>,
 *   score:array<string,mixed>
 * } $bundle
 * @return array<string,mixed>
 */
function module_persist_customer_attitude_bundle(array $module, array $source, array $bundle): array
{
    $payload = $source['payload'] ?? [];
    if (!is_array($payload)) {
        $payload = [];
    }

    $customerCode = trim((string)($payload['customer_code'] ?? ($source['primary_ref'] ?? '')));
    if ($customerCode === '') {
        throw new RuntimeException('เนเธกเนเธเธเธฃเธซเธฑเธชเธฅเธนเธเธเนเธฒเน€เธเธทเนเธญเธเธนเธเนเธเธเธชเธญเธเธ–เธฒเธกเธ—เธฑเธจเธเธเธ•เธด');
    }

    $applicantName = trim((string)($payload['customer_name'] ?? ($source['primary_name'] ?? '')));
    $applicantGender = module_normalize_attitude_gender((string)($payload['gender'] ?? ($payload['sex'] ?? 'unknown')));
    $applicantAge = null;
    if (isset($payload['age']) && is_numeric((string)$payload['age'])) {
        $age = (int)$payload['age'];
        if ($age >= 1 && $age <= 120) {
            $applicantAge = $age;
        }
    }

    $meta = [
        'contract_no' => mb_substr($customerCode, 0, 120),
        'applicant_name' => mb_substr($applicantName, 0, 255),
        'applicant_gender' => $applicantGender,
        'applicant_age' => $applicantAge,
    ];

    return attitude_persist_assessment(
        db(),
        $module,
        $source,
        $bundle['question_set'],
        $bundle['question_items'],
        $meta,
        $bundle['answers'],
        $bundle['score'],
        'CREATE',
        null
    );
}

function module_create_record(array $module, array $input, string $reason = ''): array
{
    $payload = validate_and_normalize_payload($module, $input);
    $common = normalize_common_payload($input, null, $module);
    if (!(bool)($module['branch_independent'] ?? false)) {
        assert_branch_in_current_scope((string)($common['branch_code'] ?? ''));
    }
    $recordUid = sprintf('%s-%s-%04d', $module['code'], date('YmdHis'), random_int(1000, 9999));

    $recordId = module_persist_new_version(
        $module['key'],
        $recordUid,
        1,
        $payload,
        $common,
        'CREATE',
        $reason,
        null,
        false,
        $common['record_status'] ?: 'PENDING_CHECKER'
    );

    $created = module_find_latest_by_id($module['key'], $recordId);
    if ($created === null) {
        throw new RuntimeException('เนเธกเนเธเธเธฃเธฒเธขเธเธฒเธฃเธ—เธตเนเน€เธเธดเนเธเธเธฑเธเธ—เธถเธ');
    }

    return $created;
}

function module_update_record(array $module, array $input, string $reason = ''): array
{
    $sourceId = (int)($input['source_id'] ?? 0);
    if ($sourceId <= 0) {
        throw new RuntimeException('เนเธกเนเธเธ source_id เธชเธณเธซเธฃเธฑเธเธเธฒเธฃเนเธเนเนเธ');
    }

    $source = module_find_latest_by_id($module['key'], $sourceId);
    if ($source === null) {
        throw new RuntimeException('เธชเธฒเธกเธฒเธฃเธ–เนเธเนเนเธเนเธ”เนเน€เธเธเธฒเธฐเน€เธงเธญเธฃเนเธเธฑเธเธฅเนเธฒเธชเธธเธ”เน€เธ—เนเธฒเธเธฑเนเธ');
    }

        // Preserve existing auto-generated codes when update form keeps the field blank.
    $sourcePayload = json_decode((string)($source['data_json'] ?? ''), true);
    if (!is_array($sourcePayload)) {
        $sourcePayload = [];
    }
    foreach (($module['fields'] ?? []) as $field) {
        if (!is_array($field)) {
            continue;
        }
        $fieldName = trim((string)($field['name'] ?? ''));
        if ($fieldName === '' || (string)($field['type'] ?? '') !== 'auto_customer_code') {
            continue;
        }
        $incoming = trim((string)($input[$fieldName] ?? ''));
        if ($incoming !== '') {
            continue;
        }
        $fallback = strtoupper(trim((string)($sourcePayload[$fieldName] ?? '')));
        if ($fallback === '') {
            $fallback = strtoupper(trim((string)($source['primary_ref'] ?? '')));
        }
        if ($fallback !== '') {
            $input[$fieldName] = $fallback;
        }
    }
$payload = validate_and_normalize_payload($module, $input);
    $common = normalize_common_payload($input, $source, $module);
    if (!(bool)($module['branch_independent'] ?? false)) {
        assert_branch_in_current_scope((string)($common['branch_code'] ?? ''));
    }

    $recordId = module_persist_new_version(
        $module['key'],
        $source['record_uid'],
        ((int)$source['version_no']) + 1,
        $payload,
        $common,
        'UPDATE',
        $reason,
        $source,
        false,
        $common['record_status'] ?: 'PENDING_CHECKER'
    );

    $updated = module_find_latest_by_id($module['key'], $recordId);
    if ($updated === null) {
        throw new RuntimeException('เนเธกเนเธเธเธเนเธญเธกเธนเธฅเน€เธงเธญเธฃเนเธเธฑเธเธฅเนเธฒเธชเธธเธ”เธซเธฅเธฑเธเนเธเนเนเธ');
    }

    return $updated;
}

function module_approve_record(array $module, array $input, string $reason = ''): void
{
    if (!role_can_approve(current_role_name())) {
        throw new RuntimeException('เธเธฒเธฃเธญเธเธธเธกเธฑเธ•เธดเธ•เนเธญเธเนเธเนเธชเธดเธ—เธเธดเนเธเธนเนเธ•เธฃเธงเธเธชเธญเธเธซเธฃเธทเธญเธเธนเนเธเธฃเธดเธซเธฒเธฃ');
    }

    $sourceId = (int)($input['source_id'] ?? 0);
    if ($sourceId <= 0) {
        throw new RuntimeException('เนเธกเนเธเธ source_id เธชเธณเธซเธฃเธฑเธเธเธฒเธฃเธญเธเธธเธกเธฑเธ•เธด');
    }

    $source = module_find_latest_by_id($module['key'], $sourceId);
    if ($source === null) {
        throw new RuntimeException('เธชเธฒเธกเธฒเธฃเธ–เธญเธเธธเธกเธฑเธ•เธดเนเธ”เนเน€เธเธเธฒเธฐเธฃเธฒเธขเธเธฒเธฃเธฅเนเธฒเธชเธธเธ”เน€เธ—เนเธฒเธเธฑเนเธ');
    }
    if ((int)$source['is_deleted'] === 1) {
        throw new RuntimeException('เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธญเธเธธเธกเธฑเธ•เธดเธฃเธฒเธขเธเธฒเธฃเธ—เธตเนเธ–เธนเธเธฅเธเนเธเธเธ•เธฃเธฃเธเธฐเนเธฅเนเธง');
    }

    $payload = json_decode((string)$source['data_json'], true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $common = normalize_common_payload($source, $source, $module);
    $common['record_status'] = 'APPROVED';

    module_persist_new_version(
        $module['key'],
        $source['record_uid'],
        ((int)$source['version_no']) + 1,
        $payload,
        $common,
        'APPROVE',
        $reason,
        $source,
        false,
        'APPROVED'
    );
}

function module_delete_record(array $module, array $input, string $reason = ''): void
{
    $sourceId = (int)($input['source_id'] ?? 0);
    if ($sourceId <= 0) {
        throw new RuntimeException('เนเธกเนเธเธ source_id เธชเธณเธซเธฃเธฑเธเธเธฒเธฃเธฅเธ');
    }
    if ($reason === '') {
        throw new RuntimeException('เธเธฃเธธเธ“เธฒเธฃเธฐเธเธธเน€เธซเธ•เธธเธเธฅเนเธเธเธฒเธฃเธฅเธเนเธเธเธ•เธฃเธฃเธเธฐ');
    }

    $source = module_find_latest_by_id($module['key'], $sourceId);
    if ($source === null) {
        throw new RuntimeException('เธชเธฒเธกเธฒเธฃเธ–เธฅเธเนเธ”เนเน€เธเธเธฒเธฐเธฃเธฒเธขเธเธฒเธฃเธฅเนเธฒเธชเธธเธ”เน€เธ—เนเธฒเธเธฑเนเธ');
    }

    $payload = json_decode((string)$source['data_json'], true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $common = normalize_common_payload($source, $source, $module);
    $common['record_status'] = 'DELETED';

    module_persist_new_version(
        $module['key'],
        $source['record_uid'],
        ((int)$source['version_no']) + 1,
        $payload,
        $common,
        'SOFT_DELETE',
        $reason,
        $source,
        true,
        'DELETED'
    );
}

function module_persist_new_version(
    string $moduleKey,
    string $recordUid,
    int $versionNo,
    array $payload,
    array $common,
    string $action,
    string $reason,
    ?array $source,
    bool $isDeleted,
    string $statusOverride
): int {
    $pdo = db();
    $module = module_by_key($moduleKey);
    $actor = current_user_name();
    $role = current_role_name();
    $ip = request_ip();
    $device = request_device();
    $now = now_dt();

    $primaryRef = (string)($payload[$module['primary_ref_field']] ?? ($common['primary_ref'] ?? ''));
    $primaryName = (string)($payload[$module['primary_name_field']] ?? ($common['primary_name'] ?? ''));
    $amount = isset($payload[$module['amount_field']]) ? parse_decimal_or_null($payload[$module['amount_field']]) : ($common['amount'] ?? null);
    $riskLevel = isset($payload[$module['risk_field']]) ? (string)$payload[$module['risk_field']] : ($common['risk_level'] ?? null);
    $eventDate = isset($payload[$module['event_date_field']]) ? parse_date_or_null((string)$payload[$module['event_date_field']]) : ($common['event_date'] ?? null);

    $beforeJson = $source ? json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    $pdo->beginTransaction();
    try {
        $stmtFlag = $pdo->prepare('UPDATE workflow_records SET is_latest = 0 WHERE module_key = :module_key AND record_uid = :record_uid AND is_latest = 1');
        $stmtFlag->execute([
            ':module_key' => $moduleKey,
            ':record_uid' => $recordUid,
        ]);

        $stmtInsert = $pdo->prepare(
            'INSERT INTO workflow_records (
                module_key, record_uid, version_no, is_latest, is_deleted, record_status,
                primary_name, primary_ref, customer_ref, branch_code, risk_level, amount, event_date,
                data_json, consent_flag, risk_flags, note_text,
                created_by, created_role, created_at, created_ip, created_device,
                updated_by, updated_role, updated_at, updated_ip, updated_device,
                checker_by, checker_at, deleted_by, deleted_at
            ) VALUES (
                :module_key, :record_uid, :version_no, 1, :is_deleted, :record_status,
                :primary_name, :primary_ref, :customer_ref, :branch_code, :risk_level, :amount, :event_date,
                :data_json, :consent_flag, :risk_flags, :note_text,
                :created_by, :created_role, :created_at, :created_ip, :created_device,
                :updated_by, :updated_role, :updated_at, :updated_ip, :updated_device,
                :checker_by, :checker_at, :deleted_by, :deleted_at
            )'
        );

        $checkerBy = $statusOverride === 'APPROVED' ? $actor : null;
        $checkerAt = $statusOverride === 'APPROVED' ? $now : null;
        $deletedBy = $isDeleted ? $actor : null;
        $deletedAt = $isDeleted ? $now : null;

        $stmtInsert->execute([
            ':module_key' => $moduleKey,
            ':record_uid' => $recordUid,
            ':version_no' => $versionNo,
            ':is_deleted' => $isDeleted ? 1 : 0,
            ':record_status' => $statusOverride,
            ':primary_name' => $primaryName,
            ':primary_ref' => $primaryRef,
            ':customer_ref' => (string)($common['customer_ref'] ?? ''),
            ':branch_code' => (string)($common['branch_code'] ?? ''),
            ':risk_level' => $riskLevel,
            ':amount' => $amount,
            ':event_date' => $eventDate,
            ':data_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':consent_flag' => (int)($common['consent_flag'] ?? 0),
            ':risk_flags' => (string)($common['risk_flags'] ?? ''),
            ':note_text' => (string)($common['note_text'] ?? ''),
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
            ':checker_by' => $checkerBy,
            ':checker_at' => $checkerAt,
            ':deleted_by' => $deletedBy,
            ':deleted_at' => $deletedAt,
        ]);

        $recordId = (int)$pdo->lastInsertId();

        $afterPayload = [
            'record_id' => $recordId,
            'module_key' => $moduleKey,
            'record_uid' => $recordUid,
            'version_no' => $versionNo,
            'record_status' => $statusOverride,
            'payload' => $payload,
            'common' => $common,
        ];

        $afterJson = json_encode($afterPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmtLog = $pdo->prepare(
            'INSERT INTO action_logs (
                module_key, action_type, record_uid, version_no, reason,
                before_json, after_json, user_name, role_name, ip_address, device_info, created_at
            ) VALUES (
                :module_key, :action_type, :record_uid, :version_no, :reason,
                :before_json, :after_json, :user_name, :role_name, :ip_address, :device_info, :created_at
            )'
        );

        $stmtLog->execute([
            ':module_key' => $moduleKey,
            ':action_type' => $action,
            ':record_uid' => $recordUid,
            ':version_no' => $versionNo,
            ':reason' => $reason,
            ':before_json' => $beforeJson,
            ':after_json' => $afterJson,
            ':user_name' => $actor,
            ':role_name' => $role,
            ':ip_address' => $ip,
            ':device_info' => $device,
            ':created_at' => $now,
        ]);

        if (nanfin_table_exists($pdo, 'event_ledger')) {
            $stmtEvent = $pdo->prepare(
                'INSERT INTO event_ledger (
                    event_type, module_key, record_uid, version_no, event_payload,
                    actor_name, actor_role, ip_address, device_info, created_at
                ) VALUES (
                    :event_type, :module_key, :record_uid, :version_no, :event_payload,
                    :actor_name, :actor_role, :ip_address, :device_info, :created_at
                )'
            );

            $stmtEvent->execute([
                ':event_type' => $action,
                ':module_key' => $moduleKey,
                ':record_uid' => $recordUid,
                ':version_no' => $versionNo,
                ':event_payload' => $afterJson,
                ':actor_name' => $actor,
                ':actor_role' => $role,
                ':ip_address' => $ip,
                ':device_info' => $device,
                ':created_at' => $now,
            ]);
        }

        $stmtNotify = $pdo->prepare(
            'INSERT INTO notification_logs (
                module_key, record_uid, level_name, message_text, user_name, created_at
            ) VALUES (
                :module_key, :record_uid, :level_name, :message_text, :user_name, :created_at
            )'
        );

        $level = $isDeleted ? 'WARNING' : ($statusOverride === 'APPROVED' ? 'SUCCESS' : 'INFO');
        $message = sprintf('[%s] %s เน€เธงเธญเธฃเนเธเธฑเธ %s เนเธ”เธข %s', $module['title'], thai_action_label($action), $versionNo, $actor);

        $stmtNotify->execute([
            ':module_key' => $moduleKey,
            ':record_uid' => $recordUid,
            ':level_name' => $level,
            ':message_text' => $message,
            ':user_name' => $actor,
            ':created_at' => $now,
        ]);

        $pdo->commit();
        return $recordId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function module_field_is_occupation(array $field): bool
{
    if (module_field_is_json_list($field)) {
        return false;
    }

    $type = strtolower(trim((string)($field['type'] ?? '')));
    if ($type === 'occupation') {
        return true;
    }

    $name = strtolower(trim((string)($field['name'] ?? '')));
    return str_contains($name, 'occupation');
}

function module_field_is_json_list(array $field): bool
{
    $type = strtolower(trim((string)($field['type'] ?? '')));
    return $type === 'json_list';
}

function module_field_is_branch_selector(array $field): bool
{
    $type = strtolower(trim((string)($field['type'] ?? '')));
    if ($type === 'branch') {
        return true;
    }

    $name = strtolower(trim((string)($field['name'] ?? '')));
    return str_contains($name, 'branch_code');
}

function module_generate_customer_code(): string
{
    $prefix = 'CUS';
    $stmt = db()->prepare(
        "SELECT 1
         FROM workflow_records
         WHERE module_key = 'customer_360'
           AND is_latest = 1
           AND primary_ref = :code
         LIMIT 1"
    );

    for ($i = 0; $i < 25; $i++) {
        $candidate = $prefix . date('YmdHis') . sprintf('%03d', random_int(0, 999));
        $stmt->execute([':code' => $candidate]);
        if (!$stmt->fetchColumn()) {
            return $candidate;
        }
    }

    throw new RuntimeException('เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธชเธฃเนเธฒเธเธฃเธซเธฑเธชเธฅเธนเธเธเนเธฒเธญเธฑเธ•เนเธเธกเธฑเธ•เธดเนเธ”เน เธเธฃเธธเธ“เธฒเธฅเธญเธเนเธซเธกเนเธญเธตเธเธเธฃเธฑเนเธ');
}

/**
 * @return array<int, array<string,mixed>>
 */
function module_active_occupation_options(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $options = [];
    try {
        admin_ensure_master_occupation_table(db());
        admin_seed_default_occupations(db(), current_user_name());
        $rows = admin_fetch_occupation_rows(db());
        foreach ($rows as $row) {
            if ((int)($row['is_deleted'] ?? 0) === 1) {
                continue;
            }

            $options[] = [
                'occupation_name' => trim((string)($row['occupation_name'] ?? '')),
                'employment_type' => trim((string)($row['employment_type'] ?? '')),
                'province_name' => trim((string)($row['province_name'] ?? '')),
            ];
        }
    } catch (Throwable $e) {
        $options = [];
    }

    $cached = $options;
    return $cached;
}

/**
 * @return array<string,bool>
 */
function module_active_occupation_name_set(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $set = [];
    foreach (module_active_occupation_options() as $item) {
        $name = trim((string)($item['occupation_name'] ?? ''));
        if ($name !== '') {
            $set[$name] = true;
        }
    }

    $cached = $set;
    return $cached;
}

/**
 * @return array<string, string[]>
 */
function module_active_car_model_map(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $map = [];
    try {
        admin_ensure_master_car_table(db());
        admin_seed_default_car_models(db(), current_user_name());
        $map = admin_active_car_brand_model_map(db());
    } catch (Throwable $e) {
        $map = [];
    }

    $cached = $map;
    return $cached;
}

/**
 * @return string[]
 */
function module_active_car_brand_names(): array
{
    return array_keys(module_active_car_model_map());
}

/**
 * @return string[]
 */
function module_car_year_options(): array
{
    $years = [];
    $startYear = 1980;
    $endYear = (int)date('Y') + 1;
    for ($year = $endYear; $year >= $startYear; $year--) {
        $years[] = (string)$year;
    }
    return $years;
}

function module_store_json_list_file_value(
    string $rawValue,
    string $moduleKey,
    string $fieldName,
    string $columnKey,
    string $acceptPattern = ''
): string
{
    $value = trim($rawValue);
    if ($value === '') {
        return '';
    }

    if (!str_starts_with($value, 'data:')) {
        return $value;
    }

    if (!preg_match('#^data:([a-z0-9.+/-]+);base64,(.+)$#is', $value, $matches)) {
        throw new RuntimeException('เธฃเธนเธเนเธเธเนเธเธฅเนเนเธเธเนเธกเนเธ–เธนเธเธ•เนเธญเธ');
    }

    $mimeType = strtolower(trim((string)($matches[1] ?? '')));
    $base64Payload = (string)($matches[2] ?? '');
    $binary = base64_decode($base64Payload, true);
    if ($binary === false) {
        throw new RuntimeException('เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธ–เธญเธ”เธฃเธซเธฑเธชเนเธเธฅเนเนเธเธเนเธ”เน');
    }

    $fileSize = strlen($binary);
    $maxSize = 5 * 1024 * 1024;
    if ($fileSize > $maxSize) {
        throw new RuntimeException('เนเธเธฅเนเนเธเธเธ•เนเธญเธเธกเธตเธเธเธฒเธ”เนเธกเนเน€เธเธดเธ 5 MB');
    }

    $extensionMap = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($extensionMap[$mimeType])) {
        throw new RuntimeException('เธเธเธดเธ”เนเธเธฅเนเนเธเธเนเธกเนเธฃเธญเธเธฃเธฑเธ');
    }

    $allowedMimes = [];
    $acceptPattern = trim($acceptPattern);
    if ($acceptPattern !== '') {
        $acceptTokens = array_filter(array_map(static function ($token): string {
            return strtolower(trim((string)$token));
        }, explode(',', $acceptPattern)));

        foreach ($acceptTokens as $acceptToken) {
            if ($acceptToken === '.pdf') {
                $allowedMimes['application/pdf'] = true;
            } elseif ($acceptToken === '.jpg' || $acceptToken === '.jpeg') {
                $allowedMimes['image/jpeg'] = true;
            } elseif ($acceptToken === '.png') {
                $allowedMimes['image/png'] = true;
            } elseif ($acceptToken === '.webp') {
                $allowedMimes['image/webp'] = true;
            } elseif (strpos($acceptToken, '/') !== false) {
                $allowedMimes[$acceptToken] = true;
            } elseif ($acceptToken === '*/*') {
                $allowedMimes = [];
                break;
            }
        }
    }

    if ($allowedMimes !== [] && !isset($allowedMimes[$mimeType])) {
        throw new RuntimeException('เธเธฃเธฐเน€เธ เธ—เนเธเธฅเนเนเธเธเนเธกเนเธ–เธนเธเธ•เนเธญเธ (เธญเธเธธเธเธฒเธ•: ' . $acceptPattern . ')');
    }

    $extension = $extensionMap[$mimeType];

    $moduleSegment = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($moduleKey));
    if ($moduleSegment === null || $moduleSegment === '') {
        $moduleSegment = 'module';
    }
    $fieldSegment = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($fieldName . '_' . $columnKey));
    if ($fieldSegment === null || $fieldSegment === '') {
        $fieldSegment = 'file';
    }

    $relativeDir = 'uploads/collateral_attachments/' . $moduleSegment . '/' . date('Y/m');
    $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธชเธฃเนเธฒเธเนเธเธฅเน€เธ”เธญเธฃเนเน€เธเนเธเนเธเธฅเนเนเธเธเนเธ”เน');
    }

    $filename = $fieldSegment . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $absolutePath = $absoluteDir . '/' . $filename;
    if (file_put_contents($absolutePath, $binary) === false) {
        throw new RuntimeException('เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธเธฑเธเธ—เธถเธเนเธเธฅเนเนเธเธเนเธ”เน');
    }

    return app_base_url($relativeDir . '/' . $filename);
}

function module_normalize_select_value(string $value): string
{
    $normalized = trim(nanfin_normalize_display_text($value));
    if ($normalized === '') {
        return '';
    }

    $variants = [$normalized];
    if (function_exists('nanfin_latinish_text_to_bytes')) {
        $decoded = nanfin_latinish_text_to_bytes($normalized);
        if (is_string($decoded) && trim($decoded) !== '') {
            $variants[] = trim($decoded);
        }
    }
    if (function_exists('iconv')) {
        foreach (['Windows-1252', 'ISO-8859-1'] as $targetEncoding) {
            $converted = @iconv('UTF-8', $targetEncoding . '//IGNORE', $normalized);
            if (is_string($converted) && trim($converted) !== '') {
                $variants[] = trim($converted);
            }
        }
    }

    $best = $normalized;
    $bestScore = -1000000;
    foreach ($variants as $variant) {
        $thaiCount = preg_match_all('/[\x{0E00}-\x{0E7F}]/u', $variant);
        $badCount = substr_count($variant, '?') + substr_count($variant, '๏ฟฝ');
        $score = ((int)$thaiCount * 8) - ($badCount * 3);
        if ($score > $bestScore) {
            $best = $variant;
            $bestScore = $score;
        }
    }

    return mb_strtolower(trim($best), 'UTF-8');
}

function module_select_value_exists(string $value, array $options): bool
{
    $needle = module_normalize_select_value($value);
    if ($needle === '') {
        return false;
    }

    foreach ($options as $option) {
        if (!is_scalar($option)) {
            continue;
        }

        $candidate = module_normalize_select_value((string)$option);
        if ($candidate !== '' && $candidate === $needle) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<int,string>
 */
function module_select_options_from_map(array $optionsMap, string $parentValue): array
{
    $parentToken = module_normalize_select_value($parentValue);
    if ($parentToken === '') {
        return [];
    }

    foreach ($optionsMap as $mapKey => $mappedOptions) {
        if (!is_array($mappedOptions)) {
            continue;
        }

        if (module_normalize_select_value((string)$mapKey) === $parentToken) {
            return array_values($mappedOptions);
        }
    }

    return [];
}

function validate_and_normalize_payload(array $module, array $input): array
{
    $errors = [];
    $fieldErrors = [];
    $payload = [];
    $activeBranchMap = active_branch_map();

    $addError = static function (string $message, ?string $fieldKey = null) use (&$errors, &$fieldErrors): void {
        $text = trim($message);
        if ($text === '') {
            return;
        }

        $errors[] = $text;
        if ($fieldKey === null || trim($fieldKey) === '') {
            return;
        }

        $key = trim($fieldKey);
        if (!isset($fieldErrors[$key])) {
            $fieldErrors[$key] = [];
        }
        if (!in_array($text, $fieldErrors[$key], true)) {
            $fieldErrors[$key][] = $text;
        }
    };

    foreach ($module['fields'] as $field) {
        $name = (string)($field['name'] ?? '');
        $type = (string)($field['type'] ?? 'text');
        $required = (bool)($field['required'] ?? false);
        $raw = $input[$name] ?? null;
        $label = (string)($field['label'] ?? $name);

        if ($type === 'auto_customer_code') {
            $value = strtoupper(trim((string)$raw));
            if ($value === '') {
                $value = module_generate_customer_code();
            }
            $payload[$name] = $value;
            continue;
        }

        if (module_field_is_json_list($field)) {
            $rawJson = trim((string)$raw);
            if ($rawJson === '') {
                $rawJson = '[]';
            }

            $decodedItems = json_decode($rawJson, true);
            if (!is_array($decodedItems)) {
                $addError('เธฃเธนเธเนเธเธเธเนเธญเธกเธนเธฅเนเธกเนเธ–เธนเธเธ•เนเธญเธ: ' . $label, $name);
                $decodedItems = [];
            }

            $columns = $field['list_columns'] ?? [];
            if (!is_array($columns)) {
                $columns = [];
            }
            $maxItems = isset($field['max_items']) ? (int)$field['max_items'] : 0;

            $normalizedList = [];
            foreach ($decodedItems as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $normalizedItem = [];
                $hasAnyValue = false;
                foreach ($columns as $column) {
                    if (!is_array($column)) {
                        continue;
                    }

                    $columnKey = trim((string)($column['key'] ?? ''));
                    if ($columnKey === '') {
                        continue;
                    }

                    $columnLabel = (string)($column['label'] ?? $columnKey);
                    $columnInput = strtolower(trim((string)($column['input'] ?? 'text')));
                    $columnRequired = (bool)($column['required'] ?? false);
                    $columnRaw = $item[$columnKey] ?? null;

                    if ($columnInput === 'file') {
                        $columnValue = module_store_json_list_file_value(
                            (string)$columnRaw,
                            (string)($module['key'] ?? 'module'),
                            $name,
                            $columnKey,
                            trim((string)($column['accept'] ?? ''))
                        );
                        if ($columnValue !== '') {
                            $hasAnyValue = true;
                        }
                    } elseif ($columnInput === 'number') {
                        $columnValue = parse_decimal_or_null($columnRaw);
                        if ($columnValue !== null) {
                            $hasAnyValue = true;
                        }
                    } elseif ($columnInput === 'date') {
                        $columnValue = parse_date_or_null((string)$columnRaw);
                        if ($columnValue !== null) {
                            $hasAnyValue = true;
                        }
                    } else {
                        $columnValue = trim((string)$columnRaw);
                        if ($columnInput === 'select' && $columnValue !== '') {
                            $options = $column['options'] ?? [];
                            if (!is_array($options)) {
                                $options = [];
                            }

                            $optionsMap = $column['options_map'] ?? [];
                            if (!is_array($optionsMap)) {
                                $optionsMap = [];
                            }

                            $optionSource = strtolower(trim((string)($column['options_source'] ?? '')));
                            if ($optionSource === 'occupation' && $options === []) {
                                $options = array_keys(module_active_occupation_name_set());
                            } elseif ($optionSource === 'car_brand' && $options === []) {
                                $options = module_active_car_brand_names();
                            } elseif ($optionSource === 'car_model_by_brand' && $optionsMap === []) {
                                $optionsMap = module_active_car_model_map();
                            } elseif ($optionSource === 'car_year' && $options === []) {
                                $options = module_car_year_options();
                            }

                            $dependsOn = trim((string)($column['depends_on'] ?? ''));
                            if ($optionsMap !== [] && $dependsOn !== '') {
                                $parentValue = trim((string)($item[$dependsOn] ?? ''));
                                $options = module_select_options_from_map($optionsMap, $parentValue);
                            }

                            if ($options === [] || !module_select_value_exists($columnValue, $options)) {
                                $addError('เธเนเธฒเธ—เธตเนเน€เธฅเธทเธญเธเนเธกเนเธ–เธนเธเธ•เนเธญเธ: ' . $label . ' - ' . $columnLabel, $name . '.' . $columnKey);
                            }
                        }
                        if ($columnValue !== '') {
                            $hasAnyValue = true;
                        }
                    }

                    if ($columnRequired && ($columnValue === null || $columnValue === '')) {
                        $addError('เธเธฃเธธเธ“เธฒเธเธฃเธญเธเธเนเธญเธกเธนเธฅ: ' . $label . ' - ' . $columnLabel, $name . '.' . $columnKey);
                    }

                    $normalizedItem[$columnKey] = $columnValue;
                }

                if ($hasAnyValue) {
                    $normalizedList[] = $normalizedItem;
                }
            }

            if ($required && $normalizedList === []) {
                $addError('เธเธฃเธธเธ“เธฒเน€เธเธดเนเธกเธเนเธญเธกเธนเธฅเธญเธขเนเธฒเธเธเนเธญเธข 1 เธฃเธฒเธขเธเธฒเธฃ: ' . $label, $name);
            }

            if ($maxItems > 0 && count($normalizedList) > $maxItems) {
                $addError($label . ' เธ•เนเธญเธเธกเธตเนเธ”เนเนเธกเนเน€เธเธดเธ ' . $maxItems . ' เธฃเธฒเธขเธเธฒเธฃ', $name);
            }

            $payload[$name] = json_encode($normalizedList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            continue;
        }

        if (module_field_is_occupation($field)) {
            $value = trim((string)$raw);
            if ($required && $value === '') {
                $addError('เธเธฃเธธเธ“เธฒเธเธฃเธญเธเธเนเธญเธกเธนเธฅ: ' . $label, $name);
            }

            $validNames = module_active_occupation_name_set();
            if ($value !== '' && $validNames !== [] && !isset($validNames[$value])) {
                $addError('เนเธกเนเธเธเธญเธฒเธเธตเธเนเธเธฃเธฒเธขเธเธฒเธฃเธ—เธตเนเธเธณเธซเธเธ”: ' . $label, $name);
            }

            $payload[$name] = $value;
            continue;
        }

        if (module_field_is_branch_selector($field)) {
            $value = strtoupper(trim((string)$raw));
            if ($required && $value === '') {
                $addError('เธเธฃเธธเธ“เธฒเธเธฃเธญเธเธเนเธญเธกเธนเธฅ: ' . $label, $name);
            }

            if ($value !== '' && $activeBranchMap !== [] && !isset($activeBranchMap[$value])) {
                $addError('เนเธกเนเธเธเธฃเธซเธฑเธชเธชเธฒเธเธฒเนเธเธฃเธฐเธเธ: ' . $label, $name);
            }

            if ($value !== '' && !is_branch_in_current_scope($value)) {
                $addError('เนเธกเนเธกเธตเธชเธดเธ—เธเธดเนเน€เธฅเธทเธญเธเธชเธฒเธเธฒเธเธตเน: ' . $label, $name);
            }

            $payload[$name] = $value;
            continue;
        }

        if ($type === 'number') {
            $value = parse_decimal_or_null($raw);
            if ($required && $value === null) {
                $addError('เธเธฃเธธเธ“เธฒเธเธฃเธญเธเธเนเธญเธกเธนเธฅ: ' . $label, $name);
            }
            $payload[$name] = $value;
            continue;
        }

        if ($type === 'date') {
            $value = parse_date_or_null((string)$raw);
            if ($required && $value === null) {
                $addError('เธเธฃเธธเธ“เธฒเธฃเธฐเธเธธเธงเธฑเธเธ—เธตเน: ' . $label, $name);
            }
            $payload[$name] = $value;
            continue;
        }

        $value = trim((string)$raw);

        if ($required && $value === '') {
            $addError('เธเธฃเธธเธ“เธฒเธเธฃเธญเธเธเนเธญเธกเธนเธฅ: ' . $label, $name);
        }

        if ($type === 'select' && $value !== '') {
            $options = $field['options'] ?? [];
            if (!is_array($options) || !module_select_value_exists($value, $options)) {
                $addError('เธเนเธฒเธ—เธตเนเน€เธฅเธทเธญเธเนเธกเนเธ–เธนเธเธ•เนเธญเธ: ' . $label, $name);
            }
        }

        $payload[$name] = $value;
    }

    if ((string)($module['key'] ?? '') === 'credit_policy') {
        module_apply_credit_policy_recommended_values($payload);
    }

    if ((string)($module['key'] ?? '') === 'customer_360') {
        $titleName = trim((string)($payload['title_name'] ?? ''));
        $firstName = trim((string)($payload['first_name'] ?? ''));
        $lastName = trim((string)($payload['last_name'] ?? ''));
        $fullNameParts = [];
        if ($titleName !== '') {
            $fullNameParts[] = $titleName;
        }
        if ($firstName !== '') {
            $fullNameParts[] = $firstName;
        }
        if ($lastName !== '') {
            $fullNameParts[] = $lastName;
        }
        $payload['customer_name'] = trim(implode(' ', $fullNameParts));

        if ($payload['customer_name'] === '') {
            $addError('เธเธฃเธธเธ“เธฒเธเธฃเธญเธเธเธทเนเธญ-เธเธฒเธกเธชเธเธธเธฅเธฅเธนเธเธเนเธฒ', 'first_name');
            $addError('เธเธฃเธธเธ“เธฒเธเธฃเธญเธเธเธทเนเธญ-เธเธฒเธกเธชเธเธธเธฅเธฅเธนเธเธเนเธฒ', 'last_name');
        }

        $email = trim((string)($payload['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $addError('เธฃเธนเธเนเธเธเธญเธตเน€เธกเธฅเนเธกเนเธ–เธนเธเธ•เนเธญเธ', 'email');
        }
        $payload['email'] = $email;
    }

    if ($errors !== []) {
        throw new ModuleValidationException($errors, $fieldErrors);
    }

    return $payload;
}

function normalize_common_payload(array $input, ?array $fallbackRow = null, ?array $module = null): array
{
    $fallbackPayload = [];
    if ($fallbackRow !== null && isset($fallbackRow['data_json'])) {
        $decoded = json_decode((string)$fallbackRow['data_json'], true);
        if (is_array($decoded)) {
            $fallbackPayload = $decoded;
        }
    }

    $isBranchIndependent = (bool)($module['branch_independent'] ?? false);
    $branchCode = strtoupper(trim((string)($input['branch_code'] ?? ($fallbackRow['branch_code'] ?? ''))));
    $fallbackBranchCode = strtoupper(trim((string)($fallbackRow['branch_code'] ?? '')));
    $scope = current_access_scope();
    if (!$isBranchIndependent && $scope['scope'] === 'branch' && $scope['branch_code'] !== '' && $branchCode === '') {
        $branchCode = (string)$scope['branch_code'];
    }
    if ($isBranchIndependent) {
        $branchCode = '';
    }
    $activeBranchMap = active_branch_map();
    if (!$isBranchIndependent && $branchCode !== '' && $activeBranchMap !== [] && !isset($activeBranchMap[$branchCode]) && $branchCode !== $fallbackBranchCode) {
        throw new RuntimeException('เนเธกเนเธเธเธฃเธซเธฑเธชเธชเธฒเธเธฒเนเธเธฃเธฐเธเธ');
    }

    return [
        'branch_code' => $branchCode,
        'customer_ref' => trim((string)($input['customer_ref_common'] ?? ($fallbackRow['customer_ref'] ?? ($fallbackPayload['customer_ref'] ?? '')))),
        'risk_level' => trim((string)($input['risk_level'] ?? ($fallbackRow['risk_level'] ?? ''))),
        'amount' => parse_decimal_or_null($input['amount'] ?? ($fallbackRow['amount'] ?? null)),
        'event_date' => parse_date_or_null((string)($input['event_date'] ?? ($fallbackRow['event_date'] ?? ''))),
        'consent_flag' => (int)($input['consent_flag'] ?? ($fallbackRow['consent_flag'] ?? 0)),
        'risk_flags' => trim((string)($input['risk_flags_common'] ?? ($fallbackRow['risk_flags'] ?? ''))),
        'note_text' => trim((string)($input['note_text'] ?? ($fallbackRow['note_text'] ?? ''))),
        'record_status' => trim((string)($input['record_status'] ?? ($fallbackRow['record_status'] ?? 'PENDING_CHECKER'))),
        'primary_ref' => trim((string)($input['primary_ref'] ?? ($fallbackRow['primary_ref'] ?? ''))),
        'primary_name' => trim((string)($input['primary_name'] ?? ($fallbackRow['primary_name'] ?? ''))),
    ];
}

function module_scope_filter(string $moduleKey, string $aliasPrefix): array
{
    $module = module_by_key($moduleKey);
    if ((bool)($module['branch_independent'] ?? false)) {
        return ['sql' => '', 'params' => []];
    }

    return access_scope_sql_clause('branch_code', $aliasPrefix);
}

function module_income_band_midpoint(?string $incomeBand): ?float
{
    $key = trim((string)$incomeBand);
    if ($key === '') {
        return null;
    }

    $map = [
        '10000-14999' => 12500.0,
        '15000-24999' => 20000.0,
        '25000-39999' => 32500.0,
        '40000-59999' => 50000.0,
        '60000+' => 65000.0,
    ];

    return $map[$key] ?? null;
}

/**
 * @param array<string,mixed> $payload
 */
function module_apply_credit_policy_recommended_values(array &$payload): void
{
    $incomeMidpoint = module_income_band_midpoint((string)($payload['income_band_ref'] ?? ''));
    $maxDsrPct = parse_decimal_or_null($payload['max_dsr_pct'] ?? null);
    $existingDebt = parse_decimal_or_null($payload['debt_obligation_ref'] ?? null);
    $collateralValue = parse_decimal_or_null($payload['collateral_value_ref'] ?? null);
    $maxLtvPct = parse_decimal_or_null($payload['max_ltv_pct'] ?? null);
    $annualRatePct = parse_decimal_or_null($payload['policy_interest_rate_pct'] ?? null);
    $tenorMonth = parse_decimal_or_null($payload['max_tenor_month'] ?? null);

    if ($incomeMidpoint !== null) {
        $payload['income_midpoint_ref'] = round($incomeMidpoint, 2);
    }

    $debtPerMonth = $existingDebt ?? 0.0;
    $installmentCapacity = null;
    if ($incomeMidpoint !== null && $maxDsrPct !== null) {
        $installmentCapacity = ($incomeMidpoint * ($maxDsrPct / 100.0)) - $debtPerMonth;
        if ($installmentCapacity < 0) {
            $installmentCapacity = 0.0;
        }
        $payload['recommended_installment'] = round($installmentCapacity, 2);
    }

    $loanByDsr = null;
    if ($installmentCapacity !== null && $tenorMonth !== null && $tenorMonth > 0) {
        if ($annualRatePct !== null && $annualRatePct > 0) {
            $monthlyRate = $annualRatePct / 1200.0;
            $pow = pow(1.0 + $monthlyRate, (float)(-$tenorMonth));
            $factor = (1.0 - $pow) / $monthlyRate;
            $loanByDsr = $installmentCapacity * $factor;
        } else {
            $loanByDsr = $installmentCapacity * $tenorMonth;
        }
    }

    $loanByLtv = null;
    if ($collateralValue !== null && $maxLtvPct !== null) {
        $loanByLtv = $collateralValue * ($maxLtvPct / 100.0);
    }

    $recommended = null;
    if ($loanByDsr !== null && $loanByLtv !== null) {
        $recommended = min($loanByDsr, $loanByLtv);
    } elseif ($loanByDsr !== null) {
        $recommended = $loanByDsr;
    } elseif ($loanByLtv !== null) {
        $recommended = $loanByLtv;
    }

    if ($recommended !== null) {
        if ($recommended < 0) {
            $recommended = 0.0;
        }
        $payload['recommended_loan_amount'] = round($recommended, 2);
    }
}

function module_find_latest_by_id(string $moduleKey, int $id, bool $includeDeleted = false): ?array
{
    $scopeFilter = module_scope_filter($moduleKey, 'scope_find');
    $deletedSql = $includeDeleted ? '' : ' AND is_deleted = 0';
    $stmt = db()->prepare('SELECT * FROM workflow_records WHERE id = :id AND module_key = :module_key AND is_latest = 1' . $deletedSql . $scopeFilter['sql'] . ' LIMIT 1');
    $params = [
        ':id' => $id,
        ':module_key' => $moduleKey,
    ];
    foreach ($scopeFilter['params'] as $name => $value) {
        $params[$name] = $value;
    }
    $stmt->execute($params);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $decoded = json_decode((string)$row['data_json'], true);
    $row['payload'] = is_array($decoded) ? $decoded : [];
    return $row;
}

function module_list_latest(string $moduleKey, string $searchTerm = '', bool $includeDeleted = false): array
{
    $limit = (int)(app_config()['max_rows_per_module'] ?? 50);
    if ($limit < 1) {
        $limit = 50;
    }
    $hasSearch = $searchTerm !== '';
    $scopeFilter = module_scope_filter($moduleKey, 'scope_list');
    $deletedSql = $includeDeleted ? '' : ' AND is_deleted = 0';
    $baseSql = ' FROM workflow_records WHERE module_key = :module_key AND is_latest = 1' . $deletedSql . $scopeFilter['sql'];
    $db = db();
    $rows = [];

    if (!$hasSearch) {
        $stmt = $db->prepare('SELECT *' . $baseSql . ' ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':module_key', $moduleKey, PDO::PARAM_STR);
        foreach ($scopeFilter['params'] as $name => $value) {
            $stmt->bindValue($name, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
    } else {
        $searchPrefix = $searchTerm . '%';
        $searchContains = '%' . $searchTerm . '%';
        $searchColumns = ['record_uid', 'primary_ref', 'customer_ref', 'branch_code', 'primary_name'];
        $seen = [];

        foreach ($searchColumns as $column) {
            if (count($rows) >= $limit) {
                break;
            }

            $remain = $limit - count($rows);
            $sql = 'SELECT *' . $baseSql . ' AND ' . $column . ' LIKE :search_value';

            if ($seen !== []) {
                $idPlaceholders = [];
                foreach (array_keys($seen) as $idx => $idValue) {
                    $idPlaceholders[] = ':exclude_id_' . $idx;
                }
                $sql .= ' AND id NOT IN (' . implode(', ', $idPlaceholders) . ')';
            }

            $sql .= ' ORDER BY id DESC LIMIT :limit';
            $stmtSearch = $db->prepare($sql);
            $stmtSearch->bindValue(':module_key', $moduleKey, PDO::PARAM_STR);
            foreach ($scopeFilter['params'] as $name => $value) {
                $stmtSearch->bindValue($name, $value, PDO::PARAM_STR);
            }
            $stmtSearch->bindValue(':search_value', $searchPrefix, PDO::PARAM_STR);
            $idx = 0;
            foreach (array_keys($seen) as $idValue) {
                $stmtSearch->bindValue(':exclude_id_' . $idx, (int)$idValue, PDO::PARAM_INT);
                $idx++;
            }
            $stmtSearch->bindValue(':limit', $remain, PDO::PARAM_INT);
            $stmtSearch->execute();

            $batch = $stmtSearch->fetchAll();
            foreach ($batch as $row) {
                $rowId = (int)($row['id'] ?? 0);
                if ($rowId <= 0 || isset($seen[$rowId])) {
                    continue;
                }
                $rows[] = $row;
                $seen[$rowId] = true;
            }
        }

        // Thai fallback: allow contains search for names (e.g. searching by first name after title).
        $hasThaiInput = preg_match('/[\x{0E00}-\x{0E7F}]/u', $searchTerm) === 1;
        if ($hasThaiInput && count($rows) < $limit) {
            $remain = $limit - count($rows);
            $sql = 'SELECT *' . $baseSql . ' AND primary_name LIKE :search_value';
            if ($seen !== []) {
                $idPlaceholders = [];
                foreach (array_keys($seen) as $idx => $idValue) {
                    $idPlaceholders[] = ':exclude_id_' . $idx;
                }
                $sql .= ' AND id NOT IN (' . implode(', ', $idPlaceholders) . ')';
            }
            $sql .= ' ORDER BY id DESC LIMIT :limit';

            $stmtContains = $db->prepare($sql);
            $stmtContains->bindValue(':module_key', $moduleKey, PDO::PARAM_STR);
            foreach ($scopeFilter['params'] as $name => $value) {
                $stmtContains->bindValue($name, $value, PDO::PARAM_STR);
            }
            $stmtContains->bindValue(':search_value', $searchContains, PDO::PARAM_STR);
            $idx = 0;
            foreach (array_keys($seen) as $idValue) {
                $stmtContains->bindValue(':exclude_id_' . $idx, (int)$idValue, PDO::PARAM_INT);
                $idx++;
            }
            $stmtContains->bindValue(':limit', $remain, PDO::PARAM_INT);
            $stmtContains->execute();

            $batch = $stmtContains->fetchAll();
            foreach ($batch as $row) {
                $rowId = (int)($row['id'] ?? 0);
                if ($rowId <= 0 || isset($seen[$rowId])) {
                    continue;
                }
                $rows[] = $row;
                $seen[$rowId] = true;
            }
        }

        // Customer 360 fallback: allow contains search inside JSON payload
        // for first name, last name, customer code, and citizen ID.
        if ($moduleKey === 'customer_360' && count($rows) < $limit) {
            $remain = $limit - count($rows);
            $sql = 'SELECT *' . $baseSql . ' AND data_json LIKE :search_value';
            if ($seen !== []) {
                $idPlaceholders = [];
                foreach (array_keys($seen) as $idx => $idValue) {
                    $idPlaceholders[] = ':exclude_id_' . $idx;
                }
                $sql .= ' AND id NOT IN (' . implode(', ', $idPlaceholders) . ')';
            }
            $sql .= ' ORDER BY id DESC LIMIT :limit';

            $stmtContainsJson = $db->prepare($sql);
            $stmtContainsJson->bindValue(':module_key', $moduleKey, PDO::PARAM_STR);
            foreach ($scopeFilter['params'] as $name => $value) {
                $stmtContainsJson->bindValue($name, $value, PDO::PARAM_STR);
            }
            $stmtContainsJson->bindValue(':search_value', $searchContains, PDO::PARAM_STR);
            $idx = 0;
            foreach (array_keys($seen) as $idValue) {
                $stmtContainsJson->bindValue(':exclude_id_' . $idx, (int)$idValue, PDO::PARAM_INT);
                $idx++;
            }
            $stmtContainsJson->bindValue(':limit', $remain, PDO::PARAM_INT);
            $stmtContainsJson->execute();

            $batch = $stmtContainsJson->fetchAll();
            foreach ($batch as $row) {
                $rowId = (int)($row['id'] ?? 0);
                if ($rowId <= 0 || isset($seen[$rowId])) {
                    continue;
                }
                $rows[] = $row;
                $seen[$rowId] = true;
            }
        }
    }

    foreach ($rows as &$row) {
        $decoded = json_decode((string)$row['data_json'], true);
        $row['payload'] = is_array($decoded) ? $decoded : [];
    }

    return $rows;
}

/**
 * @return string[]
 */
function module_customer_search_autocomplete_options(string $moduleKey, int $limit = 180, bool $includeDeleted = false): array
{
    $scopeFilter = module_scope_filter($moduleKey, 'scope_auto');
    $fetchLimit = max(300, min(2000, $limit * 6));
    $deletedSql = $includeDeleted ? '' : ' AND is_deleted = 0';
    $sql = 'SELECT primary_name, primary_ref, data_json FROM workflow_records'
        . ' WHERE module_key = :module_key AND is_latest = 1'
        . $deletedSql
        . $scopeFilter['sql']
        . ' ORDER BY id DESC LIMIT :fetch_limit';
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':module_key', $moduleKey, PDO::PARAM_STR);
    foreach ($scopeFilter['params'] as $name => $value) {
        $stmt->bindValue($name, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':fetch_limit', $fetchLimit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $results = [];
    foreach ($rows as $row) {
        $payload = json_decode((string)($row['data_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $firstName = trim((string)($payload['first_name'] ?? ''));
        $lastName = trim((string)($payload['last_name'] ?? ''));
        $fullName = trim($firstName . ' ' . $lastName);

        $candidates = [
            trim((string)($row['primary_name'] ?? '')),
            trim((string)($row['primary_ref'] ?? '')),
            trim((string)($payload['customer_code'] ?? '')),
            trim((string)($payload['cid_tax_id'] ?? '')),
            $firstName,
            $lastName,
            $fullName,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $key = mb_strtolower($candidate, 'UTF-8');
            if (isset($results[$key])) {
                continue;
            }
            $results[$key] = $candidate;
            if (count($results) >= $limit) {
                break 2;
            }
        }
    }

    return array_values($results);
}

function module_summary(string $moduleKey, bool $includeDeleted = false): array
{
    $scopeFilter = module_scope_filter($moduleKey, 'scope_sum');
    $params = [':module_key' => $moduleKey];
    foreach ($scopeFilter['params'] as $name => $value) {
        $params[$name] = $value;
    }

    $deletedSql = $includeDeleted ? '' : ' AND is_deleted = 0';
    $baseWhere = ' FROM workflow_records WHERE module_key = :module_key AND is_latest = 1' . $deletedSql . $scopeFilter['sql'];
    $baseWhereAll = ' FROM workflow_records WHERE module_key = :module_key AND is_latest = 1' . $scopeFilter['sql'];
    $queries = [
        'total_rows' => 'SELECT COUNT(*)' . $baseWhere,
        'deleted_rows' => 'SELECT COUNT(*)' . $baseWhereAll . ' AND is_deleted = 1',
        'pending_rows' => 'SELECT COUNT(*)' . $baseWhere . ' AND record_status = "PENDING_CHECKER"',
        'approved_rows' => 'SELECT COUNT(*)' . $baseWhere . ' AND record_status = "APPROVED"',
    ];

    $result = [
        'total_rows' => 0,
        'deleted_rows' => 0,
        'pending_rows' => 0,
        'approved_rows' => 0,
    ];

    $db = db();
    foreach ($queries as $key => $sql) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $result[$key] = (int)$stmt->fetchColumn();
    }

    return $result;
}

function render_module_page(array $context): void
{
    $module = $context['module'];
    $rows = $context['rows'];
    $edit = $context['edit'];
    $searchTerm = (string)($context['search_term'] ?? '');
    $showDeleted = (bool)($context['show_deleted'] ?? false);
    $canViewDeleted = (bool)($context['can_view_deleted'] ?? false);
    $moduleKey = (string)($module['key'] ?? '');
    $summary = module_summary($moduleKey, $showDeleted && $canViewDeleted);
    $validationState = $context['validation'] ?? [];
    $payload = $edit['payload'] ?? [];
    $isEdit = $edit !== null;
    $validationFields = [];
    if (is_array($validationState) && isset($validationState['fields']) && is_array($validationState['fields'])) {
        $validationFields = $validationState['fields'];
    }
    $invalidFieldLookup = [];
    $invalidFieldMessages = [];
    foreach ($validationFields as $fieldPath => $messages) {
        $path = trim((string)$fieldPath);
        if ($path === '') {
            continue;
        }

        $baseField = trim((string)explode('.', $path, 2)[0]);
        if ($baseField === '') {
            continue;
        }

        $invalidFieldLookup[$path] = true;
        $invalidFieldLookup[$baseField] = true;

        if (!is_array($messages)) {
            $messages = [$messages];
        }
        foreach ($messages as $message) {
            $text = trim((string)$message);
            if ($text === '') {
                continue;
            }
            if (!isset($invalidFieldMessages[$baseField])) {
                $invalidFieldMessages[$baseField] = [];
            }
            if (!in_array($text, $invalidFieldMessages[$baseField], true)) {
                $invalidFieldMessages[$baseField][] = $text;
            }
        }
    }
    $hasValidationErrors = $invalidFieldLookup !== [];
    $scope = current_access_scope();
    $branchInputValue = strtoupper(trim((string)($edit['branch_code'] ?? '')));
    if ($branchInputValue === '' && $scope['scope'] === 'branch') {
        $branchInputValue = (string)$scope['branch_code'];
    }
    $branchInputReadonly = $scope['scope'] === 'branch';
    $activeBranchMap = active_branch_map();
    $allowedBranchCodes = accessible_branch_codes($scope);
    $allowedBranchLookup = [];
    foreach ($allowedBranchCodes as $code) {
        $allowedBranchLookup[strtoupper(trim((string)$code))] = true;
    }
    $branchOptions = [];
    foreach ($activeBranchMap as $code => $branch) {
        $code = strtoupper(trim((string)$code));
        if ($code === '') {
            continue;
        }
        if ($scope['scope'] !== 'all' && !isset($allowedBranchLookup[$code])) {
            continue;
        }
        $branchOptions[] = [
            'branch_code' => $code,
            'branch_name' => trim((string)($branch['branch_name'] ?? '')),
            'region_name' => trim((string)($branch['region_name'] ?? '')),
        ];
    }
    if ($branchInputValue !== '' && !isset($activeBranchMap[$branchInputValue])) {
        $branchOptions[] = [
            'branch_code' => $branchInputValue,
            'branch_name' => '(existing branch)',
            'region_name' => '',
        ];
    }
    usort(
        $branchOptions,
        static fn(array $a, array $b): int => strcmp((string)($a['branch_code'] ?? ''), (string)($b['branch_code'] ?? ''))
    );

    $moduleFields = $module['fields'] ?? [];
    $hasModuleBranchField = false;
    $hasJsonListField = false;
    foreach ($moduleFields as $field) {
        if (module_field_is_branch_selector((array)$field)) {
            $hasModuleBranchField = true;
        }
        if (module_field_is_json_list((array)$field)) {
            $hasJsonListField = true;
        }
        if ($hasModuleBranchField && $hasJsonListField) {
            break;
        }
    }
    $fieldColumns = [[], [], []];
    foreach ($moduleFields as $index => $field) {
        $column = (int)($field['column'] ?? 0);
        if ($column >= 1 && $column <= 3) {
            $fieldColumns[$column - 1][] = $field;
            continue;
        }
        $fieldColumns[$index % 3][] = $field;
    }

    $selfPath = basename((string)($_SERVER['PHP_SELF'] ?? ''));
    $searchParams = [];
    if ($searchTerm !== '') {
        $searchParams['q'] = $searchTerm;
    }
    if ($showDeleted) {
        $searchParams['show_deleted'] = '1';
    }
    $searchSuffix = $searchParams !== [] ? ('?' . http_build_query($searchParams)) : '';
    $editSuffix = $searchParams !== [] ? ('&' . http_build_query($searchParams)) : '';
    $occupationOptions = module_active_occupation_options();
    $showEmbeddedAttitudeSurvey = (string)($module['key'] ?? '') === 'customer_360' && !$isEdit;
    $embeddedAttitudeQuestionItems = [];
    $embeddedAttitudeDimensions = [];
    if ($showEmbeddedAttitudeSurvey) {
        try {
            attitude_bootstrap(db(), current_user_name() !== '' ? current_user_name() : 'system_seed');
            $embeddedSet = attitude_fetch_latest_question_set(db());
            $embeddedAttitudeQuestionItems = attitude_fetch_question_items(db(), (int)$embeddedSet['id']);
            foreach ($embeddedAttitudeQuestionItems as $item) {
                $dimCode = (string)($item['dimension_code'] ?? '');
                $dimLabel = (string)($item['dimension_label'] ?? $dimCode);
                if ($dimCode === '') {
                    continue;
                }
                if (!isset($embeddedAttitudeDimensions[$dimCode])) {
                    $embeddedAttitudeDimensions[$dimCode] = [
                        'label' => $dimLabel,
                        'items' => [],
                    ];
                }
                $embeddedAttitudeDimensions[$dimCode]['items'][] = $item;
            }
        } catch (Throwable $e) {
            $showEmbeddedAttitudeSurvey = false;
            $embeddedAttitudeQuestionItems = [];
            $embeddedAttitudeDimensions = [];
        }
    }
    $useBottomSubmitPanel = $showEmbeddedAttitudeSurvey && $embeddedAttitudeDimensions !== [];
    $isCustomer360Module = (string)($module['key'] ?? '') === 'customer_360';
    $isBranchIndependent = (bool)($module['branch_independent'] ?? false);
    $hideCommonContextFields = (bool)($module['hide_common_context_fields'] ?? false);
    $showCommonMetaFields = !$isCustomer360Module && !$hideCommonContextFields;
    $showCommonBranchField = $showCommonMetaFields && !$hasModuleBranchField && !$isBranchIndependent;
    $searchInputPlaceholder = 'Search by code, name, reference, or branch';
    $searchAutocompleteOptions = [];
    $searchDatalistId = '';
    if ($isCustomer360Module) {
        $searchInputPlaceholder = 'Search by first name, last name, customer code, or citizen ID';
        $searchAutocompleteOptions = module_customer_search_autocomplete_options((string)$module['key'], 180, $showDeleted);
        $searchDatalistId = 'searchSuggest_' . preg_replace('/[^a-z0-9_]+/i', '_', (string)$module['key']);
    }
    if (isset($module['search_placeholder']) && trim((string)$module['search_placeholder']) !== '') {
        $searchInputPlaceholder = trim((string)$module['search_placeholder']);
    }

    $renderField = function (array $field, $value, string $wrapperClass = 'mb-3') use ($occupationOptions, $branchOptions, $branchInputReadonly, $scope, $invalidFieldLookup, $invalidFieldMessages): void {
        $name = (string)$field['name'];
        $type = (string)$field['type'];
        $required = (bool)($field['required'] ?? false);
        $isInvalid = isset($invalidFieldLookup[$name]);
        $invalidClass = $isInvalid ? ' is-invalid' : '';
        $messages = $invalidFieldMessages[$name] ?? [];
        $messageText = is_array($messages) ? implode(' | ', $messages) : '';
        ?>
        <div class="<?php echo h($wrapperClass); ?>">
            <label class="form-label"><?php echo h((string)$field['label']); ?><?php echo $required ? ' *' : ''; ?></label>
            <?php if ($type === 'auto_customer_code'): ?>
                <?php $codeValue = strtoupper(trim((string)$value)); ?>
                <input class="form-control<?php echo $invalidClass; ?>" name="<?php echo h($name); ?>" value="<?php echo h($codeValue); ?>" readonly placeholder="System-generated when saving">
            <?php elseif (module_field_is_branch_selector($field)): ?>
                <?php $branchFieldValue = strtoupper(trim((string)$value)); ?>
                <?php if ($branchFieldValue === '' && $branchInputReadonly): ?>
                    <?php $branchFieldValue = strtoupper(trim((string)($scope['branch_code'] ?? ''))); ?>
                <?php endif; ?>
                <?php $hasSelectedBranch = false; ?>
                <select class="form-select<?php echo $invalidClass; ?>" name="<?php echo h($name); ?>" <?php echo $required ? 'required' : ''; ?> <?php echo $branchInputReadonly ? 'disabled' : ''; ?>>
                    <option value="">-- Select Branch --</option>
                    <?php foreach ($branchOptions as $branchItem): ?>
                        <?php
                            $optionCode = strtoupper(trim((string)($branchItem['branch_code'] ?? '')));
                            if ($optionCode === '') {
                                continue;
                            }
                            $isSelected = $branchFieldValue === $optionCode;
                            if ($isSelected) {
                                $hasSelectedBranch = true;
                            }
                            $optionName = trim((string)($branchItem['branch_name'] ?? ''));
                            $optionRegion = trim((string)($branchItem['region_name'] ?? ''));
                            $optionLabel = $optionCode . ($optionName !== '' ? (' - ' . $optionName) : '') . ($optionRegion !== '' ? (' (' . $optionRegion . ')') : '');
                        ?>
                        <option value="<?php echo h($optionCode); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                            <?php echo h($optionLabel); ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if (!$hasSelectedBranch && $branchFieldValue !== ''): ?>
                        <option value="<?php echo h($branchFieldValue); ?>" selected><?php echo h($branchFieldValue . ' (existing value)'); ?></option>
                    <?php elseif ($branchOptions === []): ?>
                        <option value="" disabled>No branch data available</option>
                    <?php endif; ?>
                </select>
                <?php if ($branchInputReadonly): ?>
                    <input type="hidden" name="<?php echo h($name); ?>" value="<?php echo h($branchFieldValue); ?>">
                <?php endif; ?>
            <?php elseif (module_field_is_occupation($field)): ?>
                <?php if ($occupationOptions === []): ?>
                    <input class="form-control<?php echo $invalidClass; ?>" name="<?php echo h($name); ?>" value="<?php echo h((string)$value); ?>" <?php echo $required ? 'required' : ''; ?>>
                <?php else: ?>
                    <select class="form-select<?php echo $invalidClass; ?>" name="<?php echo h($name); ?>" <?php echo $required ? 'required' : ''; ?>>
                        <option value="">-- Select Occupation --</option>
                        <?php $hasSelectedOccupation = false; ?>
                        <?php foreach ($occupationOptions as $item): ?>
                            <?php
                                $occupationName = (string)($item['occupation_name'] ?? '');
                                if ($occupationName === '') {
                                    continue;
                                }
                                $isSelected = ((string)$value === $occupationName);
                                if ($isSelected) {
                                    $hasSelectedOccupation = true;
                                }
                                $meta = [];
                                $typeLabel = admin_employment_type_label((string)($item['employment_type'] ?? ''));
                                if ($typeLabel !== '') {
                                    $meta[] = $typeLabel;
                                }
                                $provinceName = trim((string)($item['province_name'] ?? ''));
                                if ($provinceName !== '') {
                                    $meta[] = $provinceName;
                                }
                                $optionLabel = $occupationName . ($meta !== [] ? (' (' . implode(' / ', $meta) . ')') : '');
                            ?>
                            <option value="<?php echo h($occupationName); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                <?php echo h($optionLabel); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (!$hasSelectedOccupation && trim((string)$value) !== ''): ?>
                            <option value="<?php echo h((string)$value); ?>" selected><?php echo h((string)$value . ' (existing value)'); ?></option>
                        <?php endif; ?>
                    </select>
                <?php endif; ?>
            <?php elseif (module_field_is_json_list($field)): ?>
                <?php
                    $listColumns = $field['list_columns'] ?? [];
                    if (!is_array($listColumns)) {
                        $listColumns = [];
                    }
                    $occupationNames = [];
                    foreach ($occupationOptions as $occupationItem) {
                        $occupationName = trim((string)($occupationItem['occupation_name'] ?? ''));
                        if ($occupationName !== '') {
                            $occupationNames[$occupationName] = true;
                        }
                    }
                    $occupationSelectOptions = array_keys($occupationNames);
                    sort($occupationSelectOptions, SORT_NATURAL | SORT_FLAG_CASE);
                    $carModelMap = module_active_car_model_map();
                    $carBrandOptions = array_keys($carModelMap);
                    sort($carBrandOptions, SORT_NATURAL | SORT_FLAG_CASE);
                    $carYearOptions = module_car_year_options();
                    foreach ($listColumns as &$column) {
                        if (!is_array($column)) {
                            continue;
                        }
                        $columnInputType = strtolower(trim((string)($column['input'] ?? '')));
                        $optionSource = strtolower(trim((string)($column['options_source'] ?? '')));
                        $hasLocalOptions = isset($column['options']) && is_array($column['options']) && $column['options'] !== [];
                        $hasLocalOptionsMap = isset($column['options_map']) && is_array($column['options_map']) && $column['options_map'] !== [];
                        if ($columnInputType === 'select' && $optionSource === 'occupation' && !$hasLocalOptions) {
                            $column['options'] = $occupationSelectOptions;
                        } elseif ($columnInputType === 'select' && $optionSource === 'car_brand' && !$hasLocalOptions) {
                            $column['options'] = $carBrandOptions;
                        } elseif ($columnInputType === 'select' && $optionSource === 'car_model_by_brand' && !$hasLocalOptionsMap) {
                            $column['options_map'] = $carModelMap;
                        } elseif ($columnInputType === 'select' && $optionSource === 'car_year' && !$hasLocalOptions) {
                            $column['options'] = $carYearOptions;
                        }
                    }
                    unset($column);
                    $buttonLabel = trim((string)($field['button_label'] ?? 'Add Item'));
                    if ($buttonLabel === '') {
                        $buttonLabel = 'Add Item';
                    }
                    $currentRows = [];
                    if (is_string($value) && trim($value) !== '') {
                        $decodedRows = json_decode((string)$value, true);
                        if (is_array($decodedRows)) {
                            $currentRows = $decodedRows;
                        }
                    } elseif (is_array($value)) {
                        $currentRows = $value;
                    }
                    $currentRowsJson = json_encode($currentRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($currentRowsJson === false) {
                        $currentRowsJson = '[]';
                    }
                    $columnsJson = json_encode($listColumns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($columnsJson === false) {
                        $columnsJson = '[]';
                    }
                ?>
                <div class="json-list-field js-json-list-field <?php echo $isInvalid ? 'border border-danger rounded p-2' : ''; ?>"
                    data-field-name="<?php echo h($name); ?>"
                    data-field-label="<?php echo h((string)$field['label']); ?>"
                    data-columns="<?php echo h($columnsJson); ?>"
                    data-required="<?php echo $required ? '1' : '0'; ?>"
                    data-max-items="<?php echo (int)($field['max_items'] ?? 0); ?>">
                    <input type="hidden" name="<?php echo h($name); ?>" value="<?php echo h($currentRowsJson); ?>" class="js-json-list-input">
                    <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary js-json-list-add"><?php echo h($buttonLabel); ?></button>
                        <small class="text-muted js-json-list-count">0 items</small>
                    </div>
                    <div class="table-responsive border rounded">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                            <tr>
                                <?php foreach ($listColumns as $column): ?>
                                    <th><?php echo h((string)($column['label'] ?? ($column['key'] ?? 'Column'))); ?></th>
                                <?php endforeach; ?>
                                <th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody class="js-json-list-body"></tbody>
                        </table>
                    </div>
                </div>
            <?php elseif ($type === 'textarea'): ?>
                <textarea class="form-control<?php echo $invalidClass; ?>" rows="2" name="<?php echo h($name); ?>" <?php echo $required ? 'required' : ''; ?>><?php echo h((string)$value); ?></textarea>
            <?php elseif ($type === 'select'): ?>
                <select class="form-select<?php echo $invalidClass; ?>" name="<?php echo h($name); ?>" <?php echo $required ? 'required' : ''; ?>>
                    <option value="">-- Select --</option>
                    <?php foreach (($field['options'] ?? []) as $option): ?>
                        <option value="<?php echo h((string)$option); ?>" <?php echo ((string)$value === (string)$option) ? 'selected' : ''; ?>><?php echo h(thai_option_label((string)$option)); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input class="form-control<?php echo $invalidClass; ?>"
                    type="<?php echo h($type === 'number' ? 'number' : ($type === 'date' ? 'date' : 'text')); ?>"
                    name="<?php echo h($name); ?>"
                    value="<?php echo h((string)$value); ?>"
                    <?php echo $required ? 'required' : ''; ?>
                    <?php echo $type === 'number' ? 'step="0.01"' : ''; ?>>
            <?php endif; ?>
            <?php if ($isInvalid && $messageText !== ''): ?>
                <div class="invalid-feedback d-block"><?php echo h($messageText); ?></div>
            <?php endif; ?>
        </div>
        <?php
    };
    ?>
    <section class="mb-4">
        <div class="card shadow-sm border-0 module-hero">
            <div class="card-body">
                <h2 class="h5 mb-1"><?php echo h($module['title']); ?></h2>
                <p class="text-muted mb-0"><?php echo h($module['description']); ?></p>
            </div>
        </div>
    </section>

    <section class="card shadow-sm border-0 mb-4 module-toolbar">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#entryModal">+ Add</button>
                <?php if ($isEdit): ?>
                    <span class="badge text-bg-primary">Editing ID <?php echo (int)$edit['id']; ?></span>
                <?php endif; ?>
            </div>
            <form method="get" class="module-search d-flex align-items-center gap-2">
                <?php if ($showDeleted): ?>
                    <input type="hidden" name="show_deleted" value="1">
                <?php endif; ?>
                <input class="form-control" name="q" value="<?php echo h($searchTerm); ?>" placeholder="<?php echo h($searchInputPlaceholder); ?>" <?php if ($searchDatalistId !== ""): ?>list="<?php echo h($searchDatalistId); ?>" autocomplete="off"<?php endif; ?>>
                <button class="btn btn-outline-primary" type="submit">Search</button>
                <?php if ($searchTerm !== ''): ?>
                    <a class="btn btn-outline-secondary" href="<?php echo h($selfPath . ($showDeleted ? '?show_deleted=1' : '')); ?>">Clear</a>
                <?php endif; ?>
            </form>
            <?php if ($canViewDeleted): ?>
                <?php
                    $toggleParams = [];
                    if ($searchTerm !== '') {
                        $toggleParams['q'] = $searchTerm;
                    }
                    if (!$showDeleted) {
                        $toggleParams['show_deleted'] = '1';
                    }
                    $toggleUrl = $selfPath;
                    if ($toggleParams !== []) {
                        $toggleUrl .= '?' . http_build_query($toggleParams);
                    }
                ?>
                <a class="btn btn-sm <?php echo $showDeleted ? 'btn-outline-danger' : 'btn-outline-secondary'; ?>" href="<?php echo h($toggleUrl); ?>">
                    <?php echo $showDeleted ? 'Hide Deleted' : 'Show Deleted'; ?>
                </a>
            <?php endif; ?>
            <?php if ($searchDatalistId !== "" && $searchAutocompleteOptions !== []): ?>
                <datalist id="<?php echo h($searchDatalistId); ?>">
                    <?php foreach ($searchAutocompleteOptions as $option): ?>
                        <option value="<?php echo h((string)$option); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            <?php endif; ?>
            <div class="small text-muted">Found <?php echo number_format(count($rows)); ?> records</div>
        </div>
    </section>

    <section class="row g-3 mb-4">
        <div class="col-md-3"><div class="stat-card"><span>Total</span><strong><?php echo (int)$summary['total_rows']; ?></strong></div></div>
        <div class="col-md-3"><div class="stat-card"><span>Pending Checker</span><strong><?php echo (int)$summary['pending_rows']; ?></strong></div></div>
        <div class="col-md-3"><div class="stat-card"><span>Approved</span><strong><?php echo (int)$summary['approved_rows']; ?></strong></div></div>
        <?php if ($canViewDeleted): ?>
            <div class="col-md-3"><div class="stat-card"><span>Deleted Rows</span><strong><?php echo (int)$summary['deleted_rows']; ?></strong></div></div>
        <?php endif; ?>
    </section>

    <section class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white">
            <h3 class="h6 mb-0">Latest Records (Editable/Deletable)</h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 js-module-datatable">
                    <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Record ID</th>
                        <th>Primary Ref</th>
                        <th>Primary Name</th>
                        <th>Status</th>
                        <th>Version</th>
                        <th>Last Updated</th>
                        <th>Last Updated By</th>
                        <th>Summary</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $status = (string)$row['record_status'];
                    ?>
                        <tr class="<?php echo ((int)$row['is_deleted'] === 1) ? 'table-danger' : ''; ?>">
                            <td><?php echo (int)$row['id']; ?></td>
                            <td><code><?php echo h((string)$row['record_uid']); ?></code></td>
                            <td><?php echo h((string)$row['primary_ref']); ?></td>
                            <td><?php echo h((string)$row['primary_name']); ?></td>
                            <td><span class="badge text-bg-<?php echo h(badge_class_for_status($status)); ?>"><?php echo h(thai_status_label($status)); ?></span></td>
                            <td><?php echo (int)$row['version_no']; ?></td>
                            <td><?php echo h((string)$row['updated_at']); ?></td>
                            <td><?php echo h((string)$row['updated_by']); ?><br><small class="text-muted"><?php echo h(thai_role_label((string)$row['updated_role'])); ?></small></td>
                            <td>
                                <?php $preview = array_slice($row['payload'], 0, 3, true); ?>
                                <?php foreach ($preview as $k => $v): ?>
                                    <small class="d-block"><?php echo h((string)$k); ?>: <?php echo h((string)$v); ?></small>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    <a class="btn btn-sm btn-outline-dark" href="<?php echo h(app_base_url('attitude_assessment.php') . '?module_key=' . rawurlencode((string)$module['key']) . '&source_id=' . (int)$row['id']); ?>">Repayment Attitude</a>
                                    <a class="btn btn-sm btn-outline-primary" href="?edit=<?php echo (int)$row['id']; ?><?php echo h($editSuffix); ?>">Edit</a>
                                    <?php if ($status !== 'APPROVED' && (int)$row['is_deleted'] === 0): ?>
                                        <form method="post">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="module_key" value="<?php echo h($module['key']); ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="source_id" value="<?php echo (int)$row['id']; ?>">
                                            <input type="hidden" name="reason" value="Approved by checker">
                                            <button class="btn btn-sm btn-outline-success" type="submit">Approve</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ((int)$row['is_deleted'] === 0): ?>
                                        <form method="post" class="needs-confirm-delete">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="module_key" value="<?php echo h($module['key']); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="source_id" value="<?php echo (int)$row['id']; ?>">
                                            <input type="hidden" name="reason" value="Soft deleted by user">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <div class="modal fade" id="entryModal" tabindex="-1" aria-labelledby="entryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-xl sf-resizable-modal">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h3 class="h6 mb-0" id="entryModalLabel"><?php echo $isEdit ? 'Edit Record (Create New Version)' : 'Add New Record'; ?></h3>
                <div class="d-flex align-items-center gap-2">
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary js-toggle-modal-fullscreen"
                        data-modal-target="#entryModal"
                        data-expand-label="Fullscreen"
                        data-collapse-label="Exit Fullscreen"
                        aria-pressed="false"
                    >
                        Fullscreen
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body">
<p class="text-muted small mb-3">3-block entry form, from top to bottom</p>
                <form method="post" class="validate-form" novalidate>
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="module_key" value="<?php echo h($module['key']); ?>">
                    <input type="hidden" name="action" value="<?php echo $isEdit ? 'update' : 'create'; ?>">
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="source_id" value="<?php echo (int)$edit['id']; ?>">
                    <?php endif; ?>
                    <?php if ($isCustomer360Module): ?>
                        <?php
                            $hiddenBranchCode = strtoupper(trim((string)($edit['branch_code'] ?? $branchInputValue)));
                            if ($hiddenBranchCode === '' && $branchInputReadonly) {
                                $hiddenBranchCode = strtoupper(trim((string)($scope['branch_code'] ?? '')));
                            }
                            $hiddenRecordStatus = (string)($edit['record_status'] ?? 'PENDING_CHECKER');
                            $hiddenCustomerRef = (string)($edit['customer_ref'] ?? '');
                        ?>
                        <input type="hidden" name="branch_code" value="<?php echo h($hiddenBranchCode); ?>">
                        <input type="hidden" name="record_status" value="<?php echo h($hiddenRecordStatus); ?>">
                        <input type="hidden" name="customer_ref_common" value="<?php echo h($hiddenCustomerRef); ?>">
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-12">
                            <div class="entry-block">
                                <div class="entry-block-title">Block 1</div>
                                <?php
                                    $renderedFieldNames = [];
                                    if ((string)($module['key'] ?? '') === 'customer_360') {
                                        $customerCodeField = null;
                                        foreach ($fieldColumns[0] as $candidateField) {
                                            if ((string)($candidateField['name'] ?? '') === 'customer_code') {
                                                $customerCodeField = $candidateField;
                                                break;
                                            }
                                        }
                                        if (is_array($customerCodeField)) {
                                            $renderField($customerCodeField, $payload['customer_code'] ?? '', 'mb-2');
                                            $renderedFieldNames['customer_code'] = true;
                                        }

                                        $inlineFieldWidthMap = [
                                            'title_name' => 'col-md-1 col-6 mb-2',
                                            'first_name' => 'col-md-2 col-6 mb-2',
                                            'last_name' => 'col-md-2 col-6 mb-2',
                                            'cid_tax_id' => 'col-md-3 col-12 mb-2',
                                            'phone_number' => 'col-md-2 col-6 mb-2',
                                            'email' => 'col-md-2 col-6 mb-2',
                                            'id_card_address' => 'col-12 mb-2',
                                        ];
                                        $spouseInlineFieldWidthMap = [
                                            'spouse_title_name' => 'col-md-1 col-6 mb-2',
                                            'spouse_first_name' => 'col-md-2 col-6 mb-2',
                                            'spouse_last_name' => 'col-md-2 col-6 mb-2',
                                            'spouse_cid_tax_id' => 'col-md-3 col-12 mb-2',
                                            'spouse_phone_number' => 'col-md-2 col-6 mb-2',
                                            'spouse_email' => 'col-md-2 col-6 mb-2',
                                            'spouse_id_card_address' => 'col-12 mb-2',
                                        ];
                                        $inlineFields = [];
                                        foreach ($fieldColumns[0] as $inlineField) {
                                            $inlineName = (string)($inlineField['name'] ?? '');
                                            if (isset($inlineFieldWidthMap[$inlineName]) || isset($spouseInlineFieldWidthMap[$inlineName])) {
                                                $inlineFields[$inlineName] = $inlineField;
                                            }
                                        }
                                        if ($inlineFields !== []) {
                                            echo '<div class="row g-2 mb-1">';
                                            foreach ($inlineFieldWidthMap as $inlineName => $inlineClass) {
                                                if (!isset($inlineFields[$inlineName])) {
                                                    continue;
                                                }
                                                $renderField($inlineFields[$inlineName], $payload[$inlineName] ?? '', $inlineClass);
                                                $renderedFieldNames[$inlineName] = true;
                                            }
                                            echo '</div>';

                                            $hasSpouseInline = false;
                                            foreach (array_keys($spouseInlineFieldWidthMap) as $spouseInlineName) {
                                                if (isset($inlineFields[$spouseInlineName])) {
                                                    $hasSpouseInline = true;
                                                    break;
                                                }
                                            }
                                            if ($hasSpouseInline) {
                                                echo '<div class="small fw-semibold mt-2 mb-1">Spouse Information</div>';
                                                echo '<div class="row g-2 mb-1">';
                                                foreach ($spouseInlineFieldWidthMap as $inlineName => $inlineClass) {
                                                    if (!isset($inlineFields[$inlineName])) {
                                                        continue;
                                                    }
                                                    $renderField($inlineFields[$inlineName], $payload[$inlineName] ?? '', $inlineClass);
                                                    $renderedFieldNames[$inlineName] = true;
                                                }
                                                echo '</div>';
                                            }
                                        }
                                    }
                                ?>
                                <?php foreach ($fieldColumns[0] as $field): ?>
                                    <?php $fieldName = (string)($field['name'] ?? ''); ?>
                                    <?php if (isset($renderedFieldNames[$fieldName])) { continue; } ?>
                                    <?php $renderField($field, $payload[$fieldName] ?? ''); ?>
                                <?php endforeach; ?>
                                <?php if ($showCommonBranchField): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Branch Code</label>
                                        <?php $branchDisplayValue = strtoupper(trim((string)$branchInputValue)); ?>
                                        <?php if ($branchDisplayValue === '' && $branchInputReadonly): ?>
                                            <?php $branchDisplayValue = strtoupper(trim((string)($scope['branch_code'] ?? ''))); ?>
                                        <?php endif; ?>
                                        <?php $hasSelectedCommonBranch = false; ?>
                                        <?php $commonBranchInvalidClass = isset($invalidFieldLookup['branch_code']) ? ' is-invalid' : ''; ?>
                                        <select class="form-select<?php echo $commonBranchInvalidClass; ?>" name="branch_code" <?php echo $branchInputReadonly ? 'disabled' : ''; ?> required>
                                            <option value="">-- Select Branch --</option>
                                            <?php foreach ($branchOptions as $branchItem): ?>
                                                <?php
                                                    $optionCode = strtoupper(trim((string)($branchItem['branch_code'] ?? '')));
                                                    if ($optionCode === '') {
                                                        continue;
                                                    }
                                                    $isSelected = $branchDisplayValue === $optionCode;
                                                    if ($isSelected) {
                                                        $hasSelectedCommonBranch = true;
                                                    }
                                                    $optionName = trim((string)($branchItem['branch_name'] ?? ''));
                                                    $optionRegion = trim((string)($branchItem['region_name'] ?? ''));
                                                    $optionLabel = $optionCode . ($optionName !== '' ? (' - ' . $optionName) : '') . ($optionRegion !== '' ? (' (' . $optionRegion . ')') : '');
                                                ?>
                                                <option value="<?php echo h($optionCode); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                                    <?php echo h($optionLabel); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <?php if (!$hasSelectedCommonBranch && $branchDisplayValue !== ''): ?>
                                                <option value="<?php echo h($branchDisplayValue); ?>" selected><?php echo h($branchDisplayValue . ' (existing value)'); ?></option>
                                            <?php elseif ($branchOptions === []): ?>
                                                <option value="" disabled>No branch data available</option>
                                            <?php endif; ?>
                                        </select>
                                        <?php if ($branchInputReadonly): ?>
                                            <input type="hidden" name="branch_code" value="<?php echo h($branchDisplayValue); ?>">
                                        <?php endif; ?>
                                        <?php if (isset($invalidFieldMessages['branch_code']) && $invalidFieldMessages['branch_code'] !== []): ?>
                                            <div class="invalid-feedback d-block"><?php echo h(implode(' | ', $invalidFieldMessages['branch_code'])); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($showCommonMetaFields): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Customer Reference (shared)</label>
                                        <input class="form-control" name="customer_ref_common" value="<?php echo h((string)($edit['customer_ref'] ?? '')); ?>">
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="entry-block">
                                <div class="entry-block-title">Block 2</div>
                                <?php foreach ($fieldColumns[1] as $field): ?>
                                    <?php $renderField($field, $payload[(string)$field['name']] ?? ''); ?>
                                <?php endforeach; ?>
                                <?php if ($showCommonMetaFields): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Risk Level (shared)</label>
                                        <input class="form-control" name="risk_level" value="<?php echo h((string)($edit['risk_level'] ?? '')); ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Amount (shared)</label>
                                        <input class="form-control" name="amount" type="number" step="0.01" value="<?php echo h((string)($edit['amount'] ?? '')); ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Event Date (shared)</label>
                                        <input class="form-control" name="event_date" type="date" value="<?php echo h((string)($edit['event_date'] ?? '')); ?>">
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="entry-block">
                                <div class="entry-block-title">Block 3</div>
                                <?php foreach ($fieldColumns[2] as $field): ?>
                                    <?php $renderField($field, $payload[(string)$field['name']] ?? ''); ?>
                                <?php endforeach; ?>
                                <?php if (!$hideCommonContextFields): ?>
                                <div class="mb-3">
                                    <label class="form-label">Consent Flag</label>
                                    <select class="form-select" name="consent_flag">
                                        <option value="0" <?php echo ((string)($edit['consent_flag'] ?? '0') === '0') ? 'selected' : ''; ?>>0 - Not Consented</option>
                                        <option value="1" <?php echo ((string)($edit['consent_flag'] ?? '0') === '1') ? 'selected' : ''; ?>>1 - Consented</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <?php if ($showCommonMetaFields): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Risk Flags (shared)</label>
                                        <input class="form-control" name="risk_flags_common" value="<?php echo h((string)($edit['risk_flags'] ?? '')); ?>">
                                    </div>
                                <?php endif; ?>
                                <?php if (!$isCustomer360Module): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Record Status</label>
                                        <select class="form-select" name="record_status" required>
                                            <?php $statusValue = (string)($edit['record_status'] ?? 'PENDING_CHECKER'); ?>
                                            <?php foreach (['DRAFT', 'PENDING_CHECKER', 'APPROVED', 'REJECTED'] as $status): ?>
                                                <option value="<?php echo h($status); ?>" <?php echo $statusValue === $status ? 'selected' : ''; ?>><?php echo h(thai_status_label($status)); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>
                                <div class="mb-3">
                                    <label class="form-label">Reason / Audit Note</label>
                                    <textarea class="form-control" name="reason" rows="3" placeholder="Reason for audit trail"><?php echo h((string)($edit['note_text'] ?? '')); ?></textarea>
                                </div>
                                <?php if (!$useBottomSubmitPanel): ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <button class="btn btn-brand" type="submit"><?php echo $isEdit ? 'Save New Version' : 'Save Record'; ?></button>
                                        <?php if ($isEdit): ?>
                                            <a class="btn btn-outline-secondary" href="<?php echo h($selfPath . $searchSuffix); ?>">Cancel Edit</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($showEmbeddedAttitudeSurvey && $embeddedAttitudeDimensions !== []): ?>
                        <div class="card shadow-sm border-0 mt-3" id="embeddedAttitudeSurvey">
                            <div class="card-header bg-white">
                                <h4 class="h6 mb-1">Debt Repayment Attitude Assessment (35 Items)</h4>
                                <small class="text-muted">Saved together with the new customer record and automatically linked by customer reference.</small>
                            </div>
                            <div class="card-body">
                                <?php foreach ($embeddedAttitudeDimensions as $dimCode => $dimension): ?>
                                    <div class="entry-block mb-3">
                                        <div class="entry-block-title"><?php echo h((string)($dimension['label'] ?? $dimCode)); ?></div>
                                        <?php foreach (($dimension['items'] ?? []) as $item): ?>
                                            <?php
                                                $questionCode = (string)($item['question_code'] ?? '');
                                                $questionText = (string)($item['question_text'] ?? '');
                                                $choiceMap = $item['choice_map'] ?? [];
                                                if (!is_array($choiceMap)) {
                                                    $choiceMap = [];
                                                }
                                            ?>
                                            <?php if ($questionCode !== ''): ?>
                                                <div class="mb-3 p-2 border rounded-2">
                                                    <div class="fw-semibold small mb-2"><?php echo h($questionCode . ' - ' . $questionText); ?></div>
                                                    <div class="row g-2">
                                                        <?php foreach ($choiceMap as $choiceValue => $choiceText): ?>
                                                            <?php $optionId = 'att_' . $questionCode . '_' . (string)$choiceValue; ?>
                                                            <div class="col-lg-6">
                                                                <label class="form-check" for="<?php echo h($optionId); ?>">
                                                                    <input
                                                                        class="form-check-input"
                                                                        type="radio"
                                                                        id="<?php echo h($optionId); ?>"
                                                                        name="<?php echo h($questionCode); ?>"
                                                                        value="<?php echo h((string)$choiceValue); ?>"
                                                                    >
                                                                    <span class="form-check-label"><?php echo h((string)$choiceValue . '. ' . (string)$choiceText); ?></span>
                                                                </label>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($useBottomSubmitPanel): ?>
                        <div class="d-flex flex-wrap gap-2 mt-3 pt-2 border-top">
                            <button class="btn btn-brand" type="submit">Save Record</button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($hasJsonListField): ?>
    <div class="modal fade" id="jsonListItemModal" tabindex="-1" aria-labelledby="jsonListItemModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-scrollable sf-resizable-modal">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <h3 class="h6 mb-0" id="jsonListItemModalLabel">Add Item</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="jsonListItemForm" class="validate-form" novalidate>
                    <div class="modal-body">
                        <div id="jsonListItemFields"></div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-brand" type="submit" id="jsonListItemSaveBtn">Save Record</button>
                        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($isEdit || $hasValidationErrors): ?>
    <script>
        window.smartFinanceOpenEntryModal = true;
    </script>
<?php endif; ?>
<?php
}






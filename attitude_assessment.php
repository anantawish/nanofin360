<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/attitude_assessment.php';

$pdo = db();
attitude_bootstrap($pdo, current_user_name() !== '' ? current_user_name() : 'system_seed');

$moduleKey = trim((string)($_GET['module_key'] ?? $_POST['module_key'] ?? ''));
$sourceId = (int)($_GET['source_id'] ?? $_POST['source_id'] ?? 0);

if ($moduleKey === '' || $sourceId <= 0) {
    add_flash('danger', 'Reference loan record not found.');
    redirect_to(app_base_url('index.php'));
}

try {
    $module = module_by_key($moduleKey);
    $source = module_find_latest_by_id($moduleKey, $sourceId);
    if ($source === null) {
        throw new RuntimeException('Reference record not found or access is denied.');
    }
} catch (Throwable $e) {
    add_flash('danger', 'Unable to open questionnaire: ' . $e->getMessage());
    redirect_to(app_base_url('index.php'));
}

$questionSet = attitude_fetch_latest_question_set($pdo);
$questionItems = attitude_fetch_question_items($pdo, (int)$questionSet['id']);

$formValues = [
    'contract_no' => attitude_default_contract_no($source),
    'applicant_name' => attitude_default_applicant_name($source),
    'applicant_gender' => 'unknown',
    'applicant_age' => '',
];
foreach ($questionItems as $item) {
    $code = (string)($item['question_code'] ?? '');
    if ($code !== '') {
        $formValues[$code] = '';
    }
}

$selectedAssessmentId = (int)($_GET['assessment_id'] ?? $_POST['assessment_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'submit_assessment') {
            $formValues['contract_no'] = trim((string)($_POST['contract_no'] ?? ''));
            $formValues['applicant_name'] = trim((string)($_POST['applicant_name'] ?? ''));
            $formValues['applicant_gender'] = trim((string)($_POST['applicant_gender'] ?? 'unknown'));
            $formValues['applicant_age'] = trim((string)($_POST['applicant_age'] ?? ''));
            foreach ($questionItems as $item) {
                $code = (string)($item['question_code'] ?? '');
                if ($code !== '') {
                    $formValues[$code] = trim((string)($_POST[$code] ?? ''));
                }
            }

            $meta = attitude_collect_meta_from_input($_POST, $source);
            $answersByCode = attitude_collect_answers_from_input($questionItems, $_POST);
            $scoreResult = attitude_calculate_scores($questionItems, $answersByCode);

            $saved = attitude_persist_assessment(
                $pdo,
                $module,
                $source,
                $questionSet,
                $questionItems,
                $meta,
                $answersByCode,
                $scoreResult,
                'CREATE',
                null
            );

            add_flash(
                'success',
                'Assessment saved successfully (Answer Set: ' . (string)$saved['answer_set_ref'] . ', Total Score: ' . number_format((float)$scoreResult['overall_index'], 2) . ').'
            );

            $target = app_base_url('attitude_assessment.php')
                . '?module_key=' . rawurlencode($moduleKey)
                . '&source_id=' . (int)$sourceId
                . '&assessment_id=' . (int)$saved['id'];
            redirect_to($target);
        }

        if ($action === 'recalculate_assessment') {
            $baseAssessmentId = (int)($_POST['assessment_id'] ?? 0);
            if ($baseAssessmentId <= 0) {
                throw new RuntimeException('Assessment to recalculate was not found.');
            }

            $baseAssessment = attitude_find_latest_by_id($pdo, $baseAssessmentId);
            if ($baseAssessment === null) {
                throw new RuntimeException('Assessment to recalculate was not found or access is denied.');
            }
            if ((string)$baseAssessment['module_key'] !== $moduleKey || (int)$baseAssessment['workflow_source_id'] !== $sourceId) {
                throw new RuntimeException('Selected assessment does not match the current loan record.');
            }

            $questionSetForRun = attitude_fetch_question_set_by_id($pdo, (int)$baseAssessment['question_set_id']);
            if ($questionSetForRun === null) {
                $questionSetForRun = $questionSet;
            }
            $questionItemsForRun = attitude_fetch_question_items($pdo, (int)$questionSetForRun['id']);

            $answerRows = attitude_fetch_answer_rows($pdo, (string)$baseAssessment['assessment_uid'], (int)$baseAssessment['version_no']);
            $answersByCode = attitude_answer_map_from_rows($answerRows);
            if ($answersByCode === []) {
                $fallbackAnswers = $baseAssessment['answers'] ?? [];
                if (is_array($fallbackAnswers)) {
                    foreach ($fallbackAnswers as $code => $value) {
                        $answersByCode[(string)$code] = (int)$value;
                    }
                }
            }

            if ($answersByCode === []) {
                throw new RuntimeException('Original answers were not found for recalculation.');
            }

            $meta = [
                'contract_no' => (string)($baseAssessment['contract_no'] ?? ''),
                'applicant_name' => (string)($baseAssessment['applicant_name'] ?? ''),
                'applicant_gender' => (string)($baseAssessment['applicant_gender'] ?? 'unknown'),
                'applicant_age' => isset($baseAssessment['applicant_age']) && $baseAssessment['applicant_age'] !== '' ? (int)$baseAssessment['applicant_age'] : null,
            ];
            if ($meta['contract_no'] === '') {
                $meta['contract_no'] = attitude_default_contract_no($source);
            }

            $scoreResult = attitude_calculate_scores($questionItemsForRun, $answersByCode);

            $saved = attitude_persist_assessment(
                $pdo,
                $module,
                $source,
                $questionSetForRun,
                $questionItemsForRun,
                $meta,
                $answersByCode,
                $scoreResult,
                'RECALCULATE',
                $baseAssessment
            );

            add_flash(
                'success',
                'Recalculated successfully (New Answer Set: ' . (string)$saved['answer_set_ref'] . ', Total Score: ' . number_format((float)$scoreResult['overall_index'], 2) . ').'
            );

            $target = app_base_url('attitude_assessment.php')
                . '?module_key=' . rawurlencode($moduleKey)
                . '&source_id=' . (int)$sourceId
                . '&assessment_id=' . (int)$saved['id'];
            redirect_to($target);
        }

        throw new RuntimeException('The sent command is not recognized.');
    } catch (Throwable $e) {
        add_flash('danger', 'Failed to save: ' . $e->getMessage());
    }
}

$assessmentRows = attitude_list_latest_for_source($pdo, $moduleKey, $sourceId);
$selectedAssessment = null;
if ($selectedAssessmentId > 0) {
    $selectedAssessment = attitude_find_latest_by_id($pdo, $selectedAssessmentId);
    if ($selectedAssessment !== null) {
        if ((string)$selectedAssessment['module_key'] !== $moduleKey || (int)$selectedAssessment['workflow_source_id'] !== $sourceId) {
            $selectedAssessment = null;
        }
    }
}
if ($selectedAssessment === null && $assessmentRows !== []) {
    $selectedAssessment = $assessmentRows[0];
}

$selectedDimensionRows = [];
$selectedAnswerRows = [];
if ($selectedAssessment !== null) {
    $selectedDimensionRows = attitude_fetch_dimension_rows(
        $pdo,
        (string)$selectedAssessment['assessment_uid'],
        (int)$selectedAssessment['version_no']
    );
    $selectedAnswerRows = attitude_fetch_answer_rows(
        $pdo,
        (string)$selectedAssessment['assessment_uid'],
        (int)$selectedAssessment['version_no']
    );
}

$dimensionGroups = [];
foreach ($questionItems as $item) {
    $dim = (string)($item['dimension_code'] ?? '');
    if ($dim === '') {
        continue;
    }
    if (!isset($dimensionGroups[$dim])) {
        $dimensionGroups[$dim] = [
            'label' => (string)($item['dimension_label'] ?? $dim),
            'items' => [],
        ];
    }
    $dimensionGroups[$dim]['items'][] = $item;
}

$backToModuleUrl = app_base_url('modules/' . (string)$module['file']) . '?edit=' . (int)$sourceId;
$pageTitle = 'Repayment Attitude Assessment';
$currentModule = '';

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/menu.php';
?>
<section class="mb-4">
    <div class="card shadow-sm border-0 module-hero">
        <div class="card-body">
            <h1 class="h5 mb-1">Debt Repayment Attitude Score (35 Items)</h1>
            <p class="text-muted mb-0">
                Save complete answers and Bayesian scoring results, linked to the applicant and contract for repeatable reassessment.
            </p>
        </div>
    </div>
</section>

<section class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h2 class="h6 mb-0">Reference Loan Record</h2>
        <a class="btn btn-sm btn-outline-secondary" href="<?php echo h($backToModuleUrl); ?>">Back to Module Record</a>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3"><div class="stat-card"><span>Module</span><strong><?php echo h((string)$module['title']); ?></strong></div></div>
            <div class="col-md-3"><div class="stat-card"><span>Record ID</span><strong><?php echo h((string)$source['record_uid']); ?></strong></div></div>
            <div class="col-md-3"><div class="stat-card"><span>Primary Reference</span><strong><?php echo h((string)$source['primary_ref']); ?></strong></div></div>
            <div class="col-md-3"><div class="stat-card"><span>Primary Name</span><strong><?php echo h((string)$source['primary_name']); ?></strong></div></div>
        </div>
    </div>
</section>

<section class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Create New Assessment</h2>
    </div>
    <div class="card-body">
        <form method="post" class="validate-form" novalidate>
            <?php echo csrf_input(); ?>
            <input type="hidden" name="module_key" value="<?php echo h($moduleKey); ?>">
            <input type="hidden" name="source_id" value="<?php echo (int)$sourceId; ?>">
            <input type="hidden" name="action" value="submit_assessment">

            <div class="row g-3 mb-3">
                <div class="col-lg-3 col-md-6">
                    <label class="form-label">Contract No. *</label>
                    <input class="form-control" name="contract_no" required value="<?php echo h((string)($formValues['contract_no'] ?? '')); ?>">
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label">Respondent / Applicant Name</label>
                    <input class="form-control" name="applicant_name" value="<?php echo h((string)($formValues['applicant_name'] ?? '')); ?>">
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label">Gender</label>
                    <?php $genderValue = (string)($formValues['applicant_gender'] ?? 'unknown'); ?>
                    <select class="form-select" name="applicant_gender">
                        <option value="unknown" <?php echo $genderValue === 'unknown' ? 'selected' : ''; ?>>Not specified</option>
                        <option value="male" <?php echo $genderValue === 'male' ? 'selected' : ''; ?>>Male</option>
                        <option value="female" <?php echo $genderValue === 'female' ? 'selected' : ''; ?>>Female</option>
                        <option value="other" <?php echo $genderValue === 'other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label">Age (years)</label>
                    <input class="form-control" type="number" min="0" max="120" name="applicant_age" value="<?php echo h((string)($formValues['applicant_age'] ?? '')); ?>">
                </div>
            </div>

            <?php foreach ($dimensionGroups as $dimCode => $group): ?>
                <div class="entry-block mb-3">
                    <div class="entry-block-title"><?php echo h((string)$group['label']); ?> (<?php echo h((string)$dimCode); ?>)</div>
                    <div class="row g-3">
                        <?php foreach ($group['items'] as $item): ?>
                            <?php
                                $questionCode = (string)$item['question_code'];
                                $questionNo = (int)$item['question_no'];
                                $selectedValue = (string)($formValues[$questionCode] ?? '');
                                $choiceMap = $item['choice_map'] ?? [];
                                if (!is_array($choiceMap)) {
                                    $choiceMap = [];
                                }
                            ?>
                            <div class="col-xl-6">
                                <label class="form-label">
                                    <?php echo h('Question ' . $questionNo . ' (' . $questionCode . ')'); ?> *
                                </label>
                                <div class="small text-muted mb-1"><?php echo h((string)$item['question_text']); ?></div>
                                <select class="form-select" name="<?php echo h($questionCode); ?>" required>
                                    <option value="">-- Select score (1-5) --</option>
                                    <?php foreach ($choiceMap as $value => $label): ?>
                                        <option value="<?php echo h((string)$value); ?>" <?php echo $selectedValue === (string)$value ? 'selected' : ''; ?>>
                                            <?php echo h((string)$value . ' - ' . (string)$label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="d-flex justify-content-end">
                <button class="btn btn-brand" type="submit">Save Answers &amp; Calculate Score</button>
            </div>
        </form>
    </div>
</section>

<section class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Assessment History (Latest Version per Set)</h2>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 js-admin-datatable">
                <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Assessment UID</th>
                    <th>Answer Set</th>
                    <th>Contract No.</th>
                    <th>Total score</th>
                    <th>Level</th>
                    <th>Version</th>
                    <th>Last Updated</th>
                    <th>Recorded By</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($assessmentRows as $row): ?>
                    <tr>
                        <td><?php echo (int)$row['id']; ?></td>
                        <td><code><?php echo h((string)$row['assessment_uid']); ?></code></td>
                        <td><code><?php echo h((string)$row['answer_set_ref']); ?></code></td>
                        <td><?php echo h((string)$row['contract_no']); ?></td>
                        <td><?php echo number_format((float)$row['overall_index'], 2); ?></td>
                        <td><?php echo h(attitude_class_label_th((string)$row['overall_class'])); ?></td>
                        <td><?php echo (int)$row['version_no']; ?></td>
                        <td><?php echo h((string)$row['updated_at']); ?></td>
                        <td><?php echo h((string)$row['updated_by']); ?></td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <a class="btn btn-sm btn-outline-primary"
                                   href="<?php echo h(app_base_url('attitude_assessment.php') . '?module_key=' . rawurlencode($moduleKey) . '&source_id=' . (int)$sourceId . '&assessment_id=' . (int)$row['id']); ?>">
                                    View Results
                                </a>
                                <form method="post">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="module_key" value="<?php echo h($moduleKey); ?>">
                                    <input type="hidden" name="source_id" value="<?php echo (int)$sourceId; ?>">
                                    <input type="hidden" name="action" value="recalculate_assessment">
                                    <input type="hidden" name="assessment_id" value="<?php echo (int)$row['id']; ?>">
                                    <button class="btn btn-sm btn-outline-success" type="submit">Recalculate</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php if ($selectedAssessment !== null): ?>
<section class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Selected Assessment</h2>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-lg-3 col-md-6"><div class="stat-card"><span>Answer Set</span><strong><?php echo h((string)$selectedAssessment['answer_set_ref']); ?></strong></div></div>
            <div class="col-lg-3 col-md-6"><div class="stat-card"><span>Total score</span><strong><?php echo number_format((float)$selectedAssessment['overall_index'], 2); ?></strong></div></div>
            <div class="col-lg-3 col-md-6"><div class="stat-card"><span>Level</span><strong><?php echo h(attitude_class_label_th((string)$selectedAssessment['overall_class'])); ?></strong></div></div>
            <div class="col-lg-3 col-md-6"><div class="stat-card"><span>Contract No.</span><strong><?php echo h((string)$selectedAssessment['contract_no']); ?></strong></div></div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-4"><small class="text-muted d-block">Low</small><strong><?php echo number_format(((float)$selectedAssessment['posterior_low']) * 100, 2); ?>%</strong></div>
            <div class="col-md-4"><small class="text-muted d-block">Mid</small><strong><?php echo number_format(((float)$selectedAssessment['posterior_mid']) * 100, 2); ?>%</strong></div>
            <div class="col-md-4"><small class="text-muted d-block">High</small><strong><?php echo number_format(((float)$selectedAssessment['posterior_high']) * 100, 2); ?>%</strong></div>
        </div>

        <div class="table-responsive mb-3">
            <table class="table table-sm table-bordered align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Dimension</th>
                    <th>Raw</th>
                    <th>Main</th>
                    <th>Spillover</th>
                    <th>Adjusted</th>
                    <th>Class</th>
                    <th>Low%</th>
                    <th>Mid%</th>
                    <th>High%</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($selectedDimensionRows as $row): ?>
                    <tr>
                        <td><?php echo h((string)$row['dimension_label']); ?></td>
                        <td><?php echo number_format((float)$row['raw_score'], 2); ?></td>
                        <td><?php echo number_format((float)$row['main_score'], 2); ?></td>
                        <td><?php echo number_format((float)$row['spillover_score'], 2); ?></td>
                        <td><?php echo number_format((float)$row['adjusted_score'], 2); ?></td>
                        <td><?php echo h(attitude_class_label_th((string)$row['class_label'])); ?></td>
                        <td><?php echo number_format(((float)$row['posterior_low']) * 100, 2); ?></td>
                        <td><?php echo number_format(((float)$row['posterior_mid']) * 100, 2); ?></td>
                        <td><?php echo number_format(((float)$row['posterior_high']) * 100, 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Question</th>
                    <th>Dimension</th>
                    <th>Question Text</th>
                    <th>Answer</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($selectedAnswerRows as $row): ?>
                    <tr>
                        <td><?php echo h((string)$row['question_code']); ?></td>
                        <td><?php echo h((string)$row['dimension_code']); ?></td>
                        <td><?php echo h((string)$row['question_text']); ?></td>
                        <td>
                            <?php echo (int)$row['answer_value']; ?>
                            <?php if (trim((string)$row['answer_text']) !== ''): ?>
                                - <?php echo h((string)$row['answer_text']); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>

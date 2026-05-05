<?php
declare(strict_types=1);

/**
 * Financial responsibility survey (35 questions, 7 dimensions)
 * Store question set + all answers per applicant for future recalculation.
 */

function attitude_question_set_code(): string
{
    return 'ATT35TH';
}

function attitude_question_set_version(): int
{
    return 2;
}

function attitude_model_version(): string
{
    return 'ATT35-BAYES-v2';
}

/**
 * @return array<string,string>
 */
function attitude_dimension_labels(): array
{
    return [
        'payment_discipline' => 'Payment Discipline',
        'future_planning' => 'Future Planning',
        'impulse_control' => 'Impulse Control',
        'duty_honesty' => 'Duty and Honesty',
        'debt_management' => 'Backlog and Debt Management',
        'reserve_saving' => 'Reserve and Buffer Planning',
        'duty_attitude' => 'Responsibility Mindset',
    ];
}

/**
 * @return array<string,array<int,string>>
 */
function attitude_choice_sets(): array
{
    return [
        'payment_discipline' => [
            1 => 'I leave it to my mood or situation at that moment and fix it later if missed.',
            2 => 'I wait until near the deadline and hope it is still on time.',
            3 => 'I meet the minimum commitment but rarely keep buffer for disruptions.',
            4 => 'I schedule it clearly and complete it a little before due time.',
            5 => 'I complete it in advance, double-check, and reserve time for unexpected issues.',
        ],
        'future_planning' => [
            1 => 'I do not plan ahead and believe I can solve it later when needed.',
            2 => 'I prepare only when I remember, one item at a time.',
            3 => 'I keep a rough plan but rarely prepare backup options.',
            4 => 'I plan timeline, resources, and risk points in advance.',
            5 => 'I plan in steps, keep alternatives, and check failure points before acting.',
        ],
        'impulse_control' => [
            1 => 'I choose what feels exciting or fun right away.',
            2 => 'I know I should pause, but often follow my emotions.',
            3 => 'I can control myself only about halfway.',
            4 => 'I pause before deciding and can trade short-term fun for priorities.',
            5 => 'I remove temptations, set limits, and follow long-term goals clearly.',
        ],
        'duty_honesty' => [
            1 => 'I hide or avoid the issue first.',
            2 => 'I wait for someone to ask before I explain.',
            3 => 'I share only the minimum required details.',
            4 => 'I state facts directly and propose a fix.',
            5 => 'I take full ownership, tell the truth quickly, and fix before being chased.',
        ],
        'debt_management' => [
            1 => 'I avoid it because I do not want to see pending items.',
            2 => 'I solve one by one based only on immediate pressure.',
            3 => 'I do easy tasks first and difficult tasks later.',
            4 => 'I prioritize and clear older pending items before they pile up.',
            5 => 'I break backlog into parts, set deadlines, and track until fully closed.',
        ],
        'reserve_saving' => [
            1 => 'I prepare only what is minimally necessary.',
            2 => 'I sometimes keep a small reserve, but not consistently.',
            3 => 'I keep moderate spare time or resources.',
            4 => 'I prepare backup for likely disruptions.',
            5 => 'I keep clear buffers for time, tools, and emergency alternatives.',
        ],
        'duty_attitude' => [
            1 => 'If nobody checks, I do not need to do much.',
            2 => 'I act only when there is external pressure.',
            3 => 'I do only what is minimally required by role.',
            4 => 'I view responsibility as my personal credibility.',
            5 => 'I view responsibility as my personal standard, even without supervision.',
        ],
    ];
}

/**
 * @return array<string,array{dim:string,text:string}>
 */
function attitude_questions(): array
{
    return [
        'Q01' => ['dim' => 'payment_discipline', 'text' => 'The night before returning a borrowed team jersey, there is a late match you really want to watch. How do you usually handle this?'],
        'Q02' => ['dim' => 'payment_discipline', 'text' => 'Your team expects image files before noon tomorrow, but tonight there is a live event you want to follow.'],
        'Q03' => ['dim' => 'payment_discipline', 'text' => 'You borrowed a tripod and the owner needs it the next day.'],
        'Q04' => ['dim' => 'payment_discipline', 'text' => 'You plan to wake up early for training, but there is an important series or match tonight.'],
        'Q05' => ['dim' => 'payment_discipline', 'text' => 'You promised to help a community activity in the morning, but you may sleep late tonight.'],

        'Q06' => ['dim' => 'future_planning', 'text' => 'A friend invites you to a long-distance out-of-town event in two months.'],
        'Q07' => ['dim' => 'future_planning', 'text' => 'You are assigned to present in front of a large audience in three weeks.'],
        'Q08' => ['dim' => 'future_planning', 'text' => 'A critical online meeting is next week, but your area often has unstable internet during rain.'],
        'Q09' => ['dim' => 'future_planning', 'text' => 'You want to join a running event that opens registration early and fills quickly.'],
        'Q10' => ['dim' => 'future_planning', 'text' => 'Your access card for work or school will expire in one month.'],

        'Q11' => ['dim' => 'impulse_control', 'text' => 'Friends invite you to continue watching football until almost morning, while you have an important appointment the next day.'],
        'Q12' => ['dim' => 'impulse_control', 'text' => 'A new limited-edition item is launched and people around you are excited and pushing for a quick decision.'],
        'Q13' => ['dim' => 'impulse_control', 'text' => 'A game or app you use has a limited-time event that makes you want to decide immediately.'],
        'Q14' => ['dim' => 'impulse_control', 'text' => 'Someone invites you to an activity and says you must decide now or lose the chance.'],
        'Q15' => ['dim' => 'impulse_control', 'text' => 'A party is very enjoyable, but tomorrow you have a task that requires full concentration.'],

        'Q16' => ['dim' => 'duty_honesty', 'text' => 'You made an error in a team file, and nobody knows yet.'],
        'Q17' => ['dim' => 'duty_honesty', 'text' => 'You promised a relative to help with an errand, but on that day you no longer feel like going.'],
        'Q18' => ['dim' => 'duty_honesty', 'text' => 'You borrowed shared equipment or books and accidentally caused a small defect.'],
        'Q19' => ['dim' => 'duty_honesty', 'text' => 'Your supervisor assumes one step is complete, but it is actually still pending.'],
        'Q20' => ['dim' => 'duty_honesty', 'text' => 'You find a shortcut that helps your team, but it feels against the spirit of the rules.'],

        'Q21' => ['dim' => 'debt_management', 'text' => 'You have three pending tasks from three groups at the same time.'],
        'Q22' => ['dim' => 'debt_management', 'text' => 'You have many pending messages and emails and start to avoid opening them.'],
        'Q23' => ['dim' => 'debt_management', 'text' => 'You borrowed items from several people around the same period, and return dates begin to overlap.'],
        'Q24' => ['dim' => 'debt_management', 'text' => 'Tasks have been postponed for weeks and now become one large backlog.'],
        'Q25' => ['dim' => 'debt_management', 'text' => 'Multiple deadlines collide within this week.'],

        'Q26' => ['dim' => 'reserve_saving', 'text' => 'You are going on a full-day trip and are unsure about weather and travel timing.'],
        'Q27' => ['dim' => 'reserve_saving', 'text' => 'You have a full day of online meetings from outside your usual location.'],
        'Q28' => ['dim' => 'reserve_saving', 'text' => 'Your area sometimes has heavy rain, traffic, or power outages.'],
        'Q29' => ['dim' => 'reserve_saving', 'text' => 'You need to run multiple errands at different locations in one day.'],
        'Q30' => ['dim' => 'reserve_saving', 'text' => 'You organize a small group activity and know some participants may be late or change plans.'],

        'Q31' => ['dim' => 'duty_attitude', 'text' => 'A teammate says: Just do enough to pass, nobody checks details.'],
        'Q32' => ['dim' => 'duty_attitude', 'text' => 'You are assigned a small part of work that rarely gets reviewed.'],
        'Q33' => ['dim' => 'duty_attitude', 'text' => 'After an activity ends, there is still detail cleanup that few people notice.'],
        'Q34' => ['dim' => 'duty_attitude', 'text' => 'One day nobody checks your check-in and check-out times.'],
        'Q35' => ['dim' => 'duty_attitude', 'text' => 'The work is finished, but nobody forces a lesson summary or documentation.'],
    ];
}

/**
 * @return array<string,array<string,float>>
 */
function attitude_spill_matrix(): array
{
    return [
        'payment_discipline' => ['future_planning' => 0.10, 'duty_attitude' => 0.10],
        'future_planning' => ['reserve_saving' => 0.10, 'impulse_control' => 0.10],
        'impulse_control' => ['future_planning' => 0.05, 'reserve_saving' => 0.05],
        'duty_honesty' => ['duty_attitude' => 0.10, 'payment_discipline' => 0.05],
        'debt_management' => ['payment_discipline' => 0.10, 'reserve_saving' => 0.10],
        'reserve_saving' => ['future_planning' => 0.10, 'impulse_control' => 0.10],
        'duty_attitude' => ['duty_honesty' => 0.10, 'future_planning' => 0.05],
    ];
}

/**
 * @return array<string,mixed>
 */
function attitude_question_set_payload(): array
{
    return [
        'set_code' => attitude_question_set_code(),
        'set_version' => attitude_question_set_version(),
        'set_name' => 'Debt Repayment Attitude Assessment (35 Questions)',
        'model_version' => attitude_model_version(),
        'dimension_labels' => attitude_dimension_labels(),
        'choice_sets' => attitude_choice_sets(),
        'questions' => attitude_questions(),
        'spill_matrix' => attitude_spill_matrix(),
        'answer_scale' => [1, 2, 3, 4, 5],
    ];
}
function attitude_ensure_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attitude_question_sets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            set_code VARCHAR(80) NOT NULL,
            set_version INT UNSIGNED NOT NULL DEFAULT 1,
            set_name VARCHAR(255) NOT NULL,
            model_version VARCHAR(80) NOT NULL,
            question_count INT UNSIGNED NOT NULL DEFAULT 0,
            dimension_count INT UNSIGNED NOT NULL DEFAULT 0,
            payload_json LONGTEXT NULL,
            is_latest TINYINT(1) NOT NULL DEFAULT 1,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT '',
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_attitude_set_version (set_code, set_version),
            KEY idx_attitude_set_latest (set_code, is_latest, is_deleted)
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attitude_question_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            set_id BIGINT UNSIGNED NOT NULL,
            question_code VARCHAR(20) NOT NULL,
            question_no INT UNSIGNED NOT NULL,
            dimension_code VARCHAR(80) NOT NULL,
            dimension_label VARCHAR(200) NOT NULL,
            question_text TEXT NOT NULL,
            choice_map_json LONGTEXT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_by VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_attitude_question_item (set_id, question_code),
            KEY idx_attitude_question_set (set_id, question_no)
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attitude_assessments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            assessment_uid VARCHAR(80) NOT NULL,
            version_no INT UNSIGNED NOT NULL DEFAULT 1,
            is_latest TINYINT(1) NOT NULL DEFAULT 1,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            module_key VARCHAR(80) NOT NULL,
            workflow_source_id BIGINT UNSIGNED NOT NULL,
            workflow_record_uid VARCHAR(80) NOT NULL,
            workflow_record_version INT UNSIGNED NOT NULL DEFAULT 1,
            branch_code VARCHAR(60) DEFAULT '',
            source_primary_ref VARCHAR(120) DEFAULT '',
            source_primary_name VARCHAR(255) DEFAULT '',
            contract_no VARCHAR(120) DEFAULT '',
            applicant_name VARCHAR(255) DEFAULT '',
            applicant_gender VARCHAR(20) DEFAULT 'unknown',
            applicant_age TINYINT UNSIGNED NULL,
            question_set_id BIGINT UNSIGNED NOT NULL,
            question_set_code VARCHAR(80) NOT NULL,
            question_set_version INT UNSIGNED NOT NULL DEFAULT 1,
            question_set_snapshot_json LONGTEXT NULL,
            answer_set_ref VARCHAR(120) DEFAULT '',
            answers_json LONGTEXT NULL,
            overall_index DECIMAL(6,2) NOT NULL DEFAULT 0,
            overall_class VARCHAR(20) NOT NULL DEFAULT 'mid',
            posterior_low DECIMAL(10,6) NOT NULL DEFAULT 0,
            posterior_mid DECIMAL(10,6) NOT NULL DEFAULT 0,
            posterior_high DECIMAL(10,6) NOT NULL DEFAULT 0,
            result_json LONGTEXT NULL,
            created_by VARCHAR(100) NOT NULL,
            created_role VARCHAR(50) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_ip VARCHAR(45) DEFAULT '',
            created_device VARCHAR(120) DEFAULT '',
            updated_by VARCHAR(100) DEFAULT '',
            updated_role VARCHAR(50) DEFAULT '',
            updated_at DATETIME NULL,
            updated_ip VARCHAR(45) DEFAULT '',
            updated_device VARCHAR(120) DEFAULT '',
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_attitude_assessment_version (assessment_uid, version_no),
            KEY idx_attitude_assessment_source (module_key, workflow_source_id, is_latest, is_deleted),
            KEY idx_attitude_assessment_branch (branch_code, is_latest, is_deleted),
            KEY idx_attitude_assessment_contract (contract_no)
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attitude_assessment_answers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            assessment_uid VARCHAR(80) NOT NULL,
            version_no INT UNSIGNED NOT NULL DEFAULT 1,
            question_set_id BIGINT UNSIGNED NOT NULL,
            question_set_item_id BIGINT UNSIGNED NOT NULL,
            question_code VARCHAR(20) NOT NULL,
            question_no INT UNSIGNED NOT NULL,
            dimension_code VARCHAR(80) NOT NULL,
            question_text TEXT NOT NULL,
            answer_value TINYINT UNSIGNED NOT NULL,
            answer_text TEXT NULL,
            created_by VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_attitude_answers_assessment (assessment_uid, version_no),
            KEY idx_attitude_answers_question (question_code)
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attitude_assessment_dimensions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            assessment_uid VARCHAR(80) NOT NULL,
            version_no INT UNSIGNED NOT NULL DEFAULT 1,
            dimension_code VARCHAR(80) NOT NULL,
            dimension_label VARCHAR(200) NOT NULL,
            raw_score DECIMAL(6,2) NOT NULL,
            main_score DECIMAL(6,2) NOT NULL,
            spillover_score DECIMAL(6,2) NOT NULL,
            adjusted_score DECIMAL(6,2) NOT NULL,
            posterior_low DECIMAL(10,6) NOT NULL,
            posterior_mid DECIMAL(10,6) NOT NULL,
            posterior_high DECIMAL(10,6) NOT NULL,
            class_label VARCHAR(20) NOT NULL,
            created_by VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_attitude_dimensions_assessment (assessment_uid, version_no),
            KEY idx_attitude_dimensions_code (dimension_code)
        )"
    );
}

function attitude_seed_default_question_set(PDO $pdo, string $actor = 'system_seed'): int
{
    $setCode = attitude_question_set_code();
    $setVersion = attitude_question_set_version();

    $stmtExisting = $pdo->prepare(
        'SELECT id
         FROM attitude_question_sets
         WHERE set_code = :set_code AND set_version = :set_version
         LIMIT 1'
    );
    $stmtExisting->execute([
        ':set_code' => $setCode,
        ':set_version' => $setVersion,
    ]);
    $existing = $stmtExisting->fetch();
    if ($existing) {
        return (int)$existing['id'];
    }

    $payload = attitude_question_set_payload();
    $questions = attitude_questions();
    $dimensions = attitude_dimension_labels();
    $choiceSets = attitude_choice_sets();

    $now = now_dt();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE attitude_question_sets SET is_latest = 0 WHERE set_code = :set_code')->execute([
            ':set_code' => $setCode,
        ]);

        $stmtSet = $pdo->prepare(
            'INSERT INTO attitude_question_sets (
                set_code, set_version, set_name, model_version, question_count, dimension_count,
                payload_json, is_latest, is_deleted,
                created_by, created_at, updated_by, updated_at
            ) VALUES (
                :set_code, :set_version, :set_name, :model_version, :question_count, :dimension_count,
                :payload_json, 1, 0,
                :created_by, :created_at, :updated_by, :updated_at
            )'
        );

        $stmtSet->execute([
            ':set_code' => $setCode,
            ':set_version' => $setVersion,
            ':set_name' => (string)$payload['set_name'],
            ':model_version' => attitude_model_version(),
            ':question_count' => count($questions),
            ':dimension_count' => count($dimensions),
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':created_by' => $actor,
            ':created_at' => $now,
            ':updated_by' => $actor,
            ':updated_at' => $now,
        ]);

        $setId = (int)$pdo->lastInsertId();

        $stmtItem = $pdo->prepare(
            'INSERT INTO attitude_question_items (
                set_id, question_code, question_no, dimension_code, dimension_label,
                question_text, choice_map_json, is_deleted,
                created_by, created_at, deleted_by, deleted_at
            ) VALUES (
                :set_id, :question_code, :question_no, :dimension_code, :dimension_label,
                :question_text, :choice_map_json, 0,
                :created_by, :created_at, NULL, NULL
            )'
        );

        $idx = 1;
        foreach ($questions as $questionCode => $question) {
            $dimensionCode = (string)$question['dim'];
            $dimensionLabel = (string)($dimensions[$dimensionCode] ?? $dimensionCode);
            $choices = $choiceSets[$dimensionCode] ?? [];
            $stmtItem->execute([
                ':set_id' => $setId,
                ':question_code' => $questionCode,
                ':question_no' => $idx,
                ':dimension_code' => $dimensionCode,
                ':dimension_label' => $dimensionLabel,
                ':question_text' => (string)$question['text'],
                ':choice_map_json' => json_encode($choices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':created_by' => $actor,
                ':created_at' => $now,
            ]);
            $idx++;
        }

        $pdo->commit();
        return $setId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function attitude_bootstrap(PDO $pdo, string $actor = 'system_seed'): void
{
    attitude_ensure_tables($pdo);
    attitude_seed_default_question_set($pdo, $actor);
}

/**
 * @return array<string,mixed>
 */
function attitude_fetch_latest_question_set(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT *
         FROM attitude_question_sets
         WHERE is_latest = 1 AND is_deleted = 0
         ORDER BY id DESC
         LIMIT 1'
    );

    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Attitude question set not found.');
    }

    $row['payload'] = json_decode((string)($row['payload_json'] ?? ''), true);
    if (!is_array($row['payload'])) {
        $row['payload'] = [];
    }

    return $row;
}

/**
 * @return array<string,mixed>|null
 */
function attitude_fetch_question_set_by_id(PDO $pdo, int $setId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM attitude_question_sets
         WHERE id = :id
           AND is_deleted = 0
         LIMIT 1'
    );
    $stmt->execute([':id' => $setId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $row['payload'] = json_decode((string)($row['payload_json'] ?? ''), true);
    if (!is_array($row['payload'])) {
        $row['payload'] = [];
    }

    return $row;
}

/**
 * @return array<int,array<string,mixed>>
 */
function attitude_fetch_question_items(PDO $pdo, int $setId): array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM attitude_question_items
         WHERE set_id = :set_id AND is_deleted = 0
         ORDER BY question_no ASC, id ASC'
    );
    $stmt->execute([':set_id' => $setId]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $choices = json_decode((string)($row['choice_map_json'] ?? ''), true);
        if (!is_array($choices)) {
            $choices = [];
        }
        $normalized = [];
        foreach ($choices as $value => $text) {
            $normalized[(string)$value] = (string)$text;
        }
        $row['choice_map'] = $normalized;
        $rows[] = $row;
    }

    return $rows;
}
function attitude_normalize_25(float $raw): float
{
    $score = (($raw - 5.0) / 20.0) * 100.0;
    if ($score < 0) {
        $score = 0;
    }
    if ($score > 100) {
        $score = 100;
    }
    return round($score, 2);
}

/**
 * @param array<string,float> $logp
 * @return array<string,float>
 */
function attitude_softmax(array $logp): array
{
    $maxLog = max($logp);
    $exp = [];
    $sum = 0.0;
    foreach ($logp as $class => $value) {
        $exp[$class] = exp($value - $maxLog);
        $sum += $exp[$class];
    }

    if ($sum <= 0) {
        return ['low' => 0.33, 'mid' => 0.34, 'high' => 0.33];
    }

    foreach ($exp as $class => $value) {
        $exp[$class] = $value / $sum;
    }

    return $exp;
}

/**
 * @param array<int,int> $answers
 * @return array{posterior:array<string,float>,label:string}
 */
function attitude_posterior_from_answers(array $answers): array
{
    $logp = [
        'low' => log(0.33),
        'mid' => log(0.34),
        'high' => log(0.33),
    ];

    $likelihood = [
        1 => ['low' => 0.34, 'mid' => 0.12, 'high' => 0.08],
        2 => ['low' => 0.26, 'mid' => 0.22, 'high' => 0.12],
        3 => ['low' => 0.20, 'mid' => 0.32, 'high' => 0.20],
        4 => ['low' => 0.12, 'mid' => 0.22, 'high' => 0.26],
        5 => ['low' => 0.08, 'mid' => 0.12, 'high' => 0.34],
    ];

    foreach ($answers as $answer) {
        if ($answer < 1 || $answer > 5) {
            continue;
        }
        foreach ($logp as $class => $current) {
            $logp[$class] = $current + log($likelihood[$answer][$class]);
        }
    }

    $posterior = attitude_softmax($logp);
    arsort($posterior);
    $label = (string)array_key_first($posterior);

    return ['posterior' => $posterior, 'label' => $label];
}

function attitude_class_label_th(string $label): string
{
    return match ($label) {
        'low' => 'Low',
        'mid' => 'Medium',
        'high' => 'High',
        default => $label,
    };
}

/**
 * @param array<int,array<string,mixed>> $questionItems
 * @param array<string,int> $answersByCode
 * @return array{dimension_scores:array<string,array<string,mixed>>,overall_index:float,overall_class:string,overall_posterior:array<string,float>}
 */
function attitude_calculate_scores(array $questionItems, array $answersByCode): array
{
    $dimensionLabels = attitude_dimension_labels();
    $spillMatrix = attitude_spill_matrix();

    /** @var array<string,array<int,int>> $answersByDim */
    $answersByDim = [];
    /** @var array<int,int> $flatAnswers */
    $flatAnswers = [];

    foreach ($questionItems as $item) {
        $questionCode = (string)($item['question_code'] ?? '');
        $dimensionCode = (string)($item['dimension_code'] ?? '');
        $answer = (int)($answersByCode[$questionCode] ?? 0);
        if ($questionCode === '' || $dimensionCode === '' || $answer < 1 || $answer > 5) {
            continue;
        }
        $answersByDim[$dimensionCode][] = $answer;
        $flatAnswers[] = $answer;
    }

    $dimensionScores = [];
    foreach ($dimensionLabels as $dimensionCode => $label) {
        $answers = $answersByDim[$dimensionCode] ?? [];
        $raw = (float)array_sum($answers);
        $mainScore = attitude_normalize_25($raw);
        $bayes = attitude_posterior_from_answers($answers);

        $dimensionScores[$dimensionCode] = [
            'dimension_code' => $dimensionCode,
            'dimension_label' => $label,
            'raw_score' => $raw,
            'main_score' => $mainScore,
            'spillover_score' => 0.0,
            'adjusted_score' => 0.0,
            'posterior' => $bayes['posterior'],
            'class_label' => $bayes['label'],
        ];
    }

    foreach ($dimensionScores as $dimensionCode => $row) {
        $spill = 0.0;
        foreach (($spillMatrix[$dimensionCode] ?? []) as $sourceCode => $weight) {
            $spill += ((float)($dimensionScores[$sourceCode]['main_score'] ?? 0.0)) * (float)$weight;
        }

        $adjusted = round(($row['main_score'] * 0.80) + $spill, 2);
        if ($adjusted < 0) {
            $adjusted = 0;
        }
        if ($adjusted > 100) {
            $adjusted = 100;
        }

        $dimensionScores[$dimensionCode]['spillover_score'] = round($spill, 2);
        $dimensionScores[$dimensionCode]['adjusted_score'] = $adjusted;
    }

    $overallBayes = attitude_posterior_from_answers($flatAnswers);
    $overallIndex = 0.0;
    if ($dimensionScores !== []) {
        $sumAdjusted = 0.0;
        foreach ($dimensionScores as $row) {
            $sumAdjusted += (float)$row['adjusted_score'];
        }
        $overallIndex = round($sumAdjusted / count($dimensionScores), 2);
    }

    return [
        'dimension_scores' => $dimensionScores,
        'overall_index' => $overallIndex,
        'overall_class' => $overallBayes['label'],
        'overall_posterior' => $overallBayes['posterior'],
    ];
}

/**
 * @param array<int,array<string,mixed>> $questionItems
 * @param array<string,mixed> $input
 * @return array<string,int>
 */
function attitude_collect_answers_from_input(array $questionItems, array $input): array
{
    $answers = [];
    foreach ($questionItems as $item) {
        $questionCode = (string)($item['question_code'] ?? '');
        if ($questionCode === '') {
            continue;
        }
        $answer = (int)($input[$questionCode] ?? 0);
        if ($answer < 1 || $answer > 5) {
            throw new RuntimeException('Please answer all survey questions (at least ' . $questionCode . ').');
        }
        $answers[$questionCode] = $answer;
    }

    return $answers;
}

/**
 * @param array<string,mixed> $source
 */
function attitude_default_contract_no(array $source): string
{
    $payload = $source['payload'] ?? [];
    if (!is_array($payload)) {
        $payload = [];
    }

    $candidateKeys = [
        'contract_no',
        'contract_number',
        'loan_contract_no',
        'application_no',
        'account_or_contract_no',
        'reference_contract_no',
    ];

    foreach ($candidateKeys as $key) {
        $value = trim((string)($payload[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return trim((string)($source['primary_ref'] ?? ''));
}

/**
 * @param array<string,mixed> $source
 */
function attitude_default_applicant_name(array $source): string
{
    $payload = $source['payload'] ?? [];
    if (!is_array($payload)) {
        $payload = [];
    }

    $candidateKeys = [
        'customer_name',
        'applicant_name',
        'borrower_name',
        'full_name',
    ];

    foreach ($candidateKeys as $key) {
        $value = trim((string)($payload[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return trim((string)($source['primary_name'] ?? ''));
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function attitude_collect_meta_from_input(array $input, array $source): array
{
    $contractNo = trim((string)($input['contract_no'] ?? ''));
    $applicantName = trim((string)($input['applicant_name'] ?? ''));
    $gender = strtolower(trim((string)($input['applicant_gender'] ?? 'unknown')));
    $age = (int)($input['applicant_age'] ?? 0);

    if ($contractNo === '') {
        $contractNo = attitude_default_contract_no($source);
    }
    if ($contractNo === '') {
        throw new RuntimeException('Please provide a contract number.');
    }

    if ($applicantName === '') {
        $applicantName = attitude_default_applicant_name($source);
    }

    if (!in_array($gender, ['male', 'female', 'other', 'unknown'], true)) {
        $gender = 'unknown';
    }

    if ($age < 0 || $age > 120) {
        throw new RuntimeException('Applicant age must be between 0 and 120 years.');
    }

    return [
        'contract_no' => mb_substr($contractNo, 0, 120),
        'applicant_name' => mb_substr($applicantName, 0, 255),
        'applicant_gender' => $gender,
        'applicant_age' => $age > 0 ? $age : null,
    ];
}
/**
 * @param array<string,mixed> $module
 * @param array<string,mixed> $source
 * @param array<string,mixed> $questionSet
 * @param array<int,array<string,mixed>> $questionItems
 * @param array<string,mixed> $meta
 * @param array<string,int> $answersByCode
 * @param array<string,mixed> $scoreResult
 * @return array<string,mixed>
 */
function attitude_persist_assessment(
    PDO $pdo,
    array $module,
    array $source,
    array $questionSet,
    array $questionItems,
    array $meta,
    array $answersByCode,
    array $scoreResult,
    string $actionType = 'CREATE',
    ?array $baseAssessment = null
): array {
    $actor = current_user_name();
    $role = current_role_name();
    $ip = request_ip();
    $device = request_device();
    $now = now_dt();

    $assessmentUid = $baseAssessment ? (string)$baseAssessment['assessment_uid'] : sprintf('ATT-%s-%s', date('YmdHis'), bin2hex(random_bytes(4)));
    $versionNo = $baseAssessment ? ((int)$baseAssessment['version_no'] + 1) : 1;

    $answerSetRef = sprintf('ANS-%s-v%d', $assessmentUid, $versionNo);

    $answersJson = [];
    foreach ($questionItems as $item) {
        $questionCode = (string)$item['question_code'];
        $answersJson[$questionCode] = (int)($answersByCode[$questionCode] ?? 0);
    }

    $setPayload = $questionSet['payload'] ?? [];
    if (!is_array($setPayload)) {
        $setPayload = [];
    }

    $beforeJson = $baseAssessment ? json_encode($baseAssessment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    $pdo->beginTransaction();
    try {
        $stmtUpdateLatest = $pdo->prepare(
            'UPDATE attitude_assessments
             SET is_latest = 0
             WHERE assessment_uid = :assessment_uid AND is_latest = 1'
        );
        $stmtUpdateLatest->execute([':assessment_uid' => $assessmentUid]);

        $stmtInsert = $pdo->prepare(
            'INSERT INTO attitude_assessments (
                assessment_uid, version_no, is_latest, is_deleted,
                module_key, workflow_source_id, workflow_record_uid, workflow_record_version,
                branch_code, source_primary_ref, source_primary_name,
                contract_no, applicant_name, applicant_gender, applicant_age,
                question_set_id, question_set_code, question_set_version, question_set_snapshot_json,
                answer_set_ref, answers_json,
                overall_index, overall_class, posterior_low, posterior_mid, posterior_high, result_json,
                created_by, created_role, created_at, created_ip, created_device,
                updated_by, updated_role, updated_at, updated_ip, updated_device,
                deleted_by, deleted_at
            ) VALUES (
                :assessment_uid, :version_no, 1, 0,
                :module_key, :workflow_source_id, :workflow_record_uid, :workflow_record_version,
                :branch_code, :source_primary_ref, :source_primary_name,
                :contract_no, :applicant_name, :applicant_gender, :applicant_age,
                :question_set_id, :question_set_code, :question_set_version, :question_set_snapshot_json,
                :answer_set_ref, :answers_json,
                :overall_index, :overall_class, :posterior_low, :posterior_mid, :posterior_high, :result_json,
                :created_by, :created_role, :created_at, :created_ip, :created_device,
                :updated_by, :updated_role, :updated_at, :updated_ip, :updated_device,
                NULL, NULL
            )'
        );

        $overallPosterior = $scoreResult['overall_posterior'];
        if (!is_array($overallPosterior)) {
            $overallPosterior = ['low' => 0.33, 'mid' => 0.34, 'high' => 0.33];
        }

        $resultPayload = [
            'overall_index' => (float)$scoreResult['overall_index'],
            'overall_class' => (string)$scoreResult['overall_class'],
            'overall_posterior' => $overallPosterior,
            'dimension_scores' => $scoreResult['dimension_scores'],
            'calculated_at' => $now,
            'model_version' => (string)($questionSet['model_version'] ?? attitude_model_version()),
        ];

        $stmtInsert->execute([
            ':assessment_uid' => $assessmentUid,
            ':version_no' => $versionNo,
            ':module_key' => (string)$module['key'],
            ':workflow_source_id' => (int)$source['id'],
            ':workflow_record_uid' => (string)$source['record_uid'],
            ':workflow_record_version' => (int)$source['version_no'],
            ':branch_code' => (string)($source['branch_code'] ?? ''),
            ':source_primary_ref' => (string)($source['primary_ref'] ?? ''),
            ':source_primary_name' => (string)($source['primary_name'] ?? ''),
            ':contract_no' => (string)$meta['contract_no'],
            ':applicant_name' => (string)$meta['applicant_name'],
            ':applicant_gender' => (string)$meta['applicant_gender'],
            ':applicant_age' => $meta['applicant_age'] !== null ? (int)$meta['applicant_age'] : null,
            ':question_set_id' => (int)$questionSet['id'],
            ':question_set_code' => (string)$questionSet['set_code'],
            ':question_set_version' => (int)$questionSet['set_version'],
            ':question_set_snapshot_json' => json_encode($setPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':answer_set_ref' => $answerSetRef,
            ':answers_json' => json_encode($answersJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':overall_index' => (float)$scoreResult['overall_index'],
            ':overall_class' => (string)$scoreResult['overall_class'],
            ':posterior_low' => (float)($overallPosterior['low'] ?? 0),
            ':posterior_mid' => (float)($overallPosterior['mid'] ?? 0),
            ':posterior_high' => (float)($overallPosterior['high'] ?? 0),
            ':result_json' => json_encode($resultPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
        ]);

        $newId = (int)$pdo->lastInsertId();

        $stmtAnswer = $pdo->prepare(
            'INSERT INTO attitude_assessment_answers (
                assessment_uid, version_no, question_set_id, question_set_item_id,
                question_code, question_no, dimension_code, question_text,
                answer_value, answer_text, created_by, created_at
            ) VALUES (
                :assessment_uid, :version_no, :question_set_id, :question_set_item_id,
                :question_code, :question_no, :dimension_code, :question_text,
                :answer_value, :answer_text, :created_by, :created_at
            )'
        );

        foreach ($questionItems as $item) {
            $questionCode = (string)$item['question_code'];
            $answerValue = (int)($answersByCode[$questionCode] ?? 0);
            $choiceMap = $item['choice_map'] ?? [];
            if (!is_array($choiceMap)) {
                $choiceMap = [];
            }
            $answerText = (string)($choiceMap[(string)$answerValue] ?? '');

            $stmtAnswer->execute([
                ':assessment_uid' => $assessmentUid,
                ':version_no' => $versionNo,
                ':question_set_id' => (int)$questionSet['id'],
                ':question_set_item_id' => (int)$item['id'],
                ':question_code' => $questionCode,
                ':question_no' => (int)$item['question_no'],
                ':dimension_code' => (string)$item['dimension_code'],
                ':question_text' => (string)$item['question_text'],
                ':answer_value' => $answerValue,
                ':answer_text' => $answerText,
                ':created_by' => $actor,
                ':created_at' => $now,
            ]);
        }

        $stmtDim = $pdo->prepare(
            'INSERT INTO attitude_assessment_dimensions (
                assessment_uid, version_no,
                dimension_code, dimension_label,
                raw_score, main_score, spillover_score, adjusted_score,
                posterior_low, posterior_mid, posterior_high, class_label,
                created_by, created_at
            ) VALUES (
                :assessment_uid, :version_no,
                :dimension_code, :dimension_label,
                :raw_score, :main_score, :spillover_score, :adjusted_score,
                :posterior_low, :posterior_mid, :posterior_high, :class_label,
                :created_by, :created_at
            )'
        );

        $dimensionScores = $scoreResult['dimension_scores'];
        if (!is_array($dimensionScores)) {
            $dimensionScores = [];
        }
        foreach ($dimensionScores as $dimensionCode => $row) {
            $posterior = $row['posterior'] ?? [];
            if (!is_array($posterior)) {
                $posterior = [];
            }
            $stmtDim->execute([
                ':assessment_uid' => $assessmentUid,
                ':version_no' => $versionNo,
                ':dimension_code' => (string)$dimensionCode,
                ':dimension_label' => (string)($row['dimension_label'] ?? $dimensionCode),
                ':raw_score' => (float)($row['raw_score'] ?? 0),
                ':main_score' => (float)($row['main_score'] ?? 0),
                ':spillover_score' => (float)($row['spillover_score'] ?? 0),
                ':adjusted_score' => (float)($row['adjusted_score'] ?? 0),
                ':posterior_low' => (float)($posterior['low'] ?? 0),
                ':posterior_mid' => (float)($posterior['mid'] ?? 0),
                ':posterior_high' => (float)($posterior['high'] ?? 0),
                ':class_label' => (string)($row['class_label'] ?? 'mid'),
                ':created_by' => $actor,
                ':created_at' => $now,
            ]);
        }

        $afterPayload = [
            'assessment_id' => $newId,
            'assessment_uid' => $assessmentUid,
            'version_no' => $versionNo,
            'module_key' => (string)$module['key'],
            'workflow_source_id' => (int)$source['id'],
            'contract_no' => (string)$meta['contract_no'],
            'overall_index' => (float)$scoreResult['overall_index'],
            'overall_class' => (string)$scoreResult['overall_class'],
            'answer_set_ref' => $answerSetRef,
        ];

        $stmtAction = $pdo->prepare(
            'INSERT INTO action_logs (
                module_key, action_type, record_uid, version_no,
                reason, before_json, after_json,
                user_name, role_name, ip_address, device_info, created_at
            ) VALUES (
                :module_key, :action_type, :record_uid, :version_no,
                :reason, :before_json, :after_json,
                :user_name, :role_name, :ip_address, :device_info, :created_at
            )'
        );
        $stmtAction->execute([
            ':module_key' => 'attitude_assessment',
            ':action_type' => $actionType,
            ':record_uid' => $assessmentUid,
            ':version_no' => $versionNo,
            ':reason' => 'Saved debt repayment attitude assessment (35 questions)',
            ':before_json' => $beforeJson,
            ':after_json' => json_encode($afterPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':user_name' => $actor,
            ':role_name' => $role,
            ':ip_address' => $ip,
            ':device_info' => $device,
            ':created_at' => $now,
        ]);

        $stmtEvent = $pdo->prepare(
            'INSERT INTO event_ledger (
                event_type, module_key, record_uid, version_no,
                event_payload, actor_name, actor_role, ip_address, device_info, created_at
            ) VALUES (
                :event_type, :module_key, :record_uid, :version_no,
                :event_payload, :actor_name, :actor_role, :ip_address, :device_info, :created_at
            )'
        );
        $stmtEvent->execute([
            ':event_type' => $actionType,
            ':module_key' => 'attitude_assessment',
            ':record_uid' => $assessmentUid,
            ':version_no' => $versionNo,
            ':event_payload' => json_encode($afterPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':actor_name' => $actor,
            ':actor_role' => $role,
            ':ip_address' => $ip,
            ':device_info' => $device,
            ':created_at' => $now,
        ]);

        $pdo->commit();

        return [
            'id' => $newId,
            'assessment_uid' => $assessmentUid,
            'version_no' => $versionNo,
            'answer_set_ref' => $answerSetRef,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * @return array<int,array<string,mixed>>
 */
function attitude_list_latest_for_source(PDO $pdo, string $moduleKey, int $sourceId): array
{
    $scopeFilter = access_scope_sql_clause('branch_code', 'att_scope');
    $stmt = $pdo->prepare(
        'SELECT *
         FROM attitude_assessments
         WHERE module_key = :module_key
           AND workflow_source_id = :source_id
           AND is_latest = 1
           AND is_deleted = 0' . $scopeFilter['sql'] . '
         ORDER BY id DESC
         LIMIT 200'
    );

    $params = [
        ':module_key' => $moduleKey,
        ':source_id' => $sourceId,
    ];
    foreach ($scopeFilter['params'] as $key => $value) {
        $params[$key] = $value;
    }

    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['answers'] = json_decode((string)($row['answers_json'] ?? ''), true);
        if (!is_array($row['answers'])) {
            $row['answers'] = [];
        }
        $row['result'] = json_decode((string)($row['result_json'] ?? ''), true);
        if (!is_array($row['result'])) {
            $row['result'] = [];
        }
    }

    return $rows;
}

/**
 * @return array<string,mixed>|null
 */
function attitude_find_latest_by_id(PDO $pdo, int $id): ?array
{
    $scopeFilter = access_scope_sql_clause('branch_code', 'att_find');
    $stmt = $pdo->prepare(
        'SELECT *
         FROM attitude_assessments
         WHERE id = :id
           AND is_latest = 1
           AND is_deleted = 0' . $scopeFilter['sql'] . '
         LIMIT 1'
    );

    $params = [':id' => $id];
    foreach ($scopeFilter['params'] as $key => $value) {
        $params[$key] = $value;
    }

    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $row['answers'] = json_decode((string)($row['answers_json'] ?? ''), true);
    if (!is_array($row['answers'])) {
        $row['answers'] = [];
    }

    $row['result'] = json_decode((string)($row['result_json'] ?? ''), true);
    if (!is_array($row['result'])) {
        $row['result'] = [];
    }

    return $row;
}

/**
 * @return array<int,array<string,mixed>>
 */
function attitude_fetch_dimension_rows(PDO $pdo, string $assessmentUid, int $versionNo): array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM attitude_assessment_dimensions
         WHERE assessment_uid = :assessment_uid AND version_no = :version_no
         ORDER BY id ASC'
    );
    $stmt->execute([
        ':assessment_uid' => $assessmentUid,
        ':version_no' => $versionNo,
    ]);

    return $stmt->fetchAll();
}

/**
 * @return array<int,array<string,mixed>>
 */
function attitude_fetch_answer_rows(PDO $pdo, string $assessmentUid, int $versionNo): array
{
    $stmt = $pdo->prepare(
        'SELECT *
         FROM attitude_assessment_answers
         WHERE assessment_uid = :assessment_uid AND version_no = :version_no
         ORDER BY question_no ASC, id ASC'
    );
    $stmt->execute([
        ':assessment_uid' => $assessmentUid,
        ':version_no' => $versionNo,
    ]);

    return $stmt->fetchAll();
}

/**
 * @param array<int,array<string,mixed>> $answerRows
 * @return array<string,int>
 */
function attitude_answer_map_from_rows(array $answerRows): array
{
    $map = [];
    foreach ($answerRows as $row) {
        $code = (string)($row['question_code'] ?? '');
        if ($code === '') {
            continue;
        }
        $map[$code] = (int)($row['answer_value'] ?? 0);
    }

    return $map;
}

function attitude_gender_label_th(string $gender): string
{
    return match (strtolower(trim($gender))) {
        'male' => 'Male',
        'female' => 'Female',
        'other' => 'Other',
        default => 'Unspecified',
    };
}

<?php

declare(strict_types=1);

/*
 * Laporan Peringkat Prioritas Pemeliharaan
 * Menampilkan:
 * - urutan prioritas A1 sampai A6;
 * - D+, D-, nilai preferensi, ranking, dan rank_group;
 * - rekomendasi Supervisor Maintenance;
 * - penugasan dan realisasi Teknisi Maintenance;
 * - ringkasan status tindak lanjut.
 * Hak akses:
 * - Administrator;
 * - Supervisor Maintenance.
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'helpers'
    . DIRECTORY_SEPARATOR . 'auth.php';

auth_require_role([
    'admin',
    'supervisor',
]);

$pdo = require dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'config'
    . DIRECTORY_SEPARATOR . 'database.php';

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun pengguna sudah tidak aktif atau tidak ditemukan.'
    );

    redirect('login.php');
}

$currentRole = auth_role();
$currentUserName = auth_name() ?? 'Pengguna';
$currentRoleLabel = auth_role_label($currentRole);

$isAdmin = $currentRole === 'admin';
$isSupervisor = $currentRole === 'supervisor';

$expectedMachineCount = (int) app_config(
    'total_machines',
    6
);

$expectedAlternativeCodes = [
    'A1',
    'A2',
    'A3',
    'A4',
    'A5',
    'A6',
];

$allowedAssessmentStatuses = [
    'validated',
    'processed',
];

$allowedActionPriorities = [
    'immediate',
    'scheduled',
    'monitoring',
];

$allowedActionStatuses = [
    'pending',
    'in_progress',
    'completed',
];

$preferenceTolerance = 0.000000001;

$periods = [];
$selectedPeriod = null;
$rankingRows = [];

$pageError = null;
$dataValidationError = null;

$summary = [
    'result_count' => 0,
    'action_count' => 0,
    'not_recorded_count' => 0,
    'pending_count' => 0,
    'in_progress_count' => 0,
    'completed_count' => 0,
    'immediate_count' => 0,
    'scheduled_count' => 0,
    'monitoring_count' => 0,
    'invalid_action_count' => 0,
];

$topPriority = null;
$topPriorityRows = [];
$calculatedAt = null;
$supervisorSignatureName = 'Supervisor Maintenance';

$resultCodes = [];
$resultAssessmentIds = [];
$actionIds = [];
$supervisorNames = [];
$rankingValidationRows = [];

$resultsStructurallyValid = true;
$rankingAndGroupValid = true;
$actionsStructurallyValid = true;

// Daftar Periode

try {
    $periodStatement = $pdo->query(
        "SELECT
            ap.id,
            ap.name,
            ap.start_date,
            ap.end_date,
            ap.status,
            ap.created_at,
            creator.name AS created_by_name
         FROM assessment_periods AS ap
         INNER JOIN users AS creator
            ON creator.id = ap.created_by
         ORDER BY
            CASE ap.status
                WHEN 'active' THEN 1
                WHEN 'draft' THEN 2
                WHEN 'completed' THEN 3
                ELSE 4
            END,
            ap.start_date DESC,
            ap.id DESC"
    );

    $periods = $periodStatement->fetchAll();
} catch (Throwable $exception) {
    error_log(
        'Daftar periode laporan peringkat gagal dimuat: '
        . $exception->getMessage()
    );

    $pageError =
        'Daftar periode laporan peringkat belum dapat dimuat.';
}

// Periode Terpilih

$periodIdInput = $_GET['period_id'] ?? null;

if (
    $periodIdInput !== null
    && $periodIdInput !== ''
) {
    $validatedPeriodId = filter_var(
        $periodIdInput,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if ($validatedPeriodId === false) {
        $notFoundMessage =
            'ID periode laporan peringkat tidak valid.';

        require dirname(__DIR__)
            . DIRECTORY_SEPARATOR . '404.php';

        exit;
    }

    foreach ($periods as $period) {
        if (
            (int) $period['id']
            === (int) $validatedPeriodId
        ) {
            $selectedPeriod = $period;
            break;
        }
    }

    if ($selectedPeriod === null) {
        $notFoundMessage =
            'Periode laporan peringkat tidak ditemukan.';

        require dirname(__DIR__)
            . DIRECTORY_SEPARATOR . '404.php';

        exit;
    }
} elseif ($periods !== []) {
    foreach ($periods as $period) {
        if (
            (string) ($period['status'] ?? '')
            === 'active'
        ) {
            $selectedPeriod = $period;
            break;
        }
    }

    $selectedPeriod ??= $periods[0];
}

$selectedPeriodId = $selectedPeriod !== null
    ? (int) $selectedPeriod['id']
    : null;

// Data Peringkat dan Tindak Lanjut

if ($selectedPeriodId !== null) {
    try {
        $rankingStatement = $pdo->prepare(
            "SELECT
                a.id AS assessment_id,
                a.inspection_date,
                a.status AS assessment_status,
                a.validated_at,

                m.id AS machine_id,
                m.alternative_code,
                m.machine_code,
                m.machine_number,
                m.motor_name,
                m.motor_function,
                m.brand,
                m.location,

                tr.id AS topsis_result_id,
                tr.positive_distance,
                tr.negative_distance,
                tr.preference_value,
                tr.ranking,
                tr.rank_group,
                tr.priority_label,
                tr.calculated_at AS topsis_calculated_at,

                ma.id AS action_id,
                ma.recommendation,
                ma.priority AS action_priority,
                ma.action_description,
                ma.target_date,
                ma.status AS action_status,
                ma.completed_date,
                ma.additional_note,
                ma.work_order_reference,
                ma.created_at AS action_created_at,
                ma.updated_at AS action_updated_at,
                ma.created_by,
                ma.performed_by,

                creator.name AS action_creator_name,
                creator.role AS action_creator_role,

                performer.name AS performed_by_name,
                performer.role AS performer_role

             FROM topsis_results AS tr

             INNER JOIN assessments AS a
                ON a.id = tr.assessment_id
               AND a.period_id = tr.period_id

             INNER JOIN machines AS m
                ON m.id = a.machine_id

             LEFT JOIN maintenance_actions AS ma
                ON ma.assessment_id = a.id

             LEFT JOIN users AS creator
                ON creator.id = ma.created_by

             LEFT JOIN users AS performer
                ON performer.id = ma.performed_by

             WHERE tr.period_id = :period_id
               AND m.alternative_code IN (
                   'A1',
                   'A2',
                   'A3',
                   'A4',
                   'A5',
                   'A6'
               )

             ORDER BY
                CASE
                    WHEN tr.ranking IS NULL THEN 1
                    ELSE 0
                END,
                tr.ranking ASC,
                tr.rank_group ASC,
                tr.preference_value DESC,
                m.alternative_code COLLATE NOCASE ASC"
        );

        $rankingStatement->execute([
            'period_id' => $selectedPeriodId,
        ]);

        $rankingRows = $rankingStatement->fetchAll();

        foreach ($rankingRows as $row) {
            $assessmentId = (int) (
                $row['assessment_id']
                ?? 0
            );

            $alternativeCode = strtoupper(
                trim(
                    (string) (
                        $row['alternative_code']
                        ?? ''
                    )
                )
            );

            $assessmentStatus = (string) (
                $row['assessment_status']
                ?? ''
            );

            if (
                $assessmentId < 1
                || !in_array(
                    $alternativeCode,
                    $expectedAlternativeCodes,
                    true
                )
                || isset($resultCodes[$alternativeCode])
                || isset($resultAssessmentIds[$assessmentId])
                || !in_array(
                    $assessmentStatus,
                    $allowedAssessmentStatuses,
                    true
                )
            ) {
                $resultsStructurallyValid = false;
                continue;
            }

            $positiveDistance =
                $row['positive_distance']
                ?? null;

            $negativeDistance =
                $row['negative_distance']
                ?? null;

            $preferenceValue =
                $row['preference_value']
                ?? null;

            $ranking =
                $row['ranking']
                ?? null;

            $rankGroup =
                $row['rank_group']
                ?? null;

            if (
                !is_numeric((string) $positiveDistance)
                || !is_numeric((string) $negativeDistance)
                || !is_numeric((string) $preferenceValue)
                || !is_numeric((string) $ranking)
                || !is_numeric((string) $rankGroup)
            ) {
                $resultsStructurallyValid = false;
                continue;
            }

            $positiveDistance = (float) $positiveDistance;
            $negativeDistance = (float) $negativeDistance;
            $preferenceValue = (float) $preferenceValue;
            $ranking = (int) $ranking;
            $rankGroup = (int) $rankGroup;

            if (
                !is_finite($positiveDistance)
                || !is_finite($negativeDistance)
                || !is_finite($preferenceValue)
                || $positiveDistance < 0
                || $negativeDistance < 0
                || $preferenceValue < 0
                || $preferenceValue > 1
                || $ranking < 1
                || $ranking > $expectedMachineCount
                || $rankGroup < 1
                || $rankGroup > $expectedMachineCount
            ) {
                $resultsStructurallyValid = false;
                continue;
            }

            $resultCodes[$alternativeCode] = true;
            $resultAssessmentIds[$assessmentId] = true;

            $rankingValidationRows[] = [
                'alternative_code' =>
                    $alternativeCode,

                'preference_value' =>
                    $preferenceValue,

                'ranking' =>
                    $ranking,

                'rank_group' =>
                    $rankGroup,
            ];

            if (
                !empty($row['topsis_calculated_at'])
            ) {
                $candidateCalculatedAt =
                    (string) $row['topsis_calculated_at'];

                if (
                    $calculatedAt === null
                    || strcmp(
                        $candidateCalculatedAt,
                        $calculatedAt
                    ) > 0
                ) {
                    $calculatedAt =
                        $candidateCalculatedAt;
                }
            }

            if ($ranking === 1) {
                $topPriorityRows[] = $row;
            }

            if ($row['action_id'] === null) {
                $summary['not_recorded_count']++;
                continue;
            }

            $actionId = (int) (
                $row['action_id']
                ?? 0
            );

            if (
                $actionId < 1
                || isset($actionIds[$actionId])
            ) {
                $actionsStructurallyValid = false;
                $summary['invalid_action_count']++;
                continue;
            }

            $actionIds[$actionId] = true;
            $summary['action_count']++;

            $recommendation = trim(
                (string) (
                    $row['recommendation']
                    ?? ''
                )
            );

            $actionPriority = (string) (
                $row['action_priority']
                ?? ''
            );

            $actionStatus = (string) (
                $row['action_status']
                ?? ''
            );

            $creatorRole = (string) (
                $row['action_creator_role']
                ?? ''
            );

            $creatorName = trim(
                (string) (
                    $row['action_creator_name']
                    ?? ''
                )
            );

            $performedBy = (int) (
                $row['performed_by']
                ?? 0
            );

            $performerRole = (string) (
                $row['performer_role']
                ?? ''
            );

            $actionDescription = trim(
                (string) (
                    $row['action_description']
                    ?? ''
                )
            );

            $completedDate = trim(
                (string) (
                    $row['completed_date']
                    ?? ''
                )
            );

            if (
                $recommendation === ''
                || !in_array(
                    $actionPriority,
                    $allowedActionPriorities,
                    true
                )
                || !in_array(
                    $actionStatus,
                    $allowedActionStatuses,
                    true
                )
                || $creatorRole !== 'supervisor'
            ) {
                $actionsStructurallyValid = false;
                $summary['invalid_action_count']++;
            }

            if ($creatorName !== '') {
                $supervisorNames[$creatorName] = true;
            }

            if (
                $performedBy > 0
                && $performerRole !== 'technician'
            ) {
                $actionsStructurallyValid = false;
                $summary['invalid_action_count']++;
            }

            if (
                $actionStatus === 'in_progress'
                && (
                    $performedBy < 1
                    || $actionDescription === ''
                )
            ) {
                $actionsStructurallyValid = false;
                $summary['invalid_action_count']++;
            }

            if (
                $actionStatus === 'completed'
                && (
                    $performedBy < 1
                    || $actionDescription === ''
                    || $completedDate === ''
                )
            ) {
                $actionsStructurallyValid = false;
                $summary['invalid_action_count']++;
            }

            if ($actionStatus === 'pending') {
                $summary['pending_count']++;
            } elseif ($actionStatus === 'in_progress') {
                $summary['in_progress_count']++;
            } elseif ($actionStatus === 'completed') {
                $summary['completed_count']++;
            }

            if ($actionPriority === 'immediate') {
                $summary['immediate_count']++;
            } elseif ($actionPriority === 'scheduled') {
                $summary['scheduled_count']++;
            } elseif ($actionPriority === 'monitoring') {
                $summary['monitoring_count']++;
            }
        }

        $summary['result_count'] =
            count($resultCodes);

        // Validasi Ranking Kompetisi dan rank_group

        usort(
            $rankingValidationRows,
            static function (
                array $first,
                array $second
            ): int {
                $preferenceComparison =
                    (float) $second['preference_value']
                    <=>
                    (float) $first['preference_value'];

                if ($preferenceComparison !== 0) {
                    return $preferenceComparison;
                }

                return strcmp(
                    (string) $first['alternative_code'],
                    (string) $second['alternative_code']
                );
            }
        );

        $previousPreference = null;
        $expectedRankGroup = 0;
        $expectedRanking = 0;

        foreach (
            $rankingValidationRows
            as $position => $validationRow
        ) {
            $preference =
                (float) $validationRow['preference_value'];

            $newGroup =
                $previousPreference === null
                || abs(
                    $preference
                    - $previousPreference
                ) > $preferenceTolerance;

            if ($newGroup) {
                $expectedRankGroup++;
                $expectedRanking = $position + 1;
                $previousPreference = $preference;
            }

            if (
                (int) $validationRow['ranking']
                !== $expectedRanking
                || (int) $validationRow['rank_group']
                !== $expectedRankGroup
            ) {
                $rankingAndGroupValid = false;
                break;
            }
        }

        if ($topPriorityRows !== []) {
            usort(
                $topPriorityRows,
                static fn (
                    array $first,
                    array $second
                ): int =>
                    strcmp(
                        (string) (
                            $first['alternative_code']
                            ?? ''
                        ),
                        (string) (
                            $second['alternative_code']
                            ?? ''
                        )
                    )
            );

            $topPriority = $topPriorityRows[0];
        }

        if (count($supervisorNames) === 1) {
            $supervisorSignatureName =
                (string) array_key_first(
                    $supervisorNames
                );
        }

        if (!$resultsStructurallyValid) {
            $dataValidationError =
                'Hasil TOPSIS tidak valid, tidak lengkap, '
                . 'atau memuat alternatif ganda.';
        } elseif (!$rankingAndGroupValid) {
            $dataValidationError =
                'Ranking dan rank_group tidak sesuai '
                . 'dengan urutan nilai preferensi.';
        } elseif (!$actionsStructurallyValid) {
            $dataValidationError =
                'Terdapat rekomendasi atau realisasi tindakan '
                . 'yang tidak sesuai dengan pembagian peran dan status.';
        }
    } catch (Throwable $exception) {
        error_log(
            'Laporan peringkat gagal dimuat: '
            . $exception->getMessage()
        );

        $pageError =
            'Data laporan peringkat belum dapat dimuat.';
    }
}

$resultsComplete =
    $summary['result_count']
        === $expectedMachineCount
    && count(
        array_diff(
            $expectedAlternativeCodes,
            array_keys($resultCodes)
        )
    ) === 0
    && $resultsStructurallyValid
    && $rankingAndGroupValid;

$reportAvailable =
    $resultsComplete
    && $dataValidationError === null;

// Helper Tampilan

$displayNumber = static function (
    mixed $value,
    int $decimals = 6
): string {
    if (
        $value === null
        || $value === ''
        || !is_numeric($value)
    ) {
        return '-';
    }

    $number = (float) $value;

    if (!is_finite($number)) {
        return '-';
    }

    return format_number_id(
        $number,
        $decimals
    );
};

$motorFunctionLabel = static function (
    ?string $function
): string {
    return match ($function) {
        'scraper' => 'Scraper',
        'homogenizer' => 'Homogenizer',
        default => '-',
    };
};

$rankingMeta = static function (
    mixed $rankGroup
): array {
    $group = is_numeric($rankGroup)
        ? (int) $rankGroup
        : null;

    return match ($group) {
        1 => [
            'label' => 'Prioritas Utama',
            'class' => 'danger',
        ],

        2 => [
            'label' => 'Prioritas Tinggi',
            'class' => 'warning',
        ],

        3 => [
            'label' => 'Prioritas Menengah',
            'class' => 'info',
        ],

        4, 5, 6 => [
            'label' => 'Prioritas Berikutnya',
            'class' => 'secondary',
        ],

        default => [
            'label' => 'Belum Diperingkat',
            'class' => 'secondary',
        ],
    };
};

$actionPriorityMeta = static function (
    ?string $priority
): array {
    return match ($priority) {
        'immediate' => [
            'label' => 'Segera',
            'class' => 'danger',
        ],

        'scheduled' => [
            'label' => 'Terjadwal',
            'class' => 'warning',
        ],

        'monitoring' => [
            'label' => 'Monitoring',
            'class' => 'info',
        ],

        default => [
            'label' => 'Belum Ditentukan',
            'class' => 'secondary',
        ],
    };
};

$actionStatusMeta = static function (
    ?string $status,
    bool $hasAction
): array {
    if (!$hasAction) {
        return [
            'label' => 'Belum Dicatat',
            'class' => 'secondary',
        ];
    }

    return match ($status) {
        'pending' => [
            'label' => 'Menunggu',
            'class' => 'warning',
        ],

        'in_progress' => [
            'label' => 'Dikerjakan',
            'class' => 'info',
        ],

        'completed' => [
            'label' => 'Selesai',
            'class' => 'success',
        ],

        default => [
            'label' => 'Tidak Diketahui',
            'class' => 'secondary',
        ],
    };
};

$periodStatusConfigurations = [
    'draft' => [
        'label' => 'Draft',
        'class' => 'secondary',
    ],

    'active' => [
        'label' => 'Aktif',
        'class' => 'success',
    ],

    'completed' => [
        'label' => 'Selesai',
        'class' => 'primary',
    ],
];

$selectedPeriodStatus = $selectedPeriod !== null
    ? (string) (
        $selectedPeriod['status']
        ?? 'draft'
    )
    : 'draft';

$selectedPeriodStatusInfo =
    $periodStatusConfigurations[
        $selectedPeriodStatus
    ]
    ?? [
        'label' => 'Tidak diketahui',
        'class' => 'secondary',
    ];

$printedAt = date('Y-m-d H:i:s');

$pageTitle =
    'Laporan Prioritas & Tindak Lanjut';

$pageDescription =
    'Laporan urutan prioritas, rekomendasi, '
    . 'dan pelaksanaan pemeliharaan motor.';

$bodyClass =
    'd-flex flex-column min-vh-100';

$includePrintCss = true;

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR . 'header.php';

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR . 'navbar.php';

?>

<div class="d-lg-flex flex-grow-1">
    <?php
    require TEMPLATE_PATH
        . DIRECTORY_SEPARATOR . 'sidebar.php';
    ?>

    <main id="main-content" class="flex-grow-1 p-3 p-lg-4 overflow-hidden">
        <?php
        require TEMPLATE_PATH
            . DIRECTORY_SEPARATOR . 'alert.php';
        ?>

        <?php if ($pageError !== null): ?>
            <div class="alert alert-danger no-print" role="alert">
                <strong class="d-block mb-1">
                    Laporan belum dapat dimuat
                </strong>

                <?= e($pageError) ?>
            </div>
        <?php endif; ?>

        <section class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4 no-print">
            <div>
                <h1 class="page-title mb-1">
                    Laporan Prioritas & Tindak Lanjut
                </h1>

                <p class="page-description mb-0">
                    Urutan prioritas A1–A6, rekomendasi,
                    dan realisasi tindakan pemeliharaan.
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="<?= e(base_url( 'laporan/index.php' . ( $selectedPeriodId !== null ? '?period_id=' . $selectedPeriodId : '' ) )) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>

                <button type="button" class="btn btn-primary" onclick="window.print()" <?= !$reportAvailable ? 'disabled' : '' ?>>
                    Cetak Laporan Ini
                </button>

                <?php if ($selectedPeriodId !== null && $reportAvailable): ?>
                    <a
                        href="<?= e(base_url('laporan/keputusan.php?period_id=' . $selectedPeriodId)) ?>"
                        class="btn btn-outline-primary"
                    >
                        Lihat Laporan Akhir
                    </a>
                <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary" disabled>
                        Laporan Akhir Belum Lengkap
                    </button>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($isAdmin): ?>
            <div class="alert alert-secondary mb-4 no-print" role="status">
                Administrator membuka laporan dalam mode baca.
                Rekomendasi dan pelaksanaan pemeliharaan tidak
                dapat diubah dari halaman laporan.
            </div>
        <?php elseif ($isSupervisor): ?>
            <div class="alert alert-info mb-4 no-print" role="status">
                Laporan menggabungkan hasil TOPSIS dengan
                rekomendasi Supervisor dan realisasi Teknisi.
            </div>
        <?php endif; ?>

        <section class="card border-0 shadow-sm mb-4 no-print" aria-labelledby="ranking-report-filter-title">
            <div class="card-body p-4">
                <h2 id="ranking-report-filter-title" class="h5 mb-1">
                    Periode Laporan
                </h2>

                <p class="small text-body-secondary mb-3">
                    Pilih periode peringkat yang akan ditampilkan.
                </p>

                <form method="get" action="<?= e(base_url( 'laporan/peringkat.php' )) ?>" class="row g-3 align-items-end">
                    <div class="col-md-8 col-xl-9">
                        <label for="period_id" class="form-label">
                            Periode Pemeriksaan
                        </label>

                        <select id="period_id" name="period_id" class="form-select" required>
                            <?php if ($periods === []): ?>
                                <option value="">
                                    Belum ada periode
                                </option>
                            <?php else: ?>
                                <?php foreach (
                                    $periods
                                    as $period
                                ): ?>
                                    <?php
                                    $periodStatus = (string) (
                                        $period['status']
                                        ?? 'draft'
                                    );

                                    $periodStatusInfo =
                                        $periodStatusConfigurations[
                                            $periodStatus
                                        ]
                                        ?? [
                                            'label' =>
                                                'Tidak diketahui',
                                        ];
                                    ?>

                                    <option value="<?= e( $period['id'] ) ?>" <?= selected( $selectedPeriodId, (int) $period['id'] ) ?>>
                                        <?= e($period['name']) ?>
                                        —
                                        <?= e(
                                            $periodStatusInfo['label']
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="col-md-4 col-xl-3">
                        <button type="submit" class="btn btn-primary w-100" <?= $periods === [] ? 'disabled' : '' ?>>
                            Tampilkan Laporan
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <?php if ($selectedPeriod === null): ?>
            <div class="alert alert-warning" role="status">
                Belum terdapat periode pemeriksaan yang dapat
                ditampilkan pada laporan peringkat.
            </div>
        <?php else: ?>
            <header class="print-report-header d-none d-print-block">
                <div class="print-report-brand-row">
                    <img
                        src="<?= e(base_url('assets/images/logo_cisas.webp')) ?>"
                        alt="Logo PT CISAS"
                        class="print-report-logo"
                    >

                    <div class="print-report-brand-copy">
                        <strong>PT CITRASEMESTA ASRISEJATI</strong>
                        <span>Sistem Pendukung Keputusan Pemeliharaan Motor Mesin Mixer</span>
                    </div>
                </div>

                <div class="print-report-title-block">
                    <h1>LAPORAN PERINGKAT PRIORITAS PEMELIHARAAN</h1>
                    <p>Metode Analytical Hierarchy Process (AHP) dan TOPSIS</p>
                </div>
            </header>

            <section class="card border-0 shadow-sm mb-4 report-identity" aria-labelledby="ranking-report-identity-title">
                <div class="card-body p-4">
                    <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                        <div>
                            <h2 id="ranking-report-identity-title" class="h5 mb-2">
                                <?= e($selectedPeriod['name']) ?>
                            </h2>

                            <p class="mb-1">
                                <strong>Rentang tanggal:</strong>

                                <?= e(format_date_id(
                                    $selectedPeriod['start_date']
                                )) ?>

                                sampai

                                <?= e(format_date_id(
                                    $selectedPeriod['end_date']
                                )) ?>
                            </p>

                            <p class="mb-1">
                                <strong>Jumlah alternatif:</strong>

                                <?= e(
                                    $summary['result_count']
                                ) ?>
                            </p>

                            <p class="mb-0">
                                <strong>Hasil perhitungan:</strong>

                                <?= e(format_date_id(
                                    $calculatedAt,
                                    true
                                )) ?>
                            </p>
                        </div>

                        <div class="text-md-end">
                            <span class="badge text-bg-<?= e( $selectedPeriodStatusInfo['class'] ) ?> mb-2">
                                <?= e(
                                    $selectedPeriodStatusInfo['label']
                                ) ?>
                            </span>

                            <p class="small text-body-secondary mb-0">
                                Dicetak oleh
                                <?= e($currentUserName) ?>
                                <br>
                                <?= e(format_date_id(
                                    $printedAt,
                                    true
                                )) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="row row-cols-2 row-cols-md-3 row-cols-xl-6 g-3 mb-4" aria-label="Ringkasan laporan peringkat">
                <?php
                $summaryCards = [
                    [
                        'label' => 'Hasil Peringkat',
                        'value' => $summary['result_count'],
                        'note' => 'Dari '
                            . $expectedMachineCount
                            . ' motor',
                    ],
                    [
                        'label' => 'Tindakan Dicatat',
                        'value' => $summary['action_count'],
                        'note' => 'Rekomendasi tersedia',
                    ],
                    [
                        'label' => 'Belum Dicatat',
                        'value' => $summary['not_recorded_count'],
                        'note' => 'Menunggu Supervisor',
                    ],
                    [
                        'label' => 'Menunggu',
                        'value' => $summary['pending_count'],
                        'note' => 'Belum dikerjakan',
                    ],
                    [
                        'label' => 'Dikerjakan',
                        'value' => $summary['in_progress_count'],
                        'note' => 'Dalam proses',
                    ],
                    [
                        'label' => 'Selesai',
                        'value' => $summary['completed_count'],
                        'note' => 'Tindakan ditutup',
                    ],
                ];
                ?>

                <?php foreach ($summaryCards as $card): ?>
                    <div class="col">
                        <article class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <p class="small text-body-secondary mb-2">
                                    <?= e($card['label']) ?>
                                </p>

                                <p class="h3 fw-semibold mb-1">
                                    <?= e($card['value']) ?>
                                </p>

                                <small class="text-body-secondary">
                                    <?= e($card['note']) ?>
                                </small>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </section>

            <?php if (
                $dataValidationError !== null
            ): ?>
                <div class="alert alert-danger mb-4" role="alert">
                    <strong class="d-block mb-1">
                        Data laporan belum valid
                    </strong>

                    <?= e($dataValidationError) ?>
                </div>
            <?php elseif (!$reportAvailable): ?>
                <div class="alert alert-warning mb-4" role="status">
                    Hasil peringkat TOPSIS belum tersedia
                    secara lengkap pada periode ini.
                </div>
            <?php else: ?>
                <div class="alert alert-success mb-4" role="status">
                    Hasil peringkat A1 sampai A6 telah tersedia.
                    Nilai preferensi terbesar menjadi prioritas
                    pemeliharaan tertinggi.
                </div>
            <?php endif; ?>

            <?php if ($topPriority !== null): ?>
                <?php
                $topActionStatus = $actionStatusMeta(
                    isset($topPriority['action_status'])
                        ? (string) $topPriority['action_status']
                        : null,
                    $topPriority['action_id'] !== null
                );

                $topActionPriority = $actionPriorityMeta(
                    isset($topPriority['action_priority'])
                        ? (string) $topPriority['action_priority']
                        : null
                );
                ?>

                <section class="card border-0 shadow-sm mb-4 border-start border-4 border-danger" aria-labelledby="top-priority-title">
                    <div class="card-body p-4">
                        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4">
                            <div class="flex-grow-1">
                                <span class="badge text-bg-danger mb-2">
                                    Prioritas Pertama
                                </span>

                                <h2 id="top-priority-title" class="h4 mb-2">
                                    <?= e(
                                        $topPriority['alternative_code']
                                    ) ?>
                                    —
                                    <?= e(
                                        $topPriority['motor_name']
                                    ) ?>
                                </h2>

                                <p class="text-body-secondary mb-3">
                                    <?= e(
                                        $topPriority['machine_code']
                                    ) ?>
                                    /
                                    <?= e($motorFunctionLabel(
                                        isset(
                                            $topPriority['motor_function']
                                        )
                                            ? (string) $topPriority[
                                                'motor_function'
                                            ]
                                            : null
                                    )) ?>
                                    ·
                                    <?= e(
                                        $topPriority['location']
                                        ?? '-'
                                    ) ?>
                                </p>

                                <p class="mb-0">
                                    <strong>Rekomendasi:</strong>

                                    <?= nl2br(e(
                                        $topPriority['recommendation']
                                        ?? 'Belum ada rekomendasi.'
                                    )) ?>
                                </p>

                                <?php if (
                                    count($topPriorityRows) > 1
                                ): ?>
                                    <p class="small text-body-secondary mt-2 mb-0">
                                        Terdapat
                                        <?= e(
                                            count($topPriorityRows)
                                        ) ?>
                                        motor dengan nilai preferensi
                                        setara pada peringkat pertama.
                                    </p>
                                <?php endif; ?>
                            </div>

                            <div style="min-width: 260px;">
                                <div class="rounded-3 bg-body-tertiary border p-3">
                                    <small class="text-body-secondary d-block">
                                        Nilai Preferensi
                                    </small>

                                    <strong class="h4 d-block mb-3">
                                        <?= e($displayNumber(
                                            $topPriority[
                                                'preference_value'
                                            ],
                                            6
                                        )) ?>
                                    </strong>

                                    <small class="text-body-secondary d-block">
                                        Kelompok Prioritas
                                    </small>

                                    <strong class="d-block mb-3">
                                        <?= e(
                                            $topPriority['rank_group']
                                            ?? '-'
                                        ) ?>
                                    </strong>

                                    <small class="text-body-secondary d-block">
                                        Prioritas Tindakan
                                    </small>

                                    <span class="badge text-bg-<?= e( $topActionPriority['class'] ) ?> mb-3">
                                        <?= e(
                                            $topActionPriority['label']
                                        ) ?>
                                    </span>

                                    <small class="text-body-secondary d-block">
                                        Status
                                    </small>

                                    <span class="badge text-bg-<?= e( $topActionStatus['class'] ) ?>">
                                        <?= e(
                                            $topActionStatus['label']
                                        ) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="ranking-table-title">
                <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div>
                        <h2 id="ranking-table-title" class="h5 mb-1">
                            Urutan Prioritas Pemeliharaan
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Urutan motor berdasarkan nilai
                            preferensi hasil TOPSIS.
                        </p>
                    </div>

                    <span class="badge text-bg-light border">
                        <?= e(
                            $summary['result_count']
                        ) ?>
                        hasil
                    </span>
                </div>

                <div class="card-body p-0">
                    <?php if ($rankingRows === []): ?>
                        <div class="text-center text-body-secondary p-5">
                            Hasil peringkat belum tersedia.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0 report-table">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-center">
                                            Peringkat
                                        </th>

                                        <th>
                                            Motor
                                        </th>

                                        <th class="text-end">
                                            D+
                                        </th>

                                        <th class="text-end">
                                            D−
                                        </th>

                                        <th class="text-end">
                                            Nilai Preferensi
                                        </th>

                                        <th class="text-center">
                                            Kelompok
                                        </th>

                                        <th>
                                            Kategori Prioritas
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach (
                                        $rankingRows
                                        as $row
                                    ): ?>
                                        <?php
                                        $rankInfo = $rankingMeta(
                                            $row['rank_group']
                                            ?? null
                                        );
                                        ?>

                                        <tr>
                                            <td class="text-center">
                                                <?php if (
                                                    is_numeric(
                                                        $row['ranking']
                                                        ?? null
                                                    )
                                                ): ?>
                                                    <span class="badge text-bg-primary fs-6">
                                                        #<?= e(
                                                            (int) $row[
                                                                'ranking'
                                                            ]
                                                        ) ?>
                                                    </span>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <strong class="d-block">
                                                    <?= e(
                                                        $row[
                                                            'alternative_code'
                                                        ]
                                                    ) ?>
                                                    —
                                                    <?= e(
                                                        $row['motor_name']
                                                    ) ?>
                                                </strong>

                                                <small class="text-body-secondary d-block">
                                                    <?= e(
                                                        $row['machine_code']
                                                    ) ?>
                                                    /
                                                    <?= e($motorFunctionLabel(
                                                        isset(
                                                            $row[
                                                                'motor_function'
                                                            ]
                                                        )
                                                            ? (string) $row[
                                                                'motor_function'
                                                            ]
                                                            : null
                                                    )) ?>
                                                </small>

                                                <small class="text-body-secondary d-block">
                                                    <?= e(
                                                        $row['location']
                                                        ?? '-'
                                                    ) ?>
                                                </small>
                                            </td>

                                            <td class="text-end">
                                                <?= e($displayNumber(
                                                    $row[
                                                        'positive_distance'
                                                    ]
                                                    ?? null,
                                                    6
                                                )) ?>
                                            </td>

                                            <td class="text-end">
                                                <?= e($displayNumber(
                                                    $row[
                                                        'negative_distance'
                                                    ]
                                                    ?? null,
                                                    6
                                                )) ?>
                                            </td>

                                            <td class="text-end">
                                                <strong>
                                                    <?= e($displayNumber(
                                                        $row[
                                                            'preference_value'
                                                        ]
                                                        ?? null,
                                                        6
                                                    )) ?>
                                                </strong>
                                            </td>

                                            <td class="text-center">
                                                <?= e(
                                                    $row['rank_group']
                                                    ?? '-'
                                                ) ?>
                                            </td>

                                            <td>
                                                <span class="badge text-bg-<?= e( $rankInfo['class'] ) ?>">
                                                    <?= e(
                                                        $row[
                                                            'priority_label'
                                                        ]
                                                        ?? $rankInfo['label']
                                                    ) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="recommendation-table-title">
                <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div>
                        <h2 id="recommendation-table-title" class="h5 mb-1">
                            Rekomendasi Pemeliharaan
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Rekomendasi Supervisor berdasarkan
                            hasil peringkat TOPSIS.
                        </p>
                    </div>

                    <span class="badge text-bg-light border">
                        <?= e(
                            $summary['action_count']
                        ) ?>
                        rekomendasi
                    </span>
                </div>

                <div class="card-body p-0">
                    <?php if ($rankingRows === []): ?>
                        <div class="text-center text-body-secondary p-5">
                            Data rekomendasi belum tersedia.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0 report-table">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-center">
                                            Peringkat
                                        </th>

                                        <th>
                                            Motor
                                        </th>

                                        <th>
                                            Rekomendasi
                                        </th>

                                        <th>
                                            Prioritas Tindakan
                                        </th>

                                        <th>
                                            Target
                                        </th>

                                        <th>
                                            Dibuat oleh
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach (
                                        $rankingRows
                                        as $row
                                    ): ?>
                                        <?php
                                        $hasAction =
                                            $row['action_id']
                                            !== null;

                                        $priorityInfo =
                                            $actionPriorityMeta(
                                                isset(
                                                    $row[
                                                        'action_priority'
                                                    ]
                                                )
                                                    ? (string) $row[
                                                        'action_priority'
                                                    ]
                                                    : null
                                            );
                                        ?>

                                        <tr>
                                            <td class="text-center">
                                                <?= is_numeric(
                                                    $row['ranking']
                                                    ?? null
                                                )
                                                    ? '#'
                                                        . e(
                                                            (int) $row[
                                                                'ranking'
                                                            ]
                                                        )
                                                    : '-' ?>
                                            </td>

                                            <td>
                                                <strong>
                                                    <?= e(
                                                        $row[
                                                            'alternative_code'
                                                        ]
                                                    ) ?>
                                                </strong>

                                                <small class="d-block text-body-secondary">
                                                    <?= e(
                                                        $row['motor_name']
                                                    ) ?>
                                                </small>
                                            </td>

                                            <td>
                                                <?= nl2br(e(
                                                    $row['recommendation']
                                                    ?? 'Belum ada rekomendasi.'
                                                )) ?>
                                            </td>

                                            <td>
                                                <?php if ($hasAction): ?>
                                                    <span class="badge text-bg-<?= e( $priorityInfo[ 'class' ] ) ?>">
                                                        <?= e(
                                                            $priorityInfo[
                                                                'label'
                                                            ]
                                                        ) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge text-bg-secondary">
                                                        Belum Ditentukan
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <?= e(format_date_id(
                                                    $row['target_date']
                                                    ?? null
                                                )) ?>
                                            </td>

                                            <td>
                                                <?= e(
                                                    $row[
                                                        'action_creator_name'
                                                    ]
                                                    ?? '-'
                                                ) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="realization-table-title">
                <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div>
                        <h2 id="realization-table-title" class="h5 mb-1">
                            Realisasi Tindakan Pemeliharaan
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Pelaksana, tindakan, dan status penyelesaian.
                        </p>
                    </div>

                    <span class="badge text-bg-success">
                        <?= e(
                            $summary['completed_count']
                        ) ?>
                        selesai
                    </span>
                </div>

                <div class="card-body p-0">
                    <?php if ($rankingRows === []): ?>
                        <div class="text-center text-body-secondary p-5">
                            Data realisasi belum tersedia.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0 report-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>
                                            Motor
                                        </th>

                                        <th>
                                            Teknisi Pelaksana
                                        </th>

                                        <th>
                                            Tindakan
                                        </th>

                                        <th>
                                            Work Order
                                        </th>

                                        <th>
                                            Status
                                        </th>

                                        <th>
                                            Tanggal Selesai
                                        </th>

                                        <th>
                                            Catatan
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach (
                                        $rankingRows
                                        as $row
                                    ): ?>
                                        <?php
                                        $hasAction =
                                            $row['action_id']
                                            !== null;

                                        $statusInfo =
                                            $actionStatusMeta(
                                                isset(
                                                    $row[
                                                        'action_status'
                                                    ]
                                                )
                                                    ? (string) $row[
                                                        'action_status'
                                                    ]
                                                    : null,
                                                $hasAction
                                            );
                                        ?>

                                        <tr>
                                            <td>
                                                <strong>
                                                    <?= e(
                                                        $row[
                                                            'alternative_code'
                                                        ]
                                                    ) ?>
                                                </strong>

                                                <small class="d-block text-body-secondary">
                                                    <?= e(
                                                        $row['motor_name']
                                                    ) ?>
                                                </small>
                                            </td>

                                            <td>
                                                <?= e(
                                                    $row[
                                                        'performed_by_name'
                                                    ]
                                                    ?? 'Belum ditentukan'
                                                ) ?>
                                            </td>

                                            <td>
                                                <?= nl2br(e(
                                                    $row[
                                                        'action_description'
                                                    ]
                                                    ?? '-'
                                                )) ?>
                                            </td>

                                            <td>
                                                <?= e(
                                                    $row[
                                                        'work_order_reference'
                                                    ]
                                                    ?? '-'
                                                ) ?>
                                            </td>

                                            <td>
                                                <span class="badge text-bg-<?= e( $statusInfo['class'] ) ?>">
                                                    <?= e(
                                                        $statusInfo['label']
                                                    ) ?>
                                                </span>
                                            </td>

                                            <td>
                                                <?= e(format_date_id(
                                                    $row[
                                                        'completed_date'
                                                    ]
                                                    ?? null
                                                )) ?>
                                            </td>

                                            <td>
                                                <?= nl2br(e(
                                                    $row[
                                                        'additional_note'
                                                    ]
                                                    ?? '-'
                                                )) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="action-summary-title">
                <div class="card-header bg-white py-3">
                    <h2 id="action-summary-title" class="h5 mb-1">
                        Ringkasan Tindak Lanjut
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Distribusi prioritas dan status tindakan.
                    </p>
                </div>

                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h3 class="h6">
                                Berdasarkan Prioritas Tindakan
                            </h3>

                            <?php
                            $prioritySummary = [
                                [
                                    'label' => 'Segera',
                                    'class' => 'danger',
                                    'note' =>
                                        'Perlu segera ditindaklanjuti',
                                    'value' =>
                                        $summary['immediate_count'],
                                ],
                                [
                                    'label' => 'Terjadwal',
                                    'class' => 'warning',
                                    'note' =>
                                        'Masuk jadwal pemeliharaan',
                                    'value' =>
                                        $summary['scheduled_count'],
                                ],
                                [
                                    'label' => 'Monitoring',
                                    'class' => 'info',
                                    'note' =>
                                        'Dilakukan pemantauan',
                                    'value' =>
                                        $summary['monitoring_count'],
                                ],
                            ];
                            ?>

                            <?php foreach (
                                $prioritySummary
                                as $item
                            ): ?>
                                <div class="d-flex justify-content-between border-bottom py-2">
                                    <span>
                                        <span class="badge text-bg-<?= e( $item['class'] ) ?> me-2">
                                            <?= e($item['label']) ?>
                                        </span>

                                        <?= e($item['note']) ?>
                                    </span>

                                    <strong>
                                        <?= e($item['value']) ?>
                                    </strong>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="col-md-6">
                            <h3 class="h6">
                                Berdasarkan Status Pelaksanaan
                            </h3>

                            <?php
                            $statusSummary = [
                                [
                                    'label' => 'Belum Dicatat',
                                    'class' => 'secondary',
                                    'note' =>
                                        'Menunggu rekomendasi',
                                    'value' =>
                                        $summary['not_recorded_count'],
                                ],
                                [
                                    'label' => 'Menunggu',
                                    'class' => 'warning',
                                    'note' =>
                                        'Belum mulai dikerjakan',
                                    'value' =>
                                        $summary['pending_count'],
                                ],
                                [
                                    'label' => 'Dikerjakan',
                                    'class' => 'info',
                                    'note' =>
                                        'Sedang dalam proses',
                                    'value' =>
                                        $summary['in_progress_count'],
                                ],
                                [
                                    'label' => 'Selesai',
                                    'class' => 'success',
                                    'note' =>
                                        'Tindakan telah ditutup',
                                    'value' =>
                                        $summary['completed_count'],
                                ],
                            ];
                            ?>

                            <?php foreach (
                                $statusSummary
                                as $item
                            ): ?>
                                <div class="d-flex justify-content-between border-bottom py-2">
                                    <span>
                                        <span class="badge text-bg-<?= e( $item['class'] ) ?> me-2">
                                            <?= e($item['label']) ?>
                                        </span>

                                        <?= e($item['note']) ?>
                                    </span>

                                    <strong>
                                        <?= e($item['value']) ?>
                                    </strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="ranking-notes-title">
                <div class="card-header bg-white py-3">
                    <h2 id="ranking-notes-title" class="h5 mb-0">
                        Keterangan Laporan
                    </h2>
                </div>

                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h3 class="h6">
                                Dasar Penentuan Prioritas
                            </h3>

                            <p class="small mb-2">
                                Urutan prioritas diperoleh dari nilai
                                preferensi hasil perhitungan TOPSIS.
                            </p>

                            <p class="small mb-2">
                                Nilai preferensi yang lebih besar
                                menunjukkan urgensi pemeliharaan
                                yang lebih tinggi.
                            </p>

                            <p class="small mb-0">
                                Nilai yang sama memperoleh ranking
                                dan rank_group yang sama.
                            </p>
                        </div>

                        <div class="col-md-6">
                            <h3 class="h6">
                                Pembagian Tanggung Jawab
                            </h3>

                            <p class="small mb-2">
                                Supervisor menetapkan rekomendasi
                                serta prioritas tindakan.
                            </p>

                            <p class="small mb-2">
                                Teknisi yang ditugaskan mencatat
                                pelaksanaan pekerjaan.
                            </p>

                            <p class="small mb-0">
                                Administrator hanya melihat dan
                                mencetak laporan.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="d-none d-print-block mt-5" aria-label="Pengesahan laporan">
                <div class="row text-center">
                    <div class="col-6">
                        <p class="mb-5">
                            Dicetak oleh,
                            <br>
                            <?= e($currentRoleLabel) ?>
                        </p>

                        <p class="mb-0">
                            <strong>
                                <?= e($currentUserName) ?>
                            </strong>
                        </p>
                    </div>

                    <div class="col-6">
                        <p class="mb-5">
                            Mengetahui,
                            <br>
                            Supervisor Maintenance
                        </p>

                        <p class="mb-0">
                            <strong>
                                <?= e(
                                    $supervisorSignatureName
                                ) ?>
                            </strong>
                        </p>
                    </div>
                </div>
            </section>

            <div class="alert alert-light border mb-0 no-print" role="status">
                <strong>Catatan:</strong>
                perubahan rekomendasi dilakukan melalui modul
                Riwayat Pemeliharaan oleh Supervisor.
                Perubahan realisasi dilakukan oleh Teknisi
                yang ditugaskan.
            </div>
        <?php endif; ?>
    </main>
</div>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR . 'footer.php';

?>

<?php

declare(strict_types=1);

/*
 * Detail Periode Pemeriksaan Sistem SPK PT CISAS
 * Halaman ini dapat diakses oleh:
 * - Administrator;
 * - Supervisor Maintenance.
 * Fungsinya:
 * - menampilkan identitas periode;
 * - menampilkan progres pemeriksaan enam motor;
 * - menampilkan status validasi dan temuan checklist;
 * - menampilkan kesiapan AHP dan TOPSIS;
 * - menampilkan hasil peringkat apabila TOPSIS sudah diproses TOPSIS.
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'helpers'
    . DIRECTORY_SEPARATOR
    . 'auth.php';

// Batasi Akses

auth_require_role([
    'admin',
    'supervisor',
]);

// Koneksi Database

$pdo = require dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'config'
    . DIRECTORY_SEPARATOR
    . 'database.php';

// Pastikan Akun Masih Aktif

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun pengguna sudah tidak aktif atau tidak ditemukan.'
    );

    redirect('login.php');
}

$currentRole = auth_role();

$isAdmin = auth_is_admin();

$isSupervisor = $currentRole === 'supervisor';

// Validasi ID Periode

$periodId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if ($periodId === false) {
    $notFoundMessage =
        'ID periode tidak valid atau tidak tersedia.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '404.php';

    exit;
}

$periodId = (int) $periodId;

// Konfigurasi Penelitian

$expectedMachineCount = (int) app_config(
    'total_machines',
    6
);

$expectedCriteriaCount = (int) app_config(
    'total_criteria',
    5
);

$expectedComparisonCount = (int) (
    (
        $expectedCriteriaCount
        * ($expectedCriteriaCount - 1)
    ) / 2
);

// Nilai Awal

$period = null;

$machineRows = [];

$ahpSummary = null;

$ahpComparisonCount = 0;

$ahpWeightCount = 0;

$pageError = null;

// Ambil Data Periode dan Progres

try {
    // Data Periode

    $periodStatement = $pdo->prepare(
        "SELECT
            id,
            name,
            start_date,
            end_date,
            status,
            created_at,
            updated_at

         FROM assessment_periods

         WHERE id = :id

         LIMIT 1"
    );

    $periodStatement->execute([
        'id' => $periodId,
    ]);

    $periodData = $periodStatement->fetch();

    $period = is_array($periodData)
        ? $periodData
        : null;

    if ($period === null) {
        $notFoundMessage =
            'Data periode pemeriksaan tidak ditemukan.';

        require dirname(__DIR__)
            . DIRECTORY_SEPARATOR
            . '404.php';

        exit;
    }

    /*
 * Enam Motor dan Data Pemeriksaannya
 * LEFT JOIN digunakan agar motor yang belum diperiksa tetap ditampilkan.
 */

    $machineStatement = $pdo->prepare(
        "SELECT
            m.id AS machine_id,
            m.alternative_code,
            m.motor_name,
            m.status AS machine_status,

            a.id AS assessment_id,
            a.inspection_date,
            a.inspection_time,
            a.status AS assessment_status,

            u.name AS inspector_name,

            s.c1_value,
            s.c2_value,
            s.c3_value,
            s.c4_value,
            s.c5_value,

            s.c4_not_checked_count,
            s.c5_not_checked_count,
            s.c4_has_danger,
            s.c5_has_danger,

            tr.positive_distance,
            tr.negative_distance,
            tr.preference_value,
            tr.ranking,
            tr.rank_group,
            tr.priority_label

         FROM machines AS m

         LEFT JOIN assessments AS a
            ON a.machine_id = m.id
           AND a.period_id = :assessment_period_id

         LEFT JOIN users AS u
            ON u.id = a.inspector_id

         LEFT JOIN assessment_scores AS s
            ON s.assessment_id = a.id

         LEFT JOIN topsis_results AS tr
            ON tr.assessment_id = a.id
           AND tr.period_id = :topsis_period_id

         ORDER BY
            m.alternative_code COLLATE NOCASE,
            m.id"
    );

    $machineStatement->execute([
        'assessment_period_id' => $periodId,
        'topsis_period_id' => $periodId,
    ]);

    $machineRows = $machineStatement->fetchAll();

    // Jumlah Perbandingan AHP

    $ahpComparisonStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM ahp_comparisons
         WHERE period_id = :period_id'
    );

    $ahpComparisonStatement->execute([
        'period_id' => $periodId,
    ]);

    $ahpComparisonCount = (int)
        $ahpComparisonStatement->fetchColumn();

    // Jumlah Bobot AHP

    $ahpWeightStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM ahp_results
         WHERE period_id = :period_id'
    );

    $ahpWeightStatement->execute([
        'period_id' => $periodId,
    ]);

    $ahpWeightCount = (int)
        $ahpWeightStatement->fetchColumn();

    // Ringkasan Konsistensi AHP

    $ahpSummaryStatement = $pdo->prepare(
        "SELECT
            lambda_max,
            consistency_index,
            random_index,
            consistency_ratio,
            is_consistent,
            calculated_at

         FROM ahp_summaries

         WHERE period_id = :period_id

         LIMIT 1"
    );

    $ahpSummaryStatement->execute([
        'period_id' => $periodId,
    ]);

    $ahpSummaryData = $ahpSummaryStatement->fetch();

    $ahpSummary = is_array($ahpSummaryData)
        ? $ahpSummaryData
        : null;
} catch (Throwable $exception) {
    error_log(
        'Detail periode SPK PT CISAS gagal: '
        . $exception->getMessage()
    );

    $pageError =
        'Sebagian data periode belum dapat dimuat. '
        . 'Periksa kembali koneksi dan struktur database.';
}

// Konfigurasi Status Periode

$periodStatusConfigurations = [
    'draft' => [
        'label' => 'Draft',
        'class' => 'secondary',
        'description' =>
            'Periode masih disiapkan dan belum digunakan untuk pemeriksaan.',
    ],

    'active' => [
        'label' => 'Aktif',
        'class' => 'success',
        'description' =>
            'Periode sedang digunakan untuk pemeriksaan enam motor.',
    ],

    'completed' => [
        'label' => 'Selesai',
        'class' => 'primary',
        'description' =>
            'Periode telah ditutup dan tersimpan sebagai riwayat.',
    ],
];

$periodStatus = (string) (
    $period['status']
    ?? 'draft'
);

$periodStatusConfiguration =
    $periodStatusConfigurations[$periodStatus]
    ?? [
        'label' => 'Tidak diketahui',
        'class' => 'secondary',
        'description' =>
            'Status periode tidak dikenali.',
    ];

// Konfigurasi Status Pemeriksaan

$assessmentStatusConfigurations = [
    'draft' => [
        'label' => 'Draft',
        'class' => 'secondary',
    ],

    'completed' => [
        'label' => 'Menunggu Validasi',
        'class' => 'warning',
    ],

    'validated' => [
        'label' => 'Disetujui',
        'class' => 'success',
    ],

    'returned' => [
        'label' => 'Dikembalikan',
        'class' => 'danger',
    ],

    'processed' => [
        'label' => 'Diproses TOPSIS',
        'class' => 'success',
    ],
];

// Hitung Ringkasan Pemeriksaan

$assessmentCount = 0;

$draftAssessmentCount = 0;

$pendingValidationCount = 0;

$validatedAssessmentCount = 0;

$returnedAssessmentCount = 0;

$processedAssessmentCount = 0;

$completeScoreCount = 0;

$notCheckedFindingCount = 0;

$dangerMotorCount = 0;

$topsisResultCount = 0;

foreach ($machineRows as $row) {
    $assessmentId = (int) (
        $row['assessment_id']
        ?? 0
    );

    if ($assessmentId < 1) {
        continue;
    }

    $assessmentCount++;

    $assessmentStatus = (string) (
        $row['assessment_status']
        ?? 'draft'
    );

    if ($assessmentStatus === 'draft') {
        $draftAssessmentCount++;
    }

    if ($assessmentStatus === 'completed') {
        $pendingValidationCount++;
    }

    if (
        in_array(
            $assessmentStatus,
            [
                'validated',
                'processed',
            ],
            true
        )
    ) {
        $validatedAssessmentCount++;
    }

    if ($assessmentStatus === 'returned') {
        $returnedAssessmentCount++;
    }

    if ($assessmentStatus === 'processed') {
        $processedAssessmentCount++;
    }

    $scoresAreComplete = true;

    foreach (
        [
            'c1_value',
            'c2_value',
            'c3_value',
            'c4_value',
            'c5_value',
        ]
        as $scoreField
    ) {
        if (
            !array_key_exists($scoreField, $row)
            || $row[$scoreField] === null
            || $row[$scoreField] === ''
        ) {
            $scoresAreComplete = false;

            break;
        }
    }

    if ($scoresAreComplete) {
        $completeScoreCount++;
    }

    $notCheckedFindingCount +=
        (int) (
            $row['c4_not_checked_count']
            ?? 0
        )
        + (int) (
            $row['c5_not_checked_count']
            ?? 0
        );

    $hasDanger =
        (int) (
            $row['c4_has_danger']
            ?? 0
        ) === 1
        || (int) (
            $row['c5_has_danger']
            ?? 0
        ) === 1;

    if ($hasDanger) {
        $dangerMotorCount++;
    }

    if (
        $row['ranking'] !== null
        && $row['ranking'] !== ''
    ) {
        $topsisResultCount++;
    }
}

// Hitung Progres

$assessmentProgress = $expectedMachineCount > 0
    ? (int) round(
        (
            $assessmentCount
            / $expectedMachineCount
        ) * 100
    )
    : 0;

$assessmentProgress = max(
    0,
    min(
        100,
        $assessmentProgress
    )
);

$validationProgress = $expectedMachineCount > 0
    ? (int) round(
        (
            $validatedAssessmentCount
            / $expectedMachineCount
        ) * 100
    )
    : 0;

$validationProgress = max(
    0,
    min(
        100,
        $validationProgress
    )
);

// Kesiapan AHP

$ahpIsConsistent = $ahpSummary !== null
    && (int) (
        $ahpSummary['is_consistent']
        ?? 0
    ) === 1
    && floatval(
        $ahpSummary['consistency_ratio']
        ?? 1
    ) <= 0.10;

$ahpIsComplete =
    $ahpComparisonCount === $expectedComparisonCount
    && $ahpWeightCount === $expectedCriteriaCount
    && $ahpIsConsistent;

// Kesiapan TOPSIS

$assessmentsAreReady =
    $validatedAssessmentCount === $expectedMachineCount
    && $completeScoreCount === $expectedMachineCount
    && $notCheckedFindingCount === 0;

$topsisIsReady =
    $assessmentsAreReady
    && $ahpIsComplete;

$topsisIsComplete =
    $topsisResultCount === $expectedMachineCount;

// Urutkan Hasil TOPSIS

$rankingRows = array_values(
    array_filter(
        $machineRows,
        static function (array $row): bool {
            return $row['ranking'] !== null
                && $row['ranking'] !== '';
        }
    )
);

usort(
    $rankingRows,
    static function (
        array $first,
        array $second
    ): int {
        $rankingComparison =
            (int) $first['ranking']
            <=> (int) $second['ranking'];

        if ($rankingComparison !== 0) {
            return $rankingComparison;
        }

        return strnatcasecmp(
            (string) $first['alternative_code'],
            (string) $second['alternative_code']
        );
    }
);

// Fungsi Tampilan Angka

$displayNumber = static function (
    mixed $value,
    int $decimals = 4,
    string $suffix = ''
): string {
    if (
        $value === null
        || $value === ''
    ) {
        return '-';
    }

    $number = floatval($value);

    if (!is_finite($number)) {
        return '-';
    }

    return format_number_id(
        $number,
        $decimals
    ) . $suffix;
};

// Konfigurasi Halaman

$pageTitle =
    'Detail Periode '
    . (string) (
        $period['name']
        ?? ''
    );

$pageDescription =
    'Detail progres pemeriksaan, AHP, dan TOPSIS '
    . 'pada Sistem SPK PT CISAS.';

$bodyClass =
    'd-flex flex-column min-vh-100';

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'header.php';

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'navbar.php';
?>

<div class="d-lg-flex flex-grow-1">

    <?php
    require TEMPLATE_PATH
        . DIRECTORY_SEPARATOR
        . 'sidebar.php';
    ?>

    <main id="main-content" class="flex-grow-1 p-3 p-lg-4 overflow-hidden">
        <?php
        require TEMPLATE_PATH
            . DIRECTORY_SEPARATOR
            . 'alert.php';
        ?>

        <?php if ($pageError !== null): ?>
            <div class="alert alert-danger" role="alert">
                <strong class="d-block mb-1">
                    Data periode belum dapat dimuat lengkap
                </strong>

                <?= e($pageError) ?>
            </div>
        <?php endif; ?>

        <!-- Judul halaman -->
        <section class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3 mb-4">
            <div>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="badge text-bg-<?= e( $periodStatusConfiguration['class'] ) ?> p-2">
                        <?= e(
                            $periodStatusConfiguration['label']
                        ) ?>
                    </span>

                    <span class="badge text-bg-light border p-2">
                        ID Periode: <?= e($periodId) ?>
                    </span>
                </div>

                <h1 class="page-title">
                    <?= e(
                        $period['name']
                        ?? 'Periode Pemeriksaan'
                    ) ?>
                </h1>

                <p class="page-description">
                    <?= e(
                        format_date_id(
                            $period['start_date']
                            ?? null
                        )
                    ) ?>

                    sampai

                    <?= e(
                        format_date_id(
                            $period['end_date']
                            ?? null
                        )
                    ) ?>
                </p>
            </div>

            <div class="page-header-actions">
                <a href="<?= e( base_url( 'periode/index.php' ) ) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>

                <?php if (
                    $isAdmin
                    && $periodStatus !== 'completed'
                ): ?>
                    <a href="<?= e( base_url( 'periode/edit.php?id=' . $periodId ) ) ?>" class="btn btn-primary">
                        Ubah Periode
                    </a>
                <?php endif; ?>
            </div>
        </section>

        <!-- Informasi status periode -->
        <div class="alert alert-<?= e( $periodStatusConfiguration['class'] ) ?> mb-4" role="status">
            <?= e(
                $periodStatusConfiguration['description']
            ) ?>
        </div>

        <!-- Ringkasan utama -->
        <section class="row g-3 mb-4" aria-label="Ringkasan periode pemeriksaan">
            <div class="col-6 col-xl-3">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Pemeriksaan dibuat
                        </p>

                        <p class="display-6 fw-semibold mb-1">
                            <?= e($assessmentCount) ?>
                        </p>

                        <small class="text-body-secondary">
                            Dari <?= e($expectedMachineCount) ?> motor
                        </small>
                    </div>
                </article>
            </div>

            <div class="col-6 col-xl-3">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Disetujui
                        </p>

                        <p class="display-6 fw-semibold mb-1">
                            <?= e($validatedAssessmentCount) ?>
                        </p>

                        <small class="text-body-secondary">
                            Termasuk yang sudah diproses TOPSIS
                        </small>
                    </div>
                </article>
            </div>

            <div class="col-6 col-xl-3">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Menunggu validasi
                        </p>

                        <p class="display-6 fw-semibold mb-1">
                            <?= e($pendingValidationCount) ?>
                        </p>

                        <small class="text-body-secondary">
                            Dikirim oleh Teknisi
                        </small>
                    </div>
                </article>
            </div>

            <div class="col-6 col-xl-3">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Motor dengan Bahaya
                        </p>

                        <p class="display-6 fw-semibold mb-1">
                            <?= e($dangerMotorCount) ?>
                        </p>

                        <small class="text-body-secondary">
                            Temuan pada C4 atau C5
                        </small>
                    </div>
                </article>
            </div>
        </section>

        <div class="row g-4 mb-4">

            <!-- Progres pemeriksaan -->
            <div class="col-xl-7">
                <section class="card h-100 border-0 shadow-sm" aria-labelledby="period-progress-title">
                    <div class="card-body p-4">
                        <h2 id="period-progress-title" class="h5 mb-1">
                            Progres Pemeriksaan
                        </h2>

                        <p class="text-body-secondary mb-4">
                            Perkembangan pengisian dan validasi
                            terhadap alternatif A1 sampai A6.
                        </p>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between small mb-2">
                                <span>
                                    Pemeriksaan dibuat
                                </span>

                                <strong>
                                    <?= e($assessmentProgress) ?>%
                                </strong>
                            </div>

                            <div class="progress" role="progressbar" aria-label="Progres pembuatan pemeriksaan" aria-valuenow="<?= e( $assessmentProgress ) ?>" aria-valuemin="0" aria-valuemax="100" style="height: 14px;">
                                <div
                                    class="progress-bar"
                                    style="width: <?= e(
                                        $assessmentProgress
                                    ) ?>%;"
                                ></div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between small mb-2">
                                <span>
                                    Pemeriksaan disetujui
                                </span>

                                <strong>
                                    <?= e($validationProgress) ?>%
                                </strong>
                            </div>

                            <div class="progress" role="progressbar" aria-label="Progres validasi pemeriksaan" aria-valuenow="<?= e( $validationProgress ) ?>" aria-valuemin="0" aria-valuemax="100" style="height: 14px;">
                                <div
                                    class="progress-bar bg-success"
                                    style="width: <?= e(
                                        $validationProgress
                                    ) ?>%;"
                                ></div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-6 col-md-3">
                                <div class="metric-box">
                                    <p class="metric-label">
                                        Draft
                                    </p>

                                    <p class="metric-value">
                                        <?= e(
                                            $draftAssessmentCount
                                        ) ?>
                                    </p>
                                </div>
                            </div>

                            <div class="col-6 col-md-3">
                                <div class="metric-box">
                                    <p class="metric-label">
                                        Dikembalikan
                                    </p>

                                    <p class="metric-value">
                                        <?= e(
                                            $returnedAssessmentCount
                                        ) ?>
                                    </p>
                                </div>
                            </div>

                            <div class="col-6 col-md-3">
                                <div class="metric-box">
                                    <p class="metric-label">
                                        Nilai lengkap
                                    </p>

                                    <p class="metric-value">
                                        <?= e($completeScoreCount) ?>
                                    </p>
                                </div>
                            </div>

                            <div class="col-6 col-md-3">
                                <div class="metric-box">
                                    <p class="metric-label">
                                        Belum diisi
                                    </p>

                                    <p class="metric-value">
                                        <?= e(
                                            $notCheckedFindingCount
                                        ) ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Status A1-A6 mengisi card dengan informasi periode yang relevan -->
                        <div class="period-motor-status mt-4 pt-4 border-top">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                <div>
                                    <h3 class="h6 mb-1">Status A1–A6</h3>
                                    <small class="text-body-secondary">Status terakhir setiap motor pada periode ini.</small>
                                </div>
                                <a href="<?= e(base_url('pemeriksaan/index.php?period_id=' . $periodId . ($isSupervisor ? '&mode=validation' : ''))) ?>" class="small fw-semibold text-decoration-none">
                                    <?= $isSupervisor ? 'Buka Validasi' : 'Lihat Pemeriksaan' ?>
                                </a>
                            </div>

                            <div class="row g-2">
                                <?php foreach ($machineRows as $statusRow): ?>
                                    <?php
                                    $statusAssessmentId = (int) ($statusRow['assessment_id'] ?? 0);
                                    $statusKey = (string) ($statusRow['assessment_status'] ?? '');
                                    $statusConfig = $assessmentStatusConfigurations[$statusKey] ?? [
                                        'label' => $statusAssessmentId > 0 ? 'Tidak diketahui' : 'Belum diperiksa',
                                        'class' => 'secondary',
                                    ];
                                    ?>
                                    <div class="col-12 col-md-6">
                                        <div class="period-motor-status-item">
                                            <div class="min-w-0">
                                                <strong><?= e((string) ($statusRow['alternative_code'] ?? '-')) ?></strong>
                                                <small class="text-body-secondary text-truncate d-block"><?= e((string) ($statusRow['motor_name'] ?? '-')) ?></small>
                                            </div>
                                            <span class="badge text-bg-<?= e((string) $statusConfig['class']) ?> flex-shrink-0"><?= e((string) $statusConfig['label']) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Kesiapan SPK -->
            <div class="col-xl-5">
                <section class="card h-100 border-0 shadow-sm" aria-labelledby="spk-readiness-title">
                    <div class="card-body p-4">
                        <h2 id="spk-readiness-title" class="h5 mb-1">
                            Kesiapan AHP dan TOPSIS
                        </h2>

                        <p class="text-body-secondary mb-4">
                            Pemeriksaan kesiapan sebelum
                            pemeringkatan enam motor.
                        </p>

                        <!-- AHP -->
                        <div class="border rounded-3 p-3 mb-3">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                                <div>
                                    <strong class="d-block">
                                        Pembobotan AHP
                                    </strong>

                                    <small class="text-body-secondary">
                                        Satu Supervisor Maintenance
                                    </small>
                                </div>

                                <?php if ($ahpIsComplete): ?>
                                    <span class="badge text-bg-success">
                                        Lengkap dan Konsisten
                                    </span>
                                <?php elseif (
                                    $ahpSummary !== null
                                    && !$ahpIsConsistent
                                ): ?>
                                    <span class="badge text-bg-danger">
                                        Tidak Konsisten
                                    </span>
                                <?php else: ?>
                                    <span class="badge text-bg-warning">
                                        Belum Lengkap
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="small">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Perbandingan</span>

                                    <strong>
                                        <?= e(
                                            $ahpComparisonCount
                                        ) ?>
                                        /
                                        <?= e(
                                            $expectedComparisonCount
                                        ) ?>
                                    </strong>
                                </div>

                                <div class="d-flex justify-content-between mb-1">
                                    <span>Bobot kriteria</span>

                                    <strong>
                                        <?= e($ahpWeightCount) ?>
                                        /
                                        <?= e(
                                            $expectedCriteriaCount
                                        ) ?>
                                    </strong>
                                </div>

                                <div class="d-flex justify-content-between">
                                    <span>Rasio Konsistensi</span>

                                    <strong>
                                        <?= $ahpSummary !== null
                                            ? e(
                                                $displayNumber(
                                                    $ahpSummary[
                                                        'consistency_ratio'
                                                    ] ?? null,
                                                    4
                                                )
                                            )
                                            : '-' ?>
                                    </strong>
                                </div>
                            </div>
                        </div>

                        <!-- TOPSIS -->
                        <div class="border rounded-3 p-3">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                                <div>
                                    <strong class="d-block">
                                        Pemeringkatan TOPSIS
                                    </strong>

                                    <small class="text-body-secondary">
                                        Enam alternatif motor
                                    </small>
                                </div>

                                <?php if ($topsisIsComplete): ?>
                                    <span class="badge text-bg-primary">
                                        Selesai
                                    </span>
                                <?php elseif ($topsisIsReady): ?>
                                    <span class="badge text-bg-success">
                                        Siap Diproses
                                    </span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">
                                        Belum Siap
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="small">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Pemeriksaan disetujui</span>

                                    <strong>
                                        <?= e(
                                            $validatedAssessmentCount
                                        ) ?>
                                        /
                                        <?= e(
                                            $expectedMachineCount
                                        ) ?>
                                    </strong>
                                </div>

                                <div class="d-flex justify-content-between mb-1">
                                    <span>Nilai C1–C5 lengkap</span>

                                    <strong>
                                        <?= e($completeScoreCount) ?>
                                        /
                                        <?= e(
                                            $expectedMachineCount
                                        ) ?>
                                    </strong>
                                </div>

                                <div class="d-flex justify-content-between">
                                    <span>Hasil peringkat</span>

                                    <strong>
                                        <?= e($topsisResultCount) ?>
                                        /
                                        <?= e(
                                            $expectedMachineCount
                                        ) ?>
                                    </strong>
                                </div>
                            </div>
                        </div>

                        <?php if ($isSupervisor): ?>
                            <div class="d-grid gap-2 mt-3">
                                <a href="<?= e( base_url( 'pemeriksaan/index.php?period_id=' . $periodId ) ) ?>" class="btn btn-outline-primary">
                                    Periksa Data Pemeriksaan
                                </a>

                                <a href="<?= e( base_url( 'ahp/index.php?period_id=' . $periodId ) ) ?>" class="btn btn-outline-primary">
                                    Buka Pembobotan AHP
                                </a>

                                <a href="<?= e( base_url( 'topsis/index.php?period_id=' . $periodId ) ) ?>" class="btn btn-outline-primary">
                                    Buka Pemeringkatan TOPSIS
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

        </div>

        <!-- Daftar motor dan pemeriksaan -->
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="period-assessment-list-title">
            <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div>
                    <h2 id="period-assessment-list-title" class="h5 mb-1">
                        Status Pemeriksaan Enam Motor
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Ringkasan nilai C1 sampai C5
                        pada setiap alternatif.
                    </p>
                </div>

                <span class="badge text-bg-light border">
                    <?= e(count($machineRows)) ?> motor tersimpan
                </span>
            </div>

            <div class="card-body p-0">
                <?php if ($machineRows === []): ?>
                    <div class="table-empty-state" role="status">
                        Data motor belum tersedia.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Motor</th>
                                    <th scope="col">Pemeriksa</th>
                                    <th scope="col">Tanggal</th>
                                    <th scope="col">Status</th>

                                    <th scope="col" class="text-center">
                                        C1
                                    </th>

                                    <th scope="col" class="text-center">
                                        C2
                                    </th>

                                    <th scope="col" class="text-center">
                                        C3
                                    </th>

                                    <th scope="col" class="text-center">
                                        C4
                                    </th>

                                    <th scope="col" class="text-center">
                                        C5
                                    </th>

                                    <th scope="col" class="text-center">
                                        Kelengkapan
                                    </th>

                                    <th scope="col" class="text-end">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach (
                                    $machineRows
                                    as $row
                                ): ?>
                                    <?php
                                    $assessmentId = (int) (
                                        $row['assessment_id']
                                        ?? 0
                                    );

                                    $assessmentStatus = (string) (
                                        $row['assessment_status']
                                        ?? ''
                                    );

                                    $statusConfiguration =
                                        $assessmentStatusConfigurations[
                                            $assessmentStatus
                                        ] ?? [
                                            'label' =>
                                                $assessmentId > 0
                                                    ? 'Tidak diketahui'
                                                    : 'Belum diperiksa',

                                            'class' => 'secondary',
                                        ];

                                    $rowNotCheckedCount =
                                        (int) (
                                            $row[
                                                'c4_not_checked_count'
                                            ] ?? 0
                                        )
                                        + (int) (
                                            $row[
                                                'c5_not_checked_count'
                                            ] ?? 0
                                        );

                                    $rowHasDanger =
                                        (int) (
                                            $row['c4_has_danger']
                                            ?? 0
                                        ) === 1
                                        || (int) (
                                            $row['c5_has_danger']
                                            ?? 0
                                        ) === 1;
                                    ?>

                                    <tr>
                                        <td>
                                            <strong class="d-block">
                                                <?= e(
                                                    $row[
                                                        'alternative_code'
                                                    ]
                                                    ?? '-'
                                                ) ?>
                                                —
                                                <?= e(
                                                    $row['motor_name']
                                                    ?? '-'
                                                ) ?>
                                            </strong>

                                            <?php if ($rowHasDanger): ?>
                                                <small class="text-danger">
                                                    Ada temuan Bahaya
                                                </small>
                                            <?php else: ?>
                                                <small class="text-body-secondary">
                                                    <?=
                                                    (string)
                                                    (
                                                        $row[
                                                            'machine_status'
                                                        ] ?? ''
                                                    ) === 'active'
                                                        ? 'Motor aktif'
                                                        : 'Motor tidak aktif' ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                $row['inspector_name']
                                                ?? '-'
                                            ) ?>
                                        </td>

                                        <td>
                                            <?php if (
                                                $assessmentId > 0
                                            ): ?>
                                                <?= e(
                                                    format_date_id(
                                                        $row[
                                                            'inspection_date'
                                                        ] ?? null
                                                    )
                                                ) ?>

                                                <small class="d-block text-body-secondary">
                                                    <?= e(
                                                        substr(
                                                            (string) (
                                                                $row[
                                                                    'inspection_time'
                                                                ] ?? ''
                                                            ),
                                                            0,
                                                            5
                                                        )
                                                    ) ?>
                                                </small>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <span class="badge text-bg-<?= e( $statusConfiguration[ 'class' ] ) ?>">
                                                <?= e(
                                                    $statusConfiguration[
                                                        'label'
                                                    ]
                                                ) ?>
                                            </span>
                                        </td>

                                        <td class="text-center">
                                            <?= e(
                                                $displayNumber(
                                                    $row['c1_value']
                                                    ?? null,
                                                    2
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-center">
                                            <?= e(
                                                $displayNumber(
                                                    $row['c2_value']
                                                    ?? null,
                                                    2
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-center">
                                            <?= e(
                                                $displayNumber(
                                                    $row['c3_value']
                                                    ?? null,
                                                    2
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-center">
                                            <?= e(
                                                $displayNumber(
                                                    $row['c4_value']
                                                    ?? null,
                                                    2
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-center">
                                            <?= e(
                                                $displayNumber(
                                                    $row['c5_value']
                                                    ?? null,
                                                    2
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-center">
                                            <?php if (
                                                $rowNotCheckedCount > 0
                                            ): ?>
                                                <span class="badge text-bg-warning">
                                                    Belum lengkap
                                                </span>
                                            <?php else: ?>
                                                <span class="badge text-bg-success">
                                                    Lengkap
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-end">
                                            <?php if (
                                                $assessmentId > 0
                                            ): ?>
                                                <a href="<?= e( base_url( 'pemeriksaan/detail.php?id=' . $assessmentId ) ) ?>" class="btn btn-sm btn-outline-secondary">
                                                    Lihat
                                                </a>
                                            <?php else: ?>
                                                <span class="text-body-secondary">
                                                    -
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Hasil TOPSIS -->
        <section class="card border-0 shadow-sm" aria-labelledby="period-ranking-title">
            <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div>
                    <h2 id="period-ranking-title" class="h5 mb-1">
                        Hasil Prioritas TOPSIS
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Urutan motor berdasarkan nilai preferensi.
                    </p>
                </div>

                <?php if ($topsisIsComplete): ?>
                    <span class="badge text-bg-primary">
                        Pemeringkatan selesai
                    </span>
                <?php endif; ?>
            </div>

            <div class="card-body p-0">
                <?php if ($rankingRows === []): ?>
                    <div class="table-empty-state" role="status">
                        <h3 class="h6 mb-2">
                            Hasil TOPSIS belum tersedia
                        </h3>

                        <p class="mb-0">
                            Lengkapi validasi enam pemeriksaan,
                            pastikan AHP konsisten, kemudian
                            jalankan proses TOPSIS.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" class="text-center">
                                        Peringkat
                                    </th>

                                    <th scope="col">
                                        Alternatif
                                    </th>

                                    <th scope="col" class="text-end">
                                        D+
                                    </th>

                                    <th scope="col" class="text-end">
                                        D−
                                    </th>

                                    <th scope="col" class="text-end">
                                        Nilai Preferensi
                                    </th>

                                    <th scope="col">
                                        Prioritas
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach (
                                    $rankingRows
                                    as $row
                                ): ?>
                                    <?php
                                    $ranking = (int) (
                                        $row['ranking']
                                        ?? 0
                                    );
                                    ?>

                                    <tr>
                                        <td class="text-center">
                                            <span class="ranking-number <?= $ranking <= 3 ? 'rank-' . $ranking : '' ?>">
                                                <?= e($ranking) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <strong class="d-block">
                                                <?= e(
                                                    $row[
                                                        'alternative_code'
                                                    ]
                                                    ?? '-'
                                                ) ?>
                                            </strong>

                                            <small class="text-body-secondary">
                                                <?= e(
                                                    $row['motor_name']
                                                    ?? '-'
                                                ) ?>
                                            </small>
                                        </td>

                                        <td class="text-end">
                                            <?= e(
                                                $displayNumber(
                                                    $row[
                                                        'positive_distance'
                                                    ] ?? null,
                                                    6
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-end">
                                            <?= e(
                                                $displayNumber(
                                                    $row[
                                                        'negative_distance'
                                                    ] ?? null,
                                                    6
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-end fw-semibold">
                                            <?= e(
                                                $displayNumber(
                                                    $row[
                                                        'preference_value'
                                                    ] ?? null,
                                                    6
                                                )
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="badge text-bg-primary">
                                                <?= e(
                                                    $row['priority_label']
                                                    ?? (
                                                        'Prioritas '
                                                        . $ranking
                                                    )
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

        <p class="page-meta-card mt-3 mb-0">
            Periode dibuat:

            <?= e(
                format_date_id(
                    $period['created_at']
                    ?? null,
                    true
                )
            ) ?>

            &middot;

            terakhir diperbarui:

            <?= e(
                format_date_id(
                    $period['updated_at']
                    ?? null,
                    true
                )
            ) ?>

            &middot;

            pemeriksaan diproses TOPSIS:

            <?= e($processedAssessmentCount) ?>
        </p>
    </main>
</div>

<?php
require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';
?>

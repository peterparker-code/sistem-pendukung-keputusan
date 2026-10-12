<?php

declare(strict_types=1);

/*
 * Halaman Utama TOPSIS
 * SPK Pemeliharaan Motor Mesin Mixer PT CISAS
 * Fungsi:
 * - Memilih periode pemeriksaan.
 * - Memeriksa kesiapan data TOPSIS.
 * - Memeriksa konsistensi AHP.
 * - Memeriksa kelengkapan 5 bobot kriteria.
 * - Memeriksa 6 pemeriksaan motor.
 * - Memeriksa nilai C1 sampai C5.
 * - Menampilkan status hasil TOPSIS.
 * Hak akses:
 * Administrator:
 * - Melihat kesiapan TOPSIS.
 * - Melihat hasil TOPSIS.
 * Supervisor:
 * - Melihat kesiapan TOPSIS.
 * - Menjalankan perhitungan TOPSIS.
 * - Melihat hasil TOPSIS.
 * TOPSIS menggunakan:
 * Alternatif:
 * A1 sampai A6.
 * Kriteria:
 * C1 = Suhu Permukaan
 * C2 = Persentase Arus
 * C3 = Deviasi RPM
 * C4 = Kondisi Mekanikal
 * C5 = Kondisi Elektrikal
 * Bobot:
 * Hasil pembobotan AHP Supervisor Maintenance.
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'helpers'
    . DIRECTORY_SEPARATOR
    . 'auth.php';

// Hak Akses

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

// Informasi Pengguna

$currentRole =
    auth_role();

$isAdmin =
    $currentRole === 'admin';

$isSupervisor =
    $currentRole === 'supervisor';

// Konfigurasi Penelitian

$expectedMachineCount =
    (int) app_config(
        'total_machines',
        6
    );

$expectedCriteriaCount =
    (int) app_config(
        'total_criteria',
        5
    );

// Kode Alternatif dan Kriteria Final

$expectedAlternativeCodes = [
    'A1',
    'A2',
    'A3',
    'A4',
    'A5',
    'A6',
];

$expectedCriterionCodes = [
    'C1',
    'C2',
    'C3',
    'C4',
    'C5',
];

// Nilai Awal

$periods = [];

$selectedPeriod = null;

$assessmentRows = [];

$weightRows = [];

$ahpSummary = null;

$topsisRows = [];

$pageError = null;

// Ambil Daftar Periode

try {
    $periodStatement =
        $pdo->query(
            "SELECT
                id,
                name,
                start_date,
                end_date,
                status

             FROM assessment_periods

             ORDER BY
                CASE status
                    WHEN 'active' THEN 1
                    WHEN 'draft' THEN 2
                    WHEN 'completed' THEN 3
                    ELSE 4
                END,

                start_date DESC,
                id DESC"
        );

    $periods =
        $periodStatement->fetchAll();
} catch (Throwable $exception) {
    error_log(
        'Daftar periode TOPSIS gagal: '
        . $exception->getMessage()
    );

    $pageError =
        'Daftar periode belum dapat dimuat.';
}

// Tentukan Periode yang Dipilih

$periodIdInput =
    $_GET['period_id']
    ?? null;

if (
    $periodIdInput !== null
    && $periodIdInput !== ''
) {
    $validatedPeriodId =
        filter_var(
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
            'ID periode TOPSIS tidak valid.';

        require dirname(__DIR__)
            . DIRECTORY_SEPARATOR
            . '404.php';

        exit;
    }

    foreach (
        $periods
        as $period
    ) {
        if (
            (int) $period['id']
            === (int) $validatedPeriodId
        ) {
            $selectedPeriod =
                $period;

            break;
        }
    }

    if (
        $selectedPeriod === null
        && $periods !== []
    ) {
        $notFoundMessage =
            'Periode TOPSIS yang dipilih tidak ditemukan.';

        require dirname(__DIR__)
            . DIRECTORY_SEPARATOR
            . '404.php';

        exit;
    }
} elseif ($periods !== []) {
    // Prioritaskan Periode Aktif

    foreach (
        $periods
        as $period
    ) {
        if (
            (string) (
                $period['status']
                ?? ''
            ) === 'active'
        ) {
            $selectedPeriod =
                $period;

            break;
        }
    }

    // Jika Tidak Ada Periode Aktif

    $selectedPeriod ??=
        $periods[0];
}

// Informasi Periode Terpilih

$selectedPeriodId =
    $selectedPeriod !== null
        ? (int) $selectedPeriod['id']
        : null;

$selectedPeriodStatus =
    $selectedPeriod !== null
        ? (string) (
            $selectedPeriod['status']
            ?? 'draft'
        )
        : null;

// Ringkasan

$assessmentCount = 0;

$validatedAssessmentCount = 0;

$completeScoreCount = 0;

$ahpWeightCount = 0;

$topsisResultCount = 0;

// Ambil Data untuk Pemeriksaan Kesiapan TOPSIS

if ($selectedPeriodId !== null) {
    try {
        // Data Pemeriksaan A1 sampai A6

        $assessmentStatement =
            $pdo->prepare(
                "SELECT
                    m.id AS machine_id,
                    m.alternative_code,
                    m.motor_name,
                    m.status AS machine_status,

                    a.id AS assessment_id,
                    a.status AS assessment_status,
                    a.inspection_date,

                    s.c1_value,
                    s.c2_value,
                    s.c3_value,
                    s.c4_value,
                    s.c5_value,
                    s.c4_not_checked_count,
                    s.c5_not_checked_count

                 FROM machines AS m

                 LEFT JOIN assessments AS a
                    ON a.machine_id = m.id
                   AND a.period_id = :period_id

                 LEFT JOIN assessment_scores AS s
                    ON s.assessment_id = a.id

                 WHERE m.alternative_code IN (
                    'A1',
                    'A2',
                    'A3',
                    'A4',
                    'A5',
                    'A6'
                 )

                 ORDER BY
                    m.alternative_code COLLATE NOCASE,
                    m.id"
            );

        $assessmentStatement->execute([
            'period_id' =>
                $selectedPeriodId,
        ]);

        $assessmentRows =
            $assessmentStatement->fetchAll();

        // Bobot AHP

        $weightStatement =
            $pdo->prepare(
                "SELECT
                    ar.criterion_id,
                    ar.weight,

                    c.code,
                    c.name,
                    c.display_order

                 FROM ahp_results AS ar

                 INNER JOIN criteria AS c
                    ON c.id = ar.criterion_id

                 WHERE ar.period_id = :period_id
                   AND c.is_active = 1
                   AND c.code IN (
                       'C1',
                       'C2',
                       'C3',
                       'C4',
                       'C5'
                   )

                 ORDER BY
                    c.display_order ASC,
                    c.id ASC"
            );

        $weightStatement->execute([
            'period_id' =>
                $selectedPeriodId,
        ]);

        $weightRows =
            $weightStatement->fetchAll();

        // Ringkasan Konsistensi AHP

        $summaryStatement =
            $pdo->prepare(
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

        $summaryStatement->execute([
            'period_id' =>
                $selectedPeriodId,
        ]);

        $summaryData =
            $summaryStatement->fetch();

        $ahpSummary =
            is_array($summaryData)
                ? $summaryData
                : null;

        // Hasil TOPSIS

        $topsisStatement =
            $pdo->prepare(
                "SELECT
                    tr.assessment_id,
                    tr.positive_distance,
                    tr.negative_distance,
                    tr.preference_value,
                    tr.ranking,
                    tr.rank_group,
                    tr.priority_label,
                    tr.calculated_at,

                    m.alternative_code,
                    m.motor_name

                 FROM topsis_results AS tr

                 INNER JOIN assessments AS a
                    ON a.id = tr.assessment_id

                 INNER JOIN machines AS m
                    ON m.id = a.machine_id

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
                    tr.ranking ASC,
                    tr.preference_value DESC,
                    m.alternative_code COLLATE NOCASE"
            );

        $topsisStatement->execute([
            'period_id' =>
                $selectedPeriodId,
        ]);

        $topsisRows =
            $topsisStatement->fetchAll();
    } catch (Throwable $exception) {
        error_log(
            'Data kesiapan TOPSIS gagal: '
            . $exception->getMessage()
        );

        $pageError =
            'Data kesiapan TOPSIS belum dapat dimuat dengan lengkap.';
    }
}

/*
 * Hitung Kelengkapan Pemeriksaan
 * Kesiapan TOPSIS tidak cukup hanya menghitung jumlah baris.
 * Sistem harus memastikan:
 * - alternatif tepat A1 sampai A6;
 * - seluruh pemeriksaan sudah disetujui;
 * - nilai C1 sampai C5 numerik dan tidak negatif;
 * - tidak terdapat checklist Belum Diperiksa;
 * - bobot AHP tepat C1 sampai C5;
 * - jumlah bobot AHP sama dengan 1;
 * - AHP konsisten dengan CR tidak lebih dari 0,10;
 * - hasil TOPSIS tersimpan secara lengkap dan valid.
 */

$assessmentCodes = [];

$validatedAssessmentCodes = [];

$completeScoreCodes = [];

$bpFreeAssessmentCodes = [];

foreach (
    $assessmentRows
    as $assessmentRow
) {
    $alternativeCode = strtoupper(
        trim(
            (string) (
                $assessmentRow[
                    'alternative_code'
                ] ?? ''
            )
        )
    );

    if (
        !in_array(
            $alternativeCode,
            $expectedAlternativeCodes,
            true
        )
    ) {
        continue;
    }

    $assessmentId = (int) (
        $assessmentRow[
            'assessment_id'
        ] ?? 0
    );

    if ($assessmentId < 1) {
        continue;
    }

    $assessmentCodes[
        $alternativeCode
    ] = true;

    $assessmentStatus = (string) (
        $assessmentRow[
            'assessment_status'
        ] ?? ''
    );

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
        $validatedAssessmentCodes[
            $alternativeCode
        ] = true;
    }

    $hasNoUncheckedChecklist =
        (int) (
            $assessmentRow[
                'c4_not_checked_count'
            ] ?? 0
        ) === 0

        && (int) (
            $assessmentRow[
                'c5_not_checked_count'
            ] ?? 0
        ) === 0;

    if ($hasNoUncheckedChecklist) {
        $bpFreeAssessmentCodes[
            $alternativeCode
        ] = true;
    }

    $scoreComplete = true;

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
        $scoreValue =
            $assessmentRow[
                $scoreField
            ] ?? null;

        if (
            $scoreValue === null
            || $scoreValue === ''
            || !is_numeric(
                (string) $scoreValue
            )
            || !is_finite(
                floatval($scoreValue)
            )
            || floatval($scoreValue) < 0
        ) {
            $scoreComplete = false;

            break;
        }
    }

    if (
        $scoreComplete
        && $hasNoUncheckedChecklist
    ) {
        $completeScoreCodes[
            $alternativeCode
        ] = true;
    }
}

$assessmentCount =
    count(
        $assessmentCodes
    );

$validatedAssessmentCount =
    count(
        $validatedAssessmentCodes
    );

$completeScoreCount =
    count(
        $completeScoreCodes
    );

$bpFreeAssessmentCount =
    count(
        $bpFreeAssessmentCodes
    );

// Periksa Bobot AHP C1 sampai C5

$weightsByCode = [];

$weightSum = 0.0;

foreach (
    $weightRows
    as $weightRow
) {
    $criterionCode = strtoupper(
        trim(
            (string) (
                $weightRow[
                    'code'
                ] ?? ''
            )
        )
    );

    $weightValue =
        $weightRow[
            'weight'
        ] ?? null;

    if (
        !in_array(
            $criterionCode,
            $expectedCriterionCodes,
            true
        )
    ) {
        continue;
    }

    if (
        $weightValue === null
        || $weightValue === ''
        || !is_numeric(
            (string) $weightValue
        )
    ) {
        continue;
    }

    $numericWeight =
        floatval(
            $weightValue
        );

    if (
        !is_finite(
            $numericWeight
        )
        || $numericWeight <= 0
    ) {
        continue;
    }

    $weightsByCode[
        $criterionCode
    ] = $numericWeight;
}

foreach (
    $expectedCriterionCodes
    as $criterionCode
) {
    if (
        array_key_exists(
            $criterionCode,
            $weightsByCode
        )
    ) {
        $weightSum +=
            $weightsByCode[
                $criterionCode
            ];
    }
}

$ahpWeightCount =
    count(
        $weightsByCode
    );

$weightSumValid =
    abs(
        $weightSum - 1.0
    ) <= 0.0001;

$ahpWeightsComplete =
    $ahpWeightCount
        === $expectedCriteriaCount

    && count(
        array_diff(
            $expectedCriterionCodes,
            array_keys(
                $weightsByCode
            )
        )
    ) === 0

    && $weightSumValid;

// Konsistensi AHP

$consistencyRatio =
    $ahpSummary !== null

    && (
        $ahpSummary[
            'consistency_ratio'
        ] ?? null
    ) !== null

    && is_numeric(
        (string) $ahpSummary[
            'consistency_ratio'
        ]
    )

        ? floatval(
            $ahpSummary[
                'consistency_ratio'
            ]
        )

        : null;

$ahpIsConsistent =
    $ahpSummary !== null

    && (int) (
        $ahpSummary[
            'is_consistent'
        ] ?? 0
    ) === 1

    && $consistencyRatio !== null

    && is_finite(
        $consistencyRatio
    )

    && $consistencyRatio >= 0

    && $consistencyRatio <= 0.10;

$ahpReady =
    $ahpIsConsistent
    && $ahpWeightsComplete;

$assessmentsComplete =
    $assessmentCount
        === $expectedMachineCount

    && count(
        array_diff(
            $expectedAlternativeCodes,
            array_keys(
                $assessmentCodes
            )
        )
    ) === 0;

$validationsComplete =
    $validatedAssessmentCount
        === $expectedMachineCount

    && count(
        array_diff(
            $expectedAlternativeCodes,
            array_keys(
                $validatedAssessmentCodes
            )
        )
    ) === 0;

$bpFreeComplete =
    $bpFreeAssessmentCount
        === $expectedMachineCount

    && count(
        array_diff(
            $expectedAlternativeCodes,
            array_keys(
                $bpFreeAssessmentCodes
            )
        )
    ) === 0;

$scoresComplete =
    $completeScoreCount
        === $expectedMachineCount

    && count(
        array_diff(
            $expectedAlternativeCodes,
            array_keys(
                $completeScoreCodes
            )
        )
    ) === 0;

// Periksa Hasil TOPSIS yang Tersimpan

$topsisResultCodes = [];

$topsisResultsValid = true;

foreach (
    $topsisRows
    as $topsisRow
) {
    $alternativeCode = strtoupper(
        trim(
            (string) (
                $topsisRow[
                    'alternative_code'
                ] ?? ''
            )
        )
    );

    if (
        !in_array(
            $alternativeCode,
            $expectedAlternativeCodes,
            true
        )
    ) {
        $topsisResultsValid = false;

        continue;
    }

    foreach (
        [
            'positive_distance',
            'negative_distance',
            'preference_value',
        ]
        as $numericField
    ) {
        $numericValue =
            $topsisRow[
                $numericField
            ] ?? null;

        if (
            $numericValue === null
            || $numericValue === ''
            || !is_numeric(
                (string) $numericValue
            )
            || !is_finite(
                floatval($numericValue)
            )
            || floatval($numericValue) < 0
        ) {
            $topsisResultsValid = false;

            break;
        }
    }

    if (
        (int) (
            $topsisRow[
                'ranking'
            ] ?? 0
        ) < 1

        || (int) (
            $topsisRow[
                'rank_group'
            ] ?? 0
        ) < 1
    ) {
        $topsisResultsValid = false;
    }

    $topsisResultCodes[
        $alternativeCode
    ] = true;
}

$topsisResultCount =
    count(
        $topsisResultCodes
    );

$topsisReady =
    $pageError === null

    && $selectedPeriodStatus
        === 'active'

    && $ahpReady

    && $assessmentsComplete

    && $validationsComplete

    && $bpFreeComplete

    && $scoresComplete;

$topsisComplete =
    $topsisResultsValid

    && $topsisResultCount
        === $expectedMachineCount

    && count(
        array_diff(
            $expectedAlternativeCodes,
            array_keys(
                $topsisResultCodes
            )
        )
    ) === 0;

$canProcessTopsis =
    $isSupervisor
    && $topsisReady;

// Progress Pemeriksaan

$assessmentProgress =
    $expectedMachineCount > 0
        ? (int) round(
            (
                $validatedAssessmentCount
                / $expectedMachineCount
            ) * 100
        )
        : 0;

$assessmentProgress =
    max(
        0,
        min(
            100,
            $assessmentProgress
        )
    );

// Status TOPSIS

if ($topsisComplete) {
    $topsisStatus = [
        'label' =>
            'Selesai',

        'class' =>
            'primary',

        'description' =>
            'Pemeringkatan enam alternatif motor telah tersedia.',
    ];
} elseif (
    $selectedPeriodStatus !== 'active'
) {
    $topsisStatus = [
        'label' =>
            'Tidak Dapat Diproses',

        'class' =>
            'secondary',

        'description' =>
            'Perhitungan atau perhitungan ulang TOPSIS hanya '
            . 'dapat dilakukan pada periode Aktif.',
    ];
} elseif ($topsisReady) {
    $topsisStatus = [
        'label' =>
            'Siap Diproses',

        'class' =>
            'success',

        'description' =>
            'Seluruh persyaratan TOPSIS telah terpenuhi.',
    ];
} else {
    $topsisStatus = [
        'label' =>
            'Belum Siap',

        'class' =>
            'warning',

        'description' =>
            'Lengkapi bobot AHP, validasi A1 sampai A6, '
            . 'nilai C1 sampai C5, dan lengkapi seluruh checklist terlebih dahulu.',
    ];
}

// Status Periode

$periodStatusConfigurations = [
    'draft' => [
        'label' =>
            'Draft',

        'class' =>
            'secondary',
    ],

    'active' => [
        'label' =>
            'Aktif',

        'class' =>
            'success',
    ],

    'completed' => [
        'label' =>
            'Selesai',

        'class' =>
            'primary',
    ],
];

$selectedPeriodStatusConfiguration =
    $selectedPeriodStatus !== null
        ? (
            $periodStatusConfigurations[
                $selectedPeriodStatus
            ] ?? [
                'label' =>
                    'Tidak diketahui',

                'class' =>
                    'secondary',
            ]
        )
        : null;

// Helper Angka

$displayNumber =
    static function (
        mixed $value,
        int $decimals = 4
    ): string {
        if (
            $value === null
            || $value === ''
        ) {
            return '-';
        }

        $number =
            floatval($value);

        if (!is_finite($number)) {
            return '-';
        }

        return format_number_id(
            $number,
            $decimals
        );
    };

// Konfigurasi Halaman

$pageTitle =
    'Pemeringkatan TOPSIS';

$pageDescription =
    'Pemeringkatan prioritas pemeliharaan '
    . 'enam motor mesin mixer menggunakan TOPSIS.';

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

        <?php if (
            $pageError !== null
        ): ?>

            <div class="alert alert-danger" role="alert">
                <strong class="d-block mb-1">
                    Data TOPSIS belum dapat dimuat
                </strong>

                <?= e(
                    $pageError
                ) ?>
            </div>

        <?php endif; ?>

        <!-- =========================================================
             JUDUL
             ========================================================= -->

        <section class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3 mb-4">

            <div>

                <h1 class="page-title">
                    Pemeringkatan TOPSIS
                </h1>

                <p class="page-description">
                    Menentukan urutan prioritas
                    pemeliharaan alternatif A1 sampai A6
                    menggunakan bobot hasil AHP.
                </p>

            </div>

            <?php if (
                $selectedPeriodId !== null
            ): ?>

                <div class="page-header-actions topsis-header-actions">

                    <?php if (
                        $canProcessTopsis
                    ): ?>

                        <a href="<?= e( base_url( 'topsis/hasil.php?period_id=' . $selectedPeriodId ) ) ?>" class="btn btn-primary">
                            <?= $topsisComplete
                                ? 'Lihat / Hitung Ulang TOPSIS'
                                : 'Proses TOPSIS' ?>
                        </a>

                    <?php elseif (
                        $topsisComplete
                    ): ?>

                        <a href="<?= e( base_url( 'topsis/hasil.php?period_id=' . $selectedPeriodId ) ) ?>" class="btn btn-primary">
                            Lihat Hasil TOPSIS
                        </a>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </section>

        <!-- =========================================================
             PENJELASAN
             ========================================================= -->

        <div class="alert alert-info mb-4" role="status">
            TOPSIS menggunakan nilai
            <strong>C1 sampai C5</strong>
            dari enam motor sebagai matriks keputusan
            dan bobot kriteria yang dihasilkan melalui
            pembobotan AHP oleh Supervisor Maintenance.
        </div>

        <?php if (
            $periods === []
        ): ?>

            <!-- =====================================================
                 BELUM ADA PERIODE
                 ===================================================== -->

            <section class="card border-0 shadow-sm">

                <div class="card-body text-center p-4 p-md-5">

                    <h2 class="h5 mb-2">
                        Periode pemeriksaan belum tersedia
                    </h2>

                    <p class="text-body-secondary mb-3">
                        TOPSIS membutuhkan periode pemeriksaan
                        yang berisi data enam motor.
                    </p>

                    <?php if ($isAdmin): ?>

                        <a href="<?= e( base_url( 'periode/create.php' ) ) ?>" class="btn btn-primary">
                            Tambah Periode
                        </a>

                    <?php endif; ?>

                </div>

            </section>

        <?php else: ?>

            <!-- =====================================================
                 PILIH PERIODE
                 ===================================================== -->

            <section class="card border-0 shadow-sm mb-4" aria-labelledby="topsis-period-title">

                <div class="card-body p-4">

                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3">

                        <div class="flex-grow-1">

                            <h2 id="topsis-period-title" class="h5 mb-3">
                                Periode TOPSIS
                            </h2>

                            <form method="get" action="<?= e( base_url( 'topsis/index.php' ) ) ?>" class="row g-3 align-items-end">

                                <div class="col-md-8">

                                    <label for="period_id" class="form-label">
                                        Pilih Periode
                                    </label>

                                    <select id="period_id" name="period_id" class="form-select" required>

                                        <?php foreach (
                                            $periods
                                            as $period
                                        ): ?>

                                            <?php
                                            $periodStatus =
                                                (string) (
                                                    $period[
                                                        'status'
                                                    ] ?? 'draft'
                                                );

                                            $periodStatusLabel =
                                                $periodStatusConfigurations[
                                                    $periodStatus
                                                ]['label']
                                                ?? 'Tidak diketahui';
                                            ?>

                                            <option value="<?= e( (int) $period['id'] ) ?>" <?= selected( (string) $selectedPeriodId, (string) $period['id'] ) ?>>
                                                <?= e(
                                                    $period['name']
                                                ) ?>

                                                —

                                                <?= e(
                                                    $periodStatusLabel
                                                ) ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </div>

                                <div class="col-md-4">

                                    <div class="d-grid">

                                        <button type="submit" class="btn btn-primary">
                                            Tampilkan
                                        </button>

                                    </div>

                                </div>

                            </form>

                        </div>

                        <?php if (
                            $selectedPeriod !== null
                        ): ?>

                            <div class="border rounded-3 p-3" style="min-width: 280px;">

                                <div class="d-flex justify-content-between align-items-center gap-2 mb-2">

                                    <strong>
                                        <?= e(
                                            $selectedPeriod[
                                                'name'
                                            ]
                                        ) ?>
                                    </strong>

                                    <span class="badge text-bg-<?= e( $selectedPeriodStatusConfiguration[ 'class' ] ) ?>">
                                        <?= e(
                                            $selectedPeriodStatusConfiguration[
                                                'label'
                                            ]
                                        ) ?>
                                    </span>

                                </div>

                                <small class="text-body-secondary">
                                    <?= e(
                                        format_date_id(
                                            $selectedPeriod[
                                                'start_date'
                                            ] ?? null
                                        )
                                    ) ?>

                                    sampai

                                    <?= e(
                                        format_date_id(
                                            $selectedPeriod[
                                                'end_date'
                                            ] ?? null
                                        )
                                    ) ?>
                                </small>

                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            </section>

            <!-- =====================================================
                 STATUS TOPSIS
                 ===================================================== -->

            <section class="card border-0 shadow-sm mb-4">

                <div class="card-body p-4">

                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">

                        <div>

                            <h2 class="h5 mb-1">
                                Status TOPSIS
                            </h2>

                            <p class="text-body-secondary mb-0">
                                <?= e(
                                    $topsisStatus[
                                        'description'
                                    ]
                                ) ?>
                            </p>

                        </div>

                        <span class="badge text-bg-<?= e( $topsisStatus[ 'class' ] ) ?> p-2">
                            <?= e(
                                $topsisStatus[
                                    'label'
                                ]
                            ) ?>
                        </span>

                    </div>

                </div>

            </section>

            <!-- =====================================================
                 RINGKASAN KESIAPAN
                 ===================================================== -->

            <section class="row g-3 mb-4" aria-label="Kesiapan TOPSIS">

                <!-- AHP -->
                <div class="col-md-6 col-xl-3">

                    <article class="card border-0 shadow-sm h-100">

                        <div class="card-body">

                            <p class="small text-body-secondary mb-2">
                                AHP
                            </p>

                            <p class="h4 fw-semibold mb-2">
                                <?= $ahpReady
                                    ? 'Siap'
                                    : 'Belum Siap' ?>
                            </p>

                            <span class="badge <?= $ahpReady ? 'text-bg-success' : 'text-bg-warning' ?>">
                                <?= e(
                                    $ahpWeightCount
                                ) ?>
                                /
                                <?= e(
                                    $expectedCriteriaCount
                                ) ?>
                                bobot
                            </span>

                        </div>

                    </article>

                </div>

                <!-- CR -->
                <div class="col-md-6 col-xl-3">

                    <article class="card border-0 shadow-sm h-100">

                        <div class="card-body">

                            <p class="small text-body-secondary mb-2">
                                Rasio Konsistensi
                            </p>

                            <p class="h4 fw-semibold mb-2 <?= $ahpIsConsistent ? 'text-success' : '' ?>">
                                <?= e(
                                    $displayNumber(
                                        $consistencyRatio,
                                        4
                                    )
                                ) ?>
                            </p>

                            <small class="text-body-secondary">
                                Batas CR ≤ 0,10
                            </small>

                        </div>

                    </article>

                </div>

                <!-- Validasi -->
                <div class="col-md-6 col-xl-3">

                    <article class="card border-0 shadow-sm h-100">

                        <div class="card-body">

                            <p class="small text-body-secondary mb-2">
                                Pemeriksaan Disetujui
                            </p>

                            <p class="h4 fw-semibold mb-2">
                                <?= e(
                                    $validatedAssessmentCount
                                ) ?>
                                /
                                <?= e(
                                    $expectedMachineCount
                                ) ?>
                            </p>

                            <small class="text-body-secondary">
                                Alternatif A1–A6
                            </small>

                        </div>

                    </article>

                </div>

                <!-- Nilai -->
                <div class="col-md-6 col-xl-3">

                    <article class="card border-0 shadow-sm h-100">

                        <div class="card-body">

                            <p class="small text-body-secondary mb-2">
                                Nilai C1–C5 Lengkap
                            </p>

                            <p class="h4 fw-semibold mb-2">
                                <?= e(
                                    $completeScoreCount
                                ) ?>
                                /
                                <?= e(
                                    $expectedMachineCount
                                ) ?>
                            </p>

                            <small class="text-body-secondary">
                                Matriks keputusan
                            </small>

                        </div>

                    </article>

                </div>

            </section>

            <!-- =====================================================
                 PROGRES VALIDASI
                 ===================================================== -->

            <section class="card border-0 shadow-sm mb-4">

                <div class="card-body p-4">

                    <div class="d-flex justify-content-between small mb-2">
                        <span>
                            Pemeriksaan siap TOPSIS
                        </span>

                        <strong>
                            <?= e(
                                $assessmentProgress
                            ) ?>%
                        </strong>
                    </div>

                    <div class="progress" role="progressbar" aria-label="Progres pemeriksaan TOPSIS" aria-valuenow="<?= e( $assessmentProgress ) ?>" aria-valuemin="0" aria-valuemax="100" style="height: 14px;">

                        <div
                            class="progress-bar
                                   bg-success"
                            style="width: <?= e(
                                $assessmentProgress
                            ) ?>%;"
                        ></div>

                    </div>

                </div>

            </section>

            <!-- =====================================================
                 CEK PERSYARATAN
                 ===================================================== -->

            <section class="card border-0 shadow-sm mb-4" aria-labelledby="requirement-title">

                <div class="card-header bg-white py-3">

                    <h2 id="requirement-title" class="h5 mb-1">
                        Pemeriksaan Persyaratan
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Semua persyaratan harus terpenuhi
                        sebelum TOPSIS dijalankan.
                    </p>

                </div>

                <div class="card-body p-0">

                    <div class="table-responsive">

                        <table class="table table-hover align-middle mb-0">

                            <thead class="table-light">

                                <tr>

                                    <th scope="col">
                                        Persyaratan
                                    </th>

                                    <th scope="col" class="text-center">
                                        Nilai
                                    </th>

                                    <th scope="col" class="text-center">
                                        Status
                                    </th>

                                    <th scope="col" class="text-end">
                                        Aksi
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                                <!-- AHP -->
                                <tr>

                                    <td>
                                        <strong class="d-block">
                                            Bobot AHP
                                        </strong>

                                        <small class="text-body-secondary">
                                            Lima bobot kriteria
                                            harus tersedia.
                                        </small>
                                    </td>

                                    <td class="text-center">
                                        <span class="d-block">
                                            <?= e(
                                                $ahpWeightCount
                                            ) ?>
                                            /
                                            <?= e(
                                                $expectedCriteriaCount
                                            ) ?>
                                        </span>

                                        <small class="text-body-secondary">
                                            Total:
                                            <?= e(
                                                $displayNumber(
                                                    $weightSum,
                                                    6
                                                )
                                            ) ?>
                                        </small>
                                    </td>

                                    <td class="text-center">

                                        <span class="badge <?= $ahpWeightsComplete ? 'text-bg-success' : 'text-bg-warning' ?>">
                                            <?= $ahpWeightsComplete
                                                ? 'Lengkap'
                                                : 'Belum Lengkap' ?>
                                        </span>

                                    </td>

                                    <td class="text-end">

                                        <a href="<?= e( base_url( 'ahp/hasil.php?period_id=' . $selectedPeriodId ) ) ?>" class="btn btn-sm btn-outline-primary">
                                            Lihat AHP
                                        </a>

                                    </td>

                                </tr>

                                <!-- Konsistensi -->
                                <tr>

                                    <td>
                                        <strong class="d-block">
                                            Konsistensi AHP
                                        </strong>

                                        <small class="text-body-secondary">
                                            CR harus ≤ 0,10.
                                        </small>
                                    </td>

                                    <td class="text-center">
                                        <?= e(
                                            $displayNumber(
                                                $consistencyRatio,
                                                4
                                            )
                                        ) ?>
                                    </td>

                                    <td class="text-center">

                                        <span class="badge <?= $ahpIsConsistent ? 'text-bg-success' : 'text-bg-danger' ?>">
                                            <?= $ahpIsConsistent
                                                ? 'Konsisten'
                                                : 'Belum Konsisten' ?>
                                        </span>

                                    </td>

                                    <td class="text-end">
                                        -
                                    </td>

                                </tr>

                                <!-- Enam Pemeriksaan -->
                                <tr>

                                    <td>
                                        <strong class="d-block">
                                            Enam Pemeriksaan
                                        </strong>

                                        <small class="text-body-secondary">
                                            A1 sampai A6 harus
                                            mempunyai pemeriksaan.
                                        </small>
                                    </td>

                                    <td class="text-center">
                                        <?= e(
                                            $assessmentCount
                                        ) ?>
                                        /
                                        <?= e(
                                            $expectedMachineCount
                                        ) ?>
                                    </td>

                                    <td class="text-center">

                                        <span class="badge <?= $assessmentsComplete ? 'text-bg-success' : 'text-bg-warning' ?>">
                                            <?= $assessmentsComplete
                                                ? 'Lengkap'
                                                : 'Belum Lengkap' ?>
                                        </span>

                                    </td>

                                    <td class="text-end">

                                        <a href="<?= e( base_url( 'pemeriksaan/index.php?period_id=' . $selectedPeriodId ) ) ?>" class="btn btn-sm btn-outline-primary">
                                            Pemeriksaan
                                        </a>

                                    </td>

                                </tr>

                                <!-- Validasi -->
                                <tr>

                                    <td>
                                        <strong class="d-block">
                                            Validasi Supervisor
                                        </strong>

                                        <small class="text-body-secondary">
                                            Semua pemeriksaan harus
                                            disetujui.
                                        </small>
                                    </td>

                                    <td class="text-center">
                                        <?= e(
                                            $validatedAssessmentCount
                                        ) ?>
                                        /
                                        <?= e(
                                            $expectedMachineCount
                                        ) ?>
                                    </td>

                                    <td class="text-center">

                                        <span class="badge <?= $validationsComplete ? 'text-bg-success' : 'text-bg-warning' ?>">
                                            <?= $validationsComplete
                                                ? 'Lengkap'
                                                : 'Belum Lengkap' ?>
                                        </span>

                                    </td>

                                    <td class="text-end">
                                        -
                                    </td>

                                </tr>

                                <!-- Kelengkapan checklist -->
                                <tr>

                                    <td>
                                        <strong class="d-block">
                                            Checklist C4–C5
                                        </strong>

                                        <small class="text-body-secondary">
                                            Seluruh bagian checklist harus sudah diisi.
                                        </small>
                                    </td>

                                    <td class="text-center">
                                        <?= e(
                                            $bpFreeAssessmentCount
                                        ) ?>
                                        /
                                        <?= e(
                                            $expectedMachineCount
                                        ) ?>
                                    </td>

                                    <td class="text-center">

                                        <span class="badge <?= $bpFreeComplete ? 'text-bg-success' : 'text-bg-warning' ?>">
                                            <?= $bpFreeComplete
                                                ? 'Lengkap'
                                                : 'Belum Lengkap' ?>
                                        </span>

                                    </td>

                                    <td class="text-end">
                                        -
                                    </td>

                                </tr>

                                <!-- Nilai C1-C5 -->
                                <tr>

                                    <td>
                                        <strong class="d-block">
                                            Nilai C1–C5
                                        </strong>

                                        <small class="text-body-secondary">
                                            Setiap alternatif harus
                                            memiliki lima nilai kriteria.
                                        </small>
                                    </td>

                                    <td class="text-center">
                                        <?= e(
                                            $completeScoreCount
                                        ) ?>
                                        /
                                        <?= e(
                                            $expectedMachineCount
                                        ) ?>
                                    </td>

                                    <td class="text-center">

                                        <span class="badge <?= $scoresComplete ? 'text-bg-success' : 'text-bg-warning' ?>">
                                            <?= $scoresComplete
                                                ? 'Lengkap'
                                                : 'Belum Lengkap' ?>
                                        </span>

                                    </td>

                                    <td class="text-end">
                                        -
                                    </td>

                                </tr>

                            </tbody>

                        </table>

                    </div>

                </div>

            </section>

            <!-- =====================================================
                 BOBOT AHP
                 ===================================================== -->

            <section class="card border-0 shadow-sm mb-4" aria-labelledby="weight-summary-title">

                <div class="card-header bg-white py-3">

                    <h2 id="weight-summary-title" class="h5 mb-1">
                        Bobot Kriteria AHP
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Bobot yang akan digunakan
                        pada matriks terbobot TOPSIS.
                    </p>

                </div>

                <div class="card-body p-0">

                    <?php if (
                        $weightRows === []
                    ): ?>

                        <div class="text-center text-body-secondary p-4">
                            Bobot AHP belum tersedia.
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle mb-0">

                                <thead class="table-light">

                                    <tr>

                                        <th>
                                            Kode
                                        </th>

                                        <th>
                                            Kriteria
                                        </th>

                                        <th class="text-end">
                                            Bobot
                                        </th>

                                        <th class="text-end">
                                            Persentase
                                        </th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach (
                                        $weightRows
                                        as $weightRow
                                    ): ?>

                                        <?php
                                        $weight =
                                            floatval(
                                                $weightRow[
                                                    'weight'
                                                ]
                                            );
                                        ?>

                                        <tr>

                                            <td>
                                                <span class="badge text-bg-primary">
                                                    <?= e(
                                                        $weightRow[
                                                            'code'
                                                        ]
                                                    ) ?>
                                                </span>
                                            </td>

                                            <td>
                                                <?= e(
                                                    $weightRow[
                                                        'name'
                                                    ]
                                                ) ?>
                                            </td>

                                            <td class="text-end fw-semibold">
                                                <?= e(
                                                    $displayNumber(
                                                        $weight,
                                                        6
                                                    )
                                                ) ?>
                                            </td>

                                            <td class="text-end">
                                                <?= e(
                                                    format_number_id(
                                                        $weight * 100,
                                                        2
                                                    )
                                                ) ?>%
                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>

            </section>

            <!-- =====================================================
                 DATA ALTERNATIF
                 ===================================================== -->

            <section class="card border-0 shadow-sm mb-4" aria-labelledby="alternative-title">

                <div class="card-header bg-white py-3">

                    <h2 id="alternative-title" class="h5 mb-1">
                        Matriks Keputusan Awal
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Nilai C1 sampai C5 dari
                        alternatif A1 sampai A6.
                    </p>

                </div>

                <div class="card-body p-0">

                    <?php if (
                        $assessmentRows === []
                    ): ?>

                        <div class="text-center text-body-secondary p-4">
                            Data alternatif belum tersedia.
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle mb-0">

                                <thead class="table-light">

                                    <tr>

                                        <th>
                                            Alternatif
                                        </th>

                                        <th>
                                            Motor
                                        </th>

                                        <th class="text-center">
                                            C1
                                        </th>

                                        <th class="text-center">
                                            C2
                                        </th>

                                        <th class="text-center">
                                            C3
                                        </th>

                                        <th class="text-center">
                                            C4
                                        </th>

                                        <th class="text-center">
                                            C5
                                        </th>

                                        <th class="text-center">
                                            Status
                                        </th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach (
                                        $assessmentRows
                                        as $row
                                    ): ?>

                                        <?php
                                        $rowStatus =
                                            (string) (
                                                $row[
                                                    'assessment_status'
                                                ] ?? ''
                                            );

                                        $rowValidated =
                                            in_array(
                                                $rowStatus,
                                                [
                                                    'validated',
                                                    'processed',
                                                ],
                                                true
                                            );
                                        ?>

                                        <tr>

                                            <td>

                                                <strong>
                                                    <?= e(
                                                        $row[
                                                            'alternative_code'
                                                        ] ?? '-'
                                                    ) ?>
                                                </strong>

                                            </td>

                                            <td>
                                                <?= e(
                                                    $row[
                                                        'motor_name'
                                                    ] ?? '-'
                                                ) ?>
                                            </td>

                                            <td class="text-center">
                                                <?= e(
                                                    $displayNumber(
                                                        $row[
                                                            'c1_value'
                                                        ] ?? null,
                                                        4
                                                    )
                                                ) ?>
                                            </td>

                                            <td class="text-center">
                                                <?= e(
                                                    $displayNumber(
                                                        $row[
                                                            'c2_value'
                                                        ] ?? null,
                                                        4
                                                    )
                                                ) ?>
                                            </td>

                                            <td class="text-center">
                                                <?= e(
                                                    $displayNumber(
                                                        $row[
                                                            'c3_value'
                                                        ] ?? null,
                                                        4
                                                    )
                                                ) ?>
                                            </td>

                                            <td class="text-center">
                                                <?= e(
                                                    $displayNumber(
                                                        $row[
                                                            'c4_value'
                                                        ] ?? null,
                                                        4
                                                    )
                                                ) ?>
                                            </td>

                                            <td class="text-center">
                                                <?= e(
                                                    $displayNumber(
                                                        $row[
                                                            'c5_value'
                                                        ] ?? null,
                                                        4
                                                    )
                                                ) ?>
                                            </td>

                                            <td class="text-center">

                                                <?php if (
                                                    (int) (
                                                        $row[
                                                            'assessment_id'
                                                        ] ?? 0
                                                    ) < 1
                                                ): ?>

                                                    <span class="badge text-bg-secondary">
                                                        Belum Diperiksa
                                                    </span>

                                                <?php elseif (
                                                    $rowValidated
                                                ): ?>

                                                    <span class="badge text-bg-success">
                                                        Siap
                                                    </span>

                                                <?php else: ?>

                                                    <span class="badge text-bg-warning">
                                                        Belum Valid
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

            <!-- =====================================================
                 HASIL RINGKAS JIKA SUDAH ADA
                 ===================================================== -->

            <?php if (
                $topsisRows !== []
            ): ?>

                <section class="card border-0 shadow-sm" aria-labelledby="ranking-summary-title">

                    <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">

                        <div>

                            <h2 id="ranking-summary-title" class="h5 mb-1">
                                Hasil Pemeringkatan
                            </h2>

                            <p class="small text-body-secondary mb-0">
                                Ringkasan hasil TOPSIS
                                yang sudah tersimpan.
                            </p>

                        </div>

                        <a href="<?= e( base_url( 'topsis/hasil.php?period_id=' . $selectedPeriodId ) ) ?>" class="btn btn-sm btn-outline-primary">
                            Detail Perhitungan
                        </a>

                    </div>

                    <div class="card-body p-0">

                        <div class="table-responsive">

                            <table class="table table-hover align-middle mb-0">

                                <thead class="table-light">

                                    <tr>

                                        <th class="text-center">
                                            Peringkat
                                        </th>

                                        <th class="text-center">
                                            Kelompok
                                        </th>

                                        <th>
                                            Alternatif
                                        </th>

                                        <th>
                                            Motor
                                        </th>

                                        <th class="text-end">
                                            Nilai Preferensi
                                        </th>

                                        <th>
                                            Prioritas
                                        </th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach (
                                        $topsisRows
                                        as $result
                                    ): ?>

                                        <tr>

                                            <td class="text-center">

                                                <strong>
                                                    <?= e(
                                                        (int) (
                                                            $result[
                                                                'ranking'
                                                            ] ?? 0
                                                        )
                                                    ) ?>
                                                </strong>

                                            </td>

                                            <td class="text-center">

                                                <span class="badge text-bg-light border">
                                                    <?= e(
                                                        (int) (
                                                            $result[
                                                                'rank_group'
                                                            ] ?? 0
                                                        )
                                                    ) ?>
                                                </span>

                                            </td>

                                            <td>

                                                <span class="badge text-bg-primary">
                                                    <?= e(
                                                        $result[
                                                            'alternative_code'
                                                        ] ?? '-'
                                                    ) ?>
                                                </span>

                                            </td>

                                            <td>
                                                <?= e(
                                                    $result[
                                                        'motor_name'
                                                    ] ?? '-'
                                                ) ?>
                                            </td>

                                            <td class="text-end fw-semibold">
                                                <?= e(
                                                    $displayNumber(
                                                        $result[
                                                            'preference_value'
                                                        ] ?? null,
                                                        6
                                                    )
                                                ) ?>
                                            </td>

                                            <td>

                                                <span class="badge text-bg-primary">
                                                    <?= e(
                                                        $result[
                                                            'priority_label'
                                                        ]
                                                        ?? (
                                                            'Prioritas '
                                                            . (
                                                                $result[
                                                                    'rank_group'
                                                                ]
                                                                ?? $result[
                                                                    'ranking'
                                                                ]
                                                                ?? '-'
                                                            )
                                                        )
                                                    ) ?>
                                                </span>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>

                </section>

            <?php endif; ?>

            <!-- =====================================================
                 INFORMASI PERAN
                 ===================================================== -->

            <div class="alert alert-light border mt-4 mb-0">

                <?php if ($isSupervisor): ?>

                    <strong>
                        Supervisor Maintenance:
                    </strong>

                    Anda dapat menjalankan TOPSIS setelah
                    seluruh pemeriksaan disetujui dan
                    hasil AHP dinyatakan konsisten.

                <?php else: ?>

                    <strong>
                        Administrator:
                    </strong>

                    Anda dapat memantau kesiapan dan melihat
                    hasil TOPSIS. Proses perhitungan dilakukan
                    oleh Supervisor Maintenance.

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </main>

</div>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

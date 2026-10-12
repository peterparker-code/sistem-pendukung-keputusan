<?php

declare(strict_types=1);

/*
 * Hasil Perhitungan AHP
 * SPK Pemeliharaan Motor Mesin Mixer PT CISAS
 * Hak akses:
 * Administrator:
 * - Melihat hasil AHP.
 * Supervisor Maintenance:
 * - Melihat hasil AHP.
 * - Menghitung AHP.
 * - Menghitung ulang AHP.
 * - Memperbaiki perbandingan selama TOPSIS belum diproses.
 * Ketentuan penelitian:
 * C1 = Suhu Permukaan
 * C2 = Persentase Arus
 * C3 = Deviasi RPM
 * C4 = Kondisi Mekanikal
 * C5 = Kondisi Elektrikal
 * Jumlah kriteria       = 5
 * Jumlah perbandingan   = 10
 * Responden AHP         = Supervisor Maintenance
 * Indeks Acak n = 5    = 1,12
 * Konsisten apabila CR <= 0,10
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'helpers'
    . DIRECTORY_SEPARATOR
    . 'auth.php';

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'services'
    . DIRECTORY_SEPARATOR
    . 'AhpService.php';

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'services'
    . DIRECTORY_SEPARATOR . 'WorkflowService.php';

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

$currentRole = auth_role();

$isAdmin =
    $currentRole === 'admin';

$isSupervisor =
    $currentRole === 'supervisor';

// Tentukan Periode

$periodId = null;

$periodIdInput =
    $_GET['period_id']
    ?? null;

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
            'ID periode AHP tidak valid.';

        require dirname(__DIR__)
            . DIRECTORY_SEPARATOR
            . '404.php';

        exit;
    }

    $periodId =
        (int) $validatedPeriodId;
} else {
    /*
 * Jika URL Tidak Memiliki period_id,
 * Prioritaskan Periode Aktif
 */

    $activePeriodStatement =
        $pdo->query(
            "SELECT id

             FROM assessment_periods

             WHERE status = 'active'

             ORDER BY
                start_date DESC,
                id DESC

             LIMIT 1"
        );

    $activePeriodId =
        $activePeriodStatement
            ->fetchColumn();

    if ($activePeriodId === false) {
        flash_warning(
            'Belum terdapat periode pemeriksaan yang Aktif.'
        );

        redirect(
            'ahp/index.php'
        );
    }

    $periodId =
        (int) $activePeriodId;
}

// Ambil Periode

$periodStatement =
    $pdo->prepare(
        "SELECT
            id,
            name,
            start_date,
            end_date,
            status

         FROM assessment_periods

         WHERE id = :id

         LIMIT 1"
    );

$periodStatement->execute([
    'id' =>
        $periodId,
]);

$periodData =
    $periodStatement->fetch();

$period =
    is_array($periodData)
        ? $periodData
        : null;

if ($period === null) {
    $notFoundMessage =
        'Periode pemeriksaan tidak ditemukan.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '404.php';

    exit;
}

// Status Periode

$periodStatus =
    (string) (
        $period['status']
        ?? ''
    );

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

$periodStatusConfiguration =
    $periodStatusConfigurations[
        $periodStatus
    ]
    ?? [
        'label' =>
            'Tidak diketahui',

        'class' =>
            'secondary',
    ];

// Ambil Lima Kriteria

$criteriaStatement =
    $pdo->query(
        "SELECT
            id,
            code,
            name,
            description,
            display_order

         FROM criteria

         WHERE is_active = 1

         ORDER BY
            display_order ASC,
            id ASC"
    );

$criteria =
    $criteriaStatement->fetchAll();

// Pastikan Jumlah Kriteria Tepat Lima

if (count($criteria) !== 5) {
    flash_error(
        'Perhitungan AHP tidak dapat dilakukan karena '
        . 'jumlah kriteria aktif harus tepat lima.'
    );

    redirect(
        'ahp/index.php?period_id='
        . $periodId
    );
}

// Susun Kriteria Berdasarkan ID

$criterionIds = [];

$criteriaById = [];

foreach (
    $criteria
    as $criterion
) {
    $criterionId =
        (int) $criterion['id'];

    $criterionIds[] =
        $criterionId;

    $criteriaById[
        $criterionId
    ] = $criterion;
}

// Ambil Perbandingan Berpasangan

$comparisonStatement =
    $pdo->prepare(
        "SELECT
            ac.id,
            ac.criterion_left_id,
            ac.criterion_right_id,
            ac.comparison_value,
            ac.assessor_id,

            u.name AS assessor_name

         FROM ahp_comparisons AS ac

         LEFT JOIN users AS u
            ON u.id = ac.assessor_id

         WHERE ac.period_id = :period_id

         ORDER BY
            ac.criterion_left_id ASC,
            ac.criterion_right_id ASC"
    );

$comparisonStatement->execute([
    'period_id' =>
        $periodId,
]);

$comparisons =
    $comparisonStatement->fetchAll();

// Nama Responden

$assessorName =
    'Supervisor Maintenance';

foreach (
    $comparisons
    as $comparison
) {
    $candidateName = trim(
        (string) (
            $comparison[
                'assessor_name'
            ] ?? ''
        )
    );

    if ($candidateName !== '') {
        $assessorName =
            $candidateName;

        break;
    }
}

$calculationReadiness = WorkflowService::calculationReadiness($pdo, $periodId);
$dataReadyForCalculation = (bool) ($calculationReadiness['ready'] ?? false);

// Jumlah Perbandingan

$expectedComparisonCount =
    10;

$comparisonCount =
    count($comparisons);

$comparisonsComplete =
    $comparisonCount
    === $expectedComparisonCount;

// Cek Apakah TOPSIS Diproses TOPSIS

$topsisStatement =
    $pdo->prepare(
        "SELECT COUNT(*)

         FROM topsis_results

         WHERE period_id = :period_id"
    );

$topsisStatement->execute([
    'period_id' =>
        $periodId,
]);

$topsisResultCount =
    (int)
    $topsisStatement
        ->fetchColumn();

$topsisAlreadyProcessed =
    $topsisResultCount > 0;

// CSRF

$csrfFormName =
    'ahp_calculate_'
    . $periodId;

/*
 * Proses Perhitungan
 * POST hanya boleh dilakukan Supervisor.
 */

if (is_post()) {
    // Administrator Tidak Boleh Menghitung AHP

    if (!$isSupervisor) {
        flash_error(
            'Perhitungan AHP hanya dapat dilakukan '
            . 'oleh Supervisor Maintenance.'
        );

        redirect(
            'ahp/hasil.php?period_id='
            . $periodId
        );
    }

    // Validasi CSRF

    if (
        !csrf_validate_request(
            $csrfFormName
        )
    ) {
        flash_error(
            'Token keamanan tidak valid atau telah kedaluwarsa.'
        );

        redirect(
            'ahp/hasil.php?period_id='
            . $periodId
        );
    }

    // Periode Harus Aktif

    if ($periodStatus !== 'active') {
        flash_error(
            'AHP hanya dapat dihitung ulang pada '
            . 'periode yang berstatus Aktif.'
        );

        redirect(
            'ahp/hasil.php?period_id='
            . $periodId
        );
    }

    // Enam pemeriksaan harus sudah siap.
    if (!$dataReadyForCalculation) {
        flash_error(
            'Perhitungan AHP belum dapat dilakukan. '
            . 'Enam pemeriksaan A1-A6 harus sudah disetujui dan nilai C1-C5 lengkap.'
        );
        redirect('ahp/index.php?period_id=' . $periodId);
    }

    // Sepuluh Perbandingan Harus Lengkap

    if (!$comparisonsComplete) {
        flash_error(
            'Perhitungan AHP belum dapat dilakukan. '
            . 'Lengkapi terlebih dahulu seluruh '
            . '10 perbandingan berpasangan.'
        );

        redirect(
            'ahp/input.php?period_id='
            . $periodId
        );
    }

    // Jalankan Perhitungan melalui AhpService

    try {
        $ahpService =
            new AhpService(
                $pdo
            );

        $calculation =
            $ahpService
                ->recalculateFromDatabase(
                    $periodId
                );

        // Baca Ringkasan Hasil

        $calculationSummary =
            isset(
                $calculation['summary']
            )
            && is_array(
                $calculation['summary']
            )
                ? $calculation['summary']
                : $calculation;

        $calculatedCr =
            isset(
                $calculationSummary[
                    'consistency_ratio'
                ]
            )
                ? floatval(
                    $calculationSummary[
                        'consistency_ratio'
                    ]
                )
                : null;

        $calculatedConsistent =
            isset(
                $calculationSummary[
                    'is_consistent'
                ]
            )
                ? (bool)
                    $calculationSummary[
                        'is_consistent'
                    ]
                : (
                    $calculatedCr !== null
                    && $calculatedCr <= 0.10
                );

        if ($calculatedConsistent) {
            flash_success(
                'Perhitungan AHP berhasil. '
                . 'Rasio Konsistensi memenuhi batas '
                . 'CR ≤ 0,10 sehingga bobot dapat '
                . 'digunakan pada proses TOPSIS.'
            );
        } else {
            flash_warning(
                'Perhitungan AHP berhasil, tetapi '
                . 'Rasio Konsistensi melebihi 0,10. '
                . 'Perbandingan berpasangan perlu diperbaiki.'
            );
        }
    } catch (Throwable $exception) {
        error_log(
            'Perhitungan AHP PT CISAS gagal: '
            . $exception->getMessage()
        );

        flash_error(
            'Perhitungan AHP belum berhasil. '
            . 'Periksa kembali data perbandingan AHP.'
        );
    }

    redirect(
        'ahp/hasil.php?period_id='
        . $periodId
    );
}

// Ambil Bobot Kriteria

$resultStatement =
    $pdo->prepare(
        "SELECT
            ar.criterion_id,
            ar.weight,

            c.code,
            c.name,
            c.description,
            c.display_order

         FROM ahp_results AS ar

         INNER JOIN criteria AS c
            ON c.id = ar.criterion_id

         WHERE ar.period_id = :period_id

         ORDER BY
            c.display_order ASC,
            c.id ASC"
    );

$resultStatement->execute([
    'period_id' =>
        $periodId,
]);

$weightRows =
    $resultStatement->fetchAll();

// Bobot Berdasarkan Kriteria

$weightsByCriterionId = [];

$totalWeight =
    0.0;

foreach (
    $weightRows
    as $weightRow
) {
    $criterionId =
        (int) $weightRow[
            'criterion_id'
        ];

    $weight =
        floatval(
            $weightRow[
                'weight'
            ]
        );

    $weightsByCriterionId[
        $criterionId
    ] = $weight;

    $totalWeight +=
        $weight;
}

$weightsComplete =
    count($weightRows)
    === count($criteria);

// Ambil Ringkasan AHP

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
        $periodId,
]);

$summaryData =
    $summaryStatement->fetch();

$summary =
    is_array($summaryData)
        ? $summaryData
        : null;

// Status Konsistensi

$consistencyRatio =
    $summary !== null
    && (
        $summary[
            'consistency_ratio'
        ] ?? null
    ) !== null
        ? floatval(
            $summary[
                'consistency_ratio'
            ]
        )
        : null;

$isConsistent =
    $summary !== null
    && (int) (
        $summary[
            'is_consistent'
        ] ?? 0
    ) === 1
    && $consistencyRatio !== null
    && $consistencyRatio <= 0.10;

// Bangun Matriks Perbandingan 5 x 5

$matrix = [];

foreach (
    $criterionIds
    as $rowId
) {
    $matrix[$rowId] = [];

    foreach (
        $criterionIds
        as $columnId
    ) {
        $matrix[$rowId][$columnId] =
            $rowId === $columnId
                ? 1.0
                : null;
    }
}

// Isi Matriks dan Nilai Resiprokal

foreach (
    $comparisons
    as $comparison
) {
    $firstId =
        (int) (
            $comparison[
                'criterion_left_id'
            ] ?? 0
        );

    $secondId =
        (int) (
            $comparison[
                'criterion_right_id'
            ] ?? 0
        );

    $comparisonValue =
        floatval(
            $comparison[
                'comparison_value'
            ] ?? 0
        );

    if (
        !isset(
            $criteriaById[
                $firstId
            ]
        )
        || !isset(
            $criteriaById[
                $secondId
            ]
        )
        || $comparisonValue <= 0
    ) {
        continue;
    }

    $matrix[
        $firstId
    ][
        $secondId
    ] =
        $comparisonValue;

    $matrix[
        $secondId
    ][
        $firstId
    ] =
        1 / $comparisonValue;
}

// Periksa Kelengkapan Matriks

$matrixComplete =
    true;

foreach (
    $criterionIds
    as $rowId
) {
    foreach (
        $criterionIds
        as $columnId
    ) {
        if (
            $matrix[$rowId][$columnId]
            === null
        ) {
            $matrixComplete =
                false;

            break 2;
        }
    }
}

// Jumlah Kolom

$columnSums = [];

foreach (
    $criterionIds
    as $columnId
) {
    $columnSums[
        $columnId
    ] = 0.0;

    if (!$matrixComplete) {
        continue;
    }

    foreach (
        $criterionIds
        as $rowId
    ) {
        $columnSums[
            $columnId
        ] +=
            floatval(
                $matrix[
                    $rowId
                ][
                    $columnId
                ]
            );
    }
}

// Matriks Normalisasi

$normalizedMatrix = [];

if ($matrixComplete) {
    foreach (
        $criterionIds
        as $rowId
    ) {
        $normalizedMatrix[
            $rowId
        ] = [];

        foreach (
            $criterionIds
            as $columnId
        ) {
            $columnSum =
                $columnSums[
                    $columnId
                ];

            $normalizedMatrix[
                $rowId
            ][
                $columnId
            ] =
                $columnSum > 0
                    ? floatval(
                        $matrix[
                            $rowId
                        ][
                            $columnId
                        ]
                    )
                    / $columnSum
                    : null;
        }
    }
}

// Helper Tampilan Angka

$displayDecimal =
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

// Helper Tampilan Skala AHP

$displayAhpValue =
    static function (
        mixed $value
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

        $knownValues = [
            '1/9' => 1 / 9,
            '1/7' => 1 / 7,
            '1/5' => 1 / 5,
            '1/3' => 1 / 3,
            '1' => 1.0,
            '3' => 3.0,
            '5' => 5.0,
            '7' => 7.0,
            '9' => 9.0,
        ];

        foreach ($knownValues as $label => $knownValue) {
            if (abs($number - $knownValue) < 0.000001) {
                return (string) $label;
            }
        }

        return format_number_id(
            $number,
            4
        );
    };

// Waktu Perhitungan

$calculatedAtDisplay =
    '-';

if (
    $summary !== null
    && trim(
        (string) (
            $summary[
                'calculated_at'
            ] ?? ''
        )
    ) !== ''
) {
    $calculatedAtDisplay =
        format_date_id(
            $summary[
                'calculated_at'
            ],
            true
        );
}

// Hasil AHP Lengkap

$ahpResultComplete =
    $comparisonsComplete
    && $matrixComplete
    && $weightsComplete
    && $summary !== null;

// Supervisor Dapat Mengubah AHP

$canModifyAhp =
    $isSupervisor
    && $periodStatus === 'active';

// Supervisor Dapat Menghitung AHP

$canCalculateAhp =
    $canModifyAhp
    && $dataReadyForCalculation
    && $comparisonsComplete;

// Konfigurasi Halaman

$pageTitle =
    'Hasil AHP';

$pageDescription =
    'Matriks perbandingan, bobot kriteria, '
    . 'dan pengujian konsistensi AHP.';

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

        <!-- Judul -->
        <section class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3 mb-4">

            <div>

                <h1 class="page-title">
                    Hasil Perhitungan AHP
                </h1>

                <p class="page-description">
                    Matriks perbandingan, normalisasi,
                    bobot lima kriteria, dan pengujian
                    konsistensi penilaian.
                </p>

            </div>

            <div class="page-header-actions">

                <a href="<?= e( base_url( 'ahp/index.php?period_id=' . $periodId ) ) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>

                <?php if ($canModifyAhp): ?>

                    <a href="<?= e( base_url( 'ahp/input.php?period_id=' . $periodId ) ) ?>" class="btn btn-outline-primary">
                        Ubah Perbandingan
                    </a>

                <?php endif; ?>

            </div>

        </section>

        <!-- Periode -->
        <section class="card border-0 shadow-sm mb-4">

            <div class="card-body p-4">

                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">

                    <div>

                        <small class="text-body-secondary">
                            Periode Pemeriksaan
                        </small>

                        <h2 class="h5 mt-1 mb-1">
                            <?= e(
                                $period[
                                    'name'
                                ]
                            ) ?>
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            <?= e(
                                format_date_id(
                                    $period[
                                        'start_date'
                                    ] ?? null
                                )
                            ) ?>

                            sampai

                            <?= e(
                                format_date_id(
                                    $period[
                                        'end_date'
                                    ] ?? null
                                )
                            ) ?>
                        </p>

                    </div>

                    <div class="text-lg-end">

                        <span class="badge text-bg-<?= e( $periodStatusConfiguration[ 'class' ] ) ?>">
                            <?= e(
                                $periodStatusConfiguration[
                                    'label'
                                ]
                            ) ?>
                        </span>

                        <p class="small text-body-secondary mt-2 mb-0">
                            Responden AHP:

                            <strong>
                                <?= e(
                                    $assessorName
                                ) ?>
                            </strong>
                        </p>

                    </div>

                </div>

            </div>

        </section>

        <!-- Informasi Hak Akses -->
        <?php if ($isAdmin): ?>

            <div class="alert alert-info mb-4" role="status">
                <strong>
                    Akses Administrator.
                </strong>

                Anda dapat melihat hasil pembobotan AHP.
                Pengisian perbandingan dan proses perhitungan
                dilakukan oleh Supervisor Maintenance.
            </div>

        <?php endif; ?>

        <!-- Perbandingan Belum Lengkap -->
        <?php if (!$comparisonsComplete): ?>

            <div class="alert alert-warning mb-4" role="alert">

                <strong class="d-block mb-1">
                    Perbandingan AHP belum lengkap
                </strong>

                Saat ini tersedia

                <strong>
                    <?= e(
                        $comparisonCount
                    ) ?>
                </strong>

                dari

                <strong>
                    <?= e(
                        $expectedComparisonCount
                    ) ?>
                </strong>

                pasangan.

                <?php if ($canModifyAhp): ?>

                    <div class="mt-3">

                        <a href="<?= e( base_url( 'ahp/input.php?period_id=' . $periodId ) ) ?>" class="btn btn-sm btn-warning">
                            Lengkapi Perbandingan
                        </a>

                    </div>

                <?php endif; ?>

            </div>

        <?php elseif (!$ahpResultComplete): ?>

            <div class="alert alert-info mb-4" role="status">
                Seluruh 10 pasangan perbandingan sudah tersedia,
                tetapi bobot dan hasil konsistensi AHP belum lengkap.
            </div>

        <?php endif; ?>

        <!-- TOPSIS Diproses TOPSIS -->
        <?php if ($topsisAlreadyProcessed): ?>

            <div class="alert alert-warning mb-4" role="status">
                <strong class="d-block mb-1">
                    TOPSIS sudah tersedia
                </strong>

                Supervisor tetap dapat menghitung ulang AHP untuk kebutuhan koreksi.
                Jika perbandingan AHP diubah, hasil TOPSIS aktif akan diminta dihitung ulang
                agar ranking menggunakan bobot terbaru.
            </div>

        <?php endif; ?>

        <!-- Tombol Hitung -->
        <?php if ($canCalculateAhp): ?>

            <section class="card border-0 shadow-sm mb-4">

                <div class="card-body p-4">

                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">

                        <div>

                            <h2 class="h5 mb-1">
                                <?= $ahpResultComplete
                                    ? 'Hitung Ulang AHP'
                                    : 'Hitung AHP' ?>
                            </h2>

                            <p class="text-body-secondary mb-0">
                                Alur sederhananya: jumlahkan tiap kolom,
                                normalisasi, ambil rata-rata tiap baris sebagai
                                bobot, lalu periksa Rasio Konsistensi (CR).
                            </p>

                        </div>

                        <form method="post" action="<?= e( base_url( 'ahp/hasil.php?period_id=' . $periodId ) ) ?>" class="m-0">

                            <?= csrf_field(
                                $csrfFormName
                            ) ?>

                            <button type="submit" class="btn btn-primary" data-confirm="<?= e( $ahpResultComplete ? ($topsisAlreadyProcessed ? 'TOPSIS sudah tersedia. Hitung ulang AHP hanya jika diperlukan untuk koreksi. Lanjutkan?' : 'Hitung ulang hasil AHP periode ini?') : 'Hitung hasil AHP periode ini?' ) ?>" data-loading-text="Menghitung...">
                                <?= $ahpResultComplete
                                    ? 'Hitung Ulang'
                                    : 'Hitung Sekarang' ?>
                            </button>

                        </form>

                    </div>

                </div>

            </section>

        <?php endif; ?>

        <!-- Detail perhitungan AHP selalu ditampilkan agar mudah diperiksa saat sidang. -->
        <div class="mb-3">
            <h2 class="h4 mb-1">Detail Perhitungan AHP</h2>
            <p class="text-body-secondary mb-0">
                Tahapan perhitungan ditampilkan langsung agar matriks, bobot, dan konsistensi mudah diperiksa.
            </p>
        </div>
        <div id="ahp-calculation-details" class="calculation-details">
        <!-- Matriks Perbandingan -->
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="matrix-title">

            <div class="card-header bg-white py-3">

                <h2 id="matrix-title" class="h5 mb-1">
                    1. Matriks Perbandingan Berpasangan
                </h2>

                <p class="small text-body-secondary mb-0">
                    Matriks 5 × 5. Nilai kebalikan dibentuk otomatis dan diagonal bernilai 1.
                </p>

            </div>

            <div class="card-body p-0">

                <?php if (!$matrixComplete): ?>

                    <div class="text-center text-body-secondary p-5">
                        Matriks belum dapat dibentuk karena
                        perbandingan belum lengkap.
                    </div>

                <?php else: ?>

                    <div class="table-responsive">

                        <table class="table table-bordered table-hover align-middle text-center mb-0">

                            <thead class="table-light">

                                <tr>

                                    <th>
                                        Kriteria
                                    </th>

                                    <?php foreach (
                                        $criteria
                                        as $criterion
                                    ): ?>

                                        <th>
                                            <?= e(
                                                $criterion[
                                                    'code'
                                                ]
                                            ) ?>
                                        </th>

                                    <?php endforeach; ?>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach (
                                    $criteria
                                    as $rowCriterion
                                ): ?>

                                    <?php
                                    $rowId =
                                        (int)
                                        $rowCriterion[
                                            'id'
                                        ];
                                    ?>

                                    <tr>

                                        <th class="table-light">
                                            <?= e(
                                                $rowCriterion[
                                                    'code'
                                                ]
                                            ) ?>
                                        </th>

                                        <?php foreach (
                                            $criteria
                                            as $columnCriterion
                                        ): ?>

                                            <?php
                                            $columnId =
                                                (int)
                                                $columnCriterion[
                                                    'id'
                                                ];
                                            ?>

                                            <td>
                                                <?= e(
                                                    $displayAhpValue(
                                                        $matrix[
                                                            $rowId
                                                        ][
                                                            $columnId
                                                        ]
                                                    )
                                                ) ?>
                                            </td>

                                        <?php endforeach; ?>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                            <tfoot class="table-light">

                                <tr>

                                    <th>
                                        Jumlah
                                    </th>

                                    <?php foreach (
                                        $criteria
                                        as $criterion
                                    ): ?>

                                        <?php
                                        $criterionId =
                                            (int)
                                            $criterion[
                                                'id'
                                            ];
                                        ?>

                                        <th>
                                            <?= e(
                                                $displayDecimal(
                                                    $columnSums[
                                                        $criterionId
                                                    ],
                                                    4
                                                )
                                            ) ?>
                                        </th>

                                    <?php endforeach; ?>

                                </tr>

                            </tfoot>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

        </section>

        <!-- Matriks Normalisasi -->
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="normalization-title">

            <div class="card-header bg-white py-3">

                <h2 id="normalization-title" class="h5 mb-1">
                    2. Matriks Normalisasi
                </h2>

                <p class="small text-body-secondary mb-0">
                    Setiap nilai matriks dibagi
                    dengan jumlah kolom masing-masing.
                </p>

            </div>

            <div class="card-body p-0">

                <?php if (!$matrixComplete): ?>

                    <div class="text-center text-body-secondary p-5">
                        Matriks normalisasi belum dapat ditampilkan.
                    </div>

                <?php else: ?>

                    <div class="table-responsive">

                        <table class="table table-bordered table-hover align-middle text-center mb-0">

                            <thead class="table-light">

                                <tr>

                                    <th>
                                        Kriteria
                                    </th>

                                    <?php foreach (
                                        $criteria
                                        as $criterion
                                    ): ?>

                                        <th>
                                            <?= e(
                                                $criterion[
                                                    'code'
                                                ]
                                            ) ?>
                                        </th>

                                    <?php endforeach; ?>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach (
                                    $criteria
                                    as $rowCriterion
                                ): ?>

                                    <?php
                                    $rowId =
                                        (int)
                                        $rowCriterion[
                                            'id'
                                        ];
                                    ?>

                                    <tr>

                                        <th class="table-light">
                                            <?= e(
                                                $rowCriterion[
                                                    'code'
                                                ]
                                            ) ?>
                                        </th>

                                        <?php foreach (
                                            $criteria
                                            as $columnCriterion
                                        ): ?>

                                            <?php
                                            $columnId =
                                                (int)
                                                $columnCriterion[
                                                    'id'
                                                ];
                                            ?>

                                            <td>
                                                <?= e(
                                                    $displayDecimal(
                                                        $normalizedMatrix[
                                                            $rowId
                                                        ][
                                                            $columnId
                                                        ] ?? null,
                                                        4
                                                    )
                                                ) ?>
                                            </td>

                                        <?php endforeach; ?>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

        </section>

        </div>

        <!-- Bobot Kriteria -->
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="weight-title">

            <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">

                <div>

                    <h2 id="weight-title" class="h5 mb-1">
                        3. Bobot Kriteria
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Bobot diperoleh dari rata-rata baris matriks normalisasi dan digunakan sebagai bobot TOPSIS.
                    </p>

                </div>

                <?php if ($weightsComplete): ?>

                    <span class="badge text-bg-primary">
                        Total:
                        <?= e(
                            $displayDecimal(
                                $totalWeight,
                                4
                            )
                        ) ?>
                    </span>

                <?php endif; ?>

            </div>

            <div class="card-body p-0">

                <?php if (!$weightsComplete): ?>

                    <div class="text-center text-body-secondary p-5">
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

                                            <strong class="d-block">
                                                <?= e(
                                                    $weightRow[
                                                        'name'
                                                    ]
                                                ) ?>
                                            </strong>

                                            <small class="text-body-secondary">
                                                <?= e(
                                                    $weightRow[
                                                        'description'
                                                    ] ?? ''
                                                ) ?>
                                            </small>

                                        </td>

                                        <td class="text-end fw-semibold">
                                            <?= e(
                                                $displayDecimal(
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

                            <tfoot class="table-light">

                                <tr>

                                    <th colspan="2">
                                        Total
                                    </th>

                                    <th class="text-end">
                                        <?= e(
                                            $displayDecimal(
                                                $totalWeight,
                                                6
                                            )
                                        ) ?>
                                    </th>

                                    <th class="text-end">
                                        <?= e(
                                            format_number_id(
                                                $totalWeight
                                                * 100,
                                                2
                                            )
                                        ) ?>%
                                    </th>

                                </tr>

                            </tfoot>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

        </section>

        <!-- Uji Konsistensi -->
        <section class="card border-0 shadow-sm" aria-labelledby="consistency-title">

            <div class="card-header bg-white py-3">

                <h2 id="consistency-title" class="h5 mb-1">
                    4. Uji Konsistensi AHP
                </h2>

                <p class="small text-body-secondary mb-0">
                    Penilaian diterima apabila
                    Rasio Konsistensi (CR) ≤ 0,10.
                </p>

            </div>

            <div class="card-body p-4">

                <?php if ($summary === null): ?>

                    <div class="text-center text-body-secondary py-4">
                        Hasil uji konsistensi belum tersedia.
                    </div>

                <?php else: ?>

                    <div class="row g-3 mb-4">

                        <div class="col-sm-6 col-xl-3">

                            <div class="metric-box h-100">
                                <p class="metric-label">
                                    λ Maksimum
                                </p>

                                <p class="metric-value">
                                    <?= e(
                                        $displayDecimal(
                                            $summary[
                                                'lambda_max'
                                            ] ?? null,
                                            6
                                        )
                                    ) ?>
                                </p>
                            </div>

                        </div>

                        <div class="col-sm-6 col-xl-3">

                            <div class="metric-box h-100">
                                <p class="metric-label">
                                    Indeks Konsistensi
                                </p>

                                <p class="metric-value">
                                    <?= e(
                                        $displayDecimal(
                                            $summary[
                                                'consistency_index'
                                            ] ?? null,
                                            6
                                        )
                                    ) ?>
                                </p>
                            </div>

                        </div>

                        <div class="col-sm-6 col-xl-3">

                            <div class="metric-box h-100">
                                <p class="metric-label">
                                    Indeks Acak
                                </p>

                                <p class="metric-value">
                                    <?= e(
                                        $displayDecimal(
                                            $summary[
                                                'random_index'
                                            ] ?? null,
                                            2
                                        )
                                    ) ?>
                                </p>

                                <small class="text-body-secondary">
                                    n = 5
                                </small>
                            </div>

                        </div>

                        <div class="col-sm-6 col-xl-3">

                            <div class="metric-box h-100">
                                <p class="metric-label">
                                    Rasio Konsistensi
                                </p>

                                <p class="metric-value <?= $isConsistent ? 'text-success' : 'text-danger' ?>">
                                    <?= e(
                                        $displayDecimal(
                                            $consistencyRatio,
                                            6
                                        )
                                    ) ?>
                                </p>

                                <small class="text-body-secondary">
                                    Batas ≤ 0,10
                                </small>
                            </div>

                        </div>

                    </div>

                    <div class="border rounded-3 p-3 mb-4">

                        <div class="table-responsive">

                            <table class="table table-sm align-middle mb-0">

                                <tbody>

                                    <tr>
                                        <th style="width: 240px;">
                                            Jumlah Kriteria
                                        </th>

                                        <td>
                                            n = 5
                                        </td>
                                    </tr>

                                    <tr>
                                        <th>
                                            Lambda Maksimum
                                        </th>

                                        <td>
                                            <?= e(
                                                $displayDecimal(
                                                    $summary[
                                                        'lambda_max'
                                                    ] ?? null,
                                                    6
                                                )
                                            ) ?>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th>
                                            Indeks Konsistensi
                                        </th>

                                        <td>
                                            CI =
                                            (λmaks - n)
                                            /
                                            (n - 1)
                                            =
                                            <?= e(
                                                $displayDecimal(
                                                    $summary[
                                                        'consistency_index'
                                                    ] ?? null,
                                                    6
                                                )
                                            ) ?>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th>
                                            Indeks Acak
                                        </th>

                                        <td>
                                            RI =
                                            <?= e(
                                                $displayDecimal(
                                                    $summary[
                                                        'random_index'
                                                    ] ?? null,
                                                    2
                                                )
                                            ) ?>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th>
                                            Rasio Konsistensi
                                        </th>

                                        <td>
                                            CR = CI / RI =
                                            <strong>
                                                <?= e(
                                                    $displayDecimal(
                                                        $consistencyRatio,
                                                        6
                                                    )
                                                ) ?>
                                            </strong>
                                        </td>
                                    </tr>

                                </tbody>

                            </table>

                        </div>

                    </div>

                    <?php if ($isConsistent): ?>

                        <div class="alert alert-success mb-0">

                            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">

                                <div>

                                    <strong class="d-block mb-1">
                                        Penilaian Konsisten
                                    </strong>

                                    CR sebesar

                                    <strong>
                                        <?= e(
                                            $displayDecimal(
                                                $consistencyRatio,
                                                4
                                            )
                                        ) ?>
                                    </strong>

                                    memenuhi batas ≤ 0,10.
                                    Bobot AHP dapat digunakan
                                    pada TOPSIS.

                                </div>

                                <?php if (
                                    $isSupervisor
                                ): ?>

                                    <a href="<?= e( base_url( 'topsis/index.php?period_id=' . $periodId ) ) ?>" class="btn btn-success">
                                        Lanjut ke TOPSIS
                                    </a>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php else: ?>

                        <div class="alert alert-danger mb-0">

                            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">

                                <div>

                                    <strong class="d-block mb-1">
                                        Penilaian Belum Konsisten
                                    </strong>

                                    CR sebesar

                                    <strong>
                                        <?= e(
                                            $displayDecimal(
                                                $consistencyRatio,
                                                4
                                            )
                                        ) ?>
                                    </strong>

                                    melebihi batas 0,10.

                                </div>

                                <?php if ($canModifyAhp): ?>

                                    <a href="<?= e( base_url( 'ahp/input.php?period_id=' . $periodId ) ) ?>" class="btn btn-danger">
                                        Perbaiki Penilaian
                                    </a>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endif; ?>

                <?php endif; ?>

            </div>

        </section>

        <?php if ($summary !== null): ?>

            <p class="small text-body-secondary mt-3 mb-0">
                Responden:
                <?= e($assessorName) ?>

                &middot;

                Perbandingan:
                <?= e($comparisonCount) ?>/10

                &middot;

                Perhitungan:
                <?= e($calculatedAtDisplay) ?>
            </p>

        <?php endif; ?>

    </main>

</div>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

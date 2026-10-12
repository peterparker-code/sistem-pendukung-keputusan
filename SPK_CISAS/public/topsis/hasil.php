<?php

declare(strict_types=1);

/*
 * Hasil Perhitungan TOPSIS
 * SPK Pemeliharaan Motor Mesin Mixer PT CISAS
 * Hak akses:
 * Administrator:
 * - Melihat detail perhitungan TOPSIS.
 * - Melihat hasil pemeringkatan.
 * Supervisor Maintenance:
 * - Melihat detail perhitungan TOPSIS.
 * - Menjalankan perhitungan TOPSIS.
 * - Menghitung ulang TOPSIS selama periode masih Aktif.
 * Teknisi:
 * - Tidak memiliki akses ke modul TOPSIS.
 * Tahapan:
 * 1. Matriks keputusan.
 * 2. Normalisasi vektor.
 * 3. Matriks normalisasi terbobot.
 * 4. Solusi ideal positif dan negatif.
 * 5. Jarak D+ dan D-.
 * 6. Nilai preferensi.
 * 7. Peringkat prioritas.
 * Seluruh C1-C5 diperlakukan sebagai benefit terhadap urgensi.
 * Nilai lebih tinggi berarti kebutuhan pemeliharaan lebih tinggi.
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
    . 'TopsisService.php';

// Hak Akses

auth_require_role([
    'admin',
    'supervisor',
]);

// Database

$pdo = require dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'config'
    . DIRECTORY_SEPARATOR
    . 'database.php';

$topsisService = new TopsisService(
    $pdo
);

// Pastikan Akun Masih Aktif

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun pengguna sudah tidak aktif atau tidak ditemukan.'
    );

    redirect('login.php');
}

// Peran Pengguna

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

// Validasi ID Periode

$periodId = filter_var(
    $_GET['period_id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if ($periodId === false) {
    $notFoundMessage =
        'ID periode TOPSIS tidak valid.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '404.php';

    exit;
}

$periodId =
    (int) $periodId;

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

$periodStatusConfiguration =
    $periodStatusConfigurations[
        $periodStatus
    ]
    ?? [
        'label' => 'Tidak diketahui',
        'class' => 'secondary',
    ];

// Jumlah tindak lanjut yang sudah dibuat dari hasil prioritas periode ini.
$maintenanceActionStatement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM maintenance_actions ma
     INNER JOIN assessments a ON a.id = ma.assessment_id
     WHERE a.period_id = :period_id"
);
$maintenanceActionStatement->execute(['period_id' => $periodId]);
$maintenanceActionCount = (int) $maintenanceActionStatement->fetchColumn();

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

if (
    count($criteria)
    !== $expectedCriteriaCount
) {
    flash_error(
        'TOPSIS tidak dapat dijalankan karena '
        . 'jumlah kriteria aktif harus tepat lima.'
    );

    redirect(
        'topsis/index.php?period_id='
        . $periodId
    );
}

// Susun Kriteria Berdasarkan Kode

$criteriaByCode = [];

foreach (
    $criteria
    as $criterion
) {
    $criterionCode =
        (string) (
            $criterion['code']
            ?? ''
        );

    $criteriaByCode[
        $criterionCode
    ] = $criterion;
}

// Pastikan C1-C5 Tersedia

$criterionCodes = [
    'C1',
    'C2',
    'C3',
    'C4',
    'C5',
];

foreach (
    $criterionCodes
    as $criterionCode
) {
    if (
        !isset(
            $criteriaByCode[
                $criterionCode
            ]
        )
    ) {
        flash_error(
            'Kriteria '
            . $criterionCode
            . ' tidak ditemukan.'
        );

        redirect(
            'topsis/index.php?period_id='
            . $periodId
        );
    }
}

// Ambil Bobot AHP

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

         ORDER BY
            c.display_order ASC,
            c.id ASC"
    );

$weightStatement->execute([
    'period_id' =>
        $periodId,
]);

$weightRows =
    $weightStatement->fetchAll();

// Susun Bobot Berdasarkan Kode

$weights = [];

$totalWeight =
    0.0;

foreach (
    $weightRows
    as $weightRow
) {
    $code =
        (string) (
            $weightRow['code']
            ?? ''
        );

    $weight =
        floatval(
            $weightRow['weight']
            ?? 0
        );

    if (
        $code !== ''
        && $weight >= 0
    ) {
        $weights[$code] =
            $weight;

        $totalWeight +=
            $weight;
    }
}

$weightsComplete =
    count($weights)
    === $expectedCriteriaCount;

// Ambil Ringkasan Konsistensi AHP

$ahpStatement =
    $pdo->prepare(
        "SELECT
            consistency_ratio,
            is_consistent,
            calculated_at

         FROM ahp_summaries

         WHERE period_id = :period_id

         LIMIT 1"
    );

$ahpStatement->execute([
    'period_id' =>
        $periodId,
]);

$ahpData =
    $ahpStatement->fetch();

$ahpSummary =
    is_array($ahpData)
        ? $ahpData
        : null;

$consistencyRatio =
    $ahpSummary !== null
    && (
        $ahpSummary[
            'consistency_ratio'
        ] ?? null
    ) !== null
        ? floatval(
            $ahpSummary[
                'consistency_ratio'
            ]
        )
        : null;

$ahpConsistent =
    $ahpSummary !== null
    && (int) (
        $ahpSummary[
            'is_consistent'
        ] ?? 0
    ) === 1
    && $consistencyRatio !== null
    && $consistencyRatio <= 0.10;

// Ambil Enam Alternatif dan Nilai C1-C5

$assessmentStatement =
    $pdo->prepare(
        "SELECT
            a.id AS assessment_id,
            a.machine_id,
            a.status AS assessment_status,
            a.inspection_date,

            m.alternative_code,
            m.motor_name,
            m.motor_function,

            s.c1_value,
            s.c2_value,
            s.c3_value,
            s.c4_value,
            s.c5_value,

            s.c4_not_checked_count,
            s.c5_not_checked_count

         FROM assessments AS a

         INNER JOIN machines AS m
            ON m.id = a.machine_id

         INNER JOIN assessment_scores AS s
            ON s.assessment_id = a.id

         WHERE a.period_id = :period_id

           AND a.status IN (
                'validated',
                'processed'
           )

         ORDER BY
            m.alternative_code COLLATE NOCASE,
            m.id"
    );

$assessmentStatement->execute([
    'period_id' =>
        $periodId,
]);

$assessmentRows =
    $assessmentStatement->fetchAll();

// Periksa Kelengkapan Alternatif

$assessmentDataComplete =
    count($assessmentRows)
    === $expectedMachineCount;

/*
 * Matriks Keputusan
 * Bentuk:
 * C1   C2   C3   C4   C5
 * A1
 * A2
 * ...
 * A6
 */

$decisionMatrix = [];

$assessmentById = [];

$dataError = null;

foreach (
    $assessmentRows
    as $row
) {
    $assessmentId =
        (int) (
            $row[
                'assessment_id'
            ] ?? 0
        );

    if ($assessmentId < 1) {
        $dataError =
            'ID pemeriksaan tidak valid.';

        break;
    }

    // Pastikan Checklist Lengkap

    $c4Bp =
        (int) (
            $row[
                'c4_not_checked_count'
            ] ?? 0
        );

    $c5Bp =
        (int) (
            $row[
                'c5_not_checked_count'
            ] ?? 0
        );

    if (
        $c4Bp > 0
        || $c5Bp > 0
    ) {
        $dataError =
            'Masih terdapat bagian checklist yang belum diisi pada pemeriksaan '
            . (
                $row[
                    'alternative_code'
                ] ?? '-'
            )
            . '.';

        break;
    }

    $values = [];

    foreach (
        $criterionCodes
        as $criterionCode
    ) {
        $fieldName =
            strtolower(
                $criterionCode
            )
            . '_value';

        $rawValue =
            $row[
                $fieldName
            ] ?? null;

        if (
            $rawValue === null
            || $rawValue === ''
        ) {
            $dataError =
                'Nilai '
                . $criterionCode
                . ' pada '
                . (
                    $row[
                        'alternative_code'
                    ] ?? '-'
                )
                . ' belum tersedia.';

            break 2;
        }

        $number =
            floatval(
                $rawValue
            );

        if (
            !is_finite($number)
            || $number < 0
        ) {
            $dataError =
                'Nilai '
                . $criterionCode
                . ' pada '
                . (
                    $row[
                        'alternative_code'
                    ] ?? '-'
                )
                . ' tidak valid.';

            break 2;
        }

        $values[
            $criterionCode
        ] = $number;
    }

    $decisionMatrix[
        $assessmentId
    ] = $values;

    $assessmentById[
        $assessmentId
    ] = $row;
}

// Kesiapan TOPSIS

$topsisReady =
    $periodStatus === 'active'
    && $assessmentDataComplete
    && $dataError === null
    && $weightsComplete
    && $ahpConsistent;

// Helper Format Angka

$displayNumber =
    static function (
        mixed $value,
        int $decimals = 6
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

/*
 * Perhitungan TOPSIS melalui TopsisService
 * Halaman ini tidak menghitung rumus TOPSIS sendiri.
 * Seluruh proses normalisasi, pembobotan, solusi ideal,
 * jarak, nilai preferensi, ranking, dan rank_group
 * dilakukan oleh TopsisService.
 */

$calculation = null;

$criterionTypes = [
    'C1' => 'benefit',
    'C2' => 'benefit',
    'C3' => 'benefit',
    'C4' => 'benefit',
    'C5' => 'benefit',
];

if (
    $assessmentDataComplete
    && $dataError === null
    && $weightsComplete
) {
    try {
        $calculation =
            $topsisService->calculate(
                $assessmentRows,
                $weights,
                $criterionTypes
            );

        // Gunakan Bobot yang Sudah Dinormalisasi oleh Service

        $weights =
            $calculation['weights'];
    } catch (Throwable $exception) {
        error_log(
            'Preview TOPSIS gagal: '
            . $exception->getMessage()
        );

        $dataError =
            'Preview TOPSIS belum dapat dihitung. '
            . 'Periksa kelengkapan nilai C1 sampai C5 dan bobot AHP.';

        $calculation = null;
    }
}

// Kesiapan TOPSIS Final

$topsisReady =
    $periodStatus === 'active'
    && $assessmentDataComplete
    && $dataError === null
    && $weightsComplete
    && $ahpConsistent
    && $calculation !== null;

// Token CSRF

$csrfFormName =
    'topsis_calculate_'
    . $periodId;

// Proses Simpan TOPSIS

if (is_post()) {
    // Hanya Supervisor

    if (!$isSupervisor) {
        flash_error(
            'Perhitungan TOPSIS hanya dapat dijalankan '
            . 'oleh Supervisor Maintenance.'
        );

        redirect(
            'topsis/hasil.php?period_id='
            . $periodId
        );
    }

    // CSRF

    if (
        !csrf_validate_request(
            $csrfFormName
        )
    ) {
        flash_error(
            'Token keamanan tidak valid atau telah kedaluwarsa.'
        );

        redirect(
            'topsis/hasil.php?period_id='
            . $periodId
        );
    }

    // Validasi Kesiapan

    if (!$topsisReady) {
        flash_error(
            'TOPSIS belum dapat diproses. '
            . 'Pastikan AHP konsisten dan seluruh enam '
            . 'pemeriksaan sudah disetujui.'
        );

        redirect(
            'topsis/index.php?period_id='
            . $periodId
        );
    }

    /*
 * Proses dan Simpan melalui TopsisService
 * TopsisService menangani:
 * - pemeriksaan ulang periode;
 * - pemeriksaan konsistensi AHP;
 * - pengambilan bobot;
 * - pengambilan enam alternatif;
 * - seluruh rumus TOPSIS;
 * - ranking kompetisi;
 * - rank_group;
 * - penggantian hasil lama;
 * - perubahan status pemeriksaan menjadi processed;
 * - transaksi commit atau rollback.
 */

    try {
        $topsisService->processAndSave(
            $periodId,
            auth_id()
        );

        flash_success(
            'Perhitungan TOPSIS berhasil. '
            . 'Peringkat prioritas A1 sampai A6 '
            . 'telah disimpan.'
        );
    } catch (Throwable $exception) {
        error_log(
            'Proses TOPSIS gagal: '
            . $exception->getMessage()
        );

        $exceptionMessage =
            strtolower(
                $exception->getMessage()
            );

        if (
            str_contains(
                $exceptionMessage,
                'ahp'
            )
            || str_contains(
                $exceptionMessage,
                'konsisten'
            )
        ) {
            flash_error(
                'TOPSIS belum dapat diproses karena '
                . 'AHP belum lengkap atau belum konsisten.'
            );
        } elseif (
            str_contains(
                $exceptionMessage,
                'enam alternatif'
            )
            || str_contains(
                $exceptionMessage,
                'enam pemeriksaan'
            )
            || str_contains(
                $exceptionMessage,
                'a1 sampai a6'
            )
        ) {
            flash_error(
                'TOPSIS membutuhkan tepat enam pemeriksaan '
                . 'A1 sampai A6 yang telah divalidasi.'
            );
        } elseif (
            str_contains(
                $exceptionMessage,
                'bp'
            )
        ) {
            flash_error(
                'TOPSIS belum dapat diproses karena '
                . 'masih terdapat bagian checklist yang belum diisi.'
            );
        } elseif (
            str_contains(
                $exceptionMessage,
                'periode'
            )
        ) {
            flash_error(
                'TOPSIS hanya dapat diproses pada periode '
                . 'yang masih berstatus Aktif.'
            );
        } else {
            flash_error(
                'Perhitungan TOPSIS belum berhasil. '
                . 'Silakan periksa data dan coba kembali.'
            );
        }
    }

    redirect(
        'topsis/hasil.php?period_id='
        . $periodId
    );
}

// Ambil Hasil TOPSIS Tersimpan

$resultStatement =
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

         ORDER BY
            tr.ranking ASC,
            tr.preference_value DESC,
            m.alternative_code COLLATE NOCASE"
    );

$resultStatement->execute([
    'period_id' =>
        $periodId,
]);

$storedResults =
    $resultStatement->fetchAll();

$topsisComplete =
    count($storedResults)
    === $expectedMachineCount;

// Waktu Perhitungan

$calculatedAt =
    null;

foreach (
    $storedResults
    as $storedResult
) {
    if (
        (
            $storedResult[
                'calculated_at'
            ] ?? null
        ) !== null
    ) {
        $calculatedAt =
            $storedResult[
                'calculated_at'
            ];

        break;
    }
}

// Data Tampilan Perhitungan

$divisors =
    $calculation[
        'divisors'
    ] ?? [];

$normalizedMatrix = [];

foreach (
    $calculation[
        'normalized_matrix'
    ] ?? []
    as $normalizedRow
) {
    $normalizedAssessmentId =
        (int) (
            $normalizedRow[
                'assessment_id'
            ] ?? 0
        );

    if ($normalizedAssessmentId > 0) {
        $normalizedMatrix[
            $normalizedAssessmentId
        ] = $normalizedRow[
            'values'
        ] ?? [];
    }
}

$weightedMatrix = [];

foreach (
    $calculation[
        'weighted_matrix'
    ] ?? []
    as $weightedRow
) {
    $weightedAssessmentId =
        (int) (
            $weightedRow[
                'assessment_id'
            ] ?? 0
        );

    if ($weightedAssessmentId > 0) {
        $weightedMatrix[
            $weightedAssessmentId
        ] = $weightedRow[
            'values'
        ] ?? [];
    }
}

$positiveIdeal =
    $calculation[
        'positive_ideal'
    ] ?? [];

$negativeIdeal =
    $calculation[
        'negative_ideal'
    ] ?? [];

$previewResults =
    $calculation[
        'results'
    ] ?? [];

// Konfigurasi Halaman

$pageTitle =
    'Hasil TOPSIS';

$pageDescription =
    'Detail perhitungan dan pemeringkatan '
    . 'prioritas pemeliharaan enam motor.';

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

        <!-- =========================================================
             JUDUL
             ========================================================= -->

        <section class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3 mb-4">

            <div>

                <h1 class="page-title">
                    Hasil Perhitungan TOPSIS
                </h1>

                <p class="page-description">
                    Detail proses pemeringkatan prioritas
                    pemeliharaan alternatif A1 sampai A6.
                </p>

            </div>

            <div class="page-header-actions">

                <a href="<?= e( base_url( 'topsis/index.php?period_id=' . $periodId ) ) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>

                <?php if ($topsisComplete): ?>

                    <a href="<?= e( base_url( 'topsis/detail.php?period_id=' . $periodId ) ) ?>" class="btn btn-outline-primary">
                        Lihat per Motor
                    </a>

                <?php endif; ?>

            </div>

        </section>

        <!-- =========================================================
             PERIODE
             ========================================================= -->

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
                            AHP CR:

                            <strong>
                                <?= e(
                                    $displayNumber(
                                        $consistencyRatio,
                                        4
                                    )
                                ) ?>
                            </strong>
                        </p>

                    </div>

                </div>

            </div>

        </section>

        <?php if ($isAdmin): ?>

            <div class="alert alert-info mb-4" role="status">
                Administrator dapat melihat hasil TOPSIS.
                Proses perhitungan dilakukan oleh
                Supervisor Maintenance.
            </div>

        <?php endif; ?>

        <!-- =========================================================
             ERROR DATA
             ========================================================= -->

        <?php if ($dataError !== null): ?>

            <div class="alert alert-danger mb-4" role="alert">
                <strong class="d-block mb-1">
                    Data TOPSIS belum siap
                </strong>

                <?= e(
                    $dataError
                ) ?>
            </div>

        <?php endif; ?>

        <?php if (!$ahpConsistent): ?>

            <div class="alert alert-danger mb-4" role="alert">
                AHP belum dapat digunakan karena
                Rasio Konsistensi belum memenuhi
                batas CR ≤ 0,10.
            </div>

        <?php endif; ?>

        <?php if (!$weightsComplete): ?>

            <div class="alert alert-warning mb-4" role="alert">
                Lima bobot AHP belum tersedia secara lengkap.
            </div>

        <?php endif; ?>

        <?php if (!$assessmentDataComplete): ?>

            <div class="alert alert-warning mb-4" role="alert">
                TOPSIS membutuhkan tepat enam
                pemeriksaan motor yang telah disetujui.
                Saat ini tersedia
                <?= e(
                    count(
                        $assessmentRows
                    )
                ) ?>
                pemeriksaan.
            </div>

        <?php endif; ?>

        <?php if ($topsisComplete && $maintenanceActionCount > 0): ?>
            <div class="alert alert-warning mb-4" role="alert">
                <strong class="d-block mb-1">Hasil sudah digunakan untuk tindak lanjut</strong>
                Terdapat <strong><?= e((string) $maintenanceActionCount) ?></strong> tindak lanjut pada periode ini.
                TOPSIS tetap boleh dihitung ulang untuk kebutuhan koreksi skripsi, tetapi jika ranking berubah maka
                rekomendasi/tindakan yang sudah dibuat perlu ditinjau kembali. Riwayat perhitungan lama tetap tersimpan.
            </div>
        <?php endif; ?>

        <!-- =========================================================
             PROSES TOPSIS
             ========================================================= -->

        <?php if (
            $isSupervisor
            && $topsisReady
        ): ?>

            <section class="card border-0 shadow-sm mb-4">

                <div class="card-body p-4">

                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">

                        <div>

                            <h2 class="h5 mb-1">
                                <?= $topsisComplete
                                    ? 'Hitung Ulang TOPSIS'
                                    : 'Proses TOPSIS' ?>
                            </h2>

                            <p class="text-body-secondary mb-0">
                                Alur sederhananya: normalisasi data, kalikan
                                dengan bobot AHP, tentukan kondisi ideal, lalu
                                hitung nilai preferensi dan urutan prioritas.
                            </p>

                        </div>

                        <form method="post" action="<?= e( base_url( 'topsis/hasil.php?period_id=' . $periodId ) ) ?>">

                            <?= csrf_field(
                                $csrfFormName
                            ) ?>

                            <button type="submit" class="btn btn-primary" data-confirm="<?= e( $topsisComplete ? ($maintenanceActionCount > 0 ? 'Hasil TOPSIS sudah digunakan pada tindak lanjut. Hitung ulang dapat mengubah ranking dan tindak lanjut lama perlu ditinjau. Lanjutkan?' : 'Hitung ulang TOPSIS periode ini?') : 'Jalankan TOPSIS periode ini?' ) ?>" data-loading-text="Menghitung...">
                                <?= $topsisComplete
                                    ? 'Hitung Ulang'
                                    : 'Hitung Sekarang' ?>
                            </button>

                        </form>

                    </div>

                </div>

            </section>

        <?php endif; ?>

        <!-- Detail perhitungan TOPSIS selalu ditampilkan agar alurnya langsung terlihat. -->
        <div class="mb-3">
            <h2 class="h4 mb-1">Detail Perhitungan TOPSIS</h2>
            <p class="text-body-secondary mb-0">
                Tahapan perhitungan ditampilkan langsung dari matriks keputusan sampai nilai preferensi dan ranking.
            </p>
        </div>
        <div id="topsis-calculation-details" class="calculation-details">
        <!-- =========================================================
             1. MATRIKS KEPUTUSAN
             ========================================================= -->

        <section class="card border-0 shadow-sm mb-4">

            <div class="card-header bg-white py-3">

                <h2 class="h5 mb-1">
                    1. Matriks Keputusan
                </h2>

                <p class="small text-body-secondary mb-0">
                    Nilai akhir C1 sampai C5
                    dari enam alternatif motor.
                </p>

            </div>

            <div class="card-body p-0">

                <div class="table-responsive">

                    <table class="table table-bordered table-hover align-middle text-center mb-0">

                        <thead class="table-light">

                            <tr>

                                <th>
                                    Alternatif
                                </th>

                                <?php foreach (
                                    $criterionCodes
                                    as $code
                                ): ?>

                                    <th>
                                        <?= e($code) ?>
                                    </th>

                                <?php endforeach; ?>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach (
                                $assessmentRows
                                as $row
                            ): ?>

                                <?php
                                $assessmentId =
                                    (int) (
                                        $row[
                                            'assessment_id'
                                        ] ?? 0
                                    );
                                ?>

                                <tr>

                                    <th class="table-light">

                                        <?= e(
                                            $row[
                                                'alternative_code'
                                            ] ?? '-'
                                        ) ?>

                                    </th>

                                    <?php foreach (
                                        $criterionCodes
                                        as $code
                                    ): ?>

                                        <?php
                                        $field =
                                            strtolower(
                                                $code
                                            )
                                            . '_value';
                                        ?>

                                        <td>
                                            <?= e(
                                                $displayNumber(
                                                    $row[
                                                        $field
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

            </div>

        </section>

        <!-- =========================================================
             BOBOT
             ========================================================= -->

        <section class="card border-0 shadow-sm mb-4">

            <div class="card-header bg-white py-3">

                <h2 class="h5 mb-1">
                    Bobot AHP
                </h2>

                <p class="small text-body-secondary mb-0">
                    Bobot yang digunakan dalam
                    matriks normalisasi terbobot.
                </p>

            </div>

            <div class="card-body p-0">

                <div class="table-responsive">

                    <table class="table table-bordered align-middle text-center mb-0">

                        <thead class="table-light">

                            <tr>

                                <?php foreach (
                                    $criterionCodes
                                    as $code
                                ): ?>

                                    <th>
                                        <?= e($code) ?>
                                    </th>

                                <?php endforeach; ?>

                            </tr>

                        </thead>

                        <tbody>

                            <tr>

                                <?php foreach (
                                    $criterionCodes
                                    as $code
                                ): ?>

                                    <td>
                                        <?= e(
                                            $displayNumber(
                                                $weights[
                                                    $code
                                                ] ?? null,
                                                6
                                            )
                                        ) ?>
                                    </td>

                                <?php endforeach; ?>

                            </tr>

                        </tbody>

                    </table>

                </div>

            </div>

        </section>

        <!-- =========================================================
             2. NORMALISASI
             ========================================================= -->

        <?php if ($calculation !== null): ?>

            <section class="card border-0 shadow-sm mb-4">

                <div class="card-header bg-white py-3">

                    <h2 class="h5 mb-1">
                        2. Matriks Normalisasi
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Normalisasi menggunakan
                        akar jumlah kuadrat setiap kolom.
                    </p>

                </div>

                <div class="card-body p-0">

                    <div class="table-responsive">

                        <table class="table table-bordered table-hover align-middle text-center mb-0">

                            <thead class="table-light">

                                <tr>

                                    <th>
                                        Alternatif
                                    </th>

                                    <?php foreach (
                                        $criterionCodes
                                        as $code
                                    ): ?>

                                        <th>
                                            <?= e($code) ?>
                                        </th>

                                    <?php endforeach; ?>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach (
                                    $assessmentRows
                                    as $row
                                ): ?>

                                    <?php
                                    $assessmentId =
                                        (int)
                                        $row[
                                            'assessment_id'
                                        ];
                                    ?>

                                    <tr>

                                        <th class="table-light">
                                            <?= e(
                                                $row[
                                                    'alternative_code'
                                                ]
                                            ) ?>
                                        </th>

                                        <?php foreach (
                                            $criterionCodes
                                            as $code
                                        ): ?>

                                            <td>
                                                <?= e(
                                                    $displayNumber(
                                                        $normalizedMatrix[
                                                            $assessmentId
                                                        ][
                                                            $code
                                                        ] ?? null,
                                                        6
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
                                        Pembagi
                                    </th>

                                    <?php foreach (
                                        $criterionCodes
                                        as $code
                                    ): ?>

                                        <th>
                                            <?= e(
                                                $displayNumber(
                                                    $divisors[
                                                        $code
                                                    ] ?? null,
                                                    6
                                                )
                                            ) ?>
                                        </th>

                                    <?php endforeach; ?>

                                </tr>

                            </tfoot>

                        </table>

                    </div>

                </div>

            </section>

            <!-- =====================================================
                 3. NORMALISASI TERBOBOT
                 ===================================================== -->

            <section class="card border-0 shadow-sm mb-4">

                <div class="card-header bg-white py-3">

                    <h2 class="h5 mb-1">
                        3. Matriks Normalisasi Terbobot
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Nilai normalisasi dikalikan
                        dengan bobot hasil AHP.
                    </p>

                </div>

                <div class="card-body p-0">

                    <div class="table-responsive">

                        <table class="table table-bordered table-hover align-middle text-center mb-0">

                            <thead class="table-light">

                                <tr>

                                    <th>
                                        Alternatif
                                    </th>

                                    <?php foreach (
                                        $criterionCodes
                                        as $code
                                    ): ?>

                                        <th>
                                            <?= e($code) ?>
                                        </th>

                                    <?php endforeach; ?>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach (
                                    $assessmentRows
                                    as $row
                                ): ?>

                                    <?php
                                    $assessmentId =
                                        (int)
                                        $row[
                                            'assessment_id'
                                        ];
                                    ?>

                                    <tr>

                                        <th class="table-light">
                                            <?= e(
                                                $row[
                                                    'alternative_code'
                                                ]
                                            ) ?>
                                        </th>

                                        <?php foreach (
                                            $criterionCodes
                                            as $code
                                        ): ?>

                                            <td>
                                                <?= e(
                                                    $displayNumber(
                                                        $weightedMatrix[
                                                            $assessmentId
                                                        ][
                                                            $code
                                                        ] ?? null,
                                                        6
                                                    )
                                                ) ?>
                                            </td>

                                        <?php endforeach; ?>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </section>

            <!-- =====================================================
                 4. SOLUSI IDEAL
                 ===================================================== -->

            <section class="card border-0 shadow-sm mb-4">

                <div class="card-header bg-white py-3">

                    <h2 class="h5 mb-1">
                        4. Solusi Ideal Positif dan Negatif
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Seluruh C1–C5 merupakan benefit
                        terhadap urgensi pemeliharaan.
                    </p>

                </div>

                <div class="card-body p-0">

                    <div class="table-responsive">

                        <table class="table table-bordered align-middle text-center mb-0">

                            <thead class="table-light">

                                <tr>

                                    <th>
                                        Solusi
                                    </th>

                                    <?php foreach (
                                        $criterionCodes
                                        as $code
                                    ): ?>

                                        <th>
                                            <?= e($code) ?>
                                        </th>

                                    <?php endforeach; ?>

                                </tr>

                            </thead>

                            <tbody>

                                <tr>

                                    <th class="table-light">
                                        A+
                                    </th>

                                    <?php foreach (
                                        $criterionCodes
                                        as $code
                                    ): ?>

                                        <td>
                                            <?= e(
                                                $displayNumber(
                                                    $positiveIdeal[
                                                        $code
                                                    ] ?? null,
                                                    6
                                                )
                                            ) ?>
                                        </td>

                                    <?php endforeach; ?>

                                </tr>

                                <tr>

                                    <th class="table-light">
                                        A−
                                    </th>

                                    <?php foreach (
                                        $criterionCodes
                                        as $code
                                    ): ?>

                                        <td>
                                            <?= e(
                                                $displayNumber(
                                                    $negativeIdeal[
                                                        $code
                                                    ] ?? null,
                                                    6
                                                )
                                            ) ?>
                                        </td>

                                    <?php endforeach; ?>

                                </tr>

                            </tbody>

                        </table>

                    </div>

                </div>

            </section>

            <!-- =====================================================
                 5. JARAK DAN PREFERENSI
                 ===================================================== -->

            <section class="card border-0 shadow-sm mb-4">

                <div class="card-header bg-white py-3">

                    <h2 class="h5 mb-1">
                        5. Jarak dan Nilai Preferensi
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Nilai preferensi dihitung dari
                        kedekatan alternatif terhadap
                        solusi ideal positif.
                    </p>

                </div>

                <div class="card-body p-0">

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

                                    <th class="text-end">
                                        D+
                                    </th>

                                    <th class="text-end">
                                        D−
                                    </th>

                                    <th class="text-end">
                                        Preferensi
                                    </th>

                                    <th class="text-center">
                                        Peringkat
                                    </th>

                                    <th class="text-center">
                                        Kelompok
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach (
                                    $previewResults
                                    as $result
                                ): ?>

                                    <?php
                                    $assessmentId =
                                        (int)
                                        $result[
                                            'assessment_id'
                                        ];

                                    $alternative =
                                        $assessmentById[
                                            $assessmentId
                                        ];
                                    ?>

                                    <tr>

                                        <td>

                                            <span class="badge text-bg-primary">
                                                <?= e(
                                                    $alternative[
                                                        'alternative_code'
                                                    ]
                                                ) ?>
                                            </span>

                                        </td>

                                        <td>
                                            <?= e(
                                                $alternative[
                                                    'motor_name'
                                                ]
                                            ) ?>
                                        </td>

                                        <td class="text-end">
                                            <?= e(
                                                $displayNumber(
                                                    $result[
                                                        'positive_distance'
                                                    ],
                                                    6
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-end">
                                            <?= e(
                                                $displayNumber(
                                                    $result[
                                                        'negative_distance'
                                                    ],
                                                    6
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-end fw-semibold">
                                            <?= e(
                                                $displayNumber(
                                                    $result[
                                                        'preference_value'
                                                    ],
                                                    6
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-center">

                                            <strong>
                                                <?= e(
                                                    $result[
                                                        'ranking'
                                                    ]
                                                ) ?>
                                            </strong>

                                        </td>

                                        <td class="text-center">

                                            <span class="badge text-bg-light border">
                                                <?= e(
                                                    $result[
                                                        'rank_group'
                                                    ] ?? '-'
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

        </div>

        <!-- =========================================================
             6. HASIL PERINGKAT TERSIMPAN
             ========================================================= -->

        <section class="card border-0 shadow-sm">

            <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">

                <div>

                    <h2 class="h5 mb-1">
                        6. Hasil Peringkat Prioritas
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Nilai preferensi terbesar
                        memperoleh prioritas tertinggi.
                    </p>

                </div>

                <?php if ($topsisComplete): ?>

                    <span class="badge text-bg-success">
                        6 Hasil Tersimpan
                    </span>

                <?php endif; ?>

            </div>

            <div class="card-body p-0">

                <?php if (!$topsisComplete): ?>

                    <div class="text-center text-body-secondary p-5">
                        Hasil TOPSIS belum disimpan.
                        Supervisor perlu menjalankan
                        proses TOPSIS terlebih dahulu.
                    </div>

                <?php else: ?>

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
                                        D+
                                    </th>

                                    <th class="text-end">
                                        D−
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
                                    $storedResults
                                    as $result
                                ): ?>

                                    <tr>

                                        <td class="text-center">

                                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fw-bold" style="width: 42px; height: 42px;">
                                                <?= e(
                                                    $result[
                                                        'ranking'
                                                    ]
                                                ) ?>
                                            </span>

                                        </td>

                                        <td class="text-center">
                                            <?= e(
                                                $result[
                                                    'rank_group'
                                                ] ?? '-'
                                            ) ?>
                                        </td>

                                        <td>

                                            <strong>
                                                <?= e(
                                                    $result[
                                                        'alternative_code'
                                                    ]
                                                ) ?>
                                            </strong>

                                        </td>

                                        <td>
                                            <?= e(
                                                $result[
                                                    'motor_name'
                                                ]
                                            ) ?>
                                        </td>

                                        <td class="text-end">
                                            <?= e(
                                                $displayNumber(
                                                    $result[
                                                        'positive_distance'
                                                    ],
                                                    6
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-end">
                                            <?= e(
                                                $displayNumber(
                                                    $result[
                                                        'negative_distance'
                                                    ],
                                                    6
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-end fw-semibold">
                                            <?= e(
                                                $displayNumber(
                                                    $result[
                                                        'preference_value'
                                                    ],
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
                                                        . $result[
                                                            'ranking'
                                                        ]
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

        <?php if (
            $topsisComplete
            && $calculatedAt !== null
        ): ?>

            <p class="small text-body-secondary mt-3 mb-0">
                Perhitungan TOPSIS terakhir:

                <?= e(
                    format_date_id(
                        $calculatedAt,
                        true
                    )
                ) ?>
            </p>

        <?php endif; ?>

        <div class="alert alert-light border mt-4 mb-0">
            Hasil TOPSIS menunjukkan
            <strong>prioritas relatif pemeliharaan</strong>,
            bukan diagnosis otomatis bahwa motor pada
            peringkat pertama pasti mengalami kerusakan.
        </div>

    </main>

</div>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

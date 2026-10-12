<?php

declare(strict_types=1);

/*
 * Halaman Utama Laporan SPK PT CISAS
 * Halaman ini menjadi pusat pemilihan laporan berdasarkan periode.
 * Hak akses:
 * - Administrator       : melihat dan mencetak seluruh laporan;
 * - Supervisor          : melihat dan mencetak seluruh laporan;
 * - Teknisi             : melihat laporan pemeriksaan miliknya sendiri.
 * Jenis laporan:
 * - laporan pemeriksaan C1 sampai C5;
 * - laporan pembobotan AHP;
 * - laporan hasil TOPSIS;
 * - laporan peringkat dan tindak lanjut pemeliharaan.
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'helpers'
    . DIRECTORY_SEPARATOR . 'auth.php';

// Batasi Hak Akses

auth_require_role([
    'admin',
    'supervisor',
    'technician',
]);

// Koneksi Database

$pdo = require dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'config'
    . DIRECTORY_SEPARATOR . 'database.php';

// Pastikan Akun Masih Aktif

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun pengguna sudah tidak aktif atau tidak ditemukan.'
    );

    redirect('login.php');
}

// Informasi Pengguna

$currentUserId = auth_id();

$currentRole = auth_role();

$isAdmin =
    $currentRole === 'admin';

$isSupervisor =
    $currentRole === 'supervisor';

$isTechnician =
    $currentRole === 'technician';

if (
    $currentUserId === null
    || $currentUserId < 1
) {
    flash_error(
        'Sesi pengguna tidak valid. Silakan masuk kembali.'
    );

    redirect('login.php');
}

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

$periods = [];

$selectedPeriod = null;

$ahpSummary = null;

$topPriority = null;

$pageError = null;

$summary = [
    'assessment_count' => 0,
    'validated_count' => 0,
    'processed_count' => 0,
    'returned_count' => 0,
    'danger_count' => 0,
    'score_count' => 0,
    'ahp_comparison_count' => 0,
    'ahp_weight_count' => 0,
    'topsis_count' => 0,
    'action_count' => 0,
    'completed_action_count' => 0,
];

// Ambil Daftar Periode

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

    $periods =
        $periodStatement->fetchAll();
} catch (Throwable $exception) {
    error_log(
        'Daftar periode laporan gagal dimuat: '
        . $exception->getMessage()
    );

    $pageError =
        'Daftar periode laporan belum dapat dimuat.';
}

// Tentukan Periode yang Dipilih

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
            'ID periode laporan tidak valid.';

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
            'Periode laporan yang dipilih tidak ditemukan.';

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

    // Jika Tidak Ada Periode Aktif, Gunakan Periode Pertama

    $selectedPeriod ??=
        $periods[0];
}

$selectedPeriodId =
    $selectedPeriod !== null
        ? (int) $selectedPeriod['id']
        : null;

// Ambil Ringkasan Periode Terpilih

if ($selectedPeriodId !== null) {
    try {
        /*
 * Pembatasan Data Teknisi
 * Admin dan Supervisor melihat seluruh pemeriksaan pada periode.
 * Teknisi hanya melihat pemeriksaan yang dibuat oleh akunnya sendiri.
 */

        $assessmentOwnerSql =
            $isTechnician
                ? 'AND a.inspector_id = :current_user_id'
                : '';

        $assessmentParameters = [
            'period_id' =>
                $selectedPeriodId,
        ];

        if ($isTechnician) {
            $assessmentParameters[
                'current_user_id'
            ] = $currentUserId;
        }

        // Ringkasan Pemeriksaan

        $summaryStatement = $pdo->prepare(
            "SELECT
                COUNT(*) AS assessment_count,

                COALESCE(SUM(
                    CASE
                        WHEN a.status IN (
                            'validated',
                            'processed'
                        )
                        THEN 1
                        ELSE 0
                    END
                ), 0) AS validated_count,

                COALESCE(SUM(
                    CASE
                        WHEN a.status = 'processed'
                        THEN 1
                        ELSE 0
                    END
                ), 0) AS processed_count,

                COALESCE(SUM(
                    CASE
                        WHEN a.status = 'returned'
                        THEN 1
                        ELSE 0
                    END
                ), 0) AS returned_count,

                COALESCE(SUM(
                    CASE
                        WHEN COALESCE(
                            s.c4_has_danger,
                            0
                        ) = 1

                        OR COALESCE(
                            s.c5_has_danger,
                            0
                        ) = 1

                        THEN 1
                        ELSE 0
                    END
                ), 0) AS danger_count

             FROM assessments AS a

             LEFT JOIN assessment_scores AS s
                ON s.assessment_id = a.id

             WHERE a.period_id = :period_id

             {$assessmentOwnerSql}"
        );

        $summaryStatement->execute(
            $assessmentParameters
        );

        $assessmentSummary =
            $summaryStatement->fetch();

        if (is_array($assessmentSummary)) {
            $summary['assessment_count'] = (int) (
                $assessmentSummary[
                    'assessment_count'
                ]
                ?? 0
            );

            $summary['validated_count'] = (int) (
                $assessmentSummary[
                    'validated_count'
                ]
                ?? 0
            );

            $summary['processed_count'] = (int) (
                $assessmentSummary[
                    'processed_count'
                ]
                ?? 0
            );

            $summary['returned_count'] = (int) (
                $assessmentSummary[
                    'returned_count'
                ]
                ?? 0
            );

            $summary['danger_count'] = (int) (
                $assessmentSummary[
                    'danger_count'
                ]
                ?? 0
            );
        }

        // Jumlah Nilai Pemeriksaan C1 sampai C5 yang Lengkap

        $scoreStatement = $pdo->prepare(
            "SELECT COUNT(*)

             FROM assessment_scores AS s

             INNER JOIN assessments AS a
                ON a.id = s.assessment_id

             WHERE a.period_id = :period_id

               {$assessmentOwnerSql}

               AND s.c1_value IS NOT NULL
               AND s.c2_value IS NOT NULL
               AND s.c3_value IS NOT NULL
               AND s.c4_value IS NOT NULL
               AND s.c5_value IS NOT NULL
               AND s.c4_not_checked_count = 0
               AND s.c5_not_checked_count = 0"
        );

        $scoreStatement->execute(
            $assessmentParameters
        );

        $summary['score_count'] =
            (int) $scoreStatement->fetchColumn();

        /*
 * Ringkasan AHP, TOPSIS, dan Tindak Lanjut
 * Data manajerial hanya dibaca oleh Admin dan Supervisor.
 */

        if (!$isTechnician) {
            // Jumlah Perbandingan AHP

            $comparisonStatement = $pdo->prepare(
                "SELECT COUNT(*)

                 FROM ahp_comparisons

                 WHERE period_id = :period_id"
            );

            $comparisonStatement->execute([
                'period_id' =>
                    $selectedPeriodId,
            ]);

            $summary['ahp_comparison_count'] =
                (int) $comparisonStatement->fetchColumn();

            // Jumlah Bobot AHP Aktif C1 sampai C5

            $weightStatement = $pdo->prepare(
                "SELECT COUNT(*)

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
                   )"
            );

            $weightStatement->execute([
                'period_id' =>
                    $selectedPeriodId,
            ]);

            $summary['ahp_weight_count'] =
                (int) $weightStatement->fetchColumn();

            // Ringkasan Konsistensi AHP

            $ahpStatement = $pdo->prepare(
                "SELECT
                    ahs.lambda_max,
                    ahs.consistency_index,
                    ahs.random_index,
                    ahs.consistency_ratio,
                    ahs.is_consistent,
                    ahs.calculated_at,

                    assessor.name AS assessor_name

                 FROM ahp_summaries AS ahs

                 INNER JOIN users AS assessor
                    ON assessor.id = ahs.assessor_id

                 WHERE ahs.period_id = :period_id

                 LIMIT 1"
            );

            $ahpStatement->execute([
                'period_id' =>
                    $selectedPeriodId,
            ]);

            $ahpData =
                $ahpStatement->fetch();

            $ahpSummary = is_array($ahpData)
                ? $ahpData
                : null;

            // Jumlah Hasil TOPSIS A1 sampai A6

            $topsisStatement = $pdo->prepare(
                "SELECT COUNT(
                    DISTINCT m.alternative_code
                 )

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
                   AND tr.ranking IS NOT NULL
                   AND tr.rank_group IS NOT NULL"
            );

            $topsisStatement->execute([
                'period_id' =>
                    $selectedPeriodId,
            ]);

            $summary['topsis_count'] =
                (int) $topsisStatement->fetchColumn();

            // Prioritas Tertinggi

            $priorityStatement = $pdo->prepare(
                "SELECT
                    m.alternative_code,
                    m.motor_name,

                    tr.preference_value,
                    tr.ranking,
                    tr.rank_group,
                    tr.priority_label

                 FROM topsis_results AS tr

                 INNER JOIN assessments AS a
                    ON a.id = tr.assessment_id

                 INNER JOIN machines AS m
                    ON m.id = a.machine_id

                 WHERE tr.period_id = :period_id
                   AND tr.ranking IS NOT NULL
                   AND tr.rank_group IS NOT NULL

                 ORDER BY
                    tr.ranking ASC,
                    tr.preference_value DESC,
                    m.alternative_code COLLATE NOCASE

                 LIMIT 1"
            );

            $priorityStatement->execute([
                'period_id' =>
                    $selectedPeriodId,
            ]);

            $priorityData =
                $priorityStatement->fetch();

            $topPriority = is_array($priorityData)
                ? $priorityData
                : null;

            // Jumlah Tindak Lanjut Pemeliharaan

            $actionStatement = $pdo->prepare(
                "SELECT
                    COUNT(*) AS action_count,

                    COALESCE(SUM(
                        CASE
                            WHEN ma.status = 'completed'
                            THEN 1
                            ELSE 0
                        END
                    ), 0) AS completed_action_count

                 FROM maintenance_actions AS ma

                 INNER JOIN assessments AS a
                    ON a.id = ma.assessment_id

                 WHERE a.period_id = :period_id"
            );

            $actionStatement->execute([
                'period_id' =>
                    $selectedPeriodId,
            ]);

            $actionData =
                $actionStatement->fetch();

            if (is_array($actionData)) {
                $summary['action_count'] = (int) (
                    $actionData[
                        'action_count'
                    ]
                    ?? 0
                );

                $summary['completed_action_count'] = (int) (
                    $actionData[
                        'completed_action_count'
                    ]
                    ?? 0
                );
            }
        }
    } catch (Throwable $exception) {
        error_log(
            'Ringkasan laporan gagal dimuat: '
            . $exception->getMessage()
        );

        $pageError =
            'Ringkasan laporan untuk periode yang dipilih '
            . 'belum dapat dimuat.';
    }
}

// Status Ketersediaan Laporan

$inspectionReportAvailable =
    $isTechnician
        ? $summary['assessment_count'] > 0
        : $summary['validated_count'] > 0;

$ahpReportAvailable =
    !$isTechnician

    && $summary['ahp_comparison_count']
        === $expectedComparisonCount

    && $summary['ahp_weight_count']
        === $expectedCriteriaCount

    && $ahpSummary !== null;

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
    !$isTechnician

    && $ahpSummary !== null

    && (int) (
        $ahpSummary['is_consistent']
        ?? 0
    ) === 1

    && $consistencyRatio !== null

    && is_finite(
        $consistencyRatio
    )

    && $consistencyRatio >= 0

    && $consistencyRatio <= 0.10;

$topsisReportAvailable =
    !$isTechnician

    && $summary['topsis_count']
        === $expectedMachineCount;

$rankingReportAvailable =
    $topsisReportAvailable;

// Konfigurasi Status Periode

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

$selectedPeriodStatus =
    $selectedPeriod !== null
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
        'label' => 'Tidak Diketahui',
        'class' => 'secondary',
    ];

// Susun Daftar Laporan

$selectedPeriodQuery =
    $selectedPeriodId !== null
        ? '?period_id=' . $selectedPeriodId
        : '';

$reportCards = [
    [
        'code' => 'LP-01',

        'title' =>
            'Laporan Pemeriksaan',

        'description' =>
            $isTechnician
                ? 'Menampilkan pemeriksaan C1–C5 yang dibuat oleh akun Teknisi yang sedang login.'
                : 'Menampilkan hasil pengukuran C1–C3, nilai C1–C5, status pemeriksaan, pemeriksa, dan validator.',

        'path' =>
            'laporan/pemeriksaan.php'
            . $selectedPeriodQuery,

        'available' =>
            $inspectionReportAvailable,

        'status_text' =>
            $isTechnician
                ? $summary['assessment_count']
                    . ' pemeriksaan milik saya'
                : $summary['validated_count']
                    . ' pemeriksaan disetujui',

        'requirement' =>
            $isTechnician
                ? 'Minimal satu pemeriksaan telah dibuat oleh akun Teknisi ini.'
                : 'Minimal satu pemeriksaan telah divalidasi.',
    ],
];

if (!$isTechnician) {
    $reportCards[] = [
        'code' => 'LP-02',

        'title' =>
            'Laporan Pembobotan AHP',

        'description' =>
            'Menampilkan matriks perbandingan, '
            . 'bobot lima kriteria, nilai konsistensi, '
            . 'dan responden ahli.',

        'path' =>
            'laporan/ahp.php'
            . $selectedPeriodQuery,

        'available' =>
            $ahpReportAvailable,

        'status_text' =>
            $summary['ahp_weight_count']
            . ' dari '
            . $expectedCriteriaCount
            . ' bobot tersedia',

        'requirement' =>
            'Perbandingan AHP dan hasil bobot harus lengkap.',
    ];

    $reportCards[] = [
        'code' => 'LP-03',

        'title' =>
            'Laporan Perhitungan TOPSIS',

        'description' =>
            'Menampilkan nilai keputusan, '
            . 'jarak solusi ideal, nilai preferensi, '
            . 'ranking, dan kelompok prioritas motor.',

        'path' =>
            'laporan/topsis.php'
            . $selectedPeriodQuery,

        'available' =>
            $topsisReportAvailable,

        'status_text' =>
            $summary['topsis_count']
            . ' dari '
            . $expectedMachineCount
            . ' hasil tersedia',

        'requirement' =>
            'Perhitungan TOPSIS A1 sampai A6 harus sudah dijalankan.',
    ];

    $reportCards[] = [
        'code' => 'LP-04',

        'title' =>
            'Laporan Prioritas & Tindak Lanjut',

        'description' =>
            'Menampilkan urutan prioritas A1–A6, '
            . 'kelompok prioritas, rekomendasi, pelaksana, '
            . 'target, dan status tindak lanjut.',

        'path' =>
            'laporan/peringkat.php'
            . $selectedPeriodQuery,

        'available' =>
            $rankingReportAvailable,

        'status_text' =>
            $summary['action_count']
            . ' tindak lanjut dicatat',

        'requirement' =>
            'Hasil peringkat TOPSIS lengkap harus tersedia.',
    ];
}

// Konfigurasi Halaman

$pageTitle =
    'Laporan';

$pageDescription =
    $isTechnician
        ? 'Laporan terbatas untuk pemeriksaan yang dibuat oleh Teknisi yang login.'
        : 'Pusat laporan pemeriksaan, AHP, TOPSIS, '
            . 'dan prioritas pemeliharaan motor.';

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
                    Data laporan belum dapat dimuat
                </strong>

                <?= e($pageError) ?>
            </div>
        <?php endif; ?>

        <section class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <h1 class="page-title mb-1">
                    Laporan
                </h1>

                <p class="page-description mb-0">
                    Pilih periode dan jenis laporan yang
                    akan ditampilkan atau dicetak.
                </p>
            </div>

            <span class="badge text-bg-light border p-2">
                Akses <?= e(
                    auth_role_label($currentRole)
                ) ?>
            </span>
        </section>

        <?php if ($isTechnician): ?>
            <div class="alert alert-info mb-4" role="status">
                Teknisi hanya dapat melihat laporan pemeriksaan
                yang dibuat oleh akun sendiri. Laporan AHP,
                TOPSIS, dan peringkat lengkap hanya tersedia
                untuk Administrator dan Supervisor Maintenance.
            </div>
        <?php elseif ($isAdmin): ?>
            <div class="alert alert-secondary mb-4" role="status">
                Administrator dapat melihat dan mencetak
                laporan, tetapi tidak dapat mengubah hasil
                AHP, TOPSIS, rekomendasi, atau tindakan
                pemeliharaan dari halaman laporan.
            </div>
        <?php elseif ($isSupervisor): ?>
            <div class="alert alert-info mb-4" role="status">
                Laporan menggunakan data pemeriksaan yang
                telah divalidasi, hasil pembobotan AHP
                Supervisor, hasil TOPSIS, dan tindak lanjut
                pada periode terpilih.
            </div>
        <?php endif; ?>

        <section class="card border-0 shadow-sm mb-4" aria-labelledby="report-period-title">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3">
                    <div class="flex-grow-1">
                        <h2 id="report-period-title" class="h5 mb-1">
                            Periode Laporan
                        </h2>

                        <p class="small text-body-secondary mb-3">
                            Seluruh ringkasan dan tombol laporan
                            mengikuti periode yang dipilih.
                        </p>

                        <form method="get" action="<?= e(base_url( 'laporan/index.php' )) ?>" class="row g-3 align-items-end">
                            <div class="col-md-8">
                                <label for="period_id" class="form-label">
                                    Pilih Periode
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
                                            $periodStatus =
                                                (string) (
                                                    $period['status']
                                                    ?? 'draft'
                                                );

                                            $periodStatusInfo =
                                                $periodStatusConfigurations[
                                                    $periodStatus
                                                ]
                                                ?? [
                                                    'label' =>
                                                        'Tidak Diketahui',
                                                ];
                                            ?>

                                            <option value="<?= e( $period['id'] ) ?>" <?= selected( $selectedPeriodId, (int) $period['id'] ) ?>>
                                                <?= e(
                                                    $period['name']
                                                ) ?>

                                                —

                                                <?= e(
                                                    $periodStatusInfo[
                                                        'label'
                                                    ]
                                                ) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <button type="submit" class="btn btn-primary w-100" <?= $periods === [] ? 'disabled' : '' ?>>
                                    Tampilkan Periode
                                </button>
                            </div>
                        </form>
                    </div>

                    <?php if ($selectedPeriod !== null): ?>
                        <div class="rounded-3 bg-body-tertiary border p-3" style="min-width: 280px;">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                                <strong>
                                    <?= e(
                                        $selectedPeriod['name']
                                    ) ?>
                                </strong>

                                <span class="badge text-bg-<?= e( $selectedPeriodStatusInfo[ 'class' ] ) ?>">
                                    <?= e(
                                        $selectedPeriodStatusInfo[
                                            'label'
                                        ]
                                    ) ?>
                                </span>
                            </div>

                            <small class="text-body-secondary d-block">
                                <?= e(format_date_id(
                                    $selectedPeriod['start_date']
                                )) ?>

                                sampai

                                <?= e(format_date_id(
                                    $selectedPeriod['end_date']
                                )) ?>
                            </small>

                            <small class="text-body-secondary d-block mt-1">
                                Dibuat oleh
                                <?= e(
                                    $selectedPeriod[
                                        'created_by_name'
                                    ]
                                ) ?>
                            </small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php if ($selectedPeriod === null): ?>
            <div class="alert alert-warning" role="status">
                Belum terdapat periode pemeriksaan.
                Buat periode terlebih dahulu sebelum
                membuka laporan.
            </div>
        <?php else: ?>
            <section class="<?= $isTechnician ? 'row row-cols-2 row-cols-lg-4 g-3 mb-4' : 'row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-4' ?>" aria-label="Ringkasan laporan">
                <div class="col">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                <?= $isTechnician
                                    ? 'Pemeriksaan Saya'
                                    : 'Pemeriksaan' ?>
                            </p>

                            <p class="display-6 fw-semibold mb-1">
                                <?= e(
                                    $summary['assessment_count']
                                ) ?>
                            </p>

                            <small class="text-body-secondary">
                                <?= $isTechnician
                                    ? 'Pada periode terpilih'
                                    : 'Dari '
                                        . $expectedMachineCount
                                        . ' motor' ?>
                            </small>
                        </div>
                    </article>
                </div>

                <div class="col">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                Disetujui
                            </p>

                            <p class="display-6 fw-semibold mb-1">
                                <?= e(
                                    $summary['validated_count']
                                ) ?>
                            </p>

                            <small class="text-body-secondary">
                                Termasuk processed
                            </small>
                        </div>
                    </article>
                </div>

                <?php if ($isTechnician): ?>
                    <div class="col">
                        <article class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <p class="small text-body-secondary mb-2">
                                    Dikembalikan
                                </p>

                                <p class="display-6 fw-semibold mb-1">
                                    <?= e(
                                        $summary['returned_count']
                                    ) ?>
                                </p>

                                <small class="text-body-secondary">
                                    Perlu diperbaiki
                                </small>
                            </div>
                        </article>
                    </div>

                    <div class="col">
                        <article class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <p class="small text-body-secondary mb-2">
                                    Temuan Bahaya
                                </p>

                                <p class="display-6 fw-semibold mb-1">
                                    <?= e(
                                        $summary['danger_count']
                                    ) ?>
                                </p>

                                <small class="text-body-secondary">
                                    Pada C4 atau C5
                                </small>
                            </div>
                        </article>
                    </div>
                <?php else: ?>
                    <div class="col">
                        <article class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <p class="small text-body-secondary mb-2">
                                    Bobot AHP
                                </p>

                                <p class="display-6 fw-semibold mb-1">
                                    <?= e(
                                        $summary['ahp_weight_count']
                                    ) ?>
                                </p>

                                <small class="text-body-secondary">
                                    Dari <?= e(
                                        $expectedCriteriaCount
                                    ) ?> kriteria
                                </small>
                            </div>
                        </article>
                    </div>

                    <div class="col">
                        <article class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <p class="small text-body-secondary mb-2">
                                    Hasil TOPSIS
                                </p>

                                <p class="display-6 fw-semibold mb-1">
                                    <?= e(
                                        $summary['topsis_count']
                                    ) ?>
                                </p>

                                <small class="text-body-secondary">
                                    Dari <?= e(
                                        $expectedMachineCount
                                    ) ?> alternatif
                                </small>
                            </div>
                        </article>
                    </div>

                    <div class="col">
                        <article class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <p class="small text-body-secondary mb-2">
                                    Tindakan Selesai
                                </p>

                                <p class="display-6 fw-semibold mb-1">
                                    <?= e(
                                        $summary[
                                            'completed_action_count'
                                        ]
                                    ) ?>
                                </p>

                                <small class="text-body-secondary">
                                    Dari <?= e(
                                        $summary['action_count']
                                    ) ?> tindakan
                                </small>
                            </div>
                        </article>
                    </div>
                <?php endif; ?>
            </section>

            <?php if (!$isTechnician): ?>
                <div class="row g-4 mb-4">
                    <div class="col-xl-7">
                        <section class="card h-100 border-0 shadow-sm" aria-labelledby="report-readiness-title">
                            <div class="card-header bg-white py-3">
                                <h2 id="report-readiness-title" class="h5 mb-1">
                                    Kesiapan Data Laporan
                                </h2>

                                <p class="small text-body-secondary mb-0">
                                    Pemeriksaan kelengkapan data pada
                                    periode terpilih.
                                </p>
                            </div>

                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Komponen</th>
                                                <th>Ketersediaan</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <tr>
                                                <td>
                                                    Pemeriksaan disetujui
                                                </td>

                                                <td>
                                                    <?= e(
                                                        $summary[
                                                            'validated_count'
                                                        ]
                                                    ) ?>
                                                    /
                                                    <?= e(
                                                        $expectedMachineCount
                                                    ) ?>
                                                </td>

                                                <td>
                                                    <span class="badge text-bg-<?= $summary[ 'validated_count' ] > 0 ? 'success' : 'secondary' ?>">
                                                        <?= $summary[
                                                            'validated_count'
                                                        ] > 0
                                                            ? 'Tersedia'
                                                            : 'Belum tersedia' ?>
                                                    </span>
                                                </td>
                                            </tr>

                                            <tr>
                                                <td>
                                                    Nilai C1–C5
                                                </td>

                                                <td>
                                                    <?= e(
                                                        $summary['score_count']
                                                    ) ?>
                                                    /
                                                    <?= e(
                                                        $expectedMachineCount
                                                    ) ?>
                                                </td>

                                                <td>
                                                    <span class="badge text-bg-<?= $summary[ 'score_count' ] === $expectedMachineCount ? 'success' : 'warning' ?>">
                                                        <?= $summary[
                                                            'score_count'
                                                        ]
                                                            ===
                                                        $expectedMachineCount
                                                            ? 'Lengkap'
                                                            : 'Belum lengkap' ?>
                                                    </span>
                                                </td>
                                            </tr>

                                            <tr>
                                                <td>
                                                    Perbandingan AHP
                                                </td>

                                                <td>
                                                    <?= e(
                                                        $summary[
                                                            'ahp_comparison_count'
                                                        ]
                                                    ) ?>
                                                    /
                                                    <?= e(
                                                        $expectedComparisonCount
                                                    ) ?>
                                                </td>

                                                <td>
                                                    <span class="badge text-bg-<?= $summary[ 'ahp_comparison_count' ] === $expectedComparisonCount ? 'success' : 'warning' ?>">
                                                        <?= $summary[
                                                            'ahp_comparison_count'
                                                        ]
                                                            ===
                                                        $expectedComparisonCount
                                                            ? 'Lengkap'
                                                            : 'Belum lengkap' ?>
                                                    </span>
                                                </td>
                                            </tr>

                                            <tr>
                                                <td>
                                                    Konsistensi AHP
                                                </td>

                                                <td>
                                                    CR =
                                                    <?= $consistencyRatio !== null
                                                        ? e(
                                                            format_number_id(
                                                                $consistencyRatio,
                                                                4
                                                            )
                                                        )
                                                        : '-' ?>
                                                </td>

                                                <td>
                                                    <span class="badge text-bg-<?= $ahpIsConsistent ? 'success' : 'warning' ?>">
                                                        <?= $ahpIsConsistent
                                                            ? 'Konsisten'
                                                            : 'Belum konsisten' ?>
                                                    </span>
                                                </td>
                                            </tr>

                                            <tr>
                                                <td>
                                                    Hasil TOPSIS
                                                </td>

                                                <td>
                                                    <?= e(
                                                        $summary[
                                                            'topsis_count'
                                                        ]
                                                    ) ?>
                                                    /
                                                    <?= e(
                                                        $expectedMachineCount
                                                    ) ?>
                                                </td>

                                                <td>
                                                    <span class="badge text-bg-<?= $summary[ 'topsis_count' ] === $expectedMachineCount ? 'success' : 'warning' ?>">
                                                        <?= $summary[
                                                            'topsis_count'
                                                        ]
                                                            ===
                                                        $expectedMachineCount
                                                            ? 'Lengkap'
                                                            : 'Belum lengkap' ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="col-xl-5">
                        <section class="card h-100 border-0 shadow-sm" aria-labelledby="report-summary-title">
                            <div class="card-header bg-white py-3">
                                <h2 id="report-summary-title" class="h5 mb-1">
                                    Ringkasan Keputusan
                                </h2>

                                <p class="small text-body-secondary mb-0">
                                    Informasi utama hasil AHP dan TOPSIS.
                                </p>
                            </div>

                            <div class="card-body p-4">
                                <dl class="row mb-0">
                                    <dt class="col-6 text-body-secondary fw-normal">
                                        Responden AHP
                                    </dt>

                                    <dd class="col-6 text-end">
                                        <?= e(
                                            $ahpSummary[
                                                'assessor_name'
                                            ]
                                            ?? 'Belum tersedia'
                                        ) ?>
                                    </dd>

                                    <dt class="col-6 text-body-secondary fw-normal">
                                        Bobot kriteria
                                    </dt>

                                    <dd class="col-6 text-end">
                                        <?= e(
                                            $summary[
                                                'ahp_weight_count'
                                            ]
                                        ) ?>
                                        /
                                        <?= e(
                                            $expectedCriteriaCount
                                        ) ?>
                                    </dd>

                                    <dt class="col-6 text-body-secondary fw-normal">
                                        Konsistensi
                                    </dt>

                                    <dd class="col-6 text-end">
                                        <?= $ahpIsConsistent
                                            ? 'Konsisten'
                                            : 'Belum konsisten' ?>
                                    </dd>

                                    <dt class="col-6 text-body-secondary fw-normal">
                                        Hasil peringkat
                                    </dt>

                                    <dd class="col-6 text-end">
                                        <?= e(
                                            $summary['topsis_count']
                                        ) ?>
                                        alternatif
                                    </dd>

                                    <dt class="col-6 text-body-secondary fw-normal">
                                        Tindak lanjut
                                    </dt>

                                    <dd class="col-6 text-end">
                                        <?= e(
                                            $summary['action_count']
                                        ) ?>
                                        tindakan
                                    </dd>
                                </dl>

                                <?php if ($topPriority !== null): ?>
                                    <div class="alert alert-primary mt-4 mb-0" role="status">
                                        <small class="d-block mb-1">
                                            Prioritas tertinggi
                                        </small>

                                        <strong class="d-block">
                                            <?= e(
                                                $topPriority[
                                                    'alternative_code'
                                                ]
                                            ) ?>

                                            —

                                            <?= e(
                                                $topPriority[
                                                    'motor_name'
                                                ]
                                            ) ?>
                                        </strong>

                                        <small class="d-block mt-1">
                                            Nilai preferensi

                                            <?= e(
                                                format_number_id(
                                                    $topPriority[
                                                        'preference_value'
                                                    ],
                                                    4
                                                )
                                            ) ?>
                                        </small>

                                        <small class="d-block mt-1">
                                            Kelompok prioritas

                                            <?= e(
                                                $topPriority[
                                                    'rank_group'
                                                ]
                                                ?? '-'
                                            ) ?>

                                            &middot;

                                            <?= e(
                                                $topPriority[
                                                    'priority_label'
                                                ]
                                                ?? (
                                                    'Prioritas '
                                                    . (
                                                        $topPriority[
                                                            'rank_group'
                                                        ]
                                                        ?? $topPriority[
                                                            'ranking'
                                                        ]
                                                        ?? '-'
                                                    )
                                                )
                                            ) ?>
                                        </small>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-light border mt-4 mb-0" role="status">
                                        Prioritas tertinggi belum tersedia
                                        karena TOPSIS belum selesai.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>
                </div>
            <?php else: ?>
                <section class="card border-0 shadow-sm mb-4" aria-labelledby="technician-report-readiness-title">
                    <div class="card-header bg-white py-3">
                        <h2 id="technician-report-readiness-title" class="h5 mb-1">
                            Kesiapan Laporan Pemeriksaan
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Ringkasan hanya berasal dari pemeriksaan
                            yang dibuat oleh akun Teknisi yang sedang login.
                        </p>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>
                                            Komponen
                                        </th>

                                        <th>
                                            Ketersediaan
                                        </th>

                                        <th>
                                            Status
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <tr>
                                        <td>
                                            Pemeriksaan saya
                                        </td>

                                        <td>
                                            <?= e(
                                                $summary[
                                                    'assessment_count'
                                                ]
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="badge text-bg-<?= $summary[ 'assessment_count' ] > 0 ? 'success' : 'secondary' ?>">
                                                <?= $summary[
                                                    'assessment_count'
                                                ] > 0
                                                    ? 'Tersedia'
                                                    : 'Belum tersedia' ?>
                                            </span>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td>
                                            Nilai C1–C5 lengkap
                                        </td>

                                        <td>
                                            <?= e(
                                                $summary[
                                                    'score_count'
                                                ]
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="badge text-bg-<?= $summary[ 'score_count' ] > 0 ? 'success' : 'warning' ?>">
                                                <?= $summary[
                                                    'score_count'
                                                ] > 0
                                                    ? 'Tersedia'
                                                    : 'Belum lengkap' ?>
                                            </span>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td>
                                            Pemeriksaan disetujui
                                        </td>

                                        <td>
                                            <?= e(
                                                $summary[
                                                    'validated_count'
                                                ]
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="badge text-bg-<?= $summary[ 'validated_count' ] > 0 ? 'success' : 'secondary' ?>">
                                                <?= $summary[
                                                    'validated_count'
                                                ] > 0
                                                    ? 'Tersedia'
                                                    : 'Belum tersedia' ?>
                                            </span>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td>
                                            Pemeriksaan dikembalikan
                                        </td>

                                        <td>
                                            <?= e(
                                                $summary[
                                                    'returned_count'
                                                ]
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="badge text-bg-<?= $summary[ 'returned_count' ] > 0 ? 'warning' : 'success' ?>">
                                                <?= $summary[
                                                    'returned_count'
                                                ] > 0
                                                    ? 'Perlu diperbaiki'
                                                    : 'Tidak ada' ?>
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="mb-4" aria-labelledby="report-list-title">
                <div class="mb-3">
                    <h2 id="report-list-title" class="h5 mb-1">
                        Jenis Laporan
                    </h2>

                    <p class="text-body-secondary mb-0">
                        Buka laporan yang tersedia untuk periode

                        <?= e(
                            $selectedPeriod['name']
                        ) ?>.
                    </p>
                </div>

                <div class="row g-4">
                    <?php foreach (
                        $reportCards
                        as $report
                    ): ?>
                        <div class="col-md-6">
                            <article class="card h-100 border-0 shadow-sm">
                                <div class="card-body p-4 d-flex flex-column">
                                    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                        <div>
                                            <span class="badge text-bg-light border mb-2">
                                                <?= e(
                                                    $report['code']
                                                ) ?>
                                            </span>

                                            <h3 class="h5 mb-1">
                                                <?= e(
                                                    $report['title']
                                                ) ?>
                                            </h3>
                                        </div>

                                        <span class="badge text-bg-<?= $report['available'] ? 'success' : 'secondary' ?>">
                                            <?= $report['available']
                                                ? 'Tersedia'
                                                : 'Belum tersedia' ?>
                                        </span>
                                    </div>

                                    <p class="text-body-secondary flex-grow-1">
                                        <?= e(
                                            $report['description']
                                        ) ?>
                                    </p>

                                    <div class="rounded-3 bg-body-tertiary border p-3 mb-3">
                                        <small class="text-body-secondary d-block">
                                            Status data
                                        </small>

                                        <strong class="d-block mt-1">
                                            <?= e(
                                                $report['status_text']
                                            ) ?>
                                        </strong>

                                        <?php if (
                                            !$report['available']
                                        ): ?>
                                            <small class="text-body-secondary d-block mt-1">
                                                <?= e(
                                                    $report['requirement']
                                                ) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (
                                        $report['available']
                                    ): ?>
                                        <div class="d-flex flex-wrap gap-2 align-items-center">
                                            <a href="<?= e( base_url( $report['path'] ) ) ?>" class="btn btn-primary">
                                                Buka Laporan
                                            </a>

                                        </div>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline-secondary align-self-start" disabled>
                                            Data Belum Lengkap
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>
</div>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

<?php

declare(strict_types=1);

/*
 * Halaman Utama AHP Sistem SPK PT CISAS
 * Hak akses:
 * - Administrator       : melihat status dan hasil AHP.
 * - Supervisor          : mengisi perbandingan dan melihat hasil AHP.
 * AHP digunakan untuk menentukan bobot:
 * C1 = Suhu Permukaan
 * C2 = Persentase Arus
 * C3 = Deviasi RPM
 * C4 = Kondisi Mekanikal
 * C5 = Kondisi Elektrikal
 * Responden ahli AHP:
 * Supervisor Maintenance.
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
    . 'WorkflowService.php';

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

// Informasi Pengguna

$currentRole = auth_role();

$isAdmin =
    $currentRole === 'admin';

$isSupervisor =
    $currentRole === 'supervisor';

// Konfigurasi AHP

$expectedCriteriaCount = (int) app_config(
    'total_criteria',
    5
);

/*
 * Jumlah Perbandingan Berpasangan
 * Rumus:
 * n(n - 1) / 2
 * Untuk 5 kriteria:
 * 5(5 - 1) / 2 = 10 pasangan.
 */

$expectedComparisonCount = (int) (
    (
        $expectedCriteriaCount
        * ($expectedCriteriaCount - 1)
    ) / 2
);

// Nilai Awal

$periods = [];

$selectedPeriod = null;

$comparisonCount = 0;

$weightCount = 0;

$ahpSummary = null;

$pageError = null;
$calculationReadiness = null;
$dataReadyForCalculation = false;

// Ambil Daftar Periode

try {
    $periodStatement = $pdo->query(
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
        'Daftar periode AHP SPK PT CISAS gagal: '
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

    foreach ($periods as $period) {
        if (
            (int) $period['id']
            === (int) $validatedPeriodId
        ) {
            $selectedPeriod = $period;

            break;
        }
    }

    if (
        $selectedPeriod === null
        && $periods !== []
    ) {
        $notFoundMessage =
            'Periode yang dipilih tidak ditemukan.';

        require dirname(__DIR__)
            . DIRECTORY_SEPARATOR
            . '404.php';

        exit;
    }
} elseif ($periods !== []) {
    // Prioritaskan Periode Aktif

    foreach ($periods as $period) {
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

    // Jika Tidak Ada Periode Aktif, Gunakan Periode Terbaru

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

// Kesiapan enam pemeriksaan sebelum pembobotan.
if ($selectedPeriodId !== null) {
    try {
        $calculationReadiness = WorkflowService::calculationReadiness($pdo, $selectedPeriodId);
        $dataReadyForCalculation = (bool) ($calculationReadiness['ready'] ?? false);
    } catch (Throwable $exception) {
        error_log('Kesiapan data AHP gagal: ' . $exception->getMessage());
        $calculationReadiness = null;
        $dataReadyForCalculation = false;
    }
}

// Ambil Status AHP

if ($selectedPeriodId !== null) {
    try {
        // Hitung Perbandingan yang Sudah Diisi

        $comparisonStatement = $pdo->prepare(
            "SELECT COUNT(*)

             FROM ahp_comparisons

             WHERE period_id = :period_id"
        );

        $comparisonStatement->execute([
            'period_id' =>
                $selectedPeriodId,
        ]);

        $comparisonCount = (int)
            $comparisonStatement
                ->fetchColumn();

        // Hitung Bobot Kriteria yang Sudah Dihasilkan

        $weightStatement = $pdo->prepare(
            "SELECT COUNT(*)

             FROM ahp_results

             WHERE period_id = :period_id"
        );

        $weightStatement->execute([
            'period_id' =>
                $selectedPeriodId,
        ]);

        $weightCount = (int)
            $weightStatement
                ->fetchColumn();

        // Ambil Ringkasan Perhitungan AHP

        $summaryStatement = $pdo->prepare(
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
    } catch (Throwable $exception) {
        error_log(
            'Status AHP SPK PT CISAS gagal: '
            . $exception->getMessage()
        );

        $pageError =
            'Data AHP belum dapat dimuat dengan lengkap.';
    }
}

// Hitung Progres Input

$comparisonProgress =
    $expectedComparisonCount > 0
        ? (int) round(
            (
                $comparisonCount
                / $expectedComparisonCount
            ) * 100
        )
        : 0;

$comparisonProgress = max(
    0,
    min(
        100,
        $comparisonProgress
    )
);

// Status Kelengkapan Perbandingan

$comparisonsAreComplete =
    $comparisonCount
    === $expectedComparisonCount;

// Status Kelengkapan Bobot

$weightsAreComplete =
    $weightCount
    === $expectedCriteriaCount;

// Status Konsistensi AHP

$consistencyRatio =
    $ahpSummary !== null
    && (
        $ahpSummary[
            'consistency_ratio'
        ] ?? null
    ) !== null

        ? (float) $ahpSummary[
            'consistency_ratio'
        ]

        : null;

$isConsistent =
    $ahpSummary !== null

    && (int) (
        $ahpSummary[
            'is_consistent'
        ] ?? 0
    ) === 1

    && $consistencyRatio !== null

    && $consistencyRatio <= 0.10;

// AHP Selesai

$ahpIsComplete =
    $comparisonsAreComplete
    && $weightsAreComplete
    && $isConsistent;

// Tentukan Status AHP untuk Tampilan

if ($ahpIsComplete) {
    $ahpStatus = [
        'label' =>
            'Selesai dan Konsisten',

        'class' =>
            'success',

        'description' =>
            'Bobot C1 sampai C5 telah dihitung '
            . 'dan memenuhi batas konsistensi.',
    ];
} elseif (
    $ahpSummary !== null
    && !$isConsistent
) {
    $ahpStatus = [
        'label' =>
            'Tidak Konsisten',

        'class' =>
            'danger',

        'description' =>
            'Nilai perbandingan perlu diperbaiki '
            . 'karena Rasio Konsistensi lebih dari 0,10.',
    ];
} elseif ($comparisonsAreComplete) {
    $ahpStatus = [
        'label' =>
            'Menunggu Perhitungan',

        'class' =>
            'warning',

        'description' =>
            'Seluruh pasangan telah diisi tetapi '
            . 'hasil akhir AHP belum lengkap.',
    ];
} elseif ($comparisonCount > 0) {
    $ahpStatus = [
        'label' =>
            'Sedang Diisi',

        'class' =>
            'warning',

        'description' =>
            'Sebagian perbandingan kriteria sudah diisi.',
    ];
} else {
    $ahpStatus = [
        'label' =>
            'Belum Diisi',

        'class' =>
            'secondary',

        'description' =>
            'Perbandingan berpasangan C1 sampai C5 '
            . 'belum dilakukan.',
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

/*
 * Supervisor Dapat Mengisi AHP
 * AHP hanya dapat diubah pada periode yang masih Aktif.
 */

$canInputAhp =
    $isSupervisor
    && $selectedPeriodStatus === 'active'
    && $dataReadyForCalculation;

// Fungsi Tampilan Angka

$displayNumber = static function (
    mixed $value,
    int $decimals = 4
): string {
    if (
        $value === null
        || $value === ''
    ) {
        return '-';
    }

    $number = floatval(
        $value
    );

    if (!is_finite($number)) {
        return '-';
    }

    return format_number_id(
        $number,
        $decimals
    );
};

// Daftar Lima Kriteria

$criteria = [
    [
        'code' => 'C1',
        'name' => 'Suhu Permukaan',
        'description' =>
            'Hasil pengukuran suhu permukaan motor.',
    ],

    [
        'code' => 'C2',
        'name' => 'Persentase Arus',
        'description' =>
            'Persentase arus aktual terhadap arus nominal.',
    ],

    [
        'code' => 'C3',
        'name' => 'Deviasi RPM',
        'description' =>
            'Deviasi putaran aktual terhadap RPM acuan.',
    ],

    [
        'code' => 'C4',
        'name' => 'Kondisi Mekanikal',
        'description' =>
            'Hasil pemeriksaan checklist mekanikal.',
    ],

    [
        'code' => 'C5',
        'name' => 'Kondisi Elektrikal',
        'description' =>
            'Hasil pemeriksaan checklist elektrikal.',
    ],
];

// Konfigurasi Halaman

$pageTitle = 'Pembobotan AHP';

$pageDescription =
    'Pembobotan lima kriteria pemeliharaan '
    . 'motor mesin mixer menggunakan metode AHP.';

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
                    Data AHP belum dapat dimuat
                </strong>

                <?= e($pageError) ?>
            </div>

        <?php endif; ?>

        <!-- Judul halaman -->
        <section class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3 mb-4">
            <div>
                <h1 class="page-title">
                    Pembobotan AHP
                </h1>

                <p class="page-description">
                    Menentukan tingkat kepentingan
                    C1 sampai C5 melalui perbandingan
                    berpasangan oleh Supervisor Maintenance.
                </p>
            </div>

            <?php if (
                $selectedPeriodId !== null
            ): ?>

                <div class="page-header-actions page-header-actions-stack">

                    <?php if ($canInputAhp): ?>

                        <a href="<?= e( base_url( 'ahp/input.php?period_id=' . $selectedPeriodId ) ) ?>" class="btn btn-primary">
                            <?= $comparisonCount > 0
                                ? 'Lanjutkan Pembobotan'
                                : 'Mulai Pembobotan' ?>
                        </a>

                    <?php endif; ?>

                    <?php if (
                        $ahpSummary !== null
                        || $weightCount > 0
                    ): ?>

                        <a href="<?= e( base_url( 'ahp/hasil.php?period_id=' . $selectedPeriodId ) ) ?>" class="btn btn-primary">
                            Lihat Hasil AHP
                        </a>

                    <?php endif; ?>

                </div>

            <?php endif; ?>
        </section>

        <!-- Informasi responden -->
        <div class="alert alert-info mb-4" role="status">
            Pembobotan AHP dilakukan oleh
            <strong>Supervisor Maintenance</strong>
            sebagai satu responden ahli yang memahami
            kondisi peralatan, proses pemeliharaan, dan
            penentuan prioritas pemeliharaan motor mesin mixer.
        </div>
        <?php if ($selectedPeriodId !== null && !$dataReadyForCalculation): ?>
            <div class="alert alert-warning mb-4" role="alert">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                    <div>
                        <strong class="d-block mb-1">Pembobotan menunggu kesiapan pemeriksaan</strong>
                        AHP baru dapat diisi setelah enam alternatif A1–A6 disetujui dan nilai C1–C5 lengkap.
                        <?php if (is_array($calculationReadiness)): ?>
                            <div class="small mt-2">
                                Disetujui: <strong><?= e((int) ($calculationReadiness['approved_count'] ?? 0)) ?>/6</strong>
                                · Nilai lengkap: <strong><?= e((int) ($calculationReadiness['score_complete_count'] ?? 0)) ?>/6</strong>
                            </div>
                        <?php endif; ?>
                    </div>
                    <a class="btn btn-outline-primary" href="<?= e(base_url('pemeriksaan/index.php?period_id=' . $selectedPeriodId . ($isSupervisor ? '&mode=validation' : ''))) ?>">Periksa Data Pemeriksaan</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($periods === []): ?>

            <!-- Belum ada periode -->
            <section class="card border-0 shadow-sm">
                <div class="card-body text-center p-4 p-md-5">
                    <h2 class="h5 mb-2">
                        Periode pemeriksaan belum tersedia
                    </h2>

                    <p class="text-body-secondary mb-3">
                        Buat periode pemeriksaan terlebih dahulu
                        sebelum melakukan pembobotan AHP.
                    </p>

                    <?php if ($isAdmin): ?>

                        <a href="<?= e( base_url( 'periode/create.php' ) ) ?>" class="btn btn-primary">
                            Tambah Periode
                        </a>

                    <?php endif; ?>
                </div>
            </section>

        <?php else: ?>

            <!-- Pemilihan periode -->
            <section class="card border-0 shadow-sm mb-4" aria-labelledby="ahp-period-title">
                <div class="card-body p-4">

                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3">

                        <div class="flex-grow-1">

                            <h2 id="ahp-period-title" class="h5 mb-3">
                                Periode AHP
                            </h2>

                            <form method="get" action="<?= e( base_url( 'ahp/index.php' ) ) ?>" class="row g-3 align-items-end">

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

                                            <option value="<?= e( (int) $period[ 'id' ] ) ?>" <?= selected( (string) $selectedPeriodId, (string) $period['id'] ) ?>>
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

                            <div class="border rounded-3 p-3" style="min-width: 270px;">
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

            <!-- Status AHP -->
            <section class="row g-3 mb-4" aria-label="Ringkasan pembobotan AHP">

                <!-- Perbandingan -->
                <div class="col-md-4">

                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">

                            <p class="small text-body-secondary mb-2">
                                Perbandingan Terisi
                            </p>

                            <p class="display-6 fw-semibold mb-1">
                                <?= e(
                                    $comparisonCount
                                ) ?>

                                <span class="fs-5 text-body-secondary">
                                    /
                                    <?= e(
                                        $expectedComparisonCount
                                    ) ?>
                                </span>
                            </p>

                            <small class="text-body-secondary">
                                Pasangan kriteria AHP
                            </small>

                        </div>

                    </article>

                </div>

                <!-- Bobot -->
                <div class="col-md-4">

                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">

                            <p class="small text-body-secondary mb-2">
                                Bobot Kriteria
                            </p>

                            <p class="display-6 fw-semibold mb-1">
                                <?= e(
                                    $weightCount
                                ) ?>

                                <span class="fs-5 text-body-secondary">
                                    /
                                    <?= e(
                                        $expectedCriteriaCount
                                    ) ?>
                                </span>
                            </p>

                            <small class="text-body-secondary">
                                C1 sampai C5
                            </small>

                        </div>

                    </article>

                </div>

                <!-- CR -->
                <div class="col-md-4">

                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">

                            <p class="small text-body-secondary mb-2">
                                Rasio Konsistensi
                            </p>

                            <p class="display-6 fw-semibold mb-1">
                                <?= e(
                                    $displayNumber(
                                        $consistencyRatio,
                                        4
                                    )
                                ) ?>
                            </p>

                            <small class="<?= $isConsistent ? 'text-success' : 'text-body-secondary' ?>">
                                Batas konsistensi:
                                CR ≤ 0,10
                            </small>

                        </div>

                    </article>

                </div>

            </section>

            <!-- Progres -->
            <section class="card border-0 shadow-sm mb-4" aria-labelledby="ahp-progress-title">

                <div class="card-body p-4">

                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">

                        <div>
                            <h2 id="ahp-progress-title" class="h5 mb-1">
                                Status Pembobotan
                            </h2>

                            <p class="text-body-secondary mb-0">
                                Progres pengisian perbandingan
                                berpasangan C1 sampai C5.
                            </p>
                        </div>

                        <span class="badge text-bg-<?= e( $ahpStatus[ 'class' ] ) ?> p-2">
                            <?= e(
                                $ahpStatus[
                                    'label'
                                ]
                            ) ?>
                        </span>

                    </div>

                    <div class="d-flex justify-content-between small mb-2">
                        <span>
                            Perbandingan selesai
                        </span>

                        <strong>
                            <?= e(
                                $comparisonProgress
                            ) ?>%
                        </strong>
                    </div>

                    <div class="progress mb-3" role="progressbar" aria-label="Progres perbandingan AHP" aria-valuenow="<?= e( $comparisonProgress ) ?>" aria-valuemin="0" aria-valuemax="100" style="height: 14px;">
                        <div
                            class="progress-bar"
                            style="width: <?= e(
                                $comparisonProgress
                            ) ?>%;"
                        ></div>
                    </div>

                    <p class="small text-body-secondary mb-0">
                        <?= e(
                            $ahpStatus[
                                'description'
                            ]
                        ) ?>
                    </p>

                </div>

            </section>

            <div class="row g-4">

                <!-- Daftar kriteria -->
                <div class="col-xl-7">

                    <section class="card border-0 shadow-sm h-100" aria-labelledby="criteria-title">

                        <div class="card-header bg-white py-3">
                            <h2 id="criteria-title" class="h5 mb-1">
                                Kriteria AHP
                            </h2>

                            <p class="small text-body-secondary mb-0">
                                Lima kriteria penentuan
                                prioritas pemeliharaan.
                            </p>
                        </div>

                        <div class="card-body p-0">

                            <div class="table-responsive">

                                <table class="table table-hover align-middle mb-0">

                                    <thead class="table-light">

                                        <tr>
                                            <th scope="col">
                                                Kode
                                            </th>

                                            <th scope="col">
                                                Kriteria
                                            </th>

                                            <th scope="col">
                                                Keterangan
                                            </th>
                                        </tr>

                                    </thead>

                                    <tbody>

                                        <?php foreach (
                                            $criteria
                                            as $criterion
                                        ): ?>

                                            <tr>

                                                <td>
                                                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary fw-bold" style="width: 44px; height: 44px;">
                                                        <?= e(
                                                            $criterion[
                                                                'code'
                                                            ]
                                                        ) ?>
                                                    </span>
                                                </td>

                                                <td>
                                                    <strong>
                                                        <?= e(
                                                            $criterion[
                                                                'name'
                                                            ]
                                                        ) ?>
                                                    </strong>
                                                </td>

                                                <td class="text-body-secondary">
                                                    <?= e(
                                                        $criterion[
                                                            'description'
                                                        ]
                                                    ) ?>
                                                </td>

                                            </tr>

                                        <?php endforeach; ?>

                                    </tbody>

                                </table>

                            </div>

                        </div>

                    </section>

                </div>

                <!-- Informasi konsistensi -->
                <div class="col-xl-5">

                    <section class="card border-0 shadow-sm h-100" aria-labelledby="consistency-title">

                        <div class="card-header bg-white py-3">
                            <h2 id="consistency-title" class="h5 mb-1">
                                Konsistensi AHP
                            </h2>

                            <p class="small text-body-secondary mb-0">
                                Ringkasan hasil pengujian
                                konsistensi penilaian.
                            </p>
                        </div>

                        <div class="card-body p-4">

                            <?php if (
                                $ahpSummary === null
                            ): ?>

                                <div class="text-center text-body-secondary py-4">
                                    <p class="mb-2">
                                        Hasil perhitungan AHP
                                        belum tersedia.
                                    </p>

                                    <?php if ($canInputAhp): ?>

                                        <a href="<?= e( base_url( 'ahp/input.php?period_id=' . $selectedPeriodId ) ) ?>" class="btn btn-sm btn-outline-primary">
                                            Isi Perbandingan
                                        </a>

                                    <?php endif; ?>
                                </div>

                            <?php else: ?>

                                <dl class="row mb-0">

                                    <dt class="col-7">
                                        Lambda Maksimum
                                    </dt>

                                    <dd class="col-5 text-end">
                                        <?= e(
                                            $displayNumber(
                                                $ahpSummary[
                                                    'lambda_max'
                                                ] ?? null,
                                                4
                                            )
                                        ) ?>
                                    </dd>

                                    <dt class="col-7">
                                        Indeks Konsistensi
                                    </dt>

                                    <dd class="col-5 text-end">
                                        <?= e(
                                            $displayNumber(
                                                $ahpSummary[
                                                    'consistency_index'
                                                ] ?? null,
                                                4
                                            )
                                        ) ?>
                                    </dd>

                                    <dt class="col-7">
                                        Indeks Acak
                                    </dt>

                                    <dd class="col-5 text-end">
                                        <?= e(
                                            $displayNumber(
                                                $ahpSummary[
                                                    'random_index'
                                                ] ?? null,
                                                4
                                            )
                                        ) ?>
                                    </dd>

                                    <dt class="col-7">
                                        Rasio Konsistensi
                                    </dt>

                                    <dd class="col-5 text-end">
                                        <strong class="<?= $isConsistent ? 'text-success' : 'text-danger' ?>">
                                            <?= e(
                                                $displayNumber(
                                                    $consistencyRatio,
                                                    4
                                                )
                                            ) ?>
                                        </strong>
                                    </dd>

                                    <dt class="col-7">
                                        Keputusan
                                    </dt>

                                    <dd class="col-5 text-end">
                                        <?php if (
                                            $isConsistent
                                        ): ?>

                                            <span class="badge text-bg-success">
                                                Konsisten
                                            </span>

                                        <?php else: ?>

                                            <span class="badge text-bg-danger">
                                                Tidak Konsisten
                                            </span>

                                        <?php endif; ?>
                                    </dd>

                                    <dt class="col-7">
                                        Dihitung
                                    </dt>

                                    <dd class="col-5 text-end mb-0">
                                        <?= e(
                                            format_date_id(
                                                $ahpSummary[
                                                    'calculated_at'
                                                ] ?? null,
                                                true
                                            )
                                        ) ?>
                                    </dd>

                                </dl>

                            <?php endif; ?>

                        </div>

                    </section>

                </div>

            </div>

            <!-- Informasi hak akses -->
            <div class="alert alert-light border mt-4 mb-0">

                <?php if ($isSupervisor): ?>

                    <strong>
                        Supervisor Maintenance:
                    </strong>

                    Anda merupakan responden ahli AHP
                    dan dapat mengisi atau memperbaiki
                    perbandingan selama periode masih Aktif.

                <?php else: ?>

                    <strong>
                        Administrator:
                    </strong>

                    Anda dapat memantau hasil AHP,
                    tetapi penilaian perbandingan dilakukan
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

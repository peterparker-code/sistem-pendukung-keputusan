<?php

declare(strict_types=1);

/*
 * Daftar Periode Pemeriksaan Sistem SPK PT CISAS
 * Halaman ini dapat diakses oleh:
 * - Administrator;
 * - Supervisor Maintenance.
 * Fungsinya:
 * - menampilkan seluruh periode pemeriksaan;
 * - mencari periode berdasarkan nama;
 * - menyaring periode berdasarkan status;
 * - menampilkan progres pemeriksaan enam motor;
 * - menyediakan akses detail untuk Admin dan Supervisor;
 * - menyediakan akses tambah dan ubah hanya untuk Administrator.
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

$isAdmin = auth_is_admin();

// Konfigurasi Penelitian

$expectedMachineCount = (int) app_config(
    'total_machines',
    6
);

// Ambil Filter

$search = input_string(
    $_GET,
    'q',
    ''
) ?? '';

$statusFilter = input_string(
    $_GET,
    'status',
    ''
) ?? '';

$allowedStatuses = [
    '',
    'draft',
    'active',
    'completed',
];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

// Nilai Awal

$periods = [];

$summary = [
    'total' => 0,
    'draft' => 0,
    'active' => 0,
    'completed' => 0,
];

$pageError = null;

// Ambil Data Periode

try {
    // Ringkasan Seluruh Periode

    $summaryStatement = $pdo->query(
        "SELECT
            COUNT(*) AS total,

            SUM(
                CASE
                    WHEN status = 'draft' THEN 1
                    ELSE 0
                END
            ) AS draft,

            SUM(
                CASE
                    WHEN status = 'active' THEN 1
                    ELSE 0
                END
            ) AS active,

            SUM(
                CASE
                    WHEN status = 'completed' THEN 1
                    ELSE 0
                END
            ) AS completed

         FROM assessment_periods"
    );

    $summaryData = $summaryStatement->fetch();

    if (is_array($summaryData)) {
        foreach (array_keys($summary) as $key) {
            $summary[$key] = (int) (
                $summaryData[$key]
                ?? 0
            );
        }
    }

    // Susun Query Daftar Periode

    $conditions = [];
    $parameters = [];

    if ($search !== '') {
        $conditions[] =
            "lower(ap.name) LIKE lower(:search_name)";

        $parameters['search_name'] = '%'
            . $search
            . '%';
    }

    if ($statusFilter !== '') {
        $conditions[] =
            'ap.status = :status';

        $parameters['status'] =
            $statusFilter;
    }

    $periodSql = "
        SELECT
            ap.id,
            ap.name,
            ap.start_date,
            ap.end_date,
            ap.status,
            ap.created_at,
            ap.updated_at,

            (
                SELECT COUNT(*)
                FROM assessments AS a
                WHERE a.period_id = ap.id
            ) AS assessment_count,

            (
                SELECT COUNT(*)
                FROM assessments AS a
                WHERE a.period_id = ap.id
                  AND a.status = 'draft'
            ) AS draft_assessment_count,

            (
                SELECT COUNT(*)
                FROM assessments AS a
                WHERE a.period_id = ap.id
                  AND a.status = 'completed'
            ) AS pending_validation_count,

            (
                SELECT COUNT(*)
                FROM assessments AS a
                WHERE a.period_id = ap.id
                  AND a.status IN (
                      'validated',
                      'processed'
                  )
            ) AS validated_assessment_count,

            (
                SELECT COUNT(*)
                FROM topsis_results AS tr
                WHERE tr.period_id = ap.id
            ) AS topsis_result_count

        FROM assessment_periods AS ap
    ";

    if ($conditions !== []) {
        $periodSql .= "\nWHERE "
            . implode(
                "\nAND ",
                $conditions
            );
    }

    $periodSql .= "
        ORDER BY
            CASE ap.status
                WHEN 'active' THEN 1
                WHEN 'draft' THEN 2
                WHEN 'completed' THEN 3
                ELSE 4
            END,
            ap.start_date DESC,
            ap.id DESC
    ";

    $periodStatement = $pdo->prepare(
        $periodSql
    );

    $periodStatement->execute(
        $parameters
    );

    $periods =
        $periodStatement->fetchAll();
} catch (Throwable $exception) {
    error_log(
        'Daftar periode SPK PT CISAS gagal: '
        . $exception->getMessage()
    );

    $pageError =
        'Data periode pemeriksaan belum dapat dimuat. '
        . 'Periksa kembali koneksi dan struktur database.';
}

// Konfigurasi Status Periode

$statusConfigurations = [
    'draft' => [
        'label' => 'Draft',
        'class' => 'secondary',

        'description' =>
            'Periode masih disiapkan dan belum '
            . 'digunakan untuk pemeriksaan.',
    ],

    'active' => [
        'label' => 'Aktif',
        'class' => 'success',

        'description' =>
            'Periode sedang digunakan untuk '
            . 'pemeriksaan enam motor.',
    ],

    'completed' => [
        'label' => 'Selesai',
        'class' => 'primary',

        'description' =>
            'Periode telah ditutup dan digunakan '
            . 'sebagai riwayat penelitian.',
    ],
];

// Fungsi Menghitung Progres

$calculateProgress = static function (
    int $validatedCount,
    int $expectedCount
): int {
    if ($expectedCount < 1) {
        return 0;
    }

    return max(
        0,
        min(
            100,
            (int) round(
                (
                    $validatedCount
                    / $expectedCount
                ) * 100
            )
        )
    );
};

// Fungsi Menentukan Kesiapan SPK

$resolveReadiness = static function (
    int $assessmentCount,
    int $validatedCount,
    int $topsisResultCount,
    int $expectedCount
): array {
    if (
        $topsisResultCount === $expectedCount
        && $expectedCount > 0
    ) {
        return [
            'label' => 'TOPSIS selesai',
            'class' => 'primary',
        ];
    }

    if (
        $validatedCount === $expectedCount
        && $expectedCount > 0
    ) {
        return [
            'label' => 'Siap TOPSIS',
            'class' => 'success',
        ];
    }

    if ($assessmentCount > 0) {
        return [
            'label' => 'Pemeriksaan berjalan',
            'class' => 'warning',
        ];
    }

    return [
        'label' => 'Belum dimulai',
        'class' => 'secondary',
    ];
};

// Konfigurasi Halaman

$pageTitle = 'Periode Pemeriksaan';

$pageDescription =
    'Pengelolaan periode pemeriksaan enam motor mesin mixer '
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
                    Data periode belum dapat dimuat
                </strong>

                <?= e($pageError) ?>
            </div>
        <?php endif; ?>

        <!-- Judul halaman -->
        <section class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <h1 class="page-title">
                    Periode Pemeriksaan
                </h1>

                <p class="page-description">
                    Kelola siklus pemeriksaan enam motor
                    dari tahap draft sampai selesai.
                </p>
            </div>

            <?php if ($isAdmin): ?>
                <a href="<?= e( base_url( 'periode/create.php' ) ) ?>" class="btn btn-primary">
                    Tambah Periode
                </a>
            <?php else: ?>
                <span class="badge text-bg-light border p-2">
                    Akses lihat Supervisor
                </span>
            <?php endif; ?>
        </section>

        <!-- Informasi penting -->
        <div class="alert alert-info mb-4" role="status">
            Satu periode digunakan untuk pemeriksaan lengkap
            terhadap alternatif A1 sampai A6. Sistem hanya
            mengizinkan satu periode berstatus Aktif pada
            waktu yang sama.
        </div>

        <!-- Ringkasan periode -->
        <section class="row g-3 mb-4" aria-label="Ringkasan periode pemeriksaan">
            <div class="col-6 col-xl-3">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Total periode
                        </p>

                        <p class="display-6 fw-semibold mb-1">
                            <?= e($summary['total']) ?>
                        </p>

                        <small class="text-body-secondary">
                            Seluruh periode tersimpan
                        </small>
                    </div>
                </article>
            </div>

            <div class="col-6 col-xl-3">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Draft
                        </p>

                        <p class="display-6 fw-semibold mb-1">
                            <?= e($summary['draft']) ?>
                        </p>

                        <small class="text-body-secondary">
                            Masih dalam persiapan
                        </small>
                    </div>
                </article>
            </div>

            <div class="col-6 col-xl-3">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Aktif
                        </p>

                        <p class="display-6 fw-semibold mb-1">
                            <?= e($summary['active']) ?>
                        </p>

                        <small class="text-body-secondary">
                            Sedang digunakan
                        </small>
                    </div>
                </article>
            </div>

            <div class="col-6 col-xl-3">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Selesai
                        </p>

                        <p class="display-6 fw-semibold mb-1">
                            <?= e($summary['completed']) ?>
                        </p>

                        <small class="text-body-secondary">
                            Tersimpan sebagai riwayat
                        </small>
                    </div>
                </article>
            </div>
        </section>

        <!-- Filter -->
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="period-filter-title">
            <div class="card-body">
                <h2 id="period-filter-title" class="h6 mb-3">
                    Cari dan Saring Periode
                </h2>

                <form method="get" action="<?= e( base_url( 'periode/index.php' ) ) ?>" class="row g-3 align-items-end">
                    <div class="col-md-7">
                        <label for="q" class="form-label">
                            Nama Periode
                        </label>

                        <input type="search" id="q" name="q" class="form-control" value="<?= e($search) ?>" maxlength="100" placeholder="Contoh: Pemeriksaan Juli 2026">
                    </div>

                    <div class="col-md-3">
                        <label for="status" class="form-label">
                            Status
                        </label>

                        <select id="status" name="status" class="form-select">
                            <option value="">
                                Semua Status
                            </option>

                            <option value="draft" <?= selected( $statusFilter, 'draft' ) ?>>
                                Draft
                            </option>

                            <option value="active" <?= selected( $statusFilter, 'active' ) ?>>
                                Aktif
                            </option>

                            <option value="completed" <?= selected( $statusFilter, 'completed' ) ?>>
                                Selesai
                            </option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                Terapkan
                            </button>

                            <a href="<?= e( base_url( 'periode/index.php' ) ) ?>" class="btn btn-outline-secondary">
                                Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <!-- Daftar periode -->
        <section class="card border-0 shadow-sm" aria-labelledby="period-list-title">
            <div class="card-header bg-white py-3 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                <div>
                    <h2 id="period-list-title" class="h5 mb-1">
                        Daftar Periode
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Ditemukan
                        <?= e(count($periods)) ?>
                        periode sesuai filter.
                    </p>
                </div>

                <?php if (
                    $search !== ''
                    || $statusFilter !== ''
                ): ?>
                    <span class="badge text-bg-light border">
                        Filter aktif
                    </span>
                <?php endif; ?>
            </div>

            <div class="card-body p-0">
                <?php if ($periods === []): ?>
                    <div class="table-empty-state" role="status">
                        <h3 class="h6 mb-2">
                            Periode tidak ditemukan
                        </h3>

                        <p class="mb-3">
                            Ubah kata pencarian atau reset filter.
                        </p>

                        <?php if (
                            $isAdmin
                            && $summary['total'] === 0
                        ): ?>
                            <a href="<?= e( base_url( 'periode/create.php' ) ) ?>" class="btn btn-primary">
                                Buat Periode Pertama
                            </a>
                        <?php else: ?>
                            <a href="<?= e( base_url( 'periode/index.php' ) ) ?>" class="btn btn-outline-primary">
                                Reset Filter
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">
                                        Periode
                                    </th>

                                    <th scope="col">
                                        Rentang Tanggal
                                    </th>

                                    <th scope="col">
                                        Status
                                    </th>

                                    <th scope="col">
                                        Progres Validasi
                                    </th>

                                    <th scope="col">
                                        Kesiapan SPK
                                    </th>

                                    <th scope="col" class="text-end">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach (
                                    $periods
                                    as $period
                                ): ?>
                                    <?php
                                    $periodId = (int) (
                                        $period['id']
                                        ?? 0
                                    );

                                    $periodStatus = (string) (
                                        $period['status']
                                        ?? 'draft'
                                    );

                                    $statusConfiguration =
                                        $statusConfigurations[
                                            $periodStatus
                                        ] ?? [
                                            'label' =>
                                                'Tidak diketahui',

                                            'class' =>
                                                'secondary',

                                            'description' =>
                                                'Status periode '
                                                . 'tidak dikenali.',
                                        ];

                                    $assessmentCount = (int) (
                                        $period[
                                            'assessment_count'
                                        ] ?? 0
                                    );

                                    $draftAssessmentCount = (int) (
                                        $period[
                                            'draft_assessment_count'
                                        ] ?? 0
                                    );

                                    $pendingValidationCount = (int) (
                                        $period[
                                            'pending_validation_count'
                                        ] ?? 0
                                    );

                                    $validatedCount = (int) (
                                        $period[
                                            'validated_assessment_count'
                                        ] ?? 0
                                    );

                                    $topsisResultCount = (int) (
                                        $period[
                                            'topsis_result_count'
                                        ] ?? 0
                                    );

                                    $progress =
                                        $calculateProgress(
                                            $validatedCount,
                                            $expectedMachineCount
                                        );

                                    $readiness =
                                        $resolveReadiness(
                                            $assessmentCount,
                                            $validatedCount,
                                            $topsisResultCount,
                                            $expectedMachineCount
                                        );
                                    ?>

                                    <tr>
                                        <td>
                                            <strong class="d-block">
                                                <?= e(
                                                    $period['name']
                                                ) ?>
                                            </strong>

                                            <small class="text-body-secondary">
                                                ID periode:
                                                <?= e($periodId) ?>
                                            </small>
                                        </td>

                                        <td>
                                            <span class="d-block">
                                                <?= e(
                                                    format_date_id(
                                                        $period[
                                                            'start_date'
                                                        ] ?? null
                                                    )
                                                ) ?>
                                            </span>

                                            <small class="text-body-secondary">
                                                sampai

                                                <?= e(
                                                    format_date_id(
                                                        $period[
                                                            'end_date'
                                                        ] ?? null
                                                    )
                                                ) ?>
                                            </small>
                                        </td>

                                        <td>
                                            <span class="badge text-bg-<?= e( $statusConfiguration[ 'class' ] ) ?>">
                                                <?= e(
                                                    $statusConfiguration[
                                                        'label'
                                                    ]
                                                ) ?>
                                            </span>

                                            <small class="d-block text-body-secondary mt-1" style="max-width: 250px;">
                                                <?= e(
                                                    $statusConfiguration[
                                                        'description'
                                                    ]
                                                ) ?>
                                            </small>
                                        </td>

                                        <td style="min-width: 210px;">
                                            <div class="d-flex justify-content-between small mb-1">
                                                <span>
                                                    <?= e(
                                                        $validatedCount
                                                    ) ?>

                                                    dari

                                                    <?= e(
                                                        $expectedMachineCount
                                                    ) ?>

                                                    motor
                                                </span>

                                                <strong>
                                                    <?= e(
                                                        $progress
                                                    ) ?>%
                                                </strong>
                                            </div>

                                            <div class="progress" role="progressbar" aria-label="Progres validasi periode" aria-valuenow="<?= e( $progress ) ?>" aria-valuemin="0" aria-valuemax="100" style="height: 10px;">
                                                <div
                                                    class="progress-bar"
                                                    style="width: <?= e(
                                                        $progress
                                                    ) ?>%;"
                                                ></div>
                                            </div>

                                            <small class="d-block text-body-secondary mt-2">
                                                Draft:

                                                <?= e(
                                                    $draftAssessmentCount
                                                ) ?>

                                                &middot;

                                                Menunggu validasi:

                                                <?= e(
                                                    $pendingValidationCount
                                                ) ?>
                                            </small>
                                        </td>

                                        <td>
                                            <span class="badge text-bg-<?= e( $readiness[ 'class' ] ) ?>">
                                                <?= e(
                                                    $readiness[
                                                        'label'
                                                    ]
                                                ) ?>
                                            </span>

                                            <small class="d-block text-body-secondary mt-1">
                                                Hasil TOPSIS:

                                                <?= e(
                                                    $topsisResultCount
                                                ) ?>

                                                /

                                                <?= e(
                                                    $expectedMachineCount
                                                ) ?>
                                            </small>
                                        </td>

                                        <td class="text-end">
                                            <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                                <a href="<?= e( base_url( 'periode/detail.php?id=' . $periodId ) ) ?>" class="btn btn-sm btn-outline-secondary">
                                                    Lihat
                                                </a>

                                                <?php if (
                                                    $isAdmin
                                                    && $periodStatus
                                                        !== 'completed'
                                                ): ?>
                                                    <a href="<?= e( base_url( 'periode/edit.php?id=' . $periodId ) ) ?>" class="btn btn-sm btn-outline-primary">
                                                        Ubah
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <p class="small text-body-secondary mt-3 mb-0">
            Periode yang sudah berstatus Selesai tidak dapat
            diubah dari halaman ini agar hasil AHP, TOPSIS,
            dan riwayat pemeriksaan tetap terjaga.
        </p>
    </main>
</div>

<?php
require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';
?>

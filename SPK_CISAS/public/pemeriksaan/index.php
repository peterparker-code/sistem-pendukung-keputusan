<?php

declare(strict_types=1);

/*
 * Daftar Pemeriksaan Sistem SPK PT CISAS
 * Halaman ini dapat diakses oleh:
 * - Administrator;
 * - Supervisor Maintenance;
 * - Teknisi Maintenance.
 * Fungsinya:
 * - memilih periode pemeriksaan;
 * - menampilkan status pemeriksaan A1 sampai A6;
 * - mencari motor atau pemeriksa;
 * - menyaring berdasarkan status pemeriksaan;
 * - menyediakan tindakan sesuai peran pengguna.
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
    'technician',
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

$currentUserId = auth_id();

$currentRole = auth_role();

$isAdmin =
    $currentRole === 'admin';

$isSupervisor =
    $currentRole === 'supervisor';

$isTechnician =
    $currentRole === 'technician';

// Mode halaman: validasi hanya untuk Supervisor.
$requestedMode = input_string(
    $_GET,
    'mode',
    ''
) ?? '';

if (
    $requestedMode === 'validation'
    && !$isSupervisor
) {
    http_response_code(403);
    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '403.php';
    exit;
}

$isValidationMode =
    $requestedMode === 'validation';

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

// Nilai Awal

$periods = [];

$selectedPeriod = null;

$assessmentRows = [];

$pageError = null;

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
        'Daftar periode pada pemeriksaan gagal: '
        . $exception->getMessage()
    );

    $pageError =
        'Daftar periode belum dapat dimuat. '
        . 'Periksa kembali koneksi dan struktur database.';
}

// Tentukan Periode Terpilih

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
            'ID periode pemeriksaan tidak valid.';

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
            'Periode pemeriksaan yang dipilih tidak ditemukan.';

        require dirname(__DIR__)
            . DIRECTORY_SEPARATOR
            . '404.php';

        exit;
    }
} elseif ($periods !== []) {
    // Prioritaskan Periode Aktif

    foreach ($periods as $period) {
        if (
            (string) $period['status']
            === 'active'
        ) {
            $selectedPeriod =
                $period;

            break;
        }
    }

    $selectedPeriod ??=
        $periods[0];
}

$selectedPeriodId =
    $selectedPeriod !== null
        ? (int) $selectedPeriod['id']
        : null;

$selectedPeriodStatus =
    $selectedPeriod !== null
        ? (string) $selectedPeriod['status']
        : null;

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
    'not_started',
    'draft',
    'completed',
    'returned',
    'validated',
    'processed',
];

if (
    !in_array(
        $statusFilter,
        $allowedStatuses,
        true
    )
) {
    $statusFilter = '';
}

// Pada halaman Validasi, tampilkan semua status pemeriksaan secara default.
// Supervisor tetap dapat mempersempit daftar melalui filter Status Pemeriksaan.

// Ringkasan Pemeriksaan

$summary = [
    'total_machines' =>
        $expectedMachineCount,

    'not_started' =>
        $expectedMachineCount,

    'draft' => 0,

    'completed' => 0,

    'returned' => 0,

    'validated' => 0,

    'processed' => 0,
];

// Ambil Data Pemeriksaan Periode Terpilih

if ($selectedPeriodId !== null) {
    try {
        // Ringkasan Seluruh Pemeriksaan dalam Periode

        $summaryStatement = $pdo->prepare(
            "SELECT
                COUNT(*) AS assessment_count,

                SUM(
                    CASE
                        WHEN status = 'draft'
                        THEN 1
                        ELSE 0
                    END
                ) AS draft_count,

                SUM(
                    CASE
                        WHEN status = 'completed'
                        THEN 1
                        ELSE 0
                    END
                ) AS completed_count,

                SUM(
                    CASE
                        WHEN status = 'returned'
                        THEN 1
                        ELSE 0
                    END
                ) AS returned_count,

                SUM(
                    CASE
                        WHEN status = 'validated'
                        THEN 1
                        ELSE 0
                    END
                ) AS validated_count,

                SUM(
                    CASE
                        WHEN status = 'processed'
                        THEN 1
                        ELSE 0
                    END
                ) AS processed_count

             FROM assessments

             WHERE period_id = :period_id"
        );

        $summaryStatement->execute([
            'period_id' =>
                $selectedPeriodId,
        ]);

        $summaryData =
            $summaryStatement->fetch();

        if (is_array($summaryData)) {
            $assessmentCount = (int) (
                $summaryData[
                    'assessment_count'
                ] ?? 0
            );

            $summary['not_started'] = max(
                0,
                $expectedMachineCount
                - $assessmentCount
            );

            $summary['draft'] = (int) (
                $summaryData[
                    'draft_count'
                ] ?? 0
            );

            $summary['completed'] = (int) (
                $summaryData[
                    'completed_count'
                ] ?? 0
            );

            $summary['returned'] = (int) (
                $summaryData[
                    'returned_count'
                ] ?? 0
            );

            $summary['validated'] = (int) (
                $summaryData[
                    'validated_count'
                ] ?? 0
            );

            $summary['processed'] = (int) (
                $summaryData[
                    'processed_count'
                ] ?? 0
            );
        }

        // Susun Query Daftar Pemeriksaan

        $conditions = [];

        $parameters = [
            'period_id' =>
                $selectedPeriodId,
        ];

        if ($search !== '') {
            $conditions[] = "(
                lower(m.alternative_code)
                    LIKE lower(:search_code)

                OR

                lower(m.motor_name)
                    LIKE lower(:search_motor)

                OR

                lower(m.machine_code)
                    LIKE lower(:search_machine_code)

                OR

                lower(
                    COALESCE(
                        u.name,
                        ''
                    )
                )
                    LIKE lower(:search_inspector)
            )";

            $searchPattern =
                '%' . $search . '%';

            $parameters['search_code'] =
                $searchPattern;

            $parameters['search_motor'] =
                $searchPattern;

            $parameters['search_machine_code'] =
                $searchPattern;

            $parameters['search_inspector'] =
                $searchPattern;
        }

        if (
            $statusFilter
            === 'not_started'
        ) {
            $conditions[] =
                'a.id IS NULL';
        } elseif ($statusFilter !== '') {
            $conditions[] =
                'a.status = :assessment_status';

            $parameters[
                'assessment_status'
            ] = $statusFilter;
        }

        $assessmentSql = "
            SELECT
                m.id AS machine_id,
                m.alternative_code,
                m.motor_name,
                m.status AS machine_status,

                a.id AS assessment_id,
                a.inspector_id,
                a.inspection_date,
                a.inspection_time,
                a.operating_status,
                a.load_condition,
                a.operating_notes,
                a.status AS assessment_status,
                a.validated_by,
                a.validated_at,
                a.supervisor_note,
                a.created_at AS assessment_created_at,
                a.updated_at AS assessment_updated_at,

                u.name AS inspector_name,
                validator.name AS validator_name,

                s.c1_value,
                s.c2_value,
                s.c3_value,
                s.c4_value,
                s.c5_value,

                (
                    SELECT COUNT(*)

                    FROM checklist_results AS cr

                    INNER JOIN checklist_items AS ci
                        ON ci.id = cr.checklist_item_id

                    INNER JOIN criteria AS c
                        ON c.id = ci.criterion_id

                    WHERE cr.assessment_id = a.id
                      AND ci.is_active = 1
                      AND c.is_active = 1
                      AND c.code IN (
                          'C4',
                          'C5'
                      )
                ) AS checklist_count,

                (
                    SELECT COUNT(*)

                    FROM checklist_results AS cr

                    INNER JOIN checklist_items AS ci
                        ON ci.id = cr.checklist_item_id

                    INNER JOIN criteria AS c
                        ON c.id = ci.criterion_id

                    WHERE cr.assessment_id = a.id
                      AND ci.is_active = 1
                      AND c.is_active = 1
                      AND c.code IN (
                          'C4',
                          'C5'
                      )
                      AND cr.condition_level = 'not_checked'
                ) AS not_checked_count,

                (
                    SELECT COUNT(*)

                    FROM checklist_results AS cr

                    INNER JOIN checklist_items AS ci
                        ON ci.id = cr.checklist_item_id

                    INNER JOIN criteria AS c
                        ON c.id = ci.criterion_id

                    WHERE cr.assessment_id = a.id
                      AND ci.is_active = 1
                      AND c.is_active = 1
                      AND c.code IN (
                          'C4',
                          'C5'
                      )
                      AND cr.condition_level = 'danger'
                ) AS danger_count

            FROM machines AS m

            LEFT JOIN assessments AS a
                ON a.machine_id = m.id

               AND a.period_id =
                   :period_id

            LEFT JOIN users AS u
                ON u.id =
                   a.inspector_id

            LEFT JOIN users AS validator
                ON validator.id =
                   a.validated_by

            LEFT JOIN assessment_scores AS s
                ON s.assessment_id =
                   a.id
        ";

        if ($conditions !== []) {
            $assessmentSql .=
                "\nWHERE "
                . implode(
                    "\nAND ",
                    $conditions
                );
        }

        $assessmentSql .= "
            ORDER BY
                m.alternative_code
                    COLLATE NOCASE,
                m.id
        ";

        $assessmentStatement =
            $pdo->prepare(
                $assessmentSql
            );

        $assessmentStatement->execute(
            $parameters
        );

        $assessmentRows =
            $assessmentStatement->fetchAll();
    } catch (Throwable $exception) {
        error_log(
            'Daftar pemeriksaan SPK PT CISAS gagal: '
            . $exception->getMessage()
        );

        $pageError =
            'Data pemeriksaan belum dapat dimuat. '
            . 'Periksa kembali koneksi dan struktur database.';
    }
}

// Konfigurasi Status Periode

$periodStatusConfigurations = [
    'draft' => [
        'label' =>
            'Draft',

        'class' =>
            'secondary',

        'description' =>
            'Periode belum dapat digunakan untuk pemeriksaan.',
    ],

    'active' => [
        'label' =>
            'Aktif',

        'class' =>
            'success',

        'description' =>
            'Teknisi dapat membuat dan melanjutkan pemeriksaan.',
    ],

    'completed' => [
        'label' =>
            'Selesai',

        'class' =>
            'primary',

        'description' =>
            'Periode telah berstatus Selesai dan menjadi riwayat.',
    ],
];

$periodStatusConfiguration =
    $selectedPeriodStatus !== null
        ? (
            $periodStatusConfigurations[
                $selectedPeriodStatus
            ] ?? [
                'label' =>
                    'Tidak diketahui',

                'class' =>
                    'secondary',

                'description' =>
                    'Status periode tidak dikenali.',
            ]
        )
        : null;

// Konfigurasi Status Pemeriksaan

$assessmentStatusConfigurations = [
    'draft' => [
        'label' =>
            'Draft',

        'class' =>
            'secondary',
    ],

    'completed' => [
        'label' =>
            'Menunggu Validasi',

        'class' =>
            'warning',
    ],

    'returned' => [
        'label' =>
            'Dikembalikan',

        'class' =>
            'danger',
    ],

    'validated' => [
        'label' =>
            'Disetujui',

        'class' =>
            'success',
    ],

    'processed' => [
        'label' =>
            'Diproses TOPSIS',

        'class' =>
            'primary',
    ],
];

// Fungsi Pemeriksaan Kelengkapan Nilai

$scoresAreComplete = static function (
    array $row
): bool {
    foreach (
        [
            'c1_value',
            'c2_value',
            'c3_value',
            'c4_value',
            'c5_value',
        ]
        as $field
    ) {
        if (
            !array_key_exists(
                $field,
                $row
            )
            || $row[$field] === null
            || $row[$field] === ''
            || !is_numeric((string) $row[$field])
            || !is_finite(floatval($row[$field]))
        ) {
            return false;
        }
    }

    return true;
};

// Label Kondisi Operasi

$operatingStatusLabels = [
    'running' => 'Beroperasi',
    'stopped' => 'Berhenti',
];

$loadConditionLabels = [
    'no_load' => 'Tanpa Beban',
    'light' => 'Beban Ringan',
    'normal' => 'Beban Normal',
    'high' => 'Beban Tinggi',
];

// Konfigurasi Halaman

if ($isValidationMode) {
    $pageTitle = 'Validasi Pemeriksaan';
    $pageDescription =
        'Antrian pemeriksaan yang perlu diperiksa dan diputuskan oleh Supervisor Maintenance.';
} elseif ($isTechnician) {
    $pageTitle = 'Pemeriksaan Motor';
    $pageDescription =
        'Isi dan pantau pemeriksaan kondisi enam motor mesin mixer.';
} else {
    $pageTitle = 'Data Pemeriksaan';
    $pageDescription =
        'Pantau data pemeriksaan kondisi enam motor mesin mixer.';
}

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
                    Data pemeriksaan belum dapat dimuat
                </strong>

                <?= e($pageError) ?>
            </div>
        <?php endif; ?>

        <!-- Judul halaman -->
        <section class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3 mb-4">
            <div>
                <h1 class="page-title">
                    <?= e($pageTitle) ?>
                </h1>

                <p class="page-description">
                    <?= e($pageDescription) ?>
                </p>
            </div>

            <?php if (
                $selectedPeriod !== null
                && (
                    $isAdmin
                    || $isSupervisor
                )
            ): ?>
                <a href="<?= e( base_url( 'periode/detail.php?id=' . $selectedPeriodId ) ) ?>" class="btn btn-primary">
                    Detail Periode
                </a>
            <?php endif; ?>
        </section>

        <?php if ($isTechnician): ?>
            <div class="alert alert-info mb-4" role="status">
                Teknisi dapat memulai pemeriksaan pada periode Aktif dan
                hanya dapat membuka atau mengubah pemeriksaan yang dibuat
                oleh akunnya sendiri.
            </div>
        <?php endif; ?>

        <?php if ($periods === []): ?>

            <section class="card border-0 shadow-sm" aria-labelledby="empty-period-title">
                <div class="card-body text-center p-4 p-md-5">
                    <h2 id="empty-period-title" class="h5 mb-2">
                        Periode pemeriksaan belum tersedia
                    </h2>

                    <p class="text-body-secondary mb-3">
                        Administrator perlu membuat dan
                        mengaktifkan periode sebelum pemeriksaan
                        motor dilakukan.
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
            <section class="card border-0 shadow-sm mb-4" aria-labelledby="period-selection-title">
                <div class="card-body p-4">

                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3">
                        <div class="flex-grow-1">
                            <h2 id="period-selection-title" class="h5 mb-3">
                                Periode Terpilih
                            </h2>

                            <form method="get" action="<?= e( base_url( 'pemeriksaan/index.php' ) ) ?>" class="row g-3 align-items-end">
                                <?php if ($isValidationMode): ?>
                                    <input type="hidden" name="mode" value="validation">
                                <?php endif; ?>

                                <div class="col-md-8">
                                    <label for="period_id" class="form-label">
                                        Periode Pemeriksaan
                                    </label>

                                    <select id="period_id" name="period_id" class="form-select" required>
                                        <?php foreach (
                                            $periods
                                            as $period
                                        ): ?>
                                            <?php
                                            $optionStatus =
                                                (string) (
                                                    $period[
                                                        'status'
                                                    ] ?? 'draft'
                                                );

                                            $optionStatusLabel =
                                                $periodStatusConfigurations[
                                                    $optionStatus
                                                ]['label']
                                                ?? 'Tidak diketahui';
                                            ?>

                                            <option value="<?= e( (int) $period['id'] ) ?>" <?= selected( (string) $selectedPeriodId, (string) $period['id'] ) ?>>
                                                <?= e(
                                                    $period['name']
                                                ) ?>

                                                —

                                                <?= e(
                                                    $optionStatusLabel
                                                ) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">
                                            Tampilkan Periode
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <?php if (
                            $periodStatusConfiguration
                            !== null
                        ): ?>
                            <div class="border rounded-3 p-3" style="min-width: 260px;">
                                <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                    <strong>
                                        <?= e(
                                            $selectedPeriod[
                                                'name'
                                            ]
                                        ) ?>
                                    </strong>

                                    <span class="badge text-bg-<?= e( $periodStatusConfiguration[ 'class' ] ) ?>">
                                        <?= e(
                                            $periodStatusConfiguration[
                                                'label'
                                            ]
                                        ) ?>
                                    </span>
                                </div>

                                <small class="d-block text-body-secondary">
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

                                <small class="d-block text-body-secondary mt-1">
                                    <?= e(
                                        $periodStatusConfiguration[
                                            'description'
                                        ]
                                    ) ?>
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- Informasi akses -->
            <?php if ($isTechnician): ?>

                <div class="alert alert-info mb-4" role="status">
                    Teknisi dapat memulai pemeriksaan baru
                    ketika periode berstatus Aktif.
                    Pemeriksaan Draft atau Dikembalikan dapat
                    dilanjutkan oleh Teknisi yang membuatnya.
                </div>

            <?php elseif ($isValidationMode): ?>

                <div class="alert alert-info mb-4" role="status">
                    Halaman ini khusus Supervisor Maintenance.
                    Secara default semua status pemeriksaan ditampilkan.
                    Gunakan filter Status Pemeriksaan untuk mempersempit daftar,
                    lalu buka detail untuk melakukan validasi bila diperlukan.
                </div>

            <?php elseif ($isSupervisor): ?>

                <div class="alert alert-info mb-4" role="status">
                    Halaman ini menampilkan data pemeriksaan sebagai
                    informasi. Proses persetujuan dilakukan melalui
                    menu Validasi Pemeriksaan.
                </div>

            <?php else: ?>

                <div class="alert alert-info mb-4" role="status">
                    Administrator memperoleh akses pemantauan.
                    Pengisian dilakukan Teknisi dan validasi
                    dilakukan Supervisor.
                </div>

            <?php endif; ?>

            <!-- Ringkasan -->
            <?php
            $assessmentSummaryCards = $isValidationMode
                ? [
                    [
                        'label' => 'Menunggu Validasi',
                        'value' => $summary['completed'],
                        'note' => 'Perlu keputusan Supervisor',
                    ],
                    [
                        'label' => 'Dikembalikan',
                        'value' => $summary['returned'],
                        'note' => 'Menunggu perbaikan Teknisi',
                    ],
                    [
                        'label' => 'Disetujui',
                        'value' => $summary['validated'] + $summary['processed'],
                        'note' => 'Termasuk hasil TOPSIS',
                    ],
                    [
                        'label' => 'Total Diperiksa',
                        'value' => $expectedMachineCount - $summary['not_started'],
                        'note' => 'Dari ' . $expectedMachineCount . ' motor',
                    ],
                ]
                : [
                    [
                        'label' => 'Belum Diperiksa',
                        'value' => $summary['not_started'],
                        'note' => 'Dari ' . $expectedMachineCount . ' motor',
                    ],
                    [
                        'label' => 'Sedang Dikerjakan',
                        'value' => $summary['draft'] + $summary['returned'],
                        'note' => 'Draft dan Dikembalikan',
                    ],
                    [
                        'label' => 'Menunggu Validasi',
                        'value' => $summary['completed'],
                        'note' => 'Menunggu Supervisor',
                    ],
                    [
                        'label' => 'Disetujui',
                        'value' => $summary['validated'] + $summary['processed'],
                        'note' => 'Termasuk hasil TOPSIS',
                    ],
                ];
            ?>

            <section class="row g-3 mb-4" aria-label="Ringkasan pemeriksaan">
                <?php foreach ($assessmentSummaryCards as $summaryCard): ?>
                    <div class="col-6 col-lg-3">
                        <article class="card h-100 border-0 shadow-sm">
                            <div class="card-body">
                                <p class="small text-body-secondary mb-2">
                                    <?= e($summaryCard['label']) ?>
                                </p>

                                <p class="display-6 fw-semibold mb-1">
                                    <?= e((string) $summaryCard['value']) ?>
                                </p>

                                <small class="text-body-secondary">
                                    <?= e($summaryCard['note']) ?>
                                </small>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- Filter -->
            <section class="card border-0 shadow-sm mb-4" aria-labelledby="assessment-filter-title">
                <div class="card-body">

                    <h2 id="assessment-filter-title" class="h6 mb-3">
                        Cari dan Saring Pemeriksaan
                    </h2>

                    <form method="get" action="<?= e( base_url( 'pemeriksaan/index.php' ) ) ?>" class="row g-3 align-items-end">
                        <input type="hidden" name="period_id" value="<?= e( $selectedPeriodId ) ?>">

                        <?php if ($isValidationMode): ?>
                            <input type="hidden" name="mode" value="validation">
                        <?php endif; ?>

                        <div class="col-lg-6">
                            <label for="q" class="form-label">
                                Motor atau Pemeriksa
                            </label>

                            <input type="search" id="q" name="q" class="form-control" value="<?= e($search) ?>" maxlength="100" placeholder="Contoh: A1, MIX-01, Scraper, atau Teknisi">
                        </div>

                        <div class="col-md-7 col-lg-3">
                            <label for="status" class="form-label">
                                Status Pemeriksaan
                            </label>

                            <select id="status" name="status" class="form-select">
                                <option value="" <?= selected( $statusFilter, '' ) ?>>
                                    Semua Status
                                </option>

                                <option value="not_started" <?= selected( $statusFilter, 'not_started' ) ?>>
                                    Belum Diperiksa
                                </option>

                                <option value="draft" <?= selected( $statusFilter, 'draft' ) ?>>
                                    Draft
                                </option>

                                <option value="completed" <?= selected( $statusFilter, 'completed' ) ?>>
                                    Menunggu Validasi
                                </option>

                                <option value="returned" <?= selected( $statusFilter, 'returned' ) ?>>
                                    Dikembalikan
                                </option>

                                <option value="validated" <?= selected( $statusFilter, 'validated' ) ?>>
                                    Disetujui
                                </option>

                                <option value="processed" <?= selected( $statusFilter, 'processed' ) ?>>
                                    Diproses TOPSIS
                                </option>
                            </select>
                        </div>

                        <div class="col-md-5 col-lg-3">
                            <div class="d-grid d-sm-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-grow-1">
                                    Terapkan
                                </button>

                                <a href="<?= e( base_url(
                                    'pemeriksaan/index.php?'
                                    . ($isValidationMode ? 'mode=validation&' : '')
                                    . 'period_id=' . $selectedPeriodId
                                ) ) ?>" class="btn btn-outline-secondary flex-grow-1">
                                    Reset
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Daftar pemeriksaan -->
            <section class="card border-0 shadow-sm" aria-labelledby="assessment-list-title">
                <div class="card-header bg-white py-3 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                    <div>
                        <h2 id="assessment-list-title" class="h5 mb-1">
                            <?= $isValidationMode
                                ? 'Antrian Validasi Pemeriksaan'
                                : 'Pemeriksaan Enam Motor' ?>
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Ditemukan
                            <?= e(
                                count(
                                    $assessmentRows
                                )
                            ) ?>
                            motor sesuai filter.
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

                    <?php if (
                        $assessmentRows === []
                    ): ?>

                        <div class="table-empty-state" role="status">
                            <h3 class="h6 mb-2">
                                <?= $isValidationMode && $statusFilter === 'completed'
                                    ? 'Tidak ada pemeriksaan menunggu validasi'
                                    : 'Data pemeriksaan tidak ditemukan' ?>
                            </h3>

                            <p class="mb-3">
                                <?= $isValidationMode && $statusFilter === 'completed'
                                    ? 'Semua pemeriksaan pada periode ini sudah diproses atau belum dikirim oleh Teknisi.'
                                    : 'Ubah kata pencarian atau reset filter.' ?>
                            </p>

                            <a href="<?= e( base_url(
                                'pemeriksaan/index.php?'
                                . ($isValidationMode ? 'mode=validation&status=&' : '')
                                . 'period_id=' . $selectedPeriodId
                            ) ) ?>" class="btn btn-outline-primary">
                                <?= $isValidationMode && $statusFilter === 'completed'
                                    ? 'Lihat Semua Pemeriksaan'
                                    : 'Reset Filter' ?>
                            </a>
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">

                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">
                                            Motor
                                        </th>

                                        <th scope="col">
                                            Pemeriksa
                                        </th>

                                        <th scope="col">
                                            Waktu Pemeriksaan
                                        </th>

                                        <th scope="col">
                                            Status
                                        </th>

                                        <th scope="col" class="text-center">
                                            Checklist
                                        </th>

                                        <th scope="col">
                                            Temuan
                                        </th>

                                        <th scope="col">
                                            Nilai C1–C5
                                        </th>

                                        <th scope="col" class="text-end">
                                            Aksi
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php foreach (
                                        $assessmentRows
                                        as $row
                                    ): ?>
                                        <?php
                                        $machineId = (int) (
                                            $row[
                                                'machine_id'
                                            ] ?? 0
                                        );

                                        $assessmentId = (int) (
                                            $row[
                                                'assessment_id'
                                            ] ?? 0
                                        );

                                        $assessmentStatus =
                                            $assessmentId > 0
                                                ? (string) (
                                                    $row[
                                                        'assessment_status'
                                                    ] ?? 'draft'
                                                )
                                                : null;

                                        $statusConfiguration =
                                            $assessmentStatus !== null
                                                ? (
                                                    $assessmentStatusConfigurations[
                                                        $assessmentStatus
                                                    ] ?? [
                                                        'label' =>
                                                            'Tidak diketahui',

                                                        'class' =>
                                                            'secondary',
                                                    ]
                                                )
                                                : [
                                                    'label' =>
                                                        'Belum Diperiksa',

                                                    'class' =>
                                                        'secondary',
                                                ];

                                        $inspectorId = (int) (
                                            $row[
                                                'inspector_id'
                                            ] ?? 0
                                        );

                                        $checklistCount = (int) (
                                            $row[
                                                'checklist_count'
                                            ] ?? 0
                                        );

                                        $notCheckedCount = (int) (
                                            $row[
                                                'not_checked_count'
                                            ] ?? 0
                                        );

                                        $dangerCount = (int) (
                                            $row[
                                                'danger_count'
                                            ] ?? 0
                                        );

                                        $machineIsActive =
                                            (string) (
                                                $row[
                                                    'machine_status'
                                                ] ?? ''
                                            ) === 'active';

                                        $isOwnedByCurrentTechnician =
                                            !$isTechnician
                                            || $inspectorId === $currentUserId;

                                        $canViewDetail =
                                            $assessmentId > 0
                                            && $isOwnedByCurrentTechnician;

                                        $canStart =
                                            $isTechnician

                                            && $selectedPeriodStatus
                                                === 'active'

                                            && $machineIsActive

                                            && $assessmentId < 1;

                                        $canEdit =
                                            $isTechnician

                                            && $selectedPeriodStatus
                                                === 'active'

                                            && $assessmentId > 0

                                            && in_array(
                                                $assessmentStatus,
                                                [
                                                    'draft',
                                                    'returned',
                                                ],
                                                true
                                            )

                                            && $currentUserId
                                                !== null

                                            && $inspectorId
                                                === $currentUserId;

                                        $canValidate =
                                            $isSupervisor

                                            && $selectedPeriodStatus
                                                === 'active'

                                            && $assessmentStatus
                                                === 'completed';

                                        $hasCompleteScores =
                                            $scoresAreComplete(
                                                $row
                                            );

                                        $hasAvailableAction =
                                            $canViewDetail
                                            || $canStart
                                            || $canEdit
                                            || $canValidate;
                                        ?>

                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center gap-3">
                                                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary fw-bold flex-shrink-0" style="width: 46px; height: 46px;">
                                                        <?= e(
                                                            $row[
                                                                'alternative_code'
                                                            ] ?? '-'
                                                        ) ?>
                                                    </span>

                                                    <div>
                                                        <strong class="d-block">
                                                            <?= e(
                                                                $row[
                                                                    'motor_name'
                                                                ] ?? '-'
                                                            ) ?>
                                                        </strong>

                                                        <small class="text-body-secondary">
                                                            <?= $machineIsActive
                                                                ? 'Motor aktif'
                                                                : 'Motor tidak aktif' ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </td>

                                            <td>
                                                <?php if (
                                                    $isTechnician
                                                    && $assessmentId > 0
                                                    && !$isOwnedByCurrentTechnician
                                                ): ?>
                                                    <span class="text-body-secondary">
                                                        Pemeriksa lain
                                                    </span>
                                                <?php else: ?>
                                                    <?= e(
                                                        $row[
                                                            'inspector_name'
                                                        ] ?? '-'
                                                    ) ?>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <?php if (
                                                    $assessmentId > 0
                                                ): ?>
                                                    <span class="d-block">
                                                        <?= e(
                                                            format_date_id(
                                                                $row[
                                                                    'inspection_date'
                                                                ] ?? null
                                                            )
                                                        ) ?>
                                                    </span>

                                                    <small class="text-body-secondary d-block">
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

                                                    <small class="text-body-secondary d-block">
                                                        <?= e(
                                                            $operatingStatusLabels[
                                                                (string) (
                                                                    $row['operating_status']
                                                                    ?? ''
                                                                )
                                                            ] ?? '-'
                                                        ) ?>

                                                        <?php if (
                                                            (
                                                                $row['load_condition']
                                                                ?? null
                                                            ) !== null
                                                        ): ?>
                                                            &middot;

                                                            <?= e(
                                                                $loadConditionLabels[
                                                                    (string) $row[
                                                                        'load_condition'
                                                                    ]
                                                                ] ?? '-'
                                                            ) ?>
                                                        <?php endif; ?>
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
                                                <?php if (
                                                    $assessmentId > 0
                                                ): ?>
                                                    <strong>
                                                        <?= e(
                                                            $checklistCount
                                                        ) ?>
                                                    </strong>

                                                    <span class="text-body-secondary">
                                                        / 12
                                                    </span>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <?php if (
                                                    $assessmentId < 1
                                                ): ?>
                                                    -

                                                <?php elseif (
                                                    $dangerCount > 0
                                                ): ?>
                                                    <span class="badge text-bg-danger">
                                                        <?= e(
                                                            $dangerCount
                                                        ) ?>
                                                        Bahaya
                                                    </span>

                                                    <?php if (
                                                        $notCheckedCount > 0
                                                    ): ?>
                                                        <span class="badge text-bg-warning">
                                                            Belum lengkap
                                                        </span>
                                                    <?php endif; ?>

                                                <?php elseif (
                                                    $notCheckedCount > 0
                                                ): ?>
                                                    <span class="badge text-bg-warning">
                                                        Belum lengkap
                                                    </span>

                                                <?php else: ?>
                                                    <span class="text-body-secondary">
                                                        Tidak ada temuan
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <?php if (
                                                    $hasCompleteScores
                                                ): ?>
                                                    <span class="badge text-bg-success">
                                                        Lengkap
                                                    </span>

                                                <?php elseif (
                                                    $assessmentId > 0
                                                ): ?>
                                                    <span class="badge text-bg-secondary">
                                                        Belum dihitung
                                                    </span>

                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-end">
                                                <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                                    <?php if (
                                                        $canViewDetail
                                                    ): ?>
                                                        <a href="<?= e( base_url( 'pemeriksaan/detail.php?id=' . $assessmentId ) ) ?>" class="btn btn-sm btn-outline-secondary">
                                                            Lihat
                                                        </a>
                                                    <?php endif; ?>

                                                    <?php if (
                                                        $canStart
                                                    ): ?>
                                                        <a href="<?= e( base_url( 'pemeriksaan/create.php?period_id=' . $selectedPeriodId . '&machine_id=' . $machineId ) ) ?>" class="btn btn-sm btn-primary">
                                                            Mulai
                                                        </a>
                                                    <?php endif; ?>

                                                    <?php if (
                                                        $canEdit
                                                    ): ?>
                                                        <a href="<?= e( base_url( 'pemeriksaan/edit.php?id=' . $assessmentId ) ) ?>" class="btn btn-sm btn-primary">
                                                            <?= $assessmentStatus
                                                                === 'returned'
                                                                    ? 'Perbaiki'
                                                                    : 'Lanjutkan' ?>
                                                        </a>
                                                    <?php endif; ?>

                                                    <?php if (
                                                        $canValidate
                                                    ): ?>
                                                        <a href="<?= e( base_url( 'pemeriksaan/validasi.php?id=' . $assessmentId ) ) ?>" class="btn btn-sm btn-success">
                                                            Periksa &amp; Validasi
                                                        </a>
                                                    <?php endif; ?>

                                                    <?php if (
                                                        !$hasAvailableAction
                                                    ): ?>
                                                        <span class="text-body-secondary">
                                                            -
                                                        </span>
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
                <?php if ($isValidationMode): ?>
                    Menunggu Validasi berarti data sudah dikirim Teknisi dan
                    belum diputuskan oleh Supervisor Maintenance. Pemeriksaan
                    yang disetujui dapat digunakan pada proses TOPSIS.
                <?php else: ?>
                    Pemeriksaan hanya dapat dibuat pada periode Aktif.
                    Teknisi hanya dapat mengubah pemeriksaan miliknya sendiri.
                <?php endif; ?>
            </p>

        <?php endif; ?>

    </main>
</div>

<?php
require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';
?>

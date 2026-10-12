<?php

declare(strict_types=1);

/*
 * Detail Motor Sistem SPK PT CISAS
 * Admin      : melihat seluruh data, riwayat, hasil TOPSIS, dan tombol ubah.
 * Supervisor : melihat seluruh data, riwayat, dan hasil TOPSIS.
 * Teknisi    : melihat data motor dan riwayat pemeriksaan miliknya sendiri.
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'helpers'
    . DIRECTORY_SEPARATOR . 'auth.php';

auth_require_role([
    'admin',
    'supervisor',
    'technician',
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

$currentUserId = auth_id();
$currentRole = auth_role();

$isAdmin = $currentRole === 'admin';
$isSupervisor = $currentRole === 'supervisor';
$isTechnician = $currentRole === 'technician';

if ($currentUserId === null || $currentUserId < 1) {
    flash_error(
        'Sesi pengguna tidak valid. Silakan masuk kembali.'
    );

    redirect('login.php');
}

// Validasi ID Motor

$machineId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if ($machineId === false) {
    $notFoundMessage =
        'ID motor tidak valid atau tidak tersedia.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR . '404.php';

    exit;
}

$machineId = (int) $machineId;

// Identitas Tetap Alternatif

$identityMap = [
    'A1' => [
        'machine_code' => 'MIX-01',
        'machine_number' => 1,
        'motor_function' => 'scraper',
    ],

    'A2' => [
        'machine_code' => 'MIX-01',
        'machine_number' => 1,
        'motor_function' => 'homogenizer',
    ],

    'A3' => [
        'machine_code' => 'MIX-02',
        'machine_number' => 2,
        'motor_function' => 'scraper',
    ],

    'A4' => [
        'machine_code' => 'MIX-02',
        'machine_number' => 2,
        'motor_function' => 'homogenizer',
    ],

    'A5' => [
        'machine_code' => 'MIX-03',
        'machine_number' => 3,
        'motor_function' => 'scraper',
    ],

    'A6' => [
        'machine_code' => 'MIX-03',
        'machine_number' => 3,
        'motor_function' => 'homogenizer',
    ],
];

$machine = null;
$summary = null;
$latestAssessment = null;
$latestTopsis = null;
$historyRows = [];
$activePeriod = null;
$activeAssessment = null;
$pageError = null;

try {
    // Data Motor

    $statement = $pdo->prepare(
        "SELECT
            id,
            alternative_code,
            machine_code,
            machine_number,
            motor_function,
            motor_name,
            brand,
            voltage,
            rated_current,
            rated_frequency,
            rated_rpm,
            power_hp,
            number_of_poles,
            location,
            status,
            notes,
            created_at,
            updated_at

         FROM machines

         WHERE id = :id

         LIMIT 1"
    );

    $statement->execute([
        'id' => $machineId,
    ]);

    $data = $statement->fetch();

    $machine = is_array($data)
        ? $data
        : null;

    if ($machine === null) {
        $notFoundMessage =
            'Data motor tidak ditemukan.';

        require dirname(__DIR__)
            . DIRECTORY_SEPARATOR . '404.php';

        exit;
    }

    // Periksa Identitas A1 sampai A6

    $alternativeCode = strtoupper(
        trim(
            (string) (
                $machine['alternative_code']
                ?? ''
            )
        )
    );

    $expectedIdentity =
        $identityMap[$alternativeCode]
        ?? null;

    if (!is_array($expectedIdentity)) {
        throw new RuntimeException(
            'Kode alternatif motor tidak sesuai dengan A1 sampai A6.'
        );
    }

    $identityValid =
        (string) (
            $machine['machine_code']
            ?? ''
        ) === (string) $expectedIdentity['machine_code']

        && (int) (
            $machine['machine_number']
            ?? 0
        ) === (int) $expectedIdentity['machine_number']

        && (string) (
            $machine['motor_function']
            ?? ''
        ) === (string) $expectedIdentity['motor_function'];

    if (!$identityValid) {
        throw new RuntimeException(
            'Identitas motor tidak sesuai dengan pemetaan '
            . 'alternatif penelitian.'
        );
    }

    // Ringkasan Semua Pemeriksaan Motor

    $statement = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_assessments,

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
            ) AS processed_count,

            MIN(inspection_date)
                AS first_inspection_date,

            MAX(inspection_date)
                AS last_inspection_date

         FROM assessments

         WHERE machine_id = :machine_id"
    );

    $statement->execute([
        'machine_id' => $machineId,
    ]);

    $data = $statement->fetch();

    $summary = is_array($data)
        ? $data
        : null;

    // Pembatasan Riwayat Teknisi

    $ownerSql = '';

    $ownerParams = [
        'machine_id' => $machineId,
    ];

    if ($isTechnician) {
        $ownerSql =
            'AND a.inspector_id = :current_user_id';

        $ownerParams['current_user_id'] =
            $currentUserId;
    }

    // Pemeriksaan Terakhir

    $statement = $pdo->prepare(
        "SELECT
            a.id AS assessment_id,
            a.period_id,
            a.inspector_id,
            a.inspection_date,
            a.inspection_time,
            a.operating_status,
            a.load_condition,
            a.operating_notes,
            a.status AS assessment_status,
            a.validated_at,
            a.supervisor_note,

            p.name AS period_name,
            p.status AS period_status,

            inspector.name AS inspector_name,
            validator.name AS validator_name,

            s.c1_value,
            s.c2_value,
            s.c3_value,
            s.c4_value,
            s.c5_value,
            s.c4_has_danger,
            s.c5_has_danger,
            s.calculated_at

         FROM assessments AS a

         INNER JOIN assessment_periods AS p
            ON p.id = a.period_id

         INNER JOIN users AS inspector
            ON inspector.id = a.inspector_id

         LEFT JOIN users AS validator
            ON validator.id = a.validated_by

         LEFT JOIN assessment_scores AS s
            ON s.assessment_id = a.id

         WHERE a.machine_id = :machine_id

           {$ownerSql}

         ORDER BY
            a.inspection_date DESC,
            a.inspection_time DESC,
            a.id DESC

         LIMIT 1"
    );

    $statement->execute(
        $ownerParams
    );

    $data = $statement->fetch();

    $latestAssessment = is_array($data)
        ? $data
        : null;

    /*
 * Hasil TOPSIS Terakhir
 * Detail TOPSIS hanya ditampilkan kepada Admin dan Supervisor.
 */

    if (!$isTechnician) {
        $statement = $pdo->prepare(
            "SELECT
                tr.period_id,
                tr.assessment_id,
                tr.positive_distance,
                tr.negative_distance,
                tr.preference_value,
                tr.ranking,
                tr.rank_group,
                tr.priority_label,
                tr.calculated_at,

                p.name AS period_name,

                a.inspection_date

             FROM topsis_results AS tr

             INNER JOIN assessments AS a
                ON a.id = tr.assessment_id

             INNER JOIN assessment_periods AS p
                ON p.id = tr.period_id

             WHERE a.machine_id = :machine_id

             ORDER BY
                tr.calculated_at DESC,
                tr.period_id DESC

             LIMIT 1"
        );

        $statement->execute([
            'machine_id' => $machineId,
        ]);

        $data = $statement->fetch();

        $latestTopsis = is_array($data)
            ? $data
            : null;
    }

    // Riwayat Pemeriksaan

    $statement = $pdo->prepare(
        "SELECT
            a.id AS assessment_id,
            a.period_id,
            a.inspector_id,
            a.inspection_date,
            a.inspection_time,
            a.operating_status,
            a.load_condition,
            a.status AS assessment_status,
            a.validated_at,

            p.name AS period_name,
            p.status AS period_status,

            inspector.name AS inspector_name,
            validator.name AS validator_name,

            s.c1_value,
            s.c2_value,
            s.c3_value,
            s.c4_value,
            s.c5_value,
            s.c4_has_danger,
            s.c5_has_danger,

            tr.preference_value,
            tr.ranking,
            tr.rank_group,
            tr.priority_label

         FROM assessments AS a

         INNER JOIN assessment_periods AS p
            ON p.id = a.period_id

         INNER JOIN users AS inspector
            ON inspector.id = a.inspector_id

         LEFT JOIN users AS validator
            ON validator.id = a.validated_by

         LEFT JOIN assessment_scores AS s
            ON s.assessment_id = a.id

         LEFT JOIN topsis_results AS tr
            ON tr.assessment_id = a.id
           AND tr.period_id = a.period_id

         WHERE a.machine_id = :machine_id

           {$ownerSql}

         ORDER BY
            a.inspection_date DESC,
            a.inspection_time DESC,
            a.id DESC

         LIMIT 20"
    );

    $statement->execute(
        $ownerParams
    );

    $historyRows =
        $statement->fetchAll();

    // Periode Aktif

    $statement = $pdo->query(
        "SELECT
            id,
            name,
            start_date,
            end_date,
            status

         FROM assessment_periods

         WHERE status = 'active'

         ORDER BY
            start_date DESC,
            id DESC

         LIMIT 1"
    );

    $data = $statement->fetch();

    $activePeriod = is_array($data)
        ? $data
        : null;

    // Pemeriksaan Motor pada Periode Aktif

    if ($activePeriod !== null) {
        $statement = $pdo->prepare(
            "SELECT
                id,
                inspector_id,
                status,
                inspection_date,
                inspection_time

             FROM assessments

             WHERE period_id = :period_id
               AND machine_id = :machine_id

             LIMIT 1"
        );

        $statement->execute([
            'period_id' =>
                (int) $activePeriod['id'],

            'machine_id' =>
                $machineId,
        ]);

        $data = $statement->fetch();

        $activeAssessment = is_array($data)
            ? $data
            : null;
    }
} catch (Throwable $exception) {
    error_log(
        'Detail motor SPK PT CISAS gagal: '
        . $exception->getMessage()
    );

    $pageError =
        'Sebagian data motor belum dapat dimuat. '
        . 'Periksa kembali koneksi dan struktur database.';
}

if ($machine === null) {
    flash_error(
        'Detail motor belum dapat dimuat.'
    );

    redirect('motor/index.php');
}

// Helper Tampilan

$displayText = static function (
    mixed $value,
    string $fallback = '-'
): string {
    if ($value === null) {
        return $fallback;
    }

    $text = trim(
        (string) $value
    );

    return $text === ''
        ? $fallback
        : $text;
};

$displayNumber = static function (
    mixed $value,
    int $decimals = 2,
    string $suffix = ''
): string {
    if (
        $value === null
        || $value === ''
        || !is_numeric((string) $value)
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
    ) . $suffix;
};

$alternativeCode = (string) (
    $machine['alternative_code']
    ?? ''
);

$motorFunction = (string) (
    $machine['motor_function']
    ?? ''
);

$motorFunctionLabel = match (
    $motorFunction
) {
    'scraper' =>
        'Scraper',

    'homogenizer' =>
        'Homogenizer',

    default =>
        'Tidak diketahui',
};

$machineStatus = (string) (
    $machine['status']
    ?? 'inactive'
);

$machineStatusConfiguration = match (
    $machineStatus
) {
    'active' => [
        'label' => 'Aktif',
        'class' => 'success',
    ],

    'inactive' => [
        'label' => 'Tidak Aktif',
        'class' => 'secondary',
    ],

    default => [
        'label' => 'Tidak diketahui',
        'class' => 'secondary',
    ],
};

$assessmentStatusConfigurations = [
    'draft' => [
        'label' => 'Draft',
        'class' => 'secondary',
    ],

    'completed' => [
        'label' => 'Menunggu Validasi',
        'class' => 'warning',
    ],

    'returned' => [
        'label' => 'Dikembalikan',
        'class' => 'danger',
    ],

    'validated' => [
        'label' => 'Disetujui',
        'class' => 'success',
    ],

    'processed' => [
        'label' => 'Diproses TOPSIS',
        'class' => 'primary',
    ],
];

$operatingStatusLabels = [
    'running' =>
        'Beroperasi',

    'stopped' =>
        'Berhenti',
];

$loadConditionLabels = [
    'no_load' =>
        'Tanpa Beban',

    'light' =>
        'Beban Ringan',

    'normal' =>
        'Beban Normal',

    'high' =>
        'Beban Tinggi',
];

// Kelengkapan Data Acuan C2 dan C3

$requiredReferenceFields = [
    'rated_current' =>
        'Arus nominal',

    'rated_frequency' =>
        'Frekuensi nominal',

    'rated_rpm' =>
        'RPM nominal',
];

$missingReferences = [];

foreach (
    $requiredReferenceFields
    as $field => $label
) {
    $value = $machine[$field]
        ?? null;

    if (
        $value === null
        || $value === ''
        || !is_numeric((string) $value)
        || floatval($value) <= 0
    ) {
        $missingReferences[] =
            $label;
    }
}

$nameplateReady =
    $missingReferences === [];

$totalAssessments = (int) (
    $summary['total_assessments']
    ?? 0
);

$totalWaiting = (int) (
    $summary['completed_count']
    ?? 0
);

$totalReturned = (int) (
    $summary['returned_count']
    ?? 0
);

$totalValidated =
    (int) (
        $summary['validated_count']
        ?? 0
    )
    + (int) (
        $summary['processed_count']
        ?? 0
    );

// Hak Akses Tombol

$canEditMachine =
    $isAdmin;

$canStartAssessment =
    $isTechnician
    && $machineStatus === 'active'
    && $activePeriod !== null
    && $activeAssessment === null;

$activeAssessmentUrl = null;
$activeAssessmentLabel = null;

if ($activeAssessment !== null) {
    $activeAssessmentId = (int) (
        $activeAssessment['id']
        ?? 0
    );

    $activeInspectorId = (int) (
        $activeAssessment['inspector_id']
        ?? 0
    );

    $activeStatus = (string) (
        $activeAssessment['status']
        ?? ''
    );

    $canOpen =
        $activeAssessmentId > 0
        && (
            !$isTechnician
            || $activeInspectorId
                === $currentUserId
        );

    if ($canOpen) {
        if (
            $isTechnician
            && in_array(
                $activeStatus,
                [
                    'draft',
                    'returned',
                ],
                true
            )
        ) {
            $activeAssessmentUrl =
                'pemeriksaan/edit.php?id='
                . $activeAssessmentId;

            $activeAssessmentLabel =
                $activeStatus === 'returned'
                    ? 'Perbaiki Pemeriksaan Aktif'
                    : 'Lanjutkan Pemeriksaan Aktif';
        } else {
            $activeAssessmentUrl =
                'pemeriksaan/detail.php?id='
                . $activeAssessmentId;

            $activeAssessmentLabel =
                'Lihat Pemeriksaan Aktif';
        }
    }
}

// Konfigurasi Halaman

$pageTitle =
    'Detail Motor '
    . $alternativeCode;

$pageDescription =
    'Identitas, data nameplate, dan riwayat pemeriksaan motor.';

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
            <div class="alert alert-danger mb-4" role="alert">
                <strong class="d-block mb-1">
                    Data belum dapat dimuat lengkap
                </strong>

                <?= e(
                    $pageError
                ) ?>
            </div>
        <?php endif; ?>

        <!-- Judul -->
        <section class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3 mb-4">
            <div>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary text-white fw-bold" style="width: 58px; height: 58px;">
                        <?= e(
                            $alternativeCode
                        ) ?>
                    </span>

                    <span class="badge text-bg-<?= e( $machineStatusConfiguration['class'] ) ?> p-2">
                        <?= e(
                            $machineStatusConfiguration['label']
                        ) ?>
                    </span>

                    <span class="badge text-bg-light border p-2">
                        <?= e(
                            $motorFunctionLabel
                        ) ?>
                    </span>
                </div>

                <h1 class="page-title">
                    Detail Data Motor
                </h1>

                <p class="page-description">
                    <?= e(
                        $machine['motor_name']
                        ?? '-'
                    ) ?>

                    &middot;

                    <?= e(
                        $machine['machine_code']
                        ?? '-'
                    ) ?>
                </p>
            </div>

            <div class="page-header-actions">
                <a href="<?= e( base_url( 'motor/index.php' ) ) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>

                <?php if (
                    $canEditMachine
                ): ?>
                    <a href="<?= e( base_url( 'motor/edit.php?id=' . $machineId ) ) ?>" class="btn btn-primary">
                        Ubah Data Motor
                    </a>
                <?php endif; ?>

                <?php if (
                    $canStartAssessment
                ): ?>
                    <a href="<?= e( base_url( 'pemeriksaan/create.php?period_id=' . (int) $activePeriod['id'] . '&machine_id=' . $machineId ) ) ?>" class="btn btn-primary">
                        Mulai Pemeriksaan
                    </a>
                <?php endif; ?>

                <?php if (
                    $activeAssessmentUrl !== null
                    && $activeAssessmentLabel !== null
                ): ?>
                    <a href="<?= e( base_url( $activeAssessmentUrl ) ) ?>" class="btn btn-outline-primary">
                        <?= e(
                            $activeAssessmentLabel
                        ) ?>
                    </a>
                <?php endif; ?>
            </div>
        </section>

        <div class="alert alert-info mb-4" role="status">
            Kode alternatif, kode mesin, nomor mesin, dan fungsi motor
            merupakan identitas tetap penelitian dan tidak dapat diubah.
        </div>

        <?php if (
            !$nameplateReady
        ): ?>
            <div class="alert alert-warning mb-4" role="alert">
                <strong class="d-block mb-1">
                    Data acuan pemeriksaan belum lengkap
                </strong>

                Lengkapi:

                <?= e(
                    implode(
                        ', ',
                        $missingReferences
                    )
                ) ?>.
            </div>
        <?php endif; ?>

        <!-- Ringkasan -->
        <section class="row g-3 mb-4" aria-label="Ringkasan motor">
            <?php

            $summaryCards = [
                [
                    'label' =>
                        'Total Pemeriksaan',

                    'value' =>
                        $totalAssessments,

                    'note' =>
                        'Seluruh periode',
                ],

                [
                    'label' =>
                        'Menunggu Validasi',

                    'value' =>
                        $totalWaiting,

                    'note' =>
                        'Perlu keputusan Supervisor',
                ],

                [
                    'label' =>
                        'Disetujui',

                    'value' =>
                        $totalValidated,

                    'note' =>
                        'Termasuk hasil TOPSIS',
                ],

                [
                    'label' =>
                        'Dikembalikan',

                    'value' =>
                        $totalReturned,

                    'note' =>
                        'Perlu diperbaiki Teknisi',
                ],
            ];

            ?>

            <?php foreach (
                $summaryCards
                as $card
            ): ?>
                <div class="col-6 col-lg-3">
                    <article class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                <?= e(
                                    $card['label']
                                ) ?>
                            </p>

                            <p class="display-6 fw-semibold mb-1">
                                <?= e(
                                    $card['value']
                                ) ?>
                            </p>

                            <small class="text-body-secondary">
                                <?= e(
                                    $card['note']
                                ) ?>
                            </small>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </section>

        <!-- Identitas dan Nameplate -->
        <div class="row g-4 mb-4">

            <div class="col-xl-6">
                <section class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h2 class="h5 mb-1">
                            Identitas Motor
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Identitas tetap alternatif A1 sampai A6.
                        </p>
                    </div>

                    <div class="card-body p-4">
                        <dl class="row mb-0">

                            <dt class="col-sm-5">
                                Kode Alternatif
                            </dt>

                            <dd class="col-sm-7">
                                <strong>
                                    <?= e(
                                        $alternativeCode
                                    ) ?>
                                </strong>
                            </dd>

                            <dt class="col-sm-5">
                                Kode Mesin
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $displayText(
                                        $machine['machine_code']
                                        ?? null
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Nomor Mesin
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $displayText(
                                        $machine['machine_number']
                                        ?? null
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Nama Motor
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $machine['motor_name']
                                    ?? '-'
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Fungsi Motor
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $motorFunctionLabel
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Lokasi
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $displayText(
                                        $machine['location']
                                        ?? null
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Status
                            </dt>

                            <dd class="col-sm-7 mb-0">
                                <span class="badge text-bg-<?= e( $machineStatusConfiguration['class'] ) ?>">
                                    <?= e(
                                        $machineStatusConfiguration['label']
                                    ) ?>
                                </span>
                            </dd>

                        </dl>
                    </div>
                </section>
            </div>

            <div class="col-xl-6">
                <section class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white py-3">
                        <h2 class="h5 mb-1">
                            Data Nameplate
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Data acuan pengukuran motor.
                        </p>
                    </div>

                    <div class="card-body p-4">
                        <dl class="row mb-0">

                            <dt class="col-sm-5">
                                Merek
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $displayText(
                                        $machine['brand']
                                        ?? null
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Tegangan
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $displayText(
                                        $machine['voltage']
                                        ?? null
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Arus Nominal
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $displayNumber(
                                        $machine['rated_current']
                                        ?? null,
                                        2,
                                        ' A'
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Frekuensi Nominal
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $displayNumber(
                                        $machine['rated_frequency']
                                        ?? null,
                                        2,
                                        ' Hz'
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                RPM Nominal
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $displayNumber(
                                        $machine['rated_rpm']
                                        ?? null,
                                        0,
                                        ' RPM'
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Daya Motor
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $displayNumber(
                                        $machine['power_hp']
                                        ?? null,
                                        2,
                                        ' HP'
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Jumlah Kutub
                            </dt>

                            <dd class="col-sm-7 mb-0">
                                <?= e(
                                    $displayNumber(
                                        $machine['number_of_poles']
                                        ?? null,
                                        0
                                    )
                                ) ?>
                            </dd>

                        </dl>
                    </div>
                </section>
            </div>
        </div>

        <!-- Catatan -->
        <section class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h2 class="h5 mb-1">
                    Catatan Motor
                </h2>
            </div>

            <div class="card-body p-4">
                <?php if (
                    trim(
                        (string) (
                            $machine['notes']
                            ?? ''
                        )
                    ) !== ''
                ): ?>
                    <p class="mb-0">
                        <?= nl2br(
                            e(
                                (string) $machine['notes']
                            )
                        ) ?>
                    </p>
                <?php else: ?>
                    <p class="text-body-secondary mb-0">
                        Belum ada catatan tambahan untuk motor ini.
                    </p>
                <?php endif; ?>
            </div>
        </section>

        <!-- Pemeriksaan Terakhir -->
        <section class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center gap-2">
                <div>
                    <h2 class="h5 mb-1">
                        Pemeriksaan Terakhir
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        <?= $isTechnician
                            ? 'Pemeriksaan terakhir yang dibuat oleh akun Teknisi ini.'
                            : 'Pemeriksaan terbaru motor pada seluruh periode.' ?>
                    </p>
                </div>

                <?php if (
                    $latestAssessment !== null
                ): ?>
                    <?php

                    $latestStatus = (string) (
                        $latestAssessment['assessment_status']
                        ?? ''
                    );

                    $latestStatusConfig =
                        $assessmentStatusConfigurations[
                            $latestStatus
                        ]
                        ?? [
                            'label' => 'Tidak diketahui',
                            'class' => 'secondary',
                        ];

                    ?>

                    <span class="badge text-bg-<?= e( $latestStatusConfig['class'] ) ?>">
                        <?= e(
                            $latestStatusConfig['label']
                        ) ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if (
                $latestAssessment === null
            ): ?>
                <div class="card-body text-center text-body-secondary p-5">
                    Belum terdapat pemeriksaan yang dapat ditampilkan.
                </div>
            <?php else: ?>
                <div class="card-body p-4">
                    <div class="row g-4">

                        <div class="col-lg-5">
                            <dl class="row mb-0">

                                <dt class="col-5">
                                    Periode
                                </dt>

                                <dd class="col-7">
                                    <?= e(
                                        $latestAssessment['period_name']
                                        ?? '-'
                                    ) ?>
                                </dd>

                                <dt class="col-5">
                                    Tanggal
                                </dt>

                                <dd class="col-7">
                                    <?= e(
                                        format_date_id(
                                            $latestAssessment['inspection_date']
                                            ?? null
                                        )
                                    ) ?>
                                </dd>

                                <dt class="col-5">
                                    Waktu
                                </dt>

                                <dd class="col-7">
                                    <?= e(
                                        substr(
                                            (string) (
                                                $latestAssessment['inspection_time']
                                                ?? ''
                                            ),
                                            0,
                                            5
                                        )
                                    ) ?>
                                </dd>

                                <dt class="col-5">
                                    Teknisi
                                </dt>

                                <dd class="col-7">
                                    <?= e(
                                        $latestAssessment['inspector_name']
                                        ?? '-'
                                    ) ?>
                                </dd>

                                <dt class="col-5">
                                    Operasi
                                </dt>

                                <dd class="col-7">
                                    <?= e(
                                        $operatingStatusLabels[
                                            (string) (
                                                $latestAssessment['operating_status']
                                                ?? ''
                                            )
                                        ]
                                        ?? '-'
                                    ) ?>

                                    <?php if (
                                        (
                                            $latestAssessment['load_condition']
                                            ?? null
                                        ) !== null
                                    ): ?>
                                        &middot;

                                        <?= e(
                                            $loadConditionLabels[
                                                (string) $latestAssessment[
                                                    'load_condition'
                                                ]
                                            ]
                                            ?? '-'
                                        ) ?>
                                    <?php endif; ?>
                                </dd>

                                <dt class="col-5">
                                    Validator
                                </dt>

                                <dd class="col-7 mb-0">
                                    <?= e(
                                        $displayText(
                                            $latestAssessment['validator_name']
                                            ?? null
                                        )
                                    ) ?>
                                </dd>

                            </dl>
                        </div>

                        <div class="col-lg-7">
                            <?php

                            $latestValues = [
                                'C1' => [
                                    $latestAssessment['c1_value']
                                    ?? null,
                                    2,
                                    ' °C',
                                ],

                                'C2' => [
                                    $latestAssessment['c2_value']
                                    ?? null,
                                    2,
                                    ' %',
                                ],

                                'C3' => [
                                    $latestAssessment['c3_value']
                                    ?? null,
                                    2,
                                    ' %',
                                ],

                                'C4' => [
                                    $latestAssessment['c4_value']
                                    ?? null,
                                    4,
                                    '',
                                ],

                                'C5' => [
                                    $latestAssessment['c5_value']
                                    ?? null,
                                    4,
                                    '',
                                ],
                            ];

                            ?>

                            <div class="row g-3">
                                <?php foreach (
                                    $latestValues
                                    as $code => [
                                        $value,
                                        $decimals,
                                        $suffix,
                                    ]
                                ): ?>
                                    <div class="col-6 col-md">
                                        <div class="metric-box h-100">
                                            <p class="metric-label">
                                                <?= e(
                                                    $code
                                                ) ?>
                                            </p>

                                            <p class="metric-value">
                                                <?= e(
                                                    $displayNumber(
                                                        $value,
                                                        $decimals,
                                                        $suffix
                                                    )
                                                ) ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if (
                                (int) (
                                    $latestAssessment['c4_has_danger']
                                    ?? 0
                                ) === 1

                                || (int) (
                                    $latestAssessment['c5_has_danger']
                                    ?? 0
                                ) === 1
                            ): ?>
                                <div class="alert alert-danger mt-3 mb-0">
                                    Pemeriksaan terakhir memiliki
                                    temuan Bahaya.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <a href="<?= e( base_url( 'pemeriksaan/detail.php?id=' . (int) $latestAssessment[ 'assessment_id' ] ) ) ?>" class="btn btn-outline-primary">
                            Lihat Detail Pemeriksaan
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <!-- Hasil TOPSIS Terakhir -->
        <?php if (
            !$isTechnician
        ): ?>
            <section class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h2 class="h5 mb-1">
                        Hasil TOPSIS Terakhir
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Posisi prioritas relatif motor pada
                        periode terakhir.
                    </p>
                </div>

                <?php if (
                    $latestTopsis === null
                ): ?>
                    <div class="card-body text-center text-body-secondary p-5">
                        Motor ini belum mempunyai hasil TOPSIS.
                    </div>
                <?php else: ?>
                    <div class="card-body p-4">
                        <?php

                        $topsisCards = [
                            [
                                'label' =>
                                    'Peringkat',

                                'value' =>
                                    $latestTopsis['ranking']
                                    ?? '-',
                            ],

                            [
                                'label' =>
                                    'Kelompok Prioritas',

                                'value' =>
                                    $latestTopsis['rank_group']
                                    ?? '-',
                            ],

                            [
                                'label' =>
                                    'Nilai Preferensi',

                                'value' =>
                                    $displayNumber(
                                        $latestTopsis['preference_value']
                                        ?? null,
                                        6
                                    ),
                            ],

                            [
                                'label' =>
                                    'Label Prioritas',

                                'value' =>
                                    $displayText(
                                        $latestTopsis['priority_label']
                                        ?? null,
                                        'Prioritas '
                                        . (
                                            $latestTopsis['rank_group']
                                            ?? $latestTopsis['ranking']
                                            ?? '-'
                                        )
                                    ),
                            ],
                        ];

                        ?>

                        <div class="row g-3">
                            <?php foreach (
                                $topsisCards
                                as $card
                            ): ?>
                                <div class="col-sm-6 col-xl-3">
                                    <div class="metric-box h-100">
                                        <p class="metric-label">
                                            <?= e(
                                                $card['label']
                                            ) ?>
                                        </p>

                                        <p class="metric-value <?= $card['label'] === 'Label Prioritas' ? 'fs-6' : '' ?>">
                                            <?= e(
                                                $card['value']
                                            ) ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-4">
                            <p class="text-body-secondary mb-0">
                                <?= e(
                                    $latestTopsis['period_name']
                                    ?? '-'
                                ) ?>

                                &middot;

                                dihitung

                                <?= e(
                                    format_date_id(
                                        $latestTopsis['calculated_at']
                                        ?? null,
                                        true
                                    )
                                ) ?>
                            </p>

                            <a href="<?= e( base_url( 'topsis/detail.php?period_id=' . (int) $latestTopsis['period_id'] . '&assessment_id=' . (int) $latestTopsis['assessment_id'] ) ) ?>" class="btn btn-outline-primary">
                                Detail TOPSIS
                            </a>
                        </div>

                        <div class="alert alert-light border mt-4 mb-0">
                            Peringkat TOPSIS merupakan prioritas
                            relatif, bukan diagnosis otomatis bahwa
                            motor pasti rusak.
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <!-- Riwayat -->
        <section class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center gap-2">
                <div>
                    <h2 class="h5 mb-1">
                        Riwayat Pemeriksaan
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        <?= $isTechnician
                            ? 'Hanya pemeriksaan yang dibuat oleh akun Teknisi ini.'
                            : 'Maksimal 20 pemeriksaan terbaru.' ?>
                    </p>
                </div>

                <span class="badge text-bg-light border">
                    <?= e(
                        count($historyRows)
                    ) ?>
                    Data
                </span>
            </div>

            <div class="card-body p-0">
                <?php if (
                    $historyRows === []
                ): ?>
                    <div class="text-center text-body-secondary p-5">
                        Belum terdapat riwayat pemeriksaan.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>
                                        Periode
                                    </th>

                                    <th>
                                        Pemeriksaan
                                    </th>

                                    <th>
                                        Teknisi
                                    </th>

                                    <th>
                                        Status
                                    </th>

                                    <th>
                                        Temuan
                                    </th>

                                    <th class="text-end">
                                        C1
                                    </th>

                                    <th class="text-end">
                                        C2
                                    </th>

                                    <th class="text-end">
                                        C3
                                    </th>

                                    <th class="text-end">
                                        C4
                                    </th>

                                    <th class="text-end">
                                        C5
                                    </th>

                                    <?php if (
                                        !$isTechnician
                                    ): ?>
                                        <th class="text-center">
                                            TOPSIS
                                        </th>
                                    <?php endif; ?>

                                    <th class="text-end">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach (
                                    $historyRows
                                    as $row
                                ): ?>
                                    <?php

                                    $status = (string) (
                                        $row['assessment_status']
                                        ?? ''
                                    );

                                    $statusConfig =
                                        $assessmentStatusConfigurations[
                                            $status
                                        ]
                                        ?? [
                                            'label' =>
                                                'Tidak diketahui',

                                            'class' =>
                                                'secondary',
                                        ];

                                    $hasDanger =
                                        (int) (
                                            $row['c4_has_danger']
                                            ?? 0
                                        ) === 1

                                        || (int) (
                                            $row['c5_has_danger']
                                            ?? 0
                                        ) === 1;

                                    ?>

                                    <tr class="<?= $hasDanger ? 'table-danger' : '' ?>">
                                        <td>
                                            <strong class="d-block">
                                                <?= e(
                                                    $row['period_name']
                                                    ?? '-'
                                                ) ?>
                                            </strong>

                                            <small class="text-body-secondary">
                                                <?= e(
                                                    ucfirst(
                                                        (string) (
                                                            $row['period_status']
                                                            ?? '-'
                                                        )
                                                    )
                                                ) ?>
                                            </small>
                                        </td>

                                        <td>
                                            <strong class="d-block">
                                                <?= e(
                                                    format_date_id(
                                                        $row['inspection_date']
                                                        ?? null
                                                    )
                                                ) ?>
                                            </strong>

                                            <small class="text-body-secondary">
                                                <?= e(
                                                    substr(
                                                        (string) (
                                                            $row['inspection_time']
                                                            ?? ''
                                                        ),
                                                        0,
                                                        5
                                                    )
                                                ) ?>

                                                &middot;

                                                <?= e(
                                                    $operatingStatusLabels[
                                                        (string) (
                                                            $row['operating_status']
                                                            ?? ''
                                                        )
                                                    ]
                                                    ?? '-'
                                                ) ?>
                                            </small>
                                        </td>

                                        <td>
                                            <?= e(
                                                $row['inspector_name']
                                                ?? '-'
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="badge text-bg-<?= e( $statusConfig['class'] ) ?>">
                                                <?= e(
                                                    $statusConfig['label']
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php if (
                                                $hasDanger
                                            ): ?>
                                                <span class="badge text-bg-danger">
                                                    Bahaya
                                                </span>
                                            <?php else: ?>
                                                <span class="text-body-secondary">
                                                    -
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-end">
                                            <?= e(
                                                $displayNumber(
                                                    $row['c1_value']
                                                    ?? null,
                                                    2
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-end">
                                            <?= e(
                                                $displayNumber(
                                                    $row['c2_value']
                                                    ?? null,
                                                    2
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-end">
                                            <?= e(
                                                $displayNumber(
                                                    $row['c3_value']
                                                    ?? null,
                                                    2
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-end">
                                            <?= e(
                                                $displayNumber(
                                                    $row['c4_value']
                                                    ?? null,
                                                    4
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-end">
                                            <?= e(
                                                $displayNumber(
                                                    $row['c5_value']
                                                    ?? null,
                                                    4
                                                )
                                            ) ?>
                                        </td>

                                        <?php if (
                                            !$isTechnician
                                        ): ?>
                                            <td class="text-center">
                                                <?php if (
                                                    (
                                                        $row['ranking']
                                                        ?? null
                                                    ) !== null
                                                ): ?>
                                                    <strong class="d-block">
                                                        Peringkat

                                                        <?= e(
                                                            $row['ranking']
                                                        ) ?>
                                                    </strong>

                                                    <small class="text-body-secondary">
                                                        Kelompok

                                                        <?= e(
                                                            $row['rank_group']
                                                            ?? '-'
                                                        ) ?>
                                                    </small>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>

                                        <td class="text-end">
                                            <a href="<?= e( base_url( 'pemeriksaan/detail.php?id=' . (int) $row[ 'assessment_id' ] ) ) ?>" class="btn btn-sm btn-outline-primary">
                                                Lihat
                                            </a>
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
            Data dibuat:

            <?= e(
                format_date_id(
                    $machine['created_at']
                    ?? null,
                    true
                )
            ) ?>

            &middot;

            terakhir diperbarui:

            <?= e(
                format_date_id(
                    $machine['updated_at']
                    ?? null,
                    true
                )
            ) ?>
        </p>
    </main>
</div>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

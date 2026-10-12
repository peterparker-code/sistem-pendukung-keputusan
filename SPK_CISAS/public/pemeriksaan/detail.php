<?php

declare(strict_types=1);

/*
 * Detail Pemeriksaan Sistem SPK PT CISAS
 * Halaman ini dapat diakses oleh:
 * - Administrator;
 * - Supervisor Maintenance;
 * - Teknisi Maintenance.
 * Fungsinya:
 * - menampilkan identitas pemeriksaan dan motor;
 * - menampilkan pengukuran C1, C2, dan C3;
 * - menampilkan checklist C4 dan C5;
 * - menampilkan nilai akhir C1 sampai C5;
 * - menyediakan tindakan sesuai peran dan status pemeriksaan;
 * - menyediakan tampilan cetak laporan pemeriksaan.
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

$isAdmin = $currentRole === 'admin';

$isSupervisor = $currentRole === 'supervisor';

$isTechnician = $currentRole === 'technician';

// Validasi ID Pemeriksaan

$assessmentId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if ($assessmentId === false) {
    $notFoundMessage =
        'ID pemeriksaan tidak valid atau tidak tersedia.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '404.php';

    exit;
}

$assessmentId = (int) $assessmentId;

// Nilai Awal

$assessment = null;

$measurement = null;

$score = null;

$checklistRows = [];

$pageError = null;

// Ambil Data Detail Pemeriksaan

try {
    // Identitas Pemeriksaan

    $assessmentStatement = $pdo->prepare(
        "SELECT
            a.id,
            a.period_id,
            a.machine_id,
            a.inspector_id,
            a.inspection_date,
            a.inspection_time,
            a.operating_status,
            a.load_condition,
            a.operating_notes,
            a.status,
            a.validated_by,
            a.validated_at,
            a.supervisor_note,
            a.created_at,
            a.updated_at,

            p.name AS period_name,
            p.start_date AS period_start_date,
            p.end_date AS period_end_date,
            p.status AS period_status,

            m.alternative_code,
            m.machine_code,
            m.machine_number,
            m.motor_name,
            m.motor_function,
            m.brand,
            m.voltage,
            m.rated_current AS machine_rated_current,
            m.rated_frequency AS machine_rated_frequency,
            m.rated_rpm AS machine_rated_rpm,
            m.power_hp,
            m.number_of_poles,
            m.location,
            m.status AS machine_status,

            inspector.name AS inspector_name,
            inspector.username AS inspector_username,
            inspector.is_active AS inspector_is_active,

            validator.name AS validator_name,
            validator.username AS validator_username

         FROM assessments AS a

         INNER JOIN assessment_periods AS p
            ON p.id = a.period_id

         INNER JOIN machines AS m
            ON m.id = a.machine_id

         INNER JOIN users AS inspector
            ON inspector.id = a.inspector_id

         LEFT JOIN users AS validator
            ON validator.id = a.validated_by

         WHERE a.id = :id

         LIMIT 1"
    );

    $assessmentStatement->execute([
        'id' => $assessmentId,
    ]);

    $assessmentData =
        $assessmentStatement->fetch();

    $assessment = is_array($assessmentData)
        ? $assessmentData
        : null;

    if ($assessment === null) {
        $notFoundMessage =
            'Data pemeriksaan tidak ditemukan.';

        require dirname(__DIR__)
            . DIRECTORY_SEPARATOR
            . '404.php';

        exit;
    }

    /*
 * Batasi Teknisi pada Pemeriksaan Miliknya
 * Pemeriksaan kepemilikan dilakukan pada server setelah objek ditemukan.
 * Dengan demikian, Teknisi tidak dapat mengganti parameter id pada URL
 * untuk membuka pemeriksaan milik pengguna lain.
 */

    if (
        $isTechnician
        && (
            $currentUserId === null
            || (int) $assessment['inspector_id'] !== $currentUserId
        )
    ) {
        flash_error(
            'Teknisi hanya dapat membuka pemeriksaan yang dibuatnya sendiri.'
        );

        redirect('403.php');
    }

    // Data Pengukuran

    $measurementStatement = $pdo->prepare(
        "SELECT
            assessment_id,

            temperature_1,
            temperature_2,
            temperature_3,
            temperature_average,
            temperature_measurement_point,

            current_1,
            current_2,
            current_3,
            current_average,
            rated_current,
            current_percentage,

            operating_frequency,
            frequency_source,
            rated_frequency,
            rated_rpm,

            rpm_1,
            rpm_2,
            rpm_3,
            rpm_average,
            rpm_reference,
            rpm_reference_method,
            rpm_measurement_point,
            rpm_deviation_percentage,

            created_at,
            updated_at

         FROM measurements

         WHERE assessment_id = :assessment_id

         LIMIT 1"
    );

    $measurementStatement->execute([
        'assessment_id' => $assessmentId,
    ]);

    $measurementData =
        $measurementStatement->fetch();

    $measurement = is_array($measurementData)
        ? $measurementData
        : null;

    // Nilai Akhir C1 sampai C5

    $scoreStatement = $pdo->prepare(
        "SELECT
            assessment_id,

            c1_value,
            c2_value,
            c3_value,

            c4_total_score,
            c4_evaluated_count,
            c4_na_count,
            c4_not_checked_count,
            c4_value,
            c4_has_danger,

            c5_total_score,
            c5_evaluated_count,
            c5_na_count,
            c5_not_checked_count,
            c5_value,
            c5_has_danger,

            calculated_at

         FROM assessment_scores

         WHERE assessment_id = :assessment_id

         LIMIT 1"
    );

    $scoreStatement->execute([
        'assessment_id' => $assessmentId,
    ]);

    $scoreData = $scoreStatement->fetch();

    $score = is_array($scoreData)
        ? $scoreData
        : null;

    // Checklist C4 dan C5

    $checklistStatement = $pdo->prepare(
        "SELECT
            ci.id AS checklist_item_id,
            ci.item_code,
            ci.item_name,
            ci.inspected_part AS description,
            ci.allows_na,
            ci.display_order,

            c.code AS criterion_code,
            c.name AS criterion_name,

            cr.condition_level,
            cr.score,
            cr.finding_note

         FROM checklist_items AS ci

         INNER JOIN criteria AS c
            ON c.id = ci.criterion_id

         LEFT JOIN checklist_results AS cr
            ON cr.checklist_item_id = ci.id
           AND cr.assessment_id = :assessment_id

         WHERE c.code IN (
            'C4',
            'C5'
         )
           AND c.is_active = 1
           AND ci.is_active = 1

         ORDER BY
            c.display_order,
            ci.display_order,
            ci.id"
    );

    $checklistStatement->execute([
        'assessment_id' => $assessmentId,
    ]);

    $checklistRows =
        $checklistStatement->fetchAll();
} catch (Throwable $exception) {
    error_log(
        'Detail pemeriksaan SPK PT CISAS gagal: '
        . $exception->getMessage()
    );

    $pageError =
        'Sebagian data pemeriksaan belum dapat dimuat. '
        . 'Periksa kembali koneksi dan struktur database.';
}

// Konfigurasi Status Pemeriksaan

$statusConfigurations = [
    'draft' => [
        'label' => 'Draft',
        'class' => 'secondary',
        'description' =>
            'Pemeriksaan masih dapat dilengkapi oleh Teknisi.',
    ],

    'completed' => [
        'label' => 'Menunggu Validasi',
        'class' => 'warning',
        'description' =>
            'Pemeriksaan telah dikirim dan menunggu keputusan Supervisor.',
    ],

    'returned' => [
        'label' => 'Dikembalikan',
        'class' => 'danger',
        'description' =>
            'Pemeriksaan perlu diperbaiki oleh Teknisi.',
    ],

    'validated' => [
        'label' => 'Disetujui',
        'class' => 'success',
        'description' =>
            'Pemeriksaan telah diterima oleh Supervisor.',
    ],

    'processed' => [
        'label' => 'Diproses TOPSIS',
        'class' => 'primary',
        'description' =>
            'Nilai pemeriksaan telah digunakan dalam pemeringkatan TOPSIS.',
    ],
];

$assessmentStatus = (string) (
    $assessment['status']
    ?? 'draft'
);

$statusConfiguration =
    $statusConfigurations[$assessmentStatus]
    ?? [
        'label' => 'Tidak diketahui',
        'class' => 'secondary',
        'description' =>
            'Status pemeriksaan tidak dikenali.',
    ];

// Konfigurasi Kondisi Checklist

$conditionConfigurations = [
    'normal' => [
        'label' => 'Normal',
        'class' => 'success',
        'score' => 1,
    ],

    'warning' => [
        'label' => 'Perlu Perhatian',
        'class' => 'warning',
        'score' => 2,
    ],

    'danger' => [
        'label' => 'Bahaya',
        'class' => 'danger',
        'score' => 3,
    ],

    'not_applicable' => [
        'label' => 'Tidak Tersedia',
        'class' => 'secondary',
        'score' => null,
    ],

    'not_checked' => [
        'label' => 'Belum diisi',
        'class' => 'warning',
        'score' => null,
    ],
];

// Fungsi Tampilan

$displayText = static function (
    mixed $value,
    string $fallback = '-'
): string {
    if ($value === null) {
        return $fallback;
    }

    $text = trim((string) $value);

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

$calculateAverage = static function (
    array $values,
    int $requiredCount = 3
): ?float {
    $numericValues = [];

    foreach ($values as $value) {
        if (
            $value === null
            || $value === ''
        ) {
            continue;
        }

        $number = floatval($value);

        if (!is_finite($number)) {
            continue;
        }

        $numericValues[] = $number;
    }

    if (count($numericValues) !== $requiredCount) {
        return null;
    }

    return array_sum($numericValues)
        / count($numericValues);
};

/*
 * Nilai Rata-rata dan Nilai Turunan Pengukuran
 * Nilai yang tersimpan pada tabel measurements menjadi sumber utama.
 * Perhitungan lokal hanya digunakan sebagai fallback tampilan untuk data lama.
 */

$temperatureAverage = null;
$currentAverage = null;
$currentPercentage = null;
$rpmAverage = null;
$rpmReference = null;
$rpmDeviationPercentage = null;

if ($measurement !== null) {
    $temperatureAverage = (
        $measurement['temperature_average']
        ?? null
    ) !== null
        ? floatval($measurement['temperature_average'])
        : $calculateAverage([
            $measurement['temperature_1'] ?? null,
            $measurement['temperature_2'] ?? null,
            $measurement['temperature_3'] ?? null,
        ]);

    $currentAverage = (
        $measurement['current_average']
        ?? null
    ) !== null
        ? floatval($measurement['current_average'])
        : $calculateAverage([
            $measurement['current_1'] ?? null,
            $measurement['current_2'] ?? null,
            $measurement['current_3'] ?? null,
        ]);

    $currentPercentage = (
        $measurement['current_percentage']
        ?? null
    ) !== null
        ? floatval($measurement['current_percentage'])
        : null;

    $rpmAverage = (
        $measurement['rpm_average']
        ?? null
    ) !== null
        ? floatval($measurement['rpm_average'])
        : $calculateAverage([
            $measurement['rpm_1'] ?? null,
            $measurement['rpm_2'] ?? null,
            $measurement['rpm_3'] ?? null,
        ]);

    $rpmReference = (
        $measurement['rpm_reference']
        ?? null
    ) !== null
        ? floatval($measurement['rpm_reference'])
        : null;

    $rpmDeviationPercentage = (
        $measurement['rpm_deviation_percentage']
        ?? null
    ) !== null
        ? floatval($measurement['rpm_deviation_percentage'])
        : null;
}

// Kelompokkan dan Ringkas Checklist

$checklistGroups = [
    'C4' => [],
    'C5' => [],
];

$checklistSummary = [
    'C4' => [
        'normal' => 0,
        'warning' => 0,
        'danger' => 0,
        'not_applicable' => 0,
        'not_checked' => 0,
    ],

    'C5' => [
        'normal' => 0,
        'warning' => 0,
        'danger' => 0,
        'not_applicable' => 0,
        'not_checked' => 0,
    ],
];

$findingRows = [];

foreach ($checklistRows as $checklistRow) {
    $criterionCode = (string) (
        $checklistRow['criterion_code']
        ?? ''
    );

    if (!isset($checklistGroups[$criterionCode])) {
        continue;
    }

    $conditionLevel = (string) (
        $checklistRow['condition_level']
        ?? 'not_checked'
    );

    if (!isset($conditionConfigurations[$conditionLevel])) {
        $conditionLevel = 'not_checked';
    }

    $checklistRow['condition_level'] =
        $conditionLevel;

    $checklistGroups[$criterionCode][] =
        $checklistRow;

    $checklistSummary[$criterionCode][$conditionLevel]++;

    $findingNote = trim(
        (string) (
            $checklistRow['finding_note']
            ?? ''
        )
    );

    if (
        in_array(
            $conditionLevel,
            [
                'warning',
                'danger',
            ],
            true
        )
        || $findingNote !== ''
    ) {
        $findingRows[] = $checklistRow;
    }
}

$totalChecklistCount = count($checklistRows);

$totalNotCheckedCount =
    $checklistSummary['C4']['not_checked']
    + $checklistSummary['C5']['not_checked'];

$totalDangerCount =
    $checklistSummary['C4']['danger']
    + $checklistSummary['C5']['danger'];

$totalWarningCount =
    $checklistSummary['C4']['warning']
    + $checklistSummary['C5']['warning'];

$hasCompleteChecklist =
    $totalChecklistCount === 12
    && count($checklistGroups['C4']) === 6
    && count($checklistGroups['C5']) === 6
    && $totalNotCheckedCount === 0;

$hasCompleteMeasurement =
    $measurement !== null
    && $temperatureAverage !== null
    && $currentAverage !== null
    && $currentPercentage !== null
    && $rpmAverage !== null
    && $rpmReference !== null
    && $rpmDeviationPercentage !== null
    && ($measurement['temperature_1'] ?? null) !== null
    && ($measurement['temperature_2'] ?? null) !== null
    && ($measurement['temperature_3'] ?? null) !== null
    && ($measurement['current_1'] ?? null) !== null
    && ($measurement['current_2'] ?? null) !== null
    && ($measurement['current_3'] ?? null) !== null
    && ($measurement['rated_current'] ?? null) !== null
    && ($measurement['operating_frequency'] ?? null) !== null
    && ($measurement['rated_frequency'] ?? null) !== null
    && ($measurement['rated_rpm'] ?? null) !== null
    && ($measurement['rpm_1'] ?? null) !== null
    && ($measurement['rpm_2'] ?? null) !== null
    && ($measurement['rpm_3'] ?? null) !== null;

$hasCompleteScores =
    $score !== null
    && ($score['c1_value'] ?? null) !== null
    && ($score['c2_value'] ?? null) !== null
    && ($score['c3_value'] ?? null) !== null
    && ($score['c4_value'] ?? null) !== null
    && ($score['c5_value'] ?? null) !== null
    && (int) (
        $score['c4_not_checked_count']
        ?? 0
    ) === 0
    && (int) (
        $score['c5_not_checked_count']
        ?? 0
    ) === 0;

// Hak Akses Tindakan

$canEdit =
    $isTechnician
    && $currentUserId !== null
    && (int) $assessment['inspector_id']
        === $currentUserId
    && (string) $assessment['period_status']
        === 'active'
    && in_array(
        $assessmentStatus,
        [
            'draft',
            'returned',
        ],
        true
    );

$canValidate =
    $isSupervisor
    && (string) $assessment['period_status'] === 'active'
    && $assessmentStatus === 'completed';

$canOpenPeriod =
    $isAdmin
    || $isSupervisor;

// Fungsi Motor

$motorFunctionLabel = match (
    (string) (
        $assessment['motor_function']
        ?? ''
    )
) {
    'scraper' => 'Scraper',
    'homogenizer' => 'Homogenizer',
    default => 'Tidak diketahui',
};

// Label Kondisi Operasi dan Validator

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

$operatingStatusLabel = $operatingStatusLabels[
    (string) (
        $assessment['operating_status']
        ?? ''
    )
] ?? 'Tidak diketahui';

$loadConditionLabel = $loadConditionLabels[
    (string) (
        $assessment['load_condition']
        ?? ''
    )
] ?? '-';

$validatorName = $displayText(
    $assessment['validator_name']
    ?? null,
    '-'
);

// Konfigurasi Halaman

$pageTitle =
    'Detail Pemeriksaan '
    . (string) (
        $assessment['alternative_code']
        ?? ''
    );

$pageDescription =
    'Detail pengukuran, checklist, dan nilai pemeriksaan '
    . 'motor pada Sistem SPK PT CISAS.';

$bodyClass =
    'd-flex flex-column min-vh-100';

$includePrintCss = true;

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
                    Data pemeriksaan belum dapat dimuat lengkap
                </strong>

                <?= e($pageError) ?>
            </div>
        <?php endif; ?>

        <!-- Header khusus cetak -->
        <header class="print-document-header print-only">
            <p class="print-company-name">
                PT Citrasemesta Asrisejati
            </p>

            <p class="print-system-name">
                Sistem Pendukung Keputusan Prioritas Pemeliharaan Motor Mesin Mixer
            </p>

            <h1 class="print-report-title">
                Laporan Detail Pemeriksaan Motor
            </h1>

            <p class="print-report-subtitle">
                <?= e(
                    (string) $assessment['alternative_code']
                    . ' — '
                    . (string) $assessment['motor_name']
                ) ?>
            </p>
        </header>

        <!-- Judul halaman -->
        <section class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3 mb-4">
            <div>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary text-white fw-bold" style="width: 54px; height: 54px;">
                        <?= e(
                            $assessment['alternative_code']
                        ) ?>
                    </span>

                    <span class="badge text-bg-<?= e( $statusConfiguration['class'] ) ?> p-2">
                        <?= e(
                            $statusConfiguration['label']
                        ) ?>
                    </span>

                    <?php if ($totalDangerCount > 0): ?>
                        <span class="badge text-bg-danger p-2">
                            <?= e($totalDangerCount) ?> Temuan Bahaya
                        </span>
                    <?php endif; ?>
                </div>

                <h1 class="page-title">
                    Detail Pemeriksaan Motor
                </h1>

                <p class="page-description">
                    <?= e(
                        $assessment['motor_name']
                    ) ?>

                    &middot;

                    <?= e(
                        $assessment['period_name']
                    ) ?>
                </p>
            </div>

            <div class="page-header-actions no-print">
                <a href="<?= e( base_url( 'pemeriksaan/index.php?period_id=' . (int) $assessment['period_id'] ) ) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>

                <?php if ($canEdit): ?>
                    <a href="<?= e( base_url( 'pemeriksaan/edit.php?id=' . $assessmentId ) ) ?>" class="btn btn-primary">
                        <?= $assessmentStatus === 'returned'
                            ? 'Perbaiki Pemeriksaan'
                            : 'Lanjutkan Pengisian' ?>
                    </a>
                <?php endif; ?>

                <?php if ($canValidate): ?>
                    <a href="<?= e( base_url( 'pemeriksaan/validasi.php?id=' . $assessmentId ) ) ?>" class="btn btn-success">
                        Validasi Pemeriksaan
                    </a>
                <?php endif; ?>

                <button type="button" class="btn btn-outline-primary" data-print>
                    Cetak
                </button>
            </div>
        </section>

        <!-- Informasi status -->
        <div class="alert alert-<?= e( $statusConfiguration['class'] ) ?> mb-4" role="status">
            <?= e(
                $statusConfiguration['description']
            ) ?>
        </div>

        <?php if (
            trim(
                (string) (
                    $assessment['supervisor_note']
                    ?? ''
                )
            ) !== ''
        ): ?>
            <div class="alert alert-warning mb-4" role="status">
                <strong class="d-block mb-1">
                    Catatan Supervisor
                </strong>

                <?= nl2br(
                    e(
                        (string) $assessment[
                            'supervisor_note'
                        ]
                    )
                ) ?>

                <?php if (
                    $validatorName !== '-'
                    || (
                        $assessment['validated_at']
                        ?? null
                    ) !== null
                ): ?>
                    <small class="d-block mt-2">
                        <?= e($validatorName) ?>

                        <?php if (
                            (
                                $assessment['validated_at']
                                ?? null
                            ) !== null
                        ): ?>
                            &middot;

                            <?= e(
                                format_date_id(
                                    $assessment['validated_at'],
                                    true
                                )
                            ) ?>
                        <?php endif; ?>
                    </small>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Ringkasan nilai C1 sampai C5 -->
        <section class="row g-3 mb-4" aria-label="Ringkasan nilai kriteria">
            <?php
            $criterionCards = [
                'C1' => [
                    'label' => 'Suhu Permukaan',
                    'value' => $score['c1_value'] ?? null,
                    'decimals' => 2,
                    'suffix' => ' °C',
                ],

                'C2' => [
                    'label' => 'Persentase Arus',
                    'value' => $score['c2_value'] ?? null,
                    'decimals' => 2,
                    'suffix' => '%',
                ],

                'C3' => [
                    'label' => 'Deviasi RPM',
                    'value' => $score['c3_value'] ?? null,
                    'decimals' => 2,
                    'suffix' => '%',
                ],

                'C4' => [
                    'label' => 'Kondisi Mekanikal',
                    'value' => $score['c4_value'] ?? null,
                    'decimals' => 4,
                    'suffix' => '',
                ],

                'C5' => [
                    'label' => 'Kondisi Elektrikal',
                    'value' => $score['c5_value'] ?? null,
                    'decimals' => 4,
                    'suffix' => '',
                ],
            ];
            ?>

            <?php foreach (
                $criterionCards
                as $criterionCode => $criterionCard
            ): ?>
                <div class="col-6 col-lg">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <span class="badge text-bg-primary mb-2">
                                <?= e($criterionCode) ?>
                            </span>

                            <p class="small text-body-secondary mb-1">
                                <?= e(
                                    $criterionCard['label']
                                ) ?>
                            </p>

                            <p class="h4 fw-semibold mb-0">
                                <?= e(
                                    $displayNumber(
                                        $criterionCard['value'],
                                        $criterionCard['decimals'],
                                        $criterionCard['suffix']
                                    )
                                ) ?>
                            </p>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </section>

        <?php if (!$hasCompleteScores): ?>
            <div class="alert alert-info mb-4" role="status">
                Nilai C1 sampai C5 belum tersedia secara lengkap.
                Nilai dihitung ketika Teknisi mengirim pemeriksaan
                kepada Supervisor.
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">

            <!-- Identitas pemeriksaan -->
            <div class="col-xl-6">
                <section class="card border-0 shadow-sm h-100" aria-labelledby="assessment-identity-title">
                    <div class="card-header bg-white py-3">
                        <h2 id="assessment-identity-title" class="h5 mb-1">
                            Identitas Pemeriksaan
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Informasi periode, motor, dan Teknisi pemeriksa.
                        </p>
                    </div>

                    <div class="card-body p-4">
                        <dl class="row mb-0">
                            <dt class="col-sm-5">
                                ID Pemeriksaan
                            </dt>

                            <dd class="col-sm-7">
                                <?= e($assessmentId) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Periode
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $assessment['period_name']
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Tanggal
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    format_date_id(
                                        $assessment['inspection_date']
                                        ?? null
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Waktu
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    substr(
                                        (string) (
                                            $assessment['inspection_time']
                                            ?? ''
                                        ),
                                        0,
                                        5
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Teknisi
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $assessment['inspector_name']
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Username
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $assessment['inspector_username']
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Status
                            </dt>

                            <dd class="col-sm-7 mb-0">
                                <span class="badge text-bg-<?= e( $statusConfiguration['class'] ) ?>">
                                    <?= e(
                                        $statusConfiguration['label']
                                    ) ?>
                                </span>
                            </dd>
                        </dl>
                    </div>
                </section>
            </div>

            <!-- Identitas motor -->
            <div class="col-xl-6">
                <section class="card border-0 shadow-sm h-100" aria-labelledby="assessment-motor-title">
                    <div class="card-header bg-white py-3">
                        <h2 id="assessment-motor-title" class="h5 mb-1">
                            Identitas Motor
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Data motor dan acuan nameplate pemeriksaan.
                        </p>
                    </div>

                    <div class="card-body p-4">
                        <dl class="row mb-0">
                            <dt class="col-sm-5">
                                Alternatif
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $assessment['alternative_code']
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Nama Motor
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $assessment['motor_name']
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Kode Mesin
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $displayText(
                                        $assessment['machine_code']
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
                                        $assessment['machine_number']
                                        ?? null
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Fungsi Motor
                            </dt>

                            <dd class="col-sm-7">
                                <?= e($motorFunctionLabel) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Merek
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $displayText(
                                        $assessment['brand']
                                        ?? null
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Lokasi
                            </dt>

                            <dd class="col-sm-7 mb-0">
                                <?= e(
                                    $displayText(
                                        $assessment['location']
                                        ?? null
                                    )
                                ) ?>
                            </dd>
                        </dl>

                        <div class="mt-3 pt-3 border-top no-print">
                            <a href="<?= e( base_url( 'motor/detail.php?id=' . (int) $assessment['machine_id'] ) ) ?>" class="page-context-link">
                                Lihat data lengkap motor
                            </a>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <!-- Kondisi operasi dan informasi validasi -->
        <div class="row g-4 mb-4">
            <div class="col-xl-6">
                <section class="card border-0 shadow-sm h-100" aria-labelledby="operating-condition-title">
                    <div class="card-header bg-white py-3">
                        <h2 id="operating-condition-title" class="h5 mb-1">
                            Kondisi Operasi
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Kondisi motor pada saat pengukuran dilakukan.
                        </p>
                    </div>

                    <div class="card-body p-4">
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Status Operasi</dt>
                            <dd class="col-sm-7"><?= e($operatingStatusLabel) ?></dd>

                            <dt class="col-sm-5">Kondisi Beban</dt>
                            <dd class="col-sm-7"><?= e($loadConditionLabel) ?></dd>

                            <dt class="col-sm-5">Frekuensi Operasi</dt>
                            <dd class="col-sm-7">
                                <?= e(
                                    $displayNumber(
                                        $measurement['operating_frequency']
                                        ?? null,
                                        2,
                                        ' Hz'
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">Catatan Operasi</dt>
                            <dd class="col-sm-7 mb-0">
                                <?= nl2br(
                                    e(
                                        $displayText(
                                            $assessment['operating_notes']
                                            ?? null
                                        )
                                    )
                                ) ?>
                            </dd>
                        </dl>
                    </div>
                </section>
            </div>

            <div class="col-xl-6">
                <section class="card border-0 shadow-sm h-100" aria-labelledby="validation-information-title">
                    <div class="card-header bg-white py-3">
                        <h2 id="validation-information-title" class="h5 mb-1">
                            Informasi Validasi
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Riwayat keputusan Supervisor Maintenance.
                        </p>
                    </div>

                    <div class="card-body p-4">
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Validator</dt>
                            <dd class="col-sm-7"><?= e($validatorName) ?></dd>

                            <dt class="col-sm-5">Waktu Validasi</dt>
                            <dd class="col-sm-7">
                                <?= e(
                                    format_date_id(
                                        $assessment['validated_at']
                                        ?? null,
                                        true
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">Status Periode</dt>
                            <dd class="col-sm-7">
                                <?= e(
                                    ucfirst(
                                        (string) (
                                            $assessment['period_status']
                                            ?? '-'
                                        )
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">Status Motor</dt>
                            <dd class="col-sm-7 mb-0">
                                <?= e(
                                    (string) (
                                        $assessment['machine_status']
                                        ?? ''
                                    ) === 'active'
                                        ? 'Aktif'
                                        : 'Tidak Aktif'
                                ) ?>
                            </dd>
                        </dl>
                    </div>
                </section>
            </div>
        </div>

        <!-- Pengukuran C1, C2, dan C3 -->
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="measurement-detail-title">
            <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div>
                    <h2 id="measurement-detail-title" class="h5 mb-1">
                        Detail Pengukuran C1, C2, dan C3
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Tiga kali pengukuran, rata-rata, dan nilai turunan
                        yang disimpan oleh sistem.
                    </p>
                </div>

                <?php if ($hasCompleteMeasurement): ?>
                    <span class="badge text-bg-success">
                        Pengukuran Lengkap
                    </span>
                <?php else: ?>
                    <span class="badge text-bg-secondary">
                        Pengukuran Belum Lengkap
                    </span>
                <?php endif; ?>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">
                                    Kriteria
                                </th>

                                <th scope="col" class="text-end">
                                    Pengukuran 1
                                </th>

                                <th scope="col" class="text-end">
                                    Pengukuran 2
                                </th>

                                <th scope="col" class="text-end">
                                    Pengukuran 3
                                </th>

                                <th scope="col" class="text-end">
                                    Rata-rata
                                </th>

                                <th scope="col" class="text-end">
                                    Nilai Kriteria
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr>
                                <td>
                                    <strong class="d-block">
                                        C1 — Suhu Permukaan
                                    </strong>

                                    <small class="text-body-secondary">
                                        Infrared thermometer
                                    </small>
                                </td>

                                <td class="text-end">
                                    <?= e(
                                        $displayNumber(
                                            $measurement['temperature_1']
                                            ?? null,
                                            2,
                                            ' °C'
                                        )
                                    ) ?>
                                </td>

                                <td class="text-end">
                                    <?= e(
                                        $displayNumber(
                                            $measurement['temperature_2']
                                            ?? null,
                                            2,
                                            ' °C'
                                        )
                                    ) ?>
                                </td>

                                <td class="text-end">
                                    <?= e(
                                        $displayNumber(
                                            $measurement['temperature_3']
                                            ?? null,
                                            2,
                                            ' °C'
                                        )
                                    ) ?>
                                </td>

                                <td class="text-end fw-semibold">
                                    <?= e(
                                        $displayNumber(
                                            $temperatureAverage,
                                            2,
                                            ' °C'
                                        )
                                    ) ?>
                                </td>

                                <td class="text-end fw-semibold">
                                    <?= e(
                                        $displayNumber(
                                            $score['c1_value']
                                            ?? null,
                                            2,
                                            ' °C'
                                        )
                                    ) ?>
                                </td>
                            </tr>

                            <tr>
                                <td>
                                    <strong class="d-block">
                                        C2 — Arus Motor
                                    </strong>

                                    <small class="text-body-secondary">
                                        Clamp meter
                                    </small>
                                </td>

                                <td class="text-end">
                                    <?= e(
                                        $displayNumber(
                                            $measurement['current_1']
                                            ?? null,
                                            2,
                                            ' A'
                                        )
                                    ) ?>
                                </td>

                                <td class="text-end">
                                    <?= e(
                                        $displayNumber(
                                            $measurement['current_2']
                                            ?? null,
                                            2,
                                            ' A'
                                        )
                                    ) ?>
                                </td>

                                <td class="text-end">
                                    <?= e(
                                        $displayNumber(
                                            $measurement['current_3']
                                            ?? null,
                                            2,
                                            ' A'
                                        )
                                    ) ?>
                                </td>

                                <td class="text-end fw-semibold">
                                    <?= e(
                                        $displayNumber(
                                            $currentAverage,
                                            2,
                                            ' A'
                                        )
                                    ) ?>
                                </td>

                                <td class="text-end fw-semibold">
                                    <?= e(
                                        $displayNumber(
                                            $score['c2_value']
                                            ?? $currentPercentage,
                                            2,
                                            '%'
                                        )
                                    ) ?>
                                </td>
                            </tr>

                            <tr>
                                <td>
                                    <strong class="d-block">
                                        C3 — Deviasi RPM
                                    </strong>

                                    <small class="text-body-secondary">
                                        Tachometer
                                    </small>
                                </td>

                                <td class="text-end">
                                    <?= e(
                                        $displayNumber(
                                            $measurement['rpm_1']
                                            ?? null,
                                            0,
                                            ' RPM'
                                        )
                                    ) ?>
                                </td>

                                <td class="text-end">
                                    <?= e(
                                        $displayNumber(
                                            $measurement['rpm_2']
                                            ?? null,
                                            0,
                                            ' RPM'
                                        )
                                    ) ?>
                                </td>

                                <td class="text-end">
                                    <?= e(
                                        $displayNumber(
                                            $measurement['rpm_3']
                                            ?? null,
                                            0,
                                            ' RPM'
                                        )
                                    ) ?>
                                </td>

                                <td class="text-end fw-semibold">
                                    <?= e(
                                        $displayNumber(
                                            $rpmAverage,
                                            2,
                                            ' RPM'
                                        )
                                    ) ?>
                                </td>

                                <td class="text-end fw-semibold">
                                    <?= e(
                                        $displayNumber(
                                            $score['c3_value']
                                            ?? $rpmDeviationPercentage,
                                            2,
                                            '%'
                                        )
                                    ) ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-footer bg-white p-4">
                <div class="row g-3">
                    <div class="col-sm-6 col-lg-3">
                        <div class="metric-box h-100">
                            <p class="metric-label">Arus Nominal</p>
                            <p class="metric-value">
                                <?= e(
                                    $displayNumber(
                                        $measurement['rated_current']
                                        ?? $assessment['machine_rated_current']
                                        ?? null,
                                        2,
                                        ' A'
                                    )
                                ) ?>
                            </p>
                        </div>
                    </div>

                    <div class="col-sm-6 col-lg-3">
                        <div class="metric-box h-100">
                            <p class="metric-label">Frekuensi Nominal</p>
                            <p class="metric-value">
                                <?= e(
                                    $displayNumber(
                                        $measurement['rated_frequency']
                                        ?? $assessment['machine_rated_frequency']
                                        ?? null,
                                        2,
                                        ' Hz'
                                    )
                                ) ?>
                            </p>
                        </div>
                    </div>

                    <div class="col-sm-6 col-lg-3">
                        <div class="metric-box h-100">
                            <p class="metric-label">Frekuensi Operasi</p>
                            <p class="metric-value">
                                <?= e(
                                    $displayNumber(
                                        $measurement['operating_frequency']
                                        ?? null,
                                        2,
                                        ' Hz'
                                    )
                                ) ?>
                            </p>
                        </div>
                    </div>

                    <div class="col-sm-6 col-lg-3">
                        <div class="metric-box h-100">
                            <p class="metric-label">RPM Nominal</p>
                            <p class="metric-value">
                                <?= e(
                                    $displayNumber(
                                        $measurement['rated_rpm']
                                        ?? $assessment['machine_rated_rpm']
                                        ?? null,
                                        2,
                                        ' RPM'
                                    )
                                ) ?>
                            </p>
                        </div>
                    </div>

                    <div class="col-sm-6 col-lg-3">
                        <div class="metric-box h-100">
                            <p class="metric-label">RPM Acuan Operasional</p>
                            <p class="metric-value">
                                <?= e(
                                    $displayNumber(
                                        $rpmReference,
                                        2,
                                        ' RPM'
                                    )
                                ) ?>
                            </p>
                        </div>
                    </div>

                    <div class="col-sm-6 col-lg-3">
                        <div class="metric-box h-100">
                            <p class="metric-label">Persentase Arus</p>
                            <p class="metric-value">
                                <?= e(
                                    $displayNumber(
                                        $currentPercentage,
                                        2,
                                        ' %'
                                    )
                                ) ?>
                            </p>
                        </div>
                    </div>

                    <div class="col-sm-6 col-lg-3">
                        <div class="metric-box h-100">
                            <p class="metric-label">Deviasi RPM</p>
                            <p class="metric-value">
                                <?= e(
                                    $displayNumber(
                                        $rpmDeviationPercentage,
                                        2,
                                        ' %'
                                    )
                                ) ?>
                            </p>
                        </div>
                    </div>

                    <div class="col-sm-6 col-lg-3">
                        <div class="metric-box h-100">
                            <p class="metric-label">Metode RPM Acuan</p>
                            <p class="metric-value fs-6">
                                <?= e(
                                    $displayText(
                                        $measurement['rpm_reference_method']
                                        ?? null
                                    )
                                ) ?>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <small class="text-body-secondary d-block mb-1">
                                Titik Pengukuran Suhu
                            </small>
                            <strong>
                                <?= e(
                                    $displayText(
                                        $measurement['temperature_measurement_point']
                                        ?? null
                                    )
                                ) ?>
                            </strong>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 h-100">
                            <small class="text-body-secondary d-block mb-1">
                                Titik Pengukuran RPM
                            </small>
                            <strong>
                                <?= e(
                                    $displayText(
                                        $measurement['rpm_measurement_point']
                                        ?? null
                                    )
                                ) ?>
                            </strong>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Checklist C4 dan C5 -->
        <?php foreach (
            $checklistGroups
            as $criterionCode => $groupRows
        ): ?>
            <?php
            $criterionTitle =
                $criterionCode === 'C4'
                    ? 'Kondisi Mekanikal'
                    : 'Kondisi Elektrikal';

            $criterionValueField =
                strtolower($criterionCode)
                . '_value';

            $criterionTotalField =
                strtolower($criterionCode)
                . '_total_score';

            $criterionEvaluatedField =
                strtolower($criterionCode)
                . '_evaluated_count';

            $criterionNaField =
                strtolower($criterionCode)
                . '_na_count';
            ?>

            <section class="card border-0 shadow-sm mb-4" aria-labelledby="<?= e( strtolower($criterionCode) ) ?>-detail-title">
                <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div>
                        <h2 id="<?= e( strtolower($criterionCode) ) ?>-detail-title" class="h5 mb-1">
                            <?= e($criterionCode) ?>
                            — <?= e($criterionTitle) ?>
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Enam indikator pemeriksaan kondisi motor.
                        </p>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge text-bg-success">
                            Normal:
                            <?= e(
                                $checklistSummary[
                                    $criterionCode
                                ]['normal']
                            ) ?>
                        </span>

                        <span class="badge text-bg-warning">
                            Perlu Perhatian:
                            <?= e(
                                $checklistSummary[
                                    $criterionCode
                                ]['warning']
                            ) ?>
                        </span>

                        <span class="badge text-bg-danger">
                            Bahaya:
                            <?= e(
                                $checklistSummary[
                                    $criterionCode
                                ]['danger']
                            ) ?>
                        </span>

                        <?php if (
                            $checklistSummary[
                                $criterionCode
                            ]['not_checked'] > 0
                        ): ?>
                            <span class="badge text-bg-secondary">
                                Belum diisi:
                                <?= e(
                                    $checklistSummary[
                                        $criterionCode
                                    ]['not_checked']
                                ) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">
                                        No.
                                    </th>

                                    <th scope="col">
                                        Indikator
                                    </th>

                                    <th scope="col">
                                        Kondisi
                                    </th>

                                    <th scope="col" class="text-center">
                                        Skor
                                    </th>

                                    <th scope="col">
                                        Keterangan Temuan
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if ($groupRows === []): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-body-secondary py-4">
                                            Data checklist belum tersedia.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach (
                                        $groupRows
                                        as $index => $checklistRow
                                    ): ?>
                                        <?php
                                        $conditionLevel =
                                            (string) $checklistRow[
                                                'condition_level'
                                            ];

                                        $conditionConfiguration =
                                            $conditionConfigurations[
                                                $conditionLevel
                                            ];
                                        ?>

                                        <tr>
                                            <td>
                                                <?= e($index + 1) ?>
                                            </td>

                                            <td>
                                                <strong class="d-block">
                                                    <?= e(
                                                        $checklistRow['item_code']
                                                        ?? '-'
                                                    ) ?>
                                                    —
                                                    <?= e(
                                                        $checklistRow['item_name']
                                                        ?? '-'
                                                    ) ?>
                                                </strong>

                                                <?php if (
                                                    trim(
                                                        (string) (
                                                            $checklistRow['description']
                                                            ?? ''
                                                        )
                                                    ) !== ''
                                                ): ?>
                                                    <small class="text-body-secondary">
                                                        <?= e(
                                                            $checklistRow['description']
                                                        ) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <span class="badge text-bg-<?= e( $conditionConfiguration['class'] ) ?>">
                                                    <?= e(
                                                        $conditionConfiguration['label']
                                                    ) ?>
                                                </span>
                                            </td>

                                            <td class="text-center">
                                                <?= e(
                                                    $displayText(
                                                        $checklistRow['score']
                                                        ?? null
                                                    )
                                                ) ?>
                                            </td>

                                            <td>
                                                <?= nl2br(
                                                    e(
                                                        $displayText(
                                                            $checklistRow['finding_note']
                                                            ?? null,
                                                            'Tidak ada keterangan.'
                                                        )
                                                    )
                                                ) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card-footer bg-white p-4">
                    <div class="row g-3">
                        <div class="col-6 col-lg-3">
                            <div class="metric-box h-100">
                                <p class="metric-label">
                                    Total Skor
                                </p>

                                <p class="metric-value">
                                    <?= e(
                                        $displayNumber(
                                            $score[$criterionTotalField]
                                            ?? null,
                                            0
                                        )
                                    ) ?>
                                </p>
                            </div>
                        </div>

                        <div class="col-6 col-lg-3">
                            <div class="metric-box h-100">
                                <p class="metric-label">
                                    Dinilai
                                </p>

                                <p class="metric-value">
                                    <?= e(
                                        $displayNumber(
                                            $score[$criterionEvaluatedField]
                                            ?? null,
                                            0
                                        )
                                    ) ?>
                                </p>
                            </div>
                        </div>

                        <div class="col-6 col-lg-3">
                            <div class="metric-box h-100">
                                <p class="metric-label">
                                    Tidak Tersedia
                                </p>

                                <p class="metric-value">
                                    <?= e(
                                        $displayNumber(
                                            $score[$criterionNaField]
                                            ?? null,
                                            0
                                        )
                                    ) ?>
                                </p>
                            </div>
                        </div>

                        <div class="col-6 col-lg-3">
                            <div class="metric-box h-100">
                                <p class="metric-label">
                                    Nilai <?= e($criterionCode) ?>
                                </p>

                                <p class="metric-value">
                                    <?= e(
                                        $displayNumber(
                                            $score[$criterionValueField]
                                            ?? null,
                                            4
                                        )
                                    ) ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>

        <!-- Ringkasan temuan -->
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="finding-summary-title">
            <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div>
                    <h2 id="finding-summary-title" class="h5 mb-1">
                        Ringkasan Temuan
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Temuan yang perlu diperhatikan, bahaya, atau catatan tambahan.
                    </p>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <span class="badge text-bg-warning">
                        Perlu Perhatian: <?= e($totalWarningCount) ?>
                    </span>

                    <span class="badge text-bg-danger">
                        Bahaya: <?= e($totalDangerCount) ?>
                    </span>

                </div>
            </div>

            <div class="card-body p-0">
                <?php if ($findingRows === []): ?>
                    <div class="text-center text-body-secondary p-4">
                        Tidak terdapat temuan atau catatan tambahan.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">
                                        Kriteria
                                    </th>

                                    <th scope="col">
                                        Indikator
                                    </th>

                                    <th scope="col">
                                        Kondisi
                                    </th>

                                    <th scope="col">
                                        Keterangan
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach (
                                    $findingRows
                                    as $findingRow
                                ): ?>
                                    <?php
                                    $findingCondition =
                                        (string) $findingRow[
                                            'condition_level'
                                        ];

                                    $findingConditionConfiguration =
                                        $conditionConfigurations[
                                            $findingCondition
                                        ];
                                    ?>

                                    <tr>
                                        <td>
                                            <?= e(
                                                $findingRow['criterion_code']
                                                ?? '-'
                                            ) ?>
                                        </td>

                                        <td>
                                            <strong class="d-block">
                                                <?= e(
                                                    $findingRow['item_code']
                                                    ?? '-'
                                                ) ?>
                                            </strong>

                                            <small class="text-body-secondary">
                                                <?= e(
                                                    $findingRow['item_name']
                                                    ?? '-'
                                                ) ?>
                                            </small>
                                        </td>

                                        <td>
                                            <span class="badge text-bg-<?= e( $findingConditionConfiguration['class'] ) ?>">
                                                <?= e(
                                                    $findingConditionConfiguration['label']
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= nl2br(
                                                e(
                                                    $displayText(
                                                        $findingRow['finding_note']
                                                        ?? null,
                                                        'Belum ada keterangan.'
                                                    )
                                                )
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

        <!-- Status kelengkapan -->
        <section class="simple-status-card mb-4" aria-labelledby="completeness-title">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3">
                <div>
                    <h2 id="completeness-title" class="h5 mb-1">
                        Kelengkapan Data
                    </h2>

                    <?php if (
                        $hasCompleteMeasurement
                        && $hasCompleteChecklist
                        && $hasCompleteScores
                    ): ?>
                        <p class="text-body-secondary mb-0">
                            Pengukuran, checklist, dan nilai C1–C5 telah lengkap.
                        </p>
                    <?php else: ?>
                        <p class="text-body-secondary mb-2">
                            Masih terdapat bagian yang perlu dilengkapi:
                        </p>

                        <ul class="small text-body-secondary mb-0 ps-3">
                            <?php if (!$hasCompleteMeasurement): ?>
                                <li>Hasil pengukuran C1–C3 belum lengkap.</li>
                            <?php endif; ?>

                            <?php if (!$hasCompleteChecklist): ?>
                                <li>
                                    <?= e($totalNotCheckedCount) ?> bagian checklist belum diisi.
                                </li>
                            <?php endif; ?>

                            <?php if (!$hasCompleteScores): ?>
                                <li>Nilai C1–C5 belum tersedia.</li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <span class="badge <?= ( $hasCompleteMeasurement && $hasCompleteChecklist && $hasCompleteScores ) ? 'text-bg-success' : 'text-bg-warning' ?> p-2">
                    <?= (
                        $hasCompleteMeasurement
                        && $hasCompleteChecklist
                        && $hasCompleteScores
                    )
                        ? 'Lengkap'
                        : 'Belum Lengkap' ?>
                </span>
            </div>
        </section>

        <!-- Informasi pembaruan -->
        <section class="page-meta-card no-print mb-4">
            <dl class="row g-2">
                <dt class="col-sm-3">Dibuat</dt>
                <dd class="col-sm-9">
                    <?= e(
                        format_date_id(
                            $assessment['created_at']
                            ?? null,
                            true
                        )
                    ) ?>
                </dd>

                <dt class="col-sm-3">Terakhir diperbarui</dt>
                <dd class="col-sm-9">
                    <?= e(
                        format_date_id(
                            $assessment['updated_at']
                            ?? null,
                            true
                        )
                    ) ?>
                </dd>

                <?php if (
                    ($assessment['validated_at'] ?? null) !== null
                ): ?>
                    <dt class="col-sm-3">Divalidasi</dt>
                    <dd class="col-sm-9">
                        <?= e(
                            format_date_id(
                                $assessment['validated_at'],
                                true
                            )
                        ) ?>
                    </dd>
                <?php endif; ?>

                <?php if (
                    $score !== null
                    && ($score['calculated_at'] ?? null) !== null
                ): ?>
                    <dt class="col-sm-3">Nilai dihitung</dt>
                    <dd class="col-sm-9">
                        <?= e(
                            format_date_id(
                                $score['calculated_at'],
                                true
                            )
                        ) ?>
                    </dd>
                <?php endif; ?>
            </dl>
        </section>

        <!-- Tanda tangan cetak -->
        <section class="print-signature-section print-only">
            <div class="print-signature-column">
                <p class="print-signature-title">
                    Teknisi Maintenance
                </p>

                <span class="print-signature-name">
                    <?= e(
                        $assessment['inspector_name']
                    ) ?>
                </span>

                <span class="print-signature-position">
                    Pemeriksa
                </span>
            </div>

            <div class="print-signature-column">
                <p class="print-signature-title">
                    Supervisor Maintenance
                </p>

                <span class="print-signature-name">
                    <?= e(
                        $validatorName !== '-'
                            ? $validatorName
                            : 'Belum divalidasi'
                    ) ?>
                </span>

                <span class="print-signature-position">
                    Validator
                </span>
            </div>
        </section>

        <p class="print-generated-at print-only">
            Dicetak pada <?= e(
                format_date_id(
                    date('Y-m-d H:i:s'),
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

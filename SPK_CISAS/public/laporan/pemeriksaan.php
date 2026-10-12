<?php

declare(strict_types=1);

/*
 * Laporan Pemeriksaan Motor Mesin Mixer
 * Laporan menampilkan hasil pemeriksaan enam motor pada satu periode,
 * meliputi:
 * - identitas motor dan pemeriksaan;
 * - pengukuran C1 Suhu Permukaan;
 * - pengukuran C2 Persentase Arus;
 * - pengukuran C3 Deviasi RPM;
 * - nilai C4 Kondisi Mekanikal;
 * - nilai C5 Kondisi Elektrikal;
 * - temuan checklist dan validasi Supervisor Maintenance.
 * Hak akses:
 * - Administrator: melihat dan mencetak seluruh laporan pemeriksaan;
 * - Supervisor Maintenance: melihat dan mencetak seluruh laporan pemeriksaan;
 * - Teknisi Maintenance: hanya melihat dan mencetak pemeriksaan miliknya sendiri.
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
$currentUserName = auth_name() ?? 'Pengguna';
$currentRoleLabel = auth_role_label($currentRole);

$isAdmin = $currentRole === 'admin';
$isSupervisor = $currentRole === 'supervisor';
$isTechnician = $currentRole === 'technician';

if (
    $currentUserId === null
    || $currentUserId < 1
) {
    flash_error(
        'Sesi pengguna tidak valid. Silakan masuk kembali.'
    );

    redirect('login.php');
}

// Konfigurasi Sistem

$expectedMachineCount = (int) app_config(
    'total_machines',
    6
);

// Nilai Awal

$periods = [];
$selectedPeriod = null;
$assessmentRows = [];
$pageError = null;

$summary = [
    'assessment_count' => 0,
    'draft_count' => 0,
    'waiting_validation_count' => 0,
    'validated_count' => 0,
    'returned_count' => 0,
    'processed_count' => 0,
    'danger_motor_count' => 0,
    'warning_finding_count' => 0,
    'danger_finding_count' => 0,
    'temperature_average' => null,
    'current_percentage_average' => null,
    'rpm_deviation_average' => null,
];

$supervisorSignatureName =
    'Supervisor Maintenance';

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

    $periods = $periodStatement->fetchAll();
} catch (Throwable $exception) {
    error_log(
        'Daftar periode laporan pemeriksaan gagal dimuat: '
        . $exception->getMessage()
    );

    $pageError =
        'Daftar periode laporan belum dapat dimuat.';
}

// Tentukan Periode yang Dipilih

$periodIdInput = $_GET['period_id'] ?? null;

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
            'ID periode laporan pemeriksaan tidak valid.';

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

    if ($selectedPeriod === null) {
        $notFoundMessage =
            'Periode laporan pemeriksaan tidak ditemukan.';

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
            $selectedPeriod = $period;

            break;
        }
    }

    // Gunakan Periode Pertama Jika Tidak Ada Periode Aktif

    $selectedPeriod ??= $periods[0];
}

$selectedPeriodId = $selectedPeriod !== null
    ? (int) $selectedPeriod['id']
    : null;

// Ambil Data Pemeriksaan Periode Terpilih

if ($selectedPeriodId !== null) {
    try {
        /*
 * Pembatasan Data Berdasarkan Peran
 * Administrator dan Supervisor hanya melihat data yang sudah
 * disetujui atau diproses TOPSIS.
 * Teknisi melihat seluruh status pemeriksaan miliknya sendiri,
 * tetapi tidak dapat melihat pemeriksaan milik pengguna lain.
 */

        $assessmentVisibilitySql =
            $isTechnician
                ? 'AND a.inspector_id = :current_user_id'
                : "AND a.status IN (
                    'validated',
                    'processed'
                )";

        $assessmentParameters = [
            'period_id' =>
                $selectedPeriodId,
        ];

        if ($isTechnician) {
            $assessmentParameters[
                'current_user_id'
            ] = $currentUserId;
        }

        $assessmentStatement = $pdo->prepare(
            "SELECT
                a.id AS assessment_id,
                a.inspection_date,
                a.inspection_time,
                a.operating_status,
                a.load_condition,
                a.operating_notes,
                a.status AS assessment_status,
                a.validated_at,
                a.supervisor_note,

                m.id AS machine_id,
                m.alternative_code,
                m.machine_code,
                m.machine_number,
                m.motor_function,
                m.motor_name,
                m.brand,
                m.rated_current AS machine_rated_current,
                m.rated_frequency,
                m.rated_rpm,
                m.location,

                inspector.name AS inspector_name,
                validator.name AS validator_name,

                ms.temperature_1,
                ms.temperature_2,
                ms.temperature_3,
                ms.temperature_average,
                ms.temperature_measurement_point,

                ms.current_1,
                ms.current_2,
                ms.current_3,
                ms.current_average,
                ms.rated_current AS measured_rated_current,
                ms.current_percentage,

                ms.operating_frequency,
                ms.frequency_source,
                ms.rpm_1,
                ms.rpm_2,
                ms.rpm_3,
                ms.rpm_average,
                ms.rpm_reference,
                ms.rpm_reference_method,
                ms.rpm_measurement_point,
                ms.rpm_deviation_percentage,

                s.c1_value,
                s.c2_value,
                s.c3_value,

                s.c4_total_score,
                s.c4_evaluated_count,
                s.c4_na_count,
                s.c4_not_checked_count,
                s.c4_value,
                s.c4_has_danger,

                s.c5_total_score,
                s.c5_evaluated_count,
                s.c5_na_count,
                s.c5_not_checked_count,
                s.c5_value,
                s.c5_has_danger,
                s.calculated_at AS score_calculated_at,

                tr.preference_value,
                tr.ranking,
                tr.rank_group,
                tr.priority_label,
                tr.calculated_at AS topsis_calculated_at,

                COALESCE(cl.normal_count, 0)
                    AS normal_finding_count,

                COALESCE(cl.warning_count, 0)
                    AS warning_finding_count,

                COALESCE(cl.danger_count, 0)
                    AS danger_finding_count,

                COALESCE(cl.na_count, 0)
                    AS na_finding_count,

                COALESCE(cl.not_checked_count, 0)
                    AS not_checked_finding_count,

                cl.finding_summary

             FROM assessments AS a

             INNER JOIN machines AS m
                ON m.id = a.machine_id

             INNER JOIN users AS inspector
                ON inspector.id = a.inspector_id

             LEFT JOIN users AS validator
                ON validator.id = a.validated_by

             LEFT JOIN measurements AS ms
                ON ms.assessment_id = a.id

             LEFT JOIN assessment_scores AS s
                ON s.assessment_id = a.id

             LEFT JOIN topsis_results AS tr
                ON tr.assessment_id = a.id
               AND tr.period_id = a.period_id

             LEFT JOIN (
                SELECT
                    cr.assessment_id,

                    SUM(
                        CASE
                            WHEN cr.condition_level = 'normal'
                            THEN 1
                            ELSE 0
                        END
                    ) AS normal_count,

                    SUM(
                        CASE
                            WHEN cr.condition_level = 'warning'
                            THEN 1
                            ELSE 0
                        END
                    ) AS warning_count,

                    SUM(
                        CASE
                            WHEN cr.condition_level = 'danger'
                            THEN 1
                            ELSE 0
                        END
                    ) AS danger_count,

                    SUM(
                        CASE
                            WHEN cr.condition_level = 'not_applicable'
                            THEN 1
                            ELSE 0
                        END
                    ) AS na_count,

                    SUM(
                        CASE
                            WHEN cr.condition_level = 'not_checked'
                            THEN 1
                            ELSE 0
                        END
                    ) AS not_checked_count,

                    GROUP_CONCAT(
                        CASE
                            WHEN cr.condition_level IN (
                                'warning',
                                'danger'
                            )
                            THEN
                                ci.item_code
                                || ' - '
                                || ci.item_name
                                || ': '
                                || COALESCE(
                                    cr.finding_note,
                                    '-'
                                )
                            ELSE NULL
                        END,
                        ' | '
                    ) AS finding_summary

                FROM checklist_results AS cr

                INNER JOIN checklist_items AS ci
                    ON ci.id = cr.checklist_item_id

                INNER JOIN criteria AS c
                    ON c.id = ci.criterion_id

                WHERE ci.is_active = 1
                  AND c.is_active = 1
                  AND c.code IN (
                      'C4',
                      'C5'
                  )

                GROUP BY cr.assessment_id
             ) AS cl
                ON cl.assessment_id = a.id

             WHERE a.period_id = :period_id

               {$assessmentVisibilitySql}

             ORDER BY
                m.alternative_code ASC,
                a.inspection_date ASC,
                a.id ASC"
        );

        $assessmentStatement->execute(
            $assessmentParameters
        );

        $assessmentRows =
            $assessmentStatement->fetchAll();

        // Hitung Ringkasan dari Data Pemeriksaan

        $summary['assessment_count'] =
            count($assessmentRows);

        $temperatureValues = [];
        $currentPercentageValues = [];
        $rpmDeviationValues = [];
        $validatorNames = [];

        foreach ($assessmentRows as $row) {
            $assessmentStatus = (string) (
                $row['assessment_status']
                ?? ''
            );

            match ($assessmentStatus) {
                'draft' =>
                    $summary['draft_count']++,

                'completed' =>
                    $summary['waiting_validation_count']++,

                'validated' =>
                    $summary['validated_count']++,

                'returned' =>
                    $summary['returned_count']++,

                'processed' =>
                    $summary['processed_count']++,

                default =>
                    null,
            };

            $validatorName = trim(
                (string) (
                    $row['validator_name']
                    ?? ''
                )
            );

            if ($validatorName !== '') {
                $validatorNames[$validatorName] =
                    true;
            }

            if (
                (int) (
                    $row['c4_has_danger']
                    ?? 0
                ) === 1
                || (int) (
                    $row['c5_has_danger']
                    ?? 0
                ) === 1
            ) {
                $summary['danger_motor_count']++;
            }

            $summary['warning_finding_count'] +=
                (int) (
                    $row['warning_finding_count']
                    ?? 0
                );

            $summary['danger_finding_count'] +=
                (int) (
                    $row['danger_finding_count']
                    ?? 0
                );

            if (is_numeric(
                $row['temperature_average']
                ?? null
            )) {
                $temperatureValues[] = (float) $row[
                    'temperature_average'
                ];
            }

            if (is_numeric(
                $row['current_percentage']
                ?? null
            )) {
                $currentPercentageValues[] = (float) $row[
                    'current_percentage'
                ];
            }

            if (is_numeric(
                $row['rpm_deviation_percentage']
                ?? null
            )) {
                $rpmDeviationValues[] = (float) $row[
                    'rpm_deviation_percentage'
                ];
            }
        }

        if ($temperatureValues !== []) {
            $summary['temperature_average'] =
                array_sum($temperatureValues)
                / count($temperatureValues);
        }

        if ($currentPercentageValues !== []) {
            $summary['current_percentage_average'] =
                array_sum($currentPercentageValues)
                / count($currentPercentageValues);
        }

        if ($rpmDeviationValues !== []) {
            $summary['rpm_deviation_average'] =
                array_sum($rpmDeviationValues)
                / count($rpmDeviationValues);
        }

        if (count($validatorNames) === 1) {
            $supervisorSignatureName =
                (string) array_key_first(
                    $validatorNames
                );
        }
    } catch (Throwable $exception) {
        error_log(
            'Laporan pemeriksaan gagal dimuat: '
            . $exception->getMessage()
        );

        $pageError =
            'Data laporan pemeriksaan belum dapat dimuat.';
    }
}

// Fungsi Tampilan Angka

$displayNumber = static function (
    mixed $value,
    int $decimals = 2
): string {
    if (
        $value === null
        || $value === ''
        || !is_numeric($value)
    ) {
        return '-';
    }

    $number = (float) $value;

    if (!is_finite($number)) {
        return '-';
    }

    return format_number_id(
        $number,
        $decimals
    );
};

// Fungsi Tampilan Waktu

$displayTime = static function (
    mixed $value
): string {
    if (
        $value === null
        || trim((string) $value) === ''
    ) {
        return '-';
    }

    return substr(
        (string) $value,
        0,
        5
    ) . ' WIB';
};

// Fungsi Metadata Nilai C4 dan C5

$conditionScoreMeta = static function (
    mixed $value,
    mixed $hasDanger
): array {
    if ((int) $hasDanger === 1) {
        return [
            'label' => 'Bahaya',
            'class' => 'danger',
        ];
    }

    if (!is_numeric($value)) {
        return [
            'label' => 'Belum tersedia',
            'class' => 'secondary',
        ];
    }

    $number = (float) $value;

    if ($number >= 2.00) {
        return [
            'label' => 'Perlu Perhatian',
            'class' => 'warning',
        ];
    }

    return [
        'label' => 'Normal',
        'class' => 'success',
    ];
};

// Label Status Pemeriksaan

$assessmentStatusMeta = static function (
    ?string $status
): array {
    return match ($status) {
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
            'class' => 'primary',
        ],

        default => [
            'label' => 'Tidak diketahui',
            'class' => 'secondary',
        ],
    };
};

// Label Kondisi Operasi

$operatingStatusLabel = static function (
    ?string $status
): string {
    return match ($status) {
        'running' => 'Beroperasi',
        'stopped' => 'Berhenti',
        default => '-',
    };
};

$loadConditionLabel = static function (
    ?string $condition
): string {
    return match ($condition) {
        'no_load' => 'Tanpa Beban',
        'light' => 'Beban Ringan',
        'normal' => 'Beban Normal',
        'high' => 'Beban Tinggi',
        default => '-',
    };
};

$motorFunctionLabel = static function (
    ?string $function
): string {
    return match ($function) {
        'scraper' => 'Scraper',
        'homogenizer' => 'Homogenizer',
        default => '-',
    };
};

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

$selectedPeriodStatus = $selectedPeriod !== null
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
        'label' => 'Tidak diketahui',
        'class' => 'secondary',
    ];

// Informasi Cetak

$printedAt = date('Y-m-d H:i:s');

// Konfigurasi Halaman

$pageTitle = 'Laporan Pemeriksaan';

$pageDescription =
    $isTechnician
        ? 'Laporan terbatas hasil pemeriksaan C1 sampai C5 '
            . 'yang dibuat oleh Teknisi yang login.'
        : 'Laporan hasil pemeriksaan C1 sampai C5 '
            . 'motor mesin mixer PT CISAS.';

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
            <div class="alert alert-danger no-print" role="alert">
                <strong class="d-block mb-1">
                    Laporan belum dapat dimuat
                </strong>

                <?= e($pageError) ?>
            </div>
        <?php endif; ?>

        <section class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4 no-print">
            <div>
                <h1 class="page-title mb-1">
                    Laporan Pemeriksaan
                </h1>

                <p class="page-description mb-0">
                    <?= $isTechnician
                        ? 'Rekap pemeriksaan C1 sampai C5 milik akun Teknisi pada periode terpilih.'
                        : 'Rekap hasil pemeriksaan C1 sampai C5 pada periode terpilih.' ?>
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="<?= e(base_url( 'laporan/index.php' . ( $selectedPeriodId !== null ? '?period_id=' . $selectedPeriodId : '' ) )) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>

                <button type="button" class="btn btn-primary" onclick="window.print()" <?= $assessmentRows === [] ? 'disabled' : '' ?>>
                    Cetak Laporan
                </button>
            </div>
        </section>

        <?php if ($isTechnician): ?>
            <div class="alert alert-info mb-4 no-print" role="status">
                Laporan Teknisi dibatasi pada pemeriksaan yang dibuat
                oleh akun yang sedang login. Pemeriksaan milik pengguna
                lain tidak ditampilkan, termasuk apabila ID periode
                diubah melalui URL.
            </div>
        <?php elseif ($isAdmin): ?>
            <div class="alert alert-secondary mb-4 no-print" role="status">
                Administrator membuka laporan dalam mode baca.
                Data pemeriksaan dan validasi tidak dapat diubah
                dari halaman ini.
            </div>
        <?php elseif ($isSupervisor): ?>
            <div class="alert alert-info mb-4 no-print" role="status">
                Laporan hanya menampilkan pemeriksaan yang telah
                divalidasi atau telah diproses pada TOPSIS.
            </div>
        <?php endif; ?>

        <section class="card border-0 shadow-sm mb-4 no-print" aria-labelledby="inspection-report-filter-title">
            <div class="card-body p-4">
                <h2 id="inspection-report-filter-title" class="h5 mb-1">
                    Periode Laporan
                </h2>

                <p class="small text-body-secondary mb-3">
                    Pilih periode pemeriksaan yang akan ditampilkan.
                </p>

                <form method="get" action="<?= e(base_url( 'laporan/pemeriksaan.php' )) ?>" class="row g-3 align-items-end">
                    <div class="col-md-8 col-xl-9">
                        <label for="period_id" class="form-label">
                            Periode Pemeriksaan
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
                                    $periodStatus = (string) (
                                        $period['status']
                                        ?? 'draft'
                                    );

                                    $periodStatusInfo =
                                        $periodStatusConfigurations[
                                            $periodStatus
                                        ]
                                        ?? [
                                            'label' =>
                                                'Tidak diketahui',
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

                    <div class="col-md-4 col-xl-3">
                        <button type="submit" class="btn btn-primary w-100" <?= $periods === [] ? 'disabled' : '' ?>>
                            Tampilkan Laporan
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <?php if ($selectedPeriod === null): ?>
            <div class="alert alert-warning" role="status">
                Belum terdapat periode pemeriksaan yang dapat
                ditampilkan pada laporan.
            </div>
        <?php else: ?>
            <header class="print-report-header d-none d-print-block">
                <div class="print-report-brand-row">
                    <img
                        src="<?= e(base_url('assets/images/logo_cisas.webp')) ?>"
                        alt="Logo PT CISAS"
                        class="print-report-logo"
                    >

                    <div class="print-report-brand-copy">
                        <strong>PT CITRASEMESTA ASRISEJATI</strong>
                        <span>Sistem Pendukung Keputusan Pemeliharaan Motor Mesin Mixer</span>
                    </div>
                </div>

                <div class="print-report-title-block">
                    <h1>LAPORAN PEMERIKSAAN MOTOR MESIN MIXER</h1>
                    <p>Metode Analytical Hierarchy Process (AHP) dan TOPSIS</p>
                <?php if ($isTechnician): ?>
                    <p class="print-report-note">
                        Laporan terbatas pemeriksaan milik <?= e($currentUserName) ?>
                    </p>
                <?php endif; ?>
                </div>
            </header>

            <section class="card border-0 shadow-sm mb-4 report-identity" aria-labelledby="report-identity-title">
                <div class="card-body p-4">
                    <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                        <div>
                            <h2 id="report-identity-title" class="h5 mb-2">
                                <?= e(
                                    $selectedPeriod['name']
                                ) ?>
                            </h2>

                            <p class="mb-1">
                                <strong>Rentang tanggal:</strong>

                                <?= e(format_date_id(
                                    $selectedPeriod['start_date']
                                )) ?>

                                sampai

                                <?= e(format_date_id(
                                    $selectedPeriod['end_date']
                                )) ?>
                            </p>

                            <p class="mb-0">
                                <strong>Dibuat oleh:</strong>

                                <?= e(
                                    $selectedPeriod[
                                        'created_by_name'
                                    ]
                                ) ?>
                            </p>
                        </div>

                        <div class="text-md-end">
                            <span class="badge text-bg-<?= e( $selectedPeriodStatusInfo[ 'class' ] ) ?> mb-2">
                                <?= e(
                                    $selectedPeriodStatusInfo[
                                        'label'
                                    ]
                                ) ?>
                            </span>

                            <p class="small text-body-secondary mb-0">
                                Dicetak oleh <?= e(
                                    $currentUserName
                                ) ?>

                                <br>

                                <?= e(format_date_id(
                                    $printedAt,
                                    true
                                )) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="row row-cols-2 row-cols-md-3 row-cols-xl-6 g-3 mb-4" aria-label="Ringkasan laporan pemeriksaan">
                <div class="col">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                <?= $isTechnician
                                    ? 'Pemeriksaan Saya'
                                    : 'Pemeriksaan' ?>
                            </p>

                            <p class="h3 fw-semibold mb-1">
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
                                <?= $isTechnician
                                    ? 'Menunggu Validasi'
                                    : 'Diproses TOPSIS' ?>
                            </p>

                            <p class="h3 fw-semibold mb-1">
                                <?= e(
                                    $isTechnician
                                        ? $summary[
                                            'waiting_validation_count'
                                        ]
                                        : $summary[
                                            'processed_count'
                                        ]
                                ) ?>
                            </p>

                            <small class="text-body-secondary">
                                <?= $isTechnician
                                    ? 'Status completed'
                                    : 'Pemeriksaan' ?>
                            </small>
                        </div>
                    </article>
                </div>

                <div class="col">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                Motor Bahaya
                            </p>

                            <p class="h3 fw-semibold mb-1">
                                <?= e(
                                    $summary['danger_motor_count']
                                ) ?>
                            </p>

                            <small class="text-body-secondary">
                                C4 atau C5
                            </small>
                        </div>
                    </article>
                </div>

                <div class="col">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                Rata-rata Suhu
                            </p>

                            <p class="h5 fw-semibold mb-1">
                                <?= e($displayNumber(
                                    $summary[
                                        'temperature_average'
                                    ],
                                    2
                                )) ?> °C
                            </p>

                            <small class="text-body-secondary">
                                C1
                            </small>
                        </div>
                    </article>
                </div>

                <div class="col">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                Rata-rata Arus
                            </p>

                            <p class="h5 fw-semibold mb-1">
                                <?= e($displayNumber(
                                    $summary[
                                        'current_percentage_average'
                                    ],
                                    2
                                )) ?>%
                            </p>

                            <small class="text-body-secondary">
                                C2
                            </small>
                        </div>
                    </article>
                </div>

                <div class="col">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                Rata-rata Deviasi
                            </p>

                            <p class="h5 fw-semibold mb-1">
                                <?= e($displayNumber(
                                    $summary[
                                        'rpm_deviation_average'
                                    ],
                                    2
                                )) ?>%
                            </p>

                            <small class="text-body-secondary">
                                C3
                            </small>
                        </div>
                    </article>
                </div>
            </section>

            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="inspection-report-table-title">
                <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div>
                        <h2 id="inspection-report-table-title" class="h5 mb-1">
                            Rekap Pemeriksaan C1–C5
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Nilai pengukuran dan hasil kondisi setiap motor.
                        </p>
                    </div>

                    <span class="badge text-bg-light border">
                        <?= e(count($assessmentRows)) ?> data
                    </span>
                </div>

                <div class="card-body p-0">
                    <?php if ($assessmentRows === []): ?>
                        <div class="text-center text-body-secondary p-5">
                            <?= $isTechnician
                                ? 'Belum ada pemeriksaan milik akun Teknisi ini pada periode tersebut.'
                                : 'Belum ada pemeriksaan disetujui pada periode ini.' ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0 report-table">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col" class="text-center">
                                            No.
                                        </th>

                                        <th scope="col">
                                            Motor
                                        </th>

                                        <th scope="col">
                                            Pemeriksaan
                                        </th>

                                        <th scope="col">
                                            C1 Suhu
                                        </th>

                                        <th scope="col">
                                            C2 Arus
                                        </th>

                                        <th scope="col">
                                            C3 Deviasi RPM
                                        </th>

                                        <th scope="col">
                                            C4 Mekanikal
                                        </th>

                                        <th scope="col">
                                            C5 Elektrikal
                                        </th>

                                        <th scope="col">
                                            Validasi
                                        </th>

                                        <?php if (!$isTechnician): ?>
                                            <th scope="col" class="text-center">
                                                TOPSIS
                                            </th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach (
                                        $assessmentRows
                                        as $index => $row
                                    ): ?>
                                        <?php
                                        $c4Meta = $conditionScoreMeta(
                                            $row['c4_value'] ?? null,
                                            $row['c4_has_danger'] ?? 0
                                        );

                                        $c5Meta = $conditionScoreMeta(
                                            $row['c5_value'] ?? null,
                                            $row['c5_has_danger'] ?? 0
                                        );

                                        $statusMeta =
                                            $assessmentStatusMeta(
                                                isset(
                                                    $row[
                                                        'assessment_status'
                                                    ]
                                                )
                                                    ? (string) $row[
                                                        'assessment_status'
                                                    ]
                                                    : null
                                            );
                                        ?>

                                        <tr>
                                            <td class="text-center">
                                                <?= e($index + 1) ?>
                                            </td>

                                            <td>
                                                <strong class="d-block">
                                                    <?= e(
                                                        $row[
                                                            'alternative_code'
                                                        ]
                                                    ) ?>
                                                    —
                                                    <?= e(
                                                        $row['motor_name']
                                                    ) ?>
                                                </strong>

                                                <small class="text-body-secondary d-block">
                                                    <?= e(
                                                        $row[
                                                            'machine_code'
                                                        ]
                                                    ) ?>
                                                    /
                                                    <?= e(
                                                        $motorFunctionLabel(
                                                            isset(
                                                                $row[
                                                                    'motor_function'
                                                                ]
                                                            )
                                                                ? (string) $row[
                                                                    'motor_function'
                                                                ]
                                                                : null
                                                        )
                                                    ) ?>
                                                </small>

                                                <small class="text-body-secondary d-block">
                                                    <?= e(
                                                        $row['location']
                                                        ?? '-'
                                                    ) ?>
                                                </small>
                                            </td>

                                            <td>
                                                <strong class="d-block">
                                                    <?= e(format_date_id(
                                                        $row[
                                                            'inspection_date'
                                                        ]
                                                    )) ?>
                                                </strong>

                                                <small class="text-body-secondary d-block">
                                                    <?= e($displayTime(
                                                        $row[
                                                            'inspection_time'
                                                        ]
                                                        ?? null
                                                    )) ?>
                                                </small>

                                                <small class="text-body-secondary d-block">
                                                    <?= e(
                                                        $row[
                                                            'inspector_name'
                                                        ]
                                                    ) ?>
                                                </small>

                                                <small class="text-body-secondary d-block">
                                                    <?= e(
                                                        $operatingStatusLabel(
                                                            isset(
                                                                $row[
                                                                    'operating_status'
                                                                ]
                                                            )
                                                                ? (string) $row[
                                                                    'operating_status'
                                                                ]
                                                                : null
                                                        )
                                                    ) ?>
                                                    /
                                                    <?= e(
                                                        $loadConditionLabel(
                                                            isset(
                                                                $row[
                                                                    'load_condition'
                                                                ]
                                                            )
                                                                ? (string) $row[
                                                                    'load_condition'
                                                                ]
                                                                : null
                                                        )
                                                    ) ?>
                                                </small>
                                            </td>

                                            <td>
                                                <strong class="d-block">
                                                    <?= e($displayNumber(
                                                        $row[
                                                            'temperature_average'
                                                        ]
                                                        ?? null,
                                                        2
                                                    )) ?> °C
                                                </strong>

                                                <small class="text-body-secondary d-block">
                                                    <?= e($displayNumber(
                                                        $row[
                                                            'temperature_1'
                                                        ]
                                                        ?? null,
                                                        2
                                                    )) ?> /
                                                    <?= e($displayNumber(
                                                        $row[
                                                            'temperature_2'
                                                        ]
                                                        ?? null,
                                                        2
                                                    )) ?> /
                                                    <?= e($displayNumber(
                                                        $row[
                                                            'temperature_3'
                                                        ]
                                                        ?? null,
                                                        2
                                                    ))
                                                    ?>
                                                </small>

                                                <small class="text-body-secondary d-block">
                                                    Nilai C1:
                                                    <?= e($displayNumber(
                                                        $row['c1_value']
                                                        ?? null,
                                                        4
                                                    )) ?>
                                                </small>
                                            </td>

                                            <td>
                                                <strong class="d-block">
                                                    <?= e($displayNumber(
                                                        $row[
                                                            'current_percentage'
                                                        ]
                                                        ?? null,
                                                        2
                                                    )) ?>%
                                                </strong>

                                                <small class="text-body-secondary d-block">
                                                    Rata-rata
                                                    <?= e($displayNumber(
                                                        $row[
                                                            'current_average'
                                                        ]
                                                        ?? null,
                                                        2
                                                    )) ?> A
                                                </small>

                                                <small class="text-body-secondary d-block">
                                                    Nominal
                                                    <?= e($displayNumber(
                                                        $row[
                                                            'measured_rated_current'
                                                        ]
                                                        ?? $row[
                                                            'machine_rated_current'
                                                        ]
                                                        ?? null,
                                                        2
                                                    )) ?> A
                                                </small>

                                                <small class="text-body-secondary d-block">
                                                    Nilai C2:
                                                    <?= e($displayNumber(
                                                        $row['c2_value']
                                                        ?? null,
                                                        4
                                                    )) ?>
                                                </small>
                                            </td>

                                            <td>
                                                <strong class="d-block">
                                                    <?= e($displayNumber(
                                                        $row[
                                                            'rpm_deviation_percentage'
                                                        ]
                                                        ?? null,
                                                        2
                                                    )) ?>%
                                                </strong>

                                                <small class="text-body-secondary d-block">
                                                    Aktual
                                                    <?= e($displayNumber(
                                                        $row[
                                                            'rpm_average'
                                                        ]
                                                        ?? null,
                                                        2
                                                    )) ?> RPM
                                                </small>

                                                <small class="text-body-secondary d-block">
                                                    Acuan
                                                    <?= e($displayNumber(
                                                        $row[
                                                            'rpm_reference'
                                                        ]
                                                        ?? null,
                                                        2
                                                    )) ?> RPM
                                                </small>

                                                <small class="text-body-secondary d-block">
                                                    Nilai C3:
                                                    <?= e($displayNumber(
                                                        $row['c3_value']
                                                        ?? null,
                                                        4
                                                    )) ?>
                                                </small>
                                            </td>

                                            <td>
                                                <strong class="d-block">
                                                    <?= e($displayNumber(
                                                        $row['c4_value']
                                                        ?? null,
                                                        3
                                                    )) ?>
                                                </strong>

                                                <span class="badge text-bg-<?= e( $c4Meta['class'] ) ?>">
                                                    <?= e(
                                                        $c4Meta['label']
                                                    ) ?>
                                                </span>

                                                <small class="text-body-secondary d-block mt-1">
                                                    <?= e(
                                                        $row[
                                                            'c4_evaluated_count'
                                                        ]
                                                        ?? 0
                                                    ) ?> indikator
                                                </small>
                                            </td>

                                            <td>
                                                <strong class="d-block">
                                                    <?= e($displayNumber(
                                                        $row['c5_value']
                                                        ?? null,
                                                        3
                                                    )) ?>
                                                </strong>

                                                <span class="badge text-bg-<?= e( $c5Meta['class'] ) ?>">
                                                    <?= e(
                                                        $c5Meta['label']
                                                    ) ?>
                                                </span>

                                                <small class="text-body-secondary d-block mt-1">
                                                    <?= e(
                                                        $row[
                                                            'c5_evaluated_count'
                                                        ]
                                                        ?? 0
                                                    ) ?> indikator
                                                </small>
                                            </td>

                                            <td>
                                                <span class="badge text-bg-<?= e( $statusMeta['class'] ) ?>">
                                                    <?= e(
                                                        $statusMeta['label']
                                                    ) ?>
                                                </span>

                                                <small class="text-body-secondary d-block mt-1">
                                                    <?= e(
                                                        $row[
                                                            'validator_name'
                                                        ]
                                                        ?? '-'
                                                    ) ?>
                                                </small>

                                                <small class="text-body-secondary d-block">
                                                    <?= e(format_date_id(
                                                        $row['validated_at']
                                                        ?? null,
                                                        true
                                                    )) ?>
                                                </small>
                                            </td>

                                            <?php if (!$isTechnician): ?>
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

                                                        <small class="text-body-secondary d-block">
                                                            Kelompok
                                                            <?= e(
                                                                $row['rank_group']
                                                                ?? '-'
                                                            ) ?>
                                                        </small>

                                                        <small class="text-body-secondary d-block">
                                                            V =
                                                            <?= e(
                                                                $displayNumber(
                                                                    $row[
                                                                        'preference_value'
                                                                    ]
                                                                    ?? null,
                                                                    6
                                                                )
                                                            ) ?>
                                                        </small>
                                                    <?php else: ?>
                                                        <span class="text-body-secondary">
                                                            -
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="inspection-findings-title">
                <div class="card-header bg-white py-3">
                    <h2 id="inspection-findings-title" class="h5 mb-1">
                        Temuan dan Catatan Pemeriksaan
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Rekap temuan Perlu Perhatian/Bahaya dan catatan
                        pemeriksaan pada setiap motor.
                    </p>
                </div>

                <div class="card-body p-0">
                    <?php if ($assessmentRows === []): ?>
                        <div class="text-center text-body-secondary p-4">
                            Tidak ada data temuan yang dapat ditampilkan.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0 report-table">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">
                                            Motor
                                        </th>

                                        <th scope="col" class="text-center">
                                            Perlu Perhatian
                                        </th>

                                        <th scope="col" class="text-center">
                                            Bahaya
                                        </th>

                                        <th scope="col">
                                            Temuan Checklist
                                        </th>

                                        <th scope="col">
                                            Catatan Operasi
                                        </th>

                                        <th scope="col">
                                            Catatan Supervisor
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach (
                                        $assessmentRows
                                        as $row
                                    ): ?>
                                        <tr>
                                            <td>
                                                <strong>
                                                    <?= e(
                                                        $row[
                                                            'alternative_code'
                                                        ]
                                                    ) ?>
                                                </strong>

                                                <small class="d-block text-body-secondary">
                                                    <?= e(
                                                        $row['motor_name']
                                                    ) ?>
                                                </small>
                                            </td>

                                            <td class="text-center">
                                                <span class="badge text-bg-warning">
                                                    <?= e(
                                                        (int) (
                                                            $row[
                                                                'warning_finding_count'
                                                            ]
                                                            ?? 0
                                                        )
                                                    ) ?>
                                                </span>
                                            </td>

                                            <td class="text-center">
                                                <span class="badge text-bg-danger">
                                                    <?= e(
                                                        (int) (
                                                            $row[
                                                                'danger_finding_count'
                                                            ]
                                                            ?? 0
                                                        )
                                                    ) ?>
                                                </span>
                                            </td>

                                            <td>
                                                <?= nl2br(e(
                                                    $row[
                                                        'finding_summary'
                                                    ]
                                                    ?? '-'
                                                )) ?>
                                            </td>

                                            <td>
                                                <?= nl2br(e(
                                                    $row[
                                                        'operating_notes'
                                                    ]
                                                    ?? '-'
                                                )) ?>
                                            </td>

                                            <td>
                                                <?= nl2br(e(
                                                    $row[
                                                        'supervisor_note'
                                                    ]
                                                    ?? '-'
                                                )) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="inspection-report-notes-title">
                <div class="card-header bg-white py-3">
                    <h2 id="inspection-report-notes-title" class="h5 mb-0">
                        Keterangan
                    </h2>
                </div>

                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h3 class="h6">
                                Kriteria Pemeriksaan
                            </h3>

                            <dl class="row mb-0 small">
                                <dt class="col-2">C1</dt>
                                <dd class="col-10">
                                    Suhu Permukaan Motor
                                </dd>

                                <dt class="col-2">C2</dt>
                                <dd class="col-10">
                                    Persentase Arus Motor
                                </dd>

                                <dt class="col-2">C3</dt>
                                <dd class="col-10">
                                    Deviasi RPM
                                </dd>

                                <dt class="col-2">C4</dt>
                                <dd class="col-10">
                                    Kondisi Mekanikal
                                </dd>

                                <dt class="col-2">C5</dt>
                                <dd class="col-10 mb-0">
                                    Kondisi Elektrikal
                                </dd>
                            </dl>
                        </div>

                        <div class="col-md-6">
                            <h3 class="h6">
                                Kondisi Checklist
                            </h3>

                            <p class="small mb-2">
                                <span class="badge text-bg-success">
                                    Normal
                                </span>

                                Kondisi indikator sesuai standar pemeriksaan.
                            </p>

                            <p class="small mb-2">
                                <span class="badge text-bg-warning">
                                    Perlu Perhatian
                                </span>

                                Kondisi perlu dipantau atau dijadwalkan.
                            </p>

                            <p class="small mb-0">
                                <span class="badge text-bg-danger">
                                    Bahaya
                                </span>

                                Kondisi membutuhkan perhatian pemeliharaan.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="d-none d-print-block mt-5" aria-label="Pengesahan laporan">
                <div class="row text-center">
                    <div class="col-6">
                        <p class="mb-5">
                            Dibuat oleh,
                            <br>
                            <?= e($currentRoleLabel) ?>
                        </p>

                        <p class="mb-0">
                            <strong>
                                <?= e($currentUserName) ?>
                            </strong>
                        </p>
                    </div>

                    <div class="col-6">
                        <p class="mb-5">
                            Mengetahui,
                            <br>
                            Supervisor Maintenance
                        </p>

                        <p class="mb-0">
                            <strong>
                                <?= e(
                                    $supervisorSignatureName
                                ) ?>
                            </strong>
                        </p>
                    </div>
                </div>
            </section>

            <div class="alert alert-light border mb-0 no-print" role="status">
                <strong>Catatan:</strong>

                <?= $isTechnician
                    ? 'laporan ini hanya memuat pemeriksaan yang dibuat oleh akun Teknisi yang sedang login. Perubahan data dilakukan melalui modul Pemeriksaan sesuai status dan hak akses.'
                    : 'laporan ini merupakan rekap data pemeriksaan yang telah divalidasi pada sistem. Perubahan data dilakukan melalui modul Pemeriksaan, bukan melalui halaman laporan.' ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

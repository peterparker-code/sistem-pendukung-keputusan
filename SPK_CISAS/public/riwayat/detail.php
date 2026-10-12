<?php

declare(strict_types=1);

/*
 * Detail Riwayat Pemeliharaan
 * Menampilkan hasil pemeriksaan C1-C5, hasil TOPSIS, dan tindak lanjut.
 * Hak akses:
 * - Admin      : melihat seluruh data dalam mode baca;
 * - Supervisor : melihat serta membuat/mengubah rekomendasi tindakan;
 * - Teknisi    : hanya melihat serta mencatat tindakan yang ditugaskan
 * kepada akun Teknisi yang sedang login.
 * Pembatasan Teknisi diterapkan pada query berdasarkan performed_by.
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

// Identitas Pengguna

$currentUserId = auth_id();
$currentRole = auth_role();

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

if (
    $assessmentId === false
    || $assessmentId === null
) {
    flash_error(
        'ID riwayat pemeriksaan tidak valid.'
    );

    redirect('riwayat/index.php');
}

$assessmentId = (int) $assessmentId;

// Konfigurasi dan Nilai Awal

$expectedAlternativeCodes = [
    'A1',
    'A2',
    'A3',
    'A4',
    'A5',
    'A6',
];

$allowedActionPriorities = [
    'immediate',
    'scheduled',
    'monitoring',
];

$allowedActionStatuses = [
    'pending',
    'in_progress',
    'completed',
];

$assessment = null;
$checklistRows = [];
$dataValidationError = null;

// Ambil Detail Riwayat

try {
    /*
 * Pembatasan Objek Berdasarkan Peran
 * Admin dan Supervisor dapat melihat seluruh riwayat disetujui.
 * Teknisi hanya dapat mengambil riwayat yang tindakan pemeliharaannya
 * ditugaskan kepada akun Teknisi yang sedang login.
 */

    $detailWhere = [
        'a.id = :assessment_id',
        "a.status IN (
            'validated',
            'processed'
        )",
        "m.alternative_code IN (
            'A1',
            'A2',
            'A3',
            'A4',
            'A5',
            'A6'
        )",
    ];

    $detailParameters = [
        'assessment_id' => $assessmentId,
    ];

    if ($isTechnician) {
        $detailWhere[] =
            'ma.performed_by = :current_user_id';

        $detailParameters[
            'current_user_id'
        ] = $currentUserId;
    }

    $statement = $pdo->prepare(
        "SELECT
            a.id AS assessment_id,
            a.inspector_id,
            a.inspection_date,
            a.inspection_time,
            a.operating_status,
            a.load_condition,
            a.operating_notes,
            a.status AS assessment_status,
            a.validated_at,
            a.supervisor_note,

            p.id AS period_id,
            p.name AS period_name,
            p.start_date AS period_start_date,
            p.end_date AS period_end_date,
            p.status AS period_status,

            m.id AS machine_id,
            m.alternative_code,
            m.machine_code,
            m.machine_number,
            m.motor_name,
            m.motor_function,
            m.brand,
            m.voltage,
            m.rated_current AS machine_rated_current,
            m.rated_frequency,
            m.rated_rpm,
            m.power_hp,
            m.number_of_poles,
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

            tr.id AS topsis_result_id,
            tr.ranking,
            tr.rank_group,
            tr.preference_value,
            tr.positive_distance,
            tr.negative_distance,
            tr.priority_label,
            tr.calculated_at AS topsis_calculated_at,

            COALESCE(
                action_map.action_record_count,
                0
            ) AS action_record_count,

            ma.id AS action_id,
            ma.recommendation,
            ma.priority AS action_priority,
            ma.action_description,
            ma.target_date,
            ma.status AS action_status,
            ma.completed_date,
            ma.additional_note,
            ma.work_order_reference,
            ma.created_by,
            ma.performed_by,
            ma.created_at AS action_created_at,
            ma.updated_at AS action_updated_at,

            creator.name AS action_creator_name,
            creator.role AS action_creator_role,

            performer.name AS performed_by_name,
            performer.role AS performer_role

         FROM assessments AS a

         INNER JOIN assessment_periods AS p
            ON p.id = a.period_id

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
                assessment_id,
                COUNT(*) AS action_record_count,
                MAX(id) AS latest_action_id

            FROM maintenance_actions

            GROUP BY assessment_id
         ) AS action_map
            ON action_map.assessment_id = a.id

         LEFT JOIN maintenance_actions AS ma
            ON ma.id = action_map.latest_action_id

         LEFT JOIN users AS creator
            ON creator.id = ma.created_by

         LEFT JOIN users AS performer
            ON performer.id = ma.performed_by

         WHERE " . implode(
            ' AND ',
            $detailWhere
        ) . "

         LIMIT 1"
    );

    $statement->execute(
        $detailParameters
    );

    $assessmentData = $statement->fetch();

    $assessment = is_array($assessmentData)
        ? $assessmentData
        : null;

    if ($assessment === null) {
        flash_error(
            $isTechnician
                ? 'Riwayat tidak ditemukan atau tindakan tidak ditugaskan kepada akun Teknisi ini.'
                : 'Riwayat pemeriksaan tidak ditemukan atau belum divalidasi.'
        );

        redirect('riwayat/index.php');
    }

    // Ambil Checklist Aktif C4 dan C5

    $checklistStatement = $pdo->prepare(
        "SELECT
            c.code AS criterion_code,
            c.name AS criterion_name,

            ci.item_code,
            ci.item_name,
            ci.inspected_part,
            ci.display_order,

            cr.condition_level,
            cr.score,
            cr.finding_note

         FROM checklist_results AS cr

         INNER JOIN checklist_items AS ci
            ON ci.id = cr.checklist_item_id

         INNER JOIN criteria AS c
            ON c.id = ci.criterion_id

         WHERE cr.assessment_id = :assessment_id
           AND ci.is_active = 1
           AND c.is_active = 1
           AND c.code IN (
                'C4',
                'C5'
           )

         ORDER BY
            c.display_order ASC,
            ci.display_order ASC,
            ci.id ASC"
    );

    $checklistStatement->execute([
        'assessment_id' => $assessmentId,
    ]);

    $checklistRows =
        $checklistStatement->fetchAll();
} catch (Throwable $exception) {
    error_log(
        'Detail riwayat pemeliharaan gagal: '
        . $exception->getMessage()
    );

    flash_error(
        (bool) app_config(
            'debug',
            false
        )
            ? 'Detail riwayat gagal dimuat: '
                . $exception->getMessage()
            : 'Detail riwayat pemeliharaan belum dapat dimuat.'
    );

    redirect('riwayat/index.php');
}

// Metadata Kondisi Checklist

$conditionMeta = static function (
    ?string $condition
): array {
    return match ($condition) {
        'normal' => [
            'label' => 'Normal',
            'class' => 'success',
        ],

        'warning' => [
            'label' => 'Perlu Perhatian',
            'class' => 'warning',
        ],

        'danger' => [
            'label' => 'Bahaya',
            'class' => 'danger',
        ],

        'not_applicable' => [
            'label' => 'Tidak Tersedia',
            'class' => 'secondary',
        ],

        'not_checked' => [
            'label' => 'Belum Diperiksa',
            'class' => 'dark',
        ],

        default => [
            'label' => '-',
            'class' => 'secondary',
        ],
    };
};

// Metadata Status Tindakan

$actionStatusMeta = static function (
    ?string $status,
    bool $hasTopsis,
    bool $hasAction,
    bool $actionValid
): array {
    if (!$hasTopsis) {
        return [
            'label' => 'Menunggu TOPSIS',
            'class' => 'dark',
        ];
    }

    if (!$actionValid) {
        return [
            'label' => 'Data Perlu Dicek',
            'class' => 'danger',
        ];
    }

    if (!$hasAction) {
        return [
            'label' => 'Belum Dicatat',
            'class' => 'secondary',
        ];
    }

    return match ($status) {
        'pending' => [
            'label' => 'Menunggu',
            'class' => 'warning',
        ],

        'in_progress' => [
            'label' => 'Dikerjakan',
            'class' => 'info',
        ],

        'completed' => [
            'label' => 'Selesai',
            'class' => 'success',
        ],

        default => [
            'label' => 'Data Perlu Dicek',
            'class' => 'danger',
        ],
    };
};

// Metadata Prioritas Tindakan

$priorityMeta = static function (
    ?string $priority
): array {
    return match ($priority) {
        'immediate' => [
            'label' => 'Segera',
            'class' => 'danger',
        ],

        'scheduled' => [
            'label' => 'Terjadwal',
            'class' => 'warning',
        ],

        'monitoring' => [
            'label' => 'Monitoring',
            'class' => 'info',
        ],

        default => [
            'label' => '-',
            'class' => 'secondary',
        ],
    };
};

// Metadata Kelompok Peringkat

$rankGroupMeta = static function (
    mixed $rankGroup
): array {
    $group = is_numeric(
        (string) $rankGroup
    )
        ? (int) $rankGroup
        : null;

    return match ($group) {
        1 => [
            'label' => 'Prioritas Utama',
            'class' => 'danger',
        ],

        2 => [
            'label' => 'Prioritas Tinggi',
            'class' => 'warning',
        ],

        3 => [
            'label' => 'Prioritas Menengah',
            'class' => 'info',
        ],

        4, 5, 6 => [
            'label' => 'Prioritas Berikutnya',
            'class' => 'secondary',
        ],

        default => [
            'label' => 'Belum Diperingkat',
            'class' => 'secondary',
        ],
    };
};

// Label Informasi Pemeriksaan

$operatingLabel = match (
    $assessment['operating_status'] ?? null
) {
    'running' => 'Beroperasi',
    'stopped' => 'Berhenti',
    default => '-',
};

$loadLabel = match (
    $assessment['load_condition'] ?? null
) {
    'no_load' => 'Tanpa Beban',
    'light' => 'Beban Ringan',
    'normal' => 'Beban Normal',
    'high' => 'Beban Tinggi',
    default => '-',
};

$motorFunctionLabel = match (
    $assessment['motor_function'] ?? null
) {
    'scraper' => 'Scraper',
    'homogenizer' => 'Homogenizer',
    default => '-',
};

// Status dan Hak Akses Tindakan

$positiveDistance =
    $assessment['positive_distance']
    ?? null;

$negativeDistance =
    $assessment['negative_distance']
    ?? null;

$preferenceValue =
    $assessment['preference_value']
    ?? null;

$ranking =
    $assessment['ranking']
    ?? null;

$rankGroup =
    $assessment['rank_group']
    ?? null;

$hasTopsis =
    $assessment['topsis_result_id'] !== null

    && (string) (
        $assessment['assessment_status']
        ?? ''
    ) === 'processed'

    && is_numeric(
        (string) $positiveDistance
    )

    && is_numeric(
        (string) $negativeDistance
    )

    && is_numeric(
        (string) $preferenceValue
    )

    && is_numeric(
        (string) $ranking
    )

    && is_numeric(
        (string) $rankGroup
    )

    && is_finite(
        (float) $positiveDistance
    )

    && is_finite(
        (float) $negativeDistance
    )

    && is_finite(
        (float) $preferenceValue
    )

    && (float) $positiveDistance >= 0

    && (float) $negativeDistance >= 0

    && (float) $preferenceValue >= 0

    && (float) $preferenceValue <= 1

    && (int) $ranking >= 1

    && (int) $rankGroup >= 1;

$actionRecordCount =
    (int) (
        $assessment['action_record_count']
        ?? 0
    );

$hasAction =
    $actionRecordCount === 1
    && $assessment['action_id'] !== null;

$assignedTechnicianId =
    $assessment['performed_by'] !== null
        ? (int) $assessment['performed_by']
        : null;

$actionPriority =
    (string) (
        $assessment['action_priority']
        ?? ''
    );

$actionStatus =
    (string) (
        $assessment['action_status']
        ?? ''
    );

$actionCreatorRole =
    (string) (
        $assessment['action_creator_role']
        ?? ''
    );

$performerRole =
    (string) (
        $assessment['performer_role']
        ?? ''
    );

$recommendation = trim(
    (string) (
        $assessment['recommendation']
        ?? ''
    )
);

$actionDescription = trim(
    (string) (
        $assessment['action_description']
        ?? ''
    )
);

$completedDate = trim(
    (string) (
        $assessment['completed_date']
        ?? ''
    )
);

$actionValid =
    $actionRecordCount <= 1

    && (
        !$hasAction

        || (
            $recommendation !== ''

            && in_array(
                $actionPriority,
                $allowedActionPriorities,
                true
            )

            && in_array(
                $actionStatus,
                $allowedActionStatuses,
                true
            )

            && $actionCreatorRole === 'supervisor'

            && $assignedTechnicianId !== null
            && $assignedTechnicianId > 0

            && $performerRole === 'technician'

            && (
                $actionStatus !== 'in_progress'

                || $actionDescription !== ''
            )

            && (
                $actionStatus !== 'completed'

                || (
                    $actionDescription !== ''
                    && $completedDate !== ''
                )
            )
        )
    );

if (!$actionValid) {
    $dataValidationError =
        $actionRecordCount > 1
            ? 'Terdapat lebih dari satu tindakan pemeliharaan untuk pemeriksaan ini.'
            : 'Data rekomendasi atau pelaksanaan tindakan tidak sesuai dengan pembagian peran dan status final.';
}

$technicianCanEdit =
    $isTechnician

    && $hasTopsis

    && $hasAction

    && $actionValid

    && $assignedTechnicianId
        === $currentUserId;

$supervisorCanEdit =
    $isSupervisor

    && $hasTopsis

    && (
        $actionRecordCount === 0
        || $actionValid
    );

$canOpenAssessmentDetail =
    !$isTechnician

    || (int) (
        $assessment['inspector_id']
        ?? 0
    ) === $currentUserId;

$actionInfo = $actionStatusMeta(
    $actionStatus !== ''
        ? $actionStatus
        : null,
    $hasTopsis,
    $hasAction,
    $actionValid
);

$priorityInfo = $priorityMeta(
    $actionPriority !== ''
        ? $actionPriority
        : null
);

$rankGroupInfo = $rankGroupMeta(
    $rankGroup
);

// Konfigurasi Halaman

$pageTitle =
    'Detail Riwayat '
    . $assessment['alternative_code'];

$pageDescription =
    'Detail hasil pemeriksaan, peringkat TOPSIS, '
    . 'dan tindak lanjut pemeliharaan.';

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

        <section class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <h1 class="page-title mb-1">
                    <?= e(
                        $assessment['alternative_code']
                    ) ?>

                    —

                    <?= e(
                        $assessment['motor_name']
                    ) ?>
                </h1>

                <p class="page-description mb-0">
                    <?= e(
                        $assessment['period_name']
                    ) ?>

                    ·

                    <?= e(format_date_id(
                        $assessment['inspection_date']
                    )) ?>
                </p>
            </div>

            <div class="page-header-actions">
                <a href="<?= e(base_url( 'riwayat/index.php' )) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>

                <?php if ($canOpenAssessmentDetail): ?>
                    <a href="<?= e(base_url( 'pemeriksaan/detail.php?id=' . $assessmentId )) ?>" class="btn btn-outline-secondary">
                        Lihat Pemeriksaan
                    </a>
                <?php endif; ?>

                <?php if ($supervisorCanEdit): ?>
                    <a href="<?= e(base_url( 'riwayat/tindakan.php?id=' . $assessmentId )) ?>" class="btn btn-primary">
                        <?= $hasAction
                            ? 'Ubah Tindakan'
                            : 'Buat Tindakan' ?>
                    </a>
                <?php elseif ($technicianCanEdit): ?>
                    <a href="<?= e(base_url( 'riwayat/tindakan.php?id=' . $assessmentId )) ?>" class="btn btn-primary">
                        <?= $actionStatus === 'completed'
                            ? 'Perbarui Catatan'
                            : 'Catat Pelaksanaan' ?>
                    </a>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($isAdmin): ?>
            <div class="alert alert-secondary mb-4" role="status">
                Halaman ini ditampilkan dalam mode baca.
                Administrator dapat melihat seluruh informasi,
                tetapi tidak dapat mengubah rekomendasi maupun
                pelaksanaan pemeliharaan.
            </div>
        <?php elseif ($isSupervisor): ?>
            <div class="alert alert-info mb-4" role="status">
                Supervisor dapat menetapkan rekomendasi,
                prioritas tindakan, target, dan Teknisi pelaksana
                setelah hasil TOPSIS tersedia.
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-4" role="status">
                Detail ini hanya dapat dibuka karena tindakan
                pemeliharaan ditugaskan kepada akun Teknisi
                yang sedang login.
            </div>
        <?php endif; ?>

        <?php if ($dataValidationError !== null): ?>
            <div class="alert alert-danger mb-4" role="alert">
                <strong class="d-block mb-1">
                    Data tindakan perlu diperiksa
                </strong>

                <?= e($dataValidationError) ?>
            </div>

        <?php elseif (!$hasTopsis): ?>
            <div class="alert alert-warning mb-4" role="status">
                Pemeriksaan sudah divalidasi, tetapi
                hasil TOPSIS belum tersedia. Tindak
                lanjut baru dapat dibuat setelah proses
                pemeringkatan selesai.
            </div>

        <?php elseif (!$hasAction): ?>
            <div class="alert alert-info mb-4" role="status">
                Hasil TOPSIS sudah tersedia, tetapi
                Supervisor belum membuat rekomendasi
                tindak lanjut untuk motor ini.
            </div>
        <?php endif; ?>

        <section class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4" aria-label="Ringkasan riwayat">
            <div class="col">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Peringkat TOPSIS
                        </p>

                        <p class="display-6 fw-semibold mb-1">
                            <?= $hasTopsis
                                ? '#'
                                    . e(
                                        $assessment['ranking']
                                    )
                                : '-' ?>
                        </p>

                        <small class="text-body-secondary">
                            <?= $hasTopsis
                                ? 'Kelompok prioritas '
                                    . e((string) $rankGroup)
                                : 'Belum dihitung' ?>
                        </small>
                    </div>
                </article>
            </div>

            <div class="col">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Nilai Preferensi
                        </p>

                        <p class="h3 fw-semibold mb-1">
                            <?= $hasTopsis
                                ? e(format_number_id(
                                    $preferenceValue,
                                    4
                                ))
                                : '-' ?>
                        </p>

                        <small class="text-body-secondary">
                            <?= $hasTopsis
                                ? e(
                                    $assessment['priority_label']
                                    ?? $rankGroupInfo['label']
                                )
                                : 'Belum tersedia' ?>
                        </small>
                    </div>
                </article>
            </div>

            <div class="col">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Status Tindakan
                        </p>

                        <p class="mb-2">
                            <span class="badge text-bg-<?= e( $actionInfo['class'] ) ?> fs-6">
                                <?= e(
                                    $actionInfo['label']
                                ) ?>
                            </span>
                        </p>

                        <small class="text-body-secondary">
                            <?= !$actionValid
                                ? 'Struktur data perlu diperiksa'
                                : (
                                    $hasAction
                                        ? 'Tindak lanjut tersimpan'
                                        : 'Belum ada tindak lanjut'
                                ) ?>
                        </small>
                    </div>
                </article>
            </div>

            <div class="col">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Target Penyelesaian
                        </p>

                        <p class="h5 mb-2">
                            <?= (
                                $hasAction
                                && $actionValid
                            )
                                ? e(format_date_id(
                                    $assessment['target_date']
                                ))
                                : '-' ?>
                        </p>

                        <small class="text-body-secondary">
                            <?= !$actionValid
                                ? 'Data perlu diperiksa'
                                : (
                                    !$hasAction
                                        ? 'Belum ada tindak lanjut'
                                        : (
                                            !empty(
                                                $assessment['completed_date']
                                            )
                                                ? 'Selesai '
                                                    . e(format_date_id(
                                                        $assessment[
                                                            'completed_date'
                                                        ]
                                                    ))
                                                : 'Belum ditutup'
                                        )
                                ) ?>
                        </small>
                    </div>
                </article>
            </div>
        </section>

        <section class="card border-0 shadow-sm mb-4" aria-labelledby="inspection-information-title">
            <div class="card-header bg-white py-3">
                <h2 id="inspection-information-title" class="h5 mb-0">
                    Informasi Pemeriksaan
                </h2>
            </div>

            <div class="card-body p-4">
                <div class="row g-4">
                    <div class="col-md-6 col-xl-4">
                        <small class="text-body-secondary d-block">
                            Alternatif
                        </small>

                        <strong>
                            <?= e(
                                $assessment['alternative_code']
                            ) ?>
                        </strong>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <small class="text-body-secondary d-block">
                            Motor
                        </small>

                        <strong>
                            <?= e(
                                $assessment['machine_code']
                            ) ?>

                            /

                            <?= e($motorFunctionLabel) ?>
                        </strong>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <small class="text-body-secondary d-block">
                            Nama Motor
                        </small>

                        <strong>
                            <?= e(
                                $assessment['motor_name']
                            ) ?>
                        </strong>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <small class="text-body-secondary d-block">
                            Merek
                        </small>

                        <strong>
                            <?= e(
                                $assessment['brand']
                                ?? '-'
                            ) ?>
                        </strong>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <small class="text-body-secondary d-block">
                            Lokasi
                        </small>

                        <strong>
                            <?= e(
                                $assessment['location']
                                ?? '-'
                            ) ?>
                        </strong>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <small class="text-body-secondary d-block">
                            Pemeriksa
                        </small>

                        <strong>
                            <?= e(
                                $assessment['inspector_name']
                            ) ?>
                        </strong>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <small class="text-body-secondary d-block">
                            Tanggal dan Waktu
                        </small>

                        <strong>
                            <?= e(format_date_id(
                                $assessment['inspection_date']
                            )) ?>,

                            <?= e(substr(
                                (string) $assessment[
                                    'inspection_time'
                                ],
                                0,
                                5
                            )) ?>

                            WIB
                        </strong>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <small class="text-body-secondary d-block">
                            Kondisi Operasi
                        </small>

                        <strong>
                            <?= e($operatingLabel) ?>

                            /

                            <?= e($loadLabel) ?>
                        </strong>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <small class="text-body-secondary d-block">
                            Validator
                        </small>

                        <strong>
                            <?= e(
                                $assessment['validator_name']
                                ?? '-'
                            ) ?>
                        </strong>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <small class="text-body-secondary d-block">
                            Tanggal Validasi
                        </small>

                        <strong>
                            <?= e(format_date_id(
                                $assessment['validated_at'],
                                true
                            )) ?>
                        </strong>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <small class="text-body-secondary d-block">
                            Tegangan Nameplate
                        </small>

                        <strong>
                            <?= e(
                                trim(
                                    (string) (
                                        $assessment['voltage']
                                        ?? ''
                                    )
                                ) !== ''
                                    ? (string) $assessment['voltage']
                                    : '-'
                            ) ?>
                        </strong>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <small class="text-body-secondary d-block">
                            Daya Motor
                        </small>

                        <strong>
                            <?= e(format_number_id(
                                $assessment['power_hp'],
                                2
                            )) ?> HP
                        </strong>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <small class="text-body-secondary d-block">
                            Arus Nominal
                        </small>

                        <strong>
                            <?= e(format_number_id(
                                $assessment[
                                    'machine_rated_current'
                                ],
                                2
                            )) ?> A
                        </strong>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <small class="text-body-secondary d-block">
                            Frekuensi Nameplate
                        </small>

                        <strong>
                            <?= e(format_number_id(
                                $assessment['rated_frequency'],
                                2
                            )) ?> Hz
                        </strong>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <small class="text-body-secondary d-block">
                            RPM Nameplate
                        </small>

                        <strong>
                            <?= e(format_number_id(
                                $assessment['rated_rpm'],
                                0
                            )) ?> RPM
                        </strong>
                    </div>

                    <div class="col-12">
                        <small class="text-body-secondary d-block">
                            Catatan Operasi
                        </small>

                        <span>
                            <?= nl2br(e(
                                $assessment['operating_notes']
                                ?? '-'
                            )) ?>
                        </span>
                    </div>

                    <div class="col-12">
                        <small class="text-body-secondary d-block">
                            Catatan Supervisor
                        </small>

                        <span>
                            <?= nl2br(e(
                                $assessment['supervisor_note']
                                ?? '-'
                            )) ?>
                        </span>
                    </div>
                </div>
            </div>
        </section>

        <section class="card border-0 shadow-sm mb-4" aria-labelledby="measurement-title">
            <div class="card-header bg-white py-3">
                <h2 id="measurement-title" class="h5 mb-1">
                    Hasil Pengukuran C1–C3
                </h2>

                <p class="small text-body-secondary mb-0">
                    Nilai pengukuran langsung yang
                    menjadi data keputusan TOPSIS.
                </p>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Kriteria</th>
                                <th>Pengukuran</th>
                                <th>Hasil Akhir</th>
                                <th>Data Pendukung</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr>
                                <td>
                                    <strong>C1</strong>

                                    <small class="d-block text-body-secondary">
                                        Suhu Permukaan
                                    </small>
                                </td>

                                <td>
                                    <?= e(format_number_id(
                                        $assessment[
                                            'temperature_1'
                                        ],
                                        2
                                    )) ?>

                                    /

                                    <?= e(format_number_id(
                                        $assessment[
                                            'temperature_2'
                                        ],
                                        2
                                    )) ?>

                                    /

                                    <?= e(format_number_id(
                                        $assessment[
                                            'temperature_3'
                                        ],
                                        2
                                    )) ?>

                                    °C
                                </td>

                                <td>
                                    <strong>
                                        <?= e(format_number_id(
                                            $assessment[
                                                'temperature_average'
                                            ],
                                            2
                                        )) ?>

                                        °C
                                    </strong>

                                    <small class="d-block text-body-secondary">
                                        Nilai C1:

                                        <?= e(format_number_id(
                                            $assessment['c1_value'],
                                            4
                                        )) ?>
                                    </small>
                                </td>

                                <td>
                                    Titik ukur:

                                    <?= e(
                                        $assessment[
                                            'temperature_measurement_point'
                                        ]
                                        ?? '-'
                                    ) ?>
                                </td>
                            </tr>

                            <tr>
                                <td>
                                    <strong>C2</strong>

                                    <small class="d-block text-body-secondary">
                                        Persentase Arus
                                    </small>
                                </td>

                                <td>
                                    <?= e(format_number_id(
                                        $assessment['current_1'],
                                        2
                                    )) ?>

                                    /

                                    <?= e(format_number_id(
                                        $assessment['current_2'],
                                        2
                                    )) ?>

                                    /

                                    <?= e(format_number_id(
                                        $assessment['current_3'],
                                        2
                                    )) ?>

                                    A
                                </td>

                                <td>
                                    <strong>
                                        <?= e(format_number_id(
                                            $assessment[
                                                'current_percentage'
                                            ],
                                            2
                                        )) ?>%
                                    </strong>

                                    <small class="d-block text-body-secondary">
                                        Rata-rata

                                        <?= e(format_number_id(
                                            $assessment[
                                                'current_average'
                                            ],
                                            2
                                        )) ?>

                                        A · Nilai C2:

                                        <?= e(format_number_id(
                                            $assessment['c2_value'],
                                            4
                                        )) ?>
                                    </small>
                                </td>

                                <td>
                                    Arus nominal:

                                    <?= e(format_number_id(
                                        $assessment[
                                            'measured_rated_current'
                                        ]
                                        ?? $assessment[
                                            'machine_rated_current'
                                        ],
                                        2
                                    )) ?>

                                    A
                                </td>
                            </tr>

                            <tr>
                                <td>
                                    <strong>C3</strong>

                                    <small class="d-block text-body-secondary">
                                        Deviasi RPM
                                    </small>
                                </td>

                                <td>
                                    <?= e(format_number_id(
                                        $assessment['rpm_1'],
                                        0
                                    )) ?>

                                    /

                                    <?= e(format_number_id(
                                        $assessment['rpm_2'],
                                        0
                                    )) ?>

                                    /

                                    <?= e(format_number_id(
                                        $assessment['rpm_3'],
                                        0
                                    )) ?>

                                    RPM
                                </td>

                                <td>
                                    <strong>
                                        Deviasi

                                        <?= e(format_number_id(
                                            $assessment[
                                                'rpm_deviation_percentage'
                                            ],
                                            2
                                        )) ?>%
                                    </strong>

                                    <small class="d-block text-body-secondary">
                                        Rata-rata

                                        <?= e(format_number_id(
                                            $assessment[
                                                'rpm_average'
                                            ],
                                            2
                                        )) ?>

                                        RPM · Nilai C3:

                                        <?= e(format_number_id(
                                            $assessment['c3_value'],
                                            4
                                        )) ?>
                                    </small>
                                </td>

                                <td>
                                    Referensi

                                    <?= e(format_number_id(
                                        $assessment['rpm_reference'],
                                        2
                                    )) ?>

                                    RPM

                                    <small class="d-block text-body-secondary">
                                        Frekuensi operasi:

                                        <?= e(format_number_id(
                                            $assessment[
                                                'operating_frequency'
                                            ],
                                            2
                                        )) ?>

                                        Hz
                                    </small>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="card border-0 shadow-sm mb-4" aria-labelledby="checklist-title">
            <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div>
                    <h2 id="checklist-title" class="h5 mb-1">
                        Checklist C4–C5
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Kondisi mekanikal dan elektrikal
                        berdasarkan hasil pemeriksaan.
                    </p>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <span class="badge text-bg-light border">
                        C4 =

                        <?= e(format_number_id(
                            $assessment['c4_value'],
                            3
                        )) ?>
                    </span>

                    <span class="badge text-bg-light border">
                        C5 =

                        <?= e(format_number_id(
                            $assessment['c5_value'],
                            3
                        )) ?>
                    </span>

                    <?php if (
                        (int) (
                            $assessment['c4_has_danger']
                            ?? 0
                        ) === 1
                        || (int) (
                            $assessment['c5_has_danger']
                            ?? 0
                        ) === 1
                    ): ?>
                        <span class="badge text-bg-danger">
                            Ada kondisi bahaya
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card-body p-0">
                <?php if ($checklistRows === []): ?>
                    <div class="p-4 text-body-secondary">
                        Data checklist tidak tersedia.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Kriteria</th>
                                    <th>Kode</th>
                                    <th>Indikator</th>
                                    <th>Bagian</th>
                                    <th>Kondisi</th>
                                    <th>Skor</th>
                                    <th>Temuan</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach (
                                    $checklistRows
                                    as $item
                                ): ?>
                                    <?php
                                    $conditionInfo =
                                        $conditionMeta(
                                            $item[
                                                'condition_level'
                                            ] !== null
                                                ? (string) $item[
                                                    'condition_level'
                                                ]
                                                : null
                                        );
                                    ?>

                                    <tr>
                                        <td>
                                            <strong>
                                                <?= e(
                                                    $item[
                                                        'criterion_code'
                                                    ]
                                                ) ?>
                                            </strong>

                                            <small class="d-block text-body-secondary">
                                                <?= e(
                                                    $item[
                                                        'criterion_name'
                                                    ]
                                                ) ?>
                                            </small>
                                        </td>

                                        <td>
                                            <?= e(
                                                $item['item_code']
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                $item['item_name']
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                $item['inspected_part']
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="badge text-bg-<?= e( $conditionInfo['class'] ) ?>">
                                                <?= e(
                                                    $conditionInfo['label']
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= e(
                                                $item['score']
                                                ?? '-'
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                $item['finding_note']
                                                ?? '-'
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

        <section class="card border-0 shadow-sm mb-4" aria-labelledby="topsis-title">
            <div class="card-header bg-white py-3">
                <h2 id="topsis-title" class="h5 mb-1">
                    Hasil TOPSIS
                </h2>

                <p class="small text-body-secondary mb-0">
                    Hasil pemeringkatan prioritas
                    pemeliharaan pada periode ini.
                </p>
            </div>

            <div class="card-body p-4">
                <?php if (!$hasTopsis): ?>
                    <div class="text-center text-body-secondary py-4">
                        Hasil TOPSIS untuk periode ini
                        belum tersedia.
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <div class="col-sm-6 col-xl">
                            <small class="text-body-secondary d-block">
                                Jarak Positif (D+)
                            </small>

                            <strong>
                                <?= e(format_number_id(
                                    $positiveDistance,
                                    6
                                )) ?>
                            </strong>
                        </div>

                        <div class="col-sm-6 col-xl">
                            <small class="text-body-secondary d-block">
                                Jarak Negatif (D−)
                            </small>

                            <strong>
                                <?= e(format_number_id(
                                    $negativeDistance,
                                    6
                                )) ?>
                            </strong>
                        </div>

                        <div class="col-sm-6 col-xl">
                            <small class="text-body-secondary d-block">
                                Nilai Preferensi
                            </small>

                            <strong>
                                <?= e(format_number_id(
                                    $preferenceValue,
                                    6
                                )) ?>
                            </strong>
                        </div>

                        <div class="col-sm-6 col-xl">
                            <small class="text-body-secondary d-block">
                                Peringkat
                            </small>

                            <strong>
                                #<?= e($ranking) ?>
                            </strong>
                        </div>

                        <div class="col-sm-6 col-xl">
                            <small class="text-body-secondary d-block">
                                Kelompok Prioritas
                            </small>

                            <span class="badge text-bg-<?= e( $rankGroupInfo['class'] ) ?>">
                                <?= e($rankGroup) ?>
                                —
                                <?= e(
                                    $assessment['priority_label']
                                    ?? $rankGroupInfo['label']
                                ) ?>
                            </span>
                        </div>

                        <div class="col-12">
                            <small class="text-body-secondary d-block">
                                Waktu Perhitungan
                            </small>

                            <strong>
                                <?= e(format_date_id(
                                    $assessment[
                                        'topsis_calculated_at'
                                    ],
                                    true
                                )) ?>
                            </strong>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card border-0 shadow-sm" aria-labelledby="maintenance-action-title">
            <div class="card-header bg-white py-3 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                <div>
                    <h2 id="maintenance-action-title" class="h5 mb-1">
                        Tindak Lanjut Pemeliharaan
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Rekomendasi Supervisor dan
                        realisasi pekerjaan Teknisi.
                    </p>
                </div>

                <span class="badge text-bg-<?= e( $actionInfo['class'] ) ?>">
                    <?= e(
                        $actionInfo['label']
                    ) ?>
                </span>
            </div>

            <div class="card-body p-4">
                <?php if (!$hasTopsis): ?>
                    <div class="text-center text-body-secondary py-4">
                        Tindak lanjut belum dapat dibuat
                        karena hasil TOPSIS belum tersedia.
                    </div>

                <?php elseif (!$actionValid): ?>
                    <div class="alert alert-danger mb-0" role="alert">
                        Data tindakan tidak dapat digunakan
                        sampai struktur tindakan ganda, pembuat,
                        pelaksana, prioritas, atau statusnya
                        diperbaiki.
                    </div>

                <?php elseif (!$hasAction): ?>
                    <div class="text-center text-body-secondary py-4">
                        Belum ada rekomendasi dan tindakan
                        pemeliharaan yang dicatat.

                    </div>

                <?php else: ?>
                    <div class="row g-4">
                        <div class="col-12">
                            <small class="text-body-secondary d-block">
                                Rekomendasi Supervisor
                            </small>

                            <strong>
                                <?= nl2br(e(
                                    $assessment['recommendation']
                                )) ?>
                            </strong>
                        </div>

                        <div class="col-sm-6 col-xl-3">
                            <small class="text-body-secondary d-block">
                                Prioritas
                            </small>

                            <span class="badge text-bg-<?= e( $priorityInfo['class'] ) ?>">
                                <?= e(
                                    $priorityInfo['label']
                                ) ?>
                            </span>
                        </div>

                        <div class="col-sm-6 col-xl-3">
                            <small class="text-body-secondary d-block">
                                Status
                            </small>

                            <span class="badge text-bg-<?= e( $actionInfo['class'] ) ?>">
                                <?= e(
                                    $actionInfo['label']
                                ) ?>
                            </span>
                        </div>

                        <div class="col-sm-6 col-xl-3">
                            <small class="text-body-secondary d-block">
                                Pelaksana
                            </small>

                            <strong>
                                <?= e(
                                    $assessment[
                                        'performed_by_name'
                                    ]
                                    ?? 'Belum ditentukan'
                                ) ?>
                            </strong>
                        </div>

                        <div class="col-sm-6 col-xl-3">
                            <small class="text-body-secondary d-block">
                                Dibuat oleh
                            </small>

                            <strong>
                                <?= e(
                                    $assessment[
                                        'action_creator_name'
                                    ]
                                    ?? '-'
                                ) ?>
                            </strong>
                        </div>

                        <div class="col-sm-6 col-xl-3">
                            <small class="text-body-secondary d-block">
                                Target
                            </small>

                            <strong>
                                <?= e(format_date_id(
                                    $assessment['target_date']
                                )) ?>
                            </strong>
                        </div>

                        <div class="col-sm-6 col-xl-3">
                            <small class="text-body-secondary d-block">
                                Tanggal Selesai
                            </small>

                            <strong>
                                <?= e(format_date_id(
                                    $assessment['completed_date']
                                )) ?>
                            </strong>
                        </div>

                        <div class="col-sm-6 col-xl-3">
                            <small class="text-body-secondary d-block">
                                Tanggal Dibuat
                            </small>

                            <strong>
                                <?= e(format_date_id(
                                    $assessment[
                                        'action_created_at'
                                    ],
                                    true
                                )) ?>
                            </strong>
                        </div>

                        <div class="col-sm-6 col-xl-3">
                            <small class="text-body-secondary d-block">
                                Terakhir Diperbarui
                            </small>

                            <strong>
                                <?= e(format_date_id(
                                    $assessment[
                                        'action_updated_at'
                                    ],
                                    true
                                )) ?>
                            </strong>
                        </div>

                        <div class="col-md-6">
                            <small class="text-body-secondary d-block">
                                Referensi Work Order
                            </small>

                            <strong>
                                <?= e(
                                    $assessment[
                                        'work_order_reference'
                                    ]
                                    ?? '-'
                                ) ?>
                            </strong>
                        </div>

                        <div class="col-12">
                            <small class="text-body-secondary d-block">
                                Tindakan yang Dilakukan
                            </small>

                            <span>
                                <?= nl2br(e(
                                    $assessment[
                                        'action_description'
                                    ]
                                    ?? '-'
                                )) ?>
                            </span>
                        </div>

                        <div class="col-12">
                            <small class="text-body-secondary d-block">
                                Catatan Tambahan
                            </small>

                            <span>
                                <?= nl2br(e(
                                    $assessment[
                                        'additional_note'
                                    ]
                                    ?? '-'
                                )) ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR . 'footer.php';

?>

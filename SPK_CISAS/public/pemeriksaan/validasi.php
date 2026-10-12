<?php

declare(strict_types=1);

/*
 * Validasi Pemeriksaan Sistem SPK PT CISAS
 * Halaman ini hanya dapat digunakan oleh Supervisor Maintenance.
 * Fungsi:
 * 1. Menampilkan identitas pemeriksaan motor.
 * 2. Menampilkan kondisi operasi dan beban motor.
 * 3. Menampilkan tiga pengukuran C1, C2, dan C3.
 * 4. Menampilkan nilai akhir C1 sampai C5.
 * 5. Menampilkan seluruh checklist C4 dan C5.
 * 6. Memeriksa kelengkapan pemeriksaan.
 * 7. Menyetujui pemeriksaan.
 * 8. Mengembalikan pemeriksaan kepada Teknisi.
 * Supervisor tidak dapat mengubah:
 * - hasil pengukuran Teknisi;
 * - hasil checklist Teknisi;
 * - nilai C1 sampai C5 secara manual.
 * Supervisor hanya menentukan keputusan validasi dan catatan review.
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
    . 'helpers'
    . DIRECTORY_SEPARATOR
    . 'validation.php';

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'helpers'
    . DIRECTORY_SEPARATOR
    . 'audit.php';

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'services'
    . DIRECTORY_SEPARATOR
    . 'AssessmentService.php';

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'services'
    . DIRECTORY_SEPARATOR
    . 'WorkflowService.php';

// Hak Akses

auth_require_role(
    'supervisor'
);

// Koneksi Database

$pdo = require dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'config'
    . DIRECTORY_SEPARATOR
    . 'database.php';

// Pastikan Akun Supervisor Masih Aktif

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun Supervisor sudah tidak aktif atau tidak ditemukan.'
    );

    redirect(
        'login.php'
    );
}

// Identitas Supervisor

$supervisorId =
    auth_id();

if (
    $supervisorId === null
    || $supervisorId < 1
) {
    flash_error(
        'Sesi Supervisor tidak valid. Silakan masuk kembali.'
    );

    redirect(
        'login.php'
    );
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

if ($assessmentId === false) {
    $notFoundMessage =
        'ID pemeriksaan tidak valid atau tidak tersedia.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '404.php';

    exit;
}

$assessmentId =
    (int) $assessmentId;

// Ambil Data Utama Pemeriksaan

$assessmentStatement =
    $pdo->prepare(
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
            m.location,
            m.status AS machine_status,

            technician.name AS inspector_name,

            validator.name AS previous_validator_name

         FROM assessments AS a

         INNER JOIN assessment_periods AS p
            ON p.id = a.period_id

         INNER JOIN machines AS m
            ON m.id = a.machine_id

         INNER JOIN users AS technician
            ON technician.id = a.inspector_id

         LEFT JOIN users AS validator
            ON validator.id = a.validated_by

         WHERE a.id = :id

         LIMIT 1"
    );

$assessmentStatement->execute([
    'id' => $assessmentId,
]);

$assessment =
    $assessmentStatement->fetch();

if (!is_array($assessment)) {
    $notFoundMessage =
        'Data pemeriksaan yang akan divalidasi tidak ditemukan.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '404.php';

    exit;
}

// Pemeriksaan Harus Menunggu Validasi

if (
    (string) (
        $assessment['status']
        ?? ''
    ) !== 'completed'
) {
    flash_warning(
        'Pemeriksaan hanya dapat divalidasi ketika '
        . 'berstatus Menunggu Validasi.'
    );

    redirect(
        'pemeriksaan/detail.php?id='
        . $assessmentId
    );
}

// Periode Harus Aktif

if (
    (string) (
        $assessment['period_status']
        ?? ''
    ) !== 'active'
) {
    flash_error(
        'Pemeriksaan tidak dapat divalidasi karena '
        . 'periode sudah tidak berstatus Aktif.'
    );

    redirect(
        'pemeriksaan/detail.php?id='
        . $assessmentId
    );
}

// Ambil Data Pengukuran C1, C2, dan C3

$measurementStatement =
    $pdo->prepare(
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
    'assessment_id' =>
        $assessmentId,
]);

$measurementData =
    $measurementStatement->fetch();

$measurement =
    is_array($measurementData)
        ? $measurementData
        : null;

// Ambil Nilai C1 sampai C5

$scoreStatement =
    $pdo->prepare(
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
    'assessment_id' =>
        $assessmentId,
]);

$scoreData =
    $scoreStatement->fetch();

$score =
    is_array($scoreData)
        ? $scoreData
        : null;

// Ambil Seluruh Checklist C4 dan C5

$checklistStatement =
    $pdo->prepare(
        "SELECT
            ci.id AS checklist_item_id,
            ci.item_code,
            ci.item_name,
            ci.inspected_part,
            ci.allows_na,
            ci.display_order,

            c.code AS criterion_code,
            c.name AS criterion_name,

            cr.condition_level,
            cr.score,
            cr.finding_note,
            cr.updated_at

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
    'assessment_id' =>
        $assessmentId,
]);

$checklistRows =
    $checklistStatement->fetchAll();

// Helper Format Angka

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

// Helper Pemeriksaan Angka Terisi

$isFilledNumber = static function (
    mixed $value,
    bool $mustBePositive = false
): bool {
    if (
        $value === null
        || $value === ''
        || !is_numeric((string) $value)
    ) {
        return false;
    }

    $number =
        floatval($value);

    if (!is_finite($number)) {
        return false;
    }

    return $mustBePositive
        ? $number > 0
        : $number >= 0;
};

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
        'class' => 'dark',
        'score' => null,
    ],
];

// Ringkasan Checklist

$checklistSummary = [
    'normal' => 0,
    'warning' => 0,
    'danger' => 0,
    'not_applicable' => 0,
    'not_checked' => 0,
];

// Kelompok Checklist

$checklistGroups = [
    'C4' => [],
    'C5' => [],
];

foreach (
    $checklistRows
    as $checklistRow
) {
    $conditionLevel = (string) (
        $checklistRow['condition_level']
        ?? 'not_checked'
    );

    if (
        !array_key_exists(
            $conditionLevel,
            $conditionConfigurations
        )
    ) {
        $conditionLevel =
            'not_checked';
    }

    $checklistSummary[
        $conditionLevel
    ]++;

    $criterionCode = (string) (
        $checklistRow['criterion_code']
        ?? ''
    );

    $checklistRow['condition_level'] =
        $conditionLevel;

    if (
        isset(
            $checklistGroups[
                $criterionCode
            ]
        )
    ) {
        $checklistGroups[
            $criterionCode
        ][] = $checklistRow;
    }
}

/*
 * Periksa Kelengkapan Data Pengukuran
 * Pemeriksaan hanya siap disetujui apabila:
 * - Suhu 1 sampai 3 tersedia.
 * - Rata-rata suhu tersedia.
 * - Arus 1 sampai 3 tersedia.
 * - Rata-rata arus tersedia.
 * - Arus nominal tersedia.
 * - Persentase arus tersedia.
 * - Frekuensi operasi tersedia.
 * - Frekuensi nominal tersedia.
 * - RPM nominal tersedia.
 * - RPM 1 sampai 3 tersedia.
 * - Rata-rata RPM tersedia.
 * - RPM acuan tersedia.
 * - Deviasi RPM tersedia.
 */

$measurementComplete =
    $measurement !== null

    && $isFilledNumber(
        $measurement['temperature_1']
        ?? null
    )

    && $isFilledNumber(
        $measurement['temperature_2']
        ?? null
    )

    && $isFilledNumber(
        $measurement['temperature_3']
        ?? null
    )

    && $isFilledNumber(
        $measurement['temperature_average']
        ?? null
    )

    && $isFilledNumber(
        $measurement['current_1']
        ?? null
    )

    && $isFilledNumber(
        $measurement['current_2']
        ?? null
    )

    && $isFilledNumber(
        $measurement['current_3']
        ?? null
    )

    && $isFilledNumber(
        $measurement['current_average']
        ?? null
    )

    && $isFilledNumber(
        $measurement['rated_current']
        ?? null,
        true
    )

    && $isFilledNumber(
        $measurement['current_percentage']
        ?? null
    )

    && $isFilledNumber(
        $measurement['operating_frequency']
        ?? null,
        true
    )

    && $isFilledNumber(
        $measurement['rated_frequency']
        ?? null,
        true
    )

    && $isFilledNumber(
        $measurement['rated_rpm']
        ?? null,
        true
    )

    && $isFilledNumber(
        $measurement['rpm_1']
        ?? null
    )

    && $isFilledNumber(
        $measurement['rpm_2']
        ?? null
    )

    && $isFilledNumber(
        $measurement['rpm_3']
        ?? null
    )

    && $isFilledNumber(
        $measurement['rpm_average']
        ?? null
    )

    && $isFilledNumber(
        $measurement['rpm_reference']
        ?? null,
        true
    )

    && $isFilledNumber(
        $measurement['rpm_deviation_percentage']
        ?? null
    );

// Periksa Kelengkapan Checklist

$checklistComplete =
    count($checklistRows) === 12

    && count(
        $checklistGroups['C4']
    ) === 6

    && count(
        $checklistGroups['C5']
    ) === 6

    && $checklistSummary[
        'not_checked'
    ] === 0;

// Periksa Kelengkapan Nilai C1 sampai C5

$scoreComplete =
    $score !== null

    && $isFilledNumber(
        $score['c1_value']
        ?? null
    )

    && $isFilledNumber(
        $score['c2_value']
        ?? null
    )

    && $isFilledNumber(
        $score['c3_value']
        ?? null
    )

    && $isFilledNumber(
        $score['c4_value']
        ?? null
    )

    && $isFilledNumber(
        $score['c5_value']
        ?? null
    )

    && (int) (
        $score['c4_not_checked_count']
        ?? 0
    ) === 0

    && (int) (
        $score['c5_not_checked_count']
        ?? 0
    ) === 0;

// Kesiapan Persetujuan

$canApprove =
    $measurementComplete
    && $checklistComplete
    && $scoreComplete;

// Konfigurasi Status Operasi

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

// Label Fungsi Motor

$motorFunctionLabel = match (
    (string) (
        $assessment['motor_function']
        ?? ''
    )
) {
    'scraper' =>
        'Scraper',

    'homogenizer' =>
        'Homogenizer',

    default =>
        'Tidak diketahui',
};

// Nama Token CSRF

$csrfFormName =
    'assessment_validation_'
    . $assessmentId;

// Proses Keputusan Supervisor

if (is_post()) {
    // Ambil Input

    $decision = input_string(
        $_POST,
        'decision',
        ''
    ) ?? '';

    $supervisorNote = input_string(
        $_POST,
        'supervisor_note',
        ''
    ) ?? '';

    // Input Lama

    $oldInput = [
        'decision' =>
            $decision,

        'supervisor_note' =>
            $supervisorNote,
    ];

    // Validasi CSRF

    if (
        !csrf_validate_request(
            $csrfFormName
        )
    ) {
        flash_set_old_input(
            $oldInput
        );

        flash_error(
            'Token keamanan tidak valid atau telah kedaluwarsa.'
        );

        redirect(
            'pemeriksaan/validasi.php?id='
            . $assessmentId
        );
    }

    // Validasi Input

    $errors = [];

    validation_add_error(
        $errors,
        'decision',
        validation_choice(
            $decision,
            'Keputusan validasi',
            [
                'approve',
                'return',
            ],
            true
        )
    );

    // Catatan Wajib ketika Dikembalikan

    if ($decision === 'return') {
        validation_add_error(
            $errors,
            'supervisor_note',
            validation_text(
                $supervisorNote,
                'Catatan Supervisor',
                5,
                1500,
                true
            )
        );
    } else {
        validation_add_error(
            $errors,
            'supervisor_note',
            validation_text(
                $supervisorNote,
                'Catatan Supervisor',
                0,
                1500,
                false
            )
        );
    }

    // Data Harus Lengkap untuk Disetujui

    if (
        $decision === 'approve'
        && !$canApprove
    ) {
        validation_add_error(
            $errors,
            'decision',
            'Pemeriksaan belum dapat disetujui karena '
            . 'pengukuran, checklist, atau nilai C1–C5 '
            . 'belum lengkap.'
        );
    }

    // Kembali apabila Validasi Gagal

    if (
        validation_has_errors(
            $errors
        )
    ) {
        flash_set_errors(
            $errors
        );

        flash_set_old_input(
            $oldInput
        );

        flash_error(
            'Keputusan validasi belum dapat disimpan.'
        );

        redirect(
            'pemeriksaan/validasi.php?id='
            . $assessmentId
        );
    }

    // Transaksi Database

    $ownsTransaction =
        !$pdo->inTransaction();

    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        // Periksa Ulang Akun Supervisor

        $supervisorStatement =
            $pdo->prepare(
                "SELECT
                    id,
                    role,
                    is_active

                 FROM users

                 WHERE id = :id

                 LIMIT 1"
            );

        $supervisorStatement->execute([
            'id' =>
                $supervisorId,
        ]);

        $latestSupervisor =
            $supervisorStatement->fetch();

        if (
            !is_array(
                $latestSupervisor
            )
            || (string) (
                $latestSupervisor['role']
                ?? ''
            ) !== 'supervisor'
            || (int) (
                $latestSupervisor['is_active']
                ?? 0
            ) !== 1
        ) {
            throw new RuntimeException(
                'Akun Supervisor sudah tidak aktif.'
            );
        }

        // Periksa Ulang Status Pemeriksaan dan Periode

        $latestStatement =
            $pdo->prepare(
                "SELECT
                    a.status,
                    p.status AS period_status

                 FROM assessments AS a

                 INNER JOIN assessment_periods AS p
                    ON p.id = a.period_id

                 WHERE a.id = :id

                 LIMIT 1"
            );

        $latestStatement->execute([
            'id' =>
                $assessmentId,
        ]);

        $latestAssessment =
            $latestStatement->fetch();

        if (!is_array($latestAssessment)) {
            throw new RuntimeException(
                'Pemeriksaan tidak ditemukan.'
            );
        }

        if (
            (string) (
                $latestAssessment['status']
                ?? ''
            ) !== 'completed'
        ) {
            throw new RuntimeException(
                'Status pemeriksaan sudah berubah.'
            );
        }

        if (
            (string) (
                $latestAssessment['period_status']
                ?? ''
            ) !== 'active'
        ) {
            throw new RuntimeException(
                'Periode pemeriksaan sudah tidak aktif.'
            );
        }

        /*
 * Hitung Ulang Nilai sebelum Persetujuan
 * Sistem menghitung ulang C1 sampai C5 menggunakan
 * AssessmentService agar nilai yang disetujui sesuai dengan
 * measurements dan checklist_results terakhir.
 */

        if ($decision === 'approve') {
            $assessmentService =
                new AssessmentService(
                    $pdo
                );

            $assessmentService
                ->recalculateAndSave(
                    $assessmentId
                );

            // Periksa Ulang Kelengkapan setelah Perhitungan

            $readinessStatement =
                $pdo->prepare(
                    "SELECT
                        (
                            SELECT COUNT(*)

                            FROM checklist_results AS cr

                            INNER JOIN checklist_items AS ci
                               ON ci.id = cr.checklist_item_id

                            INNER JOIN criteria AS c
                               ON c.id = ci.criterion_id

                            WHERE cr.assessment_id = :checklist_id

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

                            WHERE cr.assessment_id = :bp_id

                              AND ci.is_active = 1
                              AND c.is_active = 1
                              AND c.code IN (
                                  'C4',
                                  'C5'
                              )

                              AND cr.condition_level =
                                  'not_checked'
                        ) AS bp_count,

                        (
                            SELECT COUNT(*)

                            FROM measurements AS ms

                            WHERE ms.assessment_id =
                                  :measurement_id

                              AND ms.temperature_1 IS NOT NULL
                              AND ms.temperature_2 IS NOT NULL
                              AND ms.temperature_3 IS NOT NULL
                              AND ms.temperature_average IS NOT NULL

                              AND ms.current_1 IS NOT NULL
                              AND ms.current_2 IS NOT NULL
                              AND ms.current_3 IS NOT NULL
                              AND ms.current_average IS NOT NULL
                              AND ms.rated_current IS NOT NULL
                              AND ms.current_percentage IS NOT NULL

                              AND ms.operating_frequency IS NOT NULL
                              AND ms.rated_frequency IS NOT NULL
                              AND ms.rated_rpm IS NOT NULL

                              AND ms.rpm_1 IS NOT NULL
                              AND ms.rpm_2 IS NOT NULL
                              AND ms.rpm_3 IS NOT NULL
                              AND ms.rpm_average IS NOT NULL
                              AND ms.rpm_reference IS NOT NULL
                              AND ms.rpm_deviation_percentage IS NOT NULL
                        ) AS measurement_count,

                        (
                            SELECT COUNT(*)

                            FROM assessment_scores AS s

                            WHERE s.assessment_id =
                                  :score_id

                              AND s.c1_value IS NOT NULL
                              AND s.c2_value IS NOT NULL
                              AND s.c3_value IS NOT NULL
                              AND s.c4_value IS NOT NULL
                              AND s.c5_value IS NOT NULL

                              AND s.c4_not_checked_count = 0
                              AND s.c5_not_checked_count = 0
                        ) AS score_count"
                );

            $readinessStatement->execute([
                'checklist_id' =>
                    $assessmentId,

                'bp_id' =>
                    $assessmentId,

                'measurement_id' =>
                    $assessmentId,

                'score_id' =>
                    $assessmentId,
            ]);

            $latestReadiness =
                $readinessStatement->fetch();

            if (
                !is_array(
                    $latestReadiness
                )
                || (int) (
                    $latestReadiness[
                        'checklist_count'
                    ]
                    ?? 0
                ) !== 12
                || (int) (
                    $latestReadiness[
                        'bp_count'
                    ]
                    ?? 0
                ) !== 0
                || (int) (
                    $latestReadiness[
                        'measurement_count'
                    ]
                    ?? 0
                ) !== 1
                || (int) (
                    $latestReadiness[
                        'score_count'
                    ]
                    ?? 0
                ) !== 1
            ) {
                throw new RuntimeException(
                    'Data pemeriksaan belum lengkap untuk disetujui.'
                );
            }
        }

        // Tentukan Status Baru

        $newStatus =
            $decision === 'approve'
                ? 'validated'
                : 'returned';

        WorkflowService::assertTransition(
            'assessment',
            'completed',
            $newStatus
        );

        // Simpan Keputusan

        $updateStatement =
            $pdo->prepare(
                "UPDATE assessments

                 SET
                    status =
                        :status,

                    validated_by =
                        :validated_by,

                    validated_at =
                        datetime('now', '+7 hours'),

                    supervisor_note =
                        :supervisor_note,

                    updated_at =
                        datetime('now', '+7 hours')

                 WHERE id = :id
                   AND status = 'completed'"
            );

        $updateStatement->execute([
            'status' =>
                $newStatus,

            'validated_by' =>
                $supervisorId,

            'supervisor_note' =>
                $supervisorNote === ''
                    ? null
                    : $supervisorNote,

            'id' =>
                $assessmentId,
        ]);

        if (
            $updateStatement->rowCount()
            !== 1
        ) {
            throw new RuntimeException(
                'Status pemeriksaan sudah berubah.'
            );
        }

        // Catat keputusan validasi pada audit trail sebelum commit.

        audit_log(
            $pdo,
            $supervisorId,
            $decision === 'approve'
                ? 'assessment_validated'
                : 'assessment_returned',
            'assessment',
            $assessmentId,
            $decision === 'approve'
                ? 'Supervisor menyetujui pemeriksaan ' . (string) ($assessment['alternative_code'] ?? '') . '.'
                : 'Supervisor mengembalikan pemeriksaan ' . (string) ($assessment['alternative_code'] ?? '') . ' kepada Teknisi.',
            [
                'status' => 'completed',
                'supervisor_note' => $assessment['supervisor_note'] ?? null,
            ],
            [
                'status' => $newStatus,
                'supervisor_note' => $supervisorNote === '' ? null : $supervisorNote,
                'alternative_code' => $assessment['alternative_code'] ?? null,
            ]
        );

        // Commit

        if ($ownsTransaction) {
            $pdo->commit();
        }

        // Pesan Berhasil

        if ($decision === 'approve') {
            flash_success(
                'Pemeriksaan '
                . (string) (
                    $assessment[
                        'alternative_code'
                    ]
                    ?? ''
                )
                . ' berhasil disetujui.'
            );
        } else {
            flash_warning(
                'Pemeriksaan '
                . (string) (
                    $assessment[
                        'alternative_code'
                    ]
                    ?? ''
                )
                . ' dikembalikan kepada Teknisi.'
            );
        }

        redirect(
            'pemeriksaan/detail.php?id='
            . $assessmentId
        );
    } catch (Throwable $exception) {
        // Rollback

        if (
            $ownsTransaction
            && $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        // Catat Error

        error_log(
            'Validasi pemeriksaan SPK PT CISAS gagal: '
            . $exception->getMessage()
        );

        flash_set_old_input(
            $oldInput
        );

        $exceptionMessage =
            strtolower(
                $exception->getMessage()
            );

        // Status Sudah Berubah

        if (
            str_contains(
                $exceptionMessage,
                'status pemeriksaan'
            )
        ) {
            flash_error(
                'Status pemeriksaan sudah berubah. '
                . 'Muat ulang halaman sebelum melanjutkan.'
            );

            redirect(
                'pemeriksaan/detail.php?id='
                . $assessmentId
            );
        }

        // Periode Tidak Aktif

        if (
            str_contains(
                $exceptionMessage,
                'periode pemeriksaan'
            )
            && str_contains(
                $exceptionMessage,
                'tidak aktif'
            )
        ) {
            flash_error(
                'Periode pemeriksaan sudah tidak aktif.'
            );

            redirect(
                'pemeriksaan/detail.php?id='
                . $assessmentId
            );
        }

        // Akun Supervisor Tidak Aktif

        if (
            str_contains(
                $exceptionMessage,
                'akun supervisor'
            )
        ) {
            flash_error(
                'Akun Supervisor sudah tidak aktif. '
                . 'Silakan masuk kembali.'
            );

            redirect(
                'logout.php'
            );
        }

        // Data Belum Lengkap

        if (
            str_contains(
                $exceptionMessage,
                'belum lengkap'
            )
            || str_contains(
                $exceptionMessage,
                'wajib diisi'
            )
            || str_contains(
                $exceptionMessage,
                'belum diperiksa'
            )
        ) {
            flash_error(
                'Pemeriksaan belum dapat disetujui karena '
                . 'pengukuran, checklist, atau nilai C1–C5 '
                . 'belum lengkap.'
            );

            redirect(
                'pemeriksaan/validasi.php?id='
                . $assessmentId
            );
        }

        // Error Umum

        flash_error(
            'Terjadi gangguan saat menyimpan keputusan validasi. '
            . 'Silakan coba kembali.'
        );

        redirect(
            'pemeriksaan/validasi.php?id='
            . $assessmentId
        );
    }
}

// Ambil Error dan Input Lama

$errors =
    flash_get_errors();

$oldInput =
    flash_get_old_input();

$supervisorNoteValue = input_string(
    $oldInput,
    'supervisor_note',
    ''
) ?? '';

// Konfigurasi Nilai C1 sampai C5

$criteriaValues = [
    'C1' => [
        'label' =>
            'Suhu Permukaan',

        'value' =>
            $score['c1_value']
            ?? null,

        'decimals' =>
            2,

        'suffix' =>
            ' °C',
    ],

    'C2' => [
        'label' =>
            'Persentase Arus',

        'value' =>
            $score['c2_value']
            ?? null,

        'decimals' =>
            2,

        'suffix' =>
            ' %',
    ],

    'C3' => [
        'label' =>
            'Deviasi RPM',

        'value' =>
            $score['c3_value']
            ?? null,

        'decimals' =>
            2,

        'suffix' =>
            ' %',
    ],

    'C4' => [
        'label' =>
            'Kondisi Mekanikal',

        'value' =>
            $score['c4_value']
            ?? null,

        'decimals' =>
            4,

        'suffix' =>
            '',
    ],

    'C5' => [
        'label' =>
            'Kondisi Elektrikal',

        'value' =>
            $score['c5_value']
            ?? null,

        'decimals' =>
            4,

        'suffix' =>
            '',
    ],
];

// Konfigurasi Halaman

$pageTitle =
    'Validasi Pemeriksaan '
    . (string) (
        $assessment[
            'alternative_code'
        ]
        ?? ''
    );

$pageDescription =
    'Persetujuan atau pengembalian pemeriksaan motor '
    . 'oleh Supervisor Maintenance.';

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

        <!-- Judul Halaman -->
        <section class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3 mb-4">
            <div>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary text-white fw-bold" style="width: 54px; height: 54px;">
                        <?= e(
                            $assessment[
                                'alternative_code'
                            ]
                        ) ?>
                    </span>

                    <span class="badge text-bg-warning p-2">
                        Menunggu Validasi
                    </span>

                    <?php if (
                        $checklistSummary['danger']
                        > 0
                    ): ?>
                        <span class="badge text-bg-danger p-2">
                            <?= e(
                                $checklistSummary['danger']
                            ) ?>
                            Temuan Bahaya
                        </span>
                    <?php endif; ?>
                </div>

                <h1 class="page-title">
                    Validasi Pemeriksaan Motor
                </h1>

                <p class="page-description">
                    <?= e(
                        $assessment[
                            'motor_name'
                        ]
                    ) ?>

                    &middot;

                    <?= e(
                        $assessment[
                            'period_name'
                        ]
                    ) ?>
                </p>
            </div>

            <div class="page-header-actions">
                <a href="<?= e( base_url( 'pemeriksaan/index.php?period_id=' . (int) $assessment[ 'period_id' ] ) ) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>

                <a href="<?= e( base_url( 'pemeriksaan/detail.php?id=' . $assessmentId ) ) ?>" class="btn btn-outline-primary">
                    Lihat Detail
                </a>
            </div>
        </section>

        <!-- Informasi Validasi -->
        <div class="alert alert-info mb-4" role="status">
            Supervisor hanya memeriksa dan menentukan keputusan.
            Data pengukuran serta checklist tidak dapat diubah
            dari halaman ini. Catatan wajib diisi apabila
            pemeriksaan dikembalikan kepada Teknisi.
        </div>

        <!-- Kesiapan Pemeriksaan -->
        <section class="row g-3 mb-4" aria-label="Kesiapan validasi pemeriksaan">
            <?php

            $readinessItems = [
                'Pengukuran C1–C3' =>
                    $measurementComplete,

                'Checklist C4–C5' =>
                    $checklistComplete,

                'Nilai C1–C5' =>
                    $scoreComplete,
            ];

            ?>

            <?php foreach (
                $readinessItems
                as $readinessLabel => $readinessStatus
            ): ?>
                <div class="col-md-4">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                <?= e(
                                    $readinessLabel
                                ) ?>
                            </p>

                            <p class="h4 fw-semibold mb-0">
                                <?= $readinessStatus
                                    ? 'Lengkap'
                                    : 'Belum Lengkap' ?>
                            </p>

                            <span class="badge mt-2 <?= $readinessStatus ? 'text-bg-success' : 'text-bg-danger' ?>">
                                <?= $readinessStatus
                                    ? 'Siap'
                                    : 'Dikembalikan' ?>
                            </span>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </section>

        <!-- Identitas dan Kondisi Operasi -->
        <div class="row g-4 mb-4">

            <div class="col-xl-6">
                <section class="card border-0 shadow-sm h-100" aria-labelledby="assessment-identity-title">
                    <div class="card-header bg-white py-3">
                        <h2 id="assessment-identity-title" class="h5 mb-1">
                            Identitas Pemeriksaan
                        </h2>
                    </div>

                    <div class="card-body p-4">
                        <dl class="row mb-0">

                            <dt class="col-sm-5">
                                Alternatif
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $assessment[
                                        'alternative_code'
                                    ]
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Motor
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $assessment[
                                        'motor_name'
                                    ]
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Kode Mesin
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $assessment[
                                        'machine_code'
                                    ]
                                    ?? '-'
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Fungsi
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $motorFunctionLabel
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Tanggal
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    format_date_id(
                                        $assessment[
                                            'inspection_date'
                                        ]
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
                                            $assessment[
                                                'inspection_time'
                                            ]
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

                            <dd class="col-sm-7 mb-0">
                                <?= e(
                                    $assessment[
                                        'inspector_name'
                                    ]
                                ) ?>
                            </dd>

                        </dl>
                    </div>
                </section>
            </div>

            <div class="col-xl-6">
                <section class="card border-0 shadow-sm h-100" aria-labelledby="operating-condition-title">
                    <div class="card-header bg-white py-3">
                        <h2 id="operating-condition-title" class="h5 mb-1">
                            Kondisi Operasi
                        </h2>
                    </div>

                    <div class="card-body p-4">
                        <dl class="row mb-0">

                            <dt class="col-sm-5">
                                Status Operasi
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $operatingStatusLabels[
                                        (string) (
                                            $assessment[
                                                'operating_status'
                                            ]
                                            ?? ''
                                        )
                                    ]
                                    ?? '-'
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Kondisi Beban
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $loadConditionLabels[
                                        (string) (
                                            $assessment[
                                                'load_condition'
                                            ]
                                            ?? ''
                                        )
                                    ]
                                    ?? '-'
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Frekuensi Operasi
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $displayNumber(
                                        $measurement[
                                            'operating_frequency'
                                        ]
                                        ?? null,
                                        2,
                                        ' Hz'
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Lokasi
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    $assessment[
                                        'location'
                                    ]
                                    ?? '-'
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Catatan Operasi
                            </dt>

                            <dd class="col-sm-7 mb-0">
                                <?php if (
                                    trim(
                                        (string) (
                                            $assessment[
                                                'operating_notes'
                                            ]
                                            ?? ''
                                        )
                                    ) !== ''
                                ): ?>
                                    <?= nl2br(
                                        e(
                                            (string) $assessment[
                                                'operating_notes'
                                            ]
                                        )
                                    ) ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </dd>

                        </dl>
                    </div>
                </section>
            </div>

        </div>

        <!-- Nilai C1 sampai C5 -->
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="criteria-score-title">
            <div class="card-header bg-white py-3">
                <h2 id="criteria-score-title" class="h5 mb-1">
                    Nilai C1 sampai C5
                </h2>

                <p class="small text-body-secondary mb-0">
                    Nilai pemeriksaan yang akan digunakan
                    dalam proses TOPSIS setelah disetujui.
                </p>
            </div>

            <div class="card-body p-4">
                <div class="row g-3">

                    <?php foreach (
                        $criteriaValues
                        as $criterionCode => $criterionItem
                    ): ?>
                        <div class="col-6 col-lg">
                            <div class="metric-box h-100">
                                <p class="metric-label">
                                    <?= e(
                                        $criterionCode
                                    ) ?>
                                </p>

                                <p class="metric-value">
                                    <?= e(
                                        $displayNumber(
                                            $criterionItem[
                                                'value'
                                            ],
                                            $criterionItem[
                                                'decimals'
                                            ],
                                            $criterionItem[
                                                'suffix'
                                            ]
                                        )
                                    ) ?>
                                </p>

                                <small class="text-body-secondary">
                                    <?= e(
                                        $criterionItem[
                                            'label'
                                        ]
                                    ) ?>
                                </small>
                            </div>
                        </div>
                    <?php endforeach; ?>

                </div>
            </div>
        </section>

        <!-- Pengukuran C1 -->
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="temperature-measurement-title">
            <div class="card-header bg-white py-3">
                <h2 id="temperature-measurement-title" class="h5 mb-1">
                    C1 — Pengukuran Suhu Permukaan
                </h2>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle text-center mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>
                                    Pengukuran 1
                                </th>

                                <th>
                                    Pengukuran 2
                                </th>

                                <th>
                                    Pengukuran 3
                                </th>

                                <th>
                                    Rata-rata
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr>
                                <td>
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'temperature_1'
                                            ]
                                            ?? null,
                                            2,
                                            ' °C'
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'temperature_2'
                                            ]
                                            ?? null,
                                            2,
                                            ' °C'
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'temperature_3'
                                            ]
                                            ?? null,
                                            2,
                                            ' °C'
                                        )
                                    ) ?>
                                </td>

                                <td class="fw-semibold">
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'temperature_average'
                                            ]
                                            ?? null,
                                            2,
                                            ' °C'
                                        )
                                    ) ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Pengukuran C2 -->
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="current-measurement-title">
            <div class="card-header bg-white py-3">
                <h2 id="current-measurement-title" class="h5 mb-1">
                    C2 — Pengukuran Arus Motor
                </h2>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle text-center mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>
                                    Arus 1
                                </th>

                                <th>
                                    Arus 2
                                </th>

                                <th>
                                    Arus 3
                                </th>

                                <th>
                                    Rata-rata
                                </th>

                                <th>
                                    Arus Nominal
                                </th>

                                <th>
                                    Persentase
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr>
                                <td>
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'current_1'
                                            ]
                                            ?? null,
                                            2,
                                            ' A'
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'current_2'
                                            ]
                                            ?? null,
                                            2,
                                            ' A'
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'current_3'
                                            ]
                                            ?? null,
                                            2,
                                            ' A'
                                        )
                                    ) ?>
                                </td>

                                <td class="fw-semibold">
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'current_average'
                                            ]
                                            ?? null,
                                            2,
                                            ' A'
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'rated_current'
                                            ]
                                            ?? null,
                                            2,
                                            ' A'
                                        )
                                    ) ?>
                                </td>

                                <td class="fw-semibold">
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'current_percentage'
                                            ]
                                            ?? null,
                                            2,
                                            ' %'
                                        )
                                    ) ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Pengukuran C3 -->
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="rpm-measurement-title">
            <div class="card-header bg-white py-3">
                <h2 id="rpm-measurement-title" class="h5 mb-1">
                    C3 — Pengukuran Kecepatan Putaran
                </h2>

                <p class="small text-body-secondary mb-0">
                    RPM acuan dihitung dari RPM nominal yang
                    disesuaikan dengan frekuensi operasi.
                </p>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle text-center mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>
                                    RPM 1
                                </th>

                                <th>
                                    RPM 2
                                </th>

                                <th>
                                    RPM 3
                                </th>

                                <th>
                                    Rata-rata
                                </th>

                                <th>
                                    RPM Nominal
                                </th>

                                <th>
                                    Frekuensi Nominal
                                </th>

                                <th>
                                    Frekuensi Operasi
                                </th>

                                <th>
                                    RPM Acuan
                                </th>

                                <th>
                                    Deviasi
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr>
                                <td>
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'rpm_1'
                                            ]
                                            ?? null,
                                            2,
                                            ' RPM'
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'rpm_2'
                                            ]
                                            ?? null,
                                            2,
                                            ' RPM'
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'rpm_3'
                                            ]
                                            ?? null,
                                            2,
                                            ' RPM'
                                        )
                                    ) ?>
                                </td>

                                <td class="fw-semibold">
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'rpm_average'
                                            ]
                                            ?? null,
                                            2,
                                            ' RPM'
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'rated_rpm'
                                            ]
                                            ?? null,
                                            2,
                                            ' RPM'
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'rated_frequency'
                                            ]
                                            ?? null,
                                            2,
                                            ' Hz'
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'operating_frequency'
                                            ]
                                            ?? null,
                                            2,
                                            ' Hz'
                                        )
                                    ) ?>
                                </td>

                                <td class="fw-semibold">
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'rpm_reference'
                                            ]
                                            ?? null,
                                            2,
                                            ' RPM'
                                        )
                                    ) ?>
                                </td>

                                <td class="fw-semibold">
                                    <?= e(
                                        $displayNumber(
                                            $measurement[
                                                'rpm_deviation_percentage'
                                            ]
                                            ?? null,
                                            2,
                                            ' %'
                                        )
                                    ) ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Checklist C4 dan C5 -->
        <?php foreach (
            $checklistGroups
            as $criterionCode => $criterionChecklistRows
        ): ?>
            <?php

            $criterionTitle =
                $criterionCode === 'C4'
                    ? 'Kondisi Mekanikal'
                    : 'Kondisi Elektrikal';

            ?>

            <section class="card border-0 shadow-sm mb-4" aria-labelledby="<?= e( strtolower( $criterionCode ) ) ?>-checklist-title">
                <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div>
                        <h2 id="<?= e( strtolower( $criterionCode ) ) ?>-checklist-title" class="h5 mb-1">
                            <?= e(
                                $criterionCode
                            ) ?>
                            —
                            <?= e(
                                $criterionTitle
                            ) ?>
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Seluruh hasil checklist yang diisi Teknisi.
                        </p>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <?php if (
                            $criterionCode === 'C4'
                        ): ?>
                            <span class="badge text-bg-primary">
                                Nilai:
                                <?= e(
                                    $displayNumber(
                                        $score[
                                            'c4_value'
                                        ]
                                        ?? null,
                                        4
                                    )
                                ) ?>
                            </span>
                        <?php else: ?>
                            <span class="badge text-bg-primary">
                                Nilai:
                                <?= e(
                                    $displayNumber(
                                        $score[
                                            'c5_value'
                                        ]
                                        ?? null,
                                        4
                                    )
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
                                    <th>
                                        Kode
                                    </th>

                                    <th>
                                        Indikator
                                    </th>

                                    <th>
                                        Bagian Diperiksa
                                    </th>

                                    <th>
                                        Kondisi
                                    </th>

                                    <th class="text-center">
                                        Skor
                                    </th>

                                    <th>
                                        Keterangan Temuan
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach (
                                    $criterionChecklistRows
                                    as $checklistRow
                                ): ?>
                                    <?php

                                    $conditionLevel = (string) (
                                        $checklistRow[
                                            'condition_level'
                                        ]
                                        ?? 'not_checked'
                                    );

                                    $conditionConfiguration =
                                        $conditionConfigurations[
                                            $conditionLevel
                                        ]
                                        ?? $conditionConfigurations[
                                            'not_checked'
                                        ];

                                    $findingNote = trim(
                                        (string) (
                                            $checklistRow[
                                                'finding_note'
                                            ]
                                            ?? ''
                                        )
                                    );

                                    ?>

                                    <tr>
                                        <td>
                                            <strong>
                                                <?= e(
                                                    $checklistRow[
                                                        'item_code'
                                                    ]
                                                    ?? '-'
                                                ) ?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?= e(
                                                $checklistRow[
                                                    'item_name'
                                                ]
                                                ?? '-'
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                $checklistRow[
                                                    'inspected_part'
                                                ]
                                                ?? '-'
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="badge text-bg-<?= e( $conditionConfiguration[ 'class' ] ) ?>">
                                                <?= e(
                                                    $conditionConfiguration[
                                                        'label'
                                                    ]
                                                ) ?>
                                            </span>
                                        </td>

                                        <td class="text-center">
                                            <?= e(
                                                $checklistRow[
                                                    'score'
                                                ]
                                                ?? '-'
                                            ) ?>
                                        </td>

                                        <td>
                                            <?php if (
                                                $findingNote !== ''
                                            ): ?>
                                                <?= nl2br(
                                                    e(
                                                        $findingNote
                                                    )
                                                ) ?>
                                            <?php else: ?>
                                                <span class="text-body-secondary">
                                                    -
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>

        <!-- Catatan Review Sebelumnya -->
        <?php if (
            trim(
                (string) (
                    $assessment[
                        'supervisor_note'
                    ]
                    ?? ''
                )
            ) !== ''
        ): ?>
            <div class="alert alert-warning mb-4" role="status">
                <strong class="d-block mb-1">
                    Catatan Review Sebelumnya
                </strong>

                <?= nl2br(
                    e(
                        (string) $assessment[
                            'supervisor_note'
                        ]
                    )
                ) ?>

                <?php if (
                    trim(
                        (string) (
                            $assessment[
                                'previous_validator_name'
                            ]
                            ?? ''
                        )
                    ) !== ''
                ): ?>
                    <small class="d-block mt-2">
                        Oleh

                        <?= e(
                            $assessment[
                                'previous_validator_name'
                            ]
                        ) ?>

                        <?php if (
                            (
                                $assessment[
                                    'validated_at'
                                ]
                                ?? null
                            ) !== null
                        ): ?>
                            pada

                            <?= e(
                                format_date_id(
                                    $assessment[
                                        'validated_at'
                                    ],
                                    true
                                )
                            ) ?>
                        <?php endif; ?>
                    </small>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Keputusan Supervisor -->
        <section class="card border-0 shadow-sm" aria-labelledby="supervisor-decision-title">
            <div class="card-header bg-white py-3">
                <h2 id="supervisor-decision-title" class="h5 mb-1">
                    Keputusan Supervisor
                </h2>

                <p class="small text-body-secondary mb-0">
                    Setujui pemeriksaan atau kembalikan
                    kepada Teknisi untuk diperbaiki.
                </p>
            </div>

            <div class="card-body p-4">
                <form method="post" action="<?= e( base_url( 'pemeriksaan/validasi.php?id=' . $assessmentId ) ) ?>">
                    <?= csrf_field(
                        $csrfFormName
                    ) ?>

                    <?php

                    $decisionError =
                        validation_first_error(
                            $errors,
                            'decision'
                        );

                    $supervisorNoteError =
                        validation_first_error(
                            $errors,
                            'supervisor_note'
                        );

                    ?>

                    <?php if (
                        $decisionError !== null
                    ): ?>
                        <div class="alert alert-danger" role="alert">
                            <?= e(
                                $decisionError
                            ) ?>
                        </div>
                    <?php endif; ?>

                    <div class="mb-4">
                        <label for="supervisor_note" class="form-label">
                            Catatan Supervisor
                        </label>

                        <textarea
                            id="supervisor_note"
                            name="supervisor_note"
                            class="form-control <?= e(
                                validation_class(
                                    $errors,
                                    'supervisor_note'
                                )
                            ) ?>"
                            rows="5"
                            maxlength="1500"
                            placeholder="Jelaskan koreksi atau catatan persetujuan."
                        ><?= e(
                            $supervisorNoteValue
                        ) ?></textarea>

                        <?php if (
                            $supervisorNoteError !== null
                        ): ?>
                            <div class="invalid-feedback">
                                <?= e(
                                    $supervisorNoteError
                                ) ?>
                            </div>
                        <?php else: ?>
                            <div class="form-text">
                                Wajib untuk pengembalian dan
                                opsional untuk persetujuan.
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!$canApprove): ?>
                        <div class="alert alert-danger" role="alert">
                            Pemeriksaan belum dapat disetujui karena
                            pengukuran, checklist, atau nilai C1–C5
                            belum lengkap.
                        </div>
                    <?php elseif (
                        $checklistSummary['danger']
                        > 0
                    ): ?>
                        <div class="alert alert-warning" role="status">
                            Temuan Bahaya tidak otomatis membuat
                            pemeriksaan ditolak. Setujui apabila data
                            hasil pemeriksaannya benar agar kondisi
                            tersebut dapat masuk dalam pemeringkatan
                            prioritas TOPSIS.
                        </div>
                    <?php endif; ?>

                    <div class="d-flex flex-column flex-sm-row justify-content-end gap-2">
                        <button type="submit" name="decision" value="return" class="btn btn-outline-danger" data-confirm="Kembalikan pemeriksaan ini kepada Teknisi?" data-loading-text="Mengembalikan...">
                            Kembalikan ke Teknisi
                        </button>

                        <button type="submit" name="decision" value="approve" class="btn btn-success" <?= !$canApprove ? 'disabled' : '' ?> data-confirm="Setujui pemeriksaan ini?" data-loading-text="Menyetujui...">
                            Setujui Pemeriksaan
                        </button>
                    </div>
                </form>
            </div>
        </section>

    </main>

</div>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

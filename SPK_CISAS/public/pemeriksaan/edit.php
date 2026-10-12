<?php

declare(strict_types=1);

/*
 * Pengisian Pemeriksaan Sistem SPK PT CISAS
 * Halaman ini hanya dapat digunakan oleh Teknisi Maintenance.
 * Fungsinya:
 * - mengubah tanggal dan waktu pemeriksaan;
 * - mengisi pengukuran C1, C2, dan C3;
 * - mengisi checklist C4 dan C5;
 * - menyimpan data sebagai Draft;
 * - mengirim data kepada Supervisor;
 * - menghitung dan menyimpan nilai C1 sampai C5.
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

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'helpers'
    . DIRECTORY_SEPARATOR . 'audit.php';

// Batasi Akses Hanya untuk Teknisi

auth_require_role('technician');

// Koneksi Database

$pdo = require dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'config'
    . DIRECTORY_SEPARATOR
    . 'database.php';

// Pastikan Akun Teknisi Masih Aktif

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun Teknisi sudah tidak aktif atau tidak ditemukan.'
    );

    redirect('login.php');
}

$currentUserId = auth_id();

if (
    $currentUserId === null
    || $currentUserId < 1
) {
    flash_error(
        'Sesi Teknisi tidak valid. Silakan masuk kembali.'
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

if ($assessmentId === false) {
    $notFoundMessage =
        'ID pemeriksaan tidak valid atau tidak tersedia.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '404.php';

    exit;
}

$assessmentId = (int) $assessmentId;

// Ambil Data Pemeriksaan

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
        a.created_at,
        a.updated_at,

        p.name AS period_name,
        p.start_date AS period_start_date,
        p.end_date AS period_end_date,
        p.status AS period_status,

        m.alternative_code,
        m.machine_code,
        m.motor_name,
        m.motor_function,
        m.rated_current,
        m.rated_frequency,
        m.rated_rpm,
        m.number_of_poles,
        m.status AS machine_status,

        u.name AS inspector_name

     FROM assessments AS a

     INNER JOIN assessment_periods AS p
        ON p.id = a.period_id

     INNER JOIN machines AS m
        ON m.id = a.machine_id

     INNER JOIN users AS u
        ON u.id = a.inspector_id

     WHERE a.id = :id

     LIMIT 1"
);

$assessmentStatement->execute([
    'id' => $assessmentId,
]);

$assessment = $assessmentStatement->fetch();

if (!is_array($assessment)) {
    $notFoundMessage =
        'Data pemeriksaan tidak ditemukan.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '404.php';

    exit;
}

// Pastikan Pemeriksaan Milik Teknisi yang Login

if (
    (int) $assessment['inspector_id']
    !== $currentUserId
) {
    flash_error(
        'Pemeriksaan hanya dapat diubah oleh Teknisi yang membuatnya.'
    );

    redirect('403.php');
}

// Periksa Status Pemeriksaan

$currentAssessmentStatus = (string) (
    $assessment['status']
    ?? 'draft'
);

if (
    !in_array(
        $currentAssessmentStatus,
        [
            'draft',
            'returned',
        ],
        true
    )
) {
    flash_warning(
        'Pemeriksaan tidak dapat diubah karena sudah dikirim, '
        . 'divalidasi, atau diproses TOPSIS.'
    );

    redirect(
        'pemeriksaan/detail.php?id='
        . $assessmentId
    );
}

// Pastikan Periode Masih Aktif

if (
    (string) $assessment['period_status']
    !== 'active'
) {
    flash_error(
        'Pemeriksaan tidak dapat diubah karena periode '
        . 'sudah tidak berstatus Aktif.'
    );

    redirect(
        'pemeriksaan/detail.php?id='
        . $assessmentId
    );
}

// Ambil Pengukuran yang Sudah Tersimpan

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
        rpm_deviation_percentage

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
    : [];

// Ambil 12 Checklist C4 dan C5

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

$checklistItems =
    $checklistStatement->fetchAll();

if (count($checklistItems) !== 12) {
    flash_error(
        'Daftar checklist C4 dan C5 belum lengkap. '
        . 'Pemeriksaan tidak dapat dilanjutkan.'
    );

    redirect(
        'pemeriksaan/detail.php?id='
        . $assessmentId
    );
}

// Konfigurasi Kondisi Checklist

$conditionConfigurations = [
    'normal' => [
        'label' => 'Normal',
        'short_label' => 'Normal',
        'class' => 'condition-normal',
        'score' => 1,
        'description' => 'Tidak ditemukan masalah.',
    ],

    'warning' => [
        'label' => 'Perlu Perhatian',
        'short_label' => 'Perlu Perhatian',
        'class' => 'condition-warning',
        'score' => 2,
        'description' => 'Ada gejala yang perlu dipantau.',
    ],

    'danger' => [
        'label' => 'Bahaya',
        'short_label' => 'Bahaya',
        'class' => 'condition-danger',
        'score' => 3,
        'description' => 'Memerlukan penanganan segera.',
    ],

    'not_applicable' => [
        'label' => 'Tidak Tersedia',
        'short_label' => 'Tidak Tersedia',
        'class' => 'condition-na',
        'score' => null,
        'description' => 'Komponen tidak tersedia pada motor ini.',
    ],

    'not_checked' => [
        'label' => 'Belum diisi',
        'short_label' => 'Belum diisi',
        'class' => 'condition-not-checked',
        'score' => null,
        'description' => 'Bagian ini belum diisi.',
    ],
];

// Validasi Tanggal

$validateDate = static function (
    string $value,
    string $label
): ?string {
    $value = trim($value);

    if ($value === '') {
        return $label . ' wajib diisi.';
    }

    try {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value
        );
    } catch (ValueError) {
        return $label . ' tidak valid.';
    }

    $dateErrors =
        DateTimeImmutable::getLastErrors();

    $hasErrors = is_array($dateErrors)
        && (
            (int) (
                $dateErrors['warning_count']
                ?? 0
            ) > 0

            || (int) (
                $dateErrors['error_count']
                ?? 0
            ) > 0
        );

    if (
        $date === false
        || $hasErrors
        || $date->format('Y-m-d') !== $value
    ) {
        return $label . ' tidak valid.';
    }

    return null;
};

// Validasi Waktu

$validateTime = static function (
    string $value,
    string $label
): ?string {
    $value = trim($value);

    if ($value === '') {
        return $label . ' wajib diisi.';
    }

    if (
        preg_match(
            '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
            $value
        ) !== 1
    ) {
        return $label . ' tidak valid.';
    }

    return null;
};

/*
 * Normalisasi dan Validasi Angka
 * Hasil:
 * [
 * 'value' => float|null,
 * 'error' => string|null,
 * ]
 */

$parseDecimal = static function (
    mixed $value,
    string $label,
    bool $required,
    float $minimum,
    ?float $maximum = null
): array {
    if (
        $value === null
        || (
            is_string($value)
            && trim($value) === ''
        )
    ) {
        return [
            'value' => null,

            'error' => $required
                ? $label . ' wajib diisi.'
                : null,
        ];
    }

    if (
        !is_scalar($value)
        && !$value instanceof Stringable
    ) {
        return [
            'value' => null,
            'error' => $label . ' tidak valid.',
        ];
    }

    $normalized = str_replace(
        ',',
        '.',
        trim(
            (string) $value
        )
    );

    if (!is_numeric($normalized)) {
        return [
            'value' => null,
            'error' => $label . ' harus berupa angka.',
        ];
    }

    $number = floatval($normalized);

    if (!is_finite($number)) {
        return [
            'value' => null,
            'error' => $label . ' tidak valid.',
        ];
    }

    if ($number < $minimum) {
        return [
            'value' => null,

            'error' =>
                $label
                . ' minimal '
                . format_number_id(
                    $minimum,
                    2
                )
                . '.',
        ];
    }

    if (
        $maximum !== null
        && $number > $maximum
    ) {
        return [
            'value' => null,

            'error' =>
                $label
                . ' maksimal '
                . format_number_id(
                    $maximum,
                    2
                )
                . '.',
        ];
    }

    return [
        'value' => $number,
        'error' => null,
    ];
};

// Mengambil Nilai POST dalam Bentuk String

$postString = static function (
    array $source,
    string $key,
    string $default = ''
): string {
    $value = $source[$key]
        ?? $default;

    if (
        !is_scalar($value)
        && !$value instanceof Stringable
    ) {
        return $default;
    }

    return trim(
        (string) $value
    );
};

// Nama Token CSRF

$csrfFormName =
    'assessment_edit_' . $assessmentId;

// Proses Penyimpanan Pemeriksaan

if (is_post()) {
    // Tentukan Aksi

    $formAction = input_string(
        $_POST,
        'form_action',
        'save_draft'
    ) ?? 'save_draft';

    $allowedFormActions = [
        'save_draft',
        'submit',
    ];

    if (
        !in_array(
            $formAction,
            $allowedFormActions,
            true
        )
    ) {
        $formAction = 'save_draft';
    }

    $isSubmitting =
        $formAction === 'submit';

    // Ambil Input Umum

    $inspectionDate = input_string(
        $_POST,
        'inspection_date',
        ''
    ) ?? '';

    $inspectionTime = input_string(
        $_POST,
        'inspection_time',
        ''
    ) ?? '';

    $operatingStatus = input_string(
        $_POST,
        'operating_status',
        ''
    ) ?? '';

    $loadCondition = input_string(
        $_POST,
        'load_condition',
        ''
    ) ?? '';

    $operatingNotes = input_string(
        $_POST,
        'operating_notes',
        ''
    ) ?? '';

    $rawMeasurementInput = [
        'temperature_1' =>
            $postString(
                $_POST,
                'temperature_1'
            ),

        'temperature_2' =>
            $postString(
                $_POST,
                'temperature_2'
            ),

        'temperature_3' =>
            $postString(
                $_POST,
                'temperature_3'
            ),

        'current_1' =>
            $postString(
                $_POST,
                'current_1'
            ),

        'current_2' =>
            $postString(
                $_POST,
                'current_2'
            ),

        'current_3' =>
            $postString(
                $_POST,
                'current_3'
            ),

        'operating_frequency' =>
            $postString(
                $_POST,
                'operating_frequency'
            ),

        'rpm_1' =>
            $postString(
                $_POST,
                'rpm_1'
            ),

        'rpm_2' =>
            $postString(
                $_POST,
                'rpm_2'
            ),

        'rpm_3' =>
            $postString(
                $_POST,
                'rpm_3'
            ),
    ];

    $submittedChecklist =
        $_POST['checklist']
        ?? [];

    if (!is_array($submittedChecklist)) {
        $submittedChecklist = [];
    }

    // Periksa Token CSRF

    if (
        !csrf_validate_request(
            $csrfFormName
        )
    ) {
        flash_error(
            'Token keamanan tidak valid atau telah kedaluwarsa. '
            . 'Silakan isi kembali formulir.'
        );

        flash_set_old_input([
            'inspection_date' =>
                $inspectionDate,

            'inspection_time' =>
                $inspectionTime,

            'operating_status' =>
                $operatingStatus,

            'load_condition' =>
                $loadCondition,

            'operating_notes' =>
                $operatingNotes,

            ...$rawMeasurementInput,

            'checklist' =>
                $submittedChecklist,
        ]);

        redirect(
            'pemeriksaan/edit.php?id='
            . $assessmentId
        );
    }

    // Validasi Tanggal dan Waktu

    $errors = [];

    validation_add_error(
        $errors,
        'inspection_date',
        $validateDate(
            $inspectionDate,
            'Tanggal pemeriksaan'
        )
    );

    validation_add_error(
        $errors,
        'inspection_time',
        $validateTime(
            $inspectionTime,
            'Waktu pemeriksaan'
        )
    );

    if (
        validation_first_error(
            $errors,
            'inspection_date'
        ) === null

        && (
            $inspectionDate
                < (string) $assessment[
                    'period_start_date'
                ]

            || $inspectionDate
                > (string) $assessment[
                    'period_end_date'
                ]
        )
    ) {
        validation_add_error(
            $errors,
            'inspection_date',
            'Tanggal pemeriksaan harus berada '
            . 'dalam rentang periode.'
        );
    }

    // Validasi Kondisi Operasi

    if (
        !in_array(
            $operatingStatus,
            [
                'running',
                'stopped',
            ],
            true
        )
    ) {
        validation_add_error(
            $errors,
            'operating_status',
            'Status operasi motor wajib dipilih.'
        );
    }

    if (
        !in_array(
            $loadCondition,
            [
                'no_load',
                'light',
                'normal',
                'high',
            ],
            true
        )
    ) {
        validation_add_error(
            $errors,
            'load_condition',
            'Kondisi beban motor wajib dipilih.'
        );
    }

    if (
        $operatingStatus === 'stopped'
        && $loadCondition !== 'no_load'
    ) {
        validation_add_error(
            $errors,
            'load_condition',
            'Motor yang berhenti harus menggunakan '
            . 'kondisi Tanpa Beban.'
        );
    }

    if (
        $isSubmitting
        && $operatingStatus !== 'running'
    ) {
        validation_add_error(
            $errors,
            'operating_status',
            'Pemeriksaan hanya dapat dikirim ketika motor '
            . 'beroperasi karena pengukuran arus dan RPM '
            . 'membutuhkan motor dalam keadaan berjalan.'
        );
    }

    validation_add_error(
        $errors,
        'operating_notes',
        validation_text(
            $operatingNotes,
            'Catatan operasi',
            0,
            1000,
            false
        )
    );

    /*
 * Validasi Pengukuran
 * Pada Simpan Draft, kolom boleh kosong.
 * Pada Kirim ke Supervisor, seluruh pengukuran wajib diisi.
 */

    $measurementRules = [
        'temperature_1' => [
            'label' => 'Suhu pengukuran 1',
            'minimum' => 0.0,
            'maximum' => 250.0,
        ],

        'temperature_2' => [
            'label' => 'Suhu pengukuran 2',
            'minimum' => 0.0,
            'maximum' => 250.0,
        ],

        'temperature_3' => [
            'label' => 'Suhu pengukuran 3',
            'minimum' => 0.0,
            'maximum' => 250.0,
        ],

        'current_1' => [
            'label' => 'Arus pengukuran 1',
            'minimum' => 0.0,
            'maximum' => 10000.0,
        ],

        'current_2' => [
            'label' => 'Arus pengukuran 2',
            'minimum' => 0.0,
            'maximum' => 10000.0,
        ],

        'current_3' => [
            'label' => 'Arus pengukuran 3',
            'minimum' => 0.0,
            'maximum' => 10000.0,
        ],

        'operating_frequency' => [
            'label' => 'Frekuensi operasi',
            'minimum' => 30.0,
            'maximum' => 50.0,
        ],

        'rpm_1' => [
            'label' => 'RPM pengukuran 1',
            'minimum' => 0.0,
            'maximum' => 100000.0,
        ],

        'rpm_2' => [
            'label' => 'RPM pengukuran 2',
            'minimum' => 0.0,
            'maximum' => 100000.0,
        ],

        'rpm_3' => [
            'label' => 'RPM pengukuran 3',
            'minimum' => 0.0,
            'maximum' => 100000.0,
        ],
    ];

    $normalizedMeasurement = [];

    foreach (
        $measurementRules
        as $fieldName => $rule
    ) {
        $parsedValue = $parseDecimal(
            $rawMeasurementInput[
                $fieldName
            ],
            $rule['label'],
            $isSubmitting,
            $rule['minimum'],
            $rule['maximum']
        );

        $normalizedMeasurement[
            $fieldName
        ] = $parsedValue['value'];

        validation_add_error(
            $errors,
            $fieldName,
            $parsedValue['error']
        );
    }

    // Validasi Data Acuan Nameplate

    $ratedCurrentReference =
        $assessment['rated_current']
        !== null
        && $assessment['rated_current'] !== ''
            ? floatval(
                $assessment['rated_current']
            )
            : null;

    $ratedFrequencyReference =
        $assessment['rated_frequency']
        !== null
        && $assessment['rated_frequency'] !== ''
            ? floatval(
                $assessment['rated_frequency']
            )
            : null;

    $ratedRpmReference =
        $assessment['rated_rpm']
        !== null
        && $assessment['rated_rpm'] !== ''
            ? floatval(
                $assessment['rated_rpm']
            )
            : null;

    if (
        $isSubmitting
        && (
            $ratedCurrentReference === null
            || $ratedCurrentReference <= 0
        )
    ) {
        validation_add_error(
            $errors,
            'rated_current_reference',
            'Arus nominal motor belum tersedia. '
            . 'Hubungi Administrator untuk melengkapi nameplate.'
        );
    }

    if (
        $isSubmitting
        && (
            $ratedFrequencyReference === null
            || $ratedFrequencyReference <= 0

            || $ratedRpmReference === null
            || $ratedRpmReference <= 0
        )
    ) {
        validation_add_error(
            $errors,
            'rpm_reference',
            'Frekuensi nominal atau RPM nominal '
            . 'motor belum lengkap. Hubungi Administrator.'
        );
    }

    /*
 * Hitung RPM Acuan Operasional
 * RPM acuan menyesuaikan RPM nameplate terhadap frekuensi operasi:
 * RPM acuan =
 * RPM nominal × frekuensi operasi / frekuensi nominal
 */

    $rpmReference = null;

    if (
        $ratedFrequencyReference !== null
        && $ratedFrequencyReference > 0

        && $ratedRpmReference !== null
        && $ratedRpmReference > 0

        && $normalizedMeasurement[
            'operating_frequency'
        ] !== null
    ) {
        $rpmReference = round(
            $ratedRpmReference
            * (
                $normalizedMeasurement[
                    'operating_frequency'
                ]
                / $ratedFrequencyReference
            ),
            4
        );
    }

    /*
 * Hitung Nilai Turunan Pengukuran
 * Nilai turunan tetap disimpan di tabel measurements agar laporan,
 * detail pemeriksaan, dan perhitungan kriteria menggunakan sumber data
 * yang sama.
 */

    $assessmentService =
        new AssessmentService(
            $pdo
        );

    $temperatureAverage = null;
    $currentAverage = null;
    $currentPercentage = null;
    $rpmAverage = null;
    $rpmDeviationPercentage = null;

    $temperatureValues = [
        $normalizedMeasurement['temperature_1'],
        $normalizedMeasurement['temperature_2'],
        $normalizedMeasurement['temperature_3'],
    ];

    if (
        count(
            array_filter(
                $temperatureValues,
                static fn (mixed $value): bool =>
                    $value !== null
            )
        ) === 3
    ) {
        $temperatureAverage =
            $assessmentService
                ->calculateAverage(
                    $temperatureValues
                );
    }

    $currentValues = [
        $normalizedMeasurement['current_1'],
        $normalizedMeasurement['current_2'],
        $normalizedMeasurement['current_3'],
    ];

    if (
        count(
            array_filter(
                $currentValues,
                static fn (mixed $value): bool =>
                    $value !== null
            )
        ) === 3
    ) {
        $currentAverage =
            $assessmentService
                ->calculateAverage(
                    $currentValues
                );

        if (
            $ratedCurrentReference !== null
            && $ratedCurrentReference > 0
        ) {
            $currentPercentage =
                $assessmentService
                    ->calculateCurrentPercentage(
                        $currentAverage,
                        $ratedCurrentReference
                    );
        }
    }

    $rpmValues = [
        $normalizedMeasurement['rpm_1'],
        $normalizedMeasurement['rpm_2'],
        $normalizedMeasurement['rpm_3'],
    ];

    if (
        count(
            array_filter(
                $rpmValues,
                static fn (mixed $value): bool =>
                    $value !== null
            )
        ) === 3
    ) {
        $rpmAverage =
            $assessmentService
                ->calculateAverage(
                    $rpmValues
                );

        if (
            $rpmReference !== null
            && $rpmReference > 0
        ) {
            $rpmDeviationPercentage =
                $assessmentService
                    ->calculateRpmDeviation(
                        $rpmAverage,
                        $rpmReference
                    );
        }
    }

    // Validasi Checklist

    $normalizedChecklist = [];

    foreach (
        $checklistItems
        as $checklistItem
    ) {
        $checklistItemId = (int) (
            $checklistItem[
                'checklist_item_id'
            ] ?? 0
        );

        $submittedItem =
            $submittedChecklist[
                $checklistItemId
            ]
            ?? [];

        if (!is_array($submittedItem)) {
            $submittedItem = [];
        }

        $conditionLevel = input_string(
            $submittedItem,
            'condition_level',
            'not_checked'
        ) ?? 'not_checked';

        $findingNote = input_string(
            $submittedItem,
            'finding_note',
            ''
        ) ?? '';

        $conditionErrorKey =
            'checklist_'
            . $checklistItemId
            . '_condition';

        $noteErrorKey =
            'checklist_'
            . $checklistItemId
            . '_note';

        // Validasi Pilihan Kondisi

        if (
            !array_key_exists(
                $conditionLevel,
                $conditionConfigurations
            )
        ) {
            validation_add_error(
                $errors,
                $conditionErrorKey,
                'Kondisi checklist tidak valid.'
            );

            $conditionLevel =
                'not_checked';
        }

        // Pilihan Tidak Tersedia Hanya untuk Bagian yang Mendukung

        if (
            $conditionLevel
            === 'not_applicable'

            && (int) (
                $checklistItem[
                    'allows_na'
                ] ?? 0
            ) !== 1
        ) {
            validation_add_error(
                $errors,
                $conditionErrorKey,
                'Pilihan Tidak Tersedia tidak berlaku untuk bagian ini.'
            );
        }

        // Catatan Wajib untuk Perlu Perhatian dan Bahaya

        if (
            in_array(
                $conditionLevel,
                [
                    'warning',
                    'danger',
                ],
                true
            )

            && $findingNote === ''
        ) {
            validation_add_error(
                $errors,
                $noteErrorKey,
                'Keterangan wajib diisi untuk kondisi '
                . (
                    $conditionLevel === 'danger'
                        ? 'Bahaya'
                        : 'Perlu Perhatian'
                )
                . '.'
            );
        }

        // Bagian Kosong Tidak Boleh Dikirim ke Supervisor

        if (
            $isSubmitting
            && $conditionLevel
                === 'not_checked'
        ) {
            validation_add_error(
                $errors,
                $conditionErrorKey,
                'Bagian ini belum diisi. '
                . 'Pilih kondisi sebelum mengirim pemeriksaan.'
            );
        }

        $conditionScore =
            $conditionConfigurations[
                $conditionLevel
            ]['score'];

        $normalizedChecklist[] = [
            'checklist_item_id' =>
                $checklistItemId,

            'condition_level' =>
                $conditionLevel,

            'score' =>
                $conditionScore,

            'finding_note' =>
                $findingNote === ''
                    ? null
                    : $findingNote,
        ];
    }

    if (
        count($normalizedChecklist)
        !== 12
    ) {
        validation_add_error(
            $errors,
            'checklist',
            'Pemeriksaan harus mempunyai '
            . '12 hasil checklist.'
        );
    }

    // Kembali ke Form jika Validasi Gagal

    if (validation_has_errors($errors)) {
        flash_set_errors($errors);

        flash_set_old_input([
            'inspection_date' =>
                $inspectionDate,

            'inspection_time' =>
                $inspectionTime,

            'operating_status' =>
                $operatingStatus,

            'load_condition' =>
                $loadCondition,

            'operating_notes' =>
                $operatingNotes,

            ...$rawMeasurementInput,

            'checklist' =>
                $submittedChecklist,
        ]);

        flash_error(
            $isSubmitting
                ? 'Pemeriksaan belum dapat dikirim. '
                    . 'Lengkapi seluruh pengukuran dan checklist.'
                : 'Data Draft belum dapat disimpan. '
                    . 'Periksa kembali nilai yang diisi.'
        );

        redirect(
            'pemeriksaan/edit.php?id='
            . $assessmentId
        );
    }

    // Simpan dalam Satu Transaksi

    $ownsTransaction =
        !$pdo->inTransaction();

    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        // Periksa Ulang Status Pemeriksaan

        $latestAssessmentStatement =
            $pdo->prepare(
                "SELECT
                    a.id,
                    a.inspector_id,
                    a.status,
                    p.status AS period_status

                 FROM assessments AS a

                 INNER JOIN assessment_periods AS p
                    ON p.id = a.period_id

                 WHERE a.id = :id

                 LIMIT 1"
            );

        $latestAssessmentStatement->execute([
            'id' => $assessmentId,
        ]);

        $latestAssessment =
            $latestAssessmentStatement->fetch();

        if (!is_array($latestAssessment)) {
            throw new RuntimeException(
                'Pemeriksaan tidak ditemukan.'
            );
        }

        if (
            (int) $latestAssessment[
                'inspector_id'
            ] !== $currentUserId
        ) {
            throw new RuntimeException(
                'Pemeriksaan bukan milik Teknisi yang login.'
            );
        }

        if (
            !in_array(
                (string) $latestAssessment[
                    'status'
                ],
                [
                    'draft',
                    'returned',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Status pemeriksaan sudah berubah '
                . 'dan tidak dapat diedit.'
            );
        }

        if (
            (string) $latestAssessment[
                'period_status'
            ] !== 'active'
        ) {
            throw new RuntimeException(
                'Periode pemeriksaan sudah tidak aktif.'
            );
        }

        /*
 * Perbarui Informasi Pemeriksaan
 * Saat tombol Kirim digunakan, status belum diubah menjadi completed.
 * Pengukuran, checklist, nilai turunan, dan assessment_scores harus
 * disimpan terlebih dahulu agar trigger kelengkapan tidak menolak proses.
 */

        $currentAssessmentStatus = (string) $latestAssessment['status'];
        $targetAssessmentStatus = $isSubmitting
            ? 'completed'
            : 'draft';

        WorkflowService::assertTransition(
            'assessment',
            $currentAssessmentStatus,
            $targetAssessmentStatus
        );

        $temporaryAssessmentStatus =
            $isSubmitting
                ? $currentAssessmentStatus
                : 'draft';

        $updateAssessmentStatement =
            $pdo->prepare(
                "UPDATE assessments

                 SET
                    inspection_date =
                        :inspection_date,

                    inspection_time =
                        :inspection_time,

                    operating_status =
                        :operating_status,

                    load_condition =
                        :load_condition,

                    operating_notes =
                        :operating_notes,

                    status =
                        :status,

                    updated_at =
                        datetime('now', '+7 hours')

                 WHERE id = :id"
            );

        $updateAssessmentStatement->execute([
            'inspection_date' =>
                $inspectionDate,

            'inspection_time' =>
                $inspectionTime,

            'operating_status' =>
                $operatingStatus,

            'load_condition' =>
                $loadCondition,

            'operating_notes' =>
                $operatingNotes !== ''
                    ? $operatingNotes
                    : null,

            'status' =>
                $temporaryAssessmentStatus,

            'id' =>
                $assessmentId,
        ]);

        // Simpan atau Perbarui Pengukuran

        $saveMeasurementStatement =
            $pdo->prepare(
                "INSERT INTO measurements (
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

                 ) VALUES (
                    :assessment_id,

                    :temperature_1,
                    :temperature_2,
                    :temperature_3,
                    :temperature_average,
                    NULL,

                    :current_1,
                    :current_2,
                    :current_3,
                    :current_average,
                    :rated_current,
                    :current_percentage,

                    :operating_frequency,
                    NULL,
                    :rated_frequency,
                    :rated_rpm,

                    :rpm_1,
                    :rpm_2,
                    :rpm_3,
                    :rpm_average,
                    :rpm_reference,
                    'frequency_adjusted_nameplate',
                    NULL,
                    :rpm_deviation_percentage,

                    datetime('now', '+7 hours'),
                    datetime('now', '+7 hours')
                 )

                 ON CONFLICT(assessment_id)

                 DO UPDATE SET

                    temperature_1 =
                        excluded.temperature_1,

                    temperature_2 =
                        excluded.temperature_2,

                    temperature_3 =
                        excluded.temperature_3,

                    temperature_average =
                        excluded.temperature_average,

                    current_1 =
                        excluded.current_1,

                    current_2 =
                        excluded.current_2,

                    current_3 =
                        excluded.current_3,

                    current_average =
                        excluded.current_average,

                    rated_current =
                        excluded.rated_current,

                    current_percentage =
                        excluded.current_percentage,

                    operating_frequency =
                        excluded.operating_frequency,

                    rated_frequency =
                        excluded.rated_frequency,

                    rated_rpm =
                        excluded.rated_rpm,

                    rpm_1 =
                        excluded.rpm_1,

                    rpm_2 =
                        excluded.rpm_2,

                    rpm_3 =
                        excluded.rpm_3,

                    rpm_average =
                        excluded.rpm_average,

                    rpm_reference =
                        excluded.rpm_reference,

                    rpm_reference_method =
                        excluded.rpm_reference_method,

                    rpm_deviation_percentage =
                        excluded.rpm_deviation_percentage,

                    updated_at =
                        datetime('now', '+7 hours')"
            );

        $saveMeasurementStatement->execute([
            'assessment_id' =>
                $assessmentId,

            'temperature_1' =>
                $normalizedMeasurement[
                    'temperature_1'
                ],

            'temperature_2' =>
                $normalizedMeasurement[
                    'temperature_2'
                ],

            'temperature_3' =>
                $normalizedMeasurement[
                    'temperature_3'
                ],

            'temperature_average' =>
                $temperatureAverage,

            'current_1' =>
                $normalizedMeasurement[
                    'current_1'
                ],

            'current_2' =>
                $normalizedMeasurement[
                    'current_2'
                ],

            'current_3' =>
                $normalizedMeasurement[
                    'current_3'
                ],

            'current_average' =>
                $currentAverage,

            'rated_current' =>
                $ratedCurrentReference,

            'current_percentage' =>
                $currentPercentage,

            'operating_frequency' =>
                $normalizedMeasurement[
                    'operating_frequency'
                ],

            'rated_frequency' =>
                $ratedFrequencyReference,

            'rated_rpm' =>
                $ratedRpmReference,

            'rpm_1' =>
                $normalizedMeasurement[
                    'rpm_1'
                ],

            'rpm_2' =>
                $normalizedMeasurement[
                    'rpm_2'
                ],

            'rpm_3' =>
                $normalizedMeasurement[
                    'rpm_3'
                ],

            'rpm_average' =>
                $rpmAverage,

            'rpm_reference' =>
                $rpmReference,

            'rpm_deviation_percentage' =>
                $rpmDeviationPercentage,
        ]);

        // Simpan 12 Checklist

        $saveChecklistStatement =
            $pdo->prepare(
                "INSERT INTO checklist_results (
                    assessment_id,
                    checklist_item_id,
                    condition_level,
                    score,
                    finding_note,
                    created_at,
                    updated_at

                 ) VALUES (
                    :assessment_id,
                    :checklist_item_id,
                    :condition_level,
                    :score,
                    :finding_note,
                    datetime('now', '+7 hours'),
                    datetime('now', '+7 hours')
                 )

                 ON CONFLICT(
                    assessment_id,
                    checklist_item_id
                 )

                 DO UPDATE SET
                    condition_level =
                        excluded.condition_level,

                    score =
                        excluded.score,

                    finding_note =
                        excluded.finding_note,

                    updated_at =
                        datetime('now', '+7 hours')"
            );

        foreach (
            $normalizedChecklist
            as $checklistResult
        ) {
            $saveChecklistStatement->execute([
                'assessment_id' =>
                    $assessmentId,

                'checklist_item_id' =>
                    $checklistResult[
                        'checklist_item_id'
                    ],

                'condition_level' =>
                    $checklistResult[
                        'condition_level'
                    ],

                'score' =>
                    $checklistResult[
                        'score'
                    ],

                'finding_note' =>
                    $checklistResult[
                        'finding_note'
                    ],
            ]);
        }

        // Hitung Nilai C1 sampai C5 dan Kirim Pemeriksaan

        if ($isSubmitting) {
            $assessmentService
                ->recalculateAndSave(
                    $assessmentId
                );

            /*
 * Status Diubah Paling Akhir
 * Trigger database akan memeriksa bahwa measurements,
 * checklist_results, dan assessment_scores sudah lengkap.
 */

            $submitAssessmentStatement =
                $pdo->prepare(
                    "UPDATE assessments

                     SET
                        status = 'completed',
                        validated_by = NULL,
                        validated_at = NULL,
                        updated_at = datetime('now', '+7 hours')

                     WHERE id = :id"
                );

            $submitAssessmentStatement->execute([
                'id' =>
                    $assessmentId,
            ]);
        } else {
            // Hapus Nilai Lama agar Draft Tidak Menampilkan Nilai Usang

            $deleteOldScoreStatement =
                $pdo->prepare(
                    "DELETE FROM assessment_scores
                     WHERE assessment_id =
                           :assessment_id"
                );

            $deleteOldScoreStatement->execute([
                'assessment_id' =>
                    $assessmentId,
            ]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        audit_log(
            $pdo,
            $currentUserId,
            $isSubmitting ? 'assessment_submitted' : 'assessment_updated',
            'assessment',
            $assessmentId,
            $isSubmitting
                ? 'Pemeriksaan dikirim kepada Supervisor Maintenance untuk validasi.'
                : 'Draft pemeriksaan diperbarui oleh Teknisi.',
            ['status' => $currentAssessmentStatus],
            [
                'status' => $isSubmitting ? 'completed' : 'draft',
                'alternative_code' => (string) ($assessment['alternative_code'] ?? ''),
                'inspection_date' => $inspectionDate,
            ]
        );

        if ($isSubmitting) {
            flash_success(
                'Pemeriksaan '
                . (string) $assessment[
                    'alternative_code'
                ]
                . ' berhasil dikirim kepada '
                . 'Supervisor Maintenance.'
            );

            redirect(
                'pemeriksaan/detail.php?id='
                . $assessmentId
            );
        }

        flash_success(
            'Draft pemeriksaan '
            . (string) $assessment[
                'alternative_code'
            ]
            . ' berhasil disimpan.'
        );

        redirect(
            'pemeriksaan/edit.php?id='
            . $assessmentId
        );
    } catch (Throwable $exception) {
        if (
            $ownsTransaction
            && $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        error_log(
            'Simpan pemeriksaan SPK PT CISAS gagal: '
            . $exception->getMessage()
        );

        flash_set_old_input([
            'inspection_date' =>
                $inspectionDate,

            'inspection_time' =>
                $inspectionTime,

            'operating_status' =>
                $operatingStatus,

            'load_condition' =>
                $loadCondition,

            'operating_notes' =>
                $operatingNotes,

            ...$rawMeasurementInput,

            'checklist' =>
                $submittedChecklist,
        ]);

        $exceptionMessage =
            strtolower(
                $exception->getMessage()
            );

        if (
            str_contains(
                $exceptionMessage,
                'belum diperiksa'
            )
        ) {
            flash_error(
                'Pemeriksaan belum dapat dikirim karena '
                . 'masih terdapat bagian checklist yang belum diisi.'
            );
        } elseif (
            str_contains(
                $exceptionMessage,
                'tidak aktif'
            )
        ) {
            flash_error(
                'Pemeriksaan tidak dapat disimpan karena '
                . 'periode sudah tidak aktif.'
            );
        } elseif (
            str_contains(
                $exceptionMessage,
                'status pemeriksaan'
            )
        ) {
            flash_error(
                'Status pemeriksaan sudah berubah. '
                . 'Muat ulang halaman sebelum melanjutkan.'
            );
        } else {
            flash_error(
                'Terjadi gangguan saat menyimpan pemeriksaan. '
                . 'Silakan periksa data dan coba kembali.'
            );
        }

        redirect(
            'pemeriksaan/edit.php?id='
            . $assessmentId
        );
    }
}

// Ambil Error dan Input Lama

$errors =
    flash_get_errors();

$oldInput =
    flash_get_old_input();

// Fungsi Mengambil Nilai Form

$formValue = static function (
    array $oldInput,
    array $databaseData,
    string $field,
    string $default = ''
): string {
    if (
        array_key_exists(
            $field,
            $oldInput
        )
    ) {
        $value =
            $oldInput[$field];

        return is_scalar($value)
            || $value instanceof Stringable
                ? (string) $value
                : $default;
    }

    if (
        array_key_exists(
            $field,
            $databaseData
        )

        && $databaseData[$field]
            !== null
    ) {
        return (string)
            $databaseData[$field];
    }

    return $default;
};

$inspectionDateValue =
    $formValue(
        $oldInput,
        $assessment,
        'inspection_date'
    );

$inspectionTimeValue =
    $formValue(
        $oldInput,
        $assessment,
        'inspection_time'
    );

$operatingStatusValue =
    $formValue(
        $oldInput,
        $assessment,
        'operating_status',
        'running'
    );

$loadConditionValue =
    $formValue(
        $oldInput,
        $assessment,
        'load_condition',
        'normal'
    );

$operatingNotesValue =
    $formValue(
        $oldInput,
        $assessment,
        'operating_notes'
    );

$measurementValues = [];

foreach (
    [
        'temperature_1',
        'temperature_2',
        'temperature_3',

        'current_1',
        'current_2',
        'current_3',

        'operating_frequency',

        'rpm_1',
        'rpm_2',
        'rpm_3',
    ]
    as $measurementField
) {
    $measurementValues[
        $measurementField
    ] = $formValue(
        $oldInput,
        $measurement,
        $measurementField
    );
}

// Input Lama Checklist

$oldChecklist =
    $oldInput['checklist']
    ?? [];

if (!is_array($oldChecklist)) {
    $oldChecklist = [];
}

// Kelompokkan Checklist

$checklistGroups = [
    'C4' => [],
    'C5' => [],
];

foreach (
    $checklistItems
    as $checklistItem
) {
    $criterionCode = (string) (
        $checklistItem[
            'criterion_code'
        ] ?? ''
    );

    if (
        isset(
            $checklistGroups[
                $criterionCode
            ]
        )
    ) {
        $checklistGroups[
            $criterionCode
        ][] = $checklistItem;
    }
}

// Status Pemeriksaan

$statusConfiguration =
    $currentAssessmentStatus
        === 'returned'
            ? [
                'label' =>
                    'Dikembalikan',

                'class' =>
                    'danger',

                'message' =>
                    'Pemeriksaan dikembalikan oleh Supervisor. '
                    . 'Perbaiki data, kemudian kirim kembali.',
            ]
            : [
                'label' =>
                    'Draft',

                'class' =>
                    'secondary',

                'message' =>
                    'Pemeriksaan masih berupa Draft dan '
                    . 'belum dapat divalidasi Supervisor.',
            ];

// Konfigurasi Halaman

$pageTitle =
    'Isi Pemeriksaan '
    . (string) $assessment[
        'alternative_code'
    ];

$pageDescription =
    'Pengisian pengukuran dan checklist kondisi motor '
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

        <!-- Judul -->
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

                    <span class="badge text-bg-<?= e( $statusConfiguration[ 'class' ] ) ?> p-2">
                        <?= e(
                            $statusConfiguration[
                                'label'
                            ]
                        ) ?>
                    </span>
                </div>

                <h1 class="page-title">
                    Isi Pemeriksaan Motor
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

            <div class="page-header-actions">
                <a href="<?= e( base_url( 'pemeriksaan/detail.php?id=' . $assessmentId ) ) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>
            </div>
        </section>

        <div class="alert alert-<?= e( $statusConfiguration['class'] ) ?> mb-4" role="status">
            <?= e(
                $statusConfiguration['message']
            ) ?>
        </div>

        <?php
        $referenceErrors = array_filter([
            validation_first_error(
                $errors,
                'rated_current_reference'
            ),

            validation_first_error(
                $errors,
                'rpm_reference'
            ),

            validation_first_error(
                $errors,
                'checklist'
            ),
        ]);
        ?>

        <?php if ($referenceErrors !== []): ?>
            <div class="alert alert-danger mb-4" role="alert">
                <strong class="d-block mb-2">
                    Pemeriksaan belum lengkap
                </strong>

                <ul class="mb-0">
                    <?php foreach (
                        $referenceErrors
                        as $referenceError
                    ): ?>
                        <li>
                            <?= e(
                                $referenceError
                            ) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e( base_url( 'pemeriksaan/edit.php?id=' . $assessmentId ) ) ?>">
            <?= csrf_field(
                $csrfFormName
            ) ?>

            <!-- Informasi pemeriksaan -->
            <section class="card border-0 shadow-sm mb-4" aria-labelledby="assessment-information-title">
                <div class="card-header bg-white py-3">
                    <h2 id="assessment-information-title" class="h5 mb-1">
                        Informasi Pemeriksaan
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Tanggal dan waktu pelaksanaan pemeriksaan.
                    </p>
                </div>

                <div class="card-body p-4">
                    <div class="row g-3">

                        <div class="col-lg-5">
                            <label for="motor_name" class="form-label">
                                Motor
                            </label>

                            <input type="text" id="motor_name" class="form-control" value="<?= e( (string) $assessment[ 'alternative_code' ] . ' — ' . (string) $assessment[ 'motor_name' ] ) ?>" readonly>
                        </div>

                        <div class="col-lg-3 col-md-6">
                            <label for="inspection_date" class="form-label required-label">
                                Tanggal
                            </label>

                            <input type="date" id="inspection_date" name="inspection_date" class="form-control <?= e( validation_class( $errors, 'inspection_date' ) ) ?>" value="<?= e( $inspectionDateValue ) ?>" min="<?= e( $assessment[ 'period_start_date' ] ) ?>" max="<?= e( $assessment[ 'period_end_date' ] ) ?>" required>

                            <?php
                            $inspectionDateError =
                                validation_first_error(
                                    $errors,
                                    'inspection_date'
                                );
                            ?>

                            <?php if (
                                $inspectionDateError !== null
                            ): ?>
                                <div class="invalid-feedback">
                                    <?= e(
                                        $inspectionDateError
                                    ) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-lg-2 col-md-6">
                            <label for="inspection_time" class="form-label required-label">
                                Waktu
                            </label>

                            <input type="time" id="inspection_time" name="inspection_time" class="form-control <?= e( validation_class( $errors, 'inspection_time' ) ) ?>" value="<?= e( $inspectionTimeValue ) ?>" required>

                            <?php
                            $inspectionTimeError =
                                validation_first_error(
                                    $errors,
                                    'inspection_time'
                                );
                            ?>

                            <?php if (
                                $inspectionTimeError !== null
                            ): ?>
                                <div class="invalid-feedback">
                                    <?= e(
                                        $inspectionTimeError
                                    ) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-lg-2">
                            <label for="inspector_name" class="form-label">
                                Teknisi
                            </label>

                            <input type="text" id="inspector_name" class="form-control" value="<?= e( $assessment[ 'inspector_name' ] ) ?>" readonly>
                        </div>

                        <div class="col-lg-4 col-md-6">
                            <label for="operating_status" class="form-label required-label">
                                Status Operasi Motor
                            </label>

                            <select id="operating_status" name="operating_status" class="form-select <?= e( validation_class( $errors, 'operating_status' ) ) ?>" required>
                                <option value="">
                                    Pilih status operasi
                                </option>

                                <option value="running" <?= selected( $operatingStatusValue, 'running' ) ?>>
                                    Beroperasi
                                </option>

                                <option value="stopped" <?= selected( $operatingStatusValue, 'stopped' ) ?>>
                                    Berhenti
                                </option>
                            </select>

                            <?php
                            $operatingStatusError =
                                validation_first_error(
                                    $errors,
                                    'operating_status'
                                );
                            ?>

                            <?php if (
                                $operatingStatusError !== null
                            ): ?>
                                <div class="invalid-feedback">
                                    <?= e(
                                        $operatingStatusError
                                    ) ?>
                                </div>
                            <?php endif; ?>

                            <div class="form-text">
                                Pengiriman ke Supervisor membutuhkan
                                motor dalam keadaan beroperasi.
                            </div>
                        </div>

                        <div class="col-lg-4 col-md-6">
                            <label for="load_condition" class="form-label required-label">
                                Kondisi Beban
                            </label>

                            <select id="load_condition" name="load_condition" class="form-select <?= e( validation_class( $errors, 'load_condition' ) ) ?>" required>
                                <option value="">
                                    Pilih kondisi beban
                                </option>

                                <option value="no_load" <?= selected( $loadConditionValue, 'no_load' ) ?>>
                                    Tanpa Beban
                                </option>

                                <option value="light" <?= selected( $loadConditionValue, 'light' ) ?>>
                                    Beban Ringan
                                </option>

                                <option value="normal" <?= selected( $loadConditionValue, 'normal' ) ?>>
                                    Beban Normal
                                </option>

                                <option value="high" <?= selected( $loadConditionValue, 'high' ) ?>>
                                    Beban Tinggi
                                </option>
                            </select>

                            <?php
                            $loadConditionError =
                                validation_first_error(
                                    $errors,
                                    'load_condition'
                                );
                            ?>

                            <?php if (
                                $loadConditionError !== null
                            ): ?>
                                <div class="invalid-feedback">
                                    <?= e(
                                        $loadConditionError
                                    ) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="col-lg-4">
                            <label for="operating_notes" class="form-label">
                                Catatan Operasi
                            </label>

                            <textarea
                                id="operating_notes"
                                name="operating_notes"
                                class="form-control <?= e(
                                    validation_class(
                                        $errors,
                                        'operating_notes'
                                    )
                                ) ?>"
                                rows="3"
                                maxlength="1000"
                                placeholder="Catatan kondisi operasi bila diperlukan."
                            ><?= e(
                                $operatingNotesValue
                            ) ?></textarea>

                            <?php
                            $operatingNotesError =
                                validation_first_error(
                                    $errors,
                                    'operating_notes'
                                );
                            ?>

                            <?php if (
                                $operatingNotesError !== null
                            ): ?>
                                <div class="invalid-feedback">
                                    <?= e(
                                        $operatingNotesError
                                    ) ?>
                                </div>
                            <?php else: ?>
                                <div class="form-text">
                                    Opsional, maksimal 1.000 karakter.
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>
            </section>

            <!-- Pengukuran C1, C2, C3 -->
            <section class="card border-0 shadow-sm mb-4" aria-labelledby="measurement-title">
                <div class="card-header bg-white py-3">
                    <h2 id="measurement-title" class="h5 mb-1">
                        Pengukuran C1, C2, dan C3
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Lakukan setiap pengukuran sebanyak tiga kali.
                    </p>
                </div>

                <div class="card-body p-4">
                    <div class="row g-4">

                        <?php
                        $measurementSections = [
                            [
                                'criterion' => 'C1',
                                'title' => 'Suhu Permukaan',
                                'field_prefix' => 'temperature',
                                'unit' => '°C',
                                'help' =>
                                    'Diukur menggunakan infrared thermometer.',
                            ],

                            [
                                'criterion' => 'C2',
                                'title' => 'Arus Motor',
                                'field_prefix' => 'current',
                                'unit' => 'A',
                                'help' =>
                                    'Diukur menggunakan clamp meter.',
                            ],

                            [
                                'criterion' => 'C3',
                                'title' => 'Kecepatan Putaran',
                                'field_prefix' => 'rpm',
                                'unit' => 'RPM',
                                'help' =>
                                    'Diukur menggunakan tachometer.',
                            ],
                        ];
                        ?>

                        <?php foreach (
                            $measurementSections
                            as $section
                        ): ?>
                            <div class="col-xl-4">
                                <article class="measurement-card">
                                    <div class="measurement-card-header">
                                        <span class="badge text-bg-primary mb-2">
                                            <?= e(
                                                $section[
                                                    'criterion'
                                                ]
                                            ) ?>
                                        </span>

                                        <h3 class="h6 mb-1">
                                            <?= e(
                                                $section['title']
                                            ) ?>
                                        </h3>

                                        <small class="text-body-secondary">
                                            <?= e(
                                                $section['help']
                                            ) ?>
                                        </small>
                                    </div>

                                    <div class="measurement-card-body">
                                        <?php for (
                                            $measurementNumber = 1;
                                            $measurementNumber <= 3;
                                            $measurementNumber++
                                        ): ?>
                                            <?php
                                            $fieldName =
                                                $section[
                                                    'field_prefix'
                                                ]
                                                . '_'
                                                . $measurementNumber;

                                            $fieldError =
                                                validation_first_error(
                                                    $errors,
                                                    $fieldName
                                                );
                                            ?>

                                            <div class="mb-3">
                                                <label for="<?= e( $fieldName ) ?>" class="form-label">
                                                    Pengukuran
                                                    <?= e(
                                                        $measurementNumber
                                                    ) ?>
                                                </label>

                                                <div class="input-group has-validation">
                                                    <input type="text" inputmode="decimal" id="<?= e( $fieldName ) ?>" name="<?= e( $fieldName ) ?>" class="form-control <?= e( validation_class( $errors, $fieldName ) ) ?>" value="<?= e( $measurementValues[ $fieldName ] ) ?>" placeholder="0,00">

                                                    <span class="input-group-text">
                                                        <?= e(
                                                            $section[
                                                                'unit'
                                                            ]
                                                        ) ?>
                                                    </span>

                                                    <?php if (
                                                        $fieldError !== null
                                                    ): ?>
                                                        <div class="invalid-feedback">
                                                            <?= e(
                                                                $fieldError
                                                            ) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endfor; ?>

                                        <?php if (
                                            $section['criterion']
                                            === 'C2'
                                        ): ?>
                                            <div class="calculated-value">
                                                <small>
                                                    Arus nominal
                                                </small>

                                                <strong>
                                                    <?= (
                                                        $assessment[
                                                            'rated_current'
                                                        ] ?? null
                                                    ) !== null
                                                        ? e(
                                                            format_number_id(
                                                                (float)
                                                                $assessment[
                                                                    'rated_current'
                                                                ],
                                                                2
                                                            )
                                                            . ' A'
                                                        )
                                                        : '-' ?>
                                                </strong>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (
                                            $section['criterion']
                                            === 'C3'
                                        ): ?>
                                            <?php
                                            $frequencyError =
                                                validation_first_error(
                                                    $errors,
                                                    'operating_frequency'
                                                );
                                            ?>

                                            <div class="mb-3">
                                                <label for="operating_frequency" class="form-label">
                                                    Frekuensi Operasi
                                                </label>

                                                <div class="input-group has-validation">
                                                    <input type="text" inputmode="decimal" id="operating_frequency" name="operating_frequency" class="form-control <?= e( validation_class( $errors, 'operating_frequency' ) ) ?>" value="<?= e( $measurementValues[ 'operating_frequency' ] ) ?>" placeholder="30–50">

                                                    <span class="input-group-text">
                                                        Hz
                                                    </span>

                                                    <?php if (
                                                        $frequencyError
                                                        !== null
                                                    ): ?>
                                                        <div class="invalid-feedback">
                                                            <?= e(
                                                                $frequencyError
                                                            ) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="form-text">
                                                    Rentang operasi
                                                    penelitian: 30–50 Hz.
                                                </div>
                                            </div>

                                            <div class="calculated-value">
                                                <small>
                                                    RPM nameplate
                                                </small>

                                                <strong>
                                                    <?= (
                                                        $assessment[
                                                            'rated_rpm'
                                                        ] ?? null
                                                    ) !== null
                                                        ? e(
                                                            format_number_id(
                                                                (float)
                                                                $assessment[
                                                                    'rated_rpm'
                                                                ],
                                                                0
                                                            )
                                                            . ' RPM'
                                                        )
                                                        : '-' ?>
                                                </strong>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            </div>
                        <?php endforeach; ?>

                    </div>
                </div>
            </section>

            <!-- Checklist C4 dan C5 -->
            <?php foreach (
                $checklistGroups
                as $criterionCode => $groupItems
            ): ?>
                <?php
                $criterionTitle =
                    $criterionCode === 'C4'
                        ? 'Kondisi Mekanikal'
                        : 'Kondisi Elektrikal';
                ?>

                <section class="card border-0 shadow-sm mb-4" aria-labelledby="<?= e( strtolower($criterionCode) ) ?>-checklist-title">
                    <div class="card-header bg-white py-3">
                        <h2 id="<?= e( strtolower($criterionCode) ) ?>-checklist-title" class="h5 mb-1">
                            <?= e($criterionCode) ?>
                            — <?= e($criterionTitle) ?>
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Pilih satu kondisi pada setiap indikator.
                        </p>
                    </div>

                    <div class="card-body p-4">
                        <?php foreach (
                            $groupItems
                            as $itemIndex => $checklistItem
                        ): ?>
                            <?php
                            $checklistItemId = (int) (
                                $checklistItem[
                                    'checklist_item_id'
                                ] ?? 0
                            );

                            $oldChecklistItem =
                                $oldChecklist[
                                    $checklistItemId
                                ]
                                ?? [];

                            if (
                                !is_array(
                                    $oldChecklistItem
                                )
                            ) {
                                $oldChecklistItem = [];
                            }

                            $conditionValue =
                                array_key_exists(
                                    'condition_level',
                                    $oldChecklistItem
                                )
                                    ? (string) $oldChecklistItem[
                                        'condition_level'
                                    ]
                                    : (string) (
                                        $checklistItem[
                                            'condition_level'
                                        ]
                                        ?? 'not_checked'
                                    );

                            $findingNoteValue =
                                array_key_exists(
                                    'finding_note',
                                    $oldChecklistItem
                                )
                                    ? (string) $oldChecklistItem[
                                        'finding_note'
                                    ]
                                    : (string) (
                                        $checklistItem[
                                            'finding_note'
                                        ]
                                        ?? ''
                                    );

                            $conditionErrorKey =
                                'checklist_'
                                . $checklistItemId
                                . '_condition';

                            $noteErrorKey =
                                'checklist_'
                                . $checklistItemId
                                . '_note';

                            $conditionError =
                                validation_first_error(
                                    $errors,
                                    $conditionErrorKey
                                );

                            $noteError =
                                validation_first_error(
                                    $errors,
                                    $noteErrorKey
                                );
                            ?>

                            <article class="checklist-item" data-checklist-item>
                                <div class="checklist-item-header">
                                    <div class="d-flex align-items-start gap-3">
                                        <span class="checklist-code flex-shrink-0">
                                            <?= e(
                                                $checklistItem[
                                                    'item_code'
                                                ]
                                                ?? (
                                                    $criterionCode
                                                    . '-'
                                                    . ($itemIndex + 1)
                                                )
                                            ) ?>
                                        </span>

                                        <div>
                                            <h3 class="h6 mb-1">
                                                <?= e(
                                                    $checklistItem[
                                                        'item_name'
                                                    ]
                                                ) ?>
                                            </h3>

                                            <?php if (
                                                trim(
                                                    (string) (
                                                        $checklistItem[
                                                            'description'
                                                        ] ?? ''
                                                    )
                                                ) !== ''
                                            ): ?>
                                                <p class="small text-body-secondary mb-0">
                                                    <?= e(
                                                        $checklistItem[
                                                            'description'
                                                        ]
                                                    ) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="checklist-item-body">
                                    <div class="condition-options">
                                        <?php foreach (
                                            $conditionConfigurations
                                            as $conditionKey
                                            => $conditionConfiguration
                                        ): ?>
                                            <?php
                                            if ($conditionKey === 'not_checked') {
                                                continue;
                                            }

                                            if (
                                                $conditionKey
                                                    === 'not_applicable'

                                                && (int) (
                                                    $checklistItem[
                                                        'allows_na'
                                                    ] ?? 0
                                                ) !== 1
                                            ) {
                                                continue;
                                            }

                                            $radioId =
                                                'checklist_'
                                                . $checklistItemId
                                                . '_'
                                                . $conditionKey;
                                            ?>

                                            <div class="condition-option <?= e( $conditionConfiguration[ 'class' ] ) ?>">
                                                <input type="radio" id="<?= e( $radioId ) ?>" name="checklist[<?= e( $checklistItemId ) ?>][condition_level]" value="<?= e( $conditionKey ) ?>" data-condition-input <?= checked( $conditionValue, $conditionKey ) ?>>

                                                <label for="<?= e( $radioId ) ?>">
                                                    <strong>
                                                        <?= e(
                                                            $conditionConfiguration[
                                                                'short_label'
                                                            ]
                                                        ) ?>
                                                    </strong>

                                                    <small>
                                                        <?= e(
                                                            $conditionConfiguration[
                                                                'description'
                                                            ]
                                                        ) ?>
                                                    </small>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <?php if (
                                        $conditionError !== null
                                    ): ?>
                                        <div class="text-danger small mt-2">
                                            <?= e(
                                                $conditionError
                                            ) ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="mt-3">
                                        <label for="checklist_note_<?= e( $checklistItemId ) ?>" class="form-label">
                                            Keterangan Temuan
                                        </label>

                                        <textarea id="checklist_note_<?= e( $checklistItemId ) ?>" name="checklist[<?= e( $checklistItemId ) ?>][finding_note]" class="form-control <?= e( validation_class( $errors, $noteErrorKey ) ) ?>" rows="2" maxlength="500" data-finding-note placeholder="Tambahkan keterangan bila diperlukan." ><?= e( $findingNoteValue ) ?></textarea> <?php if ( $noteError !== null ): ?> <div class="invalid-feedback">
                                                <?= e(
                                                    $noteError
                                                ) ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="form-text">
                                                Wajib diisi untuk kondisi
                                                Perlu Perhatian atau Bahaya.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <!-- Tombol aksi -->
            <section class="card border-0 shadow-sm" aria-labelledby="assessment-action-title">
                <div class="card-body p-4">
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                        <div>
                            <h2 id="assessment-action-title" class="h5 mb-1">
                                Simpan Pemeriksaan
                            </h2>

                            <p class="text-body-secondary mb-0">
                                Isian yang belum selesai dapat disimpan sebagai Draft.
                                Sebelum dikirim, seluruh pengukuran dan checklist
                                wajib sudah terisi.
                            </p>
                        </div>

                        <div class="d-grid d-sm-flex gap-2">
                            <button type="submit" name="form_action" value="save_draft" class="btn btn-outline-primary" data-loading-text="Menyimpan...">
                                Simpan Draft
                            </button>

                            <button type="submit" name="form_action" value="submit" class="btn btn-primary" data-loading-text="Mengirim...">
                                Kirim ke Supervisor
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </form>

        <p class="page-meta-card mt-3 mb-0">
            Pemeriksaan dibuat:

            <?= e(
                format_date_id(
                    $assessment['created_at']
                    ?? null,
                    true
                )
            ) ?>

            &middot;

            terakhir diperbarui:

            <?= e(
                format_date_id(
                    $assessment['updated_at']
                    ?? null,
                    true
                )
            ) ?>
        </p>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const operatingStatus = document.getElementById(
        'operating_status'
    );

    const loadCondition = document.getElementById(
        'load_condition'
    );

    if (!operatingStatus || !loadCondition) {
        return;
    }

    const synchronizeLoadCondition = function () {
        if (operatingStatus.value === 'stopped') {
            loadCondition.value = 'no_load';
        }
    };

    operatingStatus.addEventListener(
        'change',
        synchronizeLoadCondition
    );

    synchronizeLoadCondition();
});
</script>

<?php
require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';
?>

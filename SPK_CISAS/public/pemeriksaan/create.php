<?php

declare(strict_types=1);

/*
 * Mulai Pemeriksaan Motor
 * Sistem SPK PT. CISAS
 * Halaman ini digunakan Teknisi untuk membuat pemeriksaan baru.
 * Alurnya:
 * 1. Teknisi memilih motor pada periode aktif.
 * 2. Sistem menampilkan identitas periode dan motor.
 * 3. Teknisi menentukan tanggal, waktu, status operasi,
 * kondisi beban, dan catatan operasi.
 * 4. Sistem membuat pemeriksaan berstatus Draft.
 * 5. Teknisi diarahkan ke halaman pengisian pemeriksaan.
 * Halaman ini hanya membuat data utama pada tabel assessments.
 * Data pengukuran dan checklist tetap disimpan melalui edit.php.
 */

// 1. Muat Helper Autentikasi

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'helpers'
    . DIRECTORY_SEPARATOR
    . 'auth.php';

// 2. Muat Helper CSRF

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'helpers'
    . DIRECTORY_SEPARATOR
    . 'csrf.php';

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'helpers'
    . DIRECTORY_SEPARATOR . 'audit.php';

// 3. Batasi Akses Hanya untuk Teknisi

auth_require_role('technician');

// 4. Koneksi Database

$pdo = require dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'config'
    . DIRECTORY_SEPARATOR
    . 'database.php';

// 5. Pastikan Akun Teknisi Masih Aktif

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun Teknisi sudah tidak aktif atau tidak ditemukan.'
    );

    redirect('login.php');
}

// 6. Ambil Informasi Teknisi

$currentUser = auth_user() ?? [];

$currentUserId = auth_id();

$currentUserName = auth_name()
    ?? 'Teknisi';

if (
    $currentUserId === null
    || $currentUserId < 1
) {
    flash_error(
        'Sesi Teknisi tidak valid. Silakan masuk kembali.'
    );

    redirect('login.php');
}

/*
 * 7. Fungsi Validasi ID
 * Nilai period_id dan machine_id harus berupa bilangan bulat positif.
 */

$validatePositiveInteger = static function (
    mixed $value
): ?int {
    $validatedValue = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if ($validatedValue === false) {
        return null;
    }

    return (int) $validatedValue;
};

/*
 * 8. Ambil ID Periode dan Motor dari URL
 * Contoh:
 * create.php?period_id=1&machine_id=1
 */

$periodId = $validatePositiveInteger(
    $_GET['period_id']
    ?? null
);

$machineId = $validatePositiveInteger(
    $_GET['machine_id']
    ?? null
);

// 9. Periksa ID URL

if (
    $periodId === null
    || $machineId === null
) {
    $notFoundMessage =
        'Periode atau motor pemeriksaan tidak valid.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '404.php';

    exit;
}

// 10. Fungsi Validasi Tanggal

$validateDate = static function (
    string $value
): bool {
    $value = trim($value);

    if ($value === '') {
        return false;
    }

    try {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value
        );
    } catch (ValueError) {
        return false;
    }

    if ($date === false) {
        return false;
    }

    $dateErrors =
        DateTimeImmutable::getLastErrors();

    if (
        is_array($dateErrors)
        && (
            (int) (
                $dateErrors['warning_count']
                ?? 0
            ) > 0

            || (int) (
                $dateErrors['error_count']
                ?? 0
            ) > 0
        )
    ) {
        return false;
    }

    return $date->format('Y-m-d')
        === $value;
};

/*
 * 11. Fungsi Validasi Waktu
 * Format waktu yang diterima:
 * HH:MM
 * Contoh:
 * 08:30
 * 17:45
 */

$validateTime = static function (
    string $value
): bool {
    return preg_match(
        '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
        trim($value)
    ) === 1;
};

// 12. Fungsi Mengambil Input String

$getInputString = static function (
    array $source,
    string $key,
    string $default = ''
): string {
    $value = $source[$key]
        ?? $default;

    if (!is_scalar($value)) {
        return $default;
    }

    return trim(
        (string) $value
    );
};

// 13. Nilai Awal

$pageError = null;

$period = null;

$machine = null;

$existingAssessment = null;

// 14. Ambil Data Periode dan Motor

try {
    // Ambil Periode

    $periodStatement = $pdo->prepare(
        "SELECT
            id,
            name,
            start_date,
            end_date,
            status,
            created_at,
            updated_at

         FROM assessment_periods

         WHERE id = :id

         LIMIT 1"
    );

    $periodStatement->execute([
        'id' => $periodId,
    ]);

    $periodData = $periodStatement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!is_array($periodData)) {
        $notFoundMessage =
            'Periode pemeriksaan tidak ditemukan.';

        require dirname(__DIR__)
            . DIRECTORY_SEPARATOR
            . '404.php';

        exit;
    }

    $period = $periodData;

    // Periode Harus Aktif

    if (
        (string) (
            $period['status']
            ?? ''
        ) !== 'active'
    ) {
        flash_error(
            'Pemeriksaan hanya dapat dibuat pada periode aktif.'
        );

        redirect(
            'pemeriksaan/index.php?period_id='
            . $periodId
        );
    }

    // Ambil Data Motor

    $machineStatement = $pdo->prepare(
        "SELECT
            id,
            alternative_code,
            machine_code,
            motor_name,
            motor_function,
            rated_current,
            rated_frequency,
            rated_rpm,
            number_of_poles,
            status,
            created_at,
            updated_at

         FROM machines

         WHERE id = :id

         LIMIT 1"
    );

    $machineStatement->execute([
        'id' => $machineId,
    ]);

    $machineData = $machineStatement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!is_array($machineData)) {
        $notFoundMessage =
            'Data motor tidak ditemukan.';

        require dirname(__DIR__)
            . DIRECTORY_SEPARATOR
            . '404.php';

        exit;
    }

    $machine = $machineData;

    // Motor Harus Aktif

    if (
        (string) (
            $machine['status']
            ?? ''
        ) !== 'active'
    ) {
        flash_error(
            'Pemeriksaan tidak dapat dibuat karena motor tidak aktif.'
        );

        redirect(
            'pemeriksaan/index.php?period_id='
            . $periodId
        );
    }

    /*
 * Periksa Pemeriksaan yang Sudah Ada
 * Satu motor hanya boleh memiliki satu pemeriksaan
 * dalam satu periode.
 */

    $existingStatement = $pdo->prepare(
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

    $existingStatement->execute([
        'period_id' => $periodId,
        'machine_id' => $machineId,
    ]);

    $existingData = $existingStatement->fetch(
        PDO::FETCH_ASSOC
    );

    $existingAssessment =
        is_array($existingData)
            ? $existingData
            : null;
} catch (Throwable $exception) {
    error_log(
        'Muat halaman mulai pemeriksaan gagal: '
        . $exception->getMessage()
    );

    $pageError =
        'Data periode atau motor belum dapat dimuat. '
        . 'Silakan coba kembali.';
}

/*
 * 15. Arahkan ke Pemeriksaan Lama jika Sudah Ada
 * Bagian ini dijalankan setelah proses POST.
 * Untuk GET, informasi tetap dapat digunakan untuk menentukan redirect.
 */

if (
    $existingAssessment !== null
    && strtoupper(
        (string) (
            $_SERVER['REQUEST_METHOD']
            ?? 'GET'
        )
    ) !== 'POST'
) {
    $existingAssessmentId = (int) (
        $existingAssessment['id']
        ?? 0
    );

    $existingInspectorId = (int) (
        $existingAssessment['inspector_id']
        ?? 0
    );

    $existingStatus = (string) (
        $existingAssessment['status']
        ?? ''
    );

    if (
        $existingAssessmentId > 0
        && $existingInspectorId === $currentUserId
        && in_array(
            $existingStatus,
            [
                'draft',
                'returned',
            ],
            true
        )
    ) {
        flash_warning(
            'Pemeriksaan motor ini sudah pernah dibuat. '
            . 'Silakan lanjutkan pengisiannya.'
        );

        redirect(
            'pemeriksaan/edit.php?id='
            . $existingAssessmentId
        );
    }

    if ($existingAssessmentId > 0) {
        flash_warning(
            'Motor ini sudah mempunyai pemeriksaan '
            . 'pada periode yang dipilih.'
        );

        redirect(
            'pemeriksaan/detail.php?id='
            . $existingAssessmentId
        );
    }
}

/*
 * 16. Tentukan Tanggal Default
 * Gunakan tanggal hari ini apabila masih berada dalam rentang periode.
 * Jika tidak, gunakan tanggal mulai periode.
 */

$periodStartDate = (string) (
    $period['start_date']
    ?? ''
);

$periodEndDate = (string) (
    $period['end_date']
    ?? ''
);

$todayDate = date('Y-m-d');

$defaultInspectionDate =
    $todayDate;

if (
    $periodStartDate !== ''
    && $todayDate < $periodStartDate
) {
    $defaultInspectionDate =
        $periodStartDate;
}

if (
    $periodEndDate !== ''
    && $todayDate > $periodEndDate
) {
    $defaultInspectionDate =
        $periodEndDate;
}

$defaultInspectionTime =
    date('H:i');

// 17. Nama Token CSRF

$csrfFormName =
    'assessment_create_'
    . $periodId
    . '_'
    . $machineId;

// 18. Proses Form

$isPostRequest = strtoupper(
    (string) (
        $_SERVER['REQUEST_METHOD']
        ?? 'GET'
    )
) === 'POST';

if ($isPostRequest) {
    // Ambil Input

    $inspectionDate = $getInputString(
        $_POST,
        'inspection_date',
        ''
    );

    $inspectionTime = $getInputString(
        $_POST,
        'inspection_time',
        ''
    );

    $operatingStatus = $getInputString(
        $_POST,
        'operating_status',
        ''
    );

    $loadCondition = $getInputString(
        $_POST,
        'load_condition',
        ''
    );

    $operatingNotes = $getInputString(
        $_POST,
        'operating_notes',
        ''
    );

    // Simpan Input Lama

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
    ]);

    // Validasi CSRF

    if (
        !csrf_validate_request(
            $csrfFormName
        )
    ) {
        flash_error(
            'Token keamanan tidak valid atau telah kedaluwarsa. '
            . 'Silakan coba kembali.'
        );

        redirect(
            'pemeriksaan/create.php?period_id='
            . $periodId
            . '&machine_id='
            . $machineId
        );
    }

    // Validasi Form

    $formErrors = [];

    if (!$validateDate($inspectionDate)) {
        $formErrors['inspection_date'] =
            'Tanggal pemeriksaan tidak valid.';
    }

    if (!$validateTime($inspectionTime)) {
        $formErrors['inspection_time'] =
            'Waktu pemeriksaan tidak valid.';
    }

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
        $formErrors['operating_status'] =
            'Status operasi motor wajib dipilih.';
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
        $formErrors['load_condition'] =
            'Kondisi beban motor wajib dipilih.';
    }

    if (
        $operatingStatus === 'stopped'
        && $loadCondition !== 'no_load'
    ) {
        $formErrors['load_condition'] =
            'Motor yang berhenti harus menggunakan '
            . 'kondisi Tanpa Beban.';
    }

    if (
        strlen(
            $operatingNotes
        ) > 1000
    ) {
        $formErrors['operating_notes'] =
            'Catatan operasi maksimal 1.000 karakter.';
    }

    // Tanggal Harus Berada dalam Periode

    if (
        !isset(
            $formErrors['inspection_date']
        )

        && (
            $inspectionDate < $periodStartDate

            || $inspectionDate > $periodEndDate
        )
    ) {
        $formErrors['inspection_date'] =
            'Tanggal pemeriksaan harus berada '
            . 'dalam rentang periode.';
    }

    // Kembali jika Validasi Gagal

    if ($formErrors !== []) {
        flash_set_errors(
            $formErrors
        );

        flash_error(
            'Pemeriksaan belum dapat dibuat. '
            . 'Periksa kembali data pemeriksaan yang diisi.'
        );

        redirect(
            'pemeriksaan/create.php?period_id='
            . $periodId
            . '&machine_id='
            . $machineId
        );
    }

    // Simpan Pemeriksaan

    $ownsTransaction =
        !$pdo->inTransaction();

    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        /*
 * Periksa Ulang Periode dan Motor
 * Pemeriksaan ulang dilakukan di dalam transaksi
 * agar status tidak berubah pada saat penyimpanan.
 */

        $latestDataStatement = $pdo->prepare(
            "SELECT
                p.status AS period_status,
                p.start_date,
                p.end_date,
                m.status AS machine_status

             FROM assessment_periods AS p

             CROSS JOIN machines AS m

             WHERE p.id = :period_id
               AND m.id = :machine_id

             LIMIT 1"
        );

        $latestDataStatement->execute([
            'period_id' => $periodId,
            'machine_id' => $machineId,
        ]);

        $latestData = $latestDataStatement->fetch(
            PDO::FETCH_ASSOC
        );

        if (!is_array($latestData)) {
            throw new RuntimeException(
                'Periode atau motor tidak ditemukan.'
            );
        }

        if (
            (string) (
                $latestData['period_status']
                ?? ''
            ) !== 'active'
        ) {
            throw new RuntimeException(
                'Periode pemeriksaan sudah tidak aktif.'
            );
        }

        if (
            (string) (
                $latestData['machine_status']
                ?? ''
            ) !== 'active'
        ) {
            throw new RuntimeException(
                'Motor sudah tidak aktif.'
            );
        }

        // Periksa Ulang Duplikasi

        $duplicateStatement = $pdo->prepare(
            "SELECT
                id,
                inspector_id,
                status

             FROM assessments

             WHERE period_id = :period_id
               AND machine_id = :machine_id

             LIMIT 1"
        );

        $duplicateStatement->execute([
            'period_id' => $periodId,
            'machine_id' => $machineId,
        ]);

        $duplicateAssessment =
            $duplicateStatement->fetch(
                PDO::FETCH_ASSOC
            );

        if (is_array($duplicateAssessment)) {
            $duplicateId = (int) (
                $duplicateAssessment['id']
                ?? 0
            );

            throw new RuntimeException(
                'Pemeriksaan motor sudah tersedia.'
                . '|assessment_id='
                . $duplicateId
            );
        }

        /*
 * Insert Pemeriksaan Draft
 * Pada tahap ini hanya tabel assessments yang diisi.
 * Status operasi, kondisi beban, dan catatan operasi
 * disimpan bersama data utama pemeriksaan.
 * Pengukuran dan checklist tetap disimpan ketika Teknisi
 * mengisi halaman edit.php.
 */

        $insertStatement = $pdo->prepare(
            "INSERT INTO assessments (
                period_id,
                machine_id,
                inspector_id,
                inspection_date,
                inspection_time,
                operating_status,
                load_condition,
                operating_notes,
                status,
                created_at,
                updated_at

             ) VALUES (
                :period_id,
                :machine_id,
                :inspector_id,
                :inspection_date,
                :inspection_time,
                :operating_status,
                :load_condition,
                :operating_notes,
                'draft',
                datetime('now', '+7 hours'),
                datetime('now', '+7 hours')
             )"
        );

        $insertStatement->execute([
            'period_id' =>
                $periodId,

            'machine_id' =>
                $machineId,

            'inspector_id' =>
                $currentUserId,

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
        ]);

        // Ambil ID Pemeriksaan Baru

        $assessmentId = (int) (
            $pdo->lastInsertId()
        );

        if ($assessmentId < 1) {
            throw new RuntimeException(
                'ID pemeriksaan baru tidak berhasil dibuat.'
            );
        }

        // Commit

        if ($ownsTransaction) {
            $pdo->commit();
        }

        audit_log(
            $pdo,
            $currentUserId,
            'assessment_created',
            'assessment',
            $assessmentId,
            'Draft pemeriksaan motor dibuat oleh Teknisi.',
            null,
            [
                'period_id' => $periodId,
                'machine_id' => $machineId,
                'alternative_code' => (string) ($machine['alternative_code'] ?? ''),
                'status' => 'draft',
            ]
        );

        // Bersihkan Input Lama

        flash_set_old_input([]);

        // Pesan Berhasil

        flash_success(
            'Draft pemeriksaan '
            . (string) (
                $machine['alternative_code']
                ?? ''
            )
            . ' berhasil dibuat. '
            . 'Silakan lanjutkan pengisian data.'
        );

        // Arahkan ke Form Pengisian

        redirect(
            'pemeriksaan/edit.php?id='
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

        // Catat Detail Error pada Terminal

        error_log(
            'Buat pemeriksaan SPK PT CISAS gagal: '
            . $exception->getMessage()
        );

        $exceptionMessage =
            strtolower(
                $exception->getMessage()
            );

        // Pemeriksaan Sudah Ada

        if (
            str_contains(
                $exceptionMessage,
                'pemeriksaan motor sudah tersedia'
            )

            || str_contains(
                $exceptionMessage,
                'unique'
            )
        ) {
            $existingId = 0;

            if (
                preg_match(
                    '/assessment_id=(\d+)/',
                    $exception->getMessage(),
                    $matches
                ) === 1
            ) {
                $existingId = (int) (
                    $matches[1]
                    ?? 0
                );
            }

            if ($existingId < 1) {
                $findExistingStatement =
                    $pdo->prepare(
                        "SELECT id

                         FROM assessments

                         WHERE period_id = :period_id
                           AND machine_id = :machine_id

                         LIMIT 1"
                    );

                $findExistingStatement->execute([
                    'period_id' =>
                        $periodId,

                    'machine_id' =>
                        $machineId,
                ]);

                $existingId = (int) (
                    $findExistingStatement
                        ->fetchColumn()
                );
            }

            flash_warning(
                'Motor ini sudah mempunyai pemeriksaan '
                . 'pada periode aktif.'
            );

            if ($existingId > 0) {
                redirect(
                    'pemeriksaan/detail.php?id='
                    . $existingId
                );
            }

            redirect(
                'pemeriksaan/index.php?period_id='
                . $periodId
            );
        }

        // Periode Tidak Aktif

        if (
            str_contains(
                $exceptionMessage,
                'periode pemeriksaan sudah tidak aktif'
            )
        ) {
            flash_error(
                'Pemeriksaan tidak dapat dibuat karena '
                . 'periode sudah tidak aktif.'
            );

            redirect(
                'pemeriksaan/index.php?period_id='
                . $periodId
            );
        }

        // Motor Tidak Aktif

        if (
            str_contains(
                $exceptionMessage,
                'motor sudah tidak aktif'
            )
        ) {
            flash_error(
                'Pemeriksaan tidak dapat dibuat karena '
                . 'motor sudah tidak aktif.'
            );

            redirect(
                'pemeriksaan/index.php?period_id='
                . $periodId
            );
        }

        // Error Umum

        flash_error(
            'Terjadi gangguan saat membuat pemeriksaan. '
            . 'Detail kesalahan telah dicatat pada terminal.'
        );

        redirect(
            'pemeriksaan/create.php?period_id='
            . $periodId
            . '&machine_id='
            . $machineId
        );
    }
}

// 19. Ambil Error dan Input Lama

$errors = flash_get_errors();

$oldInput = flash_get_old_input();

// 20. Nilai Form

$inspectionDateValue = $getInputString(
    $oldInput,
    'inspection_date',
    $defaultInspectionDate
);

$inspectionTimeValue = $getInputString(
    $oldInput,
    'inspection_time',
    $defaultInspectionTime
);

$operatingStatusValue = $getInputString(
    $oldInput,
    'operating_status',
    'running'
);

$loadConditionValue = $getInputString(
    $oldInput,
    'load_condition',
    'normal'
);

$operatingNotesValue = $getInputString(
    $oldInput,
    'operating_notes',
    ''
);

// 21. Ambil Pesan Error Kolom

$inspectionDateError = null;

$inspectionTimeError = null;

$operatingStatusError = null;

$loadConditionError = null;

$operatingNotesError = null;

if (
    isset($errors['inspection_date'])
) {
    $dateErrors =
        $errors['inspection_date'];

    if (is_array($dateErrors)) {
        $inspectionDateError =
            (string) (
                $dateErrors[0]
                ?? ''
            );
    } elseif (is_string($dateErrors)) {
        $inspectionDateError =
            $dateErrors;
    }
}

if (
    isset($errors['inspection_time'])
) {
    $timeErrors =
        $errors['inspection_time'];

    if (is_array($timeErrors)) {
        $inspectionTimeError =
            (string) (
                $timeErrors[0]
                ?? ''
            );
    } elseif (is_string($timeErrors)) {
        $inspectionTimeError =
            $timeErrors;
    }
}

if (
    isset($errors['operating_status'])
) {
    $statusErrors =
        $errors['operating_status'];

    if (is_array($statusErrors)) {
        $operatingStatusError =
            (string) (
                $statusErrors[0]
                ?? ''
            );
    } elseif (is_string($statusErrors)) {
        $operatingStatusError =
            $statusErrors;
    }
}

if (
    isset($errors['load_condition'])
) {
    $loadErrors =
        $errors['load_condition'];

    if (is_array($loadErrors)) {
        $loadConditionError =
            (string) (
                $loadErrors[0]
                ?? ''
            );
    } elseif (is_string($loadErrors)) {
        $loadConditionError =
            $loadErrors;
    }
}

if (
    isset($errors['operating_notes'])
) {
    $notesErrors =
        $errors['operating_notes'];

    if (is_array($notesErrors)) {
        $operatingNotesError =
            (string) (
                $notesErrors[0]
                ?? ''
            );
    } elseif (is_string($notesErrors)) {
        $operatingNotesError =
            $notesErrors;
    }
}

// 22. Label Fungsi Motor

$motorFunctionLabel = match (
    (string) (
        $machine['motor_function']
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

// 23. Fungsi Menampilkan Data Acuan

$displayReference = static function (
    mixed $value,
    int $decimals = 2,
    string $suffix = ''
): string {
    if (
        $value === null
        || trim((string) $value) === ''
    ) {
        return '-';
    }

    if (!is_numeric((string) $value)) {
        return (string) $value;
    }

    return format_number_id(
        floatval(
            (string) $value
        ),
        $decimals
    ) . $suffix;
};

// 24. Konfigurasi Halaman

$pageTitle =
    'Mulai Pemeriksaan '
    . (string) (
        $machine['alternative_code']
        ?? ''
    );

$pageDescription =
    'Buat pemeriksaan Draft sebelum mengisi '
    . 'pengukuran dan checklist motor.';

$bodyClass =
    'd-flex flex-column min-vh-100';

// 25. Muat Header dan Navbar

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'header.php';

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'navbar.php';

?>

<div class="d-lg-flex flex-grow-1">

    <?php
    // 26. Muat Sidebar

    require TEMPLATE_PATH
        . DIRECTORY_SEPARATOR
        . 'sidebar.php';
    ?>

    <main id="main-content" class="flex-grow-1 p-3 p-lg-4 overflow-hidden">
        <?php
        // 27. Muat Alert

        require TEMPLATE_PATH
            . DIRECTORY_SEPARATOR
            . 'alert.php';
        ?>

        <?php if ($pageError !== null): ?>

            <div class="alert alert-danger mb-4" role="alert">
                <strong class="d-block mb-1">
                    Data belum dapat dimuat
                </strong>

                <?= e(
                    $pageError
                ) ?>
            </div>

        <?php endif; ?>

        <!-- Kepala Halaman -->
        <section class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary text-white fw-bold" style=" width: 58px; height: 58px; font-size: 18px; ">
                        <?= e(
                            (string) (
                                $machine[
                                    'alternative_code'
                                ]
                                ?? '-'
                            )
                        ) ?>
                    </span>

                    <span class="badge text-bg-success p-2">
                        Periode Aktif
                    </span>
                </div>

                <h1 class="page-title">
                    Mulai Pemeriksaan Motor
                </h1>

                <p class="page-description">
                    Buat pemeriksaan Draft sebelum
                    mengisi pengukuran dan checklist motor.
                </p>
            </div>

            <div class="page-header-actions">
                <a href="<?= e( base_url( 'pemeriksaan/index.php?period_id=' . $periodId ) ) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>
            </div>
        </section>

        <div class="row g-4">

            <!-- Form Konfirmasi -->
            <div class="col-xl-8">

                <section class="card border-0 shadow-sm" aria-labelledby="confirmation-title">
                    <div class="card-header bg-white py-3">
                        <h2 id="confirmation-title" class="h5 mb-1">
                            Konfirmasi Pemeriksaan
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Tentukan waktu pemeriksaan,
                            lalu lanjutkan ke pengisian data.
                        </p>
                    </div>

                    <div class="card-body p-4">

                        <form method="post" action="<?= e( base_url( 'pemeriksaan/create.php?period_id=' . $periodId . '&machine_id=' . $machineId ) ) ?>" novalidate>
                            <?= csrf_field(
                                $csrfFormName
                            ) ?>

                            <!-- Periode Pemeriksaan -->
                            <div class="mb-4">

                                <label for="period_name" class="form-label">
                                    Periode Pemeriksaan
                                </label>

                                <input type="text" id="period_name" class="form-control" value="<?= e( (string) ( $period['name'] ?? '' ) ) ?>" readonly>

                                <div class="form-text">
                                    <?= e(
                                        format_date_id(
                                            $periodStartDate
                                        )
                                    ) ?>

                                    sampai

                                    <?= e(
                                        format_date_id(
                                            $periodEndDate
                                        )
                                    ) ?>.
                                </div>
                            </div>

                            <!-- Identitas Motor -->
                            <div class="row g-3 mb-4">

                                <div class="col-md-7">

                                    <label for="motor_name" class="form-label">
                                        Motor
                                    </label>

                                    <input type="text" id="motor_name" class="form-control" value="<?= e( (string) ( $machine[ 'alternative_code' ] ?? '' ) . ' — ' . (string) ( $machine[ 'motor_name' ] ?? '' ) ) ?>" readonly>
                                </div>

                                <div class="col-md-5">

                                    <label for="machine_code" class="form-label">
                                        Kode Mesin
                                    </label>

                                    <input type="text" id="machine_code" class="form-control" value="<?= e( (string) ( $machine[ 'machine_code' ] ?? '-' ) ) ?>" readonly>
                                </div>
                            </div>

                            <!-- Tanggal dan Waktu -->
                            <div class="row g-3 mb-4">

                                <div class="col-md-6">

                                    <label for="inspection_date" class="form-label">
                                        Tanggal Pemeriksaan

                                        <span class="text-danger" aria-hidden="true">
                                            *
                                        </span>
                                    </label>

                                    <input type="date" id="inspection_date" name="inspection_date" class="form-control <?= $inspectionDateError !== null ? 'is-invalid' : '' ?>" value="<?= e( $inspectionDateValue ) ?>" min="<?= e( $periodStartDate ) ?>" max="<?= e( $periodEndDate ) ?>" required>

                                    <?php if (
                                        $inspectionDateError
                                        !== null
                                    ): ?>

                                        <div class="invalid-feedback">
                                            <?= e(
                                                $inspectionDateError
                                            ) ?>
                                        </div>

                                    <?php endif; ?>
                                </div>

                                <div class="col-md-6">

                                    <label for="inspection_time" class="form-label">
                                        Waktu Pemeriksaan

                                        <span class="text-danger" aria-hidden="true">
                                            *
                                        </span>
                                    </label>

                                    <input type="time" id="inspection_time" name="inspection_time" class="form-control <?= $inspectionTimeError !== null ? 'is-invalid' : '' ?>" value="<?= e( $inspectionTimeValue ) ?>" required>

                                    <?php if (
                                        $inspectionTimeError
                                        !== null
                                    ): ?>

                                        <div class="invalid-feedback">
                                            <?= e(
                                                $inspectionTimeError
                                            ) ?>
                                        </div>

                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Status Operasi dan Kondisi Beban -->
                            <div class="row g-3 mb-4">

                                <div class="col-md-6">

                                    <label for="operating_status" class="form-label">
                                        Status Operasi Motor

                                        <span class="text-danger" aria-hidden="true">
                                            *
                                        </span>
                                    </label>

                                    <select id="operating_status" name="operating_status" class="form-select <?= $operatingStatusError !== null ? 'is-invalid' : '' ?>" required>
                                        <option value="">
                                            Pilih status operasi
                                        </option>

                                        <option value="running" <?= $operatingStatusValue === 'running' ? 'selected' : '' ?>>
                                            Beroperasi
                                        </option>

                                        <option value="stopped" <?= $operatingStatusValue === 'stopped' ? 'selected' : '' ?>>
                                            Berhenti
                                        </option>
                                    </select>

                                    <?php if (
                                        $operatingStatusError
                                        !== null
                                    ): ?>

                                        <div class="invalid-feedback">
                                            <?= e(
                                                $operatingStatusError
                                            ) ?>
                                        </div>

                                    <?php endif; ?>
                                </div>

                                <div class="col-md-6">

                                    <label for="load_condition" class="form-label">
                                        Kondisi Beban

                                        <span class="text-danger" aria-hidden="true">
                                            *
                                        </span>
                                    </label>

                                    <select id="load_condition" name="load_condition" class="form-select <?= $loadConditionError !== null ? 'is-invalid' : '' ?>" required>
                                        <option value="">
                                            Pilih kondisi beban
                                        </option>

                                        <option value="no_load" <?= $loadConditionValue === 'no_load' ? 'selected' : '' ?>>
                                            Tanpa Beban
                                        </option>

                                        <option value="light" <?= $loadConditionValue === 'light' ? 'selected' : '' ?>>
                                            Beban Ringan
                                        </option>

                                        <option value="normal" <?= $loadConditionValue === 'normal' ? 'selected' : '' ?>>
                                            Beban Normal
                                        </option>

                                        <option value="high" <?= $loadConditionValue === 'high' ? 'selected' : '' ?>>
                                            Beban Tinggi
                                        </option>
                                    </select>

                                    <?php if (
                                        $loadConditionError
                                        !== null
                                    ): ?>

                                        <div class="invalid-feedback">
                                            <?= e(
                                                $loadConditionError
                                            ) ?>
                                        </div>

                                    <?php endif; ?>

                                    <div class="form-text">
                                        Jika motor berhenti,
                                        pilih Tanpa Beban.
                                    </div>
                                </div>
                            </div>

                            <!-- Catatan Operasi -->
                            <div class="mb-4">

                                <label for="operating_notes" class="form-label">
                                    Catatan Operasi
                                </label>

                                <textarea id="operating_notes" name="operating_notes" class="form-control <?= $operatingNotesError !== null ? 'is-invalid' : '' ?>" rows="3" maxlength="1000" placeholder="Contoh: motor baru dinyalakan atau beban produksi sedang stabil." ><?= e( $operatingNotesValue ) ?></textarea> <?php if ( $operatingNotesError !== null ): ?> <div class="invalid-feedback">
                                        <?= e(
                                            $operatingNotesError
                                        ) ?>
                                    </div>

                                <?php endif; ?>

                                <div class="form-text">
                                    Catatan bersifat opsional,
                                    maksimal 1.000 karakter.
                                </div>
                            </div>

                            <!-- Informasi -->
                            <div class="alert alert-info mb-4" role="status">
                                Setelah tombol ditekan,
                                sistem membuat pemeriksaan
                                berstatus Draft beserta kondisi
                                operasinya. Pengukuran dan checklist
                                diisi pada halaman berikutnya.
                            </div>

                            <!-- Tombol -->
                            <div class="d-flex flex-column-reverse flex-sm-row justify-content-end gap-2">
                                <button type="submit" class="btn btn-primary" data-loading-text="Membuat Draft...">
                                    Buat Draft dan Lanjutkan
                                </button>
                            </div>
                        </form>
                    </div>
                </section>
            </div>

            <!-- Informasi Samping -->
            <div class="col-xl-4">

                <!-- Ruang Lingkup -->
                <section class="card border-0 shadow-sm mb-4" aria-labelledby="scope-title">
                    <div class="card-body p-4">

                        <h2 id="scope-title" class="h5 mb-4">
                            Ruang Lingkup Pemeriksaan
                        </h2>

                        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                            <strong>
                                C1 — Suhu
                            </strong>

                            <span>
                                3 kali
                            </span>
                        </div>

                        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                            <strong>
                                C2 — Arus
                            </strong>

                            <span>
                                3 kali
                            </span>
                        </div>

                        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                            <strong>
                                C3 — RPM
                            </strong>

                            <span>
                                3 kali
                            </span>
                        </div>

                        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                            <strong>
                                C4 — Mekanikal
                            </strong>

                            <span>
                                6 item
                            </span>
                        </div>

                        <div class="d-flex justify-content-between align-items-center gap-3">
                            <strong>
                                C5 — Elektrikal
                            </strong>

                            <span>
                                6 item
                            </span>
                        </div>
                    </div>
                </section>

                <!-- Data Acuan -->
                <section class="card border-0 shadow-sm" aria-labelledby="reference-title">
                    <div class="card-body p-4">

                        <h2 id="reference-title" class="h5 mb-4">
                            Data Acuan Motor
                        </h2>

                        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                            <strong>
                                Fungsi motor
                            </strong>

                            <span class="text-end">
                                <?= e(
                                    $motorFunctionLabel
                                ) ?>
                            </span>
                        </div>

                        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                            <strong>
                                Arus nominal
                            </strong>

                            <span class="text-end">
                                <?= e(
                                    $displayReference(
                                        $machine[
                                            'rated_current'
                                        ] ?? null,
                                        2,
                                        ' A'
                                    )
                                ) ?>
                            </span>
                        </div>

                        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                            <strong>
                                Frekuensi nominal
                            </strong>

                            <span class="text-end">
                                <?= e(
                                    $displayReference(
                                        $machine[
                                            'rated_frequency'
                                        ] ?? null,
                                        2,
                                        ' Hz'
                                    )
                                ) ?>
                            </span>
                        </div>

                        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                            <strong>
                                RPM nominal
                            </strong>

                            <span class="text-end">
                                <?= e(
                                    $displayReference(
                                        $machine[
                                            'rated_rpm'
                                        ] ?? null,
                                        0,
                                        ' RPM'
                                    )
                                ) ?>
                            </span>
                        </div>

                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <strong>
                                Jumlah kutub
                            </strong>

                            <span class="text-end">
                                <?= e(
                                    $displayReference(
                                        $machine[
                                            'number_of_poles'
                                        ] ?? null,
                                        0,
                                        ''
                                    )
                                ) ?>
                            </span>
                        </div>

                        <?php if (
                            empty(
                                $machine[
                                    'rated_current'
                                ]
                            )

                            || empty(
                                $machine[
                                    'rated_frequency'
                                ]
                            )

                            || empty(
                                $machine[
                                    'rated_rpm'
                                ]
                            )
                        ): ?>

                            <div class="alert alert-warning small mt-4 mb-0">
                                Data arus nominal, frekuensi nominal,
                                atau RPM nominal belum lengkap. Draft
                                tetap dapat dibuat, tetapi data acuan
                                wajib dilengkapi sebelum pemeriksaan
                                dikirim kepada Supervisor.
                            </div>

                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>

<?php

// 28. Muat Footer

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

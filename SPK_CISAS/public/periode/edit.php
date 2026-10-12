<?php

declare(strict_types=1);

/*
 * Ubah Periode Pemeriksaan Sistem SPK PT CISAS
 * Halaman ini hanya dapat diakses oleh Administrator.
 * Fungsinya:
 * - mengambil periode berdasarkan ID;
 * - mengubah nama dan rentang tanggal periode;
 * - mengubah status Draft, Aktif, atau Selesai;
 * - memastikan hanya satu periode aktif;
 * - mencegah tanggal pemeriksaan berada di luar rentang periode;
 * - memastikan TOPSIS selesai sebelum periode ditutup;
 * - menjaga periode yang sudah selesai agar tidak diubah kembali.
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
    . 'WorkflowService.php';

// Batasi Akses Hanya untuk Administrator

auth_require_role('admin');

// Koneksi Database

$pdo = require dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'config'
    . DIRECTORY_SEPARATOR
    . 'database.php';

// Pastikan Akun Administrator Masih Aktif

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun Administrator sudah tidak aktif atau tidak ditemukan.'
    );

    redirect('login.php');
}

$currentUserId = auth_id();
if ($currentUserId === null || $currentUserId < 1) {
    flash_error('Sesi Administrator tidak valid. Silakan masuk kembali.');
    redirect('login.php');
}

// Validasi ID Periode

$periodId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if ($periodId === false) {
    $notFoundMessage =
        'ID periode tidak valid atau tidak tersedia.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '404.php';

    exit;
}

$periodId = (int) $periodId;

// Konfigurasi Penelitian

$expectedMachineCount = (int) app_config(
    'total_machines',
    6
);

// Ambil Data Periode

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

$period = $periodStatement->fetch();

if (!is_array($period)) {
    $notFoundMessage =
        'Periode pemeriksaan yang akan diubah tidak ditemukan.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '404.php';

    exit;
}

// Periode Selesai Tidak Dapat Diubah

$currentPeriodStatus = (string) (
    $period['status']
    ?? 'draft'
);

if ($currentPeriodStatus === 'completed') {
    flash_warning(
        'Periode yang sudah berstatus Selesai tidak dapat diubah kembali.'
    );

    redirect(
        'periode/detail.php?id='
        . $periodId
    );
}

// Statistik Periode

$periodStatistics = [
    'assessment_count' => 0,
    'draft_count' => 0,
    'pending_validation_count' => 0,
    'validated_count' => 0,
    'returned_count' => 0,
    'processed_count' => 0,
    'topsis_result_count' => 0,
    'minimum_inspection_date' => null,
    'maximum_inspection_date' => null,
];

// Ambil Statistik Pemeriksaan

try {
    $assessmentSummaryStatement = $pdo->prepare(
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
            ) AS pending_validation_count,

            SUM(
                CASE
                    WHEN status IN (
                        'validated',
                        'processed'
                    )
                    THEN 1
                    ELSE 0
                END
            ) AS validated_count,

            SUM(
                CASE
                    WHEN status = 'returned'
                    THEN 1
                    ELSE 0
                END
            ) AS returned_count,

            SUM(
                CASE
                    WHEN status = 'processed'
                    THEN 1
                    ELSE 0
                END
            ) AS processed_count,

            MIN(inspection_date)
                AS minimum_inspection_date,

            MAX(inspection_date)
                AS maximum_inspection_date

         FROM assessments

         WHERE period_id = :period_id"
    );

    $assessmentSummaryStatement->execute([
        'period_id' => $periodId,
    ]);

    $assessmentSummary =
        $assessmentSummaryStatement->fetch();

    if (is_array($assessmentSummary)) {
        $periodStatistics['assessment_count'] =
            (int) (
                $assessmentSummary[
                    'assessment_count'
                ] ?? 0
            );

        $periodStatistics['draft_count'] =
            (int) (
                $assessmentSummary[
                    'draft_count'
                ] ?? 0
            );

        $periodStatistics['pending_validation_count'] =
            (int) (
                $assessmentSummary[
                    'pending_validation_count'
                ] ?? 0
            );

        $periodStatistics['validated_count'] =
            (int) (
                $assessmentSummary[
                    'validated_count'
                ] ?? 0
            );

        $periodStatistics['returned_count'] =
            (int) (
                $assessmentSummary[
                    'returned_count'
                ] ?? 0
            );

        $periodStatistics['processed_count'] =
            (int) (
                $assessmentSummary[
                    'processed_count'
                ] ?? 0
            );

        $periodStatistics['minimum_inspection_date'] =
            $assessmentSummary[
                'minimum_inspection_date'
            ] ?? null;

        $periodStatistics['maximum_inspection_date'] =
            $assessmentSummary[
                'maximum_inspection_date'
            ] ?? null;
    }

    // Hitung Hasil TOPSIS

    $topsisCountStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM topsis_results
         WHERE period_id = :period_id'
    );

    $topsisCountStatement->execute([
        'period_id' => $periodId,
    ]);

    $periodStatistics['topsis_result_count'] =
        (int) $topsisCountStatement->fetchColumn();
} catch (Throwable $exception) {
    error_log(
        'Statistik ubah periode SPK PT CISAS gagal: '
        . $exception->getMessage()
    );

    flash_error(
        'Statistik periode belum dapat dimuat dengan lengkap.'
    );
}

// Periksa Periode Aktif Lain

$otherActivePeriod = null;

try {
    $otherActivePeriodStatement = $pdo->prepare(
        "SELECT
            id,
            name,
            start_date,
            end_date

         FROM assessment_periods

         WHERE status = 'active'
           AND id <> :id

         ORDER BY id DESC

         LIMIT 1"
    );

    $otherActivePeriodStatement->execute([
        'id' => $periodId,
    ]);

    $otherActivePeriodData =
        $otherActivePeriodStatement->fetch();

    $otherActivePeriod = is_array(
        $otherActivePeriodData
    )
        ? $otherActivePeriodData
        : null;
} catch (Throwable $exception) {
    error_log(
        'Pemeriksaan periode aktif lain gagal: '
        . $exception->getMessage()
    );
}

// Kesiapan Menutup Periode

$periodCanBeCompleted =
    $periodStatistics['assessment_count']
        === $expectedMachineCount

    && $periodStatistics['processed_count']
        === $expectedMachineCount

    && $periodStatistics['topsis_result_count']
        === $expectedMachineCount

    && $periodStatistics['draft_count'] === 0

    && $periodStatistics[
        'pending_validation_count'
    ] === 0

    && $periodStatistics['returned_count'] === 0;

// Fungsi Validasi Tanggal

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

// Nama Token CSRF

$csrfFormName =
    'period_edit_' . $periodId;

// Proses Form Perubahan Periode

if (is_post()) {
    // Ambil Input

    $name = input_string(
        $_POST,
        'name',
        ''
    ) ?? '';

    $name = preg_replace(
        '/\s+/u',
        ' ',
        trim($name)
    ) ?? trim($name);

    $startDate = input_string(
        $_POST,
        'start_date',
        ''
    ) ?? '';

    $endDate = input_string(
        $_POST,
        'end_date',
        ''
    ) ?? '';

    $status = input_string(
        $_POST,
        'status',
        ''
    ) ?? '';

    // Periksa Token CSRF

    if (!csrf_validate_request($csrfFormName)) {
        flash_error(
            'Token keamanan tidak valid atau telah kedaluwarsa. '
            . 'Silakan isi kembali formulir.'
        );

        flash_set_old_input([
            'name' => $name,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $status,
        ]);

        redirect(
            'periode/edit.php?id='
            . $periodId
        );
    }

    // Validasi Input Dasar

    $errors = [];

    validation_add_error(
        $errors,
        'name',
        validation_text(
            $name,
            'Nama periode',
            3,
            100,
            true
        )
    );

    validation_add_error(
        $errors,
        'start_date',
        $validateDate(
            $startDate,
            'Tanggal mulai'
        )
    );

    validation_add_error(
        $errors,
        'end_date',
        $validateDate(
            $endDate,
            'Tanggal selesai'
        )
    );

    validation_add_error(
        $errors,
        'status',
        validation_choice(
            $status,
            'Status periode',
            [
                'draft',
                'active',
                'completed',
            ],
            true
        )
    );

    // Validasi Urutan Tanggal

    if (
        validation_first_error(
            $errors,
            'start_date'
        ) === null

        && validation_first_error(
            $errors,
            'end_date'
        ) === null

        && $endDate < $startDate
    ) {
        validation_add_error(
            $errors,
            'end_date',
            'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.'
        );
    }

    // Periksa Nama Periode Duplikat

    if (
        validation_first_error(
            $errors,
            'name'
        ) === null
    ) {
        $duplicateNameStatement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM assessment_periods
             WHERE name = :name COLLATE NOCASE
               AND id <> :id"
        );

        $duplicateNameStatement->execute([
            'name' => $name,
            'id' => $periodId,
        ]);

        if (
            (int) $duplicateNameStatement
                ->fetchColumn() > 0
        ) {
            validation_add_error(
                $errors,
                'name',
                'Nama periode sudah digunakan oleh periode lain.'
            );
        }
    }

    // Pastikan Data Master Motor Siap

    if (
        $status === 'active'
        && $currentPeriodStatus !== 'active'
    ) {
        $machineReadiness = machine_master_readiness($pdo);

        if (!$machineReadiness['ready']) {
            validation_add_error(
                $errors,
                'status',
                'Periode belum dapat diaktifkan karena data master motor belum lengkap: '
                . implode(
                    '; ',
                    array_slice(
                        $machineReadiness['missing'],
                        0,
                        6
                    )
                )
                . '.'
            );
        }
    }

    // Pastikan Hanya Satu Periode Aktif

    if (
        $status === 'active'
        && $otherActivePeriod !== null
    ) {
        validation_add_error(
            $errors,
            'status',
            'Periode tidak dapat diaktifkan karena masih terdapat '
            . 'periode aktif lain, yaitu '
            . (string) $otherActivePeriod['name']
            . '.'
        );
    }

    // Periode Aktif dengan Pemeriksaan Tidak Kembali Menjadi Draft

    if (
        $currentPeriodStatus === 'active'
        && $status === 'draft'
        && $periodStatistics['assessment_count'] > 0
    ) {
        validation_add_error(
            $errors,
            'status',
            'Periode aktif yang sudah mempunyai data pemeriksaan '
            . 'tidak dapat dikembalikan menjadi Draft.'
        );
    }

    // Pastikan Tanggal Pemeriksaan Masuk dalam Rentang Periode

    $minimumInspectionDate =
        $periodStatistics[
            'minimum_inspection_date'
        ];

    $maximumInspectionDate =
        $periodStatistics[
            'maximum_inspection_date'
        ];

    if (
        validation_first_error(
            $errors,
            'start_date'
        ) === null

        && is_string($minimumInspectionDate)

        && $minimumInspectionDate !== ''

        && $startDate > $minimumInspectionDate
    ) {
        validation_add_error(
            $errors,
            'start_date',
            'Tanggal mulai tidak boleh melewati tanggal pemeriksaan '
            . 'pertama, yaitu '
            . format_date_id($minimumInspectionDate)
            . '.'
        );
    }

    if (
        validation_first_error(
            $errors,
            'end_date'
        ) === null

        && is_string($maximumInspectionDate)

        && $maximumInspectionDate !== ''

        && $endDate < $maximumInspectionDate
    ) {
        validation_add_error(
            $errors,
            'end_date',
            'Tanggal selesai tidak boleh lebih awal dari tanggal '
            . 'pemeriksaan terakhir, yaitu '
            . format_date_id($maximumInspectionDate)
            . '.'
        );
    }

    // Syarat Menutup Periode

    if (
        $status === 'completed'
        && !$periodCanBeCompleted
    ) {
        validation_add_error(
            $errors,
            'status',
            'Periode belum dapat diselesaikan. '
            . 'Enam pemeriksaan harus sudah diproses TOPSIS TOPSIS '
            . 'dan enam hasil peringkat harus tersedia.'
        );
    }

    // Kembali ke Form apabila Validasi Gagal

    if (validation_has_errors($errors)) {
        flash_set_errors($errors);

        flash_set_old_input([
            'name' => $name,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $status,
        ]);

        flash_error(
            'Perubahan periode belum dapat disimpan. '
            . 'Periksa kembali formulir.'
        );

        redirect(
            'periode/edit.php?id='
            . $periodId
        );
    }

    // Simpan Perubahan Menggunakan Transaksi

    $ownsTransaction =
        !$pdo->inTransaction();

    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        // Periksa Ulang Data Periode

        $latestPeriodStatement = $pdo->prepare(
            "SELECT
                id,
                status

             FROM assessment_periods

             WHERE id = :id

             LIMIT 1"
        );

        $latestPeriodStatement->execute([
            'id' => $periodId,
        ]);

        $latestPeriod =
            $latestPeriodStatement->fetch();

        if (!is_array($latestPeriod)) {
            throw new RuntimeException(
                'Periode pemeriksaan tidak ditemukan.'
            );
        }

        if (
            (string) $latestPeriod['status']
            === 'completed'
        ) {
            throw new RuntimeException(
                'Periode sudah selesai dan tidak dapat diubah.'
            );
        }

        $latestPeriodStatus = (string) $latestPeriod['status'];

        WorkflowService::assertTransition(
            'period',
            $latestPeriodStatus,
            $status
        );

        // Periksa Ulang Periode Aktif Lain

        if ($status === 'active') {
            $machineReadiness = machine_master_readiness($pdo);

            if (!$machineReadiness['ready']) {
                throw new RuntimeException(
                    'Data master A1 sampai A6 belum lengkap.'
                );
            }

            $activePeriodStatement = $pdo->prepare(
                "SELECT
                    id,
                    name

                 FROM assessment_periods

                 WHERE status = 'active'
                   AND id <> :id

                 LIMIT 1"
            );

            $activePeriodStatement->execute([
                'id' => $periodId,
            ]);

            $activePeriod =
                $activePeriodStatement->fetch();

            if (is_array($activePeriod)) {
                throw new RuntimeException(
                    'Masih terdapat periode aktif lain.'
                );
            }
        }

        // Periksa Ulang Kesiapan TOPSIS ketika Ditutup

        if ($status === 'completed') {
            $completionStatement = $pdo->prepare(
                "SELECT
                    (
                        SELECT COUNT(*)
                        FROM assessments
                        WHERE period_id = :assessment_period_id
                          AND status = 'processed'
                    ) AS processed_count,

                    (
                        SELECT COUNT(*)
                        FROM topsis_results
                        WHERE period_id = :topsis_period_id
                    ) AS topsis_count"
            );

            $completionStatement->execute([
                'assessment_period_id' =>
                    $periodId,

                'topsis_period_id' =>
                    $periodId,
            ]);

            $completionData =
                $completionStatement->fetch();

            $processedCount = is_array(
                $completionData
            )
                ? (int) (
                    $completionData[
                        'processed_count'
                    ] ?? 0
                )
                : 0;

            $topsisCount = is_array(
                $completionData
            )
                ? (int) (
                    $completionData[
                        'topsis_count'
                    ] ?? 0
                )
                : 0;

            if (
                $processedCount
                    !== $expectedMachineCount

                || $topsisCount
                    !== $expectedMachineCount
            ) {
                throw new RuntimeException(
                    'Hasil TOPSIS periode belum lengkap.'
                );
            }
        }

        // Perbarui Periode

        $updateStatement = $pdo->prepare(
            "UPDATE assessment_periods

             SET
                name = :name,
                start_date = :start_date,
                end_date = :end_date,
                status = :status,
                updated_at = datetime('now', '+7 hours')

             WHERE id = :id"
        );

        $updateStatement->execute([
            'name' => $name,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $status,
            'id' => $periodId,
        ]);

        audit_log(
            $pdo,
            $currentUserId,
            'period_updated',
            'assessment_period',
            $periodId,
            'Administrator memperbarui periode pemeriksaan ' . $name . '.',
            [
                'name' => $period['name'] ?? null,
                'start_date' => $period['start_date'] ?? null,
                'end_date' => $period['end_date'] ?? null,
                'status' => $currentPeriodStatus,
            ],
            [
                'name' => $name,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $status,
            ]
        );

        if ($ownsTransaction) {
            $pdo->commit();
        }

        $successMessage =
            $status === 'completed'
                ? 'Periode '
                    . $name
                    . ' berhasil diselesaikan.'
                : 'Periode '
                    . $name
                    . ' berhasil diperbarui.';

        flash_success(
            $successMessage
        );

        redirect(
            'periode/detail.php?id='
            . $periodId
        );
    } catch (Throwable $exception) {
        if (
            $ownsTransaction
            && $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        error_log(
            'Ubah periode SPK PT CISAS gagal: '
            . $exception->getMessage()
        );

        $databaseErrors = [];

        $exceptionMessage = strtolower(
            $exception->getMessage()
        );

        // Nama Periode Duplikat

        if (
            $exception instanceof PDOException
            && (
                $exception->getCode() === '23000'

                || str_contains(
                    $exceptionMessage,
                    'unique'
                )
            )
        ) {
            validation_add_error(
                $databaseErrors,
                'name',
                'Nama periode sudah digunakan oleh periode lain.'
            );
        }

        // Periode Aktif Lain

        if (
            str_contains(
                $exceptionMessage,
                'periode aktif lain'
            )
        ) {
            validation_add_error(
                $databaseErrors,
                'status',
                'Periode tidak dapat diaktifkan karena '
                . 'masih terdapat periode aktif lain.'
            );
        }

        // TOPSIS Belum Lengkap

        if (
            str_contains(
                $exceptionMessage,
                'topsis periode belum lengkap'
            )
        ) {
            validation_add_error(
                $databaseErrors,
                'status',
                'Periode belum dapat diselesaikan karena '
                . 'hasil TOPSIS belum lengkap.'
            );
        }

        if (
            validation_has_errors(
                $databaseErrors
            )
        ) {
            flash_set_errors(
                $databaseErrors
            );
        }

        flash_set_old_input([
            'name' => $name,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $status,
        ]);

        flash_error(
            validation_has_errors(
                $databaseErrors
            )
                ? 'Perubahan periode belum dapat disimpan. '
                    . 'Periksa kembali formulir.'
                : 'Terjadi gangguan saat menyimpan perubahan periode. '
                    . 'Silakan coba kembali.'
        );

        redirect(
            'periode/edit.php?id='
            . $periodId
        );
    }
}

// Ambil Error dan Input Lama

$errors = flash_get_errors();

$oldInput = flash_get_old_input();

$nameValue = input_string(
    $oldInput,
    'name',
    (string) (
        $period['name']
        ?? ''
    )
) ?? (string) (
    $period['name']
    ?? ''
);

$startDateValue = input_string(
    $oldInput,
    'start_date',
    (string) (
        $period['start_date']
        ?? ''
    )
) ?? (string) (
    $period['start_date']
    ?? ''
);

$endDateValue = input_string(
    $oldInput,
    'end_date',
    (string) (
        $period['end_date']
        ?? ''
    )
) ?? (string) (
    $period['end_date']
    ?? ''
);

$statusValue = input_string(
    $oldInput,
    'status',
    $currentPeriodStatus
) ?? $currentPeriodStatus;

// Pastikan Nilai Status Form Valid

if (
    !in_array(
        $statusValue,
        [
            'draft',
            'active',
            'completed',
        ],
        true
    )
) {
    $statusValue =
        $currentPeriodStatus;
}

// Progres Periode

$assessmentProgress =
    $expectedMachineCount > 0
        ? (int) round(
            (
                $periodStatistics[
                    'assessment_count'
                ]
                / $expectedMachineCount
            ) * 100
        )
        : 0;

$assessmentProgress = max(
    0,
    min(
        100,
        $assessmentProgress
    )
);

$topsisProgress =
    $expectedMachineCount > 0
        ? (int) round(
            (
                $periodStatistics[
                    'topsis_result_count'
                ]
                / $expectedMachineCount
            ) * 100
        )
        : 0;

$topsisProgress = max(
    0,
    min(
        100,
        $topsisProgress
    )
);

// Konfigurasi Halaman

$pageTitle = 'Ubah Periode';

$pageDescription =
    'Perubahan nama, rentang tanggal, dan status periode '
    . 'pemeriksaan pada Sistem SPK PT CISAS.';

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

        <!-- Judul halaman -->
        <section class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="badge <?= $currentPeriodStatus === 'active' ? 'text-bg-success' : 'text-bg-secondary' ?> p-2">
                        <?= $currentPeriodStatus === 'active'
                            ? 'Aktif'
                            : 'Draft' ?>
                    </span>

                    <span class="badge text-bg-light border p-2">
                        ID Periode: <?= e($periodId) ?>
                    </span>
                </div>

                <h1 class="page-title">
                    Ubah Periode Pemeriksaan
                </h1>

                <p class="page-description">
                    Perbarui identitas dan rentang tanggal. Status periode mengikuti alur Draft, Aktif, lalu Selesai.
                </p>
            </div>

            <div class="page-header-actions">
                <a href="<?= e( base_url( 'periode/detail.php?id=' . $periodId ) ) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>
            </div>
        </section>

        <?php if ($otherActivePeriod !== null): ?>
            <div class="alert alert-warning mb-4" role="status">
                <strong class="d-block mb-1">
                    Terdapat periode aktif lain
                </strong>

                Periode ini tidak dapat diaktifkan selama

                <strong>
                    <?= e(
                        $otherActivePeriod['name']
                    ) ?>
                </strong>

                masih berstatus Aktif.
            </div>
        <?php elseif (
            $currentPeriodStatus === 'active'
        ): ?>
            <div class="alert alert-success mb-4" role="status">
                Periode ini sedang aktif dan dapat digunakan
                Teknisi untuk melakukan pemeriksaan motor.
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-4" role="status">
                Periode masih berstatus Draft. Aktifkan periode
                ketika pemeriksaan A1 sampai A6 akan dimulai.
            </div>
        <?php endif; ?>

        <div class="row g-4">

            <!-- Form perubahan periode -->
            <div class="col-xl-8">
                <section class="card border-0 shadow-sm" aria-labelledby="period-edit-form-title">
                    <div class="card-header bg-white py-3">
                        <h2 id="period-edit-form-title" class="h5 mb-1">
                            Form Perubahan Periode
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Kolom bertanda bintang wajib diisi.
                        </p>
                    </div>

                    <div class="card-body p-4">
                        <form method="post" action="<?= e( base_url( 'periode/edit.php?id=' . $periodId ) ) ?>">
                            <?= csrf_field(
                                $csrfFormName
                            ) ?>

                            <div class="row g-3">

                                <!-- Nama periode -->
                                <div class="col-12">
                                    <label for="name" class="form-label required-label">
                                        Nama Periode
                                    </label>

                                    <input type="text" id="name" name="name" class="form-control <?= e( validation_class( $errors, 'name' ) ) ?>" value="<?= e( $nameValue ) ?>" minlength="3" maxlength="100" required autofocus>

                                    <?php
                                    $nameError =
                                        validation_first_error(
                                            $errors,
                                            'name'
                                        );
                                    ?>

                                    <?php if (
                                        $nameError !== null
                                    ): ?>
                                        <div class="invalid-feedback">
                                            <?= e($nameError) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Tanggal mulai -->
                                <div class="col-md-6">
                                    <label for="start_date" class="form-label required-label">
                                        Tanggal Mulai
                                    </label>

                                    <input type="date" id="start_date" name="start_date" class="form-control <?= e( validation_class( $errors, 'start_date' ) ) ?>" value="<?= e( $startDateValue ) ?>" required>

                                    <?php
                                    $startDateError =
                                        validation_first_error(
                                            $errors,
                                            'start_date'
                                        );
                                    ?>

                                    <?php if (
                                        $startDateError !== null
                                    ): ?>
                                        <div class="invalid-feedback">
                                            <?= e(
                                                $startDateError
                                            ) ?>
                                        </div>
                                    <?php elseif (
                                        is_string(
                                            $periodStatistics[
                                                'minimum_inspection_date'
                                            ]
                                        )
                                    ): ?>
                                        <div class="form-text">
                                            Pemeriksaan pertama:
                                            <?= e(
                                                format_date_id(
                                                    $periodStatistics[
                                                        'minimum_inspection_date'
                                                    ]
                                                )
                                            ) ?>.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Tanggal selesai -->
                                <div class="col-md-6">
                                    <label for="end_date" class="form-label required-label">
                                        Tanggal Selesai
                                    </label>

                                    <input type="date" id="end_date" name="end_date" class="form-control <?= e( validation_class( $errors, 'end_date' ) ) ?>" value="<?= e( $endDateValue ) ?>" required>

                                    <?php
                                    $endDateError =
                                        validation_first_error(
                                            $errors,
                                            'end_date'
                                        );
                                    ?>

                                    <?php if (
                                        $endDateError !== null
                                    ): ?>
                                        <div class="invalid-feedback">
                                            <?= e(
                                                $endDateError
                                            ) ?>
                                        </div>
                                    <?php elseif (
                                        is_string(
                                            $periodStatistics[
                                                'maximum_inspection_date'
                                            ]
                                        )
                                    ): ?>
                                        <div class="form-text">
                                            Pemeriksaan terakhir:
                                            <?= e(
                                                format_date_id(
                                                    $periodStatistics[
                                                        'maximum_inspection_date'
                                                    ]
                                                )
                                            ) ?>.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Status periode -->
                                <div class="col-md-7">
                                    <label for="status" class="form-label required-label">
                                        Status Periode
                                    </label>

                                    <select id="status" name="status" class="form-select <?= e( validation_class( $errors, 'status' ) ) ?>" required>
                                        <?php if (
                                            $currentPeriodStatus === 'draft'
                                        ): ?>
                                            <option value="draft" <?= selected( $statusValue, 'draft' ) ?>>
                                                Draft
                                            </option>
                                        <?php endif; ?>

                                        <option value="active" <?= selected( $statusValue, 'active' ) ?> <?= $otherActivePeriod !== null ? 'disabled' : '' ?>>
                                            Aktif
                                        </option>

                                        <?php if (
                                            $currentPeriodStatus === 'active'
                                        ): ?>
                                            <option value="completed" <?= selected( $statusValue, 'completed' ) ?> <?= !$periodCanBeCompleted ? 'disabled' : '' ?>>
                                                Selesai
                                            </option>
                                        <?php endif; ?>
                                    </select>

                                    <?php
                                    $statusError =
                                        validation_first_error(
                                            $errors,
                                            'status'
                                        );
                                    ?>

                                    <?php if (
                                        $statusError !== null
                                    ): ?>
                                        <div class="invalid-feedback">
                                            <?= e(
                                                $statusError
                                            ) ?>
                                        </div>
                                    <?php elseif (
                                        !$periodCanBeCompleted
                                    ): ?>
                                        <div class="form-text">
                                            Status Selesai tersedia
                                            setelah enam pemeriksaan dan
                                            enam hasil TOPSIS lengkap.
                                        </div>
                                    <?php else: ?>
                                        <div class="form-text text-success">
                                            Periode sudah memenuhi
                                            persyaratan untuk diselesaikan.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Jumlah alternatif -->
                                <div class="col-md-5">
                                    <label for="alternative_count" class="form-label">
                                        Alternatif
                                    </label>

                                    <input type="text" id="alternative_count" class="form-control" value="6 motor — A1 sampai A6" readonly>
                                </div>

                            </div>

                            <div class="d-flex flex-column flex-sm-row justify-content-end gap-2 mt-4">
                                <button type="submit" class="btn btn-primary" data-loading-text="Menyimpan...">
                                    Simpan Perubahan
                                </button>
                            </div>
                        </form>
                    </div>
                </section>
            </div>

            <!-- Ringkasan periode -->
            <div class="col-xl-4">
                <aside class="card border-0 shadow-sm mb-4" aria-labelledby="period-progress-summary-title">
                    <div class="card-body p-4">
                        <h2 id="period-progress-summary-title" class="h5 mb-3">
                            Progres Periode
                        </h2>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between small mb-2">
                                <span>
                                    Pemeriksaan dibuat
                                </span>

                                <strong>
                                    <?= e(
                                        $periodStatistics[
                                            'assessment_count'
                                        ]
                                    ) ?>
                                    /
                                    <?= e(
                                        $expectedMachineCount
                                    ) ?>
                                </strong>
                            </div>

                            <div class="progress" role="progressbar" aria-label="Progres pemeriksaan" aria-valuenow="<?= e( $assessmentProgress ) ?>" aria-valuemin="0" aria-valuemax="100" style="height: 12px;">
                                <div
                                    class="progress-bar"
                                    style="width: <?= e(
                                        $assessmentProgress
                                    ) ?>%;"
                                ></div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between small mb-2">
                                <span>
                                    Hasil TOPSIS
                                </span>

                                <strong>
                                    <?= e(
                                        $periodStatistics[
                                            'topsis_result_count'
                                        ]
                                    ) ?>
                                    /
                                    <?= e(
                                        $expectedMachineCount
                                    ) ?>
                                </strong>
                            </div>

                            <div class="progress" role="progressbar" aria-label="Progres hasil TOPSIS" aria-valuenow="<?= e( $topsisProgress ) ?>" aria-valuemin="0" aria-valuemax="100" style="height: 12px;">
                                <div
                                    class="progress-bar bg-success"
                                    style="width: <?= e(
                                        $topsisProgress
                                    ) ?>%;"
                                ></div>
                            </div>
                        </div>

                        <dl class="row mb-0">
                            <dt class="col-7">
                                Draft
                            </dt>

                            <dd class="col-5 text-end">
                                <?= e(
                                    $periodStatistics[
                                        'draft_count'
                                    ]
                                ) ?>
                            </dd>

                            <dt class="col-7">
                                Menunggu validasi
                            </dt>

                            <dd class="col-5 text-end">
                                <?= e(
                                    $periodStatistics[
                                        'pending_validation_count'
                                    ]
                                ) ?>
                            </dd>

                            <dt class="col-7">
                                Disetujui
                            </dt>

                            <dd class="col-5 text-end">
                                <?= e(
                                    $periodStatistics[
                                        'validated_count'
                                    ]
                                ) ?>
                            </dd>

                            <dt class="col-7">
                                Dikembalikan
                            </dt>

                            <dd class="col-5 text-end">
                                <?= e(
                                    $periodStatistics[
                                        'returned_count'
                                    ]
                                ) ?>
                            </dd>

                            <dt class="col-7">
                                Diproses TOPSIS
                            </dt>

                            <dd class="col-5 text-end mb-0">
                                <?= e(
                                    $periodStatistics[
                                        'processed_count'
                                    ]
                                ) ?>
                            </dd>
                        </dl>
                    </div>
                </aside>

                <aside class="card border-0 shadow-sm" aria-labelledby="period-status-guide-title">
                    <div class="card-body p-4">
                        <h2 id="period-status-guide-title" class="h5 mb-3">
                            Aturan Status
                        </h2>

                        <div class="mb-3">
                            <span class="badge text-bg-secondary mb-2">
                                Draft
                            </span>

                            <p class="small text-body-secondary mb-0">
                                Periode masih disiapkan dan belum
                                digunakan Teknisi.
                            </p>
                        </div>

                        <div class="mb-3">
                            <span class="badge text-bg-success mb-2">
                                Aktif
                            </span>

                            <p class="small text-body-secondary mb-0">
                                Periode dapat digunakan untuk
                                pemeriksaan A1 sampai A6.
                            </p>
                        </div>

                        <div>
                            <span class="badge text-bg-primary mb-2">
                                Selesai
                            </span>

                            <p class="small text-body-secondary mb-0">
                                Periode berstatus Selesai setelah enam hasil
                                TOPSIS tersedia dan tidak dapat
                                diubah kembali.
                            </p>
                        </div>

                        <?php if (!$periodCanBeCompleted): ?>
                            <hr class="my-4">

                            <div class="alert alert-warning mb-0">
                                Periode belum memenuhi seluruh
                                persyaratan untuk diselesaikan.
                            </div>
                        <?php endif; ?>
                    </div>
                </aside>
            </div>

        </div>

        <p class="page-meta-card mt-3 mb-0">
            Periode dibuat:

            <?= e(
                format_date_id(
                    $period['created_at']
                    ?? null,
                    true
                )
            ) ?>

            &middot;

            terakhir diperbarui:

            <?= e(
                format_date_id(
                    $period['updated_at']
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

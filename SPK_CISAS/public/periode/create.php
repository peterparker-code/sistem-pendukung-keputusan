<?php

declare(strict_types=1);

/*
 * Tambah Periode Pemeriksaan Sistem SPK PT CISAS
 * Halaman ini hanya dapat diakses oleh Administrator.
 * Fungsinya:
 * - menampilkan formulir tambah periode;
 * - memvalidasi nama, tanggal mulai, tanggal selesai, dan status;
 * - memastikan tanggal selesai tidak lebih awal dari tanggal mulai;
 * - mencegah nama periode duplikat;
 * - memastikan hanya satu periode yang berstatus aktif;
 * - menyimpan periode baru ke assessment_periods.
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

// Identitas Administrator

$currentUserId = auth_id();

if (
    $currentUserId === null
    || $currentUserId < 1
) {
    flash_error(
        'Sesi Administrator tidak valid. Silakan masuk kembali.'
    );

    redirect('login.php');
}

/*
 * Fungsi Validasi Tanggal ISO
 * Format yang diterima: YYYY-MM-DD.
 */

$validateDate = static function (
    string $value,
    string $label
): ?string {
    $value = trim($value);

    if ($value === '') {
        return $label . ' wajib diisi.';
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $value
    );

    $dateErrors = DateTimeImmutable::getLastErrors();

    $hasDateErrors = is_array($dateErrors)
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
        || $hasDateErrors
        || $date->format('Y-m-d') !== $value
    ) {
        return $label . ' tidak valid.';
    }

    return null;
};

// Proses Form Tambah Periode

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
        'draft'
    ) ?? 'draft';

    // Periksa Token CSRF

    if (!csrf_validate_request('period_create')) {
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

        redirect('periode/create.php');
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

             WHERE LOWER(TRIM(name))
                   = LOWER(TRIM(:name))"
        );

        $duplicateNameStatement->execute([
            'name' => $name,
        ]);

        $duplicateNameCount = (int)
            $duplicateNameStatement
                ->fetchColumn();

        if ($duplicateNameCount > 0) {
            validation_add_error(
                $errors,
                'name',
                'Nama periode sudah digunakan.'
            );
        }
    }

    // Pastikan Data Master Motor Siap

    if (
        $status === 'active'
        && validation_first_error(
            $errors,
            'status'
        ) === null
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

        && validation_first_error(
            $errors,
            'status'
        ) === null
    ) {
        $activePeriodCount = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM assessment_periods
             WHERE status = 'active'"
        )->fetchColumn();

        if ($activePeriodCount > 0) {
            validation_add_error(
                $errors,
                'status',
                'Masih terdapat periode aktif. '
                . 'Selesaikan atau ubah periode tersebut terlebih dahulu.'
            );
        }
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
            'Periode pemeriksaan belum dapat disimpan. '
            . 'Periksa kembali formulir.'
        );

        redirect('periode/create.php');
    }

    // Simpan Periode

    try {
        $ownsTransaction =
            !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        // Periksa Ulang Periode Aktif di Dalam Transaksi

        if ($status === 'active') {
            $machineReadiness = machine_master_readiness($pdo);

            if (!$machineReadiness['ready']) {
                throw new RuntimeException(
                    'Data master A1 sampai A6 belum lengkap.'
                );
            }

            $activePeriodCount = (int) $pdo->query(
                "SELECT COUNT(*)
                 FROM assessment_periods
                 WHERE status = 'active'"
            )->fetchColumn();

            if ($activePeriodCount > 0) {
                throw new RuntimeException(
                    'Masih terdapat periode pemeriksaan aktif.'
                );
            }
        }

        // Simpan Periode Baru

        $insertStatement = $pdo->prepare(
            "INSERT INTO assessment_periods (
                name,
                start_date,
                end_date,
                status,
                created_by,
                created_at,
                updated_at
             ) VALUES (
                :name,
                :start_date,
                :end_date,
                :status,
                :created_by,
                datetime('now', '+7 hours'),
                datetime('now', '+7 hours')
             )"
        );

        $insertStatement->execute([
            'name' => $name,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $status,
            'created_by' => $currentUserId,
        ]);

        $periodId = (int) $pdo->lastInsertId();

        if ($periodId < 1) {
            throw new RuntimeException(
                'ID periode baru tidak berhasil dibuat.'
            );
        }

        audit_log(
            $pdo,
            $currentUserId,
            'period_created',
            'assessment_period',
            $periodId,
            'Administrator membuat periode pemeriksaan ' . $name . '.',
            null,
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

        flash_success(
            'Periode '
            . $name
            . ' berhasil ditambahkan.'
        );

        redirect(
            'periode/detail.php?id='
            . $periodId
        );
    } catch (Throwable $exception) {
        if (
            isset($ownsTransaction)
            && $ownsTransaction
            && $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        error_log(
            'Tambah periode SPK PT CISAS gagal: '
            . $exception->getMessage()
        );

        $databaseErrors = [];

        $exceptionMessage = strtolower(
            $exception->getMessage()
        );

        /*
 * Kesalahan Nama Duplikat
 * Jangan menggunakan seluruh kode SQLSTATE 23000 sebagai penanda
 * nama duplikat karena kode tersebut juga dapat berasal dari
 * constraint lain seperti NOT NULL atau foreign key.
 */

        $isDuplicateNameError =
            $exception instanceof PDOException
            && str_contains(
                $exceptionMessage,
                'unique constraint failed'
            )
            && str_contains(
                $exceptionMessage,
                'assessment_periods.name'
            );

        if ($isDuplicateNameError) {
            validation_add_error(
                $databaseErrors,
                'name',
                'Nama periode sudah digunakan.'
            );
        }

        // Kesalahan Periode Aktif dari Pemeriksaan Aplikasi

        if (
            str_contains(
                $exceptionMessage,
                'periode pemeriksaan aktif'
            )
        ) {
            validation_add_error(
                $databaseErrors,
                'status',
                'Masih terdapat periode aktif. '
                . 'Selesaikan atau ubah periode tersebut terlebih dahulu.'
            );
        }

        // Kesalahan Constraint Satu Periode Aktif

        $isActivePeriodConstraintError =
            $exception instanceof PDOException
            && str_contains(
                $exceptionMessage,
                'unique constraint failed'
            )
            && (
                str_contains(
                    $exceptionMessage,
                    'assessment_periods.status'
                )
                || str_contains(
                    $exceptionMessage,
                    'active_period'
                )
            );

        if ($isActivePeriodConstraintError) {
            validation_add_error(
                $databaseErrors,
                'status',
                'Masih terdapat periode aktif. '
                . 'Selesaikan atau ubah periode tersebut terlebih dahulu.'
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
                ? 'Periode pemeriksaan belum dapat disimpan. '
                    . 'Periksa kembali formulir.'
                : 'Terjadi gangguan saat menyimpan periode. '
                    . 'Silakan coba kembali.'
        );

        redirect('periode/create.php');
    }
}

// Ambil Error dan Input Lama

$errors = flash_get_errors();

$oldInput = flash_get_old_input();

$nameValue = input_string(
    $oldInput,
    'name',
    ''
) ?? '';

$startDateValue = input_string(
    $oldInput,
    'start_date',
    date('Y-m-d')
) ?? date('Y-m-d');

$endDateValue = input_string(
    $oldInput,
    'end_date',
    date('Y-m-d')
) ?? date('Y-m-d');

$statusValue = input_string(
    $oldInput,
    'status',
    'draft'
) ?? 'draft';

// Informasi Periode Aktif Saat Ini

$currentActivePeriod = null;

try {
    $activePeriodStatement = $pdo->query(
        "SELECT
            id,
            name,
            start_date,
            end_date

         FROM assessment_periods

         WHERE status = 'active'

         ORDER BY id DESC

         LIMIT 1"
    );

    $activePeriodData =
        $activePeriodStatement->fetch();

    $currentActivePeriod = is_array(
        $activePeriodData
    )
        ? $activePeriodData
        : null;
} catch (Throwable $exception) {
    error_log(
        'Pemeriksaan periode aktif gagal: '
        . $exception->getMessage()
    );
}

// Jika Sudah Ada Periode Aktif, Form Kembali ke Draft

if (
    $currentActivePeriod !== null
    && $statusValue === 'active'
) {
    $statusValue = 'draft';
}

// Konfigurasi Halaman

$pageTitle = 'Tambah Periode';

$pageDescription =
    'Penambahan periode pemeriksaan enam motor mesin mixer '
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

        <!-- Judul halaman -->
        <section class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <h1 class="page-title">
                    Tambah Periode Pemeriksaan
                </h1>

                <p class="page-description">
                    Buat siklus pemeriksaan baru untuk
                    alternatif A1 sampai A6.
                </p>
            </div>

            <a href="<?= e( base_url( 'periode/index.php' ) ) ?>" class="btn btn-outline-secondary">
                Kembali
            </a>
        </section>

        <?php if (
            $currentActivePeriod !== null
        ): ?>

            <div class="alert alert-warning mb-4" role="status">
                <strong class="d-block mb-1">
                    Masih terdapat periode aktif
                </strong>

                Periode

                <strong>
                    <?= e(
                        $currentActivePeriod[
                            'name'
                        ]
                    ) ?>
                </strong>

                sedang berjalan. Periode baru tetap
                dapat dibuat sebagai Draft, tetapi
                belum dapat langsung diaktifkan.
            </div>

        <?php else: ?>

            <div class="alert alert-info mb-4" role="status">
                Belum terdapat periode aktif.
                Periode baru dapat disimpan sebagai
                Draft atau langsung Aktif.
            </div>

        <?php endif; ?>

        <div class="row g-4">

            <!-- Form periode -->
            <div class="col-xl-8">
                <section class="card border-0 shadow-sm" aria-labelledby="period-form-title">
                    <div class="card-header bg-white py-3">
                        <h2 id="period-form-title" class="h5 mb-1">
                            Form Periode Baru
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Kolom bertanda bintang wajib diisi.
                        </p>
                    </div>

                    <div class="card-body p-4">
                        <form method="post" action="<?= e( base_url( 'periode/create.php' ) ) ?>">
                            <?= csrf_field(
                                'period_create'
                            ) ?>

                            <div class="row g-3">

                                <!-- Nama periode -->
                                <div class="col-12">
                                    <label for="name" class="form-label required-label">
                                        Nama Periode
                                    </label>

                                    <input type="text" id="name" name="name" class="form-control <?= e( validation_class( $errors, 'name' ) ) ?>" value="<?= e( $nameValue ) ?>" minlength="3" maxlength="100" placeholder="Contoh: Pemeriksaan Agustus 2026" required autofocus>

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
                                            <?= e(
                                                $nameError
                                            ) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="form-text">
                                            Gunakan nama yang mudah
                                            dibedakan dari periode
                                            sebelumnya.
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
                                    <?php else: ?>
                                        <div class="form-text">
                                            Tanggal selesai tidak
                                            boleh lebih awal dari
                                            tanggal mulai.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Status -->
                                <div class="col-md-6">
                                    <label for="status" class="form-label required-label">
                                        Status Awal
                                    </label>

                                    <select id="status" name="status" class="form-select <?= e( validation_class( $errors, 'status' ) ) ?>" required>
                                        <option value="draft" <?= selected( $statusValue, 'draft' ) ?>>
                                            Draft
                                        </option>

                                        <option value="active" <?= selected( $statusValue, 'active' ) ?> <?= $currentActivePeriod !== null ? 'disabled' : '' ?>>
                                            Aktif
                                        </option>
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
                                    <?php else: ?>
                                        <div class="form-text">
                                            Draft belum dapat
                                            digunakan Teknisi untuk
                                            pemeriksaan.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Jumlah alternatif -->
                                <div class="col-md-6">
                                    <label for="alternative_count" class="form-label">
                                        Jumlah Alternatif
                                    </label>

                                    <input type="text" id="alternative_count" class="form-control" value="6 motor — A1 sampai A6" readonly>

                                    <div class="form-text">
                                        Jumlah alternatif dikunci
                                        sesuai ruang lingkup penelitian.
                                    </div>
                                </div>

                            </div>

                            <!-- Tombol -->
                            <div class="d-flex flex-column flex-sm-row justify-content-end gap-2 mt-4">
                                <button type="submit" class="btn btn-primary" data-loading-text="Menyimpan...">
                                    Simpan Periode
                                </button>
                            </div>
                        </form>
                    </div>
                </section>
            </div>

            <!-- Penjelasan alur -->
            <div class="col-xl-4">
                <aside class="card border-0 shadow-sm" aria-labelledby="period-flow-title">
                    <div class="card-body p-4">
                        <h2 id="period-flow-title" class="h5 mb-3">
                            Alur Periode
                        </h2>

                        <div class="mb-3">
                            <span class="badge text-bg-secondary mb-2">
                                1. Draft
                            </span>

                            <p class="small text-body-secondary mb-0">
                                Administrator menyiapkan
                                nama dan rentang tanggal
                                periode.
                            </p>
                        </div>

                        <div class="mb-3">
                            <span class="badge text-bg-success mb-2">
                                2. Aktif
                            </span>

                            <p class="small text-body-secondary mb-0">
                                Teknisi melakukan pengukuran
                                dan checklist terhadap enam motor.
                            </p>
                        </div>

                        <div>
                            <span class="badge text-bg-primary mb-2">
                                3. Selesai
                            </span>

                            <p class="small text-body-secondary mb-0">
                                Periode ditutup setelah
                                pemeriksaan, AHP, dan TOPSIS
                                selesai diproses.
                            </p>
                        </div>

                        <hr class="my-4">

                        <div class="alert alert-info mb-0">
                            Membuat periode tidak langsung
                            membuat data pemeriksaan. Teknisi
                            tetap memeriksa setiap motor satu
                            per satu pada periode aktif.
                        </div>
                    </div>
                </aside>
            </div>

        </div>
    </main>
</div>

<?php
require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';
?>

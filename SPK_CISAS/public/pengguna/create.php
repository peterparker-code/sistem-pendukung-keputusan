<?php

declare(strict_types=1);

/*
 * Tambah Teknisi Sistem SPK PT CISAS
 * Halaman ini hanya dapat diakses oleh Administrator.
 * Fungsinya:
 * - menampilkan formulir tambah Teknisi;
 * - memvalidasi nama, username, status, dan password;
 * - memastikan username tidak duplikat;
 * - menyimpan password dalam bentuk hash;
 * - menyimpan akun Teknisi baru ke tabel users.
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

// Batasi Akses Hanya untuk Admin

auth_require_role('admin');

// Koneksi Database

$pdo = require dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'config'
    . DIRECTORY_SEPARATOR
    . 'database.php';

// Pastikan Akun Admin Masih Aktif

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun Administrator sudah tidak aktif atau tidak ditemukan.'
    );

    redirect('login.php');
}

/*
 * Peran Teknisi Baru
 * Administrator dan Supervisor merupakan akun inti yang tetap tunggal.
 * Halaman ini digunakan Admin untuk menambah akun Teknisi Maintenance baru
 * apabila jumlah teknisi PT CISAS bertambah.
 */

$role = 'technician';
$roleLabel = 'Teknisi Maintenance';

// Proses Form Tambah Teknisi

if (is_post()) {
    // Ambil Input

    $name = input_string(
        $_POST,
        'name',
        ''
    ) ?? '';

    $username = input_string(
        $_POST,
        'username',
        ''
    ) ?? '';

    $role = 'technician';

    /* Akun Teknisi baru dibuat dalam status aktif. */
    $isActiveInput = '1';

    $passwordInput = $_POST['password']
        ?? '';

    $passwordConfirmationInput =
        $_POST['password_confirmation']
        ?? '';

    $password = (
        is_scalar($passwordInput)
        || $passwordInput instanceof Stringable
    )
        ? (string) $passwordInput
        : '';

    $passwordConfirmation = (
        is_scalar($passwordConfirmationInput)
        || $passwordConfirmationInput instanceof Stringable
    )
        ? (string) $passwordConfirmationInput
        : '';

    // Periksa Token CSRF

    if (!csrf_validate_request('user_create')) {
        flash_error(
            'Token keamanan tidak valid atau telah kedaluwarsa. '
            . 'Silakan isi kembali formulir.'
        );

        flash_set_old_input([
            'name' => $name,
            'username' => $username,
            'role' => $role,
            'is_active' => $isActiveInput,
        ]);

        redirect('pengguna/create.php');
    }

    // Validasi Input

    $errors = [];

    validation_add_error(
        $errors,
        'name',
        validation_text(
            $name,
            'Nama pengguna',
            3,
            100,
            true
        )
    );

    validation_add_error(
        $errors,
        'username',
        validation_username(
            $username
        )
    );

    validation_add_error(
        $errors,
        'role',
        validation_role(
            $role
        )
    );

    validation_add_error(
        $errors,
        'is_active',
        validation_choice(
            $isActiveInput,
            'Status akun',
            [
                '1',
            ]
        )
    );

    validation_add_error(
        $errors,
        'password',
        validation_password(
            $password,
            'Password',
            8,
            true
        )
    );

    validation_add_error(
        $errors,
        'password_confirmation',
        validation_password(
            $passwordConfirmation,
            'Konfirmasi password',
            8,
            true
        )
    );

    // Cocokkan Password

    if (
        $password !== ''
        && $passwordConfirmation !== ''
        && $password !== $passwordConfirmation
    ) {
        validation_add_error(
            $errors,
            'password_confirmation',
            'Konfirmasi password tidak sama dengan password.'
        );
    }

    // Periksa Username Duplikat

    if (
        validation_first_error(
            $errors,
            'username'
        ) === null
    ) {
        $usernameStatement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM users
             WHERE username = :username COLLATE NOCASE'
        );

        $usernameStatement->execute([
            'username' => $username,
        ]);

        if (
            intval(
                $usernameStatement->fetchColumn()
            ) > 0
        ) {
            validation_add_error(
                $errors,
                'username',
                'Username sudah digunakan oleh akun lain.'
            );
        }
    }

    // Kembali ke Form apabila Validasi Gagal

    if (validation_has_errors($errors)) {
        flash_set_errors($errors);

        flash_set_old_input([
            'name' => $name,
            'username' => $username,
            'role' => $role,
            'is_active' => $isActiveInput,
        ]);

        flash_error(
            'Data pengguna belum dapat disimpan. '
            . 'Periksa kembali formulir.'
        );

        redirect('pengguna/create.php');
    }

    // Buat Hash Password

    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    if (
        !is_string($passwordHash)
        || $passwordHash === ''
    ) {
        flash_error(
            'Password tidak berhasil diamankan. '
            . 'Silakan coba kembali.'
        );

        flash_set_old_input([
            'name' => $name,
            'username' => $username,
            'role' => $role,
            'is_active' => $isActiveInput,
        ]);

        redirect('pengguna/create.php');
    }

    // Simpan Pengguna

    try {
        $pdo->beginTransaction();

        $statement = $pdo->prepare(
            "INSERT INTO users (
                name,
                username,
                password_hash,
                role,
                is_active,
                created_at,
                updated_at
             ) VALUES (
                :name,
                :username,
                :password_hash,
                :role,
                :is_active,
                datetime('now', '+7 hours'),
                datetime('now', '+7 hours')
             )"
        );

        $statement->execute([
            'name' => $name,
            'username' => $username,
            'password_hash' => $passwordHash,
            'role' => $role,
            'is_active' => intval(
                $isActiveInput
            ),
        ]);

        $createdUserId = (int) $pdo->lastInsertId();

        $pdo->commit();

        flash_success(
            'Pengguna '
            . $name
            . ' berhasil ditambahkan.'
        );

        redirect('pengguna/index.php');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Tambah pengguna SPK PT CISAS gagal: '
            . $exception->getMessage()
        );

        $databaseErrors = [];

        // Antisipasi Username Duplikat

        if (
            $exception->getCode() === '23000'
            || str_contains(
                strtolower(
                    $exception->getMessage()
                ),
                'unique'
            )
        ) {
            validation_add_error(
                $databaseErrors,
                'username',
                'Username sudah digunakan oleh akun lain.'
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
            'username' => $username,
            'role' => $role,
            'is_active' => $isActiveInput,
        ]);

        flash_error(
            validation_has_errors(
                $databaseErrors
            )
                ? 'Data pengguna belum dapat disimpan. '
                    . 'Periksa kembali username.'
                : 'Terjadi gangguan saat menyimpan pengguna. '
                    . 'Silakan coba kembali.'
        );

        redirect('pengguna/create.php');
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

$usernameValue = input_string(
    $oldInput,
    'username',
    ''
) ?? '';

$roleValue = input_string(
    $oldInput,
    'role',
    ''
) ?? '';

$isActiveValue = input_string(
    $oldInput,
    'is_active',
    '1'
) ?? '1';

// Konfigurasi Halaman

$pageTitle = 'Tambah Teknisi';

$pageDescription =
    'Tambahkan akun Teknisi Maintenance baru apabila personel maintenance bertambah.';

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
                    Tambah Teknisi
                </h1>

                <p class="page-description">
                    Buat akun Teknisi Maintenance baru untuk pemeriksaan dan pelaksanaan tindakan.
                </p>
            </div>

            <a href="<?= e( base_url( 'pengguna/index.php' ) ) ?>" class="btn btn-outline-secondary">
                Kembali
            </a>
        </section>

        <div class="row g-4">

            <!-- Form pengguna -->
            <div class="col-xl-8">
                <section class="card border-0 shadow-sm" aria-labelledby="user-form-title">
                    <div class="card-header bg-white py-3">
                        <h2 id="user-form-title" class="h5 mb-1">
                            Form Teknisi Baru
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Kolom bertanda bintang wajib diisi.
                        </p>
                    </div>

                    <div class="card-body p-4">

                        <form method="post" action="<?= e( base_url( 'pengguna/create.php' ) ) ?>">
                            <?= csrf_field('user_create') ?>

                            <div class="row g-3">

                                <!-- Nama lengkap -->
                                <div class="col-12">
                                    <label for="name" class="form-label required-label">
                                        Nama Lengkap
                                    </label>

                                    <input type="text" id="name" name="name" class="form-control <?= e( validation_class( $errors, 'name' ) ) ?>" value="<?= e( $nameValue ) ?>" minlength="3" maxlength="100" autocomplete="name" required autofocus>

                                    <?php
                                    $nameError =
                                        validation_first_error(
                                            $errors,
                                            'name'
                                        );
                                    ?>

                                    <?php if ($nameError !== null): ?>
                                        <div class="invalid-feedback">
                                            <?= e($nameError) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Username -->
                                <div class="col-md-6">
                                    <label for="username" class="form-label required-label">
                                        Username
                                    </label>

                                    <input type="text" id="username" name="username" class="form-control <?= e( validation_class( $errors, 'username' ) ) ?>" value="<?= e( $usernameValue ) ?>" minlength="3" maxlength="30" autocomplete="username" required>

                                    <?php
                                    $usernameError =
                                        validation_first_error(
                                            $errors,
                                            'username'
                                        );
                                    ?>

                                    <?php if ($usernameError !== null): ?>
                                        <div class="invalid-feedback">
                                            <?= e(
                                                $usernameError
                                            ) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="form-text">
                                            Gunakan huruf, angka, titik,
                                            garis bawah, atau tanda hubung.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Peran -->
                                <div class="col-md-6">
                                    <label for="role_display" class="form-label">
                                        Peran
                                    </label>

                                    <input type="text" id="role_display" class="form-control" value="Teknisi Maintenance" readonly>

                                    <input type="hidden" name="role" value="technician">

                                    <div class="form-text">
                                        Administrator dapat menambah lebih dari satu Teknisi Maintenance.
                                    </div>
                                </div>

                                <!-- Status akun -->
                                <div class="col-md-6">
                                    <label for="is_active_display" class="form-label">
                                        Status Akun
                                    </label>

                                    <input type="text" id="is_active_display" class="form-control" value="Aktif" readonly>

                                    <input type="hidden" name="is_active" value="1">

                                    <div class="form-text">
                                        Akun inti wajib aktif agar alur sistem dapat berjalan.
                                    </div>
                                </div>

                                <div class="col-12">
                                    <hr class="my-2">
                                </div>

                                <!-- Password -->
                                <div class="col-md-6">
                                    <label for="password" class="form-label required-label">
                                        Password
                                    </label>

                                    <div class="input-group has-validation">
                                        <input type="password" id="password" name="password" class="form-control <?= e( validation_class( $errors, 'password' ) ) ?>" minlength="8" maxlength="255" autocomplete="new-password" required>

                                        <button type="button" class="btn btn-outline-secondary" data-password-toggle data-target="#password" data-show-label="Tampilkan" data-hide-label="Sembunyikan" aria-pressed="false">
                                            Tampilkan
                                        </button>

                                        <?php
                                        $passwordError =
                                            validation_first_error(
                                                $errors,
                                                'password'
                                            );
                                        ?>

                                        <?php if ($passwordError !== null): ?>
                                            <div class="invalid-feedback">
                                                <?= e(
                                                    $passwordError
                                                ) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="form-text">
                                        Gunakan minimal 8 karakter.
                                    </div>
                                </div>

                                <!-- Konfirmasi password -->
                                <div class="col-md-6">
                                    <label for="password_confirmation" class="form-label required-label">
                                        Konfirmasi Password
                                    </label>

                                    <div class="input-group has-validation">
                                        <input type="password" id="password_confirmation" name="password_confirmation" class="form-control <?= e( validation_class( $errors, 'password_confirmation' ) ) ?>" minlength="8" maxlength="255" autocomplete="new-password" required>

                                        <button type="button" class="btn btn-outline-secondary" data-password-toggle data-target="#password_confirmation" data-show-label="Tampilkan" data-hide-label="Sembunyikan" aria-pressed="false">
                                            Tampilkan
                                        </button>

                                        <?php
                                        $confirmationError =
                                            validation_first_error(
                                                $errors,
                                                'password_confirmation'
                                            );
                                        ?>

                                        <?php if ($confirmationError !== null): ?>
                                            <div class="invalid-feedback">
                                                <?= e(
                                                    $confirmationError
                                                ) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            </div>

                            <!-- Tombol -->
                            <div class="d-flex flex-column flex-sm-row justify-content-end gap-2 mt-4">
                                <button type="submit" class="btn btn-primary" data-loading-text="Menyimpan...">
                                    Simpan Pengguna
                                </button>
                            </div>
                        </form>

                    </div>
                </section>
            </div>

            <!-- Penjelasan peran -->
            <div class="col-xl-4">
                <aside class="card border-0 shadow-sm">
                    <div class="card-body p-4">
                        <h2 class="h5 mb-3">
                            Pembagian Hak Akses
                        </h2>

                        <div class="mb-3">
                            <strong class="d-block">
                                Administrator
                            </strong>

                            <small class="text-body-secondary">
                                Mengelola pengguna, data motor,
                                dan periode pemeriksaan.
                            </small>
                        </div>

                        <div class="mb-3">
                            <strong class="d-block">
                                Supervisor Maintenance
                            </strong>

                            <small class="text-body-secondary">
                                Memvalidasi pemeriksaan,
                                mengisi AHP, menjalankan TOPSIS,
                                dan melihat laporan.
                            </small>
                        </div>

                        <div>
                            <strong class="d-block">
                                Teknisi Maintenance
                            </strong>

                            <small class="text-body-secondary">
                                Mengukur motor, mengisi checklist,
                                dan mencatat tindak lanjut.
                            </small>
                        </div>

                        <hr class="my-4">

                        <div class="alert alert-info mb-0">
                            Password disimpan dalam bentuk hash dan
                            tidak ditampilkan kembali oleh sistem.
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

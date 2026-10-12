<?php

declare(strict_types=1);

/*
 * Ubah Pengguna Sistem SPK PT CISAS
 * Halaman ini hanya dapat diakses oleh Administrator.
 * Fungsinya:
 * - mengambil akun berdasarkan ID;
 * - mengubah nama dan username akun;
 * - mengganti password secara opsional;
 * - mencegah username duplikat;
 * - mengunci peran akun agar riwayat tetap konsisten;
 * - menjaga Administrator dan Supervisor tetap aktif;
 * - mengizinkan Admin mengaktifkan/menonaktifkan akun Teknisi.
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

$currentUserId = auth_id();

// Validasi ID Pengguna

$userIdInput = $_GET['id'] ?? null;

$userId = filter_var(
    $userIdInput,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if ($userId === false) {
    $notFoundMessage =
        'ID pengguna tidak valid atau tidak tersedia.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '404.php';

    exit;
}

$userId = (int) $userId;

// Ambil Data Pengguna

$userStatement = $pdo->prepare(
    "SELECT
        id,
        name,
        username,
        role,
        is_active,
        created_at,
        updated_at
     FROM users
     WHERE id = :id
     LIMIT 1"
);

$userStatement->execute([
    'id' => $userId,
]);

$targetUser = $userStatement->fetch();

if (!is_array($targetUser)) {
    $notFoundMessage =
        'Data pengguna yang akan diubah tidak ditemukan.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '404.php';

    exit;
}

$isCurrentAccount =
    $currentUserId !== null
    && $userId === $currentUserId;

/*
 * Role Akun Dikunci
 * Peran akun tidak diubah melalui form edit. Administrator dan Supervisor
 * tetap tunggal, sedangkan penambahan personel dilakukan dengan membuat akun
 * Teknisi Maintenance baru.
 */

$fixedRole = (string) $targetUser['role'];

/*
 * Lindungi Akun Inti
 * Administrator dan Supervisor wajib tetap aktif. Teknisi boleh dinonaktifkan
 * apabila sudah tidak bertugas, tanpa menghapus riwayat pemeriksaan/tindakan.
 */

$isProtectedCoreAccount = in_array(
    $fixedRole,
    [
        'admin',
        'supervisor',
    ],
    true
);

$isTechnicianAccount = $fixedRole === 'technician';

// Nama Token CSRF

$csrfFormName = 'user_edit_' . $userId;

// Proses Form Ubah Pengguna

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

    $role = $fixedRole;

    $isActiveInput = $isProtectedCoreAccount
        ? '1'
        : (input_string($_POST, 'is_active', '1') ?? '1');

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

    if (!csrf_validate_request($csrfFormName)) {
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

        redirect(
            'pengguna/edit.php?id=' . $userId
        );
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
                '0',
                '1',
            ]
        )
    );

    /*
 * Password Bersifat Opsional
 * Password lama tetap digunakan jika kedua kolom dibiarkan kosong.
 */

    $passwordWillBeChanged =
        $password !== ''
        || $passwordConfirmation !== '';

    if ($passwordWillBeChanged) {
        validation_add_error(
            $errors,
            'password',
            validation_password(
                $password,
                'Password baru',
                8,
                true
            )
        );

        validation_add_error(
            $errors,
            'password_confirmation',
            validation_password(
                $passwordConfirmation,
                'Konfirmasi password baru',
                8,
                true
            )
        );

        if (
            $password !== ''
            && $passwordConfirmation !== ''
            && $password !== $passwordConfirmation
        ) {
            validation_add_error(
                $errors,
                'password_confirmation',
                'Konfirmasi password baru tidak sama dengan password baru.'
            );
        }
    }

    // Administrator dan Supervisor Wajib Tetap Aktif

    if (
        $isProtectedCoreAccount
        && $isActiveInput !== '1'
    ) {
        validation_add_error(
            $errors,
            'is_active',
            'Akun Administrator dan Supervisor tidak dapat dinonaktifkan.'
        );
    }

    // Teknisi hanya boleh dinonaktifkan jika tidak memiliki pekerjaan aktif.
    if (
        $isTechnicianAccount
        && $isActiveInput === '0'
        && (int) ($targetUser['is_active'] ?? 0) === 1
    ) {
        $openAssessmentStatement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM assessments
             WHERE inspector_id = :user_id
               AND status IN ('draft', 'returned')"
        );
        $openAssessmentStatement->execute(['user_id' => $userId]);

        $openActionStatement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM maintenance_actions
             WHERE performed_by = :user_id
               AND status <> 'completed'"
        );
        $openActionStatement->execute(['user_id' => $userId]);

        $openAssessments = (int) $openAssessmentStatement->fetchColumn();
        $openActions = (int) $openActionStatement->fetchColumn();

        if ($openAssessments > 0 || $openActions > 0) {
            validation_add_error(
                $errors,
                'is_active',
                'Teknisi masih memiliki pemeriksaan atau tindakan aktif. Selesaikan atau alihkan pekerjaan terlebih dahulu.'
            );
        }
    }

    // Periksa Username Duplikat

    if (
        validation_first_error(
            $errors,
            'username'
        ) === null
    ) {
        $usernameStatement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM users
             WHERE username = :username COLLATE NOCASE
               AND id <> :id"
        );

        $usernameStatement->execute([
            'username' => $username,
            'id' => $userId,
        ]);

        if (
            (int) $usernameStatement->fetchColumn()
            > 0
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
            'Perubahan pengguna belum dapat disimpan. '
            . 'Periksa kembali formulir.'
        );

        redirect(
            'pengguna/edit.php?id=' . $userId
        );
    }

    // Buat Hash Password Baru Jika Diperlukan

    $passwordHash = null;

    if ($passwordWillBeChanged) {
        $passwordHash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        if (
            !is_string($passwordHash)
            || $passwordHash === ''
        ) {
            flash_error(
                'Password baru tidak berhasil diamankan. '
                . 'Silakan coba kembali.'
            );

            flash_set_old_input([
                'name' => $name,
                'username' => $username,
                'role' => $role,
                'is_active' => $isActiveInput,
            ]);

            redirect(
                'pengguna/edit.php?id=' . $userId
            );
        }
    }

    // Simpan Perubahan

    try {
        if ($passwordHash !== null) {
            $updateStatement = $pdo->prepare(
                "UPDATE users
                 SET
                    name = :name,
                    username = :username,
                    password_hash = :password_hash,
                    role = :role,
                    is_active = :is_active,
                    updated_at = datetime('now', '+7 hours')
                 WHERE id = :id"
            );

            $updateParameters = [
                'name' => $name,
                'username' => $username,
                'password_hash' => $passwordHash,
                'role' => $role,
                'is_active' => (int) $isActiveInput,
                'id' => $userId,
            ];
        } else {
            $updateStatement = $pdo->prepare(
                "UPDATE users
                 SET
                    name = :name,
                    username = :username,
                    role = :role,
                    is_active = :is_active,
                    updated_at = datetime('now', '+7 hours')
                 WHERE id = :id"
            );

            $updateParameters = [
                'name' => $name,
                'username' => $username,
                'role' => $role,
                'is_active' => (int) $isActiveInput,
                'id' => $userId,
            ];
        }

        $updateStatement->execute(
            $updateParameters
        );

        // Segarkan Data Session jika Mengubah Akun Sendiri

        if ($isCurrentAccount) {
            auth_refresh_user($pdo);
        }

        flash_success(
            'Data pengguna '
            . $name
            . ' berhasil diperbarui.'
        );

        redirect('pengguna/index.php');
    } catch (PDOException $exception) {
        error_log(
            'Ubah pengguna SPK PT CISAS gagal: '
            . $exception->getMessage()
        );

        $databaseErrors = [];

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
                ? 'Perubahan pengguna belum dapat disimpan. '
                    . 'Periksa kembali username.'
                : 'Terjadi gangguan saat menyimpan perubahan pengguna. '
                    . 'Silakan coba kembali.'
        );

        redirect(
            'pengguna/edit.php?id=' . $userId
        );
    }
}

// Ambil Error dan Input Lama

$errors = flash_get_errors();

$oldInput = flash_get_old_input();

$nameValue = input_string(
    $oldInput,
    'name',
    (string) $targetUser['name']
) ?? (string) $targetUser['name'];

$usernameValue = input_string(
    $oldInput,
    'username',
    (string) $targetUser['username']
) ?? (string) $targetUser['username'];

$roleValue = $fixedRole;

$isActiveValue = input_string(
    $oldInput,
    'is_active',
    (string) ((int) $targetUser['is_active'])
) ?? (string) ((int) $targetUser['is_active']);

// Paksa Status Aktif untuk Akun Inti

if ($isProtectedCoreAccount) {
    $isActiveValue = '1';
}

// Konfigurasi Halaman

$pageTitle = 'Ubah Pengguna';

$pageDescription =
    'Perbarui identitas akun. Teknisi dapat diaktifkan atau dinonaktifkan sesuai kebutuhan.';

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
                    Ubah Pengguna
                </h1>

                <p class="page-description">
                    Perbarui nama, username, dan password akun. Status aktif/nonaktif hanya berlaku untuk Teknisi.
                </p>
            </div>

            <a href="<?= e( base_url( 'pengguna/index.php' ) ) ?>" class="btn btn-outline-secondary">
                Kembali
            </a>
        </section>

        <div class="alert alert-info" role="status">
            Peran akun dikunci. Administrator dan Supervisor wajib tetap aktif,
            sedangkan akun Teknisi dapat dinonaktifkan jika personel sudah tidak bertugas.
        </div>

        <div class="row g-4">

            <!-- Form pengguna -->
            <div class="col-xl-8">
                <section class="card border-0 shadow-sm" aria-labelledby="user-edit-form-title">
                    <div class="card-header bg-white py-3">
                        <h2 id="user-edit-form-title" class="h5 mb-1">
                            Form Perubahan Pengguna
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Kolom password boleh dikosongkan jika
                            password tidak ingin diganti.
                        </p>
                    </div>

                    <div class="card-body p-4">

                        <form method="post" action="<?= e( base_url( 'pengguna/edit.php?id=' . $userId ) ) ?>">
                            <?= csrf_field($csrfFormName) ?>

                            <div class="row g-3">

                                <!-- Nama lengkap -->
                                <div class="col-12">
                                    <label for="name" class="form-label required-label">
                                        Nama Lengkap
                                    </label>

                                    <input type="text" id="name" name="name" class="form-control <?= e( validation_class( $errors, 'name' ) ) ?>" value="<?= e($nameValue) ?>" minlength="3" maxlength="100" autocomplete="name" required autofocus>

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
                                            <?= e($usernameError) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="form-text">
                                            Username harus berbeda
                                            dari akun lain.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Peran -->
                                <div class="col-md-6">
                                    <label for="role_display" class="form-label">
                                        Peran
                                    </label>

                                    <input type="hidden" name="role" value="<?= e($fixedRole) ?>">

                                    <input type="text" id="role_display" class="form-control" value="<?= e(auth_role_label( $fixedRole )) ?>" readonly>

                                    <div class="form-text">
                                        Peran dikunci untuk menjaga riwayat aktivitas. Tambahkan akun Teknisi baru jika personel bertambah.
                                    </div>
                                </div>

                                <!-- Status akun -->
                                <div class="col-md-6">
                                    <label for="is_active" class="form-label required-label">
                                        Status Akun
                                    </label>

                                    <?php if ($isProtectedCoreAccount): ?>
                                        <input type="hidden" name="is_active" value="1">
                                        <select id="is_active" class="form-select" disabled>
                                            <option value="1" selected>Aktif</option>
                                        </select>
                                        <div class="form-text">
                                            Administrator dan Supervisor wajib tetap aktif.
                                        </div>
                                    <?php else: ?>
                                        <select id="is_active" name="is_active" class="form-select <?= e(validation_class($errors, 'is_active')) ?>" required>
                                            <option value="1" <?= selected($isActiveValue, '1') ?>>Aktif</option>
                                            <option value="0" <?= selected($isActiveValue, '0') ?>>Nonaktif</option>
                                        </select>
                                        <div class="form-text">
                                            Nonaktifkan Teknisi yang sudah tidak bertugas. Riwayatnya tetap tersimpan.
                                        </div>
                                    <?php endif; ?>

                                    <?php
                                    $statusError = validation_first_error($errors, 'is_active');
                                    ?>
                                    <?php if ($statusError !== null): ?>
                                        <div class="text-danger small mt-1">
                                            <?= e($statusError) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Password baru -->
                                <div class="col-md-6">
                                    <label for="password" class="form-label">
                                        Password Baru
                                    </label>

                                    <div class="input-group has-validation">
                                        <input type="password" id="password" name="password" class="form-control <?= e( validation_class( $errors, 'password' ) ) ?>" minlength="8" maxlength="255" autocomplete="new-password">

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
                                                <?= e($passwordError) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="form-text">
                                        Kosongkan jika password
                                        tidak diganti.
                                    </div>
                                </div>

                                <!-- Konfirmasi password baru -->
                                <div class="col-md-6">
                                    <label for="password_confirmation" class="form-label">
                                        Konfirmasi Password Baru
                                    </label>

                                    <div class="input-group has-validation">
                                        <input type="password" id="password_confirmation" name="password_confirmation" class="form-control <?= e( validation_class( $errors, 'password_confirmation' ) ) ?>" minlength="8" maxlength="255" autocomplete="new-password">

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

                                        <?php if (
                                            $confirmationError !== null
                                        ): ?>
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
                                    Simpan Perubahan
                                </button>
                            </div>
                        </form>

                    </div>
                </section>
            </div>

            <!-- Informasi akun -->
            <div class="col-xl-4">
                <aside class="card border-0 shadow-sm" aria-labelledby="account-information-title">
                    <div class="card-body p-4">
                        <h2 id="account-information-title" class="h5 mb-3">
                            Informasi Akun
                        </h2>

                        <dl class="row mb-0">
                            <dt class="col-sm-5">
                                ID Pengguna
                            </dt>

                            <dd class="col-sm-7">
                                <?= e($userId) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Dibuat
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    format_date_id(
                                        $targetUser['created_at'],
                                        true
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Diperbarui
                            </dt>

                            <dd class="col-sm-7">
                                <?= e(
                                    format_date_id(
                                        $targetUser['updated_at'],
                                        true
                                    )
                                ) ?>
                            </dd>

                            <dt class="col-sm-5">
                                Kondisi
                            </dt>

                            <dd class="col-sm-7 mb-0">
                                <?php if (
                                    (int) $targetUser['is_active'] === 1
                                ): ?>
                                    <span class="status-badge status-success">
                                        Aktif
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge status-danger">
                                        Tidak Aktif
                                    </span>
                                <?php endif; ?>
                            </dd>
                        </dl>

                        <hr class="my-4">

                        <div class="alert alert-info mb-0">
                            Password lama tidak ditampilkan.
                            Password hanya berubah jika kolom
                            password baru diisi.
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

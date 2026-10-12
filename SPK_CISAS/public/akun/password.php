<?php

declare(strict_types=1);

/*
 * Halaman Ubah Password
 * Sistem SPK PT. CISAS
 * Halaman ini digunakan oleh pengguna yang sedang login untuk:
 * - memverifikasi password saat ini;
 * - memasukkan password baru;
 * - mengonfirmasi password baru;
 * - menyimpan password baru dalam bentuk hash.
 * Halaman dapat digunakan oleh:
 * - Administrator;
 * - Supervisor Maintenance;
 * - Teknisi Maintenance.
 */

/*
 * 1. Muat Helper Autentikasi
 * auth.php menyediakan fungsi:
 * - auth_require_login();
 * - auth_refresh_user();
 * - auth_user();
 * - auth_id();
 * - auth_name();
 * - auth_role();
 * - auth_role_label();
 * - redirect();
 * - flash_error();
 * - flash_success();
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'helpers'
    . DIRECTORY_SEPARATOR
    . 'auth.php';

/*
 * 2. Muat Helper CSRF
 * csrf.php digunakan untuk melindungi formulir dari permintaan palsu.
 * Fungsi yang digunakan:
 * - csrf_field();
 * - csrf_validate_request();
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'helpers'
    . DIRECTORY_SEPARATOR
    . 'csrf.php';

/*
 * 3. Pengguna Wajib Login
 * Pengguna yang belum login akan diarahkan ke halaman login.
 */

$currentUser = auth_require_login();

/*
 * 4. Buka Koneksi Database
 * dirname(__DIR__, 2) mengarah dari:
 * public/akun/password.php
 * menuju folder utama:
 * SPK_CISAS
 */

$pdo = require dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'config'
    . DIRECTORY_SEPARATOR
    . 'database.php';

/*
 * 5. Periksa Apakah Akun Masih Aktif
 * Data pengguna pada sesi diperbarui dari database.
 * Pengguna tidak boleh melanjutkan jika akunnya sudah tidak aktif.
 */

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun pengguna sudah tidak aktif '
        . 'atau tidak ditemukan.'
    );

    redirect('login.php');
}

// 6. Ambil Informasi Pengguna Aktif

$currentUser = auth_user() ?? [];

$currentUserId = auth_id();

$currentUserName = auth_name()
    ?? 'Pengguna';

$currentUserRole = auth_role()
    ?? '';

$currentRoleLabel = auth_role_label(
    $currentUserRole
);

$currentUsername = trim(
    (string) (
        $currentUser['username']
        ?? '-'
    )
);

if ($currentUsername === '') {
    $currentUsername = '-';
}

// 7. Pastikan ID Pengguna Valid

if ($currentUserId === null) {
    flash_error(
        'Sesi pengguna tidak valid. '
        . 'Silakan masuk kembali.'
    );

    redirect('login.php');
}

/*
 * 8. Fungsi Menambahkan Pesan Error
 * Error disimpan berdasarkan nama input.
 * Contoh:
 * $passwordErrors['new_password']
 */

$addPasswordError = static function (
    array &$errors,
    string $field,
    string $message
): void {
    if (!isset($errors[$field])) {
        $errors[$field] = [];
    }

    $errors[$field][] = $message;
};

/*
 * 9. Fungsi Mengambil Error Pertama
 * Satu input mungkin memiliki beberapa error.
 * Pada tampilan cukup ditampilkan error pertamanya.
 */

$getPasswordError = static function (
    array $errors,
    string $field
): ?string {
    $fieldErrors = $errors[$field]
        ?? [];

    if (
        !is_array($fieldErrors)
        || $fieldErrors === []
    ) {
        return null;
    }

    $firstError = $fieldErrors[0]
        ?? null;

    return is_string($firstError)
        ? $firstError
        : null;
};

/*
 * 10. Fungsi Mengambil Input Password
 * Input harus berupa nilai tunggal.
 * Jika input dikirim dalam bentuk array, nilainya ditolak.
 */

$getPasswordInput = static function (
    array $source,
    string $key
): string {
    $value = $source[$key]
        ?? '';

    if (!is_scalar($value)) {
        return '';
    }

    return (string) $value;
};

/*
 * 11. Fungsi Menghitung Panjang Password
 * mb_strlen() digunakan apabila ekstensi multibyte tersedia.
 */

$passwordLength = static function (
    string $password
): int {
    if (function_exists('mb_strlen')) {
        return mb_strlen(
            $password,
            'UTF-8'
        );
    }

    return strlen(
        $password
    );
};

/*
 * 12. Fungsi Membuat Inisial Pengguna
 * Contoh:
 * Administrator Sistem menjadi AS
 * Supervisor Maintenance menjadi SM
 * Teknisi Maintenance menjadi TM
 */

$createAccountInitials = static function (
    string $name
): string {
    $name = trim($name);

    if ($name === '') {
        return '?';
    }

    $nameParts = preg_split(
        '/\s+/u',
        $name,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    if (
        !is_array($nameParts)
        || $nameParts === []
    ) {
        return '?';
    }

    $initialCharacters = [];

    foreach (
        array_slice(
            $nameParts,
            0,
            2
        )
        as $namePart
    ) {
        if (function_exists('mb_substr')) {
            $initialCharacters[] = mb_substr(
                $namePart,
                0,
                1,
                'UTF-8'
            );
        } else {
            $initialCharacters[] = substr(
                $namePart,
                0,
                1
            );
        }
    }

    $initials = implode(
        '',
        $initialCharacters
    );

    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper(
            $initials,
            'UTF-8'
        );
    }

    return strtoupper(
        $initials
    );
};

/*
 * 13. Fungsi Ikon Halaman Password
 * Ikon menggunakan SVG sehingga tidak membutuhkan library tambahan.
 * Ikon tidak dipasang pada tombol Kembali, Batal, atau Simpan Password.
 */

$accountPasswordIcon = static function (
    string $name,
    int $size = 21
): string {
    $svgOpen = '<svg'
        . ' width="' . $size . '"'
        . ' height="' . $size . '"'
        . ' viewBox="0 0 24 24"'
        . ' fill="none"'
        . ' xmlns="http://www.w3.org/2000/svg"'
        . '>';

    $svgClose = '</svg>';

    $content = match ($name) {
        // Ikon Gembok

        'lock' => '
            <rect x="4" y="10" width="16" height="11" rx="2" stroke="currentColor" stroke-width="1.7"/>

            <path d="M8 10V7C8 4.79086 9.79086 3 12 3C14.2091 3 16 4.79086 16 7V10" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>

            <path d="M12 14V17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
        ',

        // Ikon Pelindung

        'shield' => '
            <path d="M12 3L20 7V12C20 16.9706 16.5817 21 12 22C7.41828 21 4 16.9706 4 12V7L12 3Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>

            <path d="M9 12L11 14L15 10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        ',

        // Ikon Kunci

        'key' => '
            <circle cx="8" cy="15" r="4" stroke="currentColor" stroke-width="1.7"/>

            <path d="M11 12L20 3M17 6L20 9M14 9L17 12" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
        ',

        // Ikon Tampilkan Password

        'eye' => '
            <path d="M2 12C2 12 5.5 5 12 5C18.5 5 22 12 22 12C22 12 18.5 19 12 19C5.5 19 2 12 2 12Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>

            <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.7"/>
        ',

        // Ikon Sembunyikan Password

        'eye-off' => '
            <path d="M3 3L21 21" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>

            <path d="M10.7 5.1C11.1 5 11.6 5 12 5C18.5 5 22 12 22 12C21.4 13.3 20.5 14.6 19.3 15.8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>

            <path d="M6.6 6.6C3.8 8.5 2 12 2 12C2 12 5.5 19 12 19C13.4 19 14.7 18.7 15.9 18.1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
        ',

        // Ikon Centang untuk Daftar Informasi

        'check' => '
            <path d="M5 12L10 17L20 7" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>
        ',

        // Ikon Informasi

        'info' => '
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/>

            <path d="M12 11V16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>

            <circle cx="12" cy="8" r="1" fill="currentColor"/>
        ',

        // Ikon Default

        default => '
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/>
        ',
    };

    return $svgOpen
        . $content
        . $svgClose;
};

// 14. Nilai Awal Halaman

$passwordErrors = [];

$passwordPageError = null;

$currentPassword = '';

$newPassword = '';

$confirmPassword = '';

$accountInitials = $createAccountInitials(
    $currentUserName
);

/*
 * 15. Periksa Metode Permintaan
 * Form hanya diproses apabila halaman dibuka dengan metode POST.
 */

$isPostRequest = strtoupper(
    (string) (
        $_SERVER['REQUEST_METHOD']
        ?? 'GET'
    )
) === 'POST';

// 16. Proses Perubahan Password

if ($isPostRequest) {
    // 16.1 Ambil Nilai Form

    $currentPassword = $getPasswordInput(
        $_POST,
        'current_password'
    );

    $newPassword = $getPasswordInput(
        $_POST,
        'new_password'
    );

    $confirmPassword = $getPasswordInput(
        $_POST,
        'confirm_password'
    );

    // 16.2 Validasi Token CSRF

    if (
        !csrf_validate_request(
            'account_password'
        )
    ) {
        $passwordPageError =
            'Token keamanan tidak valid '
            . 'atau telah kedaluwarsa. '
            . 'Silakan isi kembali formulir.';
    }

    // 16.3 Validasi Password Saat Ini

    if ($currentPassword === '') {
        $addPasswordError(
            $passwordErrors,
            'current_password',
            'Password saat ini wajib diisi.'
        );
    }

    // 16.4 Validasi Password Baru

    if ($newPassword === '') {
        $addPasswordError(
            $passwordErrors,
            'new_password',
            'Password baru wajib diisi.'
        );
    } else {
        $newPasswordLength = $passwordLength(
            $newPassword
        );

        if ($newPasswordLength < 8) {
            $addPasswordError(
                $passwordErrors,
                'new_password',
                'Password baru minimal terdiri dari 8 karakter.'
            );
        }

        if ($newPasswordLength > 72) {
            $addPasswordError(
                $passwordErrors,
                'new_password',
                'Password baru maksimal terdiri dari 72 karakter.'
            );
        }
    }

    // 16.5 Validasi Konfirmasi Password

    if ($confirmPassword === '') {
        $addPasswordError(
            $passwordErrors,
            'confirm_password',
            'Konfirmasi password baru wajib diisi.'
        );
    } elseif (
        $newPassword !== ''
        && !hash_equals(
            $newPassword,
            $confirmPassword
        )
    ) {
        $addPasswordError(
            $passwordErrors,
            'confirm_password',
            'Konfirmasi password baru tidak sama.'
        );
    }

    // 16.6 Periksa Password pada Database

    if (
        $passwordPageError === null
        && $passwordErrors === []
    ) {
        try {
            /*
 * Ambil Hash Password Saat Ini
 * Query memakai named placeholder :user_id.
 */

            $userStatement = $pdo->prepare(
                "SELECT
                    id,
                    password_hash,
                    is_active

                 FROM users

                 WHERE id = :user_id

                 LIMIT 1"
            );

            $userStatement->execute([
                'user_id' => $currentUserId,
            ]);

            $userData = $userStatement->fetch(
                PDO::FETCH_ASSOC
            );

            // Pastikan Akun Ditemukan dan Aktif

            if (
                !is_array($userData)
                || (int) (
                    $userData['is_active']
                    ?? 0
                ) !== 1
            ) {
                flash_error(
                    'Akun pengguna sudah tidak aktif '
                    . 'atau tidak ditemukan.'
                );

                redirect('login.php');
            }

            $storedPasswordHash = (string) (
                $userData['password_hash']
                ?? ''
            );

            /*
 * Verifikasi Password Saat Ini
 * password_verify() membandingkan password yang dimasukkan
 * pengguna dengan hash yang tersimpan pada database.
 */

            if (
                $storedPasswordHash === ''
                || !password_verify(
                    $currentPassword,
                    $storedPasswordHash
                )
            ) {
                $addPasswordError(
                    $passwordErrors,
                    'current_password',
                    'Password saat ini tidak benar.'
                );
            }

            // Password Baru Tidak Boleh Sama dengan Password Lama

            if (
                $storedPasswordHash !== ''
                && password_verify(
                    $newPassword,
                    $storedPasswordHash
                )
            ) {
                $addPasswordError(
                    $passwordErrors,
                    'new_password',
                    'Password baru tidak boleh sama dengan password saat ini.'
                );
            }

            // 16.7 Simpan Password Baru

            if ($passwordErrors === []) {
                /*
 * Buat Hash Password Baru
 * PASSWORD_DEFAULT memilih algoritma bawaan PHP yang sesuai.
 */

                $newPasswordHash = password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );

                if (
                    !is_string($newPasswordHash)
                    || $newPasswordHash === ''
                ) {
                    throw new RuntimeException(
                        'Hash password baru gagal dibuat.'
                    );
                }

                // Perbarui Password pada Tabel Users

                $updateStatement = $pdo->prepare(
                    "UPDATE users

                     SET
                        password_hash = :password_hash,
                        updated_at = datetime('now', '+7 hours')

                     WHERE id = :user_id
                       AND is_active = 1"
                );

                $updateStatement->execute([
                    'password_hash' => $newPasswordHash,
                    'user_id' => $currentUserId,
                ]);

                // Pastikan Satu Akun Berhasil Diperbarui

                if (
                    $updateStatement->rowCount() !== 1
                ) {
                    throw new RuntimeException(
                        'Password pengguna tidak berhasil diperbarui.'
                    );
                }

                /*
 * Ganti ID Sesi
 * Pengguna tetap login, tetapi memperoleh ID sesi baru.
 */

                if (
                    session_status()
                    === PHP_SESSION_ACTIVE
                ) {
                    session_regenerate_id(
                        true
                    );
                }

                // Tampilkan Pesan Berhasil

                flash_success(
                    'Password akun berhasil diperbarui.'
                );

                // Kembali ke Profil Akun

                redirect(
                    'akun/profil.php'
                );
            }
        } catch (Throwable $exception) {
            // Catat Error Teknis

            error_log(
                'Perubahan password akun SPK PT. CISAS gagal: '
                . $exception->getMessage()
            );

            // Pesan Aman untuk Pengguna

            $passwordPageError =
                'Password belum dapat diperbarui. '
                . 'Silakan coba kembali.';
        }
    }

    /*
 * 16.8 Kosongkan Semua Input Password
 * Password tidak boleh ditampilkan kembali pada HTML setelah gagal.
 */

    $currentPassword = '';

    $newPassword = '';

    $confirmPassword = '';
}

// 17. Ambil Error Setiap Input

$currentPasswordError = $getPasswordError(
    $passwordErrors,
    'current_password'
);

$newPasswordError = $getPasswordError(
    $passwordErrors,
    'new_password'
);

$confirmPasswordError = $getPasswordError(
    $passwordErrors,
    'confirm_password'
);

// 18. Konfigurasi Halaman

$pageTitle = 'Ubah Password';

$pageDescription =
    'Perbarui password akun pengguna '
    . 'Sistem SPK PT. CISAS.';

$bodyClass =
    'd-flex flex-column min-vh-100 '
    . 'account-page account-password-page';

// 19. Muat Header dan Navbar

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'header.php';

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'navbar.php';

?>

<div class="d-lg-flex flex-grow-1">

    <?php
    // 20. Muat Sidebar

    require TEMPLATE_PATH
        . DIRECTORY_SEPARATOR
        . 'sidebar.php';
    ?>

    <main id="main-content" class="flex-grow-1 p-3 p-lg-4 overflow-hidden">
        <?php
        // 21. Muat Pesan Flash

        require TEMPLATE_PATH
            . DIRECTORY_SEPARATOR
            . 'alert.php';
        ?>

        <?php if (
            $passwordPageError !== null
        ): ?>

            <div class="alert alert-danger mb-4" role="alert">
                <strong class="d-block mb-1">
                    Password belum dapat diperbarui
                </strong>

                <?= e(
                    $passwordPageError
                ) ?>
            </div>

        <?php endif; ?>

        <!-- Kepala Halaman -->
        <header class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <h1 class="page-title">
                    Ubah Password
                </h1>

                <p class="page-description">
                    Masukkan password saat ini sebelum
                    membuat password baru.
                </p>
            </div>

            <!-- Tombol Kembali Tanpa Ikon -->
            <a href="<?= e( base_url( 'akun/profil.php' ) ) ?>" class="btn btn-outline-secondary">
                Kembali
            </a>
        </header>

        <div class="row g-4">

            <!-- Form Ubah Password -->
            <div class="col-xl-7">

                <section class="card account-password-card border-0 shadow-sm" aria-labelledby="change-password-title">
                    <!-- Kepala Card -->
                    <div class="card-header bg-white py-3 px-4">
                        <div class="d-flex align-items-start gap-3">
                            <span class="account-password-heading-icon" aria-hidden="true">
                                <?= $accountPasswordIcon(
                                    'key',
                                    23
                                ) ?>
                            </span>

                            <div>
                                <h2 id="change-password-title" class="h5 mb-1">
                                    Form Ubah Password
                                </h2>

                                <p class="small text-body-secondary mb-0">
                                    Seluruh kolom bertanda
                                    bintang wajib diisi.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Isi Card -->
                    <div class="card-body p-4">

                        <form method="post" action="<?= e( base_url( 'akun/password.php' ) ) ?>" class="account-password-form" novalidate>
                            <!-- Token CSRF -->
                            <?= csrf_field(
                                'account_password'
                            ) ?>

                            <!-- Password Saat Ini -->
                            <div class="mb-4">

                                <label for="current_password" class="form-label">
                                    Password Saat Ini

                                    <span class="text-danger" aria-hidden="true">
                                        *
                                    </span>
                                </label>

                                <div class="account-password-input-wrapper">
                                    <span class="account-password-input-icon" aria-hidden="true">
                                        <?= $accountPasswordIcon(
                                            'lock',
                                            20
                                        ) ?>
                                    </span>

                                    <input type="password" id="current_password" name="current_password" class="form-control account-password-input <?= $currentPasswordError !== null ? 'is-invalid' : '' ?>" maxlength="72" autocomplete="current-password" placeholder="Masukkan password saat ini" required autofocus>

                                    <button type="button" class="account-password-toggle" data-password-toggle data-password-target="current_password" aria-label="Tampilkan password saat ini" aria-pressed="false">
                                        <span class="password-toggle-show" aria-hidden="true">
                                            <?= $accountPasswordIcon(
                                                'eye',
                                                20
                                            ) ?>
                                        </span>

                                        <span class="password-toggle-hide" aria-hidden="true">
                                            <?= $accountPasswordIcon(
                                                'eye-off',
                                                20
                                            ) ?>
                                        </span>
                                    </button>
                                </div>

                                <?php if (
                                    $currentPasswordError !== null
                                ): ?>

                                    <div class="invalid-feedback d-block">
                                        <?= e(
                                            $currentPasswordError
                                        ) ?>
                                    </div>

                                <?php endif; ?>
                            </div>

                            <!-- Password Baru -->
                            <div class="mb-4">

                                <label for="new_password" class="form-label">
                                    Password Baru

                                    <span class="text-danger" aria-hidden="true">
                                        *
                                    </span>
                                </label>

                                <div class="account-password-input-wrapper">
                                    <span class="account-password-input-icon" aria-hidden="true">
                                        <?= $accountPasswordIcon(
                                            'key',
                                            20
                                        ) ?>
                                    </span>

                                    <input type="password" id="new_password" name="new_password" class="form-control account-password-input <?= $newPasswordError !== null ? 'is-invalid' : '' ?>" minlength="8" maxlength="72" autocomplete="new-password" placeholder="Masukkan password baru" required>

                                    <button type="button" class="account-password-toggle" data-password-toggle data-password-target="new_password" aria-label="Tampilkan password baru" aria-pressed="false">
                                        <span class="password-toggle-show" aria-hidden="true">
                                            <?= $accountPasswordIcon(
                                                'eye',
                                                20
                                            ) ?>
                                        </span>

                                        <span class="password-toggle-hide" aria-hidden="true">
                                            <?= $accountPasswordIcon(
                                                'eye-off',
                                                20
                                            ) ?>
                                        </span>
                                    </button>
                                </div>

                                <?php if (
                                    $newPasswordError !== null
                                ): ?>

                                    <div class="invalid-feedback d-block">
                                        <?= e(
                                            $newPasswordError
                                        ) ?>
                                    </div>

                                <?php endif; ?>

                                <div class="form-text">
                                    Gunakan minimal 8 karakter
                                    dan jangan memakai password lama.
                                </div>
                            </div>

                            <!-- Konfirmasi Password Baru -->
                            <div class="mb-4">

                                <label for="confirm_password" class="form-label">
                                    Konfirmasi Password Baru

                                    <span class="text-danger" aria-hidden="true">
                                        *
                                    </span>
                                </label>

                                <div class="account-password-input-wrapper">
                                    <span class="account-password-input-icon" aria-hidden="true">
                                        <?= $accountPasswordIcon(
                                            'shield',
                                            20
                                        ) ?>
                                    </span>

                                    <input type="password" id="confirm_password" name="confirm_password" class="form-control account-password-input <?= $confirmPasswordError !== null ? 'is-invalid' : '' ?>" minlength="8" maxlength="72" autocomplete="new-password" placeholder="Ulangi password baru" required>

                                    <button type="button" class="account-password-toggle" data-password-toggle data-password-target="confirm_password" aria-label="Tampilkan konfirmasi password" aria-pressed="false">
                                        <span class="password-toggle-show" aria-hidden="true">
                                            <?= $accountPasswordIcon(
                                                'eye',
                                                20
                                            ) ?>
                                        </span>

                                        <span class="password-toggle-hide" aria-hidden="true">
                                            <?= $accountPasswordIcon(
                                                'eye-off',
                                                20
                                            ) ?>
                                        </span>
                                    </button>
                                </div>

                                <?php if (
                                    $confirmPasswordError !== null
                                ): ?>

                                    <div class="invalid-feedback d-block">
                                        <?= e(
                                            $confirmPasswordError
                                        ) ?>
                                    </div>

                                <?php endif; ?>
                            </div>

                            <!-- Tombol Tanpa Ikon -->
                            <div class="d-flex flex-column-reverse flex-sm-row justify-content-end gap-2">
                                <a href="<?= e( base_url( 'akun/profil.php' ) ) ?>" class="btn btn-outline-secondary">
                                    Batal
                                </a>

                                <button type="submit" class="btn btn-primary" data-loading-text="Menyimpan...">
                                    Simpan Password
                                </button>
                            </div>
                        </form>
                    </div>
                </section>
            </div>

            <!-- Informasi Keamanan -->
            <div class="col-xl-5">

                <section class="card account-security-card border-0 shadow-sm mb-4" aria-labelledby="account-security-title">
                    <div class="card-body p-4">

                        <div class="account-security-icon mb-3" aria-hidden="true">
                            <?= $accountPasswordIcon(
                                'shield',
                                27
                            ) ?>
                        </div>

                        <h2 id="account-security-title" class="h5 mb-2">
                            Keamanan Password
                        </h2>

                        <p class="text-body-secondary mb-4">
                            Gunakan password yang tidak
                            mudah ditebak dan tidak digunakan
                            pada akun lain.
                        </p>

                        <ul class="account-security-list list-unstyled mb-0">
                            <li>
                                <span aria-hidden="true">
                                    <?= $accountPasswordIcon(
                                        'check',
                                        17
                                    ) ?>
                                </span>

                                Minimal 8 karakter.
                            </li>

                            <li>
                                <span aria-hidden="true">
                                    <?= $accountPasswordIcon(
                                        'check',
                                        17
                                    ) ?>
                                </span>

                                Berbeda dari password saat ini.
                            </li>

                            <li>
                                <span aria-hidden="true">
                                    <?= $accountPasswordIcon(
                                        'check',
                                        17
                                    ) ?>
                                </span>

                                Konfirmasi password harus sama.
                            </li>

                            <li>
                                <span aria-hidden="true">
                                    <?= $accountPasswordIcon(
                                        'check',
                                        17
                                    ) ?>
                                </span>

                                Jangan diberikan kepada pengguna lain.
                            </li>
                        </ul>
                    </div>
                </section>

                <!-- Akun yang Sedang Diubah -->
                <section class="card account-current-user-card border-0 shadow-sm" aria-labelledby="current-account-title">
                    <div class="card-body p-4">

                        <h2 id="current-account-title" class="h6 mb-3">
                            Akun yang Sedang Diubah
                        </h2>

                        <div class="d-flex align-items-center gap-3">
                            <span class="account-current-user-avatar" aria-hidden="true">
                                <?= e(
                                    $accountInitials
                                ) ?>
                            </span>

                            <div class="min-w-0">
                                <strong class="d-block text-truncate">
                                    <?= e(
                                        $currentUserName
                                    ) ?>
                                </strong>

                                <span class="d-block text-body-secondary small mt-1">
                                    @<?= e(
                                        $currentUsername
                                    ) ?>
                                </span>

                                <span class="badge text-bg-light border mt-2">
                                    <?= e(
                                        $currentRoleLabel
                                    ) ?>
                                </span>
                            </div>
                        </div>

                        <div class="account-security-note mt-4">
                            <span aria-hidden="true">
                                <?= $accountPasswordIcon(
                                    'info',
                                    19
                                ) ?>
                            </span>

                            <p class="mb-0">
                                Perubahan hanya berlaku untuk
                                akun yang sedang digunakan.
                            </p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>

<!-- JavaScript Tombol Tampilkan dan Sembunyikan Password -->
<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {
        const toggleButtons =
            document.querySelectorAll(
                '[data-password-toggle]'
            );

        toggleButtons.forEach(
            function (toggleButton) {
                toggleButton.addEventListener(
                    'click',
                    function () {
                        const targetId =
                            toggleButton.getAttribute(
                                'data-password-target'
                            );

                        if (!targetId) {
                            return;
                        }

                        const passwordInput =
                            document.getElementById(
                                targetId
                            );

                        if (
                            !(
                                passwordInput
                                instanceof HTMLInputElement
                            )
                        ) {
                            return;
                        }

                        const passwordIsVisible =
                            passwordInput.type === 'text';

                        // Ubah Tipe Input

                        passwordInput.type =
                            passwordIsVisible
                                ? 'password'
                                : 'text';

                        // Perbarui Status Tombol

                        toggleButton.setAttribute(
                            'aria-pressed',
                            passwordIsVisible
                                ? 'false'
                                : 'true'
                        );

                        // Perbarui Label Aksesibilitas

                        toggleButton.setAttribute(
                            'aria-label',
                            passwordIsVisible
                                ? 'Tampilkan password'
                                : 'Sembunyikan password'
                        );

                        // Kembalikan Fokus ke Input

                        passwordInput.focus();
                    }
                );
            }
        );
    }
);
</script>

<?php

// 22. Muat Footer

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

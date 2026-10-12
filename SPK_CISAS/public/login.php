<?php

declare(strict_types=1);

/**
 * Halaman Login
 * Memeriksa username dan password pengguna internal PT CISAS.
 */

require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/validation.php';
require_once dirname(__DIR__) . '/app/helpers/audit.php';

auth_require_guest('dashboard.php');

if (is_post()) {
    $username = input_string($_POST, 'username', '') ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $password = (is_scalar($passwordInput) || $passwordInput instanceof Stringable)
        ? (string) $passwordInput
        : '';

    // Validasi token dan input login.
    if (!csrf_validate_request('login')) {
        flash_error('Token keamanan tidak valid atau telah kedaluwarsa. Silakan isi kembali formulir login.');
        flash_set_old_input(['username' => $username]);
        redirect('login.php');
    }

    $loginErrors = [];
    validation_add_error($loginErrors, 'username', validation_username($username));
    validation_add_error($loginErrors, 'password', validation_password($password, 'Password', 8, true));

    if (validation_has_errors($loginErrors)) {
        flash_set_errors($loginErrors);
        flash_set_old_input(['username' => $username]);
        flash_error('Data login belum valid. Periksa kembali username dan password.');
        redirect('login.php');
    }

    try {
        $pdo = require dirname(__DIR__) . '/app/config/database.php';
        $clientIp = audit_client_ip();
        $throttle = auth_login_throttle_status($pdo, $username, $clientIp);

        if ((bool) ($throttle['locked'] ?? false)) {
            audit_log(
                $pdo,
                null,
                'login_failed',
                'authentication',
                null,
                'Percobaan login ditolak sementara karena batas kegagalan tercapai.',
                null,
                ['username' => $username, 'reason' => 'rate_limited'],
                $username,
                null
            );
            flash_error(
                'Terlalu banyak percobaan login gagal untuk akun dan perangkat ini. '
                . 'Silakan coba kembali sekitar ' . AUTH_LOGIN_WINDOW_MINUTES . ' menit lagi.'
            );
            flash_set_old_input(['username' => $username]);
            redirect('login.php');
        }

        if (!auth_attempt($pdo, $username, $password)) {
            auth_record_login_attempt($pdo, $username, $clientIp, false);
            audit_log(
                $pdo,
                null,
                'login_failed',
                'authentication',
                null,
                'Login gagal. Detail autentikasi disamarkan untuk keamanan.',
                null,
                ['username' => $username],
                $username,
                null
            );
            flash_error('Username atau password salah, atau akun pengguna sedang tidak aktif.');
            flash_set_old_input(['username' => $username]);
            redirect('login.php');
        }

        auth_record_login_attempt($pdo, $username, $clientIp, true);
        audit_log(
            $pdo,
            auth_id(),
            'login_success',
            'authentication',
            auth_id(),
            'Pengguna berhasil masuk ke sistem.'
        );

        flash_success('Login berhasil. Selamat datang, ' . (auth_name() ?? 'Pengguna') . '.');

        redirect('dashboard.php');
    } catch (Throwable $exception) {
        error_log('Login SPK PT CISAS gagal: ' . $exception->getMessage());
        flash_error('Proses login mengalami gangguan. Silakan coba kembali.');
        flash_set_old_input(['username' => $username]);
        redirect('login.php');
    }
}

// Ambil Error dan Input Lama

$errors =
    flash_get_errors();

$oldInput =
    flash_get_old_input();

$usernameValue = input_string(
    $oldInput,
    'username',
    ''
) ?? '';

// Konfigurasi Halaman

$pageTitle =
    'Masuk';

$pageDescription =
    'Halaman login Sistem Pendukung Keputusan '
    . 'Prioritas Pemeliharaan Motor Mesin Mixer PT. CISAS.';

$bodyClass =
    'login-page d-flex flex-column min-vh-100';

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'header.php';
?>

<main id="login-main-content" class="login-main flex-grow-1">
    <!-- Ornamen latar -->
    <div
        class="login-background-shape
               login-background-shape-one"
        aria-hidden="true"
    ></div>

    <div
        class="login-background-shape
               login-background-shape-two"
        aria-hidden="true"
    ></div>

    <!-- Wadah login -->
    <div class="login-container">
        <section class="login-card" aria-labelledby="login-title">
            <div class="login-card-body">

                <!-- Logo CISAS -->
                <div class="login-brand">
                    <img src="<?= e( base_url( 'assets/images/logo_cisas.webp' ) ) ?>" class="login-logo" alt="Logo CISAS">
                </div>

                <!-- Judul -->
                <div class="login-heading">
                    <h1 id="login-title" class="login-title">
                        Selamat Datang
                    </h1>

                    <p class="login-description">
                        Sistem Pendukung Keputusan
                        <br>
                        Prioritas Pemeliharaan
                        Motor Mesin Mixer
                    </p>
                </div>

                <!-- Pesan sistem -->
                <div class="login-alert">
                    <?php
                    require TEMPLATE_PATH
                        . DIRECTORY_SEPARATOR
                        . 'alert.php';
                    ?>
                </div>

                <!-- Form login -->
                <form method="post" action="<?= e( base_url( 'login.php' ) ) ?>" class="login-form" novalidate>
                    <?= csrf_field('login') ?>

                    <!-- Username -->
                    <div class="login-form-group">
                        <label for="username" class="form-label login-label">
                            Username
                        </label>

                        <div class="login-input-wrapper">
                            <span class="login-input-icon" aria-hidden="true">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M12 12C14.7614 12 17 9.76142 17 7C17 4.23858 14.7614 2 12 2C9.23858 2 7 4.23858 7 7C7 9.76142 9.23858 12 12 12Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>

                                    <path d="M20.59 22C20.59 18.13 16.74 15 12 15C7.26 15 3.41 18.13 3.41 22" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>

                            <input type="text" id="username" name="username" class="form-control login-input <?= e( validation_class( $errors, 'username' ) ) ?>" value="<?= e( $usernameValue ) ?>" minlength="3" maxlength="30" autocomplete="username" placeholder="Masukkan username" required <?= $usernameValue === '' ? 'autofocus' : '' ?>>
                        </div>

                        <?php
                        $usernameError =
                            validation_first_error(
                                $errors,
                                'username'
                            );
                        ?>

                        <?php if (
                            $usernameError !== null
                        ): ?>
                            <div class="invalid-feedback d-block">
                                <?= e(
                                    $usernameError
                                ) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Password -->
                    <div class="login-form-group">
                        <label for="password" class="form-label login-label">
                            Password
                        </label>

                        <div class="login-input-wrapper">
                            <span class="login-input-icon" aria-hidden="true">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <rect x="4" y="10" width="16" height="11" rx="2" stroke="currentColor" stroke-width="1.8"/>

                                    <path d="M8 10V7C8 4.79086 9.79086 3 12 3C14.2091 3 16 4.79086 16 7V10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>

                                    <path d="M12 14V17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                </svg>
                            </span>

                            <input type="password" id="password" name="password" class="form-control login-input login-password-input <?= e( validation_class( $errors, 'password' ) ) ?>" minlength="8" maxlength="255" autocomplete="current-password" placeholder="Masukkan password" required>

                            <!-- Tombol lihat password -->
                            <button type="button" class="login-password-toggle" id="password-toggle" aria-label="Tampilkan password" aria-controls="password" aria-pressed="false">
                                <span class="password-icon-show" aria-hidden="true">
                                    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M2 12C2 12 5.5 5 12 5C18.5 5 22 12 22 12C22 12 18.5 19 12 19C5.5 19 2 12 2 12Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>

                                        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/>
                                    </svg>
                                </span>

                                <span class="password-icon-hide" aria-hidden="true">
                                    <svg width="21" height="21" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M3 3L21 21" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>

                                        <path d="M10.73 5.08C11.15 5.03 11.57 5 12 5C18.5 5 22 12 22 12C21.36 13.28 20.46 14.63 19.31 15.78" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>

                                        <path d="M6.61 6.61C3.84 8.46 2 12 2 12C2 12 5.5 19 12 19C13.43 19 14.72 18.66 15.87 18.13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>

                                        <path d="M10.17 10.17C9.45 10.89 9.45 12.06 10.17 12.78C10.89 13.5 12.06 13.5 12.78 12.78" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </span>
                            </button>
                        </div>

                        <?php
                        $passwordError =
                            validation_first_error(
                                $errors,
                                'password'
                            );
                        ?>

                        <?php if (
                            $passwordError !== null
                        ): ?>
                            <div class="invalid-feedback d-block">
                                <?= e(
                                    $passwordError
                                ) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Tombol masuk -->
                    <button type="submit" class="btn login-submit-button" data-loading-text="Memproses...">
                        Masuk
                    </button>
                </form>

                <!-- Identitas sistem -->
                <div class="login-footer">
                    <div class="text-center">
                        <strong class="d-block">
                            PT. CISAS
                        </strong>

                        <span class="d-block mt-1">
                            AHP · TOPSIS
                        </span>
                    </div>
                </div>

            </div>
        </section>
    </div>
</main>

<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {
        const passwordInput =
            document.getElementById(
                'password'
            );

        const passwordToggle =
            document.getElementById(
                'password-toggle'
            );

        if (
            passwordInput === null
            || passwordToggle === null
        ) {
            return;
        }

        passwordToggle.addEventListener(
            'click',
            function () {
                const passwordIsVisible =
                    passwordInput.type === 'text';

                passwordInput.type =
                    passwordIsVisible
                        ? 'password'
                        : 'text';

                passwordToggle.setAttribute(
                    'aria-pressed',
                    passwordIsVisible
                        ? 'false'
                        : 'true'
                );

                passwordToggle.setAttribute(
                    'aria-label',
                    passwordIsVisible
                        ? 'Tampilkan password'
                        : 'Sembunyikan password'
                );

                passwordInput.focus();
            }
        );
    }
);
</script>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

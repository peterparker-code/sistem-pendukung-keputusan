<?php

declare(strict_types=1);

/*
 * Halaman 403 — Akses Ditolak
 * Sistem SPK PT. CISAS
 * Halaman ini ditampilkan ketika pengguna mencoba membuka halaman
 * atau menjalankan fungsi yang tidak sesuai dengan hak akses perannya.
 * Kondisi pengguna:
 * - pengguna sudah login: kembali ke Dashboard;
 * - pengguna belum login: kembali ke halaman Login.
 */

// Muat Helper Autentikasi

require_once dirname(__DIR__)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'helpers'
    . DIRECTORY_SEPARATOR
    . 'auth.php';

// Pastikan Konfigurasi Aplikasi Termuat

app_config();

// Tetapkan Status HTTP 403

http_response_code(403);

// Informasi Pengguna

$currentUser =
    auth_user();

$isAuthenticated =
    $currentUser !== null;

$currentUserName =
    $isAuthenticated
        ? (
            auth_name()
            ?? 'Pengguna'
        )
        : 'Tamu';

$currentUserRole =
    $isAuthenticated
        ? auth_role()
        : null;

$currentRoleLabel =
    $isAuthenticated
        ? auth_role_label(
            $currentUserRole
        )
        : 'Tamu';

// Buat Inisial Pengguna

$userInitials = '?';

if (
    $isAuthenticated
    && trim($currentUserName) !== ''
) {
    $nameParts = preg_split(
        '/\s+/u',
        trim($currentUserName),
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    if (
        is_array($nameParts)
        && $nameParts !== []
    ) {
        $initialCharacters = [];

        foreach (
            array_slice(
                $nameParts,
                0,
                2
            )
            as $namePart
        ) {
            $initialCharacters[] =
                function_exists('mb_substr')
                    ? mb_substr(
                        $namePart,
                        0,
                        1,
                        'UTF-8'
                    )
                    : substr(
                        $namePart,
                        0,
                        1
                    );
        }

        $userInitials = implode(
            '',
            $initialCharacters
        );

        $userInitials =
            function_exists('mb_strtoupper')
                ? mb_strtoupper(
                    $userInitials,
                    'UTF-8'
                )
                : strtoupper(
                    $userInitials
                );
    }
}

// Tujuan Tombol Utama

$primaryPath =
    $isAuthenticated
        ? 'dashboard.php'
        : 'login.php';

$primaryLabel =
    $isAuthenticated
        ? 'Kembali ke Dashboard'
        : 'Masuk ke Sistem';

// Konfigurasi Halaman

$pageTitle =
    'Akses Ditolak';

$pageDescription =
    'Pengguna tidak mempunyai izin untuk '
    . 'mengakses halaman yang diminta.';

$bodyClass =
    'error-page error-page-403 '
    . 'd-flex flex-column min-vh-100';

// Muat Template Utama

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'header.php';

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'navbar.php';

?>

<?php if ($isAuthenticated): ?>

    <div class="d-lg-flex flex-grow-1">

        <?php
        require TEMPLATE_PATH
            . DIRECTORY_SEPARATOR
            . 'sidebar.php';
        ?>

        <main id="main-content" class="error-main-content flex-grow-1 d-flex align-items-center justify-content-center p-3 p-lg-4">
            <div class="error-page-container w-100" style="max-width: 720px;">
                <?php
                require TEMPLATE_PATH
                    . DIRECTORY_SEPARATOR
                    . 'alert.php';
                ?>

                <section class="error-card card border-0 shadow-sm" aria-labelledby="forbidden-title">
                    <div class="card-body text-center p-4 p-md-5">
                        <!-- Ikon akses ditolak -->
                        <div class="error-icon-wrapper error-icon-danger d-inline-flex align-items-center justify-content-center rounded-circle bg-danger-subtle text-danger mb-4" aria-hidden="true">
                            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="5" y="10" width="14" height="11" rx="2.5" stroke="currentColor" stroke-width="1.7"/>

                                <path d="M8 10V7C8 4.79086 9.79086 3 12 3C14.2091 3 16 4.79086 16 7V10" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>

                                <path d="M12 14V17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                            </svg>
                        </div>

                        <!-- Kode error -->
                        <span class="error-code-badge badge text-bg-danger rounded-pill px-3 py-2 mb-3">
                            Error 403
                        </span>

                        <!-- Judul -->
                        <h1 id="forbidden-title" class="error-title h2 mb-3">
                            Akses tidak diizinkan
                        </h1>

                        <!-- Deskripsi -->
                        <p class="error-description text-body-secondary mx-auto mb-4" style="max-width: 540px;">
                            Halaman atau fungsi yang Anda buka
                            tidak tersedia untuk peran

                            <strong class="text-body">
                                <?= e(
                                    $currentRoleLabel
                                ) ?>
                            </strong>.

                            Gunakan menu yang sesuai dengan
                            hak akses akun Anda.
                        </p>

                        <!-- Informasi pengguna -->
                        <div class="error-user-card rounded-4 bg-body-tertiary border p-3 p-md-4 mb-4">
                            <div class="d-flex flex-column flex-sm-row align-items-center justify-content-center gap-3">
                                <span class="error-user-avatar d-inline-flex align-items-center justify-content-center rounded-3 bg-danger text-white fw-bold" aria-hidden="true" style="width: 48px; height: 48px;">
                                    <?= e(
                                        $userInitials
                                    ) ?>
                                </span>

                                <div class="text-center text-sm-start">
                                    <small class="d-block text-body-secondary mb-1">
                                        Pengguna aktif
                                    </small>

                                    <strong class="d-block">
                                        <?= e(
                                            $currentUserName
                                        ) ?>
                                    </strong>

                                    <span class="badge text-bg-light border mt-2">
                                        <?= e(
                                            $currentRoleLabel
                                        ) ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Tombol navigasi -->
                        <div class="error-actions d-grid d-sm-flex justify-content-sm-center gap-2">
                            <a href="<?= e( base_url( $primaryPath ) ) ?>" class="btn btn-primary px-4">
                                <?= e(
                                    $primaryLabel
                                ) ?>
                            </a>

                            <button type="button" class="btn btn-outline-secondary px-4" onclick="history.back()">
                                Halaman Sebelumnya
                            </button>
                        </div>
                    </div>
                </section>

                <p class="error-help-text small text-body-secondary text-center mt-3 mb-0">
                    Hubungi Administrator apabila Anda
                    seharusnya memiliki akses ke halaman ini.
                </p>
            </div>
        </main>
    </div>

<?php else: ?>

    <main id="guest-error-content" class="error-main-content flex-grow-1 d-flex align-items-center justify-content-center p-3 py-5">
        <div class="error-page-container w-100" style="max-width: 620px;">
            <?php
            require TEMPLATE_PATH
                . DIRECTORY_SEPARATOR
                . 'alert.php';
            ?>

            <section class="error-card card border-0 shadow-sm" aria-labelledby="guest-forbidden-title">
                <div class="card-body text-center p-4 p-md-5">
                    <!-- Logo CISAS -->
                    <div class="mb-4">
                        <img src="<?= e( base_url( 'assets/images/logo_cisas.webp' ) ) ?>" class="error-page-logo" alt="Logo CISAS" width="150" height="58">
                    </div>

                    <!-- Ikon akses ditolak -->
                    <div class="error-icon-wrapper error-icon-danger d-inline-flex align-items-center justify-content-center rounded-circle bg-danger-subtle text-danger mb-4" aria-hidden="true">
                        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="5" y="10" width="14" height="11" rx="2.5" stroke="currentColor" stroke-width="1.7"/>

                            <path d="M8 10V7C8 4.79086 9.79086 3 12 3C14.2091 3 16 4.79086 16 7V10" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>

                            <path d="M12 14V17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>
                    </div>

                    <!-- Kode error -->
                    <span class="error-code-badge badge text-bg-danger rounded-pill px-3 py-2 mb-3">
                        Error 403
                    </span>

                    <!-- Judul -->
                    <h1 id="guest-forbidden-title" class="error-title h2 mb-3">
                        Halaman tidak dapat diakses
                    </h1>

                    <!-- Deskripsi -->
                    <p class="error-description text-body-secondary mx-auto mb-4" style="max-width: 480px;">
                        Anda belum mempunyai izin untuk
                        membuka halaman tersebut. Silakan
                        masuk menggunakan akun PT. CISAS
                        yang sesuai.
                    </p>

                    <!-- Tombol login -->
                    <a href="<?= e( base_url( 'login.php' ) ) ?>" class="btn btn-primary px-4">
                        Masuk ke Sistem
                    </a>

                    <!-- Identitas sistem -->
                    <div class="error-system-identity border-top mt-4 pt-4">
                        <strong class="d-block">
                            PT. CISAS
                        </strong>

                        <span class="d-block small text-body-secondary mt-1">
                            AHP · TOPSIS
                        </span>
                    </div>
                </div>
            </section>
        </div>
    </main>

<?php endif; ?>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

<?php

declare(strict_types=1);

/*
 * Halaman 404 — Data atau Halaman Tidak Ditemukan
 * Sistem SPK PT. CISAS
 * Halaman ini dapat digunakan untuk:
 * - URL yang tidak ditemukan;
 * - data motor yang tidak ditemukan;
 * - periode yang tidak ditemukan;
 * - pemeriksaan yang tidak ditemukan;
 * - data AHP atau TOPSIS yang tidak ditemukan.
 * Variabel opsional:
 * $notFoundMessage
 * Digunakan oleh halaman lain untuk memberikan pesan yang lebih spesifik.
 */

// Muat Helper Autentikasi

require_once dirname(__DIR__)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'helpers'
    . DIRECTORY_SEPARATOR
    . 'auth.php';

// Tetapkan Status HTTP 404

http_response_code(404);

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

// Pesan Halaman Tidak Ditemukan

$defaultNotFoundMessage =
    'Halaman atau data yang Anda cari '
    . 'tidak ditemukan di dalam sistem.';

$displayNotFoundMessage =
    isset($notFoundMessage)
    && is_string($notFoundMessage)
    && trim($notFoundMessage) !== ''

        ? trim($notFoundMessage)

        : $defaultNotFoundMessage;

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
    'Halaman Tidak Ditemukan';

$pageDescription =
    $displayNotFoundMessage;

$bodyClass =
    'error-page error-page-404 '
    . 'd-flex flex-column min-vh-100';

// Muat Header

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'header.php';

// Navbar Hanya untuk Pengguna yang Sudah Login

if ($isAuthenticated) {
    require TEMPLATE_PATH
        . DIRECTORY_SEPARATOR
        . 'navbar.php';
}

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

                <section class="error-card card border-0 shadow-sm" aria-labelledby="not-found-title">
                    <div class="card-body text-center p-4 p-md-5">
                        <!-- Ikon halaman tidak ditemukan -->
                        <div class="error-icon-wrapper error-icon-primary d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary mb-4" aria-hidden="true">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.7"/>

                                <path d="M16 16L21 21" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>

                                <path d="M8.5 9.2C8.8 8.2 9.7 7.5 10.8 7.5C12.1 7.5 13.2 8.5 13.2 9.8C13.2 11.4 11 11.6 11 13" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>

                                <circle cx="11" cy="15.7" r="0.8" fill="currentColor"/>
                            </svg>
                        </div>

                        <!-- Kode error -->
                        <span class="error-code-badge badge text-bg-primary rounded-pill px-3 py-2 mb-3">
                            Error 404
                        </span>

                        <!-- Judul -->
                        <h1 id="not-found-title" class="error-title h2 mb-3">
                            Halaman tidak ditemukan
                        </h1>

                        <!-- Pesan -->
                        <p class="error-description text-body-secondary mx-auto mb-4" style="max-width: 540px;">
                            <?= e(
                                $displayNotFoundMessage
                            ) ?>

                            Data mungkin telah dihapus,
                            dipindahkan, atau alamat yang
                            digunakan tidak sesuai.
                        </p>

                        <!-- Informasi pengguna -->
                        <div class="error-user-card rounded-4 bg-body-tertiary border p-3 p-md-4 mb-4">
                            <div class="d-flex flex-column flex-sm-row align-items-center justify-content-center gap-3">
                                <span class="error-user-avatar d-inline-flex align-items-center justify-content-center rounded-3 bg-primary text-white fw-bold" aria-hidden="true" style="width: 48px; height: 48px;">
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
                    Periksa kembali alamat halaman
                    atau buka modul melalui menu navigasi.
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

            <section class="error-card card border-0 shadow-sm" aria-labelledby="guest-not-found-title">
                <div class="card-body text-center p-4 p-md-5">
                    <!-- Logo CISAS -->
                    <div class="mb-4">
                        <img src="<?= e( base_url( 'assets/images/logo_cisas.webp' ) ) ?>" class="error-page-logo" alt="Logo CISAS" width="155" height="60">
                    </div>

                    <!-- Ikon halaman tidak ditemukan -->
                    <div class="error-icon-wrapper error-icon-primary d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary mb-4" aria-hidden="true">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="1.7"/>

                            <path d="M16 16L21 21" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>

                            <path d="M8.5 9.2C8.8 8.2 9.7 7.5 10.8 7.5C12.1 7.5 13.2 8.5 13.2 9.8C13.2 11.4 11 11.6 11 13" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>

                            <circle cx="11" cy="15.7" r="0.8" fill="currentColor"/>
                        </svg>
                    </div>

                    <!-- Kode error -->
                    <span class="error-code-badge badge text-bg-primary rounded-pill px-3 py-2 mb-3">
                        Error 404
                    </span>

                    <!-- Judul -->
                    <h1 id="guest-not-found-title" class="error-title h2 mb-3">
                        Halaman tidak ditemukan
                    </h1>

                    <!-- Pesan -->
                    <p class="error-description text-body-secondary mx-auto mb-4" style="max-width: 480px;">
                        <?= e(
                            $displayNotFoundMessage
                        ) ?>

                        Periksa kembali alamat yang dibuka
                        atau masuk melalui halaman utama sistem.
                    </p>

                    <!-- Tombol -->
                    <div class="d-grid d-sm-flex justify-content-sm-center gap-2">
                        <a href="<?= e( base_url( 'login.php' ) ) ?>" class="btn btn-primary px-4">
                            Masuk ke Sistem
                        </a>

                        <button type="button" class="btn btn-outline-secondary px-4" onclick="history.back()">
                            Halaman Sebelumnya
                        </button>
                    </div>

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

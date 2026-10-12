<?php

declare(strict_types=1);

/*
 * Navbar Sistem SPK PT. CISAS
 * Navbar menampilkan:
 * - tombol pembuka sidebar pada layar kecil;
 * - logo CISAS;
 * - judul halaman aktif;
 * - informasi pengguna;
 * - menu Profil Akun;
 * - menu Ubah Password;
 * - tindakan Keluar dari Sistem.
 */

// Pastikan Helper Tersedia

if (!function_exists('base_url')) {
    require_once dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . 'app'
        . DIRECTORY_SEPARATOR
        . 'helpers'
        . DIRECTORY_SEPARATOR
        . 'functions.php';
}

if (!function_exists('auth_user')) {
    require_once dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . 'app'
        . DIRECTORY_SEPARATOR
        . 'helpers'
        . DIRECTORY_SEPARATOR
        . 'auth.php';
}

if (!function_exists('csrf_field')) {
    require_once dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . 'app'
        . DIRECTORY_SEPARATOR
        . 'helpers'
        . DIRECTORY_SEPARATOR
        . 'csrf.php';
}

// Judul Halaman

$navbarPageTitle = isset($pageTitle)
    && is_string($pageTitle)
    && trim($pageTitle) !== ''
        ? trim($pageTitle)
        : 'Dashboard';

// Informasi Pengguna

$navbarUser = isset($currentUser)
    && is_array($currentUser)
        ? $currentUser
        : auth_user();

$navbarIsAuthenticated =
    is_array($navbarUser);

$navbarUserName = $navbarIsAuthenticated
    ? trim(
        (string) (
            $navbarUser['name']
            ?? 'Pengguna'
        )
    )
    : 'Tamu';

if ($navbarUserName === '') {
    $navbarUserName = 'Pengguna';
}

$navbarUserRole = $navbarIsAuthenticated
    ? trim(
        (string) (
            $navbarUser['role']
            ?? ''
        )
    )
    : '';

$navbarRoleLabel = $navbarIsAuthenticated
    ? auth_role_label(
        $navbarUserRole
    )
    : 'Tamu';

// Fungsi Membuat Inisial Pengguna

$createNavbarInitials = static function (
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

    $initials = implode(
        '',
        $initialCharacters
    );

    return function_exists('mb_strtoupper')
        ? mb_strtoupper(
            $initials,
            'UTF-8'
        )
        : strtoupper(
            $initials
        );
};

$navbarInitials =
    $createNavbarInitials(
        $navbarUserName
    );

// Tujuan Logo

$navbarHomeUrl = $navbarIsAuthenticated
    ? base_url('dashboard.php')
    : base_url('login.php');

?>

<nav id="top-navbar" class="navbar sticky-top" aria-label="Navigasi utama">
    <div class="container-fluid px-3 px-lg-4">

        <!-- Bagian kiri navbar -->
        <div class="d-flex align-items-center gap-2 gap-lg-3">
            <?php if (
                $navbarIsAuthenticated
            ): ?>

                <!-- Tombol sidebar layar kecil -->
                <button type="button" class="navbar-sidebar-toggle d-inline-flex d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#mainSidebar" aria-controls="mainSidebar" aria-label="Buka menu navigasi">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M4 7H20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>

                        <path d="M4 12H20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>

                        <path d="M4 17H20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                </button>

            <?php endif; ?>

            <!-- Logo CISAS -->
            <a href="<?= e( $navbarHomeUrl ) ?>" class="navbar-brand mb-0" aria-label="<?= $navbarIsAuthenticated ? 'Buka Dashboard' : 'Buka halaman Login' ?>">
                <img src="<?= e( base_url( 'assets/images/logo_cisas.webp' ) ) ?>" class="navbar-brand-logo" alt="Logo CISAS">
            </a>
        </div>

        <!-- Judul halaman aktif -->
        <div class="navbar-page-information d-none d-md-flex me-auto">
            <strong class="navbar-page-title">
                <?= e(
                    $navbarPageTitle
                ) ?>
            </strong>
        </div>

        <!-- Bagian kanan navbar -->
        <div class="ms-auto">

            <?php if (
                $navbarIsAuthenticated
            ): ?>

                <div class="dropdown">

                    <!-- Tombol akun -->
                    <button type="button" class="navbar-user-button" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false" aria-label="Buka menu akun">
                        <span class="navbar-user-avatar" aria-hidden="true">
                            <?= e(
                                $navbarInitials
                            ) ?>
                        </span>

                        <span class="navbar-user-information d-none d-md-flex">
                            <strong class="navbar-user-name">
                                <?= e(
                                    $navbarUserName
                                ) ?>
                            </strong>

                            <small class="navbar-user-role">
                                <?= e(
                                    $navbarRoleLabel
                                ) ?>
                            </small>
                        </span>

                        <span class="navbar-user-chevron" aria-hidden="true">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6 9L12 15L18 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                    </button>

                    <!-- Dropdown akun -->
                    <div class="dropdown-menu dropdown-menu-end navbar-user-dropdown mt-2">
                        <!-- Informasi pengguna -->
                        <div class="navbar-dropdown-profile">
                            <span class="navbar-dropdown-avatar" aria-hidden="true">
                                <?= e(
                                    $navbarInitials
                                ) ?>
                            </span>

                            <div class="min-w-0">
                                <strong class="d-block text-truncate">
                                    <?= e(
                                        $navbarUserName
                                    ) ?>
                                </strong>

                                <small class="d-block text-body-secondary mt-1">
                                    <?= e(
                                        $navbarRoleLabel
                                    ) ?>
                                </small>
                            </div>
                        </div>

                        <hr class="dropdown-divider">

                        <!-- Profil akun -->
                        <a href="<?= e( base_url( 'akun/profil.php' ) ) ?>" class="dropdown-item navbar-account-menu-item d-flex align-items-center gap-2">
                            <span class="dropdown-item-icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.7"/>

                                    <path d="M4 21C4 16.5817 7.58172 13 12 13C16.4183 13 20 16.5817 20 21" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                                </svg>
                            </span>

                            <span>
                                Profil Akun
                            </span>
                        </a>

                        <!-- Ubah password -->
                        <a href="<?= e( base_url( 'akun/password.php' ) ) ?>" class="dropdown-item navbar-account-menu-item d-flex align-items-center gap-2">
                            <span class="dropdown-item-icon" aria-hidden="true">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <rect x="4" y="10" width="16" height="11" rx="2" stroke="currentColor" stroke-width="1.7"/>

                                    <path d="M8 10V7C8 4.79086 9.79086 3 12 3C14.2091 3 16 4.79086 16 7V10" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>

                                    <path d="M12 14V17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                </svg>
                            </span>

                            <span>
                                Ubah Password
                            </span>
                        </a>

                        <hr class="dropdown-divider">

                        <!-- Keluar dari sistem -->
                        <form method="post" action="<?= e( base_url( 'logout.php' ) ) ?>" class="m-0">
                            <?= csrf_field(
                                'logout'
                            ) ?>

                            <button type="submit" class="dropdown-item navbar-logout-button d-flex align-items-center gap-2" data-confirm="Keluar dari Sistem SPK PT. CISAS?">
                                <span class="dropdown-item-icon" aria-hidden="true">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M10 17L15 12L10 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>

                                        <path d="M15 12H3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>

                                        <path d="M14 4H19C20.1046 4 21 4.89543 21 6V18C21 19.1046 20.1046 20 19 20H14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                    </svg>
                                </span>

                                <span>
                                    Keluar dari Sistem
                                </span>
                            </button>
                        </form>
                    </div>
                </div>

            <?php else: ?>

                <a href="<?= e( base_url( 'login.php' ) ) ?>" class="btn btn-primary">
                    Masuk
                </a>

            <?php endif; ?>

        </div>
    </div>
</nav>

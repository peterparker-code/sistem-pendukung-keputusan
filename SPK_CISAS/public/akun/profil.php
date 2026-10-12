<?php

declare(strict_types=1);

/*
 * Halaman Profil Akun
 * Sistem SPK PT. CISAS
 * Halaman ini digunakan oleh pengguna yang sedang login untuk melihat:
 * - nama lengkap;
 * - username;
 * - peran pengguna;
 * - status akun;
 * - tanggal akun dibuat;
 * - tanggal akun terakhir diperbarui.
 * Data profil tidak dapat diubah dari halaman ini.
 * Perubahan nama, username, peran, dan status dilakukan Administrator
 * melalui menu Data Pengguna.
 */

/*
 * 1. Muat Helper Autentikasi
 * File auth.php menyediakan fungsi:
 * - auth_require_login();
 * - auth_refresh_user();
 * - auth_user();
 * - auth_id();
 * - auth_name();
 * - auth_role();
 * - auth_role_label();
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'helpers'
    . DIRECTORY_SEPARATOR
    . 'auth.php';

/*
 * 2. Pengguna Wajib Login
 * Apabila pengguna belum login, sistem akan mengarahkannya ke login.php.
 */

$currentUser = auth_require_login();

/*
 * 3. Buka Koneksi Database
 * dirname(__DIR__, 2) mengarah dari:
 * public/akun/profil.php
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
 * 4. Periksa Apakah Akun Masih Aktif
 * Data sesi diperbarui berdasarkan data terakhir pada tabel users.
 * Jika akun sudah dinonaktifkan atau dihapus dari database,
 * pengguna tidak boleh melanjutkan akses.
 */

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun pengguna sudah tidak aktif '
        . 'atau tidak ditemukan.'
    );

    redirect('login.php');
}

// 5. Ambil Informasi Pengguna dari Sesi

$currentUser = auth_user() ?? [];

$currentUserId = auth_id();

$currentUserName = auth_name()
    ?? 'Pengguna';

$currentUserRole = auth_role()
    ?? '';

// 6. Pastikan ID Pengguna Valid

if ($currentUserId === null) {
    flash_error(
        'Sesi pengguna tidak valid. '
        . 'Silakan masuk kembali.'
    );

    redirect('login.php');
}

/*
 * 7. Fungsi Membuat Inisial Pengguna
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

    // Pecah Nama Berdasarkan Spasi

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

    // Ambil Maksimal Dua Kata Pertama

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

    // Ubah Menjadi Huruf Kapital

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
 * 8. Fungsi Format Tanggal Indonesia
 * Contoh nilai database:
 * 2026-08-01 15:30:00
 * Akan ditampilkan menjadi:
 * 1 Agustus 2026, 15:30 WIB
 */

$formatAccountDateTime = static function (
    mixed $value
): string {
    if (
        $value === null
        || trim((string) $value) === ''
    ) {
        return '-';
    }

    try {
        $dateTime = new DateTimeImmutable(
            (string) $value
        );

        $monthNames = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        $monthNumber = (int) $dateTime->format(
            'n'
        );

        $monthName = $monthNames[$monthNumber]
            ?? $dateTime->format('F');

        return $dateTime->format('j')
            . ' '
            . $monthName
            . ' '
            . $dateTime->format('Y')
            . ', '
            . $dateTime->format('H:i')
            . ' WIB';
    } catch (Throwable) {
        /*
 * Fallback
 * Jika nilai tanggal tidak dapat dibaca, tampilkan nilai aslinya.
 */

        return (string) $value;
    }
};

/*
 * 9. Fungsi Ikon Halaman Profil
 * Ikon dibuat langsung menggunakan SVG agar tidak membutuhkan
 * library ikon tambahan.
 */

$accountIcon = static function (
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
        // Ikon Nama Pengguna

        'user' => '
            <circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.7"/>

            <path d="M4 21C4 16.5817 7.58172 13 12 13C16.4183 13 20 16.5817 20 21" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
        ',

        // Ikon Username

        'username' => '
            <circle cx="9" cy="8" r="4" stroke="currentColor" stroke-width="1.7"/>

            <path d="M2 21C2 16.5817 5.13401 13 9 13C12.866 13 16 16.5817 16 21" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>

            <path d="M18 8V15M21.5 11.5H14.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
        ',

        // Ikon Peran Pengguna

        'role' => '
            <path d="M12 3L20 7V12C20 16.9706 16.5817 21 12 22C7.41828 21 4 16.9706 4 12V7L12 3Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>

            <path d="M9 12L11 14L15 10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        ',

        // Ikon Status

        'status' => '
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/>

            <path d="M8 12L11 15L16 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        ',

        // Ikon Kalender

        'calendar' => '
            <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7"/>

            <path d="M16 3V7M8 3V7M3 10H21" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
        ',

        // Ikon Waktu

        'clock' => '
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/>

            <path d="M12 7V12L15.5 14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        ',

        // Ikon Keamanan

        'lock' => '
            <rect x="4" y="10" width="16" height="11" rx="2" stroke="currentColor" stroke-width="1.7"/>

            <path d="M8 10V7C8 4.79086 9.79086 3 12 3C14.2091 3 16 4.79086 16 7V10" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>

            <path d="M12 14V17" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
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

/*
 * 10. Nilai Awal Profil
 * Nilai ini menjadi fallback apabila query database gagal.
 * Perhatikan penulisan cast string:
 * Benar:
 * (string) (...)
 * Salah:
 * string(...)
 */

$profileError = null;

$accountProfile = [
    'id' => $currentUserId,

    'name' => $currentUserName,

    'username' => (string) (
        $currentUser['username']
        ?? '-'
    ),

    'role' => $currentUserRole,

    'is_active' => 1,

    'created_at' => null,

    'updated_at' => null,
];

/*
 * 11. Ambil Profil dari Database
 * Query mengambil satu pengguna berdasarkan ID pengguna yang sedang login.
 * :user_id merupakan named placeholder.
 */

try {
    $profileStatement = $pdo->prepare(
        "SELECT
            id,
            name,
            username,
            role,
            is_active,
            created_at,
            updated_at

         FROM users

         WHERE id = :user_id

         LIMIT 1"
    );

    // Isi Placeholder :user_id

    $profileStatement->execute([
        'user_id' => $currentUserId,
    ]);

    // Ambil Satu Baris Hasil

    $profileData = $profileStatement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!is_array($profileData)) {
        throw new RuntimeException(
            'Data akun pengguna tidak ditemukan.'
        );
    }

    $accountProfile = $profileData;
} catch (Throwable $exception) {
    /*
 * Catat Error Asli ke Log
 * Pesan teknis tidak ditampilkan langsung kepada pengguna.
 */

    error_log(
        'Profil akun SPK PT. CISAS gagal dimuat: '
        . $exception->getMessage()
    );

    $profileError =
        'Sebagian informasi akun belum dapat dimuat. '
        . 'Silakan coba kembali.';
}

// 12. Normalisasi Nama

$profileName = trim(
    (string) (
        $accountProfile['name']
        ?? $currentUserName
    )
);

if ($profileName === '') {
    $profileName = 'Pengguna';
}

// 13. Normalisasi Username

$profileUsername = trim(
    (string) (
        $accountProfile['username']
        ?? '-'
    )
);

if ($profileUsername === '') {
    $profileUsername = '-';
}

// 14. Normalisasi Peran

$profileRole = trim(
    (string) (
        $accountProfile['role']
        ?? $currentUserRole
    )
);

$profileRoleLabel = auth_role_label(
    $profileRole
);

/*
 * 15. Normalisasi Status
 * Nilai is_active:
 * 1 = Aktif
 * 0 = Tidak Aktif
 */

$profileIsActive = (int) (
    $accountProfile['is_active']
    ?? 0
) === 1;

$profileStatusLabel = $profileIsActive
    ? 'Aktif'
    : 'Tidak Aktif';

$profileStatusClass = $profileIsActive
    ? 'success'
    : 'secondary';

// 16. Format Tanggal Akun

$profileCreatedAt = $formatAccountDateTime(
    $accountProfile['created_at']
    ?? null
);

$profileUpdatedAt = $formatAccountDateTime(
    $accountProfile['updated_at']
    ?? null
);

// 17. Buat Inisial Profil

$profileInitials = $createAccountInitials(
    $profileName
);

// 18. Konfigurasi Halaman

$pageTitle = 'Profil Akun';

$pageDescription =
    'Informasi akun pengguna yang sedang masuk '
    . 'ke dalam Sistem SPK PT. CISAS.';

$bodyClass =
    'd-flex flex-column min-vh-100 account-page';

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

        <?php if ($profileError !== null): ?>

            <div class="alert alert-danger mb-4" role="alert">
                <strong class="d-block mb-1">
                    Profil akun belum dapat dimuat
                </strong>

                <?= e(
                    $profileError
                ) ?>
            </div>

        <?php endif; ?>

        <!-- Kepala Halaman -->
        <header class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <h1 class="page-title">
                    Profil Akun
                </h1>

                <p class="page-description">
                    Lihat informasi akun yang sedang
                    digunakan untuk masuk ke sistem.
                </p>
            </div>

            <!-- Tombol Kembali Tanpa Ikon -->
            <a href="<?= e( base_url( 'dashboard.php' ) ) ?>" class="btn btn-outline-secondary">
                Kembali
            </a>
        </header>

        <div class="row g-4">

            <!-- Ringkasan Profil -->
            <div class="col-xl-4">

                <section class="card account-profile-summary h-100 border-0 shadow-sm" aria-labelledby="profile-summary-title">
                    <div class="card-body p-4 text-center">
                        <!-- Inisial Pengguna -->
                        <div class="account-profile-avatar mx-auto mb-3" aria-hidden="true">
                            <?= e(
                                $profileInitials
                            ) ?>
                        </div>

                        <!-- Nama Pengguna -->
                        <h2 id="profile-summary-title" class="h4 mb-2">
                            <?= e(
                                $profileName
                            ) ?>
                        </h2>

                        <!-- Username -->
                        <p class="text-body-secondary mb-3">
                            @<?= e(
                                $profileUsername
                            ) ?>
                        </p>

                        <!-- Badge Peran dan Status -->
                        <div class="d-flex flex-wrap justify-content-center gap-2 mb-4">
                            <span class="badge text-bg-primary rounded-pill px-3 py-2">
                                <?= e(
                                    $profileRoleLabel
                                ) ?>
                            </span>

                            <span class="badge text-bg-<?= e( $profileStatusClass ) ?> rounded-pill px-3 py-2">
                                <?= e(
                                    $profileStatusLabel
                                ) ?>
                            </span>
                        </div>

                        <!-- Informasi Keamanan -->
                        <div class="account-profile-security rounded-4 p-3 mb-4">
                            <span class="account-profile-security-icon" aria-hidden="true">
                                <?= $accountIcon(
                                    'lock',
                                    22
                                ) ?>
                            </span>

                            <div class="text-start">
                                <strong class="d-block">
                                    Keamanan Akun
                                </strong>

                                <small class="text-body-secondary d-block mt-1">
                                    Perbarui password secara
                                    berkala untuk menjaga
                                    keamanan akun.
                                </small>
                            </div>
                        </div>

                        <!-- Tombol Tanpa Ikon -->
                        <a href="<?= e( base_url( 'akun/password.php' ) ) ?>" class="btn btn-primary w-100">
                            Ubah Password
                        </a>
                    </div>
                </section>
            </div>

            <!-- Informasi Akun -->
            <div class="col-xl-8">

                <section class="card account-information-card h-100 border-0 shadow-sm" aria-labelledby="account-information-title">
                    <!-- Kepala Card -->
                    <div class="card-header bg-white py-3 px-4">
                        <h2 id="account-information-title" class="h5 mb-1">
                            Informasi Akun
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Data identitas dan status
                            akun pengguna.
                        </p>
                    </div>

                    <!-- Isi Card -->
                    <div class="card-body p-4">

                        <div class="row row-cols-1 row-cols-md-2 g-3">
                            <!-- Nama Lengkap -->
                            <div class="col">

                                <article class="account-information-item h-100">
                                    <span class="account-information-icon" aria-hidden="true">
                                        <?= $accountIcon(
                                            'user'
                                        ) ?>
                                    </span>

                                    <div class="min-w-0">
                                        <small>
                                            Nama Lengkap
                                        </small>

                                        <strong>
                                            <?= e(
                                                $profileName
                                            ) ?>
                                        </strong>
                                    </div>
                                </article>
                            </div>

                            <!-- Username -->
                            <div class="col">

                                <article class="account-information-item h-100">
                                    <span class="account-information-icon" aria-hidden="true">
                                        <?= $accountIcon(
                                            'username'
                                        ) ?>
                                    </span>

                                    <div class="min-w-0">
                                        <small>
                                            Username
                                        </small>

                                        <strong>
                                            <?= e(
                                                $profileUsername
                                            ) ?>
                                        </strong>
                                    </div>
                                </article>
                            </div>

                            <!-- Peran Pengguna -->
                            <div class="col">

                                <article class="account-information-item h-100">
                                    <span class="account-information-icon" aria-hidden="true">
                                        <?= $accountIcon(
                                            'role'
                                        ) ?>
                                    </span>

                                    <div class="min-w-0">
                                        <small>
                                            Peran Pengguna
                                        </small>

                                        <strong>
                                            <?= e(
                                                $profileRoleLabel
                                            ) ?>
                                        </strong>
                                    </div>
                                </article>
                            </div>

                            <!-- Status Akun -->
                            <div class="col">

                                <article class="account-information-item h-100">
                                    <span class="account-information-icon account-information-icon-<?= e( $profileStatusClass ) ?>" aria-hidden="true">
                                        <?= $accountIcon(
                                            'status'
                                        ) ?>
                                    </span>

                                    <div class="min-w-0">
                                        <small>
                                            Status Akun
                                        </small>

                                        <strong>
                                            <?= e(
                                                $profileStatusLabel
                                            ) ?>
                                        </strong>
                                    </div>
                                </article>
                            </div>

                            <!-- Akun Dibuat -->
                            <div class="col">

                                <article class="account-information-item h-100">
                                    <span class="account-information-icon" aria-hidden="true">
                                        <?= $accountIcon(
                                            'calendar'
                                        ) ?>
                                    </span>

                                    <div class="min-w-0">
                                        <small>
                                            Akun Dibuat
                                        </small>

                                        <strong>
                                            <?= e(
                                                $profileCreatedAt
                                            ) ?>
                                        </strong>
                                    </div>
                                </article>
                            </div>

                            <!-- Terakhir Diperbarui -->
                            <div class="col">

                                <article class="account-information-item h-100">
                                    <span class="account-information-icon" aria-hidden="true">
                                        <?= $accountIcon(
                                            'clock'
                                        ) ?>
                                    </span>

                                    <div class="min-w-0">
                                        <small>
                                            Terakhir Diperbarui
                                        </small>

                                        <strong>
                                            <?= e(
                                                $profileUpdatedAt
                                            ) ?>
                                        </strong>
                                    </div>
                                </article>
                            </div>
                        </div>

                        <!-- Catatan Hak Perubahan Data -->
                        <div class="account-information-note mt-4">
                            <span aria-hidden="true">
                                <?= $accountIcon(
                                    'status',
                                    20
                                ) ?>
                            </span>

                            <p class="mb-0">
                                Nama, username, peran, dan
                                status akun dikelola melalui
                                menu Data Pengguna oleh
                                Administrator. Pengguna hanya
                                dapat mengubah password
                                akunnya sendiri.
                            </p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>

<?php

// 22. Muat Footer

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

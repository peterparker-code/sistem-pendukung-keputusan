<?php

declare(strict_types=1);

/*
 * Sidebar Sistem SPK PT. CISAS
 * Sidebar hanya menampilkan menu navigasi.
 * Menu dibedakan berdasarkan peran:
 * - Administrator;
 * - Supervisor Maintenance;
 * - Teknisi Maintenance.
 * Pada layar kecil sidebar menggunakan offcanvas Bootstrap.
 * Pada layar besar sidebar tampil tetap di sebelah kiri.
 * Catatan:
 * - Sidebar bukan pengganti pemeriksaan hak akses.
 * - Setiap halaman tujuan tetap wajib memvalidasi role dan kepemilikan data.
 */

// Pastikan Helper Tersedia

if (!function_exists('app_config')) {
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

// Ambil Pengguna Aktif

$sidebarUser = auth_user();

if (!is_array($sidebarUser)) {
    return;
}

$sidebarRole = trim(
    (string) (
        $sidebarUser['role']
        ?? ''
    )
);

$sidebarAllowedRoles = [
    'admin',
    'supervisor',
    'technician',
];

if (
    !in_array(
        $sidebarRole,
        $sidebarAllowedRoles,
        true
    )
) {
    return;
}

// Tentukan Halaman yang Sedang Dibuka

$requestUri = (string) (
    $_SERVER['REQUEST_URI']
    ?? ''
);

$requestPath = parse_url(
    $requestUri,
    PHP_URL_PATH
);

$sidebarCurrentPath = is_string($requestPath)
    ? trim(
        str_replace(
            '\\',
            '/',
            $requestPath
        ),
        '/'
    )
    : '';

// Hapus Base Path apabila Aplikasi Berada dalam Subfolder

$configuredBaseUrl = (string) app_config(
    'base_url',
    'http://localhost:8000'
);

$configuredBasePath = parse_url(
    $configuredBaseUrl,
    PHP_URL_PATH
);

$configuredBasePath = is_string(
    $configuredBasePath
)
    ? trim(
        str_replace(
            '\\',
            '/',
            $configuredBasePath
        ),
        '/'
    )
    : '';

if (
    $configuredBasePath !== ''
    && (
        $sidebarCurrentPath === $configuredBasePath

        || str_starts_with(
            $sidebarCurrentPath,
            $configuredBasePath . '/'
        )
    )
) {
    $sidebarCurrentPath = ltrim(
        substr(
            $sidebarCurrentPath,
            strlen($configuredBasePath)
        ),
        '/'
    );
}

/*
 * Normalisasi Folder Public
 * Pada beberapa konfigurasi server, base_url dapat menunjuk ke folder proyek,
 * sedangkan REQUEST_URI masih memuat segmen "public".
 * Aturan menu menggunakan path relatif dari document root aplikasi,
 * sehingga segmen "public" dihapus dari path yang sedang dibuka.
 */

if ($sidebarCurrentPath === 'public') {
    $sidebarCurrentPath = '';
} elseif (
    str_starts_with(
        $sidebarCurrentPath,
        'public/'
    )
) {
    $sidebarCurrentPath = substr(
        $sidebarCurrentPath,
        strlen('public/')
    );
}

// Fallback Berdasarkan SCRIPT_NAME

if ($sidebarCurrentPath === '') {
    $scriptName = str_replace(
        '\\',
        '/',
        (string) (
            $_SERVER['SCRIPT_NAME']
            ?? ''
        )
    );

    $scriptName = trim(
        $scriptName,
        '/'
    );

    if ($scriptName === 'public') {
        $scriptName = '';
    } elseif (
        str_starts_with(
            $scriptName,
            'public/'
        )
    ) {
        $scriptName = substr(
            $scriptName,
            strlen('public/')
        );
    }

    $sidebarCurrentPath =
        $scriptName;
}

/*
 * Ambil Query String
 * Query hanya digunakan untuk menentukan menu aktif.
 * Nilai array atau struktur bertingkat tidak digunakan.
 */

$sidebarCurrentQuery = [];

foreach (
    $_GET
    as $queryKey => $queryValue
) {
    if (
        !is_scalar($queryValue)
        && $queryValue !== null
    ) {
        continue;
    }

    $sidebarCurrentQuery[
        (string) $queryKey
    ] = (string) $queryValue;
}

// Fungsi Pemeriksaan Menu Aktif

$sidebarIsActive = static function (
    array $activeRules
) use (
    $sidebarCurrentPath,
    $sidebarCurrentQuery
): bool {
    foreach ($activeRules as $rule) {
        if (!is_array($rule)) {
            continue;
        }

        $exactPath = trim(
            (string) (
                $rule['path']
                ?? ''
            ),
            '/'
        );

        $prefixPath = trim(
            (string) (
                $rule['prefix']
                ?? ''
            ),
            '/'
        );

        $pathMatches = false;

        if ($exactPath !== '') {
            $pathMatches =
                $sidebarCurrentPath === $exactPath;
        } elseif ($prefixPath !== '') {
            $pathMatches =
                $sidebarCurrentPath === $prefixPath

                || str_starts_with(
                    $sidebarCurrentPath,
                    $prefixPath . '/'
                );
        }

        if (!$pathMatches) {
            continue;
        }

        // Query yang Wajib Sesuai

        $requiredQuery = $rule['query']
            ?? [];

        if (!is_array($requiredQuery)) {
            $requiredQuery = [];
        }

        $requiredQueryMatches = true;

        foreach (
            $requiredQuery
            as $queryKey => $queryValue
        ) {
            $normalizedQueryKey =
                (string) $queryKey;

            if (
                !array_key_exists(
                    $normalizedQueryKey,
                    $sidebarCurrentQuery
                )

                || (string) $sidebarCurrentQuery[
                    $normalizedQueryKey
                ] !== (string) $queryValue
            ) {
                $requiredQueryMatches = false;

                break;
            }
        }

        if (!$requiredQueryMatches) {
            continue;
        }

        // Query yang Mengecualikan Menu

        $excludedQuery = $rule['exclude_query']
            ?? [];

        if (!is_array($excludedQuery)) {
            $excludedQuery = [];
        }

        $isExcluded = false;

        foreach (
            $excludedQuery
            as $queryKey => $queryValue
        ) {
            $normalizedQueryKey =
                (string) $queryKey;

            if (
                array_key_exists(
                    $normalizedQueryKey,
                    $sidebarCurrentQuery
                )

                && (string) $sidebarCurrentQuery[
                    $normalizedQueryKey
                ] === (string) $queryValue
            ) {
                $isExcluded = true;

                break;
            }
        }

        if (!$isExcluded) {
            return true;
        }
    }

    return false;
};

// Ikon Menu

$sidebarIcon = static function (
    string $iconName
): string {
    return match ($iconName) {
        // Dashboard

        'dashboard' => '
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="3" y="3" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/>

                <rect x="14" y="3" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/>

                <rect x="3" y="14" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/>

                <rect x="14" y="14" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/>
            </svg>
        ',

        // Pengguna

        'users' => '
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M16 21V19C16 16.7909 14.2091 15 12 15H6C3.79086 15 2 16.7909 2 19V21" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>

                <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.7"/>

                <path d="M17 11C18.6569 11 20 9.65685 20 8C20 6.34315 18.6569 5 17 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>

                <path d="M22 21V19C21.9986 17.1771 20.765 15.5857 19 15.13" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
            </svg>
        ',

        // Motor

        'motor' => '
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="5" y="5" width="14" height="14" rx="3" stroke="currentColor" stroke-width="1.7"/>

                <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.7"/>

                <path d="M9 2V5M15 2V5M9 19V22M15 19V22M2 9H5M2 15H5M19 9H22M19 15H22" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
            </svg>
        ',

        // Periode

        'calendar' => '
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7"/>

                <path d="M16 3V7M8 3V7M3 10H21" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>

                <path d="M8 14H10M14 14H16M8 17H10" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
            </svg>
        ',

        // Pemeriksaan

        'clipboard' => '
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="5" y="4" width="14" height="17" rx="2" stroke="currentColor" stroke-width="1.7"/>

                <path d="M9 4V3C9 2.44772 9.44772 2 10 2H14C14.5523 2 15 2.44772 15 3V4" stroke="currentColor" stroke-width="1.7"/>

                <path d="M8 10H16M8 14H16M8 18H13" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
            </svg>
        ',

        // Validasi

        'validation' => '
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M9 11L12 14L20 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>

                <path d="M20 12V19C20 20.1046 19.1046 21 18 21H5C3.89543 21 3 20.1046 3 19V6C3 4.89543 3.89543 4 5 4H15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
            </svg>
        ',

        // Riwayat

        'history' => '
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12C21 16.9706 16.9706 21 12 21C8.7 21 5.81 19.22 4.25 16.57" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>

                <path d="M3 7V12H8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>

                <path d="M12 7V12L15 14" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        ',

        // AHP

        'ahp' => '
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 3L21 8L12 13L3 8L12 3Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>

                <path d="M3 12L12 17L21 12M3 16L12 21L21 16" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        ',

        // TOPSIS

        'topsis' => '
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M4 19V10M10 19V5M16 19V13M22 19V2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>

                <path d="M2 19H22" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
            </svg>
        ',

        // Laporan

        'report' => '
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M6 2H14L19 7V20C19 21.1046 18.1046 22 17 22H6C4.89543 22 4 21.1046 4 20V4C4 2.89543 4.89543 2 6 2Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>

                <path d="M14 2V7H19M8 12H15M8 16H15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
            </svg>
        ',

        // Ikon Default

        default => '
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/>

                <circle cx="12" cy="12" r="2" fill="currentColor"/>
            </svg>
        ',
    };
};

// Daftar Menu Sidebar
//
// Administrator adalah otoritas sistem tertinggi, tetapi hak akses proses
// keputusan teknis tetap dipisahkan berdasarkan tanggung jawab:
// Supervisor = validasi/AHP/TOPSIS/rekomendasi,
// Teknisi = pemeriksaan/tindak lanjut.

$sidebarMenus = [
    // Utama

    [
        'section' => 'Utama',

        'items' => [
            [
                'label' => 'Dashboard',
                'icon' => 'dashboard',
                'path' => 'dashboard.php',

                'active_rules' => [
                    [
                        'path' => 'dashboard.php',
                    ],
                ],

                'roles' => [
                    'admin',
                    'supervisor',
                    'technician',
                ],
            ],
        ],
    ],

    // Master Data

    [
        'section' => 'Master Data',

        'items' => [
            [
                'label' => 'Data Pengguna',
                'icon' => 'users',
                'path' => 'pengguna/index.php',

                'active_rules' => [
                    [
                        'prefix' => 'pengguna',
                    ],
                ],

                'roles' => [
                    'admin',
                ],
            ],

            [
                'label' => 'Data Motor',
                'icon' => 'motor',
                'path' => 'motor/index.php',

                'active_rules' => [
                    [
                        'prefix' => 'motor',
                    ],
                ],

                'roles' => [
                    'admin',
                    'supervisor',
                    'technician',
                ],
            ],

            [
                'label' => 'Periode Pemeriksaan',
                'icon' => 'calendar',
                'path' => 'periode/index.php',

                'active_rules' => [
                    [
                        'prefix' => 'periode',
                    ],
                ],

                'roles' => [
                    'admin',
                    'supervisor',
                ],
            ],
        ],
    ],

    // Pemeriksaan

    [
        'section' => 'Pemeriksaan',

        'items' => [
            [
                'label' => 'Pemeriksaan Motor',
                'icon' => 'clipboard',
                'path' => 'pemeriksaan/index.php',

                'active_rules' => [
                    [
                        'path' => 'pemeriksaan/index.php',

                        'exclude_query' => [
                            'mode' => 'validation',
                        ],
                    ],

                    [
                        'path' => 'pemeriksaan/detail.php',
                    ],
                ],

                'roles' => [
                    'admin',
                ],
            ],

            [
                'label' => 'Pemeriksaan Motor',
                'icon' => 'clipboard',
                'path' => 'pemeriksaan/index.php',

                'active_rules' => [
                    [
                        'path' => 'pemeriksaan/index.php',

                        'exclude_query' => [
                            'mode' => 'validation',
                        ],
                    ],

                    [
                        'path' => 'pemeriksaan/create.php',
                    ],

                    [
                        'path' => 'pemeriksaan/edit.php',
                    ],

                    [
                        'path' => 'pemeriksaan/detail.php',
                    ],
                ],

                'roles' => [
                    'supervisor',
                    'technician',
                ],
            ],

            [
                'label' => 'Validasi Pemeriksaan',
                'icon' => 'validation',
                'path' => 'pemeriksaan/index.php?mode=validation',

                'active_rules' => [
                    [
                        'path' => 'pemeriksaan/index.php',

                        'query' => [
                            'mode' => 'validation',
                        ],
                    ],

                    [
                        'path' => 'pemeriksaan/validasi.php',
                    ],
                ],

                'roles' => [
                    'supervisor',
                ],
            ],

            [
                'label' => 'Riwayat Pemeliharaan',
                'icon' => 'history',
                'path' => 'riwayat/index.php',

                'active_rules' => [
                    [
                        'prefix' => 'riwayat',
                    ],
                ],

                'roles' => [
                    'admin',
                    'supervisor',
                    'technician',
                ],
            ],
        ],
    ],

    // Perhitungan SPK

    [
        'section' => 'Perhitungan SPK',

        'items' => [
            [
                'label' => 'Pembobotan AHP',
                'icon' => 'ahp',
                'path' => 'ahp/index.php',

                'active_rules' => [
                    [
                        'prefix' => 'ahp',
                    ],
                ],

                'roles' => [
                    'admin',
                    'supervisor',
                ],
            ],

            [
                'label' => 'Pemeringkatan TOPSIS',
                'icon' => 'topsis',
                'path' => 'topsis/index.php',

                'active_rules' => [
                    ['path' => 'topsis/index.php'],
                    ['path' => 'topsis/hasil.php'],
                    ['path' => 'topsis/detail.php'],
                ],

                'roles' => [
                    'admin',
                    'supervisor',
                ],
            ],


            [
                'label' => 'Riwayat Perhitungan',
                'icon' => 'history',
                'path' => 'perhitungan/riwayat.php',
                'active_rules' => [
                    ['prefix' => 'perhitungan'],
                ],
                'roles' => ['admin', 'supervisor'],
            ],
        ],
    ],

    // Pelaporan

    [
        'section' => 'Pelaporan',

        'items' => [
            [
                'label' => 'Laporan',

                'icon' => 'report',
                'path' => 'laporan/index.php',

                'active_rules' => [
                    [
                        'prefix' => 'laporan',
                    ],
                ],

                'roles' => [
                    'admin',
                    'supervisor',
                    'technician',
                ],
            ],

            [
                'label' => 'Log Aktivitas',
                'icon' => 'history',
                'path' => 'audit/index.php',
                'active_rules' => [
                    ['prefix' => 'audit'],
                ],
                'roles' => ['admin'],
            ],
        ],
    ],
];

// Fungsi Menyaring Menu Berdasarkan Peran

$sidebarVisibleItems = static function (
    array $items
) use (
    $sidebarRole
): array {
    return array_values(
        array_filter(
            $items,

            static function (
                array $item
            ) use (
                $sidebarRole
            ): bool {
                $allowedRoles = $item['roles']
                    ?? [];

                return is_array($allowedRoles)
                    && in_array(
                        $sidebarRole,
                        $allowedRoles,
                        true
                    );
            }
        )
    );
};

?>

<aside id="mainSidebar" class="offcanvas-lg offcanvas-start border-end bg-white" tabindex="-1" aria-labelledby="mainSidebarLabel" style="--bs-offcanvas-width: 286px;">
    <!-- Header sidebar hanya pada layar kecil -->
    <div class="offcanvas-header border-bottom d-lg-none">
        <a href="<?= e( base_url( 'dashboard.php' ) ) ?>" class="d-inline-flex align-items-center text-decoration-none" aria-label="Buka Dashboard">
            <img src="<?= e( base_url( 'assets/images/logo_cisas.webp' ) ) ?>" class="sidebar-logo" alt="Logo CISAS">
        </a>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="offcanvas"
            data-bs-target="#mainSidebar"
            aria-label="Tutup menu navigasi"
        ></button>
    </div>

    <!-- Isi sidebar -->
    <div class="offcanvas-body d-flex flex-column p-0">
        <nav class="flex-grow-1 overflow-y-auto p-3" aria-label="Menu samping">
            <?php foreach (
                $sidebarMenus
                as $menuSection
            ): ?>

                <?php

                $sectionItems = $sidebarVisibleItems(
                    $menuSection['items']
                    ?? []
                );

                if ($sectionItems === []) {
                    continue;
                }

                ?>

                <section class="sidebar-menu-section mb-4">
                    <h2 class="sidebar-section-label">
                        <?= e(
                            (string) (
                                $menuSection['section']
                                ?? 'Menu'
                            )
                        ) ?>
                    </h2>

                    <ul class="nav nav-pills flex-column">
                        <?php foreach (
                            $sectionItems
                            as $menuItem
                        ): ?>

                            <?php

                            $menuPath = (string) (
                                $menuItem['path']
                                ?? ''
                            );

                            $activeRules = $menuItem[
                                'active_rules'
                            ] ?? [];

                            $isActive = $sidebarIsActive(
                                is_array($activeRules)
                                    ? $activeRules
                                    : []
                            );

                            $menuClass = $isActive
                                ? 'nav-link active'
                                : 'nav-link';

                            ?>

                            <li class="nav-item">
                                <a href="<?= e( base_url( $menuPath ) ) ?>" class="<?= e( $menuClass ) ?>" <?php if ( $isActive ): ?> aria-current="page" <?php endif; ?>>
                                    <span class="nav-icon" aria-hidden="true">
                                        <?= $sidebarIcon(
                                            (string) (
                                                $menuItem['icon']
                                                ?? ''
                                            )
                                        ) ?>
                                    </span>

                                    <span class="flex-grow-1">
                                        <?= e(
                                            (string) (
                                                $menuItem['label']
                                                ?? 'Menu'
                                            )
                                        ) ?>
                                    </span>

                                    <?php if (
                                        $isActive
                                    ): ?>
                                        <span
                                            class="sidebar-active-dot"
                                            aria-hidden="true"
                                        ></span>
                                    <?php endif; ?>
                                </a>
                            </li>

                        <?php endforeach; ?>
                    </ul>
                </section>

            <?php endforeach; ?>
        </nav>
    </div>
</aside>

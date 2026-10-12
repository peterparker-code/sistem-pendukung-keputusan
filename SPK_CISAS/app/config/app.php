<?php

declare(strict_types=1);

/*
 * Konfigurasi Utama Aplikasi
 * File ini menyimpan identitas aplikasi, zona waktu, lokasi folder,
 * daftar peran pengguna, dan pengaturan dasar sistem.
 */

date_default_timezone_set('Asia/Jakarta');

// Lokasi Folder Proyek

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

if (!defined('APP_PATH')) {
    define(
        'APP_PATH',
        BASE_PATH . DIRECTORY_SEPARATOR . 'app'
    );
}

if (!defined('PUBLIC_PATH')) {
    define(
        'PUBLIC_PATH',
        BASE_PATH . DIRECTORY_SEPARATOR . 'public'
    );
}

if (!defined('DATABASE_PATH')) {
    define(
        'DATABASE_PATH',
        BASE_PATH . DIRECTORY_SEPARATOR . 'database'
    );
}

if (!defined('TEMPLATE_PATH')) {
    define(
        'TEMPLATE_PATH',
        BASE_PATH . DIRECTORY_SEPARATOR . 'templates'
    );
}

if (!defined('TEST_PATH')) {
    define(
        'TEST_PATH',
        BASE_PATH . DIRECTORY_SEPARATOR . 'tests'
    );
}

/*
 * Lingkungan Aplikasi
 * Nilai dapat diatur melalui environment variable tanpa mengubah file:
 * - APP_ENV=development|production
 * - APP_DEBUG=true|false
 * - APP_URL=http://localhost:8000
 */

$appEnvironment = trim(
    (string) (
        getenv('APP_ENV')
        ?: 'production'
    )
);

if (!in_array(
    $appEnvironment,
    [
        'development',
        'production',
    ],
    true
)) {
    $appEnvironment = 'production';
}

$appDebugEnvironment = getenv('APP_DEBUG');

$appDebug = $appDebugEnvironment === false
    ? $appEnvironment !== 'production'
    : filter_var(
        $appDebugEnvironment,
        FILTER_VALIDATE_BOOL
    );

$appBaseUrl = trim(
    (string) (
        getenv('APP_URL')
        ?: 'http://localhost:8000'
    )
);

if ($appBaseUrl === '') {
    $appBaseUrl = 'http://localhost:8000';
}

$appBaseUrl = rtrim(
    $appBaseUrl,
    '/'
);

/*
 * Database Aplikasi
 * Sistem menggunakan satu database aktif agar alur penggunaan sederhana dan
 * seluruh halaman membaca sumber data yang sama.
 */

$appDatabaseFile = 'spk_cisas.sqlite';

// Konfigurasi Sistem

return [
    // Identitas Aplikasi

    'name' => 'Sistem Pendukung Keputusan Prioritas Pemeliharaan Motor Mesin Mixer',

    'short_name' => 'SPK PT CISAS',

    'description' => 'Sistem penentuan prioritas pemeliharaan motor mesin mixer menggunakan metode AHP dan TOPSIS.',

    // Lingkungan Aplikasi

    'environment' => $appEnvironment,

    'debug' => $appDebug,

    'base_url' => $appBaseUrl,

    'timezone' => 'Asia/Jakarta',

    'locale' => 'id_ID',

    // Database

    'database_file' => $appDatabaseFile,

    // Daftar Peran Pengguna

    'roles' => [
        'admin' => 'Administrator',
        'supervisor' => 'Supervisor Maintenance',
        'technician' => 'Teknisi Maintenance',
    ],

    /*
     * Hierarki otoritas sistem.
     *
     * Administrator adalah otoritas sistem tertinggi untuk pengelolaan akun,
     * data master, periode, monitoring, audit, dan pemulihan administratif.
     * Hierarki ini TIDAK mengambil alih kewenangan keputusan teknis:
     * - validasi pemeriksaan, AHP, TOPSIS, dan rekomendasi tetap Supervisor;
     * - input pemeriksaan dan pelaksanaan tindak lanjut tetap Teknisi.
     *
     * Nilai hanya menyatakan tingkat otoritas sistem, bukan izin otomatis
     * terhadap seluruh aksi bisnis.
     */
    'role_hierarchy' => [
        'admin' => 300,
        'supervisor' => 200,
        'technician' => 100,
    ],

    /*
 * Pengaturan AHP
 * Pembobotan AHP hanya dilakukan oleh Supervisor Maintenance.
 */

    'ahp_assessor_role' => 'supervisor',

    // Pengaturan Sistem

    'total_machines' => 6,

    'total_criteria' => 5,

    'ahp_max_consistency_ratio' => 0.10,
];

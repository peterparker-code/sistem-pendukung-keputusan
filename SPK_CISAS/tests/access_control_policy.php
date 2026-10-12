<?php

declare(strict_types=1);

/*
 * Audit kebijakan akses sistem.
 *
 * Tujuan:
 * - memastikan Administrator menjadi otoritas sistem tertinggi;
 * - memastikan kewenangan keputusan teknis tidak diambil alih Administrator;
 * - memastikan Teknisi tetap menjadi pelaksana pemeriksaan/tindak lanjut.
 *
 * Audit ini bersifat statis terhadap guard halaman utama sehingga dapat
 * menangkap perubahan role yang tidak sengaja ketika source code diperbarui.
 */

if (PHP_SAPI !== 'cli') {
    exit('Audit hanya dapat dijalankan melalui terminal.' . PHP_EOL);
}

$basePath = dirname(__DIR__);

require_once $basePath . '/app/helpers/functions.php';
require_once $basePath . '/app/helpers/auth.php';

$failures = [];

$check = static function (
    bool $condition,
    string $success,
    string $failure
) use (&$failures): void {
    if ($condition) {
        echo '[OK] ' . $success . PHP_EOL;
        return;
    }

    $failures[] = $failure;
    echo '[GAGAL] ' . $failure . PHP_EOL;
};

$check(
    auth_role_rank('admin') > auth_role_rank('supervisor')
        && auth_role_rank('supervisor') > auth_role_rank('technician'),
    'Hierarki otoritas sistem: Administrator > Supervisor > Teknisi.',
    'Hierarki otoritas sistem tidak sesuai.'
);

$read = static function (string $relativePath) use ($basePath): string {
    $path = $basePath . '/' . ltrim($relativePath, '/');
    $contents = @file_get_contents($path);

    return is_string($contents)
        ? $contents
        : '';
};

$containsGuard = static function (
    string $source,
    array $roles
): bool {
    if ($source === '') {
        return false;
    }

    $position = strpos($source, 'auth_require_role');

    if ($position === false) {
        return false;
    }

    $fragment = substr($source, $position, 500);

    foreach ($roles as $role) {
        if (
            !str_contains($fragment, "'" . $role . "'")
            && !str_contains($fragment, '"' . $role . '"')
        ) {
            return false;
        }
    }

    return true;
};

// Administrator: kontrol sistem/master data.
$check(
    $containsGuard($read('public/pengguna/index.php'), ['admin'])
        && $containsGuard($read('public/motor/edit.php'), ['admin'])
        && $containsGuard($read('public/periode/edit.php'), ['admin'])
        && $containsGuard($read('public/audit/index.php'), ['admin']),
    'Kontrol akun, motor, periode, dan log aktivitas tersedia untuk Administrator.',
    'Guard modul kontrol Administrator tidak sesuai.'
);

// Supervisor: keputusan teknis.
$check(
    $containsGuard($read('public/pemeriksaan/validasi.php'), ['supervisor'])
        && $containsGuard($read('public/ahp/input.php'), ['supervisor']),
    'Validasi pemeriksaan dan input AHP tetap menjadi kewenangan Supervisor.',
    'Kewenangan teknis Supervisor tidak sesuai.'
);

// Teknisi: data pemeriksaan.
$check(
    $containsGuard($read('public/pemeriksaan/create.php'), ['technician'])
        && $containsGuard($read('public/pemeriksaan/edit.php'), ['technician']),
    'Pembuatan/perbaikan pemeriksaan tetap menjadi kewenangan Teknisi.',
    'Kewenangan operasional Teknisi tidak sesuai.'
);

// Administrator tetap dapat memonitor proses keputusan.
$check(
    $containsGuard($read('public/ahp/hasil.php'), ['admin', 'supervisor'])
        && $containsGuard($read('public/topsis/hasil.php'), ['admin', 'supervisor'])
        && $containsGuard($read('public/perhitungan/riwayat.php'), ['admin', 'supervisor']),
    'Administrator dapat memonitor hasil AHP/TOPSIS dan riwayat perhitungan.',
    'Akses monitoring Administrator terhadap hasil keputusan tidak lengkap.'
);

// Admin tidak boleh mengambil alih aksi teknis normal.
$check(
    !str_contains(
        substr(
            $read('public/ahp/input.php'),
            (int) strpos($read('public/ahp/input.php'), 'auth_require_role'),
            500
        ),
        "'admin'"
    )
    && !str_contains(
        substr(
            $read('public/pemeriksaan/validasi.php'),
            (int) strpos($read('public/pemeriksaan/validasi.php'), 'auth_require_role'),
            500
        ),
        "'admin'"
    ),
    'Pemisahan tugas tetap terjaga: Admin tidak mengambil alih input AHP/validasi.',
    'Administrator memperoleh aksi teknis yang seharusnya tetap milik Supervisor.'
);

if ($failures !== []) {
    echo PHP_EOL . '[HASIL] Audit akses gagal: '
        . count($failures)
        . ' masalah ditemukan.'
        . PHP_EOL;
    exit(1);
}

echo PHP_EOL . '[HASIL] Kebijakan akses sesuai hierarki dan pemisahan tugas.' . PHP_EOL;
exit(0);

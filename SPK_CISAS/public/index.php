<?php

declare(strict_types=1);

/*
 * Halaman Utama Sistem SPK PT CISAS
 * File ini menjadi pintu masuk aplikasi.
 * Pengguna yang sudah login diarahkan ke dashboard.
 * Pengguna yang belum login diarahkan ke halaman login.
 */

// Muat Helper Autentikasi

require_once dirname(__DIR__)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'helpers'
    . DIRECTORY_SEPARATOR
    . 'auth.php';

// Periksa Status Login

auth_initialize();

if (auth_check()) {
    redirect(
        'dashboard.php'
    );
}

// Pengguna Belum Login

redirect(
    'login.php'
);

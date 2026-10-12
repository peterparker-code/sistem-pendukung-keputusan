<?php

declare(strict_types=1);

/*
 * Header Utama Sistem SPK PT CISAS
 * File ini menangani:
 * - pembuka dokumen HTML;
 * - judul dan deskripsi halaman;
 * - Bootstrap 5.3;
 * - stylesheet utama aplikasi;
 * - stylesheet khusus cetak;
 * - metadata pengguna dan CSRF.
 * Variabel halaman yang dapat dikirim:
 * - $pageTitle;
 * - $pageDescription;
 * - $bodyClass;
 * - $includePrintCss.
 */

// Pastikan Helper Dasar Tersedia

if (!function_exists('app_config')) {
    require_once dirname(__DIR__)
        . DIRECTORY_SEPARATOR . 'app'
        . DIRECTORY_SEPARATOR . 'helpers'
        . DIRECTORY_SEPARATOR . 'functions.php';
}

// Nilai Bawaan Halaman

$pageTitle = trim(
    (string) (
        $pageTitle
        ?? ''
    )
);

$pageDescription = trim(
    (string) (
        $pageDescription
        ?? ''
    )
);

$bodyClass = trim(
    (string) (
        $bodyClass
        ?? ''
    )
);

$includePrintCss = (bool) (
    $includePrintCss
    ?? false
);

// Identitas Aplikasi

$appName = trim(
    (string) app_config(
        'name',
        'Sistem Pendukung Keputusan '
        . 'Pemeliharaan Motor Mesin Mixer'
    )
);

$appShortName = trim(
    (string) app_config(
        'short_name',
        'SPK PT CISAS'
    )
);

$appDescription = trim(
    (string) app_config(
        'description',
        'Sistem Pendukung Keputusan penentuan '
        . 'prioritas pemeliharaan motor mesin mixer '
        . 'menggunakan metode AHP dan TOPSIS.'
    )
);

if ($appName === '') {
    $appName =
        'Sistem Pendukung Keputusan '
        . 'Pemeliharaan Motor Mesin Mixer';
}

if ($appShortName === '') {
    $appShortName = 'SPK PT CISAS';
}

if ($appDescription === '') {
    $appDescription =
        'Sistem Pendukung Keputusan '
        . 'Pemeliharaan Motor Mesin Mixer.';
}

// Judul Dokumen

$documentTitle = $pageTitle !== ''
    ? $pageTitle . ' | ' . $appShortName
    : $appShortName;

$documentDescription = $pageDescription !== ''
    ? $pageDescription
    : $appDescription;

// Data Pengguna Aktif

$headerUser = function_exists('auth_user')
    ? auth_user()
    : null;

$headerUserId = null;
$headerUserName = '';
$headerUserRole = '';

if (is_array($headerUser)) {
    $headerUserId = isset($headerUser['id'])
        ? (int) $headerUser['id']
        : null;

    $headerUserName = trim(
        (string) (
            $headerUser['name']
            ?? ''
        )
    );

    $headerUserRole = trim(
        (string) (
            $headerUser['role']
            ?? ''
        )
    );
}

// Token CSRF untuk JavaScript

$headerCsrfToken = '';

if (function_exists('csrf_token')) {
    $headerCsrfToken = csrf_token();
}

// Base URL untuk Metadata JavaScript

$applicationBaseUrl = rtrim(
    (string) app_config(
        'base_url',
        'http://localhost:8000'
    ),
    '/'
);

// Kelas Body

$bodyClasses = [
    $bodyClass,
];

if ($headerUser !== null) {
    $bodyClasses[] = 'authenticated';
} else {
    $bodyClasses[] = 'guest';
}

if ($includePrintCss) {
    $bodyClasses[] = 'report-page';
}

$bodyClasses = array_values(
    array_filter(
        $bodyClasses,
        static fn (string $class): bool =>
            trim($class) !== ''
    )
);

$finalBodyClass = implode(
    ' ',
    $bodyClasses
);

?>
<!doctype html>

<html lang="id">
<head>
    <meta charset="utf-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <meta name="description" content="<?= e($documentDescription) ?>">

    <meta name="application-name" content="<?= e($appShortName) ?>">

    <meta name="theme-color" content="#0d6efd">

    <meta name="color-scheme" content="light">

    <?php if ($headerCsrfToken !== ''): ?>
        <meta name="csrf-token" content="<?= e($headerCsrfToken) ?>">
    <?php endif; ?>

    <title>
        <?= e($documentTitle) ?>
    </title>

    <!-- Bootstrap 5.3 -->
    <link href="<?= e(base_url('assets/vendor/bootstrap/bootstrap.min.css')) ?>" rel="stylesheet">

    <!-- Stylesheet utama aplikasi -->
    <link href="<?= e(base_url( 'assets/css/style.css' )) ?>" rel="stylesheet">

    <?php if ($includePrintCss): ?>
        <!-- Stylesheet khusus cetak laporan -->
        <link href="<?= e(base_url( 'assets/css/print.css' )) ?>" rel="stylesheet" media="print">
    <?php endif; ?>
</head>

<body class="<?= e($finalBodyClass) ?>" data-app-name="<?= e($appShortName) ?>" data-base-url="<?= e($applicationBaseUrl) ?>" <?php if ($headerUserId !== null): ?> data-user-id="<?= e($headerUserId) ?>" <?php endif; ?> <?php if ($headerUserName !== ''): ?> data-user-name="<?= e($headerUserName) ?>" <?php endif; ?> <?php if ($headerUserRole !== ''): ?> data-user-role="<?= e($headerUserRole) ?>" <?php endif; ?>>

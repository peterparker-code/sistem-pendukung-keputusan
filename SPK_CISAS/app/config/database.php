<?php

declare(strict_types=1);

/**
 * Koneksi Database SQLite
 * Membuat satu koneksi PDO untuk seluruh modul aplikasi.
 */

$appConfig = require __DIR__ . '/app.php';

if (!extension_loaded('PDO') || !extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Ekstensi PDO SQLite belum aktif pada PHP.');
}

$databaseDirectory = DATABASE_PATH;
$databaseFileName = (string) ($appConfig['database_file'] ?? 'spk_cisas.sqlite');
if ($databaseFileName === '' || basename($databaseFileName) !== $databaseFileName
    || preg_match('/^[A-Za-z0-9._-]+\.sqlite$/', $databaseFileName) !== 1) {
    throw new RuntimeException('Nama file database tidak valid.');
}

if (!is_dir($databaseDirectory) && !mkdir($databaseDirectory, 0775, true) && !is_dir($databaseDirectory)) {
    throw new RuntimeException('Folder database tidak berhasil dibuat.');
}
if (!is_writable($databaseDirectory)) {
    throw new RuntimeException('Folder database tidak dapat ditulisi.');
}

try {
    $pdo = new PDO('sqlite:' . $databaseDirectory . DIRECTORY_SEPARATOR . $databaseFileName, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);

    // Foreign key wajib aktif pada setiap koneksi SQLite.
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');

    if ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
        throw new RuntimeException('Foreign key SQLite tidak berhasil diaktifkan.');
    }

    return $pdo;
} catch (PDOException $exception) {
    $message = (bool) ($appConfig['debug'] ?? false)
        ? 'Koneksi database gagal: ' . $exception->getMessage()
        : 'Koneksi database tidak dapat dibuat.';
    throw new RuntimeException($message, 0, $exception);
}

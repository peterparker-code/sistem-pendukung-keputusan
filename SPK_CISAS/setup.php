<?php

declare(strict_types=1);

/**
 * Setup Database SPK PT CISAS
 * Membuat database baru bila belum ada dan memeriksa database yang sudah tersedia.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Setup hanya dapat dijalankan melalui terminal.' . PHP_EOL);
}

$basePath = __DIR__;
$appConfig = require $basePath . '/app/config/app.php';
$databasePath = $basePath . '/database/' . ($appConfig['database_file'] ?? 'spk_cisas.sqlite');
$schemaPath = $basePath . '/database/schema.sql';
$seedPath = $basePath . '/database/seed.php';
$researchSeedPath = $basePath . '/database/seed_research_august_2026.sql';
$migrationPaths = [
    $basePath . '/database/migrations/20260809_recommendations.sql',
    $basePath . '/database/migrations/20260809_ahp_primary_scale.sql',
];
$databaseAlreadyExists = is_file($databasePath) && filesize($databasePath) > 0;

function setup_message(string $message, string $type = 'INFO'): void
{
    echo '[' . $type . '] ' . $message . PHP_EOL;
}

try {
    if (!extension_loaded('PDO') || !extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('Ekstensi PDO SQLite belum aktif.');
    }
    if (!is_file($schemaPath) || !is_file($seedPath)) {
        throw new RuntimeException('File schema.sql atau seed.php tidak ditemukan.');
    }

    $pdo = require $basePath . '/app/config/database.php';

    if (!$databaseAlreadyExists) {
        setup_message('Membuat struktur database baru.');
        $schema = file_get_contents($schemaPath);
        if ($schema === false || trim($schema) === '') {
            throw new RuntimeException('schema.sql tidak dapat dibaca.');
        }
        $pdo->exec($schema);
        require $seedPath;

        if (is_file($researchSeedPath)) {
            $researchSeed = file_get_contents($researchSeedPath);
            if ($researchSeed === false || trim($researchSeed) === '') {
                throw new RuntimeException('seed_research_august_2026.sql tidak dapat dibaca.');
            }
            $pdo->exec($researchSeed);
            setup_message('Data penelitian Agustus 2026 berhasil dimasukkan.', 'BERHASIL');
        }

        setup_message('Database baru dan data awal berhasil dibuat.', 'BERHASIL');
    } else {
        setup_message('Database sudah tersedia. Data penelitian tidak diubah.');
    }

    // Migrasi penguatan dijalankan juga pada database lama.
    foreach ($migrationPaths as $migrationPath) {
        if (!is_file($migrationPath)) {
            continue;
        }
        $migration = file_get_contents($migrationPath);
        if ($migration === false || trim($migration) === '') {
            throw new RuntimeException('File migrasi tidak dapat dibaca: ' . basename($migrationPath));
        }
        $pdo->exec($migration);
    }
    setup_message('Migrasi audit, histori, proteksi login, dan skala AHP utama siap.', 'BERHASIL');

    $requiredTables = [
        'users', 'machines', 'criteria', 'assessment_periods', 'assessments',
        'measurements', 'checklist_items', 'checklist_results', 'assessment_scores',
        'ahp_comparisons', 'ahp_results', 'ahp_summaries', 'topsis_results',
        'maintenance_actions',
        'audit_logs', 'calculation_runs', 'login_attempts',
    ];
    $actualTables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff($requiredTables, $actualTables));
    if ($missing !== []) {
        throw new RuntimeException('Tabel belum tersedia: ' . implode(', ', $missing));
    }

    if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
        throw new RuntimeException('Pemeriksaan integritas database gagal.');
    }
    if ($pdo->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
        throw new RuntimeException('Ditemukan pelanggaran foreign key.');
    }

    setup_message('Database siap digunakan dan lulus pemeriksaan dasar.', 'BERHASIL');
} catch (Throwable $exception) {
    setup_message($exception->getMessage(), 'GAGAL');
    exit(1);
}

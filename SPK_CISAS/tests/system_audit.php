<?php

declare(strict_types=1);

/**
 * Audit Sistem
 * Memeriksa struktur database, data inti, dan data penelitian Agustus 2026.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Audit hanya dapat dijalankan melalui terminal.' . PHP_EOL);
}

$basePath = dirname(__DIR__);
require_once $basePath . '/app/helpers/functions.php';
$pdo = require $basePath . '/app/config/database.php';

$failures = [];
$check = static function (bool $condition, string $success, string $failure) use (&$failures): void {
    echo ($condition ? '[OK] ' . $success : '[GAGAL] ' . $failure) . PHP_EOL;
    if (!$condition) {
        $failures[] = $failure;
    }
};
$count = static fn (PDO $pdo, string $table): int =>
    (int) $pdo->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $table) . '"')->fetchColumn();

$requiredTables = [
    'users', 'machines', 'criteria', 'assessment_periods', 'assessments',
    'measurements', 'checklist_items', 'checklist_results', 'assessment_scores',
    'ahp_comparisons', 'ahp_results', 'ahp_summaries', 'topsis_results',
    'maintenance_actions',
    'audit_logs',
    'calculation_runs',
    'login_attempts',
];

$check((string) $pdo->query('PRAGMA integrity_check')->fetchColumn() === 'ok',
    'Integritas SQLite baik.', 'Integritas SQLite bermasalah.');
$check($pdo->query('PRAGMA foreign_key_check')->fetchAll() === [],
    'Tidak ada pelanggaran foreign key.', 'Terdapat pelanggaran foreign key.');

$actualTables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
    ->fetchAll(PDO::FETCH_COLUMN);
foreach ($requiredTables as $table) {
    $check(in_array($table, $actualTables, true), 'Tabel ' . $table . ' tersedia.', 'Tabel ' . $table . ' tidak tersedia.');
}

$adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
$supervisorCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='supervisor'")->fetchColumn();
$technicianCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='technician'")->fetchColumn();
$check($adminCount === 1, 'Tepat satu Administrator tersedia.', 'Jumlah Administrator harus tepat satu.');
$check($supervisorCount === 1, 'Tepat satu Supervisor tersedia.', 'Jumlah Supervisor harus tepat satu.');
$check($technicianCount >= 1, 'Minimal satu Teknisi tersedia.', 'Belum ada Teknisi Maintenance.');
$check($count($pdo, 'machines') === 6, 'Enam alternatif motor tersedia.', 'Jumlah motor bukan enam.');
$check($count($pdo, 'criteria') === 5, 'Lima kriteria tersedia.', 'Jumlah kriteria bukan lima.');
$check($count($pdo, 'checklist_items') === 12, 'Dua belas item checklist tersedia.', 'Jumlah checklist bukan dua belas.');

$machineReadiness = machine_master_readiness($pdo);
$check((bool) ($machineReadiness['ready'] ?? false),
    'Data master/nameplate enam motor lengkap.', 'Data master/nameplate motor belum lengkap.');

$activePeriods = (int) $pdo->query("SELECT COUNT(*) FROM assessment_periods WHERE status='active'")->fetchColumn();
$check($activePeriods <= 1, 'Maksimal satu periode aktif.', 'Terdapat lebih dari satu periode aktif.');

// Validasi kelompok data AHP/TOPSIS pada setiap periode yang sudah dihitung.
$invalidAhp = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT period_id, COUNT(*) total FROM ahp_comparisons GROUP BY period_id HAVING total <> 10)")->fetchColumn();
$invalidWeights = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT period_id, COUNT(*) total FROM ahp_results GROUP BY period_id HAVING total <> 5)")->fetchColumn();
$invalidTopsis = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT period_id, COUNT(*) total FROM topsis_results GROUP BY period_id HAVING total <> 6)")->fetchColumn();
$check($invalidAhp === 0, 'Setiap AHP memiliki 10 perbandingan.', 'Ada kelompok AHP yang tidak berisi 10 perbandingan.');
$ahpComparisonSchema = (string) ($pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='ahp_comparisons'")->fetchColumn() ?: '');
$primarySaatyConstraint = str_contains($ahpComparisonSchema, 'comparison_value - 3.00')
    && str_contains($ahpComparisonSchema, 'comparison_value - 5.00')
    && str_contains($ahpComparisonSchema, 'comparison_value - 7.00')
    && str_contains($ahpComparisonSchema, 'comparison_value - 9.00')
    && str_contains($ahpComparisonSchema, 'comparison_value - 0.3333333333333333')
    && str_contains($ahpComparisonSchema, 'comparison_value - 0.20')
    && !str_contains($ahpComparisonSchema, 'comparison_value - 2.00')
    && !str_contains($ahpComparisonSchema, 'comparison_value - 4.00')
    && !str_contains($ahpComparisonSchema, 'comparison_value - 6.00')
    && !str_contains($ahpComparisonSchema, 'comparison_value - 8.00');
$check($primarySaatyConstraint,
    'Constraint database memakai intensitas utama Saaty 1, 3, 5, 7, 9 beserta nilai kebalikannya.',
    'Constraint database AHP belum sesuai intensitas utama yang digunakan UI.');
$check($invalidWeights === 0, 'Setiap AHP memiliki 5 bobot kriteria.', 'Ada kelompok AHP yang tidak berisi 5 bobot.');
$check($invalidTopsis === 0, 'Setiap hasil TOPSIS memiliki 6 alternatif.', 'Ada kelompok TOPSIS yang tidak berisi 6 alternatif.');

// Setiap periode yang sudah masuk AHP/TOPSIS harus berasal dari enam data siap hitung.
$calculatedPeriodIds = $pdo->query(
    "SELECT period_id FROM ahp_summaries UNION SELECT period_id FROM topsis_results"
)->fetchAll(PDO::FETCH_COLUMN);

$invalidReadinessPeriods = [];
foreach ($calculatedPeriodIds as $calculatedPeriodId) {
    $calculatedPeriodId = (int) $calculatedPeriodId;
    $readinessStatement = $pdo->prepare(
        "SELECT
            COUNT(DISTINCT a.id) AS assessment_count,
            COUNT(DISTINCT CASE WHEN a.status IN ('validated','processed') THEN a.id END) AS approved_count,
            COUNT(DISTINCT CASE
                WHEN a.status IN ('validated','processed')
                 AND s.c1_value IS NOT NULL AND s.c2_value IS NOT NULL
                 AND s.c3_value IS NOT NULL AND s.c4_value IS NOT NULL
                 AND s.c5_value IS NOT NULL
                 AND s.c4_not_checked_count = 0 AND s.c5_not_checked_count = 0
                THEN a.id END) AS score_complete_count,
            COUNT(DISTINCT CASE
                WHEN a.status IN ('validated','processed')
                 AND m.alternative_code IN ('A1','A2','A3','A4','A5','A6')
                THEN m.alternative_code END) AS alternative_count
         FROM assessments a
         JOIN machines m ON m.id = a.machine_id
         LEFT JOIN assessment_scores s ON s.assessment_id = a.id
         WHERE a.period_id = :period_id"
    );
    $readinessStatement->execute(['period_id' => $calculatedPeriodId]);
    $ready = $readinessStatement->fetch();
    if (
        !is_array($ready)
        || (int) ($ready['assessment_count'] ?? 0) !== 6
        || (int) ($ready['approved_count'] ?? 0) !== 6
        || (int) ($ready['score_complete_count'] ?? 0) !== 6
        || (int) ($ready['alternative_count'] ?? 0) !== 6
    ) {
        $invalidReadinessPeriods[] = $calculatedPeriodId;
    }
}
$check(
    $invalidReadinessPeriods === [],
    'Seluruh periode terhitung berasal dari 6 pemeriksaan A1-A6 yang disetujui dan C1-C5 lengkap.',
    'Ada periode AHP/TOPSIS yang tidak memenuhi readiness 6 pemeriksaan lengkap.'
);

$invalidTopsisConsistency = (int) $pdo->query(
    "SELECT COUNT(*) FROM (
        SELECT DISTINCT tr.period_id
        FROM topsis_results tr
        LEFT JOIN ahp_summaries a ON a.period_id = tr.period_id
        WHERE a.period_id IS NULL OR a.is_consistent <> 1 OR a.consistency_ratio > 0.10
    )"
)->fetchColumn();
$check(
    $invalidTopsisConsistency === 0,
    'TOPSIS hanya tersedia pada periode dengan AHP konsisten (CR <= 0,10).',
    'Ada hasil TOPSIS yang tidak memiliki AHP konsisten.'
);

$duplicateFinalRuns = (int) $pdo->query(
    "SELECT COUNT(*) FROM (
        SELECT period_id, method, COUNT(*) total
        FROM calculation_runs
        WHERE is_final = 1
        GROUP BY period_id, method
        HAVING total > 1
    )"
)->fetchColumn();
$check(
    $duplicateFinalRuns === 0,
    'Setiap metode memiliki maksimal satu run final per periode.',
    'Terdapat lebih dari satu calculation run final untuk metode/periode yang sama.'
);

// Data penelitian asli Agustus 2026 harus tetap utuh bila periodenya tersedia.
$periodStatement = $pdo->prepare("SELECT id FROM assessment_periods WHERE name = :name LIMIT 1");
$periodStatement->execute(['name' => 'Pemeriksaan Agustus 2026']);
$augustPeriodId = (int) ($periodStatement->fetchColumn() ?: 0);
if ($augustPeriodId > 0) {
    $periodCount = static function (PDO $pdo, string $table, int $periodId): int {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE period_id = :period_id');
        $stmt->execute(['period_id' => $periodId]);
        return (int) $stmt->fetchColumn();
    };
    $check($periodCount($pdo, 'assessments', $augustPeriodId) === 6,
        'Data Agustus memiliki 6 pemeriksaan.', 'Data Agustus tidak memiliki 6 pemeriksaan.');
    $check($periodCount($pdo, 'ahp_comparisons', $augustPeriodId) === 10,
        'Data Agustus memiliki 10 perbandingan AHP.', 'Data AHP Agustus tidak lengkap.');
    $check($periodCount($pdo, 'ahp_results', $augustPeriodId) === 5,
        'Data Agustus memiliki 5 bobot AHP.', 'Bobot AHP Agustus tidak lengkap.');
    $check($periodCount($pdo, 'topsis_results', $augustPeriodId) === 6,
        'Data Agustus memiliki 6 hasil TOPSIS.', 'Hasil TOPSIS Agustus tidak lengkap.');

    // Tindakan terhubung ke periode melalui assessment_id, bukan period_id langsung.
    $actionStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM maintenance_actions ma
         JOIN assessments a ON a.id = ma.assessment_id
         WHERE a.period_id = :period_id'
    );
    $actionStatement->execute(['period_id' => $augustPeriodId]);
    $check((int) $actionStatement->fetchColumn() === 6,
        'Data Agustus memiliki 6 tindak lanjut.', 'Tindak lanjut Agustus tidak lengkap.');
}

if ($failures !== []) {
    echo PHP_EOL . '[HASIL] Audit gagal: ' . count($failures) . ' masalah ditemukan.' . PHP_EOL;
    exit(1);
}

echo PHP_EOL . '[HASIL] Sistem lulus audit dasar.' . PHP_EOL;
exit(0);

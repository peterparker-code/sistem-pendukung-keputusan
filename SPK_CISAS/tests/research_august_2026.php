<?php

declare(strict_types=1);

/**
 * Regression test data penelitian Agustus 2026.
 * Mengambil data mentah dari database, menghitung ulang AHP dan TOPSIS,
 * lalu memastikan hasilnya sama dengan hasil penelitian yang tersimpan.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Pengujian hanya dapat dijalankan melalui terminal.\n");
}

$basePath = dirname(__DIR__);
require_once $basePath . '/app/services/AhpService.php';
require_once $basePath . '/app/services/TopsisService.php';

if (!extension_loaded('pdo_sqlite')) {
    exit("[LEWATI] PDO SQLite belum aktif. Aktifkan extension=pdo_sqlite untuk menjalankan regression test data penelitian.\n");
}

$pdo = require $basePath . '/app/config/database.php';
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[GAGAL] ') . $message . PHP_EOL;
    if (!$ok) {
        $failures[] = $message;
    }
};
$near = static function (float $actual, float $expected, float $tol, string $message) use (&$failures): void {
    $ok = abs($actual - $expected) <= $tol;
    echo ($ok ? '[OK] ' : '[GAGAL] ') . $message . sprintf(' (%.10f)', $actual) . PHP_EOL;
    if (!$ok) {
        $failures[] = $message;
    }
};

$stmt = $pdo->prepare("SELECT id FROM assessment_periods WHERE name = :name LIMIT 1");
$stmt->execute(['name' => 'Pemeriksaan Agustus 2026']);
$periodId = (int) ($stmt->fetchColumn() ?: 0);
$check($periodId > 0, 'Periode penelitian Agustus 2026 ditemukan.');
if ($periodId < 1) {
    exit(1);
}

$criteria = $pdo->query("SELECT id, code, name FROM criteria WHERE is_active = 1 ORDER BY display_order, id")->fetchAll();
$stmt = $pdo->prepare("SELECT criterion_left_id, criterion_right_id, comparison_value FROM ahp_comparisons WHERE period_id = :period_id ORDER BY criterion_left_id, criterion_right_id");
$stmt->execute(['period_id' => $periodId]);
$comparisons = $stmt->fetchAll();

$ahpService = (new ReflectionClass(AhpService::class))->newInstanceWithoutConstructor();
$ahp = $ahpService->calculate($criteria, $comparisons);
$expectedWeights = [
    'C1' => 0.1698212044,
    'C2' => 0.0836502859,
    'C3' => 0.0497555058,
    'C4' => 0.3483865019,
    'C5' => 0.3483865019,
];
$weights = [];
foreach ($ahp['weights'] as $row) {
    $code = (string) $row['criterion_code'];
    $weights[$code] = (float) $row['weight'];
    $near($weights[$code], $expectedWeights[$code], 1.0E-8, 'Bobot ' . $code . ' dapat direproduksi.');
}
$near((float) $ahp['summary']['consistency_ratio'], 0.0551403825, 1.0E-8, 'CR Agustus dapat direproduksi.');
$check((bool) $ahp['summary']['is_consistent'], 'AHP Agustus tetap konsisten (CR <= 0,10).');

$stmt = $pdo->prepare(
    "SELECT a.id AS assessment_id, m.alternative_code, m.motor_name,
            s.c1_value, s.c2_value, s.c3_value, s.c4_value, s.c5_value
     FROM assessments a
     JOIN machines m ON m.id = a.machine_id
     JOIN assessment_scores s ON s.assessment_id = a.id
     WHERE a.period_id = :period_id
     ORDER BY m.alternative_code"
);
$stmt->execute(['period_id' => $periodId]);
$alternatives = $stmt->fetchAll();
$check(count($alternatives) === 6, 'Enam alternatif Agustus tersedia untuk hitung ulang TOPSIS.');

$topsisService = (new ReflectionClass(TopsisService::class))->newInstanceWithoutConstructor();
$topsis = $topsisService->calculate($alternatives, $weights);
$expectedOrder = ['A2', 'A4', 'A6', 'A3', 'A1', 'A5'];
$expectedPreference = [
    'A2' => 0.8710818837,
    'A4' => 0.7498520703,
    'A6' => 0.4852757500,
    'A3' => 0.2122083565,
    'A1' => 0.1291706524,
    'A5' => 0.0000000000,
];
foreach ($topsis['results'] as $index => $row) {
    $code = (string) $row['alternative_code'];
    $check($code === $expectedOrder[$index], 'Ranking ' . ($index + 1) . ' = ' . $expectedOrder[$index] . '.');
    $near((float) $row['preference_value'], $expectedPreference[$code], 1.0E-8, 'Nilai preferensi ' . $code . ' dapat direproduksi.');
}

$stmt = $pdo->prepare("SELECT m.alternative_code, tr.preference_value, tr.ranking FROM topsis_results tr JOIN assessments a ON a.id=tr.assessment_id JOIN machines m ON m.id=a.machine_id WHERE tr.period_id=:period_id ORDER BY tr.ranking, m.alternative_code");
$stmt->execute(['period_id' => $periodId]);
$stored = $stmt->fetchAll();
$check(count($stored) === 6, 'Enam hasil TOPSIS tersimpan di database.');
foreach ($stored as $index => $row) {
    $check((string) $row['alternative_code'] === $expectedOrder[$index], 'Urutan tersimpan sesuai hasil hitung ulang pada posisi ' . ($index + 1) . '.');
    $near((float) $row['preference_value'], $expectedPreference[(string) $row['alternative_code']], 1.0E-8, 'Nilai tersimpan ' . $row['alternative_code'] . ' sesuai hasil hitung ulang.');
}

if ($failures !== []) {
    echo PHP_EOL . '[HASIL] Regression test gagal: ' . count($failures) . ' masalah.' . PHP_EOL;
    exit(1);
}

echo PHP_EOL . '[HASIL] Data penelitian Agustus 2026 dapat direproduksi dari data mentah AHP -> TOPSIS.' . PHP_EOL;
exit(0);

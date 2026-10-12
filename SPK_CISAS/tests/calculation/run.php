<?php

declare(strict_types=1);

/*
 * Pengujian Otomatis Perhitungan AHP dan TOPSIS
 * Jalankan dari folder utama:
 * php tests/calculation/run.php
 * Data bersifat sintetis dan tidak digunakan sebagai hasil penelitian.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Pengujian hanya dapat dijalankan melalui terminal.\n");
}

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'services'
    . DIRECTORY_SEPARATOR . 'AhpService.php';

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'services'
    . DIRECTORY_SEPARATOR . 'TopsisService.php';

$failures = [];

$assert = static function (
    bool $condition,
    string $message
) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assertNear = static function (
    float $actual,
    float $expected,
    float $tolerance,
    string $message
) use (&$failures): void {
    if (abs($actual - $expected) > $tolerance) {
        $failures[] = sprintf(
            '%s Aktual %.10f, diharapkan %.10f.',
            $message,
            $actual,
            $expected
        );
    }
};

$assertThrows = static function (
    callable $callback,
    string $message
) use (&$failures): void {
    try {
        $callback();
        $failures[] = $message;
    } catch (Throwable) {
        // Sesuai harapan: skenario tidak valid harus ditolak.
    }
};

/* calculate() tidak menggunakan koneksi database. Reflection dipakai agar
   pengujian algoritma tetap dapat berjalan walaupun pdo_sqlite belum aktif. */
$ahpService = (new ReflectionClass(
    AhpService::class
))->newInstanceWithoutConstructor();

$topsisService = (new ReflectionClass(
    TopsisService::class
))->newInstanceWithoutConstructor();

$criteria = [];

for ($index = 1; $index <= 5; $index++) {
    $criteria[] = [
        'id' => $index,
        'code' => 'C' . $index,
        'name' => 'Kriteria C' . $index,
    ];
}

$comparisonValues = [
    [1, 2, 1.0],
    [1, 3, 3.0],
    [1, 4, 1.0 / 3.0],
    [1, 5, 1.0 / 3.0],
    [2, 3, 3.0],
    [2, 4, 1.0 / 3.0],
    [2, 5, 1.0 / 3.0],
    [3, 4, 1.0 / 5.0],
    [3, 5, 1.0 / 5.0],
    [4, 5, 1.0],
];

$comparisons = array_map(
    static fn (array $row): array => [
        'criterion_left_id' => $row[0],
        'criterion_right_id' => $row[1],
        'comparison_value' => $row[2],
    ],
    $comparisonValues
);

$ahp = $ahpService->calculate(
    $criteria,
    $comparisons
);

$expectedWeights = [
    0.1298057480,
    0.1298057480,
    0.0556716780,
    0.3423584130,
    0.3423584130,
];

$assert(count($ahp['matrix']) === 5, 'Matriks AHP harus 5×5.');
$assert(count($ahp['weights']) === 5, 'AHP harus menghasilkan lima bobot.');

$weightSum = 0.0;

foreach ($ahp['weights'] as $index => $weight) {
    $value = (float) $weight['weight'];
    $weightSum += $value;

    $assert($value > 0, 'Setiap bobot AHP harus positif.');
    $assertNear(
        $value,
        $expectedWeights[$index],
        1.0E-8,
        'Bobot ' . $weight['criterion_code'] . ' berbeda.'
    );
}

$assertNear($weightSum, 1.0, 1.0E-8, 'Total bobot AHP bukan 1.');
$assertNear(
    (float) $ahp['summary']['lambda_max'],
    5.0560262715,
    1.0E-8,
    'Lambda maksimum berbeda.'
);
$assertNear(
    (float) $ahp['summary']['consistency_index'],
    0.0140065679,
    1.0E-8,
    'Consistency Index berbeda.'
);
$assertNear(
    (float) $ahp['summary']['consistency_ratio'],
    0.0125058642,
    1.0E-8,
    'Consistency Ratio berbeda.'
);
$assert(
    (bool) $ahp['summary']['is_consistent'],
    'Data AHP sintetis seharusnya konsisten.'
);

$decisionData = [
    [48, 75, 1.2, 1.3, 1.2],
    [55, 88, 2.1, 1.8, 1.7],
    [51, 81, 1.6, 1.5, 1.4],
    [62, 96, 3.0, 2.2, 2.4],
    [46, 72, 1.0, 1.2, 1.1],
    [58, 91, 2.5, 2.0, 2.1],
];

$alternatives = [];

foreach ($decisionData as $index => $values) {
    $alternatives[] = [
        'assessment_id' => $index + 1,
        'alternative_code' => 'A' . ($index + 1),
        'motor_name' => 'Motor ' . ($index + 1),
        'c1_value' => $values[0],
        'c2_value' => $values[1],
        'c3_value' => $values[2],
        'c4_value' => $values[3],
        'c5_value' => $values[4],
    ];
}

$topsis = $topsisService->calculate(
    $alternatives,
    [
        'C1' => 0.25,
        'C2' => 0.20,
        'C3' => 0.15,
        'C4' => 0.20,
        'C5' => 0.20,
    ]
);

$expectedOrder = ['A4', 'A6', 'A2', 'A3', 'A1', 'A5'];
$expectedPreferences = [
    1.0000000000,
    0.7681961121,
    0.5361852784,
    0.2827372809,
    0.0965333934,
    0.0000000000,
];

$assert(count($topsis['results']) === 6, 'TOPSIS harus menghasilkan enam hasil.');

foreach ($topsis['results'] as $index => $result) {
    $assert(
        $result['alternative_code'] === $expectedOrder[$index],
        'Urutan TOPSIS pada posisi ' . ($index + 1) . ' berbeda.'
    );

    $assert((int) $result['ranking'] === $index + 1, 'Ranking TOPSIS tidak berurutan.');
    $assert((int) $result['rank_group'] === $index + 1, 'rank_group TOPSIS tidak berurutan.');

    $assertNear(
        (float) $result['preference_value'],
        $expectedPreferences[$index],
        1.0E-8,
        'Nilai preferensi ' . $result['alternative_code'] . ' berbeda.'
    );
}

/* -------------------------------------------------------------------------
 * Pengujian batas/negatif: memastikan algoritma menolak input yang tidak sah
 * dan workflow tidak dapat mundur ke status yang dilarang.
 * ---------------------------------------------------------------------- */

$assertThrows(
    static fn () => $ahpService->calculate(
        array_slice($criteria, 0, 4),
        $comparisons
    ),
    'AHP harus menolak jumlah kriteria selain lima.'
);

$normalizeComparisonsMethod = new ReflectionMethod(
    AhpService::class,
    'normalizeComparisons'
);
$normalizeComparisonsMethod->setAccessible(true);

$assertThrows(
    static fn () => $normalizeComparisonsMethod->invoke(
        $ahpService,
        array_slice($comparisons, 0, 9),
        $criteria
    ),
    'AHP harus menolak perbandingan yang tidak lengkap (kurang dari 10 pasangan).'
);

foreach ([2.0, 4.0, 6.0, 8.0, 1 / 2, 1 / 4, 1 / 6, 1 / 8] as $intermediateValue) {
    $intermediateScale = $comparisons;
    $intermediateScale[0]['comparison_value'] = $intermediateValue;
    $assertThrows(
        static fn () => $normalizeComparisonsMethod->invoke(
            $ahpService,
            $intermediateScale,
            $criteria
        ),
        'Input AHP dibatasi pada intensitas utama 1, 3, 5, 7, 9 beserta nilai kebalikannya.'
    );
}

$invalidScale = $comparisons;
$invalidScale[0]['comparison_value'] = 2.5;
$assertThrows(
    static fn () => $normalizeComparisonsMethod->invoke(
        $ahpService,
        $invalidScale,
        $criteria
    ),
    'AHP harus menolak nilai di luar intensitas utama 1, 3, 5, 7, 9 atau nilai kebalikannya.'
);

$assertThrows(
    static fn () => $topsisService->calculate(
        array_slice($alternatives, 0, 5),
        [
            'C1' => 0.25,
            'C2' => 0.20,
            'C3' => 0.15,
            'C4' => 0.20,
            'C5' => 0.20,
        ]
    ),
    'TOPSIS harus menolak jumlah alternatif selain enam.'
);

$duplicateAlternatives = $alternatives;
$duplicateAlternatives[5]['alternative_code'] = 'A1';
$assertThrows(
    static fn () => $topsisService->calculate(
        $duplicateAlternatives,
        [
            'C1' => 0.25,
            'C2' => 0.20,
            'C3' => 0.15,
            'C4' => 0.20,
            'C5' => 0.20,
        ]
    ),
    'TOPSIS harus menolak kode alternatif duplikat/tidak lengkap.'
);

$zeroColumnAlternatives = $alternatives;
foreach ($zeroColumnAlternatives as &$row) {
    $row['c5_value'] = 0;
}
unset($row);
$assertThrows(
    static fn () => $topsisService->calculate(
        $zeroColumnAlternatives,
        [
            'C1' => 0.25,
            'C2' => 0.20,
            'C3' => 0.15,
            'C4' => 0.20,
            'C5' => 0.20,
        ]
    ),
    'TOPSIS harus menolak kolom kriteria dengan pembagi normalisasi nol.'
);

$tieAlternatives = [];
for ($index = 1; $index <= 6; $index++) {
    $tieAlternatives[] = [
        'assessment_id' => $index,
        'alternative_code' => 'A' . $index,
        'motor_name' => 'Motor Seri ' . $index,
        'c1_value' => 10,
        'c2_value' => 20,
        'c3_value' => 30,
        'c4_value' => 40,
        'c5_value' => 50,
    ];
}
$tieResult = $topsisService->calculate(
    $tieAlternatives,
    [
        'C1' => 0.20,
        'C2' => 0.20,
        'C3' => 0.20,
        'C4' => 0.20,
        'C5' => 0.20,
    ]
);
foreach ($tieResult['results'] as $row) {
    $assertNear(
        (float) $row['preference_value'],
        0.5,
        1.0E-10,
        'Alternatif identik harus memperoleh preferensi 0,5.'
    );
    $assert(
        (int) $row['ranking'] === 1 && (int) $row['rank_group'] === 1,
        'Alternatif identik harus memperoleh ranking seri yang sama.'
    );
}

$assert(
    WorkflowService::canTransition('assessment', 'completed', 'validated'),
    'Workflow harus mengizinkan Menunggu Validasi -> Disetujui.'
);
$assert(
    !WorkflowService::canTransition('assessment', 'validated', 'draft'),
    'Workflow tidak boleh mengizinkan Disetujui -> Draft.'
);
$assert(
    WorkflowService::canTransition('action', 'in_progress', 'completed'),
    'Workflow harus mengizinkan tindakan Dikerjakan -> Selesai.'
);
$assert(
    !WorkflowService::canTransition('action', 'completed', 'in_progress'),
    'Workflow tidak boleh mengizinkan tindakan Selesai -> Dikerjakan.'
);

if ($failures !== []) {
    echo "PENGUJIAN PERHITUNGAN: GAGAL\n\n";

    foreach ($failures as $index => $failure) {
        echo ($index + 1) . '. ' . $failure . "\n";
    }

    exit(1);
}

echo "PENGUJIAN PERHITUNGAN: LULUS\n";
echo "AHP  : normalisasi kolom, rata-rata baris, lambda maksimum, CI, dan CR sesuai data pembanding.\n";
echo "TOPSIS: urutan A4, A6, A2, A3, A1, A5 dan nilai preferensi sesuai.\n";
echo "NEGATIF: jumlah input, skala AHP, alternatif TOPSIS, pembagi nol, tie ranking, dan transisi workflow teruji.\n";

exit(0);

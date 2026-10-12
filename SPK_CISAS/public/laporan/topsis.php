<?php

declare(strict_types=1);

/*
 * Laporan Perhitungan TOPSIS
 * Menampilkan:
 * - identitas periode;
 * - bobot hasil AHP;
 * - matriks keputusan C1-C5;
 * - pembagi normalisasi;
 * - matriks normalisasi;
 * - matriks normalisasi terbobot;
 * - solusi ideal positif dan negatif;
 * - jarak solusi ideal;
 * - nilai preferensi dan peringkat A1-A6.
 * Hak akses:
 * - Administrator: melihat dan mencetak laporan;
 * - Supervisor Maintenance: melihat dan mencetak laporan.
 * Seluruh kriteria diperlakukan sebagai benefit terhadap urgensi
 * pemeliharaan. Nilai yang lebih tinggi menunjukkan kebutuhan
 * pemeliharaan yang lebih tinggi.
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'helpers'
    . DIRECTORY_SEPARATOR . 'auth.php';

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'services'
    . DIRECTORY_SEPARATOR . 'TopsisService.php';

// Batasi Hak Akses

auth_require_role([
    'admin',
    'supervisor',
]);

// Koneksi Database

$pdo = require dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'config'
    . DIRECTORY_SEPARATOR . 'database.php';

$topsisService = new TopsisService($pdo);

// Pastikan Akun Masih Aktif

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun pengguna sudah tidak aktif atau tidak ditemukan.'
    );

    redirect('login.php');
}

// Informasi Pengguna

$currentRole = auth_role();
$currentUserName = auth_name() ?? 'Pengguna';
$currentRoleLabel = auth_role_label($currentRole);

$isAdmin = $currentRole === 'admin';
$isSupervisor = $currentRole === 'supervisor';

// Konfigurasi Penelitian

$expectedMachineCount = (int) app_config(
    'total_machines',
    6
);

$expectedCriteriaCount = (int) app_config(
    'total_criteria',
    5
);

$criterionCodes = [
    'C1',
    'C2',
    'C3',
    'C4',
    'C5',
];

$criterionTypes = [
    'C1' => 'benefit',
    'C2' => 'benefit',
    'C3' => 'benefit',
    'C4' => 'benefit',
    'C5' => 'benefit',
];

$expectedAlternativeCodes = [
    'A1',
    'A2',
    'A3',
    'A4',
    'A5',
    'A6',
];

$weightTolerance = 0.0001;
$resultTolerance = 0.0001;
$consistencyLimit = 0.10;

// Nilai Awal

$periods = [];
$selectedPeriod = null;

$criteria = [];
$criteriaByCode = [];
$weightRows = [];
$weights = [];
$ahpSummary = null;

$alternativeRows = [];
$decisionMatrix = [];
$serviceAlternatives = [];
$normalizedMatrix = [];
$weightedMatrix = [];
$divisors = [];
$positiveIdeal = [];
$negativeIdeal = [];

$pageError = null;
$calculationError = null;

$totalWeight = 0.0;
$resultCount = 0;
$calculatedAt = null;
$topPriority = null;

$alternativeCodes = [];
$alternativeCodeByAssessment = [];

$storedResultsByAssessment = [];
$storedResultAlternativeCodes = [];
$storedRankingValues = [];
$storedCalculationTimes = [];

$computedPositiveDistances = [];
$computedNegativeDistances = [];
$computedPreferences = [];
$computedRankingByAssessment = [];

$invalidWeightCount = 0;
$storedResultsValid = true;
$storedResultsMatchCalculation = true;
$resultMismatchCount = 0;

$totalWeightValid = false;
$rankingSequenceValid = false;
$assessorIsSupervisor = false;

// Ambil Daftar Periode

try {
    $periodStatement = $pdo->query(
        "SELECT
            ap.id,
            ap.name,
            ap.start_date,
            ap.end_date,
            ap.status,
            ap.created_at,

            creator.name AS created_by_name

         FROM assessment_periods AS ap

         INNER JOIN users AS creator
            ON creator.id = ap.created_by

         ORDER BY
            CASE ap.status
                WHEN 'active' THEN 1
                WHEN 'draft' THEN 2
                WHEN 'completed' THEN 3
                ELSE 4
            END,

            ap.start_date DESC,
            ap.id DESC"
    );

    $periods = $periodStatement->fetchAll();
} catch (Throwable $exception) {
    error_log(
        'Daftar periode laporan TOPSIS gagal dimuat: '
        . $exception->getMessage()
    );

    $pageError =
        'Daftar periode laporan TOPSIS belum dapat dimuat.';
}

// Tentukan Periode yang Dipilih

$periodIdInput = $_GET['period_id'] ?? null;

if (
    $periodIdInput !== null
    && $periodIdInput !== ''
) {
    $validatedPeriodId = filter_var(
        $periodIdInput,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if ($validatedPeriodId === false) {
        $notFoundMessage =
            'ID periode laporan TOPSIS tidak valid.';

        require dirname(__DIR__)
            . DIRECTORY_SEPARATOR
            . '404.php';

        exit;
    }

    foreach ($periods as $period) {
        if (
            (int) $period['id']
            === (int) $validatedPeriodId
        ) {
            $selectedPeriod = $period;

            break;
        }
    }

    if ($selectedPeriod === null) {
        $notFoundMessage =
            'Periode laporan TOPSIS tidak ditemukan.';

        require dirname(__DIR__)
            . DIRECTORY_SEPARATOR
            . '404.php';

        exit;
    }
} elseif ($periods !== []) {
    // Prioritaskan Periode Aktif

    foreach ($periods as $period) {
        if (
            (string) (
                $period['status']
                ?? ''
            ) === 'active'
        ) {
            $selectedPeriod = $period;

            break;
        }
    }

    // Gunakan Periode Pertama Jika Tidak Ada Periode Aktif

    $selectedPeriod ??= $periods[0];
}

$selectedPeriodId = $selectedPeriod !== null
    ? (int) $selectedPeriod['id']
    : null;

// Ambil Data TOPSIS Periode Terpilih

if ($selectedPeriodId !== null) {
    try {
        // Ambil Lima Kriteria

        $criteriaStatement = $pdo->query(
            "SELECT
                id,
                code,
                name,
                description,
                display_order

             FROM criteria

             WHERE is_active = 1
               AND code IN (
                   'C1',
                   'C2',
                   'C3',
                   'C4',
                   'C5'
               )

             ORDER BY
                display_order ASC,
                id ASC"
        );

        $criteria = $criteriaStatement->fetchAll();

        foreach ($criteria as $criterion) {
            $code = strtoupper(
                trim(
                    (string) (
                        $criterion['code']
                        ?? ''
                    )
                )
            );

            if (
                in_array(
                    $code,
                    $criterionCodes,
                    true
                )
                && !isset(
                    $criteriaByCode[$code]
                )
            ) {
                $criteriaByCode[$code] =
                    $criterion;
            }
        }

        // Ambil Bobot AHP

        $weightStatement = $pdo->prepare(
            "SELECT
                ar.criterion_id,
                ar.weight,
                ar.calculated_at,

                c.code,
                c.name,
                c.description,
                c.display_order

             FROM ahp_results AS ar

             INNER JOIN criteria AS c
                ON c.id = ar.criterion_id

             WHERE ar.period_id = :period_id
               AND c.is_active = 1
               AND c.code IN (
                   'C1',
                   'C2',
                   'C3',
                   'C4',
                   'C5'
               )

             ORDER BY
                c.display_order ASC,
                c.id ASC"
        );

        $weightStatement->execute([
            'period_id' => $selectedPeriodId,
        ]);

        $weightRows = $weightStatement->fetchAll();

        foreach ($weightRows as $weightRow) {
            $code = strtoupper(
                trim(
                    (string) (
                        $weightRow['code']
                        ?? ''
                    )
                )
            );

            $rawWeight =
                $weightRow['weight']
                ?? null;

            if (
                !in_array(
                    $code,
                    $criterionCodes,
                    true
                )
                || isset(
                    $weights[$code]
                )
                || $rawWeight === null
                || $rawWeight === ''
                || !is_numeric(
                    (string) $rawWeight
                )
            ) {
                $invalidWeightCount++;

                continue;
            }

            $weight =
                (float) $rawWeight;

            if (
                !is_finite($weight)
                || $weight <= 0
            ) {
                $invalidWeightCount++;

                continue;
            }

            $weights[$code] =
                $weight;

            $totalWeight +=
                $weight;
        }

        $totalWeightValid =
            abs(
                $totalWeight - 1.0
            ) <= $weightTolerance;

        // Ambil Ringkasan AHP

        $ahpStatement = $pdo->prepare(
            "SELECT
                ahs.consistency_ratio,
                ahs.is_consistent,
                ahs.calculated_at,

                assessor.name AS assessor_name,
                assessor.role AS assessor_role

             FROM ahp_summaries AS ahs

             INNER JOIN users AS assessor
                ON assessor.id = ahs.assessor_id

             WHERE ahs.period_id = :period_id

             LIMIT 1"
        );

        $ahpStatement->execute([
            'period_id' => $selectedPeriodId,
        ]);

        $ahpData = $ahpStatement->fetch();

        $ahpSummary = is_array($ahpData)
            ? $ahpData
            : null;

        $assessorIsSupervisor =
            $ahpSummary !== null
            && (string) (
                $ahpSummary['assessor_role']
                ?? ''
            ) === 'supervisor';

        // Ambil Nilai Alternatif dan Hasil TOPSIS Tersimpan

        $alternativeStatement = $pdo->prepare(
            "SELECT
                a.id AS assessment_id,
                a.inspection_date,
                a.status AS assessment_status,

                m.id AS machine_id,
                m.alternative_code,
                m.machine_code,
                m.motor_name,
                m.motor_function,
                m.location,

                s.c1_value,
                s.c2_value,
                s.c3_value,
                s.c4_value,
                s.c5_value,
                s.c4_not_checked_count,
                s.c5_not_checked_count,

                tr.id AS topsis_result_id,
                tr.positive_distance,
                tr.negative_distance,
                tr.preference_value,
                tr.ranking,
                tr.rank_group,
                tr.priority_label,
                tr.calculated_at

             FROM assessments AS a

             INNER JOIN machines AS m
                ON m.id = a.machine_id

             INNER JOIN assessment_scores AS s
                ON s.assessment_id = a.id

             LEFT JOIN topsis_results AS tr
                ON tr.period_id = a.period_id
               AND tr.assessment_id = a.id

             WHERE a.period_id = :period_id
               AND a.status IN (
                    'validated',
                    'processed'
               )
               AND m.alternative_code IN (
                   'A1',
                   'A2',
                   'A3',
                   'A4',
                   'A5',
                   'A6'
               )

             ORDER BY
                m.alternative_code ASC,
                a.id ASC"
        );

        $alternativeStatement->execute([
            'period_id' => $selectedPeriodId,
        ]);

        $alternativeRows = $alternativeStatement->fetchAll();

        // Susun Matriks Keputusan dan Validasi Hasil Tersimpan

        foreach ($alternativeRows as $row) {
            $assessmentId = (int) (
                $row['assessment_id']
                ?? 0
            );

            $alternativeCode = strtoupper(
                trim(
                    (string) (
                        $row['alternative_code']
                        ?? ''
                    )
                )
            );

            $assessmentStatus = (string) (
                $row['assessment_status']
                ?? ''
            );

            if (
                $assessmentId < 1
                || !in_array(
                    $alternativeCode,
                    $expectedAlternativeCodes,
                    true
                )
                || isset(
                    $alternativeCodes[
                        $alternativeCode
                    ]
                )
                || !in_array(
                    $assessmentStatus,
                    [
                        'validated',
                        'processed',
                    ],
                    true
                )
            ) {
                $calculationError =
                    'Data alternatif A1 sampai A6 tidak valid '
                    . 'atau terdapat alternatif ganda.';

                break;
            }

            $alternativeCodes[
                $alternativeCode
            ] = true;

            $alternativeCodeByAssessment[
                $assessmentId
            ] = $alternativeCode;

            if (
                (int) (
                    $row['c4_not_checked_count']
                    ?? 0
                ) > 0
                || (int) (
                    $row['c5_not_checked_count']
                    ?? 0
                ) > 0
            ) {
                $calculationError =
                    'Masih terdapat indikator Belum Diperiksa pada '
                    . $alternativeCode
                    . '.';

                break;
            }

            $decisionMatrix[$assessmentId] = [];

            foreach ($criterionCodes as $criterionCode) {
                $fieldName =
                    strtolower(
                        $criterionCode
                    )
                    . '_value';

                $rawValue =
                    $row[$fieldName]
                    ?? null;

                if (
                    $rawValue === null
                    || $rawValue === ''
                    || !is_numeric(
                        (string) $rawValue
                    )
                ) {
                    $calculationError =
                        'Nilai '
                        . $criterionCode
                        . ' pada '
                        . $alternativeCode
                        . ' belum tersedia.';

                    break 2;
                }

                $value =
                    (float) $rawValue;

                if (
                    !is_finite($value)
                    || $value < 0
                ) {
                    $calculationError =
                        'Nilai '
                        . $criterionCode
                        . ' pada '
                        . $alternativeCode
                        . ' tidak valid.';

                    break 2;
                }

                $decisionMatrix[
                    $assessmentId
                ][
                    $criterionCode
                ] = $value;
            }

            $serviceAlternatives[] = [
                'assessment_id' => $assessmentId,
                'alternative_code' => $alternativeCode,
                'motor_name' => (string) ($row['motor_name'] ?? ''),
                'c1_value' => (float) $decisionMatrix[$assessmentId]['C1'],
                'c2_value' => (float) $decisionMatrix[$assessmentId]['C2'],
                'c3_value' => (float) $decisionMatrix[$assessmentId]['C3'],
                'c4_value' => (float) $decisionMatrix[$assessmentId]['C4'],
                'c5_value' => (float) $decisionMatrix[$assessmentId]['C5'],
            ];

            if (
                $row['topsis_result_id']
                === null
            ) {
                continue;
            }

            $positiveDistance =
                $row['positive_distance']
                ?? null;

            $negativeDistance =
                $row['negative_distance']
                ?? null;

            $preferenceValue =
                $row['preference_value']
                ?? null;

            $ranking =
                $row['ranking']
                ?? null;

            $rankGroup =
                $row['rank_group']
                ?? null;

            if (
                !is_numeric(
                    (string) $positiveDistance
                )
                || !is_numeric(
                    (string) $negativeDistance
                )
                || !is_numeric(
                    (string) $preferenceValue
                )
                || !is_numeric(
                    (string) $ranking
                )
                || !is_numeric(
                    (string) $rankGroup
                )
            ) {
                $storedResultsValid = false;

                continue;
            }

            $positiveDistance =
                (float) $positiveDistance;

            $negativeDistance =
                (float) $negativeDistance;

            $preferenceValue =
                (float) $preferenceValue;

            $ranking =
                (int) $ranking;

            $rankGroup =
                (int) $rankGroup;

            if (
                !is_finite($positiveDistance)
                || !is_finite($negativeDistance)
                || !is_finite($preferenceValue)
                || $positiveDistance < 0
                || $negativeDistance < 0
                || $preferenceValue < 0
                || $preferenceValue > 1
                || $ranking < 1
                || $ranking > $expectedMachineCount
                || $rankGroup < 1
                || $rankGroup > $expectedMachineCount
                || isset(
                    $storedResultAlternativeCodes[
                        $alternativeCode
                    ]
                )
            ) {
                $storedResultsValid = false;

                continue;
            }

            $storedResultsByAssessment[
                $assessmentId
            ] = [
                'positive_distance' =>
                    $positiveDistance,

                'negative_distance' =>
                    $negativeDistance,

                'preference_value' =>
                    $preferenceValue,

                'ranking' =>
                    $ranking,

                'rank_group' =>
                    $rankGroup,
            ];

            $storedResultAlternativeCodes[
                $alternativeCode
            ] = true;

            $storedRankingValues[] =
                $ranking;

            $resultCount =
                count(
                    $storedResultAlternativeCodes
                );

            if (
                !empty(
                    $row['calculated_at']
                )
            ) {
                $storedCalculationTimes[] =
                    (string) $row[
                        'calculated_at'
                    ];
            }

            if ($ranking === 1) {
                if ($topPriority !== null) {
                    $storedResultsValid = false;
                } else {
                    $topPriority = $row;
                }
            }
        }

        // Waktu Perhitungan TOPSIS

        if ($storedCalculationTimes !== []) {
            sort(
                $storedCalculationTimes
            );

            $calculatedAt =
                end(
                    $storedCalculationTimes
                ) ?: null;
        }

        // Pemeriksaan Awal Kelengkapan

        $criteriaCodesFound =
            array_keys(
                $criteriaByCode
            );

        sort(
            $criteriaCodesFound
        );

        $expectedCriterionCodes =
            $criterionCodes;

        sort(
            $expectedCriterionCodes
        );

        $alternativeCodesFound =
            array_keys(
                $alternativeCodes
            );

        sort(
            $alternativeCodesFound
        );

        $expectedAlternativeCodesSorted =
            $expectedAlternativeCodes;

        sort(
            $expectedAlternativeCodesSorted
        );

        $criteriaComplete =
            count($criteriaByCode)
                === $expectedCriteriaCount

            && $criteriaCodesFound
                === $expectedCriterionCodes;

        $weightsComplete =
            count($weights)
                === $expectedCriteriaCount

            && count(
                array_diff(
                    $criterionCodes,
                    array_keys(
                        $weights
                    )
                )
            ) === 0

            && $invalidWeightCount === 0

            && $totalWeightValid;

        $alternativesComplete =
            count($decisionMatrix)
                === $expectedMachineCount

            && $alternativeCodesFound
                === $expectedAlternativeCodesSorted;

        /*
 * Hitung Ulang melalui TopsisService
 * Seluruh rumus TOPSIS dipusatkan di TopsisService. Halaman laporan hanya
 * memetakan hasil service untuk ditampilkan dan membandingkannya dengan
 * hasil yang tersimpan pada database.
 */

        if (
            $calculationError === null
            && $criteriaComplete
            && $weightsComplete
            && $alternativesComplete
        ) {
            try {
                $calculation = $topsisService->calculate(
                    $serviceAlternatives,
                    $weights,
                    $criterionTypes
                );

                $divisors = (array) ($calculation['divisors'] ?? []);
                $positiveIdeal = (array) ($calculation['positive_ideal'] ?? []);
                $negativeIdeal = (array) ($calculation['negative_ideal'] ?? []);

                foreach ((array) ($calculation['normalized_matrix'] ?? []) as $matrixRow) {
                    $assessmentId = (int) ($matrixRow['assessment_id'] ?? 0);
                    if ($assessmentId > 0) {
                        $normalizedMatrix[$assessmentId] = (array) ($matrixRow['values'] ?? []);
                    }
                }

                foreach ((array) ($calculation['weighted_matrix'] ?? []) as $matrixRow) {
                    $assessmentId = (int) ($matrixRow['assessment_id'] ?? 0);
                    if ($assessmentId > 0) {
                        $weightedMatrix[$assessmentId] = (array) ($matrixRow['values'] ?? []);
                    }
                }

                foreach ((array) ($calculation['results'] ?? []) as $resultRow) {
                    $assessmentId = (int) ($resultRow['assessment_id'] ?? 0);
                    if ($assessmentId < 1) {
                        continue;
                    }

                    $computedPositiveDistances[$assessmentId] = (float) ($resultRow['positive_distance'] ?? 0);
                    $computedNegativeDistances[$assessmentId] = (float) ($resultRow['negative_distance'] ?? 0);
                    $computedPreferences[$assessmentId] = (float) ($resultRow['preference_value'] ?? 0);
                    $computedRankingByAssessment[$assessmentId] = (int) ($resultRow['ranking'] ?? 0);
                }

                if (count($storedResultsByAssessment) !== $expectedMachineCount) {
                    $storedResultsMatchCalculation = false;
                } else {
                    foreach ($computedPreferences as $assessmentId => $computedPreference) {
                        $storedResult = $storedResultsByAssessment[$assessmentId] ?? null;

                        if (
                            !is_array($storedResult)
                            || abs(
                                $computedPositiveDistances[$assessmentId]
                                - (float) $storedResult['positive_distance']
                            ) > $resultTolerance
                            || abs(
                                $computedNegativeDistances[$assessmentId]
                                - (float) $storedResult['negative_distance']
                            ) > $resultTolerance
                            || abs(
                                $computedPreference
                                - (float) $storedResult['preference_value']
                            ) > $resultTolerance
                            || ($computedRankingByAssessment[$assessmentId] ?? 0)
                                !== (int) $storedResult['ranking']
                        ) {
                            $storedResultsMatchCalculation = false;
                            $resultMismatchCount++;
                        }
                    }
                }
            } catch (Throwable $calculationException) {
                $calculationError = $calculationException->getMessage();
            }
        }
    } catch (Throwable $exception) {
        error_log(
            'Laporan TOPSIS gagal dimuat: '
            . $exception->getMessage()
        );

        $pageError =
            'Data laporan TOPSIS belum dapat dimuat.';
    }
}

// Status Kelengkapan Laporan

$criteriaCodesFound =
    array_keys(
        $criteriaByCode
    );

sort(
    $criteriaCodesFound
);

$expectedCriterionCodes =
    $criterionCodes;

sort(
    $expectedCriterionCodes
);

$criteriaComplete =
    count($criteriaByCode)
        === $expectedCriteriaCount

    && $criteriaCodesFound
        === $expectedCriterionCodes;

$weightsComplete =
    count($weights)
        === $expectedCriteriaCount

    && count(
        array_diff(
            $criterionCodes,
            array_keys(
                $weights
            )
        )
    ) === 0

    && $invalidWeightCount === 0

    && $totalWeightValid;

$alternativeCodesFound =
    array_keys(
        $alternativeCodes
    );

sort(
    $alternativeCodesFound
);

$expectedAlternativeCodesSorted =
    $expectedAlternativeCodes;

sort(
    $expectedAlternativeCodesSorted
);

$alternativesComplete =
    count($decisionMatrix)
        === $expectedMachineCount

    && $alternativeCodesFound
        === $expectedAlternativeCodesSorted;

$storedResultCodesFound =
    array_keys(
        $storedResultAlternativeCodes
    );

sort(
    $storedResultCodesFound
);

$rankingValues =
    $storedRankingValues;

sort(
    $rankingValues
);

$expectedRankingValues =
    range(
        1,
        $expectedMachineCount
    );

$rankingSequenceValid =
    $rankingValues
        === $expectedRankingValues;

$resultsComplete =
    $storedResultsValid

    && $resultCount
        === $expectedMachineCount

    && $storedResultCodesFound
        === $expectedAlternativeCodesSorted

    && $rankingSequenceValid

    && $topPriority !== null

    && $storedResultsMatchCalculation;

$consistencyRatio =
    $ahpSummary !== null

    && (
        $ahpSummary[
            'consistency_ratio'
        ]
        ?? null
    ) !== null

    && is_numeric(
        (string) $ahpSummary[
            'consistency_ratio'
        ]
    )

        ? (float) $ahpSummary[
            'consistency_ratio'
        ]

        : null;

$ahpIsConsistent =
    $ahpSummary !== null

    && $assessorIsSupervisor

    && (int) (
        $ahpSummary[
            'is_consistent'
        ]
        ?? 0
    ) === 1

    && $consistencyRatio !== null

    && is_finite(
        $consistencyRatio
    )

    && $consistencyRatio >= 0

    && $consistencyRatio
        <= $consistencyLimit;

$matrixCalculationComplete =
    $calculationError === null

    && $criteriaComplete

    && $weightsComplete

    && $alternativesComplete

    && count(
        $divisors
    ) === $expectedCriteriaCount

    && count(
        $normalizedMatrix
    ) === $expectedMachineCount

    && count(
        $weightedMatrix
    ) === $expectedMachineCount

    && count(
        $positiveIdeal
    ) === $expectedCriteriaCount

    && count(
        $negativeIdeal
    ) === $expectedCriteriaCount

    && count(
        $computedPositiveDistances
    ) === $expectedMachineCount

    && count(
        $computedNegativeDistances
    ) === $expectedMachineCount

    && count(
        $computedPreferences
    ) === $expectedMachineCount

    && count(
        $computedRankingByAssessment
    ) === $expectedMachineCount;

$reportAvailable =
    $pageError === null

    && $matrixCalculationComplete

    && $resultsComplete

    && $ahpIsConsistent;

// Fungsi Tampilan Angka

$displayNumber = static function (
    mixed $value,
    int $decimals = 6
): string {
    if (
        $value === null
        || $value === ''
        || !is_numeric($value)
    ) {
        return '-';
    }

    $number = (float) $value;

    if (!is_finite($number)) {
        return '-';
    }

    return format_number_id(
        $number,
        $decimals
    );
};

// Fungsi Label Kriteria

$criterionShortName = static function (
    string $code
): string {
    return match ($code) {
        'C1' => 'Suhu',
        'C2' => 'Arus',
        'C3' => 'Deviasi RPM',
        'C4' => 'Mekanikal',
        'C5' => 'Elektrikal',
        default => $code,
    };
};

// Fungsi Label Motor

$motorFunctionLabel = static function (
    ?string $function
): string {
    return match ($function) {
        'scraper' => 'Scraper',
        'homogenizer' => 'Homogenizer',
        default => '-',
    };
};

// Fungsi Metadata Peringkat

$rankingMeta = static function (
    mixed $rankGroup,
    mixed $ranking
): array {
    $group = is_numeric(
        $rankGroup
    )
        ? (int) $rankGroup
        : null;

    $rank = is_numeric(
        $ranking
    )
        ? (int) $ranking
        : null;

    return match ($group) {
        1 => [
            'label' =>
                'Prioritas Utama',

            'class' =>
                'danger',
        ],

        2 => [
            'label' =>
                'Prioritas Tinggi',

            'class' =>
                'warning',
        ],

        3 => [
            'label' =>
                'Prioritas Menengah',

            'class' =>
                'info',
        ],

        default => [
            'label' =>
                $rank !== null
                    ? 'Prioritas Peringkat ' . $rank
                    : 'Prioritas Berikutnya',

            'class' =>
                'secondary',
        ],
    };
};

// Status Periode

$periodStatusConfigurations = [
    'draft' => [
        'label' => 'Draft',
        'class' => 'secondary',
    ],

    'active' => [
        'label' => 'Aktif',
        'class' => 'success',
    ],

    'completed' => [
        'label' => 'Selesai',
        'class' => 'primary',
    ],
];

$selectedPeriodStatus = $selectedPeriod !== null
    ? (string) (
        $selectedPeriod['status']
        ?? 'draft'
    )
    : 'draft';

$selectedPeriodStatusInfo =
    $periodStatusConfigurations[
        $selectedPeriodStatus
    ]
    ?? [
        'label' => 'Tidak diketahui',
        'class' => 'secondary',
    ];

// Informasi Cetak

$printedAt = date('Y-m-d H:i:s');

// Konfigurasi Halaman

$pageTitle = 'Laporan Perhitungan TOPSIS';

$pageDescription =
    'Laporan matriks keputusan, normalisasi, '
    . 'solusi ideal, nilai preferensi, dan peringkat TOPSIS.';

$bodyClass =
    'd-flex flex-column min-vh-100';

$includePrintCss = true;

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'header.php';

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'navbar.php';

?>

<div class="d-lg-flex flex-grow-1">
    <?php
    require TEMPLATE_PATH
        . DIRECTORY_SEPARATOR
        . 'sidebar.php';
    ?>

    <main id="main-content" class="flex-grow-1 p-3 p-lg-4 overflow-hidden">
        <?php
        require TEMPLATE_PATH
            . DIRECTORY_SEPARATOR
            . 'alert.php';
        ?>

        <?php if ($pageError !== null): ?>
            <div class="alert alert-danger no-print" role="alert">
                <strong class="d-block mb-1">
                    Laporan belum dapat dimuat
                </strong>

                <?= e($pageError) ?>
            </div>
        <?php endif; ?>

        <section class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4 no-print">
            <div>
                <h1 class="page-title mb-1">
                    Laporan Perhitungan TOPSIS
                </h1>

                <p class="page-description mb-0">
                    Matriks keputusan, normalisasi,
                    solusi ideal, dan peringkat A1–A6.
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="<?= e(base_url( 'laporan/index.php' . ( $selectedPeriodId !== null ? '?period_id=' . $selectedPeriodId : '' ) )) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>

                <button type="button" class="btn btn-primary" onclick="window.print()" <?= !$reportAvailable ? 'disabled' : '' ?>>
                    Cetak Laporan
                </button>
            </div>
        </section>

        <?php if ($isAdmin): ?>
            <div class="alert alert-secondary mb-4 no-print" role="status">
                Administrator membuka laporan dalam mode baca.
                Hasil TOPSIS tidak dapat dihitung ulang atau diubah
                dari halaman laporan.
            </div>
        <?php elseif ($isSupervisor): ?>
            <div class="alert alert-info mb-4 no-print" role="status">
                Laporan menampilkan tahapan perhitungan TOPSIS
                berdasarkan bobot AHP dan nilai C1–C5 yang telah
                tersimpan pada periode terpilih.
            </div>
        <?php endif; ?>

        <section class="card border-0 shadow-sm mb-4 no-print" aria-labelledby="topsis-report-filter-title">
            <div class="card-body p-4">
                <h2 id="topsis-report-filter-title" class="h5 mb-1">
                    Periode Laporan
                </h2>

                <p class="small text-body-secondary mb-3">
                    Pilih periode hasil TOPSIS yang akan ditampilkan.
                </p>

                <form method="get" action="<?= e(base_url( 'laporan/topsis.php' )) ?>" class="row g-3 align-items-end">
                    <div class="col-md-8 col-xl-9">
                        <label for="period_id" class="form-label">
                            Periode Pemeriksaan
                        </label>

                        <select id="period_id" name="period_id" class="form-select" required>
                            <?php if ($periods === []): ?>
                                <option value="">
                                    Belum ada periode
                                </option>
                            <?php else: ?>
                                <?php foreach (
                                    $periods
                                    as $period
                                ): ?>
                                    <?php
                                    $periodStatus = (string) (
                                        $period['status']
                                        ?? 'draft'
                                    );

                                    $periodStatusInfo =
                                        $periodStatusConfigurations[
                                            $periodStatus
                                        ]
                                        ?? [
                                            'label' =>
                                                'Tidak diketahui',
                                        ];
                                    ?>

                                    <option value="<?= e( $period['id'] ) ?>" <?= selected( $selectedPeriodId, (int) $period['id'] ) ?>>
                                        <?= e(
                                            $period['name']
                                        ) ?>

                                        —

                                        <?= e(
                                            $periodStatusInfo[
                                                'label'
                                            ]
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="col-md-4 col-xl-3">
                        <button type="submit" class="btn btn-primary w-100" <?= $periods === [] ? 'disabled' : '' ?>>
                            Tampilkan Laporan
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <?php if ($selectedPeriod === null): ?>
            <div class="alert alert-warning" role="status">
                Belum terdapat periode pemeriksaan yang dapat
                ditampilkan pada laporan TOPSIS.
            </div>
        <?php else: ?>
            <header class="print-report-header d-none d-print-block">
                <div class="print-report-brand-row">
                    <img
                        src="<?= e(base_url('assets/images/logo_cisas.webp')) ?>"
                        alt="Logo PT CISAS"
                        class="print-report-logo"
                    >

                    <div class="print-report-brand-copy">
                        <strong>PT CITRASEMESTA ASRISEJATI</strong>
                        <span>Sistem Pendukung Keputusan Pemeliharaan Motor Mesin Mixer</span>
                    </div>
                </div>

                <div class="print-report-title-block">
                    <h1>LAPORAN PERHITUNGAN TOPSIS</h1>
                    <p>Metode Analytical Hierarchy Process (AHP) dan TOPSIS</p>
                </div>
            </header>

            <section class="card border-0 shadow-sm mb-4 report-identity" aria-labelledby="topsis-report-identity-title">
                <div class="card-body p-4">
                    <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                        <div>
                            <h2 id="topsis-report-identity-title" class="h5 mb-2">
                                <?= e(
                                    $selectedPeriod['name']
                                ) ?>
                            </h2>

                            <p class="mb-1">
                                <strong>Rentang tanggal:</strong>

                                <?= e(format_date_id(
                                    $selectedPeriod['start_date']
                                )) ?>

                                sampai

                                <?= e(format_date_id(
                                    $selectedPeriod['end_date']
                                )) ?>
                            </p>

                            <p class="mb-1">
                                <strong>Jumlah alternatif:</strong>

                                <?= e(count($alternativeRows)) ?>
                            </p>

                            <p class="mb-0">
                                <strong>Jenis kriteria:</strong>

                                Benefit terhadap urgensi pemeliharaan
                            </p>
                        </div>

                        <div class="text-md-end">
                            <span class="badge text-bg-<?= e( $selectedPeriodStatusInfo[ 'class' ] ) ?> mb-2">
                                <?= e(
                                    $selectedPeriodStatusInfo[
                                        'label'
                                    ]
                                ) ?>
                            </span>

                            <p class="small text-body-secondary mb-0">
                                Dicetak oleh <?= e(
                                    $currentUserName
                                ) ?>

                                <br>

                                <?= e(format_date_id(
                                    $printedAt,
                                    true
                                )) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="row row-cols-2 row-cols-md-3 row-cols-xl-6 g-3 mb-4" aria-label="Ringkasan laporan TOPSIS">
                <div class="col">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                Alternatif
                            </p>

                            <p class="h3 fw-semibold mb-1">
                                <?= e(count($decisionMatrix)) ?>
                            </p>

                            <small class="text-body-secondary">
                                Dari <?= e(
                                    $expectedMachineCount
                                ) ?> motor
                            </small>
                        </div>
                    </article>
                </div>

                <div class="col">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                Kriteria
                            </p>

                            <p class="h3 fw-semibold mb-1">
                                <?= e(count($criteria)) ?>
                            </p>

                            <small class="text-body-secondary">
                                C1 sampai C5
                            </small>
                        </div>
                    </article>
                </div>

                <div class="col">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                Total Bobot
                            </p>

                            <p class="h5 fw-semibold mb-1">
                                <?= e($displayNumber(
                                    $totalWeight,
                                    4
                                )) ?>
                            </p>

                            <small class="text-body-secondary">
                                Target 1,0000
                            </small>
                        </div>
                    </article>
                </div>

                <div class="col">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                CR AHP
                            </p>

                            <p class="h5 fw-semibold mb-1">
                                <?= e($displayNumber(
                                    $ahpSummary[
                                        'consistency_ratio'
                                    ]
                                    ?? null,
                                    4
                                )) ?>
                            </p>

                            <small class="text-body-secondary">
                                Batas ≤ 0,10
                            </small>
                        </div>
                    </article>
                </div>

                <div class="col">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                Hasil TOPSIS
                            </p>

                            <p class="h3 fw-semibold mb-1">
                                <?= e($resultCount) ?>
                            </p>

                            <small class="text-body-secondary">
                                Peringkat tersimpan
                            </small>
                        </div>
                    </article>
                </div>

                <div class="col">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                Prioritas Pertama
                            </p>

                            <p class="h5 fw-semibold mb-1">
                                <?= e(
                                    $topPriority[
                                        'alternative_code'
                                    ]
                                    ?? '-'
                                ) ?>
                            </p>

                            <small class="text-body-secondary">
                                <?php
                                $topPriorityMeta =
                                    $rankingMeta(
                                        $topPriority[
                                            'rank_group'
                                        ]
                                        ?? null,
                                        $topPriority[
                                            'ranking'
                                        ]
                                        ?? null
                                    );
                                ?>

                                <?= e(
                                    $topPriority[
                                        'priority_label'
                                    ]
                                    ?? $topPriorityMeta[
                                        'label'
                                    ]
                                ) ?>
                            </small>
                        </div>
                    </article>
                </div>
            </section>

            <?php if ($calculationError !== null): ?>
                <div class="alert alert-danger mb-4" role="alert">
                    <strong class="d-block mb-1">
                        Tahapan TOPSIS belum dapat ditampilkan
                    </strong>

                    <?= e($calculationError) ?>
                </div>
            <?php elseif (!$criteriaComplete): ?>
                <div class="alert alert-warning mb-4" role="status">
                    Jumlah kriteria aktif belum tepat lima.
                </div>
            <?php elseif (!$weightsComplete): ?>
                <div class="alert alert-warning mb-4" role="status">
                    Bobot AHP belum lengkap untuk lima kriteria.
                </div>
            <?php elseif (!$ahpIsConsistent): ?>
                <div class="alert alert-warning mb-4" role="status">
                    Bobot AHP belum konsisten atau nilai CR masih
                    melebihi 0,10.
                </div>
            <?php elseif (!$alternativesComplete): ?>
                <div class="alert alert-warning mb-4" role="status">
                    Nilai keputusan belum lengkap untuk enam motor.
                </div>
            <?php elseif (!$resultsComplete): ?>
                <div class="alert alert-warning mb-4" role="status">
                    Hasil TOPSIS tersimpan belum lengkap, tidak valid,
                    atau tidak sesuai dengan perhitungan ulang laporan.
                </div>
            <?php else: ?>
                <div class="alert alert-success mb-4" role="status">
                    Data TOPSIS lengkap. Laporan menampilkan bobot AHP,
                    matriks keputusan, normalisasi, solusi ideal,
                    nilai preferensi, dan peringkat enam motor.
                </div>
            <?php endif; ?>

            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="topsis-weight-title">
                <div class="card-header bg-white py-3">
                    <h2 id="topsis-weight-title" class="h5 mb-1">
                        Bobot Kriteria AHP
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Bobot hasil AHP yang digunakan untuk
                        membentuk matriks normalisasi terbobot.
                    </p>
                </div>

                <div class="card-body p-0">
                    <?php if ($weightRows === []): ?>
                        <div class="text-center text-body-secondary p-5">
                            Bobot AHP belum tersedia.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0 report-table">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">
                                            Kriteria
                                        </th>

                                        <th scope="col">
                                            Nama
                                        </th>

                                        <th scope="col" class="text-end">
                                            Bobot
                                        </th>

                                        <th scope="col" class="text-end">
                                            Persentase
                                        </th>

                                        <th scope="col">
                                            Jenis
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach (
                                        $weightRows
                                        as $weightRow
                                    ): ?>
                                        <?php
                                        $code = (string) (
                                            $weightRow['code']
                                            ?? ''
                                        );

                                        $weight = $weights[$code]
                                            ?? null;
                                        ?>

                                        <tr>
                                            <td>
                                                <strong>
                                                    <?= e($code) ?>
                                                </strong>
                                            </td>

                                            <td>
                                                <?= e(
                                                    $weightRow['name']
                                                ) ?>
                                            </td>

                                            <td class="text-end">
                                                <strong>
                                                    <?= e($displayNumber(
                                                        $weight,
                                                        6
                                                    )) ?>
                                                </strong>
                                            </td>

                                            <td class="text-end">
                                                <?= e($displayNumber(
                                                    is_numeric($weight)
                                                        ? (float) $weight * 100
                                                        : null,
                                                    2
                                                )) ?>%
                                            </td>

                                            <td>
                                                <span class="badge text-bg-primary">
                                                    Benefit
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>

                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="2">
                                            Total
                                        </th>

                                        <th class="text-end">
                                            <?= e($displayNumber(
                                                $totalWeight,
                                                6
                                            )) ?>
                                        </th>

                                        <th class="text-end">
                                            <?= e($displayNumber(
                                                $totalWeight * 100,
                                                2
                                            )) ?>%
                                        </th>

                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="decision-matrix-title">
                <div class="card-header bg-white py-3">
                    <h2 id="decision-matrix-title" class="h5 mb-1">
                        Matriks Keputusan
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Nilai C1–C5 enam motor sebelum proses normalisasi.
                    </p>
                </div>

                <div class="card-body p-0">
                    <?php if ($decisionMatrix === []): ?>
                        <div class="text-center text-body-secondary p-5">
                            Matriks keputusan belum tersedia.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle text-center mb-0 report-table">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">
                                            Alternatif
                                        </th>

                                        <th scope="col">
                                            Motor
                                        </th>

                                        <?php foreach (
                                            $criterionCodes
                                            as $criterionCode
                                        ): ?>
                                            <th scope="col">
                                                <?= e($criterionCode) ?>

                                                <small class="d-block fw-normal">
                                                    <?= e($criterionShortName(
                                                        $criterionCode
                                                    )) ?>
                                                </small>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach (
                                        $alternativeRows
                                        as $row
                                    ): ?>
                                        <?php
                                        $assessmentId = (int) (
                                            $row['assessment_id']
                                            ?? 0
                                        );
                                        ?>

                                        <tr>
                                            <th scope="row">
                                                <?= e(
                                                    $row['alternative_code']
                                                ) ?>
                                            </th>

                                            <td class="text-start">
                                                <strong class="d-block">
                                                    <?= e(
                                                        $row['motor_name']
                                                    ) ?>
                                                </strong>

                                                <small class="text-body-secondary">
                                                    <?= e(
                                                        $row['machine_code']
                                                    ) ?>
                                                    /
                                                    <?= e($motorFunctionLabel(
                                                        isset(
                                                            $row[
                                                                'motor_function'
                                                            ]
                                                        )
                                                            ? (string) $row[
                                                                'motor_function'
                                                            ]
                                                            : null
                                                    )) ?>
                                                </small>
                                            </td>

                                            <?php foreach (
                                                $criterionCodes
                                                as $criterionCode
                                            ): ?>
                                                <td>
                                                    <?= e($displayNumber(
                                                        $decisionMatrix[
                                                            $assessmentId
                                                        ][
                                                            $criterionCode
                                                        ]
                                                        ?? null,
                                                        6
                                                    )) ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>

                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="2">
                                            Pembagi Normalisasi
                                        </th>

                                        <?php foreach (
                                            $criterionCodes
                                            as $criterionCode
                                        ): ?>
                                            <th>
                                                <?= e($displayNumber(
                                                    $divisors[
                                                        $criterionCode
                                                    ]
                                                    ?? null,
                                                    6
                                                )) ?>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="normalized-matrix-title">
                <div class="card-header bg-white py-3">
                    <h2 id="normalized-matrix-title" class="h5 mb-1">
                        Matriks Normalisasi
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Setiap nilai keputusan dibagi dengan akar jumlah
                        kuadrat pada kolom kriterianya.
                    </p>
                </div>

                <div class="card-body p-0">
                    <?php if ($normalizedMatrix === []): ?>
                        <div class="text-center text-body-secondary p-5">
                            Matriks normalisasi belum tersedia.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle text-center mb-0 report-table">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">
                                            Alternatif
                                        </th>

                                        <?php foreach (
                                            $criterionCodes
                                            as $criterionCode
                                        ): ?>
                                            <th scope="col">
                                                <?= e($criterionCode) ?>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach (
                                        $alternativeRows
                                        as $row
                                    ): ?>
                                        <?php
                                        $assessmentId = (int) (
                                            $row['assessment_id']
                                            ?? 0
                                        );
                                        ?>

                                        <tr>
                                            <th scope="row">
                                                <?= e(
                                                    $row['alternative_code']
                                                ) ?>
                                            </th>

                                            <?php foreach (
                                                $criterionCodes
                                                as $criterionCode
                                            ): ?>
                                                <td>
                                                    <?= e($displayNumber(
                                                        $normalizedMatrix[
                                                            $assessmentId
                                                        ][
                                                            $criterionCode
                                                        ]
                                                        ?? null,
                                                        6
                                                    )) ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="weighted-matrix-title">
                <div class="card-header bg-white py-3">
                    <h2 id="weighted-matrix-title" class="h5 mb-1">
                        Matriks Normalisasi Terbobot
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Nilai normalisasi dikalikan dengan bobot AHP
                        pada masing-masing kriteria.
                    </p>
                </div>

                <div class="card-body p-0">
                    <?php if ($weightedMatrix === []): ?>
                        <div class="text-center text-body-secondary p-5">
                            Matriks normalisasi terbobot belum tersedia.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle text-center mb-0 report-table">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">
                                            Alternatif
                                        </th>

                                        <?php foreach (
                                            $criterionCodes
                                            as $criterionCode
                                        ): ?>
                                            <th scope="col">
                                                <?= e($criterionCode) ?>

                                                <small class="d-block fw-normal">
                                                    w = <?= e($displayNumber(
                                                        $weights[
                                                            $criterionCode
                                                        ]
                                                        ?? null,
                                                        4
                                                    )) ?>
                                                </small>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach (
                                        $alternativeRows
                                        as $row
                                    ): ?>
                                        <?php
                                        $assessmentId = (int) (
                                            $row['assessment_id']
                                            ?? 0
                                        );
                                        ?>

                                        <tr>
                                            <th scope="row">
                                                <?= e(
                                                    $row['alternative_code']
                                                ) ?>
                                            </th>

                                            <?php foreach (
                                                $criterionCodes
                                                as $criterionCode
                                            ): ?>
                                                <td>
                                                    <?= e($displayNumber(
                                                        $weightedMatrix[
                                                            $assessmentId
                                                        ][
                                                            $criterionCode
                                                        ]
                                                        ?? null,
                                                        6
                                                    )) ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>

                                <tfoot class="table-light">
                                    <tr>
                                        <th scope="row">
                                            A+
                                        </th>

                                        <?php foreach (
                                            $criterionCodes
                                            as $criterionCode
                                        ): ?>
                                            <th>
                                                <?= e($displayNumber(
                                                    $positiveIdeal[
                                                        $criterionCode
                                                    ]
                                                    ?? null,
                                                    6
                                                )) ?>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>

                                    <tr>
                                        <th scope="row">
                                            A−
                                        </th>

                                        <?php foreach (
                                            $criterionCodes
                                            as $criterionCode
                                        ): ?>
                                            <th>
                                                <?= e($displayNumber(
                                                    $negativeIdeal[
                                                        $criterionCode
                                                    ]
                                                    ?? null,
                                                    6
                                                )) ?>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="ideal-solution-title">
                <div class="card-header bg-white py-3">
                    <h2 id="ideal-solution-title" class="h5 mb-1">
                        Solusi Ideal Positif dan Negatif
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Karena seluruh kriteria bersifat benefit terhadap
                        urgensi, A+ menggunakan nilai maksimum dan A−
                        menggunakan nilai minimum.
                    </p>
                </div>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle text-center mb-0 report-table">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">
                                        Solusi Ideal
                                    </th>

                                    <?php foreach (
                                        $criterionCodes
                                        as $criterionCode
                                    ): ?>
                                        <th scope="col">
                                            <?= e($criterionCode) ?>

                                            <small class="d-block fw-normal">
                                                <?= e($criterionShortName(
                                                    $criterionCode
                                                )) ?>
                                            </small>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>

                            <tbody>
                                <tr>
                                    <th scope="row">
                                        Positif (A+)
                                    </th>

                                    <?php foreach (
                                        $criterionCodes
                                        as $criterionCode
                                    ): ?>
                                        <td>
                                            <?= e($displayNumber(
                                                $positiveIdeal[
                                                    $criterionCode
                                                ]
                                                ?? null,
                                                6
                                            )) ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>

                                <tr>
                                    <th scope="row">
                                        Negatif (A−)
                                    </th>

                                    <?php foreach (
                                        $criterionCodes
                                        as $criterionCode
                                    ): ?>
                                        <td>
                                            <?= e($displayNumber(
                                                $negativeIdeal[
                                                    $criterionCode
                                                ]
                                                ?? null,
                                                6
                                            )) ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="topsis-result-title">
                <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div>
                        <h2 id="topsis-result-title" class="h5 mb-1">
                            Hasil Akhir dan Peringkat
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Nilai preferensi yang lebih besar menunjukkan
                            prioritas pemeliharaan yang lebih tinggi.
                        </p>
                    </div>

                    <span class="badge text-bg-light border">
                        <?= e($resultCount) ?> hasil
                    </span>
                </div>

                <div class="card-body p-0">
                    <?php if ($alternativeRows === []): ?>
                        <div class="text-center text-body-secondary p-5">
                            Hasil TOPSIS belum tersedia.
                        </div>
                    <?php else: ?>
                        <?php
                        $rankedRows = $alternativeRows;

                        usort(
                            $rankedRows,
                            static function (
                                array $first,
                                array $second
                            ): int {
                                $firstRank = is_numeric(
                                    $first['ranking']
                                    ?? null
                                )
                                    ? (int) $first['ranking']
                                    : PHP_INT_MAX;

                                $secondRank = is_numeric(
                                    $second['ranking']
                                    ?? null
                                )
                                    ? (int) $second['ranking']
                                    : PHP_INT_MAX;

                                if ($firstRank !== $secondRank) {
                                    return $firstRank
                                        <=> $secondRank;
                                }

                                return (float) (
                                    $second['preference_value']
                                    ?? 0
                                )
                                    <=> (float) (
                                        $first['preference_value']
                                        ?? 0
                                    );
                            }
                        );
                        ?>

                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0 report-table">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col" class="text-center">
                                            Peringkat
                                        </th>

                                        <th scope="col">
                                            Alternatif
                                        </th>

                                        <th scope="col">
                                            Motor
                                        </th>

                                        <th scope="col" class="text-end">
                                            D+
                                        </th>

                                        <th scope="col" class="text-end">
                                            D−
                                        </th>

                                        <th scope="col" class="text-end">
                                            Nilai Preferensi
                                        </th>

                                        <th scope="col" class="text-center">
                                            Kelompok
                                        </th>

                                        <th scope="col">
                                            Prioritas
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach (
                                        $rankedRows
                                        as $row
                                    ): ?>
                                        <?php
                                        $rankInfo = $rankingMeta(
                                            $row['rank_group']
                                            ?? null,
                                            $row['ranking']
                                            ?? null
                                        );
                                        ?>

                                        <tr>
                                            <td class="text-center">
                                                <?php if (
                                                    is_numeric(
                                                        $row['ranking']
                                                        ?? null
                                                    )
                                                ): ?>
                                                    <span class="badge text-bg-primary fs-6">
                                                        #<?= e(
                                                            (int) $row[
                                                                'ranking'
                                                            ]
                                                        ) ?>
                                                    </span>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <strong>
                                                    <?= e(
                                                        $row[
                                                            'alternative_code'
                                                        ]
                                                    ) ?>
                                                </strong>
                                            </td>

                                            <td>
                                                <strong class="d-block">
                                                    <?= e(
                                                        $row['motor_name']
                                                    ) ?>
                                                </strong>

                                                <small class="text-body-secondary">
                                                    <?= e(
                                                        $row['machine_code']
                                                    ) ?>
                                                    /
                                                    <?= e($motorFunctionLabel(
                                                        isset(
                                                            $row[
                                                                'motor_function'
                                                            ]
                                                        )
                                                            ? (string) $row[
                                                                'motor_function'
                                                            ]
                                                            : null
                                                    )) ?>
                                                </small>
                                            </td>

                                            <td class="text-end">
                                                <?= e($displayNumber(
                                                    $row[
                                                        'positive_distance'
                                                    ]
                                                    ?? null,
                                                    6
                                                )) ?>
                                            </td>

                                            <td class="text-end">
                                                <?= e($displayNumber(
                                                    $row[
                                                        'negative_distance'
                                                    ]
                                                    ?? null,
                                                    6
                                                )) ?>
                                            </td>

                                            <td class="text-end">
                                                <strong>
                                                    <?= e($displayNumber(
                                                        $row[
                                                            'preference_value'
                                                        ]
                                                        ?? null,
                                                        6
                                                    )) ?>
                                                </strong>
                                            </td>

                                            <td class="text-center">
                                                <?= e(
                                                    $row['rank_group']
                                                    ?? '-'
                                                ) ?>
                                            </td>

                                            <td>
                                                <span class="badge text-bg-<?= e( $rankInfo['class'] ) ?>">
                                                    <?= e(
                                                        $row['priority_label']
                                                        ?? $rankInfo['label']
                                                    ) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="topsis-notes-title">
                <div class="card-header bg-white py-3">
                    <h2 id="topsis-notes-title" class="h5 mb-0">
                        Keterangan Perhitungan
                    </h2>
                </div>

                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h3 class="h6">
                                Tahapan TOPSIS
                            </h3>

                            <ol class="small mb-0 ps-3">
                                <li>Menyusun nilai C1–C5 untuk A1–A6.</li>
                                <li>Menormalisasi nilai agar dapat dibandingkan.</li>
                                <li>Mengalikan hasil normalisasi dengan bobot AHP.</li>
                                <li>Menentukan kondisi ideal dan menghitung jaraknya.</li>
                                <li>Menghitung nilai preferensi untuk menentukan peringkat.</li>
                            </ol>
                        </div>

                        <div class="col-md-6">
                            <h3 class="h6">
                                Ketentuan Penelitian
                            </h3>

                            <p class="small mb-2">
                                Seluruh kriteria diperlakukan sebagai benefit
                                terhadap urgensi pemeliharaan.
                            </p>

                            <p class="small mb-2">
                                Semakin besar nilai C1–C5, semakin tinggi
                                kebutuhan motor untuk diprioritaskan.
                            </p>

                            <p class="small mb-0">
                                Bobot TOPSIS berasal langsung dari hasil AHP
                                satu responden ahli, yaitu Supervisor Maintenance.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="stored-result-validation-title">
                <div class="card-header bg-white py-3">
                    <h2 id="stored-result-validation-title" class="h5 mb-1">
                        Validasi Hasil Tersimpan
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Perbandingan hasil TOPSIS pada database dengan
                        perhitungan ulang dari matriks keputusan dan bobot AHP.
                    </p>
                </div>

                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-md-4">
                            <small class="text-body-secondary d-block">
                                Alternatif Valid
                            </small>

                            <strong>
                                <?= e(
                                    count(
                                        $alternativeCodes
                                    )
                                ) ?>

                                /

                                <?= e(
                                    $expectedMachineCount
                                ) ?>
                            </strong>
                        </div>

                        <div class="col-md-4">
                            <small class="text-body-secondary d-block">
                                Hasil Sesuai Perhitungan
                            </small>

                            <span class="badge text-bg-<?= $storedResultsMatchCalculation ? 'success' : 'danger' ?>">
                                <?= $storedResultsMatchCalculation
                                    ? 'Sesuai'
                                    : 'Tidak Sesuai' ?>
                            </span>
                        </div>

                        <div class="col-md-4">
                            <small class="text-body-secondary d-block">
                                Jumlah Ketidaksesuaian
                            </small>

                            <strong>
                                <?= e(
                                    $resultMismatchCount
                                ) ?>
                            </strong>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="calculation-information-title">
                <div class="card-header bg-white py-3">
                    <h2 id="calculation-information-title" class="h5 mb-0">
                        Informasi Perhitungan
                    </h2>
                </div>

                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-md-4">
                            <small class="text-body-secondary d-block">
                                Responden Bobot AHP
                            </small>

                            <strong>
                                <?= e(
                                    $ahpSummary['assessor_name']
                                    ?? 'Belum tersedia'
                                ) ?>
                            </strong>
                        </div>

                        <div class="col-md-4">
                            <small class="text-body-secondary d-block">
                                Rasio Konsistensi AHP
                            </small>

                            <strong>
                                <?= e($displayNumber(
                                    $ahpSummary[
                                        'consistency_ratio'
                                    ]
                                    ?? null,
                                    6
                                )) ?>
                            </strong>
                        </div>

                        <div class="col-md-4">
                            <small class="text-body-secondary d-block">
                                Waktu Perhitungan TOPSIS
                            </small>

                            <strong>
                                <?= e(format_date_id(
                                    $calculatedAt,
                                    true
                                )) ?>
                            </strong>
                        </div>
                    </div>
                </div>
            </section>

            <section class="d-none d-print-block mt-5" aria-label="Pengesahan laporan">
                <div class="row text-center">
                    <div class="col-6">
                        <p class="mb-5">
                            Dicetak oleh,
                            <br>
                            <?= e($currentRoleLabel) ?>
                        </p>

                        <p class="mb-0">
                            <strong>
                                <?= e($currentUserName) ?>
                            </strong>
                        </p>
                    </div>

                    <div class="col-6">
                        <p class="mb-5">
                            Mengetahui,
                            <br>
                            Supervisor Maintenance
                        </p>

                        <p class="mb-0">
                            <strong>
                                <?= e(
                                    $ahpSummary['assessor_name']
                                    ?? '(........................................)'
                                ) ?>
                            </strong>
                        </p>
                    </div>
                </div>
            </section>

            <div class="alert alert-light border mb-0 no-print" role="status">
                <strong>Catatan:</strong>
                halaman laporan hanya membaca hasil TOPSIS yang tersimpan.
                Perhitungan atau perhitungan ulang dilakukan melalui modul
                Pemeringkatan TOPSIS oleh Supervisor Maintenance.
            </div>
        <?php endif; ?>
    </main>
</div>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

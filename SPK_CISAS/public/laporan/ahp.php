<?php

declare(strict_types=1);

/*
 * Laporan Pembobotan AHP
 * Menampilkan:
 * - identitas periode;
 * - responden ahli AHP;
 * - perbandingan berpasangan;
 * - matriks perbandingan;
 * - bobot lima kriteria;
 * - Lambda Maksimum, CI, RI, dan CR;
 * - status konsistensi AHP.
 * Hak akses:
 * - Administrator: melihat dan mencetak laporan;
 * - Supervisor Maintenance: melihat dan mencetak laporan.
 * Pembobotan AHP berasal dari satu responden ahli,
 * yaitu Supervisor Maintenance.
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'helpers'
    . DIRECTORY_SEPARATOR . 'auth.php';

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

// Pastikan Akun Masih Aktif

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun pengguna sudah tidak aktif atau tidak ditemukan.'
    );

    redirect('login.php');
}

// Informasi Pengguna

$currentRole = auth_role();

$currentUserName =
    auth_name()
    ?? 'Pengguna';

$currentRoleLabel =
    auth_role_label(
        $currentRole
    );

$isAdmin =
    $currentRole === 'admin';

$isSupervisor =
    $currentRole === 'supervisor';

// Konfigurasi AHP

$expectedCriteriaCount = (int) app_config(
    'total_criteria',
    5
);

$expectedComparisonCount = (int) (
    (
        $expectedCriteriaCount
        * ($expectedCriteriaCount - 1)
    ) / 2
);

$consistencyLimit =
    0.10;

$weightTolerance =
    0.0001;

$expectedCriterionCodes = [
    'C1',
    'C2',
    'C3',
    'C4',
    'C5',
];

$expectedAlternativeCodes = [
    'A1',
    'A2',
    'A3',
    'A4',
    'A5',
    'A6',
];

// Nilai Awal

$periods = [];

$selectedPeriod = null;

$criteria = [];

$comparisons = [];

$weightRows = [];

$ahpSummary = null;

$pageError = null;

$criteriaById = [];

$criterionIds = [];

$matrix = [];

$columnTotals = [];

$comparisonCount = 0;

$weightCount = 0;

$totalWeight = 0.0;

$topsisResultCount = 0;

$comparisonPairKeys = [];

$comparisonAssessorIds = [];

$comparisonAssessorNames = [];

$comparisonAssessorRoles = [];

$invalidComparisonCount = 0;

$weightByCode = [];

$invalidWeightCount = 0;

$matrixReciprocalValid = true;

$totalWeightValid = false;

$singleAssessorValid = false;

$summaryAssessorMatches = false;

$assessorName =
    'Supervisor Maintenance';

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

    $periods =
        $periodStatement->fetchAll();
} catch (Throwable $exception) {
    error_log(
        'Daftar periode laporan AHP gagal dimuat: '
        . $exception->getMessage()
    );

    $pageError =
        'Daftar periode laporan AHP belum dapat dimuat.';
}

// Tentukan Periode yang Dipilih

$periodIdInput =
    $_GET['period_id']
    ?? null;

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
            'ID periode laporan AHP tidak valid.';

        require dirname(__DIR__)
            . DIRECTORY_SEPARATOR
            . '404.php';

        exit;
    }

    foreach (
        $periods
        as $period
    ) {
        if (
            (int) $period['id']
            === (int) $validatedPeriodId
        ) {
            $selectedPeriod =
                $period;

            break;
        }
    }

    if ($selectedPeriod === null) {
        $notFoundMessage =
            'Periode laporan AHP tidak ditemukan.';

        require dirname(__DIR__)
            . DIRECTORY_SEPARATOR
            . '404.php';

        exit;
    }
} elseif ($periods !== []) {
    // Prioritaskan Periode Aktif

    foreach (
        $periods
        as $period
    ) {
        if (
            (string) (
                $period['status']
                ?? ''
            ) === 'active'
        ) {
            $selectedPeriod =
                $period;

            break;
        }
    }

    // Gunakan Periode Pertama Jika Tidak Ada Periode Aktif

    $selectedPeriod ??=
        $periods[0];
}

$selectedPeriodId =
    $selectedPeriod !== null
        ? (int) $selectedPeriod['id']
        : null;

// Ambil Data AHP

if ($selectedPeriodId !== null) {
    try {
        // Ambil Kriteria Aktif C1 sampai C5

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

        $criteria =
            $criteriaStatement->fetchAll();

        foreach (
            $criteria
            as $criterion
        ) {
            $criterionId = (int) (
                $criterion['id']
                ?? 0
            );

            if ($criterionId < 1) {
                continue;
            }

            $criterionIds[] =
                $criterionId;

            $criteriaById[
                $criterionId
            ] = $criterion;
        }

        // Ambil Perbandingan Berpasangan

        $comparisonStatement = $pdo->prepare(
            "SELECT
                ac.id,
                ac.criterion_left_id,
                ac.criterion_right_id,
                ac.comparison_value,
                ac.assessor_id,
                ac.created_at,
                ac.updated_at,

                left_criterion.code AS left_code,
                left_criterion.name AS left_name,

                right_criterion.code AS right_code,
                right_criterion.name AS right_name,

                assessor.name AS assessor_name,
                assessor.role AS assessor_role

             FROM ahp_comparisons AS ac

             INNER JOIN criteria AS left_criterion
                ON left_criterion.id =
                   ac.criterion_left_id

             INNER JOIN criteria AS right_criterion
                ON right_criterion.id =
                   ac.criterion_right_id

             INNER JOIN users AS assessor
                ON assessor.id = ac.assessor_id

             WHERE ac.period_id = :period_id
               AND left_criterion.is_active = 1
               AND right_criterion.is_active = 1

               AND left_criterion.code IN (
                   'C1',
                   'C2',
                   'C3',
                   'C4',
                   'C5'
               )

               AND right_criterion.code IN (
                   'C1',
                   'C2',
                   'C3',
                   'C4',
                   'C5'
               )

             ORDER BY
                left_criterion.display_order ASC,
                right_criterion.display_order ASC,
                ac.id ASC"
        );

        $comparisonStatement->execute([
            'period_id' =>
                $selectedPeriodId,
        ]);

        $comparisons =
            $comparisonStatement->fetchAll();

        // Validasi Perbandingan dan Responden Ahli

        foreach (
            $comparisons
            as $comparison
        ) {
            $leftId = (int) (
                $comparison[
                    'criterion_left_id'
                ]
                ?? 0
            );

            $rightId = (int) (
                $comparison[
                    'criterion_right_id'
                ]
                ?? 0
            );

            $comparisonValue =
                $comparison[
                    'comparison_value'
                ]
                ?? null;

            $assessorId = (int) (
                $comparison[
                    'assessor_id'
                ]
                ?? 0
            );

            $candidateAssessor = trim(
                (string) (
                    $comparison[
                        'assessor_name'
                    ]
                    ?? ''
                )
            );

            $candidateAssessorRole =
                (string) (
                    $comparison[
                        'assessor_role'
                    ]
                    ?? ''
                );

            if (
                $leftId < 1
                || $rightId < 1
                || $leftId === $rightId
                || !isset(
                    $criteriaById[$leftId]
                )
                || !isset(
                    $criteriaById[$rightId]
                )
                || $comparisonValue === null
                || $comparisonValue === ''
                || !is_numeric(
                    (string) $comparisonValue
                )
                || !is_finite(
                    (float) $comparisonValue
                )
                || (float) $comparisonValue <= 0
            ) {
                $invalidComparisonCount++;

                continue;
            }

            $pairIds = [
                $leftId,
                $rightId,
            ];

            sort($pairIds);

            $pairKey =
                $pairIds[0]
                . ':'
                . $pairIds[1];

            // Satu Pasangan Hanya Boleh Muncul Sekali

            if (
                isset(
                    $comparisonPairKeys[
                        $pairKey
                    ]
                )
            ) {
                $invalidComparisonCount++;

                continue;
            }

            $comparisonPairKeys[
                $pairKey
            ] = true;

            if ($assessorId > 0) {
                $comparisonAssessorIds[
                    $assessorId
                ] = true;
            }

            if ($candidateAssessor !== '') {
                $comparisonAssessorNames[
                    $candidateAssessor
                ] = true;
            }

            if (
                $candidateAssessorRole
                !== ''
            ) {
                $comparisonAssessorRoles[
                    $candidateAssessorRole
                ] = true;
            }
        }

        $comparisonCount =
            count(
                $comparisonPairKeys
            );

        // Harus Satu Responden Berperan Supervisor

        $singleAssessorValid =
            count(
                $comparisonAssessorIds
            ) === 1

            && count(
                $comparisonAssessorRoles
            ) === 1

            && isset(
                $comparisonAssessorRoles[
                    'supervisor'
                ]
            );

        if (
            count(
                $comparisonAssessorNames
            ) === 1
        ) {
            $assessorName =
                (string) array_key_first(
                    $comparisonAssessorNames
                );
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
            'period_id' =>
                $selectedPeriodId,
        ]);

        $weightRows =
            $weightStatement->fetchAll();

        foreach (
            $weightRows
            as $weightRow
        ) {
            $criterionCode = strtoupper(
                trim(
                    (string) (
                        $weightRow['code']
                        ?? ''
                    )
                )
            );

            $weightValue =
                $weightRow['weight']
                ?? null;

            if (
                !in_array(
                    $criterionCode,
                    $expectedCriterionCodes,
                    true
                )
                || isset(
                    $weightByCode[
                        $criterionCode
                    ]
                )
                || $weightValue === null
                || $weightValue === ''
                || !is_numeric(
                    (string) $weightValue
                )
                || !is_finite(
                    (float) $weightValue
                )
                || (float) $weightValue <= 0
            ) {
                $invalidWeightCount++;

                continue;
            }

            $numericWeight =
                (float) $weightValue;

            $weightByCode[
                $criterionCode
            ] = $numericWeight;

            $totalWeight +=
                $numericWeight;
        }

        $weightCount =
            count(
                $weightByCode
            );

        $totalWeightValid =
            abs(
                $totalWeight - 1.0
            ) <= $weightTolerance;

        // Ambil Ringkasan Konsistensi

        $summaryStatement = $pdo->prepare(
            "SELECT
                ahs.assessor_id,
                ahs.lambda_max,
                ahs.consistency_index,
                ahs.random_index,
                ahs.consistency_ratio,
                ahs.is_consistent,
                ahs.calculated_at,

                assessor.name AS assessor_name,
                assessor.role AS assessor_role

             FROM ahp_summaries AS ahs

             INNER JOIN users AS assessor
                ON assessor.id =
                   ahs.assessor_id

             WHERE ahs.period_id = :period_id

             LIMIT 1"
        );

        $summaryStatement->execute([
            'period_id' =>
                $selectedPeriodId,
        ]);

        $summaryData =
            $summaryStatement->fetch();

        $ahpSummary =
            is_array($summaryData)
                ? $summaryData
                : null;

        if ($ahpSummary !== null) {
            $summaryAssessor = trim(
                (string) (
                    $ahpSummary[
                        'assessor_name'
                    ]
                    ?? ''
                )
            );

            $summaryAssessorId = (int) (
                $ahpSummary[
                    'assessor_id'
                ]
                ?? 0
            );

            $summaryAssessorRole =
                (string) (
                    $ahpSummary[
                        'assessor_role'
                    ]
                    ?? ''
                );

            if ($summaryAssessor !== '') {
                $assessorName =
                    $summaryAssessor;
            }

            // Responden Ringkasan Harus Sama dengan Responden Perbandingan

            $summaryAssessorMatches =
                $summaryAssessorId > 0

                && $summaryAssessorRole
                    === 'supervisor'

                && isset(
                    $comparisonAssessorIds[
                        $summaryAssessorId
                    ]
                )

                && count(
                    $comparisonAssessorIds
                ) === 1;
        }

        // Cek Hasil TOPSIS A1 sampai A6

        $topsisStatement = $pdo->prepare(
            "SELECT COUNT(
                DISTINCT m.alternative_code
             )

             FROM topsis_results AS tr

             INNER JOIN assessments AS a
                ON a.id = tr.assessment_id

             INNER JOIN machines AS m
                ON m.id = a.machine_id

             WHERE tr.period_id = :period_id

               AND m.alternative_code IN (
                   'A1',
                   'A2',
                   'A3',
                   'A4',
                   'A5',
                   'A6'
               )

               AND tr.preference_value
                   IS NOT NULL

               AND tr.ranking
                   IS NOT NULL

               AND tr.rank_group
                   IS NOT NULL"
        );

        $topsisStatement->execute([
            'period_id' =>
                $selectedPeriodId,
        ]);

        $topsisResultCount =
            (int) $topsisStatement
                ->fetchColumn();

        // Susun Matriks Perbandingan

        foreach (
            $criterionIds
            as $rowId
        ) {
            $matrix[$rowId] = [];

            foreach (
                $criterionIds
                as $columnId
            ) {
                $matrix[$rowId][
                    $columnId
                ] =
                    $rowId === $columnId
                        ? 1.0
                        : null;
            }
        }

        $filledPairKeys = [];

        foreach (
            $comparisons
            as $comparison
        ) {
            $leftId = (int) (
                $comparison[
                    'criterion_left_id'
                ]
                ?? 0
            );

            $rightId = (int) (
                $comparison[
                    'criterion_right_id'
                ]
                ?? 0
            );

            $comparisonValue =
                is_numeric(
                    $comparison[
                        'comparison_value'
                    ]
                    ?? null
                )
                    ? (float) $comparison[
                        'comparison_value'
                    ]
                    : 0.0;

            $pairIds = [
                $leftId,
                $rightId,
            ];

            sort($pairIds);

            $pairKey =
                $pairIds[0]
                . ':'
                . $pairIds[1];

            if (
                !isset(
                    $criteriaById[$leftId]
                )
                || !isset(
                    $criteriaById[$rightId]
                )
                || $leftId === $rightId
                || $comparisonValue <= 0
                || isset(
                    $filledPairKeys[
                        $pairKey
                    ]
                )
            ) {
                continue;
            }

            $filledPairKeys[
                $pairKey
            ] = true;

            $matrix[$leftId][
                $rightId
            ] = $comparisonValue;

            $matrix[$rightId][
                $leftId
            ] = 1 / $comparisonValue;
        }

        // Hitung Jumlah Setiap Kolom

        foreach (
            $criterionIds
            as $columnId
        ) {
            $columnTotal = 0.0;

            $columnComplete = true;

            foreach (
                $criterionIds
                as $rowId
            ) {
                $matrixValue =
                    $matrix[$rowId][
                        $columnId
                    ]
                    ?? null;

                if (
                    !is_numeric(
                        $matrixValue
                    )
                ) {
                    $columnComplete = false;

                    break;
                }

                $columnTotal +=
                    (float) $matrixValue;
            }

            $columnTotals[
                $columnId
            ] =
                $columnComplete
                    ? $columnTotal
                    : null;
        }
    } catch (Throwable $exception) {
        error_log(
            'Laporan AHP gagal dimuat: '
            . $exception->getMessage()
        );

        $pageError =
            'Data laporan AHP belum dapat dimuat.';
    }
}

// Status Kelengkapan

$criteriaCodes = array_map(
    static fn (
        array $criterion
    ): string =>
        strtoupper(
            trim(
                (string) (
                    $criterion['code']
                    ?? ''
                )
            )
        ),
    $criteria
);

// Kriteria Harus Tepat C1 sampai C5

$criteriaComplete =
    count($criteria)
        === $expectedCriteriaCount

    && count(
        array_diff(
            $expectedCriterionCodes,
            $criteriaCodes
        )
    ) === 0

    && count(
        array_diff(
            $criteriaCodes,
            $expectedCriterionCodes
        )
    ) === 0;

// Perbandingan Harus Tepat Sepuluh Pasangan Unik

$comparisonsComplete =
    $comparisonCount
        === $expectedComparisonCount

    && $invalidComparisonCount === 0

    && $singleAssessorValid;

// Bobot Harus Tepat Lima dan Totalnya Sama dengan Satu

$weightsComplete =
    $weightCount
        === $expectedCriteriaCount

    && $invalidWeightCount === 0

    && count(
        array_diff(
            $expectedCriterionCodes,
            array_keys(
                $weightByCode
            )
        )
    ) === 0

    && $totalWeightValid;

// Validasi Matriks

$matrixComplete =
    $criteriaComplete
    && $comparisonsComplete;

if ($matrixComplete) {
    foreach (
        $criterionIds
        as $rowId
    ) {
        foreach (
            $criterionIds
            as $columnId
        ) {
            $matrixValue =
                $matrix[$rowId][
                    $columnId
                ]
                ?? null;

            if (
                !is_numeric(
                    $matrixValue
                )
                || !is_finite(
                    (float) $matrixValue
                )
                || (float) $matrixValue <= 0
            ) {
                $matrixComplete = false;

                break 2;
            }

            if ($rowId !== $columnId) {
                $reverseValue =
                    $matrix[$columnId][
                        $rowId
                    ]
                    ?? null;

                if (
                    !is_numeric(
                        $reverseValue
                    )
                    || abs(
                        (
                            (float) $matrixValue
                            * (float) $reverseValue
                        ) - 1.0
                    ) > 0.000001
                ) {
                    $matrixReciprocalValid =
                        false;

                    $matrixComplete =
                        false;

                    break 2;
                }
            }
        }
    }
}

// Rasio Konsistensi

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

// Status Konsistensi AHP

$ahpIsConsistent =
    $ahpSummary !== null

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

// Ketersediaan Laporan

$reportAvailable =
    $criteriaComplete

    && $comparisonsComplete

    && $weightsComplete

    && $matrixComplete

    && $ahpSummary !== null

    && $summaryAssessorMatches;

$topsisComplete =
    $topsisResultCount
        === count(
            $expectedAlternativeCodes
        );

// Fungsi Tampilan Angka

$displayNumber = static function (
    mixed $value,
    int $decimals = 4
): string {
    if (
        $value === null
        || $value === ''
        || !is_numeric($value)
    ) {
        return '-';
    }

    $number =
        (float) $value;

    if (!is_finite($number)) {
        return '-';
    }

    return format_number_id(
        $number,
        $decimals
    );
};

// Fungsi Tampilan Nilai Skala

$comparisonScaleValue = static function (
    mixed $value
) use (
    $displayNumber
): string {
    if (!is_numeric($value)) {
        return '-';
    }

    $number = (float) $value;
    $knownValues = [
        '1/9' => 1 / 9,
        '1/7' => 1 / 7,
        '1/5' => 1 / 5,
        '1/3' => 1 / 3,
        '1' => 1.0,
        '3' => 3.0,
        '5' => 5.0,
        '7' => 7.0,
        '9' => 9.0,
    ];

    foreach ($knownValues as $label => $knownValue) {
        if (abs($number - $knownValue) < 0.000001) {
            return (string) $label;
        }
    }

    return $displayNumber($number, 4);
};

// Fungsi Interpretasi Skala

$comparisonScaleLabel = static function (
    mixed $value
): string {
    if (!is_numeric($value)) {
        return '-';
    }

    $number = (float) $value;
    $labels = [
        9.0 => 'Kriteria kiri mutlak penting',
        7.0 => 'Kriteria kiri sangat penting',
        5.0 => 'Kriteria kiri lebih penting',
        3.0 => 'Kriteria kiri cukup penting',
        1.0 => 'Sama penting',
    ];

    foreach ($labels as $knownValue => $label) {
        if (abs($number - $knownValue) < 0.000001) {
            return (string) $label;
        }
    }

    $reciprocalLabels = [
        [1 / 3, 'Kriteria kanan cukup penting'],
        [1 / 5, 'Kriteria kanan lebih penting'],
        [1 / 7, 'Kriteria kanan sangat penting'],
        [1 / 9, 'Kriteria kanan mutlak penting'],
    ];

    foreach ($reciprocalLabels as [$knownValue, $label]) {
        if (abs($number - $knownValue) < 0.000001) {
            return (string) $label;
        }
    }

    return 'Nilai perbandingan';
};

// Status Periode

$periodStatusConfigurations = [
    'draft' => [
        'label' =>
            'Draft',

        'class' =>
            'secondary',
    ],

    'active' => [
        'label' =>
            'Aktif',

        'class' =>
            'success',
    ],

    'completed' => [
        'label' =>
            'Selesai',

        'class' =>
            'primary',
    ],
];

$selectedPeriodStatus =
    $selectedPeriod !== null
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
        'label' =>
            'Tidak diketahui',

        'class' =>
            'secondary',
    ];

// Informasi Cetak

$printedAt =
    date('Y-m-d H:i:s');

// Konfigurasi Halaman

$pageTitle =
    'Laporan Pembobotan AHP';

$pageDescription =
    'Laporan matriks perbandingan, bobot kriteria, '
    . 'dan konsistensi AHP.';

$bodyClass =
    'd-flex flex-column min-vh-100';

$includePrintCss =
    true;

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

        <?php if (
            $pageError !== null
        ): ?>
            <div class="alert alert-danger no-print" role="alert">
                <strong class="d-block mb-1">
                    Laporan belum dapat dimuat
                </strong>

                <?= e(
                    $pageError
                ) ?>
            </div>
        <?php endif; ?>

        <!-- Judul Halaman -->
        <section class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4 no-print">
            <div>
                <h1 class="page-title mb-1">
                    Laporan Pembobotan AHP
                </h1>

                <p class="page-description mb-0">
                    Matriks perbandingan, bobot lima
                    kriteria, dan hasil uji konsistensi.
                </p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a href="<?= e( base_url( 'laporan/index.php' . ( $selectedPeriodId !== null ? '?period_id=' . $selectedPeriodId : '' ) ) ) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>

                <button type="button" class="btn btn-primary" onclick="window.print()" <?= !$reportAvailable ? 'disabled' : '' ?>>
                    Cetak Laporan
                </button>
            </div>
        </section>

        <!-- Informasi Peran -->
        <?php if ($isAdmin): ?>
            <div class="alert alert-secondary mb-4 no-print" role="status">
                Administrator membuka laporan dalam
                mode baca. Perbandingan dan bobot AHP
                tidak dapat diubah dari halaman laporan.
            </div>
        <?php elseif ($isSupervisor): ?>
            <div class="alert alert-info mb-4 no-print" role="status">
                Laporan menampilkan hasil pembobotan
                dari satu responden ahli, yaitu
                Supervisor Maintenance.
            </div>
        <?php endif; ?>

        <!-- Filter Periode -->
        <section class="card border-0 shadow-sm mb-4 no-print" aria-labelledby="ahp-report-filter-title">
            <div class="card-body p-4">
                <h2 id="ahp-report-filter-title" class="h5 mb-1">
                    Periode Laporan
                </h2>

                <p class="small text-body-secondary mb-3">
                    Pilih periode pembobotan AHP yang
                    akan ditampilkan.
                </p>

                <form method="get" action="<?= e( base_url( 'laporan/ahp.php' ) ) ?>" class="row g-3 align-items-end">
                    <div class="col-md-8 col-xl-9">
                        <label for="period_id" class="form-label">
                            Periode Pemeriksaan
                        </label>

                        <select id="period_id" name="period_id" class="form-select" required>
                            <?php if (
                                $periods === []
                            ): ?>
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

        <?php if (
            $selectedPeriod === null
        ): ?>
            <div class="alert alert-warning" role="status">
                Belum terdapat periode pemeriksaan
                yang dapat ditampilkan pada laporan AHP.
            </div>
        <?php else: ?>

            <!-- Kepala Cetak -->
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
                    <h1>LAPORAN PEMBOBOTAN AHP</h1>
                    <p>Metode Analytical Hierarchy Process (AHP) dan TOPSIS</p>
                </div>
            </header>

            <!-- Identitas Laporan -->
            <section class="card border-0 shadow-sm mb-4 report-identity" aria-labelledby="ahp-report-identity-title">
                <div class="card-body p-4">
                    <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                        <div>
                            <h2 id="ahp-report-identity-title" class="h5 mb-2">
                                <?= e(
                                    $selectedPeriod['name']
                                ) ?>
                            </h2>

                            <p class="mb-1">
                                <strong>
                                    Rentang tanggal:
                                </strong>

                                <?= e(
                                    format_date_id(
                                        $selectedPeriod[
                                            'start_date'
                                        ]
                                    )
                                ) ?>

                                sampai

                                <?= e(
                                    format_date_id(
                                        $selectedPeriod[
                                            'end_date'
                                        ]
                                    )
                                ) ?>
                            </p>

                            <p class="mb-1">
                                <strong>
                                    Responden ahli:
                                </strong>

                                <?= e(
                                    $assessorName
                                ) ?>
                            </p>

                            <p class="mb-0">
                                <strong>
                                    Jumlah kriteria:
                                </strong>

                                <?= e(
                                    count($criteria)
                                ) ?>
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
                                Dicetak oleh

                                <?= e(
                                    $currentUserName
                                ) ?>

                                <br>

                                <?= e(
                                    format_date_id(
                                        $printedAt,
                                        true
                                    )
                                ) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Ringkasan -->
            <section class="row row-cols-2 row-cols-md-3 row-cols-xl-6 g-3 mb-4" aria-label="Ringkasan laporan AHP">
                <div class="col">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                Kriteria
                            </p>

                            <p class="h3 fw-semibold mb-1">
                                <?= e(
                                    count($criteria)
                                ) ?>
                            </p>

                            <small class="text-body-secondary">
                                Dari

                                <?= e(
                                    $expectedCriteriaCount
                                ) ?>

                                kriteria
                            </small>
                        </div>
                    </article>
                </div>

                <div class="col">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                Perbandingan
                            </p>

                            <p class="h3 fw-semibold mb-1">
                                <?= e(
                                    $comparisonCount
                                ) ?>
                            </p>

                            <small class="text-body-secondary">
                                Dari

                                <?= e(
                                    $expectedComparisonCount
                                ) ?>

                                pasangan
                            </small>
                        </div>
                    </article>
                </div>

                <div class="col">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                Bobot
                            </p>

                            <p class="h3 fw-semibold mb-1">
                                <?= e(
                                    $weightCount
                                ) ?>
                            </p>

                            <small class="text-body-secondary">
                                Dari

                                <?= e(
                                    $expectedCriteriaCount
                                ) ?>

                                bobot
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
                                <?= e(
                                    $displayNumber(
                                        $totalWeight,
                                        4
                                    )
                                ) ?>
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
                                Rasio Konsistensi
                            </p>

                            <p class="h5 fw-semibold mb-1">
                                <?= e(
                                    $displayNumber(
                                        $consistencyRatio,
                                        4
                                    )
                                ) ?>
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
                                Konsistensi
                            </p>

                            <p class="mb-2">
                                <span class="badge text-bg-<?= $ahpIsConsistent ? 'success' : 'warning' ?> fs-6">
                                    <?= $ahpIsConsistent
                                        ? 'Konsisten'
                                        : 'Belum Konsisten' ?>
                                </span>
                            </p>

                            <small class="text-body-secondary">
                                <?= $ahpSummary !== null
                                    ? 'Hasil perhitungan AHP'
                                    : 'Belum dihitung' ?>
                            </small>
                        </div>
                    </article>
                </div>
            </section>

            <!-- Status Utama -->
            <?php if (!$reportAvailable): ?>
                <div class="alert alert-warning mb-4" role="status">
                    Laporan AHP belum lengkap atau belum valid.
                    Pastikan kriteria tepat C1–C5, sepuluh
                    pasangan perbandingan unik berasal dari satu
                    Supervisor, matriks bersifat resiprokal,
                    lima bobot positif berjumlah 1,0000, serta
                    ringkasan konsistensi menggunakan responden
                    yang sama.
                </div>
            <?php elseif (!$ahpIsConsistent): ?>
                <div class="alert alert-warning mb-4" role="status">
                    Nilai AHP telah dihitung, tetapi
                    Rasio Konsistensi masih melebihi
                    0,10. Hasil belum layak digunakan
                    sebagai bobot TOPSIS.
                </div>
            <?php else: ?>
                <div class="alert alert-success mb-4" role="status">
                    Rasio Konsistensi memenuhi batas
                    CR ≤ 0,10. Bobot AHP konsisten dan
                    dapat digunakan pada proses TOPSIS.
                </div>
            <?php endif; ?>

            <!-- Validasi Struktur -->
            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="ahp-validation-title">
                <div class="card-header bg-white py-3">
                    <h2 id="ahp-validation-title" class="h5 mb-1">
                        Validasi Struktur AHP
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Pemeriksaan kesesuaian data AHP dengan
                        keputusan final penelitian.
                    </p>
                </div>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-0 report-table">
                            <thead class="table-light">
                                <tr>
                                    <th>
                                        Komponen
                                    </th>

                                    <th>
                                        Kondisi
                                    </th>

                                    <th class="text-center">
                                        Status
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr>
                                    <td>
                                        Kriteria aktif
                                    </td>

                                    <td>
                                        C1, C2, C3, C4, dan C5
                                    </td>

                                    <td class="text-center">
                                        <span class="badge text-bg-<?= $criteriaComplete ? 'success' : 'warning' ?>">
                                            <?= $criteriaComplete
                                                ? 'Sesuai'
                                                : 'Belum sesuai' ?>
                                        </span>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        Perbandingan unik
                                    </td>

                                    <td>
                                        <?= e(
                                            $comparisonCount
                                        ) ?>

                                        dari

                                        <?= e(
                                            $expectedComparisonCount
                                        ) ?>

                                        pasangan
                                    </td>

                                    <td class="text-center">
                                        <span class="badge text-bg-<?= $comparisonsComplete ? 'success' : 'warning' ?>">
                                            <?= $comparisonsComplete
                                                ? 'Lengkap'
                                                : 'Belum lengkap' ?>
                                        </span>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        Responden ahli
                                    </td>

                                    <td>
                                        <?= e(
                                            $assessorName
                                        ) ?>
                                    </td>

                                    <td class="text-center">
                                        <span class="badge text-bg-<?= ( $singleAssessorValid && $summaryAssessorMatches ) ? 'success' : 'danger' ?>">
                                            <?= (
                                                $singleAssessorValid
                                                && $summaryAssessorMatches
                                            )
                                                ? 'Satu Supervisor'
                                                : 'Tidak konsisten' ?>
                                        </span>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        Matriks resiprokal
                                    </td>

                                    <td>
                                        aᵢⱼ × aⱼᵢ = 1
                                    </td>

                                    <td class="text-center">
                                        <span class="badge text-bg-<?= ( $matrixComplete && $matrixReciprocalValid ) ? 'success' : 'warning' ?>">
                                            <?= (
                                                $matrixComplete
                                                && $matrixReciprocalValid
                                            )
                                                ? 'Valid'
                                                : 'Belum valid' ?>
                                        </span>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        Total bobot
                                    </td>

                                    <td>
                                        <?= e(
                                            $displayNumber(
                                                $totalWeight,
                                                6
                                            )
                                        ) ?>
                                    </td>

                                    <td class="text-center">
                                        <span class="badge text-bg-<?= $totalWeightValid ? 'success' : 'warning' ?>">
                                            <?= $totalWeightValid
                                                ? 'Sama dengan 1'
                                                : 'Belum sama dengan 1' ?>
                                        </span>
                                    </td>
                                </tr>

                                <tr>
                                    <td>
                                        Hasil TOPSIS
                                    </td>

                                    <td>
                                        <?= e(
                                            $topsisResultCount
                                        ) ?>

                                        dari

                                        <?= e(
                                            count(
                                                $expectedAlternativeCodes
                                            )
                                        ) ?>

                                        alternatif
                                    </td>

                                    <td class="text-center">
                                        <span class="badge text-bg-<?= $topsisComplete ? 'success' : 'secondary' ?>">
                                            <?= $topsisComplete
                                                ? 'Lengkap'
                                                : 'Belum lengkap' ?>
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Daftar Kriteria -->
            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="criteria-title">
                <div class="card-header bg-white py-3">
                    <h2 id="criteria-title" class="h5 mb-1">
                        Daftar Kriteria
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Lima kriteria yang dibandingkan
                        oleh Supervisor Maintenance.
                    </p>
                </div>

                <div class="card-body p-0">
                    <?php if (
                        $criteria === []
                    ): ?>
                        <div class="text-center text-body-secondary p-5">
                            Data kriteria tidak tersedia.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0 report-table">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col" class="text-center">
                                            No.
                                        </th>

                                        <th scope="col">
                                            Kode
                                        </th>

                                        <th scope="col">
                                            Nama Kriteria
                                        </th>

                                        <th scope="col">
                                            Keterangan
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach (
                                        $criteria
                                        as $index => $criterion
                                    ): ?>
                                        <tr>
                                            <td class="text-center">
                                                <?= e(
                                                    $index + 1
                                                ) ?>
                                            </td>

                                            <td>
                                                <strong>
                                                    <?= e(
                                                        $criterion[
                                                            'code'
                                                        ]
                                                    ) ?>
                                                </strong>
                                            </td>

                                            <td>
                                                <?= e(
                                                    $criterion[
                                                        'name'
                                                    ]
                                                ) ?>
                                            </td>

                                            <td>
                                                <?= e(
                                                    $criterion[
                                                        'description'
                                                    ]
                                                    ?? '-'
                                                ) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Perbandingan Berpasangan -->
            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="comparison-title">
                <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div>
                        <h2 id="comparison-title" class="h5 mb-1">
                            Perbandingan Berpasangan
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Penilaian satu responden ahli
                            terhadap sepuluh pasangan kriteria.
                        </p>
                    </div>

                    <span class="badge text-bg-light border">
                        <?= e(
                            $comparisonCount
                        ) ?>

                        /

                        <?= e(
                            $expectedComparisonCount
                        ) ?>

                        pasangan
                    </span>
                </div>

                <div class="card-body p-0">
                    <?php if (
                        $comparisons === []
                    ): ?>
                        <div class="text-center text-body-secondary p-5">
                            Perbandingan AHP belum tersedia.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0 report-table">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col" class="text-center">
                                            No.
                                        </th>

                                        <th scope="col">
                                            Kriteria Kiri
                                        </th>

                                        <th scope="col" class="text-center">
                                            Nilai
                                        </th>

                                        <th scope="col">
                                            Kriteria Kanan
                                        </th>

                                        <th scope="col">
                                            Interpretasi
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach (
                                        $comparisons
                                        as $index => $comparison
                                    ): ?>
                                        <tr>
                                            <td class="text-center">
                                                <?= e(
                                                    $index + 1
                                                ) ?>
                                            </td>

                                            <td>
                                                <strong>
                                                    <?= e(
                                                        $comparison[
                                                            'left_code'
                                                        ]
                                                    ) ?>
                                                </strong>

                                                —

                                                <?= e(
                                                    $comparison[
                                                        'left_name'
                                                    ]
                                                ) ?>
                                            </td>

                                            <td class="text-center">
                                                <strong>
                                                    <?= e(
                                                        $comparisonScaleValue(
                                                            $comparison[
                                                                'comparison_value'
                                                            ]
                                                            ?? null
                                                        )
                                                    ) ?>
                                                </strong>
                                            </td>

                                            <td>
                                                <strong>
                                                    <?= e(
                                                        $comparison[
                                                            'right_code'
                                                        ]
                                                    ) ?>
                                                </strong>

                                                —

                                                <?= e(
                                                    $comparison[
                                                        'right_name'
                                                    ]
                                                ) ?>
                                            </td>

                                            <td>
                                                <?= e(
                                                    $comparisonScaleLabel(
                                                        $comparison[
                                                            'comparison_value'
                                                        ]
                                                        ?? null
                                                    )
                                                ) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Matriks Perbandingan -->
            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="matrix-title">
                <div class="card-header bg-white py-3">
                    <h2 id="matrix-title" class="h5 mb-1">
                        Matriks Perbandingan AHP
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Nilai diagonal adalah 1 dan nilai
                        di bawah diagonal merupakan nilai
                        kebalikan.
                    </p>
                </div>

                <div class="card-body p-0">
                    <?php if (
                        !$matrixComplete
                    ): ?>
                        <div class="text-center text-body-secondary p-5">
                            Matriks belum dapat ditampilkan
                            karena data perbandingan belum
                            lengkap atau tidak valid.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle text-center mb-0 report-table">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">
                                            Kriteria
                                        </th>

                                        <?php foreach (
                                            $criteria
                                            as $criterion
                                        ): ?>
                                            <th scope="col">
                                                <?= e(
                                                    $criterion[
                                                        'code'
                                                    ]
                                                ) ?>
                                            </th>
                                        <?php endforeach; ?>

                                        <th scope="col">
                                            Keterangan
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach (
                                        $criteria
                                        as $rowCriterion
                                    ): ?>
                                        <?php

                                        $rowCriterionId =
                                            (int) $rowCriterion[
                                                'id'
                                            ];

                                        ?>

                                        <tr>
                                            <th scope="row" class="table-light">
                                                <?= e(
                                                    $rowCriterion[
                                                        'code'
                                                    ]
                                                ) ?>
                                            </th>

                                            <?php foreach (
                                                $criteria
                                                as $columnCriterion
                                            ): ?>
                                                <?php

                                                $columnCriterionId =
                                                    (int) $columnCriterion[
                                                        'id'
                                                    ];

                                                ?>

                                                <td>
                                                    <?= e(
                                                        $displayNumber(
                                                            $matrix[
                                                                $rowCriterionId
                                                            ][
                                                                $columnCriterionId
                                                            ]
                                                            ?? null,
                                                            4
                                                        )
                                                    ) ?>
                                                </td>
                                            <?php endforeach; ?>

                                            <td class="text-body-secondary">
                                                Baris

                                                <?= e(
                                                    $rowCriterion[
                                                        'code'
                                                    ]
                                                ) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>

                                <tfoot class="table-light">
                                    <tr>
                                        <th scope="row">
                                            Jumlah Kolom
                                        </th>

                                        <?php foreach (
                                            $criteria
                                            as $criterion
                                        ): ?>
                                            <?php

                                            $criterionId =
                                                (int) $criterion[
                                                    'id'
                                                ];

                                            ?>

                                            <th>
                                                <?= e(
                                                    $displayNumber(
                                                        $columnTotals[
                                                            $criterionId
                                                        ]
                                                        ?? null,
                                                        4
                                                    )
                                                ) ?>
                                            </th>
                                        <?php endforeach; ?>

                                        <th>
                                            —
                                        </th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Bobot Prioritas -->
            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="weight-title">
                <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div>
                        <h2 id="weight-title" class="h5 mb-1">
                            Bobot Prioritas Kriteria
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Bobot hasil AHP yang digunakan
                            pada perhitungan TOPSIS.
                        </p>
                    </div>

                    <span class="badge text-bg-light border">
                        Total

                        <?= e(
                            $displayNumber(
                                $totalWeight,
                                4
                            )
                        ) ?>
                    </span>
                </div>

                <div class="card-body p-0">
                    <?php if (
                        $weightRows === []
                    ): ?>
                        <div class="text-center text-body-secondary p-5">
                            Bobot AHP belum tersedia.
                        </div>
                    <?php else: ?>
                        <?php

                        $sortedWeightRows =
                            $weightRows;

                        usort(
                            $sortedWeightRows,
                            static function (
                                array $first,
                                array $second
                            ): int {
                                return (float) (
                                    $second['weight']
                                    ?? 0
                                )
                                    <=>
                                    (float) (
                                        $first['weight']
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
                                            Urutan
                                        </th>

                                        <th scope="col">
                                            Kriteria
                                        </th>

                                        <th scope="col" class="text-end">
                                            Bobot
                                        </th>

                                        <th scope="col" class="text-end">
                                            Persentase
                                        </th>

                                        <th scope="col">
                                            Keterangan
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach (
                                        $sortedWeightRows
                                        as $index => $weightRow
                                    ): ?>
                                        <?php

                                        $weight =
                                            is_numeric(
                                                $weightRow['weight']
                                                ?? null
                                            )
                                                ? (float) $weightRow[
                                                    'weight'
                                                ]
                                                : 0.0;

                                        ?>

                                        <tr>
                                            <td class="text-center">
                                                <?= e(
                                                    $index + 1
                                                ) ?>
                                            </td>

                                            <td>
                                                <strong>
                                                    <?= e(
                                                        $weightRow[
                                                            'code'
                                                        ]
                                                    ) ?>
                                                </strong>

                                                —

                                                <?= e(
                                                    $weightRow[
                                                        'name'
                                                    ]
                                                ) ?>
                                            </td>

                                            <td class="text-end">
                                                <strong>
                                                    <?= e(
                                                        $displayNumber(
                                                            $weight,
                                                            6
                                                        )
                                                    ) ?>
                                                </strong>
                                            </td>

                                            <td class="text-end">
                                                <?= e(
                                                    $displayNumber(
                                                        $weight * 100,
                                                        2
                                                    )
                                                ) ?>%
                                            </td>

                                            <td>
                                                <?= e(
                                                    $weightRow[
                                                        'description'
                                                    ]
                                                    ?? '-'
                                                ) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>

                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="2">
                                            Total Bobot
                                        </th>

                                        <th class="text-end">
                                            <?= e(
                                                $displayNumber(
                                                    $totalWeight,
                                                    6
                                                )
                                            ) ?>
                                        </th>

                                        <th class="text-end">
                                            <?= e(
                                                $displayNumber(
                                                    $totalWeight * 100,
                                                    2
                                                )
                                            ) ?>%
                                        </th>

                                        <th>
                                            <?= $totalWeightValid
                                                ? 'Valid'
                                                : 'Perlu diperiksa' ?>
                                        </th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Uji Konsistensi -->
            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="consistency-title">
                <div class="card-header bg-white py-3">
                    <h2 id="consistency-title" class="h5 mb-1">
                        Hasil Uji Konsistensi
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Matriks dinyatakan konsisten
                        apabila Rasio Konsistensi tidak
                        melebihi 0,10.
                    </p>
                </div>

                <div class="card-body p-4">
                    <?php if (
                        $ahpSummary === null
                    ): ?>
                        <div class="text-center text-body-secondary py-4">
                            Ringkasan konsistensi AHP
                            belum tersedia.
                        </div>
                    <?php else: ?>
                        <div class="row g-4">
                            <div class="col-sm-6 col-xl-2">
                                <small class="text-body-secondary d-block">
                                    Lambda Maksimum
                                </small>

                                <strong>
                                    <?= e(
                                        $displayNumber(
                                            $ahpSummary[
                                                'lambda_max'
                                            ],
                                            6
                                        )
                                    ) ?>
                                </strong>
                            </div>

                            <div class="col-sm-6 col-xl-2">
                                <small class="text-body-secondary d-block">
                                    Indeks Konsistensi
                                </small>

                                <strong>
                                    <?= e(
                                        $displayNumber(
                                            $ahpSummary[
                                                'consistency_index'
                                            ],
                                            6
                                        )
                                    ) ?>
                                </strong>
                            </div>

                            <div class="col-sm-6 col-xl-2">
                                <small class="text-body-secondary d-block">
                                    Indeks Acak
                                </small>

                                <strong>
                                    <?= e(
                                        $displayNumber(
                                            $ahpSummary[
                                                'random_index'
                                            ],
                                            2
                                        )
                                    ) ?>
                                </strong>
                            </div>

                            <div class="col-sm-6 col-xl-2">
                                <small class="text-body-secondary d-block">
                                    Rasio Konsistensi
                                </small>

                                <strong>
                                    <?= e(
                                        $displayNumber(
                                            $consistencyRatio,
                                            6
                                        )
                                    ) ?>
                                </strong>
                            </div>

                            <div class="col-sm-6 col-xl-2">
                                <small class="text-body-secondary d-block">
                                    Batas Konsistensi
                                </small>

                                <strong>
                                    ≤

                                    <?= e(
                                        $displayNumber(
                                            $consistencyLimit,
                                            2
                                        )
                                    ) ?>
                                </strong>
                            </div>

                            <div class="col-sm-6 col-xl-2">
                                <small class="text-body-secondary d-block">
                                    Status
                                </small>

                                <span class="badge text-bg-<?= $ahpIsConsistent ? 'success' : 'warning' ?>">
                                    <?= $ahpIsConsistent
                                        ? 'Konsisten'
                                        : 'Belum Konsisten' ?>
                                </span>
                            </div>

                            <div class="col-12">
                                <small class="text-body-secondary d-block">
                                    Waktu Perhitungan
                                </small>

                                <strong>
                                    <?= e(
                                        format_date_id(
                                            $ahpSummary[
                                                'calculated_at'
                                            ],
                                            true
                                        )
                                    ) ?>
                                </strong>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Keterangan -->
            <section class="card border-0 shadow-sm mb-4 report-section" aria-labelledby="ahp-notes-title">
                <div class="card-header bg-white py-3">
                    <h2 id="ahp-notes-title" class="h5 mb-0">
                        Keterangan
                    </h2>
                </div>

                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h3 class="h6">
                                Skala Perbandingan
                            </h3>

                            <dl class="row mb-0 small">
                                <dt class="col-2">9</dt><dd class="col-10">Mutlak penting.</dd>
                                <dt class="col-2">7</dt><dd class="col-10">Sangat penting.</dd>
                                <dt class="col-2">5</dt><dd class="col-10">Lebih penting.</dd>
                                <dt class="col-2">3</dt><dd class="col-10">Cukup penting.</dd>
                                <dt class="col-2">1</dt><dd class="col-10">Kedua kriteria sama penting.</dd>
                                <dt class="col-2">Arah</dt><dd class="col-10 mb-0">Pilih kriteria yang dinilai lebih penting.</dd>
                            </dl>
                        </div>

                        <div class="col-md-6">
                            <h3 class="h6">
                                Ketentuan Penelitian
                            </h3>

                            <p class="small mb-2">
                                Pembobotan dilakukan oleh satu
                                responden ahli, yaitu Supervisor
                                Maintenance.
                            </p>

                            <p class="small mb-2">
                                Bobot dihitung dengan normalisasi setiap kolom
                                matriks, lalu rata-rata setiap baris digunakan
                                sebagai bobot kriteria.
                            </p>

                            <p class="small mb-2">
                                Sistem tidak menggunakan
                                rata-rata geometrik
                                antarpengambil keputusan.
                            </p>

                            <p class="small mb-0">
                                Bobot hanya dapat digunakan
                                dalam TOPSIS apabila CR ≤ 0,10.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <?php if (
                $topsisResultCount > 0
            ): ?>
                <div class="alert alert-light border mb-4 no-print" role="status">
                    <?= $topsisComplete
                        ? 'Hasil TOPSIS A1 sampai A6 pada periode ini sudah lengkap.'
                        : 'Sebagian hasil TOPSIS sudah tersedia, tetapi belum mencakup seluruh A1 sampai A6.' ?>

                    Laporan AHP tetap dapat dilihat, tetapi
                    perubahan bobot harus dilakukan melalui
                    alur AHP sesuai pembatasan sistem.
                </div>
            <?php endif; ?>

            <!-- Pengesahan Cetak -->
            <section class="d-none d-print-block mt-5" aria-label="Pengesahan laporan">
                <div class="row text-center">
                    <div class="col-6">
                        <p class="mb-5">
                            Dicetak oleh,
                            <br>

                            <?= e(
                                $currentRoleLabel
                            ) ?>
                        </p>

                        <p class="mb-0">
                            <strong>
                                <?= e(
                                    $currentUserName
                                ) ?>
                            </strong>
                        </p>
                    </div>

                    <div class="col-6">
                        <p class="mb-5">
                            Responden Ahli,
                            <br>
                            Supervisor Maintenance
                        </p>

                        <p class="mb-0">
                            <strong>
                                <?= e(
                                    $assessorName
                                ) ?>
                            </strong>
                        </p>
                    </div>
                </div>
            </section>

            <div class="alert alert-light border mb-0 no-print" role="status">
                <strong>Catatan:</strong>

                laporan ini menampilkan data AHP yang
                tersimpan pada periode terpilih. Perubahan
                penilaian dilakukan melalui modul
                Pembobotan AHP, bukan melalui halaman
                laporan.
            </div>
        <?php endif; ?>
    </main>
</div>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

<?php

declare(strict_types=1);

/*
 * Detail Hasil TOPSIS
 * SPK Pemeliharaan Motor Mesin Mixer PT CISAS
 * Hak akses:
 * - Administrator
 * - Supervisor Maintenance
 * Fungsi:
 * - Menampilkan detail satu alternatif hasil TOPSIS.
 * - Menampilkan nilai awal C1-C5.
 * - Menampilkan normalisasi.
 * - Menampilkan bobot AHP.
 * - Menampilkan matriks normalisasi terbobot.
 * - Menampilkan solusi ideal positif dan negatif.
 * - Menampilkan kontribusi jarak.
 * - Menampilkan D+, D-, nilai preferensi, dan peringkat.
 * Tidak ada proses INSERT / UPDATE pada halaman ini.
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'helpers'
    . DIRECTORY_SEPARATOR
    . 'auth.php';

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'services'
    . DIRECTORY_SEPARATOR
    . 'TopsisService.php';

// Hak Akses

auth_require_role([
    'admin',
    'supervisor',
]);

// Database

$pdo = require dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'config'
    . DIRECTORY_SEPARATOR
    . 'database.php';

$topsisService = new TopsisService(
    $pdo
);

// Pastikan Akun Masih Aktif

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun pengguna sudah tidak aktif atau tidak ditemukan.'
    );

    redirect('login.php');
}

// Validasi ID Periode

$periodId = filter_var(
    $_GET['period_id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if ($periodId === false) {
    $notFoundMessage =
        'ID periode TOPSIS tidak valid.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '404.php';

    exit;
}

$periodId = (int) $periodId;

// Ambil Data Periode

$periodStatement = $pdo->prepare(
    "SELECT
        id,
        name,
        start_date,
        end_date,
        status

     FROM assessment_periods

     WHERE id = :id

     LIMIT 1"
);

$periodStatement->execute([
    'id' => $periodId,
]);

$periodData =
    $periodStatement->fetch();

$period = is_array($periodData)
    ? $periodData
    : null;

if ($period === null) {
    $notFoundMessage =
        'Periode pemeriksaan tidak ditemukan.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '404.php';

    exit;
}

/*
 * Ambil Seluruh Hasil TOPSIS Periode
 * Data ini sekaligus digunakan untuk:
 * - daftar alternatif;
 * - pembentukan ulang matriks keputusan;
 * - normalisasi;
 * - solusi ideal.
 */

$resultStatement = $pdo->prepare(
    "SELECT
        tr.assessment_id,
        tr.positive_distance,
        tr.negative_distance,
        tr.preference_value,
        tr.ranking,
        tr.rank_group,
        tr.priority_label,
        tr.calculated_at,

        a.machine_id,
        a.inspection_date,
        a.inspection_time,
        a.status AS assessment_status,

        m.alternative_code,
        m.machine_code,
        m.motor_name,
        m.motor_function,

        s.c1_value,
        s.c2_value,
        s.c3_value,
        s.c4_value,
        s.c5_value

     FROM topsis_results AS tr

     INNER JOIN assessments AS a
        ON a.id = tr.assessment_id

     INNER JOIN machines AS m
        ON m.id = a.machine_id

     INNER JOIN assessment_scores AS s
        ON s.assessment_id = a.id

     WHERE tr.period_id = :period_id

     ORDER BY
        tr.ranking ASC,
        tr.preference_value DESC,
        m.alternative_code COLLATE NOCASE"
);

$resultStatement->execute([
    'period_id' => $periodId,
]);

$resultRows =
    $resultStatement->fetchAll();

// Pastikan TOPSIS Diproses TOPSIS

if ($resultRows === []) {
    flash_warning(
        'Hasil TOPSIS pada periode ini belum tersedia.'
    );

    redirect(
        'topsis/index.php?period_id='
        . $periodId
    );
}

/*
 * Tentukan Alternatif yang Dipilih
 * Jika assessment_id tidak dikirim melalui URL,
 * tampilkan alternatif peringkat pertama.
 */

$assessmentIdInput =
    $_GET['assessment_id']
    ?? null;

if (
    $assessmentIdInput !== null
    && $assessmentIdInput !== ''
) {
    $selectedAssessmentId = filter_var(
        $assessmentIdInput,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if ($selectedAssessmentId === false) {
        $notFoundMessage =
            'ID pemeriksaan TOPSIS tidak valid.';

        require dirname(__DIR__)
            . DIRECTORY_SEPARATOR
            . '404.php';

        exit;
    }

    $selectedAssessmentId =
        (int) $selectedAssessmentId;
} else {
    $selectedAssessmentId =
        (int) (
            $resultRows[0][
                'assessment_id'
            ] ?? 0
        );
}

// Cari Alternatif Terpilih

$selectedResult = null;

$resultByAssessmentId = [];

foreach (
    $resultRows
    as $row
) {
    $assessmentId =
        (int) (
            $row[
                'assessment_id'
            ] ?? 0
        );

    $resultByAssessmentId[
        $assessmentId
    ] = $row;

    if (
        $assessmentId
        === $selectedAssessmentId
    ) {
        $selectedResult =
            $row;
    }
}

if ($selectedResult === null) {
    $notFoundMessage =
        'Hasil TOPSIS alternatif yang dipilih tidak ditemukan.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . '404.php';

    exit;
}

// Kode Kriteria

$criterionCodes = [
    'C1',
    'C2',
    'C3',
    'C4',
    'C5',
];

/*
 * Jenis Kriteria TOPSIS
 * Seluruh C1 sampai C5 merupakan benefit terhadap urgensi pemeliharaan.
 */

$criterionTypes = [
    'C1' => 'benefit',
    'C2' => 'benefit',
    'C3' => 'benefit',
    'C4' => 'benefit',
    'C5' => 'benefit',
];

// Ambil Bobot AHP

$weightStatement = $pdo->prepare(
    "SELECT
        c.code,
        c.name,
        c.description,
        ar.weight

     FROM ahp_results AS ar

     INNER JOIN criteria AS c
        ON c.id = ar.criterion_id

     WHERE ar.period_id = :period_id
       AND c.is_active = 1

     ORDER BY
        c.display_order ASC,
        c.id ASC"
);

$weightStatement->execute([
    'period_id' => $periodId,
]);

$weightRows =
    $weightStatement->fetchAll();

$weights = [];

$criteriaInformation = [];

foreach (
    $weightRows
    as $weightRow
) {
    $code = strtoupper(
        trim(
            (string) (
                $weightRow['code']
                ?? ''
            )
        )
    );

    if ($code === '') {
        continue;
    }

    $weights[$code] = floatval(
        $weightRow['weight']
        ?? 0
    );

    $criteriaInformation[$code] = [
        'name' => (string) (
            $weightRow['name']
            ?? ''
        ),

        'description' => (string) (
            $weightRow['description']
            ?? ''
        ),
    ];
}

// Pastikan Bobot C1 sampai C5 Lengkap

foreach (
    $criterionCodes
    as $criterionCode
) {
    if (
        !array_key_exists(
            $criterionCode,
            $weights
        )
        || !is_finite(
            floatval(
                $weights[$criterionCode]
            )
        )
        || floatval(
            $weights[$criterionCode]
        ) <= 0
    ) {
        flash_error(
            'Bobot '
            . $criterionCode
            . ' belum tersedia atau tidak valid.'
        );

        redirect(
            'topsis/hasil.php?period_id='
            . $periodId
        );
    }
}

/*
 * Hitung Ulang untuk Tampilan melalui TopsisService
 * Halaman detail tidak mempunyai rumus TOPSIS sendiri.
 * Seluruh matriks dihitung kembali oleh service yang sama dengan
 * halaman proses dan penyimpanan hasil.
 */

try {
    $calculation = $topsisService->calculate(
        $resultRows,
        $weights,
        $criterionTypes
    );
} catch (Throwable $exception) {
    error_log(
        'Detail TOPSIS gagal dihitung melalui service: '
        . $exception->getMessage()
    );

    flash_error(
        'Detail perhitungan TOPSIS belum dapat ditampilkan. '
        . 'Silakan periksa hasil TOPSIS periode ini.'
    );

    redirect(
        'topsis/hasil.php?period_id='
        . $periodId
    );
}

// Gunakan Bobot yang Sudah Dinormalisasi Service

$weights = $calculation['weights'];

$divisors = $calculation['divisors'];

$positiveIdeal =
    $calculation['positive_ideal'];

$negativeIdeal =
    $calculation['negative_ideal'];

// Susun Matriks Berdasarkan assessment_id

$decisionMatrix = [];

foreach (
    $resultRows
    as $row
) {
    $rowAssessmentId = (int) (
        $row['assessment_id']
        ?? 0
    );

    if ($rowAssessmentId < 1) {
        continue;
    }

    $decisionMatrix[$rowAssessmentId] = [];

    foreach (
        $criterionCodes
        as $criterionCode
    ) {
        $fieldName = strtolower(
            $criterionCode
        ) . '_value';

        $decisionMatrix[$rowAssessmentId][
            $criterionCode
        ] = floatval(
            $row[$fieldName]
            ?? 0
        );
    }
}

$normalizedMatrix = [];

foreach (
    $calculation['normalized_matrix']
    as $row
) {
    $rowAssessmentId = (int) (
        $row['assessment_id']
        ?? 0
    );

    if ($rowAssessmentId > 0) {
        $normalizedMatrix[$rowAssessmentId] =
            $row['values']
            ?? [];
    }
}

$weightedMatrix = [];

foreach (
    $calculation['weighted_matrix']
    as $row
) {
    $rowAssessmentId = (int) (
        $row['assessment_id']
        ?? 0
    );

    if ($rowAssessmentId > 0) {
        $weightedMatrix[$rowAssessmentId] =
            $row['values']
            ?? [];
    }
}

$calculatedResultsByAssessmentId = [];

foreach (
    $calculation['results']
    as $result
) {
    $rowAssessmentId = (int) (
        $result['assessment_id']
        ?? 0
    );

    if ($rowAssessmentId > 0) {
        $calculatedResultsByAssessmentId[
            $rowAssessmentId
        ] = $result;
    }
}

if (
    !isset(
        $decisionMatrix[$selectedAssessmentId],
        $normalizedMatrix[$selectedAssessmentId],
        $weightedMatrix[$selectedAssessmentId],
        $calculatedResultsByAssessmentId[
            $selectedAssessmentId
        ]
    )
) {
    flash_error(
        'Data detail alternatif terpilih tidak lengkap.'
    );

    redirect(
        'topsis/hasil.php?period_id='
        . $periodId
    );
}

/*
 * Detail Kontribusi Setiap Kriteria
 * Nilai normalisasi, nilai terbobot, dan solusi ideal berasal dari service.
 * Halaman hanya membentuk rincian kuadrat selisih untuk kebutuhan tampilan.
 */

$selectedCalculationRows = [];

$positiveDistanceSum = 0.0;

$negativeDistanceSum = 0.0;

foreach (
    $criterionCodes
    as $criterionCode
) {
    $originalValue = floatval(
        $decisionMatrix[
            $selectedAssessmentId
        ][$criterionCode]
    );

    $normalizedValue = floatval(
        $normalizedMatrix[
            $selectedAssessmentId
        ][$criterionCode]
    );

    $weight = floatval(
        $weights[$criterionCode]
    );

    $weightedValue = floatval(
        $weightedMatrix[
            $selectedAssessmentId
        ][$criterionCode]
    );

    $positive = floatval(
        $positiveIdeal[$criterionCode]
    );

    $negative = floatval(
        $negativeIdeal[$criterionCode]
    );

    $positiveDifference =
        $weightedValue - $positive;

    $negativeDifference =
        $weightedValue - $negative;

    $positiveSquare =
        $positiveDifference
        * $positiveDifference;

    $negativeSquare =
        $negativeDifference
        * $negativeDifference;

    $positiveDistanceSum +=
        $positiveSquare;

    $negativeDistanceSum +=
        $negativeSquare;

    $selectedCalculationRows[] = [
        'code' => $criterionCode,

        'name' =>
            $criteriaInformation[
                $criterionCode
            ]['name']
            ?? $criterionCode,

        'original' => $originalValue,

        'divisor' =>
            $divisors[$criterionCode],

        'normalized' =>
            $normalizedValue,

        'weight' => $weight,

        'weighted' =>
            $weightedValue,

        'positive_ideal' =>
            $positive,

        'negative_ideal' =>
            $negative,

        'positive_square' =>
            $positiveSquare,

        'negative_square' =>
            $negativeSquare,
    ];
}

// Nilai Hasil Perhitungan Service

$selectedCalculatedResult =
    $calculatedResultsByAssessmentId[
        $selectedAssessmentId
    ];

$calculatedPositiveDistance = floatval(
    $selectedCalculatedResult[
        'positive_distance'
    ]
    ?? sqrt($positiveDistanceSum)
);

$calculatedNegativeDistance = floatval(
    $selectedCalculatedResult[
        'negative_distance'
    ]
    ?? sqrt($negativeDistanceSum)
);

$calculatedPreference = floatval(
    $selectedCalculatedResult[
        'preference_value'
    ]
    ?? 0
);

// Data Hasil Tersimpan

$storedPositiveDistance =
    floatval(
        $selectedResult[
            'positive_distance'
        ] ?? 0
    );

$storedNegativeDistance =
    floatval(
        $selectedResult[
            'negative_distance'
        ] ?? 0
    );

$storedPreference =
    floatval(
        $selectedResult[
            'preference_value'
        ] ?? 0
    );

$storedRanking =
    (int) (
        $selectedResult[
            'ranking'
        ] ?? 0
    );

$storedRankGroup =
    (int) (
        $selectedResult[
            'rank_group'
        ] ?? 0
    );

$priorityLabel =
    trim(
        (string) (
            $selectedResult[
                'priority_label'
            ]
            ?? ''
        )
    );

if ($priorityLabel === '') {
    $priorityLabel =
        'Prioritas '
        . (
            $storedRankGroup > 0
                ? $storedRankGroup
                : $storedRanking
        );
}

// Label Fungsi Motor

$motorFunctionLabel = match (
    (string) (
        $selectedResult[
            'motor_function'
        ] ?? ''
    )
) {
    'scraper' =>
        'Scraper',

    'homogenizer' =>
        'Homogenizer',

    default =>
        'Tidak diketahui',
};

// Helper Angka

$displayNumber =
    static function (
        mixed $value,
        int $decimals = 6
    ): string {
        if (
            $value === null
            || $value === ''
        ) {
            return '-';
        }

        $number =
            floatval(
                $value
            );

        if (!is_finite($number)) {
            return '-';
        }

        return format_number_id(
            $number,
            $decimals
        );
    };

// Waktu Perhitungan

$calculatedAt =
    $selectedResult[
        'calculated_at'
    ] ?? null;

// Konfigurasi Halaman

$pageTitle =
    'Detail TOPSIS '
    . (
        $selectedResult[
            'alternative_code'
        ] ?? ''
    );

$pageDescription =
    'Detail perhitungan TOPSIS untuk alternatif '
    . (
        $selectedResult[
            'alternative_code'
        ] ?? ''
    )
    . '.';

$bodyClass =
    'd-flex flex-column min-vh-100';

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

        <!-- Judul -->
        <section class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3 mb-4">

            <div>

                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">

                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary text-white fw-bold" style="width: 54px; height: 54px;">
                        <?= e(
                            $selectedResult[
                                'alternative_code'
                            ]
                        ) ?>
                    </span>

                    <span class="badge text-bg-primary p-2">
                        Peringkat
                        <?= e(
                            $storedRanking
                        ) ?>

                        &middot;

                        Kelompok
                        <?= e(
                            $storedRankGroup
                        ) ?>
                    </span>

                </div>

                <h1 class="page-title">
                    Detail Hasil TOPSIS
                </h1>

                <p class="page-description">
                    <?= e(
                        $selectedResult[
                            'motor_name'
                        ]
                    ) ?>

                    &middot;

                    <?= e(
                        $period[
                            'name'
                        ]
                    ) ?>
                </p>

            </div>

            <div class="page-header-actions">

                <a href="<?= e( base_url( 'topsis/hasil.php?period_id=' . $periodId ) ) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>

                <a href="<?= e( base_url( 'pemeriksaan/detail.php?id=' . $selectedAssessmentId ) ) ?>" class="btn btn-outline-primary">
                    Lihat Pemeriksaan
                </a>

            </div>

        </section>

        <!-- Pilih alternatif -->
        <section class="card border-0 shadow-sm mb-4">

            <div class="card-body p-4">

                <form method="get" action="<?= e( base_url( 'topsis/detail.php' ) ) ?>" class="row g-3 align-items-end">

                    <input type="hidden" name="period_id" value="<?= e( $periodId ) ?>">

                    <div class="col-md-8">

                        <label for="assessment_id" class="form-label">
                            Pilih Alternatif
                        </label>

                        <select id="assessment_id" name="assessment_id" class="form-select" required>

                            <?php foreach (
                                $resultRows
                                as $resultRow
                            ): ?>

                                <option value="<?= e( $resultRow[ 'assessment_id' ] ) ?>" <?= selected( (string) $selectedAssessmentId, (string) $resultRow[ 'assessment_id' ] ) ?>>
                                    Peringkat
                                    <?= e(
                                        $resultRow[
                                            'ranking'
                                        ]
                                    ) ?>

                                    / Kelompok
                                    <?= e(
                                        $resultRow[
                                            'rank_group'
                                        ] ?? '-'
                                    ) ?>

                                    —

                                    <?= e(
                                        $resultRow[
                                            'alternative_code'
                                        ]
                                    ) ?>

                                    —

                                    <?= e(
                                        $resultRow[
                                            'motor_name'
                                        ]
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-md-4">

                        <div class="d-grid">

                            <button type="submit" class="btn btn-primary">
                                Tampilkan Detail
                            </button>

                        </div>

                    </div>

                </form>

            </div>

        </section>

        <!-- Ringkasan hasil -->
        <section class="row g-3 mb-4">

            <div class="col-6 col-lg-3">

                <article class="card border-0 shadow-sm h-100">

                    <div class="card-body">

                        <p class="small text-body-secondary mb-2">
                            Peringkat
                        </p>

                        <p class="display-6 fw-semibold mb-1">
                            <?= e(
                                $storedRanking
                            ) ?>
                        </p>

                        <small class="text-body-secondary">
                            Dari 6 alternatif
                        </small>

                    </div>

                </article>

            </div>

            <div class="col-6 col-lg-3">

                <article class="card border-0 shadow-sm h-100">

                    <div class="card-body">

                        <p class="small text-body-secondary mb-2">
                            Nilai Preferensi
                        </p>

                        <p class="h3 fw-semibold mb-1">
                            <?= e(
                                $displayNumber(
                                    $storedPreference,
                                    6
                                )
                            ) ?>
                        </p>

                        <small class="text-body-secondary">
                            Semakin besar semakin prioritas
                        </small>

                    </div>

                </article>

            </div>

            <div class="col-6 col-lg-3">

                <article class="card border-0 shadow-sm h-100">

                    <div class="card-body">

                        <p class="small text-body-secondary mb-2">
                            Jarak D+
                        </p>

                        <p class="h3 fw-semibold mb-1">
                            <?= e(
                                $displayNumber(
                                    $storedPositiveDistance,
                                    6
                                )
                            ) ?>
                        </p>

                        <small class="text-body-secondary">
                            Jarak terhadap ideal positif
                        </small>

                    </div>

                </article>

            </div>

            <div class="col-6 col-lg-3">

                <article class="card border-0 shadow-sm h-100">

                    <div class="card-body">

                        <p class="small text-body-secondary mb-2">
                            Jarak D−
                        </p>

                        <p class="h3 fw-semibold mb-1">
                            <?= e(
                                $displayNumber(
                                    $storedNegativeDistance,
                                    6
                                )
                            ) ?>
                        </p>

                        <small class="text-body-secondary">
                            Jarak terhadap ideal negatif
                        </small>

                    </div>

                </article>

            </div>

        </section>

        <!-- Identitas alternatif -->
        <section class="card border-0 shadow-sm mb-4">

            <div class="card-header bg-white py-3">

                <h2 class="h5 mb-1">
                    Identitas Alternatif
                </h2>

            </div>

            <div class="card-body p-4">

                <div class="row g-4">

                    <div class="col-md-6">

                        <dl class="row mb-0">

                            <dt class="col-5">
                                Alternatif
                            </dt>

                            <dd class="col-7">
                                <?= e(
                                    $selectedResult[
                                        'alternative_code'
                                    ]
                                ) ?>
                            </dd>

                            <dt class="col-5">
                                Motor
                            </dt>

                            <dd class="col-7">
                                <?= e(
                                    $selectedResult[
                                        'motor_name'
                                    ]
                                ) ?>
                            </dd>

                            <dt class="col-5">
                                Kode Mesin
                            </dt>

                            <dd class="col-7 mb-0">
                                <?= e(
                                    $selectedResult[
                                        'machine_code'
                                    ] ?? '-'
                                ) ?>
                            </dd>

                        </dl>

                    </div>

                    <div class="col-md-6">

                        <dl class="row mb-0">

                            <dt class="col-5">
                                Fungsi
                            </dt>

                            <dd class="col-7">
                                <?= e(
                                    $motorFunctionLabel
                                ) ?>
                            </dd>

                            <dt class="col-5">
                                Status
                            </dt>

                            <dd class="col-7">
                                <span class="badge text-bg-primary">
                                    <?= e(
                                        $priorityLabel
                                    ) ?>
                                </span>
                            </dd>

                            <dt class="col-5">
                                Tanggal Pemeriksaan
                            </dt>

                            <dd class="col-7 mb-0">
                                <?= e(
                                    format_date_id(
                                        $selectedResult[
                                            'inspection_date'
                                        ] ?? null
                                    )
                                ) ?>
                            </dd>

                        </dl>

                    </div>

                </div>

            </div>

        </section>

        <!-- Detail per kriteria -->
        <section class="card border-0 shadow-sm mb-4">

            <div class="card-header bg-white py-3">

                <h2 class="h5 mb-1">
                    Detail Perhitungan per Kriteria
                </h2>

                <p class="small text-body-secondary mb-0">
                    Nilai awal sampai kontribusi
                    terhadap jarak solusi ideal.
                </p>

            </div>

            <div class="card-body p-0">

                <div class="table-responsive">

                    <table class="table table-hover align-middle mb-0">

                        <thead class="table-light">

                            <tr>

                                <th>
                                    Kriteria
                                </th>

                                <th class="text-end">
                                    Nilai Awal
                                </th>

                                <th class="text-end">
                                    Pembagi
                                </th>

                                <th class="text-end">
                                    Normalisasi
                                </th>

                                <th class="text-end">
                                    Bobot
                                </th>

                                <th class="text-end">
                                    Terbobot
                                </th>

                                <th class="text-end">
                                    A+
                                </th>

                                <th class="text-end">
                                    A−
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach (
                                $selectedCalculationRows
                                as $calculationRow
                            ): ?>

                                <tr>

                                    <td>

                                        <span class="badge text-bg-primary me-2">
                                            <?= e(
                                                $calculationRow[
                                                    'code'
                                                ]
                                            ) ?>
                                        </span>

                                        <?= e(
                                            $calculationRow[
                                                'name'
                                            ]
                                        ) ?>

                                    </td>

                                    <td class="text-end">
                                        <?= e(
                                            $displayNumber(
                                                $calculationRow[
                                                    'original'
                                                ],
                                                4
                                            )
                                        ) ?>
                                    </td>

                                    <td class="text-end">
                                        <?= e(
                                            $displayNumber(
                                                $calculationRow[
                                                    'divisor'
                                                ],
                                                6
                                            )
                                        ) ?>
                                    </td>

                                    <td class="text-end">
                                        <?= e(
                                            $displayNumber(
                                                $calculationRow[
                                                    'normalized'
                                                ],
                                                6
                                            )
                                        ) ?>
                                    </td>

                                    <td class="text-end">
                                        <?= e(
                                            $displayNumber(
                                                $calculationRow[
                                                    'weight'
                                                ],
                                                6
                                            )
                                        ) ?>
                                    </td>

                                    <td class="text-end fw-semibold">
                                        <?= e(
                                            $displayNumber(
                                                $calculationRow[
                                                    'weighted'
                                                ],
                                                6
                                            )
                                        ) ?>
                                    </td>

                                    <td class="text-end">
                                        <?= e(
                                            $displayNumber(
                                                $calculationRow[
                                                    'positive_ideal'
                                                ],
                                                6
                                            )
                                        ) ?>
                                    </td>

                                    <td class="text-end">
                                        <?= e(
                                            $displayNumber(
                                                $calculationRow[
                                                    'negative_ideal'
                                                ],
                                                6
                                            )
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </section>

        <!-- Perhitungan jarak -->
        <section class="card border-0 shadow-sm mb-4">

            <div class="card-header bg-white py-3">

                <h2 class="h5 mb-1">
                    Kontribusi Jarak
                </h2>

                <p class="small text-body-secondary mb-0">
                    Selisih kuadrat setiap kriteria
                    terhadap A+ dan A−.
                </p>

            </div>

            <div class="card-body p-0">

                <div class="table-responsive">

                    <table class="table table-hover align-middle mb-0">

                        <thead class="table-light">

                            <tr>

                                <th>
                                    Kriteria
                                </th>

                                <th class="text-end">
                                    (yᵢⱼ − A+)²
                                </th>

                                <th class="text-end">
                                    (yᵢⱼ − A−)²
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach (
                                $selectedCalculationRows
                                as $calculationRow
                            ): ?>

                                <tr>

                                    <td>

                                        <strong>
                                            <?= e(
                                                $calculationRow[
                                                    'code'
                                                ]
                                            ) ?>
                                        </strong>

                                        —

                                        <?= e(
                                            $calculationRow[
                                                'name'
                                            ]
                                        ) ?>

                                    </td>

                                    <td class="text-end">
                                        <?= e(
                                            $displayNumber(
                                                $calculationRow[
                                                    'positive_square'
                                                ],
                                                8
                                            )
                                        ) ?>
                                    </td>

                                    <td class="text-end">
                                        <?= e(
                                            $displayNumber(
                                                $calculationRow[
                                                    'negative_square'
                                                ],
                                                8
                                            )
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                        <tfoot class="table-light">

                            <tr>

                                <th>
                                    Total
                                </th>

                                <th class="text-end">
                                    <?= e(
                                        $displayNumber(
                                            $positiveDistanceSum,
                                            8
                                        )
                                    ) ?>
                                </th>

                                <th class="text-end">
                                    <?= e(
                                        $displayNumber(
                                            $negativeDistanceSum,
                                            8
                                        )
                                    ) ?>
                                </th>

                            </tr>

                            <tr>

                                <th>
                                    Akar / Jarak
                                </th>

                                <th class="text-end">
                                    D+ =
                                    <?= e(
                                        $displayNumber(
                                            $calculatedPositiveDistance,
                                            6
                                        )
                                    ) ?>
                                </th>

                                <th class="text-end">
                                    D− =
                                    <?= e(
                                        $displayNumber(
                                            $calculatedNegativeDistance,
                                            6
                                        )
                                    ) ?>
                                </th>

                            </tr>

                        </tfoot>

                    </table>

                </div>

            </div>

        </section>

        <!-- Preferensi -->
        <section class="card border-0 shadow-sm mb-4">

            <div class="card-body p-4">

                <h2 class="h5 mb-3">
                    Nilai Preferensi
                </h2>

                <div class="border rounded-3 p-3 mb-3">

                    <p class="mb-2">
                        Rumus:
                    </p>

                    <p class="h5 fw-normal mb-0">
                        V =
                        D−
                        /
                        (D+ + D−)
                    </p>

                </div>

                <div class="row g-3">

                    <div class="col-md-4">

                        <div class="metric-box h-100">

                            <p class="metric-label">
                                D+
                            </p>

                            <p class="metric-value">
                                <?= e(
                                    $displayNumber(
                                        $calculatedPositiveDistance,
                                        6
                                    )
                                ) ?>
                            </p>

                        </div>

                    </div>

                    <div class="col-md-4">

                        <div class="metric-box h-100">

                            <p class="metric-label">
                                D−
                            </p>

                            <p class="metric-value">
                                <?= e(
                                    $displayNumber(
                                        $calculatedNegativeDistance,
                                        6
                                    )
                                ) ?>
                            </p>

                        </div>

                    </div>

                    <div class="col-md-4">

                        <div class="metric-box h-100">

                            <p class="metric-label">
                                V
                            </p>

                            <p class="metric-value">
                                <?= e(
                                    $displayNumber(
                                        $calculatedPreference,
                                        6
                                    )
                                ) ?>
                            </p>

                        </div>

                    </div>

                </div>

            </div>

        </section>

        <!-- Ranking semua alternatif -->
        <section class="card border-0 shadow-sm">

            <div class="card-header bg-white py-3">

                <h2 class="h5 mb-1">
                    Peringkat Keseluruhan
                </h2>

                <p class="small text-body-secondary mb-0">
                    Posisi alternatif terpilih
                    dibandingkan lima alternatif lainnya.
                </p>

            </div>

            <div class="card-body p-0">

                <div class="table-responsive">

                    <table class="table table-hover align-middle mb-0">

                        <thead class="table-light">

                            <tr>

                                <th class="text-center">
                                    Peringkat
                                </th>

                                <th class="text-center">
                                    Kelompok
                                </th>

                                <th>
                                    Alternatif
                                </th>

                                <th>
                                    Motor
                                </th>

                                <th class="text-end">
                                    Preferensi
                                </th>

                                <th>
                                    Prioritas
                                </th>

                                <th class="text-end">
                                    Aksi
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach (
                                $resultRows
                                as $resultRow
                            ): ?>

                                <?php
                                $isSelected =
                                    (int)
                                    $resultRow[
                                        'assessment_id'
                                    ]
                                    ===
                                    $selectedAssessmentId;
                                ?>

                                <tr class="<?= $isSelected ? 'table-primary' : '' ?>">

                                    <td class="text-center">

                                        <strong>
                                            <?= e(
                                                $resultRow[
                                                    'ranking'
                                                ]
                                            ) ?>
                                        </strong>

                                    </td>

                                    <td class="text-center">

                                        <?= e(
                                            $resultRow[
                                                'rank_group'
                                            ] ?? '-'
                                        ) ?>

                                    </td>

                                    <td>

                                        <strong>
                                            <?= e(
                                                $resultRow[
                                                    'alternative_code'
                                                ]
                                            ) ?>
                                        </strong>

                                    </td>

                                    <td>
                                        <?= e(
                                            $resultRow[
                                                'motor_name'
                                            ]
                                        ) ?>
                                    </td>

                                    <td class="text-end fw-semibold">
                                        <?= e(
                                            $displayNumber(
                                                $resultRow[
                                                    'preference_value'
                                                ],
                                                6
                                            )
                                        ) ?>
                                    </td>

                                    <td>

                                        <span class="badge text-bg-primary">
                                            <?= e(
                                                $resultRow[
                                                    'priority_label'
                                                ]
                                                ?? (
                                                    'Prioritas '
                                                    . (
                                                        $resultRow[
                                                            'rank_group'
                                                        ]
                                                        ?? $resultRow[
                                                            'ranking'
                                                        ]
                                                    )
                                                )
                                            ) ?>
                                        </span>

                                    </td>

                                    <td class="text-end">

                                        <?php if (
                                            $isSelected
                                        ): ?>

                                            <span class="badge text-bg-light border text-dark">
                                                Ditampilkan
                                            </span>

                                        <?php else: ?>

                                            <a href="<?= e( base_url( 'topsis/detail.php?period_id=' . $periodId . '&assessment_id=' . (int) $resultRow[ 'assessment_id' ] ) ) ?>" class="btn btn-sm btn-outline-primary">
                                                Lihat
                                            </a>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </section>

        <?php if (
            $calculatedAt !== null
        ): ?>

            <p class="small text-body-secondary mt-3 mb-0">
                TOPSIS dihitung pada:

                <?= e(
                    format_date_id(
                        $calculatedAt,
                        true
                    )
                ) ?>
            </p>

        <?php endif; ?>

        <div class="alert alert-light border mt-4 mb-0">
            Peringkat TOPSIS menunjukkan
            <strong>urutan prioritas relatif pemeliharaan</strong>
            berdasarkan lima kriteria penelitian.
            Peringkat tidak menggantikan keputusan teknis
            Supervisor Maintenance dalam pelaksanaan
            pemeliharaan aktual.
        </div>

    </main>

</div>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

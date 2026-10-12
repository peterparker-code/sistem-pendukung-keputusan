<?php

declare(strict_types=1);

/*
 * Input Perbandingan Berpasangan AHP
 * SPK Pemeliharaan Motor Mesin Mixer PT CISAS
 * File:
 * public/ahp/input.php
 * Fungsi:
 * - Hanya dapat diakses Supervisor Maintenance.
 * - Menampilkan 10 pasangan perbandingan dari 5 kriteria.
 * - Menyimpan penilaian AHP ke tabel ahp_comparisons.
 * - Menghapus hasil AHP lama apabila penilaian diperbarui.
 * Responden:
 * Supervisor Maintenance.
 * Skala:
 * 9   = Kriteria pertama mutlak penting.
 * 7   = Kriteria pertama sangat penting.
 * 5   = Kriteria pertama lebih penting.
 * 3   = Kriteria pertama cukup penting.
 * 1   = Sama penting.
 * Jika kriteria kedua dipilih, sistem menyimpan nilai kebalikannya secara otomatis.
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
    . 'helpers'
    . DIRECTORY_SEPARATOR
    . 'validation.php';

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'services'
    . DIRECTORY_SEPARATOR . 'WorkflowService.php';

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'helpers'
    . DIRECTORY_SEPARATOR . 'audit.php';

// Hak Akses

auth_require_role([
    'supervisor',
]);

// Koneksi Database

$pdo = require dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'config'
    . DIRECTORY_SEPARATOR
    . 'database.php';

// Pastikan Akun Masih Aktif

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun pengguna sudah tidak aktif atau tidak ditemukan.'
    );

    redirect('login.php');
}

// Informasi Supervisor

$supervisorId = auth_id();

if ($supervisorId === null) {
    flash_error(
        'Sesi Supervisor tidak valid. Silakan masuk kembali.'
    );

    redirect('login.php');
}

// Ambil dan Validasi ID Periode

$periodIdInput =
    $_GET['period_id']
    ?? null;

$periodId = filter_var(
    $periodIdInput,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if ($periodId === false) {
    $notFoundMessage =
        'ID periode AHP tidak valid.';

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

$period =
    is_array($periodData)
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

// AHP Hanya Dapat Diubah pada Periode Aktif

if (
    (string) (
        $period['status']
        ?? ''
    ) !== 'active'
) {
    flash_warning(
        'Pembobotan AHP hanya dapat diubah '
        . 'pada periode yang berstatus Aktif.'
    );

    redirect(
        'ahp/index.php?period_id='
        . $periodId
    );
}


$calculationReadiness = WorkflowService::calculationReadiness($pdo, $periodId);
if (!(bool) ($calculationReadiness['ready'] ?? false)) {
    flash_warning(
        'Pembobotan AHP belum dapat dimulai. '
        . 'Pastikan enam pemeriksaan A1-A6 sudah disetujui dan nilai C1-C5 lengkap.'
    );
    redirect('ahp/index.php?period_id=' . $periodId);
}

// Ambil Lima Kriteria Aktif

$criteriaStatement = $pdo->query(
    "SELECT
        id,
        code,
        name,
        description,
        display_order

     FROM criteria

     WHERE is_active = 1

     ORDER BY
        display_order ASC,
        id ASC"
);

$criteria =
    $criteriaStatement->fetchAll();

// Pastikan Tepat Lima Kriteria

if (count($criteria) !== 5) {
    flash_error(
        'Pembobotan AHP tidak dapat dilakukan karena '
        . 'jumlah kriteria aktif harus tepat lima.'
    );

    redirect(
        'ahp/index.php?period_id='
        . $periodId
    );
}

/*
 * Buat Sepuluh Pasangan Perbandingan
 * Jika terdapat 5 kriteria:
 * n(n - 1) / 2
 * 5(5 - 1) / 2 = 10 pasangan.
 */

$pairs = [];

$criteriaCount =
    count($criteria);

for (
    $firstIndex = 0;
    $firstIndex < $criteriaCount - 1;
    $firstIndex++
) {
    for (
        $secondIndex = $firstIndex + 1;
        $secondIndex < $criteriaCount;
        $secondIndex++
    ) {
        $firstCriterion =
            $criteria[$firstIndex];

        $secondCriterion =
            $criteria[$secondIndex];

        $firstId =
            (int) $firstCriterion['id'];

        $secondId =
            (int) $secondCriterion['id'];

        $pairKey =
            $firstId
            . '_'
            . $secondId;

        $pairs[] = [
            'key' =>
                $pairKey,

            'first' =>
                $firstCriterion,

            'second' =>
                $secondCriterion,
        ];
    }
}

/*
 * Skala Perbandingan
 * UI menggunakan intensitas utama Saaty: 1, 3, 5, 7, dan 9.
 * Nilai disimpan dari sudut pandang kriteria pertama; pilihan ke kriteria kedua
 * otomatis disimpan sebagai nilai kebalikan pada matriks AHP.
 */

$scaleOptions = [
    '9' => ['value' => 9.0, 'label' => '9 — {first} Mutlak penting'],
    '7' => ['value' => 7.0, 'label' => '7 — {first} Sangat penting'],
    '5' => ['value' => 5.0, 'label' => '5 — {first} Lebih penting'],
    '3' => ['value' => 3.0, 'label' => '3 — {first} Cukup penting'],
    '1' => ['value' => 1.0, 'label' => '1 — Sama penting'],
    '0.3333333333333333' => ['value' => 1 / 3, 'label' => '3 — {second} Cukup penting'],
    '0.2' => ['value' => 1 / 5, 'label' => '5 — {second} Lebih penting'],
    '0.14285714285714285' => ['value' => 1 / 7, 'label' => '7 — {second} Sangat penting'],
    '0.1111111111111111' => ['value' => 1 / 9, 'label' => '9 — {second} Mutlak penting'],
];

$scaleGuide = [
    ['value' => '1', 'label' => 'Sama penting'],
    ['value' => '3', 'label' => 'Cukup penting'],
    ['value' => '5', 'label' => 'Lebih penting'],
    ['value' => '7', 'label' => 'Sangat penting'],
    ['value' => '9', 'label' => 'Mutlak penting'],
];

// Fungsi Mencocokkan Nilai Database dengan Pilihan Skala

$scaleKeyFromValue = static function (
    mixed $value
) use (
    $scaleOptions
): ?string {
    if (
        $value === null
        || $value === ''
    ) {
        return null;
    }

    $number =
        floatval($value);

    if (!is_finite($number)) {
        return null;
    }

    foreach (
        $scaleOptions
        as $key => $configuration
    ) {
        $scaleValue =
            floatval(
                $configuration['value']
            );

        if (
            abs(
                $number
                - $scaleValue
            ) < 0.000001
        ) {
            return (string) $key;
        }
    }

    return null;
};

// Ambil Perbandingan yang Sudah Tersimpan

$comparisonStatement = $pdo->prepare(
    "SELECT
        criterion_left_id,
        criterion_right_id,
        comparison_value,
        assessor_id

     FROM ahp_comparisons

     WHERE period_id = :period_id

     ORDER BY
        criterion_left_id ASC,
        criterion_right_id ASC"
);

$comparisonStatement->execute([
    'period_id' =>
        $periodId,
]);

$existingComparisonRows =
    $comparisonStatement->fetchAll();

$existingComparisonValues = [];

foreach (
    $existingComparisonRows
    as $comparisonRow
) {
    $firstId =
        (int) (
            $comparisonRow[
                'criterion_left_id'
            ] ?? 0
        );

    $secondId =
        (int) (
            $comparisonRow[
                'criterion_right_id'
            ] ?? 0
        );

    if (
        $firstId <= 0
        || $secondId <= 0
    ) {
        continue;
    }

    $pairKey =
        $firstId
        . '_'
        . $secondId;

    $scaleKey =
        $scaleKeyFromValue(
            $comparisonRow[
                'comparison_value'
            ] ?? null
        );

    if ($scaleKey !== null) {
        $existingComparisonValues[
            $pairKey
        ] = $scaleKey;
    }
}

/*
 * Cek Apakah TOPSIS Sudah Dihitung
 * Apabila TOPSIS sudah mempunyai hasil, AHP tidak boleh diubah langsung.
 * Hal ini mencegah hasil TOPSIS menggunakan bobot AHP yang sudah berubah.
 */

$topsisStatement = $pdo->prepare(
    "SELECT COUNT(*)

     FROM topsis_results

     WHERE period_id = :period_id"
);

$topsisStatement->execute([
    'period_id' =>
        $periodId,
]);

$topsisResultCount =
    (int) $topsisStatement
        ->fetchColumn();

$topsisAlreadyProcessed =
    $topsisResultCount > 0;

// Nama Form CSRF

$csrfFormName =
    'ahp_input_'
    . $periodId;

// Proses Penyimpanan

if (is_post()) {
    // Ambil Data Form

    $postedComparisons =
        $_POST['comparisons']
        ?? [];

    if (!is_array($postedComparisons)) {
        $postedComparisons = [];
    }

    // Simpan Input Lama

    $oldComparisons = [];

    foreach (
        $postedComparisons
        as $key => $value
    ) {
        if (
            is_string($key)
            && is_scalar($value)
        ) {
            $oldComparisons[$key] =
                trim(
                    (string) $value
                );
        }
    }

    // Validasi CSRF

    if (
        !csrf_validate_request(
            $csrfFormName
        )
    ) {
        flash_error(
            'Token keamanan tidak valid atau telah kedaluwarsa.'
        );

        flash_set_old_input([
            'comparisons' =>
                $oldComparisons,
        ]);

        redirect(
            'ahp/input.php?period_id='
            . $periodId
        );
    }

    // Validasi Sepuluh Perbandingan

    $errors = [];

    $comparisonValuesToSave = [];

    foreach (
        $pairs
        as $pairNumber => $pair
    ) {
        $pairKey =
            (string) $pair['key'];

        $fieldName =
            'comparison_'
            . $pairKey;

        $rawValue =
            isset(
                $postedComparisons[
                    $pairKey
                ]
            )
            && is_scalar(
                $postedComparisons[
                    $pairKey
                ]
            )

                ? trim(
                    (string)
                    $postedComparisons[
                        $pairKey
                    ]
                )

                : '';

        if ($rawValue === '') {
            validation_add_error(
                $errors,
                $fieldName,
                'Perbandingan pasangan '
                . (
                    $pairNumber
                    + 1
                )
                . ' wajib dipilih.'
            );

            continue;
        }

        if (
            !array_key_exists(
                $rawValue,
                $scaleOptions
            )
        ) {
            validation_add_error(
                $errors,
                $fieldName,
                'Nilai perbandingan pasangan '
                . (
                    $pairNumber
                    + 1
                )
                . ' tidak valid.'
            );

            continue;
        }

        $comparisonValuesToSave[
            $pairKey
        ] = floatval(
            $scaleOptions[
                $rawValue
            ]['value']
        );
    }

    // Pastikan Sepuluh Nilai Lengkap

    if (
        count(
            $comparisonValuesToSave
        ) !== count($pairs)
    ) {
        validation_add_error(
            $errors,
            'comparisons',
            'Seluruh 10 pasangan perbandingan '
            . 'harus diisi.'
        );
    }

    // Jika Validasi Gagal

    if (
        validation_has_errors(
            $errors
        )
    ) {
        flash_set_errors(
            $errors
        );

        flash_set_old_input([
            'comparisons' =>
                $oldComparisons,
        ]);

        flash_error(
            'Perbandingan AHP belum dapat disimpan. '
            . 'Periksa kembali seluruh pasangan.'
        );

        redirect(
            'ahp/input.php?period_id='
            . $periodId
        );
    }

    // Cek apakah nilai benar-benar berubah dari data yang sudah tersimpan.
    $comparisonChanged = false;
    foreach ($pairs as $pair) {
        $pairKey = (string) $pair['key'];
        $newValue = (float) ($comparisonValuesToSave[$pairKey] ?? 0);
        $oldKey = $existingComparisonValues[$pairKey] ?? null;
        $oldValue = ($oldKey !== null && isset($scaleOptions[$oldKey]))
            ? (float) $scaleOptions[$oldKey]['value']
            : null;
        if ($oldValue === null || abs($newValue - $oldValue) > 1.0E-8) {
            $comparisonChanged = true;
            break;
        }
    }

    // Simpan ke Database

    $ownsTransaction =
        !$pdo->inTransaction();

    try {
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        /*
 * Periksa Kembali Status Periode
 * Pemeriksaan dilakukan kembali di dalam transaksi agar status
 * periode tidak berubah antara halaman dibuka dan form disimpan.
 */

        $latestPeriodStatement =
            $pdo->prepare(
                "SELECT
                    status

                 FROM assessment_periods

                 WHERE id = :id

                 LIMIT 1"
            );

        $latestPeriodStatement->execute([
            'id' =>
                $periodId,
        ]);

        $latestPeriodStatus =
            $latestPeriodStatement
                ->fetchColumn();

        if (
            $latestPeriodStatus === false
        ) {
            throw new RuntimeException(
                'Periode tidak ditemukan.'
            );
        }

        if (
            (string)
            $latestPeriodStatus
            !== 'active'
        ) {
            throw new RuntimeException(
                'Periode sudah tidak aktif.'
            );
        }

        // Hasil TOPSIS boleh sudah tersedia. Jika perbandingan benar-benar berubah,
        // hasil aktif TOPSIS akan dibersihkan agar tidak memakai bobot AHP lama.
        // Snapshot perhitungan sebelumnya tetap aman di Riwayat Perhitungan.
        $latestTopsisStatement = $pdo->prepare(
            "SELECT COUNT(*) FROM topsis_results WHERE period_id = :period_id"
        );
        $latestTopsisStatement->execute(['period_id' => $periodId]);
        $latestTopsisCount = (int) $latestTopsisStatement->fetchColumn();

        /*
 * Jika Supervisor mengubah perbandingan, hasil AHP aktif dan hasil TOPSIS aktif
 * tidak boleh tetap dianggap terbaru. Riwayat perhitungan lama tidak dihapus.
 */
        if ($comparisonChanged) {
            $deleteResultsStatement = $pdo->prepare(
                "DELETE FROM ahp_results WHERE period_id = :period_id"
            );
            $deleteResultsStatement->execute(['period_id' => $periodId]);

            $deleteSummaryStatement = $pdo->prepare(
                "DELETE FROM ahp_summaries WHERE period_id = :period_id"
            );
            $deleteSummaryStatement->execute(['period_id' => $periodId]);

            if ($latestTopsisCount > 0) {
                $deleteTopsisStatement = $pdo->prepare(
                    "DELETE FROM topsis_results WHERE period_id = :period_id"
                );
                $deleteTopsisStatement->execute(['period_id' => $periodId]);
            }
        }

        /*
 * Hapus Perbandingan Lama
 * Sistem menggunakan satu responden ahli.
 * Karena itu seluruh pasangan periode dihapus kemudian dibuat ulang
 * agar tepat sepuluh baris dan tidak ada duplikasi.
 */

        $deleteComparisonsStatement =
            $pdo->prepare(
                "DELETE FROM ahp_comparisons
                 WHERE period_id = :period_id"
            );

        $deleteComparisonsStatement->execute([
            'period_id' =>
                $periodId,
        ]);

        // Siapkan Query Insert

        $insertStatement =
            $pdo->prepare(
                "INSERT INTO ahp_comparisons
                (
                    period_id,
                    criterion_left_id,
                    criterion_right_id,
                    comparison_value,
                    assessor_id
                )
                VALUES
                (
                    :period_id,
                    :criterion_left_id,
                    :criterion_right_id,
                    :comparison_value,
                    :assessor_id
                )"
            );

        // Simpan Sepuluh Pasangan

        foreach (
            $pairs
            as $pair
        ) {
            $pairKey =
                (string)
                $pair['key'];

            $firstCriterion =
                $pair['first'];

            $secondCriterion =
                $pair['second'];

            $comparisonValue =
                $comparisonValuesToSave[
                    $pairKey
                ];

            $insertStatement->execute([
                'period_id' =>
                    $periodId,

                'criterion_left_id' =>
                    (int)
                    $firstCriterion['id'],

                'criterion_right_id' =>
                    (int)
                    $secondCriterion['id'],

                'comparison_value' =>
                    $comparisonValue,

                'assessor_id' =>
                    $supervisorId,
            ]);
        }

        // Verifikasi Jumlah Data

        $countStatement =
            $pdo->prepare(
                "SELECT COUNT(*)

                 FROM ahp_comparisons

                 WHERE period_id = :period_id"
            );

        $countStatement->execute([
            'period_id' =>
                $periodId,
        ]);

        $savedCount =
            (int)
            $countStatement
                ->fetchColumn();

        if (
            $savedCount
            !== count($pairs)
        ) {
            throw new RuntimeException(
                'Jumlah perbandingan AHP yang tersimpan tidak lengkap.'
            );
        }

        // Commit

        if ($ownsTransaction) {
            $pdo->commit();
        }

        // Catat perubahan perbandingan AHP.
        audit_log(
            $pdo,
            $supervisorId,
            'ahp_comparisons_saved',
            'assessment_period',
            $periodId,
            'Sepuluh perbandingan berpasangan AHP disimpan oleh Supervisor Maintenance.',
            null,
            ['comparison_count' => $savedCount, 'comparisons' => $comparisonValuesToSave, 'comparison_changed' => $comparisonChanged, 'previous_topsis_cleared' => $comparisonChanged && $latestTopsisCount > 0]
        );

        // Berhasil

        flash_success(
            'Sepuluh perbandingan AHP berhasil disimpan. ' . ($comparisonChanged && $latestTopsisCount > 0 ? 'Hasil TOPSIS aktif sebelumnya dibersihkan karena bobot perlu dihitung ulang. ' : '') . 'Selanjutnya hasil bobot dan konsistensi dapat dihitung.'
        );

        redirect(
            'ahp/input.php?period_id='
            . $periodId
        );
    } catch (Throwable $exception) {
        // Rollback

        if (
            $ownsTransaction
            && $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        error_log(
            'Penyimpanan AHP SPK PT CISAS gagal: '
            . $exception->getMessage()
        );

        flash_set_old_input([
            'comparisons' =>
                $oldComparisons,
        ]);

        $exceptionMessage =
            strtolower(
                $exception->getMessage()
            );

        if (
            str_contains(
                $exceptionMessage,
                'tidak aktif'
            )
        ) {
            flash_error(
                'Periode pemeriksaan sudah tidak aktif.'
            );

            redirect(
                'ahp/index.php?period_id='
                . $periodId
            );
        }

        if (
            str_contains(
                $exceptionMessage,
                'topsis'
            )
        ) {
            flash_error(
                'Perbandingan AHP tidak dapat diubah karena '
                . 'TOPSIS sudah diproses TOPSIS.'
            );

            redirect(
                'ahp/index.php?period_id='
                . $periodId
            );
        }

        flash_error(
            'Terjadi gangguan saat menyimpan '
            . 'perbandingan AHP.'
        );

        redirect(
            'ahp/input.php?period_id='
            . $periodId
        );
    }
}

// Ambil Pesan Validasi dan Old Input

$errors =
    flash_get_errors();

$oldInput =
    flash_get_old_input();

$oldComparisonValues =
    isset(
        $oldInput['comparisons']
    )
    && is_array(
        $oldInput['comparisons']
    )

        ? $oldInput['comparisons']

        : [];

/*
 * Tentukan Nilai yang Ditampilkan
 * Prioritas:
 * 1. Old input apabila sebelumnya validasi gagal.
 * 2. Nilai yang sudah tersimpan di database.
 */

$displayComparisonValues = [];

foreach (
    $pairs
    as $pair
) {
    $pairKey =
        (string)
        $pair['key'];

    if (
        isset(
            $oldComparisonValues[
                $pairKey
            ]
        )
        && is_scalar(
            $oldComparisonValues[
                $pairKey
            ]
        )
    ) {
        $oldValue =
            trim(
                (string)
                $oldComparisonValues[
                    $pairKey
                ]
            );

        if (
            array_key_exists(
                $oldValue,
                $scaleOptions
            )
        ) {
            $displayComparisonValues[
                $pairKey
            ] = $oldValue;

            continue;
        }
    }

    if (
        isset(
            $existingComparisonValues[
                $pairKey
            ]
        )
    ) {
        $displayComparisonValues[
            $pairKey
        ] =
            $existingComparisonValues[
                $pairKey
            ];
    }
}

// Hitung Kelengkapan

$savedComparisonCount =
    count(
        $existingComparisonRows
    );

$expectedComparisonCount =
    count($pairs);

$comparisonsComplete =
    $savedComparisonCount
    === $expectedComparisonCount;

// Konfigurasi Halaman

$pageTitle =
    'Input Perbandingan AHP';

$pageDescription =
    'Penilaian perbandingan berpasangan '
    . 'lima kriteria pemeliharaan motor mesin mixer.';

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
                <h1 class="page-title">
                    Input Perbandingan AHP
                </h1>

                <p class="page-description">
                    Berikan penilaian tingkat kepentingan
                    pada 10 pasangan kriteria pemeliharaan.
                </p>
            </div>

            <div class="page-header-actions">

                <a href="<?= e( base_url( 'ahp/index.php?period_id=' . $periodId ) ) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>

                <?php if (
                    $comparisonsComplete
                ): ?>

                    <a href="<?= e( base_url( 'ahp/hasil.php?period_id=' . $periodId ) ) ?>" class="btn btn-primary">
                        Lihat Hasil AHP
                    </a>

                <?php endif; ?>

            </div>

        </section>

        <!-- Periode -->
        <section class="card border-0 shadow-sm mb-4">

            <div class="card-body p-4">

                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">

                    <div>

                        <small class="text-body-secondary">
                            Periode Pemeriksaan
                        </small>

                        <h2 class="h5 mt-1 mb-1">
                            <?= e(
                                $period['name']
                            ) ?>
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            <?= e(
                                format_date_id(
                                    $period[
                                        'start_date'
                                    ] ?? null
                                )
                            ) ?>

                            sampai

                            <?= e(
                                format_date_id(
                                    $period[
                                        'end_date'
                                    ] ?? null
                                )
                            ) ?>
                        </p>

                    </div>

                    <div class="text-lg-end">

                        <span class="badge text-bg-success">
                            Periode Aktif
                        </span>

                        <p class="small text-body-secondary mt-2 mb-0">
                            Responden:
                            <strong>
                                Supervisor Maintenance
                            </strong>
                        </p>

                    </div>

                </div>

            </div>

        </section>

        <!-- Informasi -->
        <div class="alert alert-info mb-4" role="status">
            <strong class="d-block mb-1">
                Petunjuk Penilaian
            </strong>

            Bandingkan dua kriteria pada setiap baris.
            Cukup pilih kalimat yang paling sesuai; Supervisor tidak perlu
            menghitung nilai pecahan secara manual karena sistem sudah
            menyediakan nilai kebalikannya.
        </div>

        <?php if (
            $topsisAlreadyProcessed
        ): ?>

            <div class="alert alert-warning mb-4" role="alert">
                <strong class="d-block mb-1">
                    Perubahan akan memerlukan hitung ulang TOPSIS
                </strong>

                Hasil TOPSIS pada periode ini sudah tersedia. Supervisor tetap dapat
                mengoreksi perbandingan AHP, tetapi jika nilainya berubah maka hasil
                TOPSIS aktif akan dibersihkan dan harus dihitung ulang. Riwayat
                perhitungan sebelumnya tetap tersimpan.
            </div>

        <?php endif; ?>

        <!-- Skala -->
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="scale-title">

            <div class="card-header bg-white py-3">
                <h2 id="scale-title" class="h5 mb-1">
                    Skala Perbandingan
                </h2>

                <p class="small text-body-secondary mb-0">
                    Gunakan nilai 1, 3, 5, 7, atau 9 sesuai tingkat kepentingan.
                </p>
            </div>

            <div class="card-body p-0">

                <div class="table-responsive">

                    <table class="table table-hover align-middle mb-0">

                        <thead class="table-light">

                            <tr>
                                <th scope="col" style="width: 100px;">
                                    Nilai
                                </th>

                                <th scope="col">
                                    Arti
                                </th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($scaleGuide as $configuration): ?>
                                <tr>
                                    <td><strong><?= e($configuration['value']) ?></strong></td>
                                    <td><?= e($configuration['label']) ?></td>
                                </tr>
                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </section>

        <!-- Form -->
        <section class="card border-0 shadow-sm" aria-labelledby="comparison-title">

            <div class="card-header bg-white py-3">

                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">

                    <div>

                        <h2 id="comparison-title" class="h5 mb-1">
                            Perbandingan Kriteria
                        </h2>

                        <p class="small text-body-secondary mb-0">
                            Seluruh 10 pasangan wajib diisi.
                        </p>

                    </div>

                    <span class="badge <?= $comparisonsComplete ? 'text-bg-success' : 'text-bg-warning' ?>">
                        <?= e(
                            $savedComparisonCount
                        ) ?>

                        /

                        <?= e(
                            $expectedComparisonCount
                        ) ?>

                        tersimpan
                    </span>

                </div>

            </div>

            <div class="card-body p-0">

                <?php
                $generalComparisonError =
                    validation_first_error(
                        $errors,
                        'comparisons'
                    );
                ?>

                <?php if (
                    $generalComparisonError !== null
                ): ?>

                    <div class="alert alert-danger rounded-0 mb-0">
                        <?= e(
                            $generalComparisonError
                        ) ?>
                    </div>

                <?php endif; ?>

                <form method="post" action="<?= e( base_url( 'ahp/input.php?period_id=' . $periodId ) ) ?>">

                    <?= csrf_field(
                        $csrfFormName
                    ) ?>

                    <div class="table-responsive">

                        <table class="table table-hover align-middle mb-0">

                            <thead class="table-light">

                                <tr>

                                    <th scope="col" class="text-center" style="width: 70px;">
                                        No.
                                    </th>

                                    <th scope="col">
                                        Kriteria Pertama
                                    </th>

                                    <th scope="col" class="text-center" style="width: 80px;">
                                        VS
                                    </th>

                                    <th scope="col">
                                        Kriteria Kedua
                                    </th>

                                    <th scope="col" style="min-width: 330px;">
                                        Penilaian
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach (
                                    $pairs
                                    as $index => $pair
                                ): ?>

                                    <?php
                                    $pairKey =
                                        (string)
                                        $pair['key'];

                                    $first =
                                        $pair['first'];

                                    $second =
                                        $pair['second'];

                                    $fieldName =
                                        'comparison_'
                                        . $pairKey;

                                    $fieldError =
                                        validation_first_error(
                                            $errors,
                                            $fieldName
                                        );

                                    $selectedValue =
                                        $displayComparisonValues[
                                            $pairKey
                                        ] ?? '';
                                    ?>

                                    <tr>

                                        <td class="text-center">
                                            <span class="badge text-bg-light border text-dark">
                                                <?= e(
                                                    $index + 1
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>

                                            <div class="d-flex align-items-start gap-2">

                                                <span class="badge text-bg-primary mt-1">
                                                    <?= e(
                                                        $first[
                                                            'code'
                                                        ]
                                                    ) ?>
                                                </span>

                                                <div>

                                                    <strong class="d-block">
                                                        <?= e(
                                                            $first[
                                                                'name'
                                                            ]
                                                        ) ?>
                                                    </strong>

                                                    <?php if (
                                                        trim(
                                                            (string) (
                                                                $first[
                                                                    'description'
                                                                ]
                                                                ?? ''
                                                            )
                                                        ) !== ''
                                                    ): ?>

                                                        <small class="text-body-secondary">
                                                            <?= e(
                                                                $first[
                                                                    'description'
                                                                ]
                                                            ) ?>
                                                        </small>

                                                    <?php endif; ?>

                                                </div>

                                            </div>

                                        </td>

                                        <td class="text-center fw-semibold text-body-secondary">
                                            VS
                                        </td>

                                        <td>

                                            <div class="d-flex align-items-start gap-2">

                                                <span class="badge text-bg-secondary mt-1">
                                                    <?= e(
                                                        $second[
                                                            'code'
                                                        ]
                                                    ) ?>
                                                </span>

                                                <div>

                                                    <strong class="d-block">
                                                        <?= e(
                                                            $second[
                                                                'name'
                                                            ]
                                                        ) ?>
                                                    </strong>

                                                    <?php if (
                                                        trim(
                                                            (string) (
                                                                $second[
                                                                    'description'
                                                                ]
                                                                ?? ''
                                                            )
                                                        ) !== ''
                                                    ): ?>

                                                        <small class="text-body-secondary">
                                                            <?= e(
                                                                $second[
                                                                    'description'
                                                                ]
                                                            ) ?>
                                                        </small>

                                                    <?php endif; ?>

                                                </div>

                                            </div>

                                        </td>

                                        <td>

                                            <label for="<?= e( 'comparison_' . $pairKey ) ?>" class="visually-hidden">
                                                Perbandingan
                                                <?= e(
                                                    $first[
                                                        'code'
                                                    ]
                                                ) ?>
                                                dan
                                                <?= e(
                                                    $second[
                                                        'code'
                                                    ]
                                                ) ?>
                                            </label>

                                            <select id="<?= e( 'comparison_' . $pairKey ) ?>" name="<?= e( 'comparisons[' . $pairKey . ']' ) ?>" class="form-select <?= e( validation_class( $errors, $fieldName ) ) ?>" required>

                                                <option value="">
                                                    -- Pilih tingkat kepentingan --
                                                </option>

                                                <?php foreach (
                                                    $scaleOptions
                                                    as $optionKey =>
                                                        $configuration
                                                ): ?>

                                                    <option value="<?= e( $optionKey ) ?>" <?= selected( (string) $selectedValue, (string) $optionKey ) ?>>
                                                        <?= e(
                                                            str_replace(
                                                                ['{first}', '{second}'],
                                                                [
                                                                    (string) $first['code'],
                                                                    (string) $second['code'],
                                                                ],
                                                                (string) $configuration['label']
                                                            )
                                                        ) ?>
                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                            <?php if (
                                                $fieldError !== null
                                            ): ?>

                                                <div class="invalid-feedback">
                                                    <?= e(
                                                        $fieldError
                                                    ) ?>
                                                </div>

                                            <?php endif; ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                        <div class="border-top bg-body-tertiary p-3 p-md-4">

                            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">

                                <div>

                                    <strong class="d-block mb-1">
                                        Sebelum menyimpan
                                    </strong>

                                    <small class="text-body-secondary">
                                        Pastikan seluruh perbandingan
                                        telah mencerminkan penilaian
                                        Supervisor Maintenance.
                                    </small>

                                </div>

                                <div class="d-flex flex-column flex-sm-row gap-2">

                                    <button type="submit" class="btn btn-primary" data-confirm="<?= e($topsisAlreadyProcessed ? 'Hasil TOPSIS sudah tersedia. Jika nilai AHP berubah, hasil TOPSIS aktif akan dibersihkan dan perlu dihitung ulang. Simpan perubahan AHP?' : 'Simpan seluruh perbandingan AHP ini?') ?>" data-loading-text="Menyimpan...">
                                        Simpan Perbandingan
                                    </button>

                                </div>

                            </div>

                        </div>

                </form>

            </div>

        </section>

        <!-- Catatan -->
        <div class="alert alert-light border mt-4 mb-0">
            Setelah 10 nilai disimpan, sistem akan
            menggunakan matriks perbandingan tersebut
            untuk memperoleh bobot C1–C5 dan menguji
            konsistensinya. Bobot hanya dapat diteruskan
            ke TOPSIS apabila
            <strong>
                Rasio Konsistensi (CR) ≤ 0,10
            </strong>.
        </div>

    </main>

</div>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

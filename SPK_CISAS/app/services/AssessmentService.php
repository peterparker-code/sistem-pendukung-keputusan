<?php

declare(strict_types=1);

/*
 * AssessmentService SPK PT CISAS
 * Service ini menangani seluruh perhitungan hasil pemeriksaan motor:
 * C1 = rata-rata suhu permukaan motor;
 * C2 = persentase arus aktual terhadap arus nominal;
 * C3 = persentase deviasi RPM aktual terhadap RPM acuan operasional;
 * C4 = rata-rata skor enam indikator kondisi mekanikal;
 * C5 = rata-rata skor enam indikator kondisi elektrikal.
 * Selain menghitung nilai, service ini juga:
 * 1. memperbarui nilai turunan pada tabel measurements;
 * 2. menyimpan nilai C1 sampai C5 pada assessment_scores;
 * 3. menjaga proses penyimpanan dalam transaksi;
 * 4. memastikan data pengukuran dan checklist telah lengkap.
 */

final class AssessmentService
{
    // Pemetaan Kondisi Checklist ke Skor

    private const CONDITION_SCORES = [
        'normal' => 1,
        'warning' => 2,
        'danger' => 3,
        'not_applicable' => null,
        'not_checked' => null,
    ];

    // Kriteria Checklist

    private const CHECKLIST_CODES = [
        'C4',
        'C5',
    ];

    // Jumlah Indikator per Kriteria Checklist

    private const CHECKLIST_ITEM_COUNT = 6;

    // Jumlah Seluruh Checklist C4 dan C5

    private const TOTAL_CHECKLIST_ITEM_COUNT = 12;

    // Presisi Hasil Perhitungan

    private const CALCULATION_PRECISION = 4;

    // Constructor

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /*
 * Menghitung dan Menyimpan Seluruh Nilai Pemeriksaan
 * Method ini dipanggil setelah:
 * 1. data pengukuran C1, C2, dan C3 disimpan;
 * 2. dua belas hasil checklist disimpan;
 * 3. pemeriksaan akan dikirim kepada Supervisor.
 * Urutan proses:
 * ambil data pemeriksaan
 * → ambil pengukuran
 * → ambil checklist
 * → hitung seluruh nilai
 * → simpan nilai turunan measurements
 * → simpan assessment_scores
 */

    public function recalculateAndSave(
        int $assessmentId
    ): array {
        if ($assessmentId < 1) {
            throw new InvalidArgumentException(
                'ID pemeriksaan tidak valid.'
            );
        }

        $assessment = $this->getAssessment(
            $assessmentId
        );

        $measurement = $this->getMeasurement(
            $assessmentId
        );

        $checklistResults =
            $this->getChecklistResults(
                $assessmentId
            );

        $scores = $this->calculateAllScores(
            $measurement,
            $checklistResults
        );

        /*
 * Gunakan Transaksi
 * Jika pemanggil sudah membuka transaksi, service tidak membuka
 * transaksi baru. Dengan demikian service dapat dipakai dari edit.php
 * tanpa menimbulkan nested transaction.
 */

        $ownsTransaction =
            !$this->pdo->inTransaction();

        try {
            if ($ownsTransaction) {
                $this->pdo->beginTransaction();
            }

            $this->storeMeasurementCalculations(
                $assessmentId,
                $scores
            );

            $this->storeScores(
                $assessmentId,
                $scores
            );

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $this->pdo->inTransaction()
            ) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        return [
            'assessment' => $assessment,

            'measurement' => [
                ...$measurement,

                'temperature_average' =>
                    $scores[
                        'temperature_average'
                    ],

                'current_average' =>
                    $scores[
                        'current_average'
                    ],

                'current_percentage' =>
                    $scores[
                        'current_percentage'
                    ],

                'rpm_average' =>
                    $scores[
                        'rpm_average'
                    ],

                'rpm_reference' =>
                    $scores[
                        'rpm_reference'
                    ],

                'rpm_deviation_percentage' =>
                    $scores[
                        'rpm_deviation_percentage'
                    ],
            ],

            'scores' => $scores,
        ];
    }

    // Menghitung Semua Nilai C1 sampai C5

    public function calculateAllScores(
        array $measurement,
        array $checklistResults
    ): array {
        $measurementScores =
            $this->calculateMeasurementScores(
                $measurement
            );

        $mechanicalSummary =
            $this->calculateChecklistSummary(
                $checklistResults,
                'C4'
            );

        $electricalSummary =
            $this->calculateChecklistSummary(
                $checklistResults,
                'C5'
            );

        return [
            // Nilai Kriteria C1, C2, dan C3

            'c1_value' =>
                $measurementScores[
                    'c1_value'
                ],

            'c2_value' =>
                $measurementScores[
                    'c2_value'
                ],

            'c3_value' =>
                $measurementScores[
                    'c3_value'
                ],

            // Nilai Turunan Pengukuran

            'temperature_average' =>
                $measurementScores[
                    'temperature_average'
                ],

            'current_average' =>
                $measurementScores[
                    'current_average'
                ],

            'current_percentage' =>
                $measurementScores[
                    'current_percentage'
                ],

            'operating_frequency' =>
                $measurementScores[
                    'operating_frequency'
                ],

            'rated_frequency' =>
                $measurementScores[
                    'rated_frequency'
                ],

            'rated_rpm' =>
                $measurementScores[
                    'rated_rpm'
                ],

            'rpm_average' =>
                $measurementScores[
                    'rpm_average'
                ],

            'rpm_reference' =>
                $measurementScores[
                    'rpm_reference'
                ],

            'rpm_deviation_percentage' =>
                $measurementScores[
                    'rpm_deviation_percentage'
                ],

            // Rekap C4: Kondisi Mekanikal

            'c4_total_score' =>
                $mechanicalSummary[
                    'total_score'
                ],

            'c4_evaluated_count' =>
                $mechanicalSummary[
                    'evaluated_count'
                ],

            'c4_na_count' =>
                $mechanicalSummary[
                    'na_count'
                ],

            'c4_not_checked_count' =>
                $mechanicalSummary[
                    'not_checked_count'
                ],

            'c4_value' =>
                $mechanicalSummary[
                    'average'
                ],

            'c4_danger_count' =>
                $mechanicalSummary[
                    'danger_count'
                ],

            'c4_has_danger' =>
                $mechanicalSummary[
                    'has_danger'
                ]
                    ? 1
                    : 0,

            // Rekap C5: Kondisi Elektrikal

            'c5_total_score' =>
                $electricalSummary[
                    'total_score'
                ],

            'c5_evaluated_count' =>
                $electricalSummary[
                    'evaluated_count'
                ],

            'c5_na_count' =>
                $electricalSummary[
                    'na_count'
                ],

            'c5_not_checked_count' =>
                $electricalSummary[
                    'not_checked_count'
                ],

            'c5_value' =>
                $electricalSummary[
                    'average'
                ],

            'c5_danger_count' =>
                $electricalSummary[
                    'danger_count'
                ],

            'c5_has_danger' =>
                $electricalSummary[
                    'has_danger'
                ]
                    ? 1
                    : 0,
        ];
    }

    // Menghitung C1, C2, dan C3

    public function calculateMeasurementScores(
        array $measurement
    ): array {
        // Tiga Pengukuran Suhu

        $temperatureValues = [
            $this->requireNonNegativeNumber(
                $measurement[
                    'temperature_1'
                ] ?? null,
                'Suhu pengukuran 1'
            ),

            $this->requireNonNegativeNumber(
                $measurement[
                    'temperature_2'
                ] ?? null,
                'Suhu pengukuran 2'
            ),

            $this->requireNonNegativeNumber(
                $measurement[
                    'temperature_3'
                ] ?? null,
                'Suhu pengukuran 3'
            ),
        ];

        // Tiga Pengukuran Arus

        $currentValues = [
            $this->requireNonNegativeNumber(
                $measurement[
                    'current_1'
                ] ?? null,
                'Arus pengukuran 1'
            ),

            $this->requireNonNegativeNumber(
                $measurement[
                    'current_2'
                ] ?? null,
                'Arus pengukuran 2'
            ),

            $this->requireNonNegativeNumber(
                $measurement[
                    'current_3'
                ] ?? null,
                'Arus pengukuran 3'
            ),
        ];

        // Tiga Pengukuran RPM

        $rpmValues = [
            $this->requireNonNegativeNumber(
                $measurement[
                    'rpm_1'
                ] ?? null,
                'RPM pengukuran 1'
            ),

            $this->requireNonNegativeNumber(
                $measurement[
                    'rpm_2'
                ] ?? null,
                'RPM pengukuran 2'
            ),

            $this->requireNonNegativeNumber(
                $measurement[
                    'rpm_3'
                ] ?? null,
                'RPM pengukuran 3'
            ),
        ];

        // Data Acuan C2 dan C3

        $ratedCurrent =
            $this->requirePositiveNumber(
                $measurement[
                    'rated_current'
                ] ?? null,
                'Arus nominal'
            );

        $operatingFrequency =
            $this->requirePositiveNumber(
                $measurement[
                    'operating_frequency'
                ] ?? null,
                'Frekuensi operasi'
            );

        $ratedFrequency =
            $this->requirePositiveNumber(
                $measurement[
                    'rated_frequency'
                ] ?? null,
                'Frekuensi nominal'
            );

        $ratedRpm =
            $this->requirePositiveNumber(
                $measurement[
                    'rated_rpm'
                ] ?? null,
                'RPM nominal'
            );

        // Hitung Rata-rata

        $temperatureAverage =
            $this->calculateAverage(
                $temperatureValues
            );

        $currentAverage =
            $this->calculateAverage(
                $currentValues
            );

        $rpmAverage =
            $this->calculateAverage(
                $rpmValues
            );

        // Hitung C2

        $currentPercentage =
            $this->calculateCurrentPercentage(
                $currentAverage,
                $ratedCurrent
            );

        /*
 * Hitung RPM Acuan Operasional C3
 * RPM acuan =
 * RPM nominal × frekuensi operasi / frekuensi nominal
 * Jumlah kutub tidak digunakan dalam rumus final C3.
 */

        $rpmReference =
            $this->calculateRpmReference(
                $ratedRpm,
                $operatingFrequency,
                $ratedFrequency
            );

        $rpmDeviationPercentage =
            $this->calculateRpmDeviation(
                $rpmAverage,
                $rpmReference
            );

        return [
            'temperature_average' =>
                $temperatureAverage,

            'current_average' =>
                $currentAverage,

            'current_percentage' =>
                $currentPercentage,

            'rated_current' =>
                $ratedCurrent,

            'operating_frequency' =>
                $operatingFrequency,

            'rated_frequency' =>
                $ratedFrequency,

            'rated_rpm' =>
                $ratedRpm,

            'rpm_average' =>
                $rpmAverage,

            'rpm_reference' =>
                $rpmReference,

            'rpm_deviation_percentage' =>
                $rpmDeviationPercentage,

            // Nilai C1, C2, dan C3

            'c1_value' =>
                $temperatureAverage,

            'c2_value' =>
                $currentPercentage,

            'c3_value' =>
                $rpmDeviationPercentage,
        ];
    }

    // Menghitung Rata-rata

    public function calculateAverage(
        array $values,
        int $precision = self::CALCULATION_PRECISION
    ): float {
        if ($values === []) {
            throw new InvalidArgumentException(
                'Data pengukuran tidak boleh kosong.'
            );
        }

        if (
            $precision < 0
            || $precision > 10
        ) {
            throw new InvalidArgumentException(
                'Presisi perhitungan tidak valid.'
            );
        }

        $total = 0.0;

        foreach ($values as $value) {
            if (
                !is_int($value)
                && !is_float($value)
            ) {
                throw new InvalidArgumentException(
                    'Data pengukuran harus berupa angka.'
                );
            }

            $number = floatval(
                $value
            );

            if (!is_finite($number)) {
                throw new InvalidArgumentException(
                    'Data pengukuran mengandung '
                    . 'angka tidak valid.'
                );
            }

            $total += $number;
        }

        return round(
            $total / count($values),
            $precision
        );
    }

    /*
 * Menghitung Persentase Arus C2
 * C2 = arus rata-rata / arus nominal × 100%
 */

    public function calculateCurrentPercentage(
        float $currentAverage,
        float $ratedCurrent,
        int $precision = self::CALCULATION_PRECISION
    ): float {
        if (!is_finite($currentAverage)) {
            throw new InvalidArgumentException(
                'Arus aktual tidak valid.'
            );
        }

        if (!is_finite($ratedCurrent)) {
            throw new InvalidArgumentException(
                'Arus nominal tidak valid.'
            );
        }

        if ($currentAverage < 0) {
            throw new InvalidArgumentException(
                'Arus aktual tidak boleh negatif.'
            );
        }

        if ($ratedCurrent <= 0) {
            throw new InvalidArgumentException(
                'Arus nominal harus lebih besar '
                . 'dari nol.'
            );
        }

        return round(
            (
                $currentAverage
                / $ratedCurrent
            ) * 100,
            $precision
        );
    }

    /*
 * Menghitung RPM Acuan Operasional
 * RPM acuan =
 * RPM nominal × frekuensi operasi / frekuensi nominal
 */

    public function calculateRpmReference(
        float $ratedRpm,
        float $operatingFrequency,
        float $ratedFrequency,
        int $precision = self::CALCULATION_PRECISION
    ): float {
        if (!is_finite($ratedRpm)) {
            throw new InvalidArgumentException(
                'RPM nominal tidak valid.'
            );
        }

        if (!is_finite($operatingFrequency)) {
            throw new InvalidArgumentException(
                'Frekuensi operasi tidak valid.'
            );
        }

        if (!is_finite($ratedFrequency)) {
            throw new InvalidArgumentException(
                'Frekuensi nominal tidak valid.'
            );
        }

        if ($ratedRpm <= 0) {
            throw new InvalidArgumentException(
                'RPM nominal harus lebih besar '
                . 'dari nol.'
            );
        }

        if ($operatingFrequency <= 0) {
            throw new InvalidArgumentException(
                'Frekuensi operasi harus lebih besar '
                . 'dari nol.'
            );
        }

        if ($ratedFrequency <= 0) {
            throw new InvalidArgumentException(
                'Frekuensi nominal harus lebih besar '
                . 'dari nol.'
            );
        }

        $rpmReference =
            $ratedRpm
            * (
                $operatingFrequency
                / $ratedFrequency
            );

        if (
            !is_finite($rpmReference)
            || $rpmReference <= 0
        ) {
            throw new RuntimeException(
                'RPM acuan tidak berhasil dihitung.'
            );
        }

        return round(
            $rpmReference,
            $precision
        );
    }

    /*
 * Menghitung Deviasi RPM C3
 * C3 = |RPM aktual - RPM acuan| / RPM acuan × 100%
 */

    public function calculateRpmDeviation(
        float $rpmAverage,
        float $rpmReference,
        int $precision = self::CALCULATION_PRECISION
    ): float {
        if (!is_finite($rpmAverage)) {
            throw new InvalidArgumentException(
                'RPM aktual tidak valid.'
            );
        }

        if (!is_finite($rpmReference)) {
            throw new InvalidArgumentException(
                'RPM acuan tidak valid.'
            );
        }

        if ($rpmAverage < 0) {
            throw new InvalidArgumentException(
                'RPM aktual tidak boleh negatif.'
            );
        }

        if ($rpmReference <= 0) {
            throw new InvalidArgumentException(
                'RPM acuan harus lebih besar '
                . 'dari nol.'
            );
        }

        return round(
            (
                abs(
                    $rpmAverage
                    - $rpmReference
                )
                / $rpmReference
            ) * 100,
            $precision
        );
    }

    // Menghitung Ringkasan C4 atau C5

    public function calculateChecklistSummary(
        array $checklistResults,
        string $criterionCode
    ): array {
        $criterionCode = strtoupper(
            trim($criterionCode)
        );

        if (
            !in_array(
                $criterionCode,
                self::CHECKLIST_CODES,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Kode kriteria checklist '
                . 'tidak valid.'
            );
        }

        // Ambil Hasil Sesuai Kriteria

        $criterionResults = [];

        foreach ($checklistResults as $result) {
            if (!is_array($result)) {
                continue;
            }

            $resultCriterionCode =
                strtoupper(
                    trim(
                        (string) (
                            $result[
                                'criterion_code'
                            ] ?? ''
                        )
                    )
                );

            if (
                $resultCriterionCode
                !== $criterionCode
            ) {
                continue;
            }

            $criterionResults[] = $result;
        }

        // Setiap Kriteria Memiliki Enam Indikator

        if (
            count($criterionResults)
            !== self::CHECKLIST_ITEM_COUNT
        ) {
            throw new RuntimeException(
                $criterionCode
                . ' harus memiliki tepat '
                . self::CHECKLIST_ITEM_COUNT
                . ' hasil checklist.'
            );
        }

        // Nilai Awal Rekap

        $totalScore = 0;
        $evaluatedCount = 0;
        $naCount = 0;
        $notCheckedCount = 0;
        $dangerCount = 0;

        // Hitung Setiap Kondisi

        foreach ($criterionResults as $result) {
            $condition = trim(
                (string) (
                    $result[
                        'condition_level'
                    ] ?? ''
                )
            );

            if (
                !array_key_exists(
                    $condition,
                    self::CONDITION_SCORES
                )
            ) {
                throw new RuntimeException(
                    'Ditemukan kondisi checklist '
                    . 'yang tidak valid.'
                );
            }

            $findingNote = trim(
                (string) (
                    $result[
                        'finding_note'
                    ] ?? ''
                )
            );

            // Keterangan Wajib untuk Perlu Perhatian dan Bahaya

            if (
                in_array(
                    $condition,
                    [
                        'warning',
                        'danger',
                    ],
                    true
                )

                && $findingNote === ''
            ) {
                throw new RuntimeException(
                    'Keterangan wajib diisi untuk '
                    . 'kondisi Perlu Perhatian atau Bahaya.'
                );
            }

            // Belum diisi

            if ($condition === 'not_checked') {
                $notCheckedCount++;

                continue;
            }

            // Tidak Tersedia

            if (
                $condition
                === 'not_applicable'
            ) {
                $allowsNa = (
                    (int) (
                        $result[
                            'allows_na'
                        ] ?? 0
                    )
                ) === 1;

                if (!$allowsNa) {
                    throw new RuntimeException(
                        'Terdapat pilihan Tidak Tersedia pada '
                        . 'indikator yang tidak '
                        . 'mendukung pilihan tersebut.'
                    );
                }

                $naCount++;

                continue;
            }

            // Normal, Perlu Perhatian, atau Bahaya

            $score =
                self::CONDITION_SCORES[
                    $condition
                ];

            if (!is_int($score)) {
                throw new RuntimeException(
                    'Skor checklist tidak dapat '
                    . 'ditentukan.'
                );
            }

            $storedScore =
                $result['score']
                ?? null;

            if (
                $storedScore !== null
                && (int) $storedScore !== $score
            ) {
                throw new RuntimeException(
                    'Skor checklist tidak sesuai '
                    . 'dengan kondisi yang dipilih.'
                );
            }

            $totalScore += $score;
            $evaluatedCount++;

            if ($condition === 'danger') {
                $dangerCount++;
            }
        }

        // Bagian Kosong Menghalangi Perhitungan Final

        if ($notCheckedCount > 0) {
            throw new RuntimeException(
                $criterionCode
                . ' masih memiliki indikator '
                . 'bagian checklist yang belum diisi.'
            );
        }

        // Minimal Satu Indikator Harus Dinilai

        if ($evaluatedCount < 1) {
            throw new RuntimeException(
                $criterionCode
                . ' tidak mempunyai indikator '
                . 'yang dapat dihitung.'
            );
        }

        // Periksa Jumlah Indikator

        if (
            $evaluatedCount
            + $naCount
            + $notCheckedCount
            !== self::CHECKLIST_ITEM_COUNT
        ) {
            throw new RuntimeException(
                'Rekap '
                . $criterionCode
                . ' tidak lengkap.'
            );
        }

        // Periksa Rentang Total Skor

        if (
            $totalScore < $evaluatedCount
            || $totalScore > $evaluatedCount * 3
        ) {
            throw new RuntimeException(
                'Total skor '
                . $criterionCode
                . ' berada di luar rentang.'
            );
        }

        return [
            'total_score' =>
                $totalScore,

            'evaluated_count' =>
                $evaluatedCount,

            'na_count' =>
                $naCount,

            'not_checked_count' =>
                $notCheckedCount,

            'average' => round(
                $totalScore
                / $evaluatedCount,
                self::CALCULATION_PRECISION
            ),

            'danger_count' =>
                $dangerCount,

            'has_danger' =>
                $dangerCount > 0,
        ];
    }

    // Mengambil Data Pemeriksaan

    private function getAssessment(
        int $assessmentId
    ): array {
        $statement = $this->pdo->prepare(
            "SELECT
                a.id,
                a.period_id,
                a.machine_id,
                a.inspector_id,
                a.status,
                a.inspection_date,
                a.inspection_time,
                a.operating_status,
                a.load_condition,
                a.operating_notes,

                m.alternative_code,
                m.motor_name,
                m.motor_function,

                p.name AS period_name,
                p.status AS period_status

             FROM assessments AS a

             INNER JOIN machines AS m
                ON m.id = a.machine_id

             INNER JOIN assessment_periods AS p
                ON p.id = a.period_id

             WHERE a.id = :id

             LIMIT 1"
        );

        $statement->execute([
            'id' => $assessmentId,
        ]);

        $assessment =
            $statement->fetch();

        if (!is_array($assessment)) {
            throw new RuntimeException(
                'Data pemeriksaan tidak ditemukan.'
            );
        }

        return $assessment;
    }

    // Mengambil Data Pengukuran

    private function getMeasurement(
        int $assessmentId
    ): array {
        $statement = $this->pdo->prepare(
            "SELECT
                assessment_id,

                temperature_1,
                temperature_2,
                temperature_3,
                temperature_average,
                temperature_measurement_point,

                current_1,
                current_2,
                current_3,
                current_average,
                rated_current,
                current_percentage,

                operating_frequency,
                frequency_source,
                rated_frequency,
                rated_rpm,

                rpm_1,
                rpm_2,
                rpm_3,
                rpm_average,
                rpm_reference,
                rpm_reference_method,
                rpm_measurement_point,
                rpm_deviation_percentage

             FROM measurements

             WHERE assessment_id = :assessment_id

             LIMIT 1"
        );

        $statement->execute([
            'assessment_id' =>
                $assessmentId,
        ]);

        $measurement =
            $statement->fetch();

        if (!is_array($measurement)) {
            throw new RuntimeException(
                'Data pengukuran pemeriksaan '
                . 'belum tersedia.'
            );
        }

        return $measurement;
    }

    // Mengambil Dua Belas Hasil Checklist Aktif

    private function getChecklistResults(
        int $assessmentId
    ): array {
        $statement = $this->pdo->prepare(
            "SELECT
                cr.checklist_item_id,
                cr.condition_level,
                cr.score,
                cr.finding_note,

                ci.item_code,
                ci.item_name,
                ci.allows_na,
                ci.display_order,

                c.code AS criterion_code

             FROM checklist_results AS cr

             INNER JOIN checklist_items AS ci
                ON ci.id =
                   cr.checklist_item_id

             INNER JOIN criteria AS c
                ON c.id =
                   ci.criterion_id

             WHERE cr.assessment_id =
                   :assessment_id

               AND ci.is_active = 1
               AND c.is_active = 1
               AND c.code IN (
                   'C4',
                   'C5'
               )

             ORDER BY
                c.display_order,
                ci.display_order,
                ci.id"
        );

        $statement->execute([
            'assessment_id' =>
                $assessmentId,
        ]);

        $results =
            $statement->fetchAll();

        if (
            count($results)
            !== self::TOTAL_CHECKLIST_ITEM_COUNT
        ) {
            throw new RuntimeException(
                'Pemeriksaan harus memiliki '
                . self::TOTAL_CHECKLIST_ITEM_COUNT
                . ' hasil checklist aktif.'
            );
        }

        return $results;
    }

    // Menyimpan Nilai Turunan ke Tabel Measurements

    private function storeMeasurementCalculations(
        int $assessmentId,
        array $scores
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE measurements

             SET
                temperature_average =
                    :temperature_average,

                current_average =
                    :current_average,

                current_percentage =
                    :current_percentage,

                rpm_average =
                    :rpm_average,

                rpm_reference =
                    :rpm_reference,

                rpm_reference_method =
                    'frequency_adjusted_nameplate',

                rpm_deviation_percentage =
                    :rpm_deviation_percentage,

                updated_at =
                    datetime('now', '+7 hours')

             WHERE assessment_id =
                   :assessment_id"
        );

        $statement->execute([
            'temperature_average' =>
                $scores[
                    'temperature_average'
                ],

            'current_average' =>
                $scores[
                    'current_average'
                ],

            'current_percentage' =>
                $scores[
                    'current_percentage'
                ],

            'rpm_average' =>
                $scores[
                    'rpm_average'
                ],

            'rpm_reference' =>
                $scores[
                    'rpm_reference'
                ],

            'rpm_deviation_percentage' =>
                $scores[
                    'rpm_deviation_percentage'
                ],

            'assessment_id' =>
                $assessmentId,
        ]);

        /*
 * rowCount Dapat Nol jika Nilai Tidak Berubah
 * Karena itu keberadaan baris diperiksa kembali sebelum dinyatakan gagal.
 */

        if ($statement->rowCount() < 1) {
            $checkStatement =
                $this->pdo->prepare(
                    "SELECT COUNT(*)

                     FROM measurements

                     WHERE assessment_id =
                           :assessment_id"
                );

            $checkStatement->execute([
                'assessment_id' =>
                    $assessmentId,
            ]);

            if (
                (int) $checkStatement
                    ->fetchColumn()
                !== 1
            ) {
                throw new RuntimeException(
                    'Nilai turunan pengukuran '
                    . 'tidak berhasil disimpan.'
                );
            }
        }
    }

    // Menyimpan Nilai Akhir C1 sampai C5

    private function storeScores(
        int $assessmentId,
        array $scores
    ): void {
        $statement = $this->pdo->prepare(
            "INSERT INTO assessment_scores (
                assessment_id,

                c1_value,
                c2_value,
                c3_value,

                c4_total_score,
                c4_evaluated_count,
                c4_na_count,
                c4_not_checked_count,
                c4_value,
                c4_has_danger,

                c5_total_score,
                c5_evaluated_count,
                c5_na_count,
                c5_not_checked_count,
                c5_value,
                c5_has_danger,

                calculated_at

             ) VALUES (
                :assessment_id,

                :c1_value,
                :c2_value,
                :c3_value,

                :c4_total_score,
                :c4_evaluated_count,
                :c4_na_count,
                :c4_not_checked_count,
                :c4_value,
                :c4_has_danger,

                :c5_total_score,
                :c5_evaluated_count,
                :c5_na_count,
                :c5_not_checked_count,
                :c5_value,
                :c5_has_danger,

                datetime('now', '+7 hours')
             )

             ON CONFLICT(assessment_id)
             DO UPDATE SET

                c1_value =
                    excluded.c1_value,

                c2_value =
                    excluded.c2_value,

                c3_value =
                    excluded.c3_value,

                c4_total_score =
                    excluded.c4_total_score,

                c4_evaluated_count =
                    excluded.c4_evaluated_count,

                c4_na_count =
                    excluded.c4_na_count,

                c4_not_checked_count =
                    excluded.c4_not_checked_count,

                c4_value =
                    excluded.c4_value,

                c4_has_danger =
                    excluded.c4_has_danger,

                c5_total_score =
                    excluded.c5_total_score,

                c5_evaluated_count =
                    excluded.c5_evaluated_count,

                c5_na_count =
                    excluded.c5_na_count,

                c5_not_checked_count =
                    excluded.c5_not_checked_count,

                c5_value =
                    excluded.c5_value,

                c5_has_danger =
                    excluded.c5_has_danger,

                calculated_at =
                    datetime('now', '+7 hours')"
        );

        $statement->execute([
            'assessment_id' =>
                $assessmentId,

            'c1_value' =>
                $scores['c1_value'],

            'c2_value' =>
                $scores['c2_value'],

            'c3_value' =>
                $scores['c3_value'],

            'c4_total_score' =>
                $scores[
                    'c4_total_score'
                ],

            'c4_evaluated_count' =>
                $scores[
                    'c4_evaluated_count'
                ],

            'c4_na_count' =>
                $scores[
                    'c4_na_count'
                ],

            'c4_not_checked_count' =>
                $scores[
                    'c4_not_checked_count'
                ],

            'c4_value' =>
                $scores['c4_value'],

            'c4_has_danger' =>
                $scores[
                    'c4_has_danger'
                ],

            'c5_total_score' =>
                $scores[
                    'c5_total_score'
                ],

            'c5_evaluated_count' =>
                $scores[
                    'c5_evaluated_count'
                ],

            'c5_na_count' =>
                $scores[
                    'c5_na_count'
                ],

            'c5_not_checked_count' =>
                $scores[
                    'c5_not_checked_count'
                ],

            'c5_value' =>
                $scores['c5_value'],

            'c5_has_danger' =>
                $scores[
                    'c5_has_danger'
                ],
        ]);
    }

    // Validasi Angka Tidak Negatif

    private function requireNonNegativeNumber(
        mixed $value,
        string $label
    ): float {
        if (
            $value === null
            || $value === ''
        ) {
            throw new InvalidArgumentException(
                $label . ' wajib diisi.'
            );
        }

        if (
            !is_scalar($value)
            && !$value instanceof Stringable
        ) {
            throw new InvalidArgumentException(
                $label . ' tidak valid.'
            );
        }

        // Mendukung Koma dan Titik

        $normalized = str_replace(
            ',',
            '.',
            trim(
                (string) $value
            )
        );

        if (!is_numeric($normalized)) {
            throw new InvalidArgumentException(
                $label
                . ' harus berupa angka.'
            );
        }

        $number = floatval(
            $normalized
        );

        if (!is_finite($number)) {
            throw new InvalidArgumentException(
                $label . ' tidak valid.'
            );
        }

        if ($number < 0) {
            throw new InvalidArgumentException(
                $label
                . ' tidak boleh negatif.'
            );
        }

        return $number;
    }

    // Validasi Angka Lebih Besar dari Nol

    private function requirePositiveNumber(
        mixed $value,
        string $label
    ): float {
        $number =
            $this->requireNonNegativeNumber(
                $value,
                $label
            );

        if ($number <= 0) {
            throw new InvalidArgumentException(
                $label
                . ' harus lebih besar dari nol.'
            );
        }

        return $number;
    }
}

<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'WorkflowService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'CalculationHistoryService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'audit.php';

/**
 * Service TOPSIS
 * Mengubah nilai C1-C5 menjadi ranking prioritas pemeliharaan A1-A6.
 */
final class TopsisService
{
    private const ALTERNATIVE_COUNT = 6;
    private const CODES = ['C1', 'C2', 'C3', 'C4', 'C5'];
    private const TYPES = ['C1' => 'benefit', 'C2' => 'benefit', 'C3' => 'benefit', 'C4' => 'benefit', 'C5' => 'benefit'];
    private const CR_LIMIT = 0.10;
    private const TIE_TOLERANCE = 1.0E-10;
    private const ZERO_TOLERANCE = 1.0E-12;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Jalankan TOPSIS dari data periode lalu simpan hasilnya. */
    public function processAndSave(int $periodId, ?int $calculatedBy = null): array
    {
        $period = $this->getProcessablePeriod($periodId);
        $this->assertCalculationReadiness($periodId);
        $this->assertAhpConsistent($periodId);
        $alternatives = $this->getAlternatives($periodId);
        $weights = $this->getWeights($periodId);
        $result = $this->calculate(
            $alternatives,
            $weights,
            self::TYPES
        );
        $calculatedBy = $this->resolveCalculator($calculatedBy, $periodId);

        $ownsTransaction = !$this->pdo->inTransaction();
        try {
            if ($ownsTransaction) {
                $this->pdo->beginTransaction();
            }
            $this->replaceResults($periodId, $result['results']);
            $this->markAssessmentsProcessed($periodId);
            $this->recordCalculationRun($periodId, $calculatedBy, $alternatives, $weights, $result);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return ['period' => $period] + $result;
    }

    public function recalculateFromDatabase(int $periodId, ?int $calculatedBy = null): array
    {
        return $this->processAndSave($periodId, $calculatedBy);
    }


    /**
     * TOPSIS sederhana:
     * 1) normalisasi; 2) kalikan bobot AHP; 3) cari ideal +/-;
     * 4) hitung jarak; 5) hitung preferensi; 6) ranking.
     */
    public function calculate(array $alternatives, array $weights, ?array $criterionTypes = null): array
    {
        $alternatives = $this->normalizeAlternatives($alternatives);
        $weights = $this->normalizeWeights($weights);
        $types = $this->normalizeCriterionTypes($criterionTypes ?? self::TYPES);

        $decisionMatrix = array_map(static fn (array $row): array => [
            'assessment_id' => $row['assessment_id'],
            'alternative_code' => $row['alternative_code'],
            'motor_name' => $row['motor_name'],
            'values' => $row['values'],
        ], $alternatives);

        $divisors = $this->calculateDivisors($decisionMatrix);
        $normalized = $this->calculateNormalizedMatrix($decisionMatrix, $divisors);
        $weighted = $this->calculateWeightedMatrix($normalized, $weights);
        [$positiveIdeal, $negativeIdeal] = $this->calculateIdealSolutions($weighted, $types);
        $results = $this->rankResults($this->calculateDistances($weighted, $positiveIdeal, $negativeIdeal));

        return [
            'weights' => $weights,
            'criterion_types' => $types,
            'divisors' => $divisors,
            'normalized_matrix' => $normalized,
            'weighted_matrix' => $weighted,
            'positive_ideal' => $positiveIdeal,
            'negative_ideal' => $negativeIdeal,
            'results' => $results,
        ];
    }

    /** Pembagi normalisasi vektor: sqrt(sum x²). */
    private function calculateDivisors(array $matrix): array
    {
        $divisors = [];
        foreach (self::CODES as $code) {
            $sum = 0.0;
            foreach ($matrix as $row) {
                $value = (float) $row['values'][$code];
                $sum += $value * $value;
            }
            $divisor = sqrt($sum);
            if (!is_finite($divisor) || $divisor <= self::ZERO_TOLERANCE) {
                throw new RuntimeException('Pembagi normalisasi ' . $code . ' bernilai nol atau tidak valid.');
            }
            $divisors[$code] = $divisor;
        }
        return $divisors;
    }

    private function calculateNormalizedMatrix(array $matrix, array $divisors): array
    {
        $result = [];
        foreach ($matrix as $row) {
            $values = [];
            foreach (self::CODES as $code) {
                $values[$code] = (float) $row['values'][$code] / $divisors[$code];
            }
            $result[] = $this->copyAlternativeIdentity($row, $values);
        }
        return $result;
    }

    private function calculateWeightedMatrix(array $matrix, array $weights): array
    {
        $result = [];
        foreach ($matrix as $row) {
            $values = [];
            foreach (self::CODES as $code) {
                $values[$code] = (float) $row['values'][$code] * $weights[$code];
            }
            $result[] = $this->copyAlternativeIdentity($row, $values);
        }
        return $result;
    }

    private function copyAlternativeIdentity(array $row, array $values): array
    {
        return [
            'assessment_id' => $row['assessment_id'],
            'alternative_code' => $row['alternative_code'],
            'motor_name' => $row['motor_name'],
            'values' => $values,
        ];
    }

    /** Ideal positif = paling urgen; ideal negatif = paling rendah urgensinya. */
    private function calculateIdealSolutions(array $matrix, array $types): array
    {
        $positive = [];
        $negative = [];
        foreach (self::CODES as $code) {
            $column = array_map(static fn (array $row): float => (float) $row['values'][$code], $matrix);
            $positive[$code] = $types[$code] === 'benefit' ? max($column) : min($column);
            $negative[$code] = $types[$code] === 'benefit' ? min($column) : max($column);
        }
        return [$positive, $negative];
    }

    /** V = D- / (D+ + D-). Nilai terbesar mendapat prioritas tertinggi. */
    private function calculateDistances(array $matrix, array $positive, array $negative): array
    {
        $results = [];
        foreach ($matrix as $row) {
            $positiveSquare = 0.0;
            $negativeSquare = 0.0;
            foreach (self::CODES as $code) {
                $value = (float) $row['values'][$code];
                $positiveSquare += ($value - $positive[$code]) ** 2;
                $negativeSquare += ($value - $negative[$code]) ** 2;
            }
            $dPlus = sqrt($positiveSquare);
            $dMinus = sqrt($negativeSquare);
            $total = $dPlus + $dMinus;
            $preference = $total <= self::ZERO_TOLERANCE ? 0.5 : $dMinus / $total;
            $results[] = [
                'assessment_id' => $row['assessment_id'],
                'alternative_code' => $row['alternative_code'],
                'motor_name' => $row['motor_name'],
                'positive_distance' => round($dPlus, 10),
                'negative_distance' => round($dMinus, 10),
                'preference_value' => round($preference, 10),
            ];
        }
        return $results;
    }

    /** Ranking kompetisi: nilai seri memperoleh ranking dan rank_group yang sama. */
    private function rankResults(array $results): array
    {
        usort($results, static function (array $a, array $b): int {
            $cmp = $b['preference_value'] <=> $a['preference_value'];
            return $cmp !== 0 ? $cmp : strnatcasecmp((string) $a['alternative_code'], (string) $b['alternative_code']);
        });

        $previous = null;
        $rank = 0;
        $group = 0;
        foreach ($results as $index => &$result) {
            $value = (float) $result['preference_value'];
            if ($previous === null || abs($value - $previous) > self::TIE_TOLERANCE) {
                $rank = $index + 1;
                $group++;
                $previous = $value;
            }
            $result['ranking'] = $rank;
            $result['rank_group'] = $group;
            $result['priority_label'] = 'Prioritas ' . $group;
        }
        unset($result);
        return $results;
    }

    private function normalizeAlternatives(array $alternatives): array
    {
        if (count($alternatives) !== self::ALTERNATIVE_COUNT) {
            throw new InvalidArgumentException('TOPSIS membutuhkan tepat enam alternatif.');
        }
        $normalized = [];
        $seenIds = [];
        $seenCodes = [];

        foreach ($alternatives as $alternative) {
            if (!is_array($alternative)) {
                throw new InvalidArgumentException('Format alternatif TOPSIS tidak valid.');
            }
            $assessmentId = (int) ($alternative['assessment_id'] ?? 0);
            $code = strtoupper(trim((string) ($alternative['alternative_code'] ?? '')));
            $motorName = trim((string) ($alternative['motor_name'] ?? ''));
            if ($assessmentId < 1 || preg_match('/^A[1-6]$/', $code) !== 1 || $motorName === '') {
                throw new InvalidArgumentException('Identitas alternatif TOPSIS tidak valid.');
            }
            if (isset($seenIds[$assessmentId]) || isset($seenCodes[$code])) {
                throw new InvalidArgumentException('Alternatif TOPSIS tidak boleh duplikat.');
            }
            $seenIds[$assessmentId] = true;
            $seenCodes[$code] = true;

            $values = [];
            foreach (self::CODES as $criterionCode) {
                $raw = $alternative[strtolower($criterionCode) . '_value'] ?? null;
                $text = str_replace(',', '.', trim((string) $raw));
                if ($raw === null || $text === '' || !is_numeric($text)) {
                    throw new InvalidArgumentException('Nilai ' . $criterionCode . ' pada ' . $code . ' belum valid.');
                }
                $number = (float) $text;
                if (!is_finite($number) || $number < 0) {
                    throw new InvalidArgumentException('Nilai ' . $criterionCode . ' pada ' . $code . ' tidak valid.');
                }
                $values[$criterionCode] = $number;
            }
            $normalized[] = [
                'assessment_id' => $assessmentId,
                'alternative_code' => $code,
                'motor_name' => $motorName,
                'values' => $values,
            ];
        }

        $actualCodes = array_keys($seenCodes);
        sort($actualCodes);
        if ($actualCodes !== ['A1', 'A2', 'A3', 'A4', 'A5', 'A6']) {
            throw new InvalidArgumentException('Alternatif TOPSIS harus lengkap A1 sampai A6.');
        }
        usort($normalized, static fn (array $a, array $b): int => strnatcasecmp($a['alternative_code'], $b['alternative_code']));
        return $normalized;
    }

    /** Bobot AHP dinormalisasi kembali agar total tepat 1. */
    private function normalizeWeights(array $weights): array
    {
        $map = [];
        foreach ($weights as $key => $weight) {
            if (is_array($weight)) {
                $code = strtoupper(trim((string) ($weight['criterion_code'] ?? '')));
                $raw = $weight['weight'] ?? null;
            } else {
                $code = strtoupper(trim((string) $key));
                $raw = $weight;
            }
            if (!in_array($code, self::CODES, true) || isset($map[$code])) {
                throw new InvalidArgumentException('Kode atau duplikasi bobot TOPSIS tidak valid.');
            }
            $text = str_replace(',', '.', trim((string) $raw));
            if ($raw === null || $text === '' || !is_numeric($text) || (float) $text <= 0) {
                throw new InvalidArgumentException('Bobot ' . $code . ' harus berupa angka positif.');
            }
            $map[$code] = (float) $text;
        }
        foreach (self::CODES as $code) {
            if (!isset($map[$code])) {
                throw new InvalidArgumentException('Bobot ' . $code . ' belum tersedia.');
            }
        }
        $total = array_sum($map);
        if (!is_finite($total) || $total <= self::ZERO_TOLERANCE) {
            throw new RuntimeException('Jumlah bobot TOPSIS tidak valid.');
        }
        foreach ($map as $code => $value) {
            $map[$code] = $value / $total;
        }
        return array_replace(array_fill_keys(self::CODES, 0.0), $map);
    }

    private function normalizeCriterionTypes(array $types): array
    {
        $normalized = [];
        foreach (self::CODES as $code) {
            $type = strtolower(trim((string) ($types[$code] ?? '')));
            if (!in_array($type, ['benefit', 'cost'], true)) {
                throw new InvalidArgumentException('Jenis kriteria ' . $code . ' harus benefit atau cost.');
            }
            $normalized[$code] = $type;
        }
        return $normalized;
    }

    private function assertCalculationReadiness(int $periodId): void
    {
        $readiness = WorkflowService::calculationReadiness($this->pdo, $periodId);
        if (!(bool) ($readiness['ready'] ?? false)) {
            throw new RuntimeException((string) ($readiness['message'] ?? 'Data pemeriksaan belum siap untuk TOPSIS.'));
        }
    }

    private function resolveCalculator(?int $calculatedBy, int $periodId): int
    {
        if ($calculatedBy !== null && $calculatedBy > 0) {
            $statement = $this->pdo->prepare(
                "SELECT id FROM users WHERE id=:id AND role='supervisor' AND is_active=1 LIMIT 1"
            );
            $statement->execute(['id' => $calculatedBy]);
            if ($statement->fetchColumn() !== false) {
                return $calculatedBy;
            }
        }

        $statement = $this->pdo->prepare(
            "SELECT assessor_id FROM ahp_summaries WHERE period_id=:period_id LIMIT 1"
        );
        $statement->execute(['period_id' => $periodId]);
        $fallback = (int) ($statement->fetchColumn() ?: 0);
        if ($fallback < 1) {
            throw new RuntimeException('Supervisor pelaksana perhitungan TOPSIS tidak dapat ditentukan.');
        }
        return $fallback;
    }

    private function recordCalculationRun(
        int $periodId,
        int $calculatedBy,
        array $alternatives,
        array $weights,
        array $result
    ): void {
        $history = new CalculationHistoryService($this->pdo);
        $runId = $history->recordRun(
            $periodId,
            'topsis',
            $calculatedBy,
            'TOPSIS-vector-normalization-v1',
            [
                'result_count' => count($result['results'] ?? []),
                'top_alternative' => $result['results'][0]['alternative_code'] ?? null,
                'top_preference' => $result['results'][0]['preference_value'] ?? null,
            ],
            [
                'alternatives' => $alternatives,
                'weights' => $weights,
                'criterion_types' => self::TYPES,
            ],
            [
                'results' => $result['results'] ?? [],
                'positive_ideal' => $result['positive_ideal'] ?? [],
                'negative_ideal' => $result['negative_ideal'] ?? [],
                'divisors' => $result['divisors'] ?? [],
            ]
        );

        audit_log(
            $this->pdo,
            $calculatedBy,
            'topsis_calculated',
            'assessment_period',
            $periodId,
            'Perhitungan TOPSIS disimpan sebagai histori perhitungan #' . $runId . '.',
            null,
            [
                'calculation_run_id' => $runId,
                'top_alternative' => $result['results'][0]['alternative_code'] ?? null,
                'top_preference' => $result['results'][0]['preference_value'] ?? null,
            ]
        );
    }

    private function getProcessablePeriod(int $periodId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, start_date, end_date, status FROM assessment_periods WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $periodId]);
        $period = $stmt->fetch();
        if (!is_array($period)) {
            throw new RuntimeException('Periode pemeriksaan tidak ditemukan.');
        }
        if (!in_array((string) $period['status'], ['active', 'completed'], true)) {
            throw new RuntimeException('TOPSIS hanya dapat diproses pada periode Aktif atau Selesai.');
        }
        return $period;
    }

    private function assertAhpConsistent(int $periodId): void
    {
        $stmt = $this->pdo->prepare('SELECT consistency_ratio, is_consistent FROM ahp_summaries WHERE period_id = :period_id LIMIT 1');
        $stmt->execute(['period_id' => $periodId]);
        $summary = $stmt->fetch();
        if (!is_array($summary)) {
            throw new RuntimeException('Ringkasan konsistensi AHP belum tersedia.');
        }
        if ((int) $summary['is_consistent'] !== 1 || (float) $summary['consistency_ratio'] > self::CR_LIMIT) {
            throw new RuntimeException('TOPSIS belum dapat diproses karena AHP tidak konsisten.');
        }
    }

    private function getWeights(int $periodId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.code AS criterion_code, c.name AS criterion_name, ar.weight
             FROM ahp_results ar JOIN criteria c ON c.id = ar.criterion_id
             WHERE ar.period_id = :period_id AND c.is_active = 1 ORDER BY c.display_order'
        );
        $stmt->execute(['period_id' => $periodId]);
        $weights = $stmt->fetchAll();
        if (count($weights) !== count(self::CODES)) {
            throw new RuntimeException('Bobot AHP C1 sampai C5 belum lengkap.');
        }
        return $weights;
    }

    private function getAlternatives(int $periodId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.id AS assessment_id, m.alternative_code, m.motor_name,
                    s.c1_value, s.c2_value, s.c3_value, s.c4_value, s.c5_value,
                    s.c4_not_checked_count, s.c5_not_checked_count, a.status
             FROM assessments a
             JOIN machines m ON m.id = a.machine_id
             JOIN assessment_scores s ON s.assessment_id = a.id
             WHERE a.period_id = :period_id AND a.status IN ('validated','processed')
             ORDER BY m.alternative_code"
        );
        $stmt->execute(['period_id' => $periodId]);
        $alternatives = $stmt->fetchAll();
        if (count($alternatives) !== self::ALTERNATIVE_COUNT) {
            throw new RuntimeException('TOPSIS membutuhkan enam pemeriksaan motor yang sudah disetujui.');
        }
        foreach ($alternatives as $alternative) {
            if ((int) $alternative['c4_not_checked_count'] > 0 || (int) $alternative['c5_not_checked_count'] > 0) {
                throw new RuntimeException('TOPSIS belum dapat diproses karena checklist masih memiliki BP.');
            }
        }
        return $alternatives;
    }

    private function replaceResults(int $periodId, array $results): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM topsis_results WHERE period_id = :period_id');
        $stmt->execute(['period_id' => $periodId]);
        $insert = $this->pdo->prepare(
            "INSERT INTO topsis_results
             (period_id, assessment_id, positive_distance, negative_distance, preference_value, ranking, rank_group, priority_label, calculated_at)
             VALUES (:period_id, :assessment_id, :d_plus, :d_minus, :preference, :ranking, :rank_group, :priority_label, datetime('now', '+7 hours'))"
        );
        foreach ($results as $result) {
            $insert->execute([
                'period_id' => $periodId,
                'assessment_id' => $result['assessment_id'],
                'd_plus' => $result['positive_distance'],
                'd_minus' => $result['negative_distance'],
                'preference' => $result['preference_value'],
                'ranking' => $result['ranking'],
                'rank_group' => $result['rank_group'],
                'priority_label' => $result['priority_label'],
            ]);
        }
    }

    private function markAssessmentsProcessed(int $periodId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE assessments SET status='processed', updated_at=datetime('now', '+7 hours')
             WHERE period_id = :period_id AND status='validated'"
        );
        $stmt->execute(['period_id' => $periodId]);
    }
}

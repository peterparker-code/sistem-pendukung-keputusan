<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'WorkflowService.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'CalculationHistoryService.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'audit.php';

/**
 * Service AHP
 * Menghitung bobot dengan normalisasi kolom + rata-rata baris dan menguji CR.
 */
final class AhpService
{
    private const CRITERIA_COUNT = 5;
    private const CR_LIMIT = 0.10;
    private const RI = [1 => 0.00, 2 => 0.00, 3 => 0.58, 4 => 0.90, 5 => 1.12,
        6 => 1.24, 7 => 1.32, 8 => 1.41, 9 => 1.45, 10 => 1.49];
    private const SCALE = [
        1 / 9, 1 / 7, 1 / 5, 1 / 3,
        1.0, 3.0, 5.0, 7.0, 9.0,
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Simpan 10 perbandingan lalu hitung bobot AHP. */
    public function saveAndCalculate(int $periodId, int $assessorId, array $comparisons): array
    {
        $this->assertValidPeriod($periodId);
        $this->assertSupervisor($assessorId);
        $this->assertCalculationReadiness($periodId);
        $criteria = $this->getCriteria();
        $comparisons = $this->normalizeComparisons($comparisons, $criteria);
        $result = $this->calculate($criteria, $comparisons);

        $ownsTransaction = !$this->pdo->inTransaction();
        try {
            if ($ownsTransaction) {
                $this->pdo->beginTransaction();
            }
            $this->replaceComparisons($periodId, $assessorId, $comparisons);
            $this->replaceWeights($periodId, $result['weights']);
            $this->replaceSummary($periodId, $assessorId, $result['summary']);
            $this->recordCalculationRun($periodId, $assessorId, $criteria, $comparisons, $result);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return ['criteria' => $criteria, 'comparisons' => $comparisons] + $result;
    }

    /** Hitung ulang AHP yang sudah tersimpan tanpa mengubah 10 penilaian. */
    public function recalculateFromDatabase(int $periodId): array
    {
        $this->assertValidPeriod($periodId, false);
        $criteria = $this->getCriteria();
        $stmt = $this->pdo->prepare(
            'SELECT criterion_left_id, criterion_right_id, comparison_value, assessor_id
             FROM ahp_comparisons WHERE period_id = :period_id
             ORDER BY criterion_left_id, criterion_right_id'
        );
        $stmt->execute(['period_id' => $periodId]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            throw new RuntimeException('Data perbandingan AHP belum tersedia.');
        }

        $assessorIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['assessor_id'], $rows
        )));
        if (count($assessorIds) !== 1) {
            throw new RuntimeException('Perbandingan AHP harus berasal dari satu Supervisor Maintenance.');
        }
        $assessorId = $assessorIds[0];
        $this->assertSupervisor($assessorId);
        $this->assertCalculationReadiness($periodId);

        $comparisons = $this->normalizeComparisons($rows, $criteria);
        $result = $this->calculate($criteria, $comparisons);
        $ownsTransaction = !$this->pdo->inTransaction();
        try {
            if ($ownsTransaction) {
                $this->pdo->beginTransaction();
            }
            $this->replaceWeights($periodId, $result['weights']);
            $this->replaceSummary($periodId, $assessorId, $result['summary']);
            $this->recordCalculationRun($periodId, $assessorId, $criteria, $comparisons, $result);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return ['criteria' => $criteria, 'comparisons' => $comparisons] + $result;
    }

    /**
     * Perhitungan AHP sederhana:
     * 1) bentuk matriks; 2) jumlah kolom; 3) normalisasi;
     * 4) rata-rata baris = bobot; 5) hitung lambda, CI, dan CR.
     */
    public function calculate(array $criteria, array $comparisons): array
    {
        if (count($criteria) !== self::CRITERIA_COUNT) {
            throw new RuntimeException('AHP harus menggunakan tepat lima kriteria.');
        }

        $matrix = $this->buildMatrix($criteria, $comparisons);
        $weightsVector = $this->normalizedColumnAverage($matrix);
        $lambdaMax = $this->calculateLambdaMax($matrix, $weightsVector);
        $n = count($criteria);
        $ci = $n > 1 ? max(0.0, ($lambdaMax - $n) / ($n - 1)) : 0.0;
        $ri = self::RI[$n] ?? throw new RuntimeException('Random Index tidak tersedia.');
        $cr = $ri > 0 ? $ci / $ri : 0.0;

        $weights = [];
        foreach ($criteria as $i => $criterion) {
            $weights[] = [
                'criterion_id' => (int) $criterion['id'],
                'criterion_code' => (string) $criterion['code'],
                'criterion_name' => (string) $criterion['name'],
                'weight' => round($weightsVector[$i], 10),
            ];
        }

        return [
            'matrix' => $matrix,
            'weights' => $weights,
            'summary' => [
                'lambda_max' => round($lambdaMax, 10),
                'consistency_index' => round($ci, 10),
                'random_index' => round($ri, 10),
                'consistency_ratio' => round($cr, 10),
                'is_consistent' => $cr <= self::CR_LIMIT,
                'consistency_threshold' => self::CR_LIMIT,
            ],
        ];
    }

    /** Bentuk matriks resiprokal 5×5. */
    private function buildMatrix(array $criteria, array $comparisons): array
    {
        $indexById = [];
        foreach ($criteria as $i => $criterion) {
            $indexById[(int) $criterion['id']] = $i;
        }
        $n = count($criteria);
        $matrix = array_fill(0, $n, array_fill(0, $n, 1.0));

        foreach ($comparisons as $comparison) {
            $left = (int) $comparison['criterion_left_id'];
            $right = (int) $comparison['criterion_right_id'];
            $value = (float) $comparison['comparison_value'];
            if (!isset($indexById[$left], $indexById[$right])) {
                throw new RuntimeException('Perbandingan mengandung kriteria yang tidak tersedia.');
            }
            $i = $indexById[$left];
            $j = $indexById[$right];
            $matrix[$i][$j] = $value;
            $matrix[$j][$i] = 1.0 / $value;
        }
        return $matrix;
    }

    /** Normalisasi setiap kolom, lalu rata-ratakan setiap baris. */
    private function normalizedColumnAverage(array $matrix): array
    {
        $n = count($matrix);
        if ($n === 0) {
            throw new InvalidArgumentException('Matriks AHP tidak boleh kosong.');
        }

        $columnSums = array_fill(0, $n, 0.0);
        foreach ($matrix as $row) {
            if (count($row) !== $n) {
                throw new InvalidArgumentException('Matriks AHP harus berbentuk persegi.');
            }
            foreach ($row as $j => $value) {
                $value = (float) $value;
                if (!is_finite($value) || $value <= 0) {
                    throw new RuntimeException('Seluruh nilai matriks AHP harus positif.');
                }
                $columnSums[$j] += $value;
            }
        }

        $weights = array_fill(0, $n, 0.0);
        foreach ($matrix as $i => $row) {
            foreach ($row as $j => $value) {
                if ($columnSums[$j] <= 0) {
                    throw new RuntimeException('Jumlah kolom matriks AHP tidak valid.');
                }
                $weights[$i] += (float) $value / $columnSums[$j];
            }
            $weights[$i] /= $n;
        }

        $sum = array_sum($weights);
        if (!is_finite($sum) || $sum <= 0) {
            throw new RuntimeException('Bobot AHP tidak dapat dinormalisasi.');
        }
        return array_map(static fn (float $weight): float => $weight / $sum, $weights);
    }

    /** Lambda maksimum dihitung dari rata-rata (A×w)/w. */
    private function calculateLambdaMax(array $matrix, array $weights): float
    {
        $ratios = [];
        foreach ($matrix as $i => $row) {
            $weighted = 0.0;
            foreach ($row as $j => $value) {
                $weighted += (float) $value * $weights[$j];
            }
            if ($weights[$i] <= 0) {
                throw new RuntimeException('Bobot AHP harus lebih besar dari nol.');
            }
            $ratios[] = $weighted / $weights[$i];
        }
        return array_sum($ratios) / count($ratios);
    }

    /** Validasi 10 pasangan dengan intensitas utama Saaty 1, 3, 5, 7, 9 beserta kebalikannya. */
    private function normalizeComparisons(array $comparisons, array $criteria): array
    {
        $validIds = array_fill_keys(array_map(
            static fn (array $criterion): int => (int) $criterion['id'], $criteria
        ), true);
        $normalized = [];
        $seen = [];

        foreach ($comparisons as $comparison) {
            if (!is_array($comparison)) {
                throw new InvalidArgumentException('Format perbandingan AHP tidak valid.');
            }
            $left = (int) ($comparison['criterion_left_id'] ?? 0);
            $right = (int) ($comparison['criterion_right_id'] ?? 0);
            if (!isset($validIds[$left], $validIds[$right]) || $left === $right) {
                throw new InvalidArgumentException('Pasangan kriteria AHP tidak valid.');
            }
            $value = $this->normalizeScaleValue($comparison['comparison_value'] ?? null);

            // Database menyimpan ID kecil di kiri agar satu pasangan hanya punya satu bentuk.
            if ($left > $right) {
                [$left, $right] = [$right, $left];
                $value = $this->normalizeScaleValue(1.0 / $value);
            }
            $key = $left . ':' . $right;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Pasangan perbandingan AHP tidak boleh duplikat.');
            }
            $seen[$key] = true;
            $normalized[] = [
                'criterion_left_id' => $left,
                'criterion_right_id' => $right,
                'comparison_value' => $value,
            ];
        }

        $expected = count($criteria) * (count($criteria) - 1) / 2;
        if (count($normalized) !== $expected) {
            throw new InvalidArgumentException('Lima kriteria membutuhkan tepat 10 perbandingan berpasangan.');
        }
        usort($normalized, static fn (array $a, array $b): int =>
            [$a['criterion_left_id'], $a['criterion_right_id']] <=> [$b['criterion_left_id'], $b['criterion_right_id']]);
        return $normalized;
    }

    private function normalizeScaleValue(mixed $value): float
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException('Nilai perbandingan AHP wajib diisi.');
        }
        $text = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($text)) {
            throw new InvalidArgumentException('Nilai perbandingan AHP harus berupa angka.');
        }
        $number = (float) $text;
        foreach (self::SCALE as $allowed) {
            if (abs($number - $allowed) <= 1.0E-8) {
                return $allowed;
            }
        }
        throw new InvalidArgumentException('Gunakan skala AHP 1, 3, 5, 7, 9 atau nilai kebalikannya.');
    }

    private function getCriteria(): array
    {
        $criteria = $this->pdo->query(
            "SELECT id, code, name, display_order FROM criteria WHERE is_active = 1 ORDER BY display_order"
        )->fetchAll();
        $codes = array_map(static fn (array $criterion): string => (string) $criterion['code'], $criteria);
        if (count($criteria) !== self::CRITERIA_COUNT || $codes !== ['C1', 'C2', 'C3', 'C4', 'C5']) {
            throw new RuntimeException('Kriteria aktif harus tepat C1 sampai C5.');
        }
        return $criteria;
    }

    private function assertValidPeriod(int $periodId, bool $mustBeEditable = true): void
    {
        $stmt = $this->pdo->prepare('SELECT status FROM assessment_periods WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $periodId]);
        $status = $stmt->fetchColumn();
        if ($status === false) {
            throw new RuntimeException('Periode pemeriksaan tidak ditemukan.');
        }
        if ($mustBeEditable && !in_array((string) $status, ['draft', 'active'], true)) {
            throw new RuntimeException('AHP tidak dapat diubah karena periode sudah Selesai.');
        }
    }

    private function assertSupervisor(int $assessorId): void
    {
        $stmt = $this->pdo->prepare('SELECT role, is_active FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $assessorId]);
        $user = $stmt->fetch();
        if (!is_array($user) || (string) $user['role'] !== 'supervisor' || (int) $user['is_active'] !== 1) {
            throw new RuntimeException('Pembobotan AHP hanya boleh dilakukan Supervisor Maintenance aktif.');
        }
    }

    private function assertCalculationReadiness(int $periodId): void
    {
        $readiness = WorkflowService::calculationReadiness($this->pdo, $periodId);
        if (!(bool) ($readiness['ready'] ?? false)) {
            throw new RuntimeException((string) ($readiness['message'] ?? 'Data pemeriksaan belum siap untuk AHP.'));
        }
    }

    private function recordCalculationRun(
        int $periodId,
        int $assessorId,
        array $criteria,
        array $comparisons,
        array $result
    ): void {
        $history = new CalculationHistoryService($this->pdo);
        $runId = $history->recordRun(
            $periodId,
            'ahp',
            $assessorId,
            'AHP-normalisasi-kolom-rata-rata-baris-v1',
            $result['summary'] ?? null,
            [
                'comparisons' => $comparisons,
                'criteria' => $criteria,
            ],
            [
                'weights' => $result['weights'] ?? [],
                'matrix' => $result['matrix'] ?? [],
            ]
        );

        audit_log(
            $this->pdo,
            $assessorId,
            'ahp_calculated',
            'assessment_period',
            $periodId,
            'Perhitungan AHP disimpan sebagai histori perhitungan #' . $runId . '.',
            null,
            [
                'calculation_run_id' => $runId,
                'consistency_ratio' => $result['summary']['consistency_ratio'] ?? null,
                'is_consistent' => $result['summary']['is_consistent'] ?? null,
            ]
        );
    }

    private function replaceComparisons(int $periodId, int $assessorId, array $comparisons): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ahp_comparisons WHERE period_id = :period_id');
        $stmt->execute(['period_id' => $periodId]);
        $insert = $this->pdo->prepare(
            "INSERT INTO ahp_comparisons
             (period_id, criterion_left_id, criterion_right_id, comparison_value, assessor_id, created_at, updated_at)
             VALUES (:period_id, :left_id, :right_id, :value, :assessor_id,
                     datetime('now', '+7 hours'), datetime('now', '+7 hours'))"
        );
        foreach ($comparisons as $comparison) {
            $insert->execute([
                'period_id' => $periodId,
                'left_id' => $comparison['criterion_left_id'],
                'right_id' => $comparison['criterion_right_id'],
                'value' => $comparison['comparison_value'],
                'assessor_id' => $assessorId,
            ]);
        }
    }

    private function replaceWeights(int $periodId, array $weights): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ahp_results WHERE period_id = :period_id');
        $stmt->execute(['period_id' => $periodId]);
        $insert = $this->pdo->prepare(
            "INSERT INTO ahp_results (period_id, criterion_id, weight, calculated_at)
             VALUES (:period_id, :criterion_id, :weight, datetime('now', '+7 hours'))"
        );
        foreach ($weights as $weight) {
            $insert->execute([
                'period_id' => $periodId,
                'criterion_id' => $weight['criterion_id'],
                'weight' => $weight['weight'],
            ]);
        }
    }

    private function replaceSummary(int $periodId, int $assessorId, array $summary): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO ahp_summaries
             (period_id, assessor_id, lambda_max, consistency_index, random_index, consistency_ratio, is_consistent, calculated_at)
             VALUES (:period_id, :assessor_id, :lambda_max, :ci, :ri, :cr, :consistent, datetime('now', '+7 hours'))
             ON CONFLICT(period_id) DO UPDATE SET
                 assessor_id=excluded.assessor_id, lambda_max=excluded.lambda_max,
                 consistency_index=excluded.consistency_index, random_index=excluded.random_index,
                 consistency_ratio=excluded.consistency_ratio, is_consistent=excluded.is_consistent,
                 calculated_at=datetime('now', '+7 hours')"
        );
        $stmt->execute([
            'period_id' => $periodId,
            'assessor_id' => $assessorId,
            'lambda_max' => $summary['lambda_max'],
            'ci' => $summary['consistency_index'],
            'ri' => $summary['random_index'],
            'cr' => $summary['consistency_ratio'],
            'consistent' => $summary['is_consistent'] ? 1 : 0,
        ]);
    }
}

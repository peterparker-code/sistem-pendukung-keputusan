<?php

declare(strict_types=1);

/**
 * Menyimpan snapshot setiap perhitungan AHP/TOPSIS tanpa mengganti hasil historis.
 * Tabel hasil lama tetap dipakai sebagai current/final state agar kompatibel dengan UI yang ada.
 */
final class CalculationHistoryService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function recordRun(
        int $periodId,
        string $method,
        int $calculatedBy,
        string $methodVersion,
        ?array $summary,
        ?array $inputSnapshot,
        ?array $outputSnapshot
    ): int {
        $method = strtolower(trim($method));
        if (!in_array($method, ['ahp', 'topsis'], true)) {
            throw new InvalidArgumentException('Metode histori perhitungan tidak valid.');
        }
        if ($periodId < 1 || $calculatedBy < 1) {
            throw new InvalidArgumentException('Identitas histori perhitungan tidak valid.');
        }

        $runStatement = $this->pdo->prepare(
            'SELECT COALESCE(MAX(run_number), 0) + 1
             FROM calculation_runs
             WHERE period_id = :period_id AND method = :method'
        );
        $runStatement->execute([
            'period_id' => $periodId,
            'method' => $method,
        ]);
        $runNumber = max(1, (int) $runStatement->fetchColumn());

        $this->pdo->prepare(
            'UPDATE calculation_runs
             SET is_final = 0
             WHERE period_id = :period_id AND method = :method AND is_final = 1'
        )->execute([
            'period_id' => $periodId,
            'method' => $method,
        ]);

        $encode = static function (?array $value): ?string {
            if ($value === null) {
                return null;
            }
            $json = json_encode(
                $value,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if (!is_string($json)) {
                throw new RuntimeException('Snapshot perhitungan tidak dapat diserialisasi.');
            }
            return $json;
        };

        $insert = $this->pdo->prepare(
            "INSERT INTO calculation_runs (
                period_id, method, run_number, calculated_by, method_version, is_final,
                summary_json, input_snapshot_json, output_snapshot_json, created_at
             ) VALUES (
                :period_id, :method, :run_number, :calculated_by, :method_version, 1,
                :summary_json, :input_snapshot_json, :output_snapshot_json,
                datetime('now', '+7 hours')
             )"
        );
        $insert->execute([
            'period_id' => $periodId,
            'method' => $method,
            'run_number' => $runNumber,
            'calculated_by' => $calculatedBy,
            'method_version' => trim($methodVersion),
            'summary_json' => $encode($summary),
            'input_snapshot_json' => $encode($inputSnapshot),
            'output_snapshot_json' => $encode($outputSnapshot),
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}

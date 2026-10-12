<?php

declare(strict_types=1);

final class WorkflowService
{
    public const PERIOD_TRANSITIONS = [
        'draft' => ['draft', 'active'],
        'active' => ['active', 'completed'],
        'completed' => ['completed'],
    ];

    public const ASSESSMENT_TRANSITIONS = [
        'draft' => ['draft', 'completed'],
        'returned' => ['returned', 'draft', 'completed'],
        'completed' => ['completed', 'returned', 'validated'],
        'validated' => ['validated', 'processed'],
        'processed' => ['processed'],
    ];

    public const ACTION_TRANSITIONS = [
        'pending' => ['pending', 'in_progress', 'completed'],
        'in_progress' => ['in_progress', 'completed'],
        'completed' => ['completed'],
    ];


    /**
     * Kesiapan data sebelum AHP/TOPSIS dijalankan.
     * Enam alternatif A1-A6 harus sudah disetujui/diproses dan memiliki C1-C5 lengkap.
     */
    public static function calculationReadiness(PDO $pdo, int $periodId): array
    {
        if ($periodId < 1) {
            return [
                'ready' => false,
                'assessment_count' => 0,
                'approved_count' => 0,
                'score_complete_count' => 0,
                'alternative_count' => 0,
                'message' => 'Periode pemeriksaan tidak valid.',
            ];
        }

        $statement = $pdo->prepare(
            "SELECT
                COUNT(DISTINCT a.id) AS assessment_count,
                COUNT(DISTINCT CASE WHEN a.status IN ('validated','processed') THEN a.id END) AS approved_count,
                COUNT(DISTINCT CASE
                    WHEN a.status IN ('validated','processed')
                     AND s.assessment_id IS NOT NULL
                     AND s.c1_value IS NOT NULL
                     AND s.c2_value IS NOT NULL
                     AND s.c3_value IS NOT NULL
                     AND s.c4_value IS NOT NULL
                     AND s.c5_value IS NOT NULL
                     AND s.c4_not_checked_count = 0
                     AND s.c5_not_checked_count = 0
                    THEN a.id END) AS score_complete_count,
                COUNT(DISTINCT CASE
                    WHEN a.status IN ('validated','processed')
                     AND m.alternative_code IN ('A1','A2','A3','A4','A5','A6')
                    THEN m.alternative_code END) AS alternative_count
             FROM assessments a
             JOIN machines m ON m.id = a.machine_id
             LEFT JOIN assessment_scores s ON s.assessment_id = a.id
             WHERE a.period_id = :period_id"
        );
        $statement->execute(['period_id' => $periodId]);
        $row = $statement->fetch();

        $assessmentCount = (int) ($row['assessment_count'] ?? 0);
        $approvedCount = (int) ($row['approved_count'] ?? 0);
        $scoreCompleteCount = (int) ($row['score_complete_count'] ?? 0);
        $alternativeCount = (int) ($row['alternative_count'] ?? 0);
        $ready = $assessmentCount === 6
            && $approvedCount === 6
            && $scoreCompleteCount === 6
            && $alternativeCount === 6;

        $message = $ready
            ? 'Enam pemeriksaan A1-A6 sudah disetujui dan nilai C1-C5 lengkap.'
            : 'AHP/TOPSIS baru dapat diproses setelah enam pemeriksaan A1-A6 disetujui dan nilai C1-C5 lengkap.';

        return [
            'ready' => $ready,
            'assessment_count' => $assessmentCount,
            'approved_count' => $approvedCount,
            'score_complete_count' => $scoreCompleteCount,
            'alternative_count' => $alternativeCount,
            'message' => $message,
        ];
    }

    public static function canTransition(
        string $workflow,
        string $from,
        string $to
    ): bool {
        $map = match ($workflow) {
            'period' => self::PERIOD_TRANSITIONS,
            'assessment' => self::ASSESSMENT_TRANSITIONS,
            'action' => self::ACTION_TRANSITIONS,
            default => [],
        };

        return in_array(
            $to,
            $map[$from] ?? [],
            true
        );
    }

    public static function assertTransition(
        string $workflow,
        string $from,
        string $to
    ): void {
        if (!self::canTransition($workflow, $from, $to)) {
            throw new RuntimeException(
                'Perubahan status dari '
                . $from
                . ' menjadi '
                . $to
                . ' tidak diizinkan.'
            );
        }
    }

    public static function periodLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Draft',
            'active' => 'Aktif',
            'completed' => 'Selesai',
            default => 'Tidak Dikenal',
        };
    }

    public static function assessmentLabel(string $status): string
    {
        return match ($status) {
            'draft' => 'Draft',
            'completed' => 'Menunggu Validasi',
            'returned' => 'Dikembalikan',
            'validated' => 'Disetujui',
            'processed' => 'Diproses TOPSIS',
            default => 'Tidak Dikenal',
        };
    }

    public static function actionLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Menunggu',
            'in_progress' => 'Dikerjakan',
            'completed' => 'Selesai',
            default => 'Tidak Dikenal',
        };
    }
}

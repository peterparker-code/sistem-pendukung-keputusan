<?php

declare(strict_types=1);

/*
 * Dashboard Sistem SPK PT. CISAS
 * Dashboard menampilkan informasi sesuai peran pengguna:
 * - Administrator: kesiapan sistem dan data;
 * - Supervisor: validasi, AHP, TOPSIS, dan rekomendasi;
 * - Teknisi: pemeriksaan miliknya, laporan terbatas, dan tindakan tugasnya.
 */

require_once dirname(__DIR__)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'helpers'
    . DIRECTORY_SEPARATOR . 'auth.php';

auth_require_role([
    'admin',
    'supervisor',
    'technician',
]);

$pdo = require dirname(__DIR__)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'config'
    . DIRECTORY_SEPARATOR . 'database.php';

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun pengguna sudah tidak aktif atau tidak ditemukan.'
    );

    redirect('login.php');
}

$currentUser = auth_user() ?? [];
$currentRole = auth_role() ?? '';
$currentUserId = auth_id();
$currentUserName = auth_name() ?? 'Pengguna';
$currentRoleLabel = auth_role_label($currentRole);

$isAdmin = $currentRole === 'admin';
$isSupervisor = $currentRole === 'supervisor';
$isTechnician = $currentRole === 'technician';

if (
    $currentUserId === null
    || $currentUserId < 1
    || !in_array(
        $currentRole,
        [
            'admin',
            'supervisor',
            'technician',
        ],
        true
    )
) {
    flash_error(
        'Sesi pengguna tidak valid. Silakan masuk kembali.'
    );

    redirect('login.php');
}

$expectedMachineCount = (int) app_config(
    'total_machines',
    6
);

$expectedCriteriaCount = (int) app_config(
    'total_criteria',
    5
);

$expectedAhpComparisonCount = (int) (
    (
        $expectedCriteriaCount
        * ($expectedCriteriaCount - 1)
    ) / 2
);

$expectedAlternativeCodes = [
    'A1',
    'A2',
    'A3',
    'A4',
    'A5',
    'A6',
];

$expectedCriterionCodes = [
    'C1',
    'C2',
    'C3',
    'C4',
    'C5',
];

$machineIdentityMap = [
    'A1' => [
        'machine_code' => 'MIX-01',
        'machine_number' => 1,
        'motor_function' => 'scraper',
    ],
    'A2' => [
        'machine_code' => 'MIX-01',
        'machine_number' => 1,
        'motor_function' => 'homogenizer',
    ],
    'A3' => [
        'machine_code' => 'MIX-02',
        'machine_number' => 2,
        'motor_function' => 'scraper',
    ],
    'A4' => [
        'machine_code' => 'MIX-02',
        'machine_number' => 2,
        'motor_function' => 'homogenizer',
    ],
    'A5' => [
        'machine_code' => 'MIX-03',
        'machine_number' => 3,
        'motor_function' => 'scraper',
    ],
    'A6' => [
        'machine_code' => 'MIX-03',
        'machine_number' => 3,
        'motor_function' => 'homogenizer',
    ],
];

$allowedValidatedStatuses = [
    'validated',
    'processed',
];

$weightTolerance = 0.0001;
$preferenceTolerance = 0.000000001;

// Helper Dashboard

$fetchInt = static function (
    PDO $pdo,
    string $sql,
    array $parameters = []
): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    return (int) $statement->fetchColumn();
};

$displayNumber = static function (
    mixed $value,
    int $decimals = 4
): string {
    if (
        $value === null
        || $value === ''
        || !is_numeric((string) $value)
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

$dashboardIcon = static function (
    string $name,
    int $size = 22
): string {
    $svgOpen = '<svg width="' . $size . '" height="' . $size
        . '" viewBox="0 0 24 24" fill="none"'
        . ' xmlns="http://www.w3.org/2000/svg">';

    $svgClose = '</svg>';

    $content = match ($name) {
        'calendar' => '
            <rect x="3" y="5" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.7"/>

            <path d="M16 3V7M8 3V7M3 10H21" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
        ',

        'clipboard' => '
            <rect x="5" y="4" width="14" height="17" rx="2" stroke="currentColor" stroke-width="1.7"/>

            <path d="M9 4V3C9 2.45 9.45 2 10 2H14C14.55 2 15 2.45 15 3V4" stroke="currentColor" stroke-width="1.7"/>

            <path d="M8 10H16M8 14H16M8 18H13" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
        ',

        'check' => '
            <path d="M9 11L12 14L20 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>

            <path d="M20 12V19C20 20.1 19.1 21 18 21H5C3.9 21 3 20.1 3 19V6C3 4.9 3.9 4 5 4H15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
        ',

        'layers' => '
            <path d="M12 3L21 8L12 13L3 8L12 3Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>

            <path d="M3 12L12 17L21 12M3 16L12 21L21 16" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
        ',

        'chart' => '
            <path d="M4 19V10M10 19V5M16 19V13M22 19V2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>

            <path d="M2 19H22" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
        ',

        'wrench' => '
            <path d="M14.7 6.3C14.3 5.9 14.3 5.3 14.7 4.9L17.6 2C15.1 1.4 12.4 2.1 10.6 4C8.8 5.8 8.1 8.4 8.7 10.8L2.8 16.7C1.7 17.8 1.7 19.6 2.8 20.7C3.9 21.8 5.7 21.8 6.8 20.7L12.7 14.8C15.1 15.4 17.7 14.7 19.5 12.9C21.4 11 22.1 8.3 21.5 5.8L18.6 8.7C18.2 9.1 17.6 9.1 17.2 8.7L14.7 6.3Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
        ',

        'users' => '
            <path d="M16 21V19C16 16.8 14.2 15 12 15H6C3.8 15 2 16.8 2 19V21" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>

            <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="1.7"/>

            <path d="M17 11C18.7 11 20 9.7 20 8C20 6.3 18.7 5 17 5M22 21V19C22 17.2 20.8 15.6 19 15.1" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
        ',

        'motor' => '
            <rect x="5" y="5" width="14" height="14" rx="3" stroke="currentColor" stroke-width="1.7"/>

            <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.7"/>

            <path d="M9 2V5M15 2V5M9 19V22M15 19V22M2 9H5M2 15H5M19 9H22M19 15H22" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
        ',

        'alert' => '
            <path d="M10.3 3.9L1.8 18C1.4 18.7 1.9 19.6 2.7 19.6H21.3C22.1 19.6 22.6 18.7 22.2 18L13.7 3.9C12.9 2.6 11.1 2.6 10.3 3.9Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>

            <path d="M12 9V13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>

            <circle cx="12" cy="16.5" r="1" fill="currentColor"/>
        ',

        'clock' => '
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/>

            <path d="M12 7V12L15.5 14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        ',

        'arrow' => '
            <path d="M5 12H19" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>

            <path d="M13 6L19 12L13 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        ',

        'info' => '
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/>

            <path d="M12 11V16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>

            <circle cx="12" cy="8" r="1" fill="currentColor"/>
        ',

        default => '
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/>

            <circle cx="12" cy="12" r="2" fill="currentColor"/>
        ',
    };

    return $svgOpen
        . $content
        . $svgClose;
};

// Nilai Awal Dashboard

$dashboardError = null;
$activePeriod = null;
$periodIsActive = false;
$periodStatusLabel = 'Belum tersedia';
$ahpSummary = null;
$topPriority = null;
$topPriorityCount = 0;
$recentAssessments = [];
$assessmentStatusByCode = [];

$ahpWeightSum = 0.0;
$ahpComparisonStructureValid = false;
$ahpWeightStructureValid = false;
$ahpAssessorValid = false;
$ahpResultValidationError = null;
$topsisResultStructureValid = false;
$assessmentStructureValid = true;
$assessmentScoreStructureValid = true;

$statistics = [
    'active_machines' => 0,
    'active_users' => 0,
    'total_periods' => 0,
    'machine_data_ready' => 0,

    'assessments_started' => 0,
    'assessments_completed' => 0,
    'assessments_validated' => 0,
    'assessments_score_complete' => 0,
    'pending_validation' => 0,
    'draft_assessments' => 0,
    'returned_assessments' => 0,

    'danger_findings' => 0,
    'not_checked_findings' => 0,

    'ahp_comparisons' => 0,
    'ahp_weights' => 0,
    'topsis_results' => 0,

    'actions_total' => 0,
    'actions_active' => 0,
    'actions_completed' => 0,
    'actions_overdue' => 0,
    'actions_assigned_to_user' => 0,
];

// Ambil Data Dashboard

try {
    // Data Umum dan Kesiapan Enam Motor

    $machineStatement = $pdo->query(
        "SELECT
            id,
            alternative_code,
            machine_code,
            machine_number,
            motor_function,
            motor_name,
            rated_current,
            rated_frequency,
            rated_rpm,
            status
         FROM machines
         WHERE alternative_code IN (
             'A1',
             'A2',
             'A3',
             'A4',
             'A5',
             'A6'
         )
         ORDER BY alternative_code COLLATE NOCASE, id"
    );

    $machineRows = $machineStatement->fetchAll();
    $activeMachineCodes = [];
    $readyMachineCodes = [];
    $duplicateMachineCodeFound = false;

    foreach ($machineRows as $machineRow) {
        $alternativeCode = strtoupper(
            trim(
                (string) (
                    $machineRow['alternative_code']
                    ?? ''
                )
            )
        );

        if (
            !in_array(
                $alternativeCode,
                $expectedAlternativeCodes,
                true
            )
            || (string) (
                $machineRow['status']
                ?? ''
            ) !== 'active'
        ) {
            continue;
        }

        if (isset($activeMachineCodes[$alternativeCode])) {
            $duplicateMachineCodeFound = true;
            continue;
        }

        $activeMachineCodes[$alternativeCode] = true;

        $expectedIdentity = $machineIdentityMap[
            $alternativeCode
        ];

        $identityValid =
            (string) (
                $machineRow['machine_code']
                ?? ''
            ) === $expectedIdentity['machine_code']
            && (int) (
                $machineRow['machine_number']
                ?? 0
            ) === $expectedIdentity['machine_number']
            && (string) (
                $machineRow['motor_function']
                ?? ''
            ) === $expectedIdentity['motor_function'];

        $referenceDataValid = true;

        foreach (
            [
                'rated_current',
                'rated_frequency',
                'rated_rpm',
            ]
            as $referenceField
        ) {
            $referenceValue = $machineRow[
                $referenceField
            ] ?? null;

            if (
                $referenceValue === null
                || $referenceValue === ''
                || !is_numeric((string) $referenceValue)
                || !is_finite((float) $referenceValue)
                || (float) $referenceValue <= 0
            ) {
                $referenceDataValid = false;
                break;
            }
        }

        if (
            $identityValid
            && $referenceDataValid
            && trim(
                (string) (
                    $machineRow['motor_name']
                    ?? ''
                )
            ) !== ''
        ) {
            $readyMachineCodes[$alternativeCode] = true;
        }
    }

    $statistics['active_machines'] =
        count($activeMachineCodes);

    $statistics['machine_data_ready'] =
        $duplicateMachineCodeFound
            ? 0
            : count($readyMachineCodes);

    $statistics['active_users'] = $fetchInt(
        $pdo,
        "SELECT COUNT(*)
         FROM users
         WHERE is_active = 1
           AND role IN (
               'admin',
               'supervisor',
               'technician'
           )"
    );

    $statistics['total_periods'] = $fetchInt(
        $pdo,
        "SELECT COUNT(*)
         FROM assessment_periods"
    );

    /*
 * Periode yang Ditampilkan
 * Dashboard memprioritaskan periode Aktif. Jika tidak ada periode Aktif,
 * periode terbaru tetap ditampilkan agar progres tidak kembali kosong.
 */

    $periodStatement = $pdo->query(
        "SELECT
            id,
            name,
            start_date,
            end_date,
            status
         FROM assessment_periods
         ORDER BY
            CASE status
                WHEN 'active' THEN 1
                ELSE 2
            END,
            start_date DESC,
            id DESC
         LIMIT 1"
    );

    $periodData = $periodStatement->fetch();

    $activePeriod = is_array($periodData)
        ? $periodData
        : null;

    $periodIsActive = $activePeriod !== null
        && (string) ($activePeriod['status'] ?? '') === 'active';

    $periodStatusLabel = match (
        (string) ($activePeriod['status'] ?? '')
    ) {
        'draft' => 'Draft',
        'active' => 'Aktif',
        'completed' => 'Selesai',
        default => 'Belum tersedia',
    };

    // Data Berdasarkan Periode yang Ditampilkan

    if ($activePeriod !== null) {
        $periodId = (int) (
            $activePeriod['id']
            ?? 0
        );

        $assessmentScopeSql = $isTechnician
            ? 'AND a.inspector_id = :scope_user_id'
            : '';

        $assessmentParameters = [
            'period_id' => $periodId,
        ];

        if ($isTechnician) {
            $assessmentParameters['scope_user_id'] =
                $currentUserId;
        }

        // Pemeriksaan A1 sampai A6

        $assessmentStatement = $pdo->prepare(
            "SELECT
                a.id,
                a.machine_id,
                a.inspector_id,
                a.status,
                m.alternative_code,
                s.c1_value,
                s.c2_value,
                s.c3_value,
                s.c4_value,
                s.c5_value,
                s.c4_not_checked_count,
                s.c5_not_checked_count
             FROM assessments AS a
             INNER JOIN machines AS m
                ON m.id = a.machine_id
             LEFT JOIN assessment_scores AS s
                ON s.assessment_id = a.id
             WHERE a.period_id = :period_id
               AND m.alternative_code IN (
                   'A1',
                   'A2',
                   'A3',
                   'A4',
                   'A5',
                   'A6'
               )
               {$assessmentScopeSql}
             ORDER BY
                m.alternative_code COLLATE NOCASE,
                a.id"
        );

        $assessmentStatement->execute(
            $assessmentParameters
        );

        $assessmentRows =
            $assessmentStatement->fetchAll();

        $assessmentCodes = [];
        $completedCodes = [];
        $validatedCodes = [];
        $scoreCompleteCodes = [];

        foreach ($assessmentRows as $assessmentRow) {
            $alternativeCode = strtoupper(
                trim(
                    (string) (
                        $assessmentRow['alternative_code']
                        ?? ''
                    )
                )
            );

            if (
                !in_array(
                    $alternativeCode,
                    $expectedAlternativeCodes,
                    true
                )
                || isset($assessmentCodes[$alternativeCode])
            ) {
                $assessmentStructureValid = false;
                continue;
            }

            $assessmentCodes[$alternativeCode] = true;

            $assessmentStatus = (string) (
                $assessmentRow['status']
                ?? ''
            );

            $assessmentStatusByCode[$alternativeCode] =
                $assessmentStatus;

            if (
                in_array(
                    $assessmentStatus,
                    [
                        'completed',
                        'validated',
                        'processed',
                    ],
                    true
                )
            ) {
                $completedCodes[$alternativeCode] = true;
            }

            if (
                in_array(
                    $assessmentStatus,
                    $allowedValidatedStatuses,
                    true
                )
            ) {
                $validatedCodes[$alternativeCode] = true;
            }

            if ($assessmentStatus === 'completed') {
                $statistics['pending_validation']++;
            } elseif ($assessmentStatus === 'draft') {
                $statistics['draft_assessments']++;
            } elseif ($assessmentStatus === 'returned') {
                $statistics['returned_assessments']++;
            }

            $scoreComplete = true;

            foreach (
                [
                    'c1_value',
                    'c2_value',
                    'c3_value',
                    'c4_value',
                    'c5_value',
                ]
                as $scoreField
            ) {
                $scoreValue = $assessmentRow[
                    $scoreField
                ] ?? null;

                if (
                    $scoreValue === null
                    || $scoreValue === ''
                    || !is_numeric((string) $scoreValue)
                    || !is_finite((float) $scoreValue)
                    || (float) $scoreValue < 0
                ) {
                    $scoreComplete = false;
                    break;
                }
            }

            if (
                (int) (
                    $assessmentRow['c4_not_checked_count']
                    ?? 0
                ) !== 0
                || (int) (
                    $assessmentRow['c5_not_checked_count']
                    ?? 0
                ) !== 0
            ) {
                $scoreComplete = false;
            }

            if ($scoreComplete) {
                $scoreCompleteCodes[$alternativeCode] = true;
            } elseif (
                in_array(
                    $assessmentStatus,
                    $allowedValidatedStatuses,
                    true
                )
            ) {
                $assessmentScoreStructureValid = false;
            }
        }

        $statistics['assessments_started'] =
            count($assessmentCodes);

        $statistics['assessments_completed'] =
            count($completedCodes);

        $statistics['assessments_validated'] =
            count($validatedCodes);

        $statistics['assessments_score_complete'] =
            count($scoreCompleteCodes);

        // Temuan Checklist Aktif C4 dan C5

        $findingStatement = $pdo->prepare(
            "SELECT
                SUM(
                    CASE
                        WHEN cr.condition_level = 'danger'
                        THEN 1
                        ELSE 0
                    END
                ) AS danger_count,
                SUM(
                    CASE
                        WHEN cr.condition_level = 'not_checked'
                        THEN 1
                        ELSE 0
                    END
                ) AS not_checked_count
             FROM checklist_results AS cr
             INNER JOIN assessments AS a
                ON a.id = cr.assessment_id
             INNER JOIN checklist_items AS ci
                ON ci.id = cr.checklist_item_id
             INNER JOIN criteria AS c
                ON c.id = ci.criterion_id
             WHERE a.period_id = :period_id
               AND ci.is_active = 1
               AND c.is_active = 1
               AND c.code IN (
                   'C4',
                   'C5'
               )
               {$assessmentScopeSql}"
        );

        $findingStatement->execute(
            $assessmentParameters
        );

        $findingData = $findingStatement->fetch();

        if (is_array($findingData)) {
            $statistics['danger_findings'] = (int) (
                $findingData['danger_count']
                ?? 0
            );

            $statistics['not_checked_findings'] = (int) (
                $findingData['not_checked_count']
                ?? 0
            );
        }

        // AHP dan TOPSIS Hanya untuk Admin/Supervisor

        if (!$isTechnician) {
            $comparisonStatement = $pdo->prepare(
                "SELECT
                    ac.criterion_left_id,
                    ac.criterion_right_id,
                    ac.comparison_value,
                    ac.assessor_id,
                    assessor.role AS assessor_role,
                    left_criterion.code AS left_code,
                    right_criterion.code AS right_code
                 FROM ahp_comparisons AS ac
                 INNER JOIN criteria AS left_criterion
                    ON left_criterion.id = ac.criterion_left_id
                 INNER JOIN criteria AS right_criterion
                    ON right_criterion.id = ac.criterion_right_id
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
                   )"
            );

            $comparisonStatement->execute([
                'period_id' => $periodId,
            ]);

            $comparisonRows =
                $comparisonStatement->fetchAll();

            $comparisonPairKeys = [];
            $comparisonAssessorIds = [];
            $comparisonInvalid = false;

            foreach ($comparisonRows as $comparisonRow) {
                $leftId = (int) (
                    $comparisonRow['criterion_left_id']
                    ?? 0
                );

                $rightId = (int) (
                    $comparisonRow['criterion_right_id']
                    ?? 0
                );

                $comparisonValue = $comparisonRow[
                    'comparison_value'
                ] ?? null;

                $assessorId = (int) (
                    $comparisonRow['assessor_id']
                    ?? 0
                );

                $assessorRole = (string) (
                    $comparisonRow['assessor_role']
                    ?? ''
                );

                if (
                    $leftId < 1
                    || $rightId < 1
                    || $leftId === $rightId
                    || $comparisonValue === null
                    || !is_numeric((string) $comparisonValue)
                    || !is_finite((float) $comparisonValue)
                    || (float) $comparisonValue <= 0
                    || $assessorId < 1
                    || $assessorRole !== 'supervisor'
                ) {
                    $comparisonInvalid = true;
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

                if (isset($comparisonPairKeys[$pairKey])) {
                    $comparisonInvalid = true;
                    continue;
                }

                $comparisonPairKeys[$pairKey] = true;
                $comparisonAssessorIds[$assessorId] = true;
            }

            $statistics['ahp_comparisons'] =
                count($comparisonPairKeys);

            $ahpComparisonStructureValid =
                !$comparisonInvalid
                && count($comparisonPairKeys)
                    === $expectedAhpComparisonCount
                && count($comparisonAssessorIds) === 1;

            $weightStatement = $pdo->prepare(
                "SELECT
                    ar.criterion_id,
                    ar.weight,
                    c.code
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
                   )"
            );

            $weightStatement->execute([
                'period_id' => $periodId,
            ]);

            $weightRows = $weightStatement->fetchAll();
            $weightsByCode = [];
            $weightInvalid = false;

            foreach ($weightRows as $weightRow) {
                $criterionCode = strtoupper(
                    trim(
                        (string) (
                            $weightRow['code']
                            ?? ''
                        )
                    )
                );

                $weightValue = $weightRow['weight']
                    ?? null;

                if (
                    !in_array(
                        $criterionCode,
                        $expectedCriterionCodes,
                        true
                    )
                    || isset($weightsByCode[$criterionCode])
                    || $weightValue === null
                    || !is_numeric((string) $weightValue)
                    || !is_finite((float) $weightValue)
                    || (float) $weightValue <= 0
                ) {
                    $weightInvalid = true;
                    continue;
                }

                $weightsByCode[$criterionCode] =
                    (float) $weightValue;
            }

            foreach (
                $expectedCriterionCodes
                as $criterionCode
            ) {
                if (isset($weightsByCode[$criterionCode])) {
                    $ahpWeightSum +=
                        $weightsByCode[$criterionCode];
                }
            }

            $statistics['ahp_weights'] =
                count($weightsByCode);

            $ahpWeightStructureValid =
                !$weightInvalid
                && count($weightsByCode)
                    === $expectedCriteriaCount
                && count(
                    array_diff(
                        $expectedCriterionCodes,
                        array_keys($weightsByCode)
                    )
                ) === 0
                && abs(
                    $ahpWeightSum - 1.0
                ) <= $weightTolerance;

            $ahpStatement = $pdo->prepare(
                "SELECT
                    ahs.assessor_id,
                    ahs.lambda_max,
                    ahs.consistency_index,
                    ahs.random_index,
                    ahs.consistency_ratio,
                    ahs.is_consistent,
                    ahs.calculated_at,
                    assessor.role AS assessor_role
                 FROM ahp_summaries AS ahs
                 INNER JOIN users AS assessor
                    ON assessor.id = ahs.assessor_id
                 WHERE ahs.period_id = :period_id
                 LIMIT 1"
            );

            $ahpStatement->execute([
                'period_id' => $periodId,
            ]);

            $ahpData = $ahpStatement->fetch();

            $ahpSummary = is_array($ahpData)
                ? $ahpData
                : null;

            if ($ahpSummary !== null) {
                $summaryAssessorId = (int) (
                    $ahpSummary['assessor_id']
                    ?? 0
                );

                $summaryAssessorRole = (string) (
                    $ahpSummary['assessor_role']
                    ?? ''
                );

                $ahpAssessorValid =
                    $summaryAssessorRole === 'supervisor'
                    && count($comparisonAssessorIds) === 1
                    && isset(
                        $comparisonAssessorIds[
                            $summaryAssessorId
                        ]
                    );
            }

            $topsisStatement = $pdo->prepare(
                "SELECT
                    tr.assessment_id,
                    tr.positive_distance,
                    tr.negative_distance,
                    tr.preference_value,
                    tr.ranking,
                    tr.rank_group,
                    tr.priority_label,
                    m.alternative_code,
                    m.motor_name
                 FROM topsis_results AS tr
                 INNER JOIN assessments AS a
                    ON a.id = tr.assessment_id
                   AND a.period_id = tr.period_id
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
                 ORDER BY
                    tr.ranking ASC,
                    tr.rank_group ASC,
                    tr.preference_value DESC,
                    m.alternative_code COLLATE NOCASE"
            );

            $topsisStatement->execute([
                'period_id' => $periodId,
            ]);

            $topsisRows = $topsisStatement->fetchAll();
            $topsisCodes = [];
            $topsisValidationRows = [];
            $topsisInvalid = false;
            $topPriorityRows = [];

            foreach ($topsisRows as $topsisRow) {
                $alternativeCode = strtoupper(
                    trim(
                        (string) (
                            $topsisRow['alternative_code']
                            ?? ''
                        )
                    )
                );

                if (
                    !in_array(
                        $alternativeCode,
                        $expectedAlternativeCodes,
                        true
                    )
                    || isset($topsisCodes[$alternativeCode])
                ) {
                    $topsisInvalid = true;
                    continue;
                }

                foreach (
                    [
                        'positive_distance',
                        'negative_distance',
                        'preference_value',
                    ]
                    as $numericField
                ) {
                    $numericValue = $topsisRow[
                        $numericField
                    ] ?? null;

                    if (
                        $numericValue === null
                        || !is_numeric((string) $numericValue)
                        || !is_finite((float) $numericValue)
                        || (float) $numericValue < 0
                    ) {
                        $topsisInvalid = true;
                        continue 2;
                    }
                }

                $preferenceValue = (float) (
                    $topsisRow['preference_value']
                    ?? 0
                );

                $ranking = (int) (
                    $topsisRow['ranking']
                    ?? 0
                );

                $rankGroup = (int) (
                    $topsisRow['rank_group']
                    ?? 0
                );

                if (
                    $preferenceValue > 1
                    || $ranking < 1
                    || $ranking > $expectedMachineCount
                    || $rankGroup < 1
                    || $rankGroup > $expectedMachineCount
                ) {
                    $topsisInvalid = true;
                    continue;
                }

                $topsisCodes[$alternativeCode] = true;

                $topsisValidationRows[] = [
                    'alternative_code' => $alternativeCode,
                    'preference_value' => $preferenceValue,
                    'ranking' => $ranking,
                    'rank_group' => $rankGroup,
                ];

                if ($ranking === 1) {
                    $topPriorityRows[] = $topsisRow;
                }
            }

            usort(
                $topsisValidationRows,
                static function (
                    array $first,
                    array $second
                ): int {
                    $preferenceComparison =
                        (float) $second['preference_value']
                        <=>
                        (float) $first['preference_value'];

                    if ($preferenceComparison !== 0) {
                        return $preferenceComparison;
                    }

                    return strcmp(
                        (string) $first['alternative_code'],
                        (string) $second['alternative_code']
                    );
                }
            );

            $previousPreference = null;
            $expectedRankGroup = 0;
            $expectedRanking = 0;

            foreach (
                $topsisValidationRows
                as $position => $validationRow
            ) {
                $preferenceValue = (float) (
                    $validationRow['preference_value']
                );

                $newGroup =
                    $previousPreference === null
                    || abs(
                        $preferenceValue
                        - $previousPreference
                    ) > $preferenceTolerance;

                if ($newGroup) {
                    $expectedRankGroup++;
                    $expectedRanking = $position + 1;
                    $previousPreference = $preferenceValue;
                }

                if (
                    (int) $validationRow['ranking']
                        !== $expectedRanking
                    || (int) $validationRow['rank_group']
                        !== $expectedRankGroup
                ) {
                    $topsisInvalid = true;
                    break;
                }
            }

            $statistics['topsis_results'] =
                count($topsisCodes);

            $topsisResultStructureValid =
                !$topsisInvalid
                && count($topsisCodes)
                    === $expectedMachineCount
                && count(
                    array_diff(
                        $expectedAlternativeCodes,
                        array_keys($topsisCodes)
                    )
                ) === 0;

            if ($topPriorityRows !== []) {
                usort(
                    $topPriorityRows,
                    static fn (
                        array $first,
                        array $second
                    ): int => strcmp(
                        (string) (
                            $first['alternative_code']
                            ?? ''
                        ),
                        (string) (
                            $second['alternative_code']
                            ?? ''
                        )
                    )
                );

                $topPriority = $topPriorityRows[0];
                $topPriorityCount = count($topPriorityRows);
            }
        }

        // Tindakan Pemeliharaan Sesuai Peran

        $actionScopeSql = $isTechnician
            ? 'AND ma.performed_by = :action_user_id'
            : '';

        $actionParameters = [
            'period_id' => $periodId,
        ];

        if ($isTechnician) {
            $actionParameters['action_user_id'] =
                $currentUserId;
        }

        $actionStatement = $pdo->prepare(
            "SELECT
                COUNT(*) AS action_count,
                SUM(
                    CASE
                        WHEN ma.status IN (
                            'pending',
                            'in_progress'
                        )
                        THEN 1
                        ELSE 0
                    END
                ) AS active_count,
                SUM(
                    CASE
                        WHEN ma.status = 'completed'
                        THEN 1
                        ELSE 0
                    END
                ) AS completed_count,
                SUM(
                    CASE
                        WHEN ma.status <> 'completed'
                         AND ma.target_date IS NOT NULL
                         AND ma.target_date <> ''
                         AND date(ma.target_date) < date('now', '+7 hours')
                        THEN 1
                        ELSE 0
                    END
                ) AS overdue_count
             FROM maintenance_actions AS ma
             INNER JOIN assessments AS a
                ON a.id = ma.assessment_id
             WHERE a.period_id = :period_id
               {$actionScopeSql}"
        );

        $actionStatement->execute(
            $actionParameters
        );

        $actionData = $actionStatement->fetch();

        if (is_array($actionData)) {
            $statistics['actions_total'] = (int) (
                $actionData['action_count']
                ?? 0
            );

            $statistics['actions_active'] = (int) (
                $actionData['active_count']
                ?? 0
            );

            $statistics['actions_completed'] = (int) (
                $actionData['completed_count']
                ?? 0
            );

            $statistics['actions_overdue'] = (int) (
                $actionData['overdue_count']
                ?? 0
            );
        }

        if ($isTechnician) {
            $statistics['actions_assigned_to_user'] =
                $statistics['actions_active'];
        }
    }

    // Pemeriksaan Terbaru

    $recentSql = "
        SELECT
            a.id,
            a.inspection_date,
            a.inspection_time,
            a.status,
            p.name AS period_name,
            m.alternative_code,
            m.motor_name,
            u.name AS inspector_name,
            COALESCE(cl.danger_count, 0)
                AS danger_count,
            COALESCE(cl.not_checked_count, 0)
                AS not_checked_count
        FROM assessments AS a
        INNER JOIN machines AS m
            ON m.id = a.machine_id
        INNER JOIN assessment_periods AS p
            ON p.id = a.period_id
        INNER JOIN users AS u
            ON u.id = a.inspector_id
        LEFT JOIN (
            SELECT
                cr.assessment_id,
                SUM(
                    CASE
                        WHEN cr.condition_level = 'danger'
                        THEN 1
                        ELSE 0
                    END
                ) AS danger_count,
                SUM(
                    CASE
                        WHEN cr.condition_level = 'not_checked'
                        THEN 1
                        ELSE 0
                    END
                ) AS not_checked_count
            FROM checklist_results AS cr
            INNER JOIN checklist_items AS ci
                ON ci.id = cr.checklist_item_id
            INNER JOIN criteria AS c
                ON c.id = ci.criterion_id
            WHERE ci.is_active = 1
              AND c.is_active = 1
              AND c.code IN (
                  'C4',
                  'C5'
              )
            GROUP BY cr.assessment_id
        ) AS cl
            ON cl.assessment_id = a.id
        WHERE 1 = 1
    ";

    $recentParameters = [];

    if ($isTechnician) {
        $recentSql .= "
            AND a.inspector_id = :inspector_id
        ";

        $recentParameters['inspector_id'] =
            $currentUserId;
    }

    if ($activePeriod !== null) {
        $recentSql .= "
            AND a.period_id = :recent_period_id
        ";

        $recentParameters['recent_period_id'] =
            (int) $activePeriod['id'];
    }

    $recentSql .= "
        ORDER BY
            a.updated_at DESC,
            a.id DESC
        LIMIT 6
    ";

    $recentStatement = $pdo->prepare($recentSql);
    $recentStatement->execute($recentParameters);

    $recentAssessments =
        $recentStatement->fetchAll();
} catch (Throwable $exception) {
    error_log(
        'Dashboard SPK PT. CISAS gagal: '
        . $exception->getMessage()
    );

    $dashboardError =
        'Sebagian data dashboard belum dapat dimuat. '
        . 'Periksa kembali database dan proses setup.';
}

// Status dan Progres

$notInspectedCount = max(
    0,
    $expectedMachineCount
        - $statistics['assessments_started']
);

$assessmentProgress = $expectedMachineCount > 0
    ? (int) round(
        (
            $statistics['assessments_completed']
            / $expectedMachineCount
        ) * 100
    )
    : 0;

$assessmentProgress = max(
    0,
    min(100, $assessmentProgress)
);

$validationProgress = $expectedMachineCount > 0
    ? (int) round(
        (
            $statistics['assessments_validated']
            / $expectedMachineCount
        ) * 100
    )
    : 0;

$validationProgress = max(
    0,
    min(100, $validationProgress)
);

$consistencyRatio =
    $ahpSummary !== null
    && is_numeric(
        (string) (
            $ahpSummary['consistency_ratio']
            ?? null
        )
    )
        ? (float) $ahpSummary['consistency_ratio']
        : null;

$ahpIsConsistent =
    !$isTechnician
    && $ahpSummary !== null
    && (int) (
        $ahpSummary['is_consistent']
        ?? 0
    ) === 1
    && $consistencyRatio !== null
    && is_finite($consistencyRatio)
    && $consistencyRatio >= 0
    && $consistencyRatio <= 0.10;

$ahpIsComplete =
    !$isTechnician
    && $ahpComparisonStructureValid
    && $ahpWeightStructureValid
    && $ahpAssessorValid
    && $ahpIsConsistent;

$assessmentsAreReady =
    $assessmentStructureValid
    && $assessmentScoreStructureValid
    && $statistics['assessments_validated']
        === $expectedMachineCount
    && $statistics['assessments_score_complete']
        === $expectedMachineCount
    && $statistics['not_checked_findings'] === 0;

$topsisIsReady =
    !$isTechnician
    && $assessmentsAreReady
    && $ahpIsComplete;

$topsisIsComplete =
    !$isTechnician
    && $topsisResultStructureValid
    && $statistics['topsis_results']
        === $expectedMachineCount;

if (
    !$isTechnician
    && $statistics['ahp_comparisons'] > 0
    && !$ahpComparisonStructureValid
) {
    $ahpResultValidationError =
        'Pasangan AHP atau responden ahli belum sesuai.';
} elseif (
    !$isTechnician
    && $statistics['ahp_weights'] > 0
    && !$ahpWeightStructureValid
) {
    $ahpResultValidationError =
        'Bobot C1–C5 belum lengkap atau totalnya bukan 1.';
} elseif (
    !$isTechnician
    && $ahpSummary !== null
    && !$ahpAssessorValid
) {
    $ahpResultValidationError =
        'Responden ringkasan AHP tidak sama dengan Supervisor penilai.';
}

// Status Pemeriksaan

$statusConfigurations = [
    'draft' => [
        'label' => 'Draft',
        'class' => 'secondary',
    ],
    'completed' => [
        'label' => 'Menunggu Validasi',
        'class' => 'warning',
    ],
    'validated' => [
        'label' => 'Disetujui',
        'class' => 'success',
    ],
    'returned' => [
        'label' => 'Dikembalikan',
        'class' => 'danger',
    ],
    'processed' => [
        'label' => 'Diproses',
        'class' => 'primary',
    ],
];

// Konfigurasi Sambutan Berdasarkan Peran

$roleDashboardConfiguration = match ($currentRole) {
    'admin' => [
        'greeting_name' => 'Administrator',
        'description' =>
            'Otoritas sistem tertinggi: kelola akun, data master, '
            . 'periode pemeriksaan, monitoring seluruh proses, '
            . 'riwayat perhitungan, dan log aktivitas.',
        'action_label' => 'Kelola Sistem',
        'action_path' => 'pengguna/index.php',
    ],
    'supervisor' => [
        'greeting_name' => 'Supervisor',
        'description' =>
            'Pantau validasi pemeriksaan, konsistensi AHP, '
            . 'dan kesiapan pemeringkatan TOPSIS.',
        'action_label' => 'Validasi Pemeriksaan',
        'action_path' =>
            'pemeriksaan/index.php?mode=validation',
    ],
    'technician' => [
        'greeting_name' => 'Teknisi',
        'description' =>
            'Lanjutkan pemeriksaan motor milik akun Anda dan '
            . 'tindak lanjuti pekerjaan yang ditugaskan.',
        'action_label' => 'Buka Pemeriksaan',
        'action_path' => 'pemeriksaan/index.php',
    ],
    default => [
        'greeting_name' => $currentUserName,
        'description' =>
            'Pantau proses pemeriksaan Sistem SPK PT. CISAS.',
        'action_label' => 'Buka Dashboard',
        'action_path' => 'dashboard.php',
    ],
};

// Pusat Perhatian

$attention = [
    'tone' => 'success',
    'icon' => 'check',
    'title' => 'Tidak ada hambatan utama',
    'message' =>
        'Alur utama sistem berada dalam kondisi siap. '
        . 'Lanjutkan pemantauan sesuai tugas Anda.',
    'action_label' => null,
    'action_path' => null,
];

if ($activePeriod === null) {
    $attention = [
        'tone' => 'warning',
        'icon' => 'calendar',
        'title' => 'Belum terdapat periode pemeriksaan',
        'message' => $isAdmin
            ? 'Buat periode agar alur pemeriksaan dapat dimulai.'
            : 'Periode pemeriksaan belum disiapkan oleh Administrator.',
        'action_label' => $isAdmin
            ? 'Kelola Periode'
            : null,
        'action_path' => $isAdmin
            ? 'periode/index.php'
            : null,
    ];
} elseif (!$periodIsActive && (string) ($activePeriod['status'] ?? '') === 'draft') {
    $attention = [
        'tone' => 'warning',
        'icon' => 'calendar',
        'title' => 'Periode belum diaktifkan',
        'message' => $isAdmin
            ? 'Aktifkan periode agar Teknisi dapat memulai pemeriksaan enam motor.'
            : 'Pemeriksaan belum dapat dimulai sampai Administrator mengaktifkan periode.',
        'action_label' => $isAdmin
            ? 'Kelola Periode'
            : null,
        'action_path' => $isAdmin
            ? 'periode/index.php'
            : null,
    ];
} elseif (
    $isTechnician
    && $statistics['returned_assessments'] > 0
) {
    $attention = [
        'tone' => 'danger',
        'icon' => 'alert',
        'title' => 'Pemeriksaan perlu diperbaiki',
        'message' =>
            $statistics['returned_assessments']
            . ' pemeriksaan milik Anda dikembalikan oleh Supervisor.',
        'action_label' => 'Buka Pemeriksaan',
        'action_path' => 'pemeriksaan/index.php',
    ];
} elseif (
    $isTechnician
    && $statistics['not_checked_findings'] > 0
) {
    $attention = [
        'tone' => 'warning',
        'icon' => 'alert',
        'title' => 'Checklist belum lengkap',
        'message' =>
            'Terdapat '
            . $statistics['not_checked_findings']
            . ' bagian checklist belum diisi pada data Anda.',
        'action_label' => 'Lengkapi Pemeriksaan',
        'action_path' => 'pemeriksaan/index.php',
    ];
} elseif (
    $isSupervisor
    && $statistics['pending_validation'] > 0
) {
    $attention = [
        'tone' => 'warning',
        'icon' => 'clock',
        'title' => 'Pemeriksaan menunggu validasi',
        'message' =>
            $statistics['pending_validation']
            . ' pemeriksaan telah dikirim oleh Teknisi.',
        'action_label' => 'Buka Validasi',
        'action_path' =>
            'pemeriksaan/index.php?mode=validation',
    ];
} elseif (
    !$isTechnician
    && $statistics['danger_findings'] > 0
) {
    $attention = [
        'tone' => 'danger',
        'icon' => 'alert',
        'title' => 'Ditemukan kondisi Bahaya',
        'message' =>
            'Terdapat '
            . $statistics['danger_findings']
            . ' indikator Bahaya pada periode saat ini.',
        'action_label' => 'Lihat Pemeriksaan',
        'action_path' => $isSupervisor
            ? 'pemeriksaan/index.php?mode=validation&status='
            : 'pemeriksaan/index.php',
    ];
} elseif (
    $isTechnician
    && $statistics['assessments_started']
        < $expectedMachineCount
) {
    $attention = [
        'tone' => 'primary',
        'icon' => 'clipboard',
        'title' => 'Pemeriksaan belum lengkap',
        'message' =>
            $notInspectedCount
            . ' motor belum Anda periksa pada periode saat ini.',
        'action_label' => 'Lanjutkan Pemeriksaan',
        'action_path' => 'pemeriksaan/index.php',
    ];
} elseif (
    $isTechnician
    && $statistics['actions_assigned_to_user'] > 0
) {
    $attention = [
        'tone' => 'primary',
        'icon' => 'wrench',
        'title' => 'Tindakan pemeliharaan aktif',
        'message' =>
            $statistics['actions_assigned_to_user']
            . ' tindakan masih menunggu atau sedang Anda kerjakan.',
        'action_label' => 'Buka Riwayat',
        'action_path' => 'riwayat/index.php',
    ];
} elseif (
    $isSupervisor
    && $assessmentsAreReady
    && !$ahpIsComplete
) {
    $attention = [
        'tone' =>
            $ahpSummary !== null
            && !$ahpIsConsistent
                ? 'danger'
                : 'primary',
        'icon' => 'layers',
        'title' =>
            $ahpSummary !== null
            && !$ahpIsConsistent
                ? 'Pembobotan AHP belum konsisten'
                : 'Pembobotan AHP perlu diselesaikan',
        'message' => $ahpResultValidationError
            ?? (
                $ahpSummary !== null
                && !$ahpIsConsistent
                    ? 'Rasio Konsistensi saat ini '
                        . $displayNumber(
                            $consistencyRatio,
                            4
                        )
                        . '. Nilainya harus ≤ 0,10.'
                    : 'Lengkapi sepuluh perbandingan dan lima bobot C1–C5.'
            ),
        'action_label' => 'Buka AHP',
        'action_path' => 'ahp/index.php',
    ];
} elseif (
    $isSupervisor
    && $topsisIsReady
    && !$topsisIsComplete
) {
    $attention = [
        'tone' => 'primary',
        'icon' => 'chart',
        'title' => 'TOPSIS siap diproses',
        'message' =>
            'Enam pemeriksaan lengkap dan bobot AHP konsisten.',
        'action_label' => 'Proses TOPSIS',
        'action_path' => 'topsis/index.php',
    ];
} elseif (
    $isSupervisor
    && $topsisIsComplete
    && $statistics['actions_total'] === 0
) {
    $attention = [
        'tone' => 'primary',
        'icon' => 'wrench',
        'title' => 'Rekomendasi belum dibuat',
        'message' =>
            'Pemeringkatan TOPSIS selesai. Buat rekomendasi tindak lanjut.',
        'action_label' => 'Buka Riwayat',
        'action_path' => 'riwayat/index.php',
    ];
} elseif (
    $isAdmin
    && $statistics['assessments_started']
        < $expectedMachineCount
) {
    $attention = [
        'tone' => 'primary',
        'icon' => 'info',
        'title' => 'Pemeriksaan sedang berlangsung',
        'message' =>
            $statistics['assessments_started']
            . ' dari '
            . $expectedMachineCount
            . ' motor telah mulai diperiksa.',
        'action_label' => 'Lihat Pemeriksaan',
        'action_path' => 'pemeriksaan/index.php',
    ];
}

// Alur Proses SPK

if ($isTechnician) {
    $processSteps = [
        [
            'title' => 'Periode',
            'icon' => 'calendar',
            'status' => $activePeriod === null
                || (string) ($activePeriod['status'] ?? '') === 'draft'
                    ? 'attention'
                    : 'complete',
            'status_label' => $periodStatusLabel,
            'detail' => $activePeriod !== null
                ? (string) $activePeriod['name']
                : 'Periode belum tersedia',
        ],
        [
            'title' => 'Pemeriksaan',
            'icon' => 'clipboard',
            'status' =>
                $statistics['assessments_completed']
                    === $expectedMachineCount
                    ? 'complete'
                    : (
                        $periodIsActive
                            ? 'active'
                            : 'pending'
                    ),
            'status_label' =>
                $statistics['assessments_completed']
                . ' dari '
                . $expectedMachineCount,
            'detail' => 'Pemeriksaan milik akun Anda',
        ],
        [
            'title' => 'Validasi',
            'icon' => 'check',
            'status' =>
                $statistics['assessments_validated']
                    === $expectedMachineCount
                    ? 'complete'
                    : (
                        $statistics['returned_assessments'] > 0
                            ? 'attention'
                            : 'active'
                    ),
            'status_label' =>
                $statistics['assessments_validated']
                . ' dari '
                . $expectedMachineCount,
            'detail' => 'Keputusan Supervisor',
        ],
        [
            'title' => 'Tindak Lanjut',
            'icon' => 'wrench',
            'status' =>
                $statistics['actions_total'] > 0
                && $statistics['actions_active'] === 0
                    ? 'complete'
                    : (
                        $statistics['actions_active'] > 0
                            ? 'active'
                            : 'pending'
                    ),
            'status_label' =>
                $statistics['actions_completed']
                . ' selesai',
            'detail' => 'Tindakan yang ditugaskan',
        ],
        [
            'title' => 'Laporan',
            'icon' => 'chart',
            'status' =>
                $statistics['assessments_started'] > 0
                    ? 'active'
                    : 'pending',
            'status_label' =>
                $statistics['assessments_started'] > 0
                    ? 'Tersedia terbatas'
                    : 'Belum tersedia',
            'detail' => 'Laporan pemeriksaan milik sendiri',
        ],
    ];
} else {
    $processSteps = [
        [
            'title' => 'Periode',
            'icon' => 'calendar',
            'status' => $activePeriod === null
                || (string) ($activePeriod['status'] ?? '') === 'draft'
                    ? 'attention'
                    : 'complete',
            'status_label' => $periodStatusLabel,
            'detail' => $activePeriod !== null
                ? (string) $activePeriod['name']
                : 'Periode belum tersedia',
        ],
        [
            'title' => 'Pemeriksaan',
            'icon' => 'clipboard',
            'status' =>
                $statistics['assessments_completed']
                    === $expectedMachineCount
                    ? 'complete'
                    : (
                        $periodIsActive
                            ? 'active'
                            : 'pending'
                    ),
            'status_label' =>
                $statistics['assessments_completed']
                . ' dari '
                . $expectedMachineCount,
            'detail' => 'Pengukuran dan checklist motor',
        ],
        [
            'title' => 'Validasi',
            'icon' => 'check',
            'status' =>
                $statistics['assessments_validated']
                    === $expectedMachineCount
                    ? 'complete'
                    : (
                        $statistics['pending_validation'] > 0
                            ? 'attention'
                            : (
                                $statistics['assessments_completed'] > 0
                                    ? 'active'
                                    : 'pending'
                            )
                    ),
            'status_label' =>
                $statistics['assessments_validated']
                . ' dari '
                . $expectedMachineCount,
            'detail' => 'Persetujuan Supervisor',
        ],
        [
            'title' => 'AHP',
            'icon' => 'layers',
            'status' => $ahpIsComplete
                ? 'complete'
                : (
                    $assessmentsAreReady
                        ? 'active'
                        : 'pending'
                ),
            'status_label' => $ahpIsComplete
                ? 'Konsisten'
                : 'Belum selesai',
            'detail' => 'Bobot lima kriteria',
        ],
        [
            'title' => 'TOPSIS',
            'icon' => 'chart',
            'status' => $topsisIsComplete
                ? 'complete'
                : (
                    $topsisIsReady
                        ? 'active'
                        : 'pending'
                ),
            'status_label' => $topsisIsComplete
                ? 'Selesai'
                : (
                    $topsisIsReady
                        ? 'Siap diproses'
                        : 'Belum siap'
                ),
            'detail' => 'Peringkat enam motor',
        ],
        [
            'title' => 'Tindak Lanjut',
            'icon' => 'wrench',
            'status' =>
                $statistics['actions_total'] > 0
                && $statistics['actions_active'] === 0
                    ? 'complete'
                    : (
                        $topsisIsComplete
                            ? 'active'
                            : 'pending'
                    ),
            'status_label' =>
                $statistics['actions_total'] > 0
                    ? $statistics['actions_completed']
                        . ' selesai'
                    : 'Belum tersedia',
            'detail' => 'Rekomendasi dan pelaksanaan',
        ],
    ];
}

// Ringkasan Berdasarkan Peran

$summaryCards = match ($currentRole) {
    'admin' => [
        [
            'label' => 'Periode Saat Ini',
            'value' => $periodStatusLabel,
            'note' => $activePeriod !== null
                ? (string) $activePeriod['name']
                : 'Belum ada periode pemeriksaan',
            'icon' => 'calendar',
            'tone' => $periodIsActive
                ? 'primary'
                : (
                    $activePeriod !== null
                    && (string) ($activePeriod['status'] ?? '') === 'completed'
                        ? 'success'
                        : 'warning'
                ),
        ],
        [
            'label' => 'Pemeriksaan Dimulai',
            'value' =>
                $statistics['assessments_started']
                . '/'
                . $expectedMachineCount,
            'note' => 'Motor pada periode saat ini',
            'icon' => 'clipboard',
            'tone' => 'primary',
        ],
        [
            'label' => 'Pengguna Aktif',
            'value' =>
                (string) $statistics['active_users'],
            'note' => 'Admin, Supervisor, dan Teknisi',
            'icon' => 'users',
            'tone' => 'success',
        ],
        [
            'label' => 'Data Motor Siap',
            'value' =>
                $statistics['machine_data_ready']
                . '/'
                . $expectedMachineCount,
            'note' =>
                'Identitas, arus, frekuensi, dan RPM tersedia',
            'icon' => 'motor',
            'tone' =>
                $statistics['machine_data_ready']
                    === $expectedMachineCount
                    ? 'success'
                    : 'warning',
        ],
    ],
    'supervisor' => [
        [
            'label' => 'Menunggu Validasi',
            'value' =>
                (string) $statistics['pending_validation'],
            'note' => 'Pemeriksaan yang telah dikirim',
            'icon' => 'clock',
            'tone' => 'warning',
        ],
        [
            'label' => 'Disetujui',
            'value' =>
                $statistics['assessments_validated']
                . '/'
                . $expectedMachineCount,
            'note' => 'Siap digunakan dalam TOPSIS',
            'icon' => 'check',
            'tone' => 'success',
        ],
        [
            'label' => 'Rasio Konsistensi',
            'value' => $displayNumber(
                $consistencyRatio,
                4
            ),
            'note' => $ahpIsConsistent
                ? 'AHP konsisten'
                : 'Batas konsistensi ≤ 0,10',
            'icon' => 'layers',
            'tone' => $ahpIsConsistent
                ? 'success'
                : 'secondary',
        ],
        [
            'label' => 'Hasil TOPSIS',
            'value' =>
                $statistics['topsis_results']
                . '/'
                . $expectedMachineCount,
            'note' => $topsisIsComplete
                ? 'Pemeringkatan selesai'
                : 'Belum lengkap',
            'icon' => 'chart',
            'tone' => $topsisIsComplete
                ? 'primary'
                : 'secondary',
        ],
    ],
    'technician' => [
        [
            'label' => 'Belum Diperiksa',
            'value' => (string) $notInspectedCount,
            'note' => 'Motor pada periode saat ini',
            'icon' => 'motor',
            'tone' => $notInspectedCount > 0
                ? 'warning'
                : 'success',
        ],
        [
            'label' => 'Draft Saya',
            'value' =>
                (string) $statistics['draft_assessments'],
            'note' => 'Pemeriksaan belum dikirim',
            'icon' => 'clipboard',
            'tone' => 'secondary',
        ],
        [
            'label' => 'Dikembalikan',
            'value' =>
                (string) $statistics['returned_assessments'],
            'note' => 'Perlu diperbaiki',
            'icon' => 'alert',
            'tone' =>
                $statistics['returned_assessments'] > 0
                    ? 'danger'
                    : 'secondary',
        ],
        [
            'label' => 'Tindakan Aktif',
            'value' =>
                (string) $statistics['actions_assigned_to_user'],
            'note' => 'Menunggu atau sedang dikerjakan',
            'icon' => 'wrench',
            'tone' => 'primary',
        ],
    ],
    default => [],
};

// Tindakan Cepat

$quickActions = match ($currentRole) {
    'admin' => [
        [
            'title' => 'Kelola Pengguna',
            'description' =>
                'Tambah atau ubah akun pengguna sistem.',
            'path' => 'pengguna/index.php',
            'icon' => 'users',
        ],
        [
            'title' => 'Data Motor',
            'description' =>
                'Periksa identitas dan spesifikasi enam motor.',
            'path' => 'motor/index.php',
            'icon' => 'motor',
        ],
        [
            'title' => 'Periode Pemeriksaan',
            'description' =>
                'Buat dan kelola periode pemeriksaan.',
            'path' => 'periode/index.php',
            'icon' => 'calendar',
        ],
        [
            'title' => 'Pemeriksaan Motor',
            'description' =>
                'Pantau seluruh data pemeriksaan tanpa mengambil alih validasi Supervisor.',
            'path' => 'pemeriksaan/index.php',
            'icon' => 'clipboard',
        ],
        [
            'title' => 'Riwayat Perhitungan',
            'description' =>
                'Telusuri histori proses AHP dan TOPSIS untuk audit dan pengecekan.',
            'path' => 'perhitungan/riwayat.php',
            'icon' => 'history',
        ],
        [
            'title' => 'Log Aktivitas',
            'description' =>
                'Telusuri aktivitas pengguna ketika terjadi kendala administratif sistem.',
            'path' => 'audit/index.php',
            'icon' => 'history',
        ],
        [
            'title' => 'Riwayat Pemeliharaan',
            'description' =>
                'Lihat rekomendasi dan tindak lanjut.',
            'path' => 'riwayat/index.php',
            'icon' => 'wrench',
        ],
        [
            'title' => 'Laporan',
            'description' =>
                'Lihat dan cetak laporan sistem.',
            'path' => 'laporan/index.php',
            'icon' => 'chart',
        ],
    ],
    'supervisor' => [
        [
            'title' => 'Validasi Pemeriksaan',
            'description' =>
                'Periksa data yang dikirim oleh Teknisi.',
            'path' =>
                'pemeriksaan/index.php?mode=validation',
            'icon' => 'check',
        ],
        [
            'title' => 'Pembobotan AHP',
            'description' =>
                'Tentukan bobot lima kriteria.',
            'path' => 'ahp/index.php',
            'icon' => 'layers',
        ],
        [
            'title' => 'Pemeringkatan TOPSIS',
            'description' =>
                'Proses prioritas enam motor.',
            'path' => 'topsis/index.php',
            'icon' => 'chart',
        ],
        [
            'title' => 'Riwayat Pemeliharaan',
            'description' =>
                'Buat rekomendasi dan pantau realisasi.',
            'path' => 'riwayat/index.php',
            'icon' => 'wrench',
        ],
        [
            'title' => 'Laporan',
            'description' =>
                'Lihat dan cetak laporan sistem.',
            'path' => 'laporan/index.php',
            'icon' => 'clipboard',
        ],
    ],
    'technician' => [
        [
            'title' => 'Pemeriksaan Motor',
            'description' =>
                'Masukkan data pemeriksaan A1 sampai A6.',
            'path' => 'pemeriksaan/index.php',
            'icon' => 'clipboard',
        ],
        [
            'title' => 'Data Motor',
            'description' =>
                'Lihat identitas dan spesifikasi motor.',
            'path' => 'motor/index.php',
            'icon' => 'motor',
        ],
        [
            'title' => 'Riwayat Pemeliharaan',
            'description' =>
                'Catat pelaksanaan tindakan yang ditugaskan.',
            'path' => 'riwayat/index.php',
            'icon' => 'wrench',
        ],
        [
            'title' => 'Laporan Pemeriksaan',
            'description' =>
                'Lihat laporan terbatas pemeriksaan milik Anda.',
            'path' => 'laporan/index.php',
            'icon' => 'chart',
        ],
    ],
    default => [],
};

// Konfigurasi Halaman

$pageTitle = 'Dashboard';

$pageDescription =
    'Ringkasan Sistem Pendukung Keputusan '
    . 'Pemeliharaan Motor Mesin Mixer PT. CISAS.';

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

        <?php if (
            $dashboardError !== null
        ): ?>
            <div class="alert alert-danger mb-4" role="alert">
                <strong class="d-block mb-1">
                    Dashboard belum dapat dimuat
                </strong>

                <?= e(
                    $dashboardError
                ) ?>
            </div>
        <?php endif; ?>

        <!-- Sambutan ringkas -->
        <section class="dashboard-welcome dashboard-welcome-compact card border-0 shadow-sm mb-4" aria-labelledby="dashboard-welcome-title">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-4">
                    <div class="dashboard-welcome-copy">
                        <h1 id="dashboard-welcome-title" class="dashboard-welcome-title">
                            Selamat datang,

                            <?= e(
                                (string)
                                $roleDashboardConfiguration[
                                    'greeting_name'
                                ]
                            ) ?>
                        </h1>

                        <p class="dashboard-welcome-description">
                            <?= e(
                                (string)
                                $roleDashboardConfiguration[
                                    'description'
                                ]
                            ) ?>
                        </p>

                        <div class="mt-4">
                            <a href="<?= e( base_url( (string) $roleDashboardConfiguration[ 'action_path' ] ) ) ?>" class="btn btn-primary">
                                <?= e(
                                    (string)
                                    $roleDashboardConfiguration[
                                        'action_label'
                                    ]
                                ) ?>
                            </a>
                        </div>
                    </div>

                    <div class="dashboard-period-card">
                        <span class="dashboard-period-icon" aria-hidden="true">
                            <?= $dashboardIcon(
                                'calendar',
                                23
                            ) ?>
                        </span>

                        <div class="min-w-0">
                            <small class="dashboard-period-label">
                                Periode Saat Ini
                            </small>

                            <strong class="dashboard-period-name">
                                <?= $activePeriod !== null
                                    ? e(
                                        (string)
                                        $activePeriod['name']
                                    )
                                    : 'Belum tersedia' ?>
                            </strong>

                            <span class="dashboard-period-date">
                                <?php if (
                                    $activePeriod !== null
                                ): ?>

                                    <?= e(
                                        format_date_id(
                                            $activePeriod[
                                                'start_date'
                                            ]
                                        )
                                    ) ?>

                                    sampai

                                    <?= e(
                                        format_date_id(
                                            $activePeriod[
                                                'end_date'
                                            ] ?? null
                                        )
                                    ) ?>

                                <?php else: ?>

                                    Buat periode untuk memulai alur.

                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Perlu perhatian -->
        <section class="dashboard-attention dashboard-attention-<?= e( (string) $attention['tone'] ) ?> mb-4" aria-labelledby="dashboard-attention-title">
            <span class="dashboard-attention-icon" aria-hidden="true">
                <?= $dashboardIcon(
                    (string) $attention['icon'],
                    23
                ) ?>
            </span>

            <div class="dashboard-attention-content">
                <small>
                    Perlu Perhatian
                </small>

                <h2 id="dashboard-attention-title" class="h6 mb-1">
                    <?= e(
                        (string) $attention['title']
                    ) ?>
                </h2>

                <p class="mb-0">
                    <?= e(
                        (string) $attention['message']
                    ) ?>
                </p>
            </div>

            <?php if (
                is_string(
                    $attention['action_label']
                )

                && is_string(
                    $attention['action_path']
                )
            ): ?>

                <a href="<?= e( base_url( (string) $attention['action_path'] ) ) ?>" class="btn btn-sm dashboard-attention-button">
                    <?= e(
                        (string)
                        $attention['action_label']
                    ) ?>
                </a>

            <?php endif; ?>
        </section>

        <!-- Alur proses -->
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="process-flow-title">
            <div class="card-header bg-white py-3">
                <h2 id="process-flow-title" class="h5 mb-1">
                    Alur Proses SPK
                </h2>

                <p class="small text-body-secondary mb-0">
                    Posisi proses saat ini dari periode
                    sampai tindak lanjut.
                </p>
            </div>

            <div class="card-body p-4">
                <div class="dashboard-process-flow">

                    <?php foreach (
                        $processSteps
                        as $stepIndex => $processStep
                    ): ?>

                        <article class="dashboard-process-step dashboard-process-step-<?= e( (string) $processStep['status'] ) ?>">
                            <div class="dashboard-process-marker">
                                <span class="dashboard-process-icon" aria-hidden="true">
                                    <?= $dashboardIcon(
                                        (string)
                                        $processStep['icon'],
                                        21
                                    ) ?>
                                </span>

                                <span class="dashboard-process-number">
                                    <?= e(
                                        (string) (
                                            $stepIndex + 1
                                        )
                                    ) ?>
                                </span>
                            </div>

                            <div class="dashboard-process-copy">
                                <strong>
                                    <?= e(
                                        (string)
                                        $processStep['title']
                                    ) ?>
                                </strong>

                                <span class="dashboard-process-status">
                                    <?= e(
                                        (string)
                                        $processStep[
                                            'status_label'
                                        ]
                                    ) ?>
                                </span>

                                <small>
                                    <?= e(
                                        (string)
                                        $processStep['detail']
                                    ) ?>
                                </small>
                            </div>
                        </article>

                    <?php endforeach; ?>

                </div>
            </div>
        </section>

        <!-- Ringkasan sesuai peran -->
        <section class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4" aria-label="Ringkasan sesuai peran">
            <?php foreach (
                $summaryCards
                as $summaryCard
            ): ?>

                <div class="col">
                    <article class="dashboard-stat-card h-100">
                        <span class="dashboard-stat-icon dashboard-stat-icon-<?= e( (string) $summaryCard['tone'] ) ?>" aria-hidden="true">
                            <?= $dashboardIcon(
                                (string)
                                $summaryCard['icon'],
                                24
                            ) ?>
                        </span>

                        <div class="dashboard-stat-content">
                            <span class="dashboard-stat-label">
                                <?= e(
                                    (string)
                                    $summaryCard['label']
                                ) ?>
                            </span>

                            <strong class="dashboard-stat-value">
                                <?= e(
                                    (string)
                                    $summaryCard['value']
                                ) ?>
                            </strong>

                            <small class="dashboard-stat-note">
                                <?= e(
                                    (string)
                                    $summaryCard['note']
                                ) ?>
                            </small>
                        </div>
                    </article>
                </div>

            <?php endforeach; ?>
        </section>

        <!-- Detail progres dan tindakan cepat dihilangkan dari dashboard
             agar halaman tetap ringkas. Navigasi modul tetap tersedia
             melalui sidebar dan informasi utama tetap ditampilkan di atas. -->

        <!-- Pemeriksaan terbaru -->
        <section class="card border-0 shadow-sm" aria-labelledby="recent-assessments-title">
            <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h2 id="recent-assessments-title" class="h5 mb-1">
                        Pemeriksaan Terbaru
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        <?= $currentRole === 'technician'
                            ? 'Pemeriksaan terbaru yang Anda kerjakan.'
                            : 'Aktivitas pemeriksaan terbaru pada periode saat ini.' ?>
                    </p>
                </div>

                <a href="<?= e( base_url( 'pemeriksaan/index.php' ) ) ?>" class="btn btn-primary btn-sm">
                    Lihat Semua
                </a>
            </div>

            <div class="card-body p-0">

                <?php if (
                    $recentAssessments === []
                ): ?>

                    <div class="dashboard-empty-state">
                        <span class="dashboard-empty-icon" aria-hidden="true">
                            <?= $dashboardIcon(
                                'clipboard',
                                25
                            ) ?>
                        </span>

                        <h3 class="h6 mb-2">
                            Belum ada pemeriksaan
                        </h3>

                        <p class="text-body-secondary mb-0">
                            Data pemeriksaan terbaru
                            akan tampil di sini.
                        </p>
                    </div>

                <?php else: ?>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>
                                        Motor
                                    </th>

                                    <th>
                                        Periode
                                    </th>

                                    <th>
                                        Pemeriksa
                                    </th>

                                    <th>
                                        Tanggal
                                    </th>

                                    <th class="text-center">
                                        Bahaya
                                    </th>

                                    <th class="text-center">
                                        Kelengkapan
                                    </th>

                                    <th>
                                        Status
                                    </th>

                                    <th class="text-end">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach (
                                    $recentAssessments
                                    as $assessment
                                ): ?>

                                    <?php

                                    $assessmentStatus =
                                        (string) (
                                            $assessment['status']
                                            ?? 'draft'
                                        );

                                    $statusConfiguration =
                                        $statusConfigurations[
                                            $assessmentStatus
                                        ]
                                        ?? [
                                            'label' =>
                                                'Tidak Diketahui',

                                            'class' =>
                                                'secondary',
                                        ];

                                    ?>

                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <span class="dashboard-motor-code">
                                                    <?= e(
                                                        (string)
                                                        $assessment[
                                                            'alternative_code'
                                                        ]
                                                    ) ?>
                                                </span>

                                                <strong class="d-block">
                                                    <?= e(
                                                        (string)
                                                        $assessment[
                                                            'motor_name'
                                                        ]
                                                    ) ?>
                                                </strong>
                                            </div>
                                        </td>

                                        <td>
                                            <?= e(
                                                (string)
                                                $assessment[
                                                    'period_name'
                                                ]
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                (string)
                                                $assessment[
                                                    'inspector_name'
                                                ]
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                format_date_id(
                                                    $assessment[
                                                        'inspection_date'
                                                    ]
                                                )
                                            ) ?>

                                            <small class="d-block text-body-secondary">
                                                <?= e(
                                                    substr(
                                                        (string)
                                                        $assessment[
                                                            'inspection_time'
                                                        ],
                                                        0,
                                                        5
                                                    )
                                                ) ?>

                                                WIB
                                            </small>
                                        </td>

                                        <td class="text-center">
                                            <?php if (
                                                (int)
                                                $assessment[
                                                    'danger_count'
                                                ] > 0
                                            ): ?>

                                                <span class="badge text-bg-danger">
                                                    <?= e(
                                                        (string) (int)
                                                        $assessment[
                                                            'danger_count'
                                                        ]
                                                    ) ?>
                                                </span>

                                            <?php else: ?>

                                                <span class="text-body-secondary">
                                                    0
                                                </span>

                                            <?php endif; ?>
                                        </td>

                                        <td class="text-center">
                                            <?php if (
                                                (int)
                                                $assessment[
                                                    'not_checked_count'
                                                ] > 0
                                            ): ?>

                                                <span class="badge text-bg-warning">
                                                    Belum lengkap
                                                </span>

                                            <?php else: ?>

                                                <span class="badge text-bg-success">
                                                    Lengkap
                                                </span>

                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <span class="badge text-bg-<?= e( (string) $statusConfiguration[ 'class' ] ) ?>">
                                                <?= e(
                                                    (string)
                                                    $statusConfiguration[
                                                        'label'
                                                    ]
                                                ) ?>
                                            </span>
                                        </td>

                                        <td class="text-end">
                                            <a href="<?= e( base_url( 'pemeriksaan/detail.php?id=' . (int) $assessment['id'] ) ) ?>" class="btn btn-sm btn-outline-secondary">
                                                Lihat
                                            </a>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

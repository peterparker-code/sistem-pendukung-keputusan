<?php

declare(strict_types=1);

/*
 * Daftar Riwayat Pemeliharaan
 * Menampilkan pemeriksaan yang telah divalidasi, hasil peringkat TOPSIS,
 * rekomendasi Supervisor, dan tindak lanjut pemeliharaan.
 * Hak akses:
 * - Administrator : melihat seluruh riwayat dalam mode baca;
 * - Supervisor    : melihat seluruh riwayat serta membuat atau mengubah
 * rekomendasi tindakan;
 * - Teknisi       : hanya melihat dan mencatat pelaksanaan tindakan yang
 * ditugaskan kepada akun Teknisi yang sedang login.
 * Sidebar dan tombol hanya mengatur tampilan. Pembatasan data tetap
 * diterapkan langsung pada query server.
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'helpers'
    . DIRECTORY_SEPARATOR . 'auth.php';

// Batasi Hak Akses

auth_require_role([
    'admin',
    'supervisor',
    'technician',
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

// Identitas Pengguna

$currentUserId = auth_id();
$currentRole = auth_role();

$isAdmin = $currentRole === 'admin';
$isSupervisor = $currentRole === 'supervisor';
$isTechnician = $currentRole === 'technician';

if (
    $currentUserId === null
    || $currentUserId < 1
) {
    flash_error(
        'Sesi pengguna tidak valid. Silakan masuk kembali.'
    );

    redirect('login.php');
}

// Konfigurasi Final Sistem

$expectedMachineCount = (int) app_config(
    'total_machines',
    6
);

$expectedAlternativeCodes = [
    'A1',
    'A2',
    'A3',
    'A4',
    'A5',
    'A6',
];

$allowedAssessmentStatuses = [
    'validated',
    'processed',
];

$allowedActionStatuses = [
    'pending',
    'in_progress',
    'completed',
];

$allowedActionPriorities = [
    'immediate',
    'scheduled',
    'monitoring',
];

// Ambil dan Validasi Filter

$search = trim(
    input_string(
        $_GET,
        'q',
        ''
    ) ?? ''
);

if (strlen($search) > 100) {
    $search = substr(
        $search,
        0,
        100
    );
}

$periodId = input_int(
    $_GET,
    'period_id'
);

if (
    $periodId !== null
    && $periodId < 1
) {
    $periodId = null;
}

$actionStatus = trim(
    input_string(
        $_GET,
        'status',
        ''
    ) ?? ''
);

$allowedActionStatusFilters = $isTechnician
    ? [
        '',
        'pending',
        'in_progress',
        'completed',
        'overdue',
    ]
    : [
        '',
        'waiting_topsis',
        'not_recorded',
        'pending',
        'in_progress',
        'completed',
        'overdue',
        'invalid_data',
    ];

if (!in_array(
    $actionStatus,
    $allowedActionStatusFilters,
    true
)) {
    $actionStatus = '';
}

// Nilai Awal Halaman

$pageError = null;
$periods = [];
$rows = [];

$summary = [
    'total' => 0,
    'waiting_topsis' => 0,
    'not_recorded' => 0,
    'in_process' => 0,
    'completed' => 0,
    'overdue' => 0,
    'invalid_data' => 0,
];

/*
 * Helper SQL untuk Status TOPSIS yang Valid
 * TOPSIS dianggap tersedia apabila:
 * - baris topsis_results tersedia;
 * - pemeriksaan sudah berstatus processed;
 * - D+, D-, nilai preferensi, ranking, dan rank_group tersedia;
 * - nilai preferensi berada pada rentang 0 sampai 1;
 * - ranking dan rank_group lebih besar dari nol.
 */

$validTopsisSql = "(
    tr.id IS NOT NULL
    AND a.status = 'processed'
    AND tr.positive_distance IS NOT NULL
    AND tr.negative_distance IS NOT NULL
    AND tr.preference_value IS NOT NULL
    AND tr.preference_value >= 0
    AND tr.preference_value <= 1
    AND tr.ranking IS NOT NULL
    AND tr.ranking >= 1
    AND tr.rank_group IS NOT NULL
    AND tr.rank_group >= 1
)";

// Ambil Data Riwayat

try {
    /*
 * Daftar Periode untuk Filter
 * Admin dan Supervisor melihat seluruh periode.
 * Teknisi hanya melihat periode yang mempunyai tindakan yang ditugaskan
 * kepada akunnya.
 */

    if ($isTechnician) {
        $periodStatement = $pdo->prepare(
            "SELECT DISTINCT
                ap.id,
                ap.name,
                ap.start_date,
                ap.end_date,
                ap.status

             FROM assessment_periods AS ap

             INNER JOIN assessments AS a
                ON a.period_id = ap.id

             INNER JOIN maintenance_actions AS ma
                ON ma.assessment_id = a.id

             WHERE ma.performed_by = :current_user_id

             ORDER BY
                ap.start_date DESC,
                ap.id DESC"
        );

        $periodStatement->execute([
            'current_user_id' => $currentUserId,
        ]);

        $periods = $periodStatement->fetchAll();
    } else {
        $periods = $pdo->query(
            "SELECT
                id,
                name,
                start_date,
                end_date,
                status

             FROM assessment_periods

             ORDER BY
                start_date DESC,
                id DESC"
        )->fetchAll();
    }

    // Validasi Periode terhadap Hak Akses

    if ($periodId !== null) {
        $periodFound = false;

        foreach ($periods as $period) {
            if ((int) ($period['id'] ?? 0) === $periodId) {
                $periodFound = true;
                break;
            }
        }

        if (!$periodFound) {
            $notFoundMessage = $isTechnician
                ? 'Periode tidak ditemukan atau tidak mempunyai tindakan yang ditugaskan kepada akun Teknisi ini.'
                : 'Periode riwayat pemeliharaan tidak ditemukan.';

            require dirname(__DIR__)
                . DIRECTORY_SEPARATOR . '404.php';

            exit;
        }
    }

    /*
 * Kondisi Dasar Pencarian
 * Riwayat hanya memuat pemeriksaan yang sudah validated atau processed.
 * Alternatif dibatasi pada A1 sampai A6.
 * Teknisi hanya memperoleh baris yang maintenance_actions.performed_by
 * sama dengan ID akunnya.
 */

    $baseWhere = [
        "a.status IN ('validated', 'processed')",
        "m.alternative_code IN (
            'A1',
            'A2',
            'A3',
            'A4',
            'A5',
            'A6'
        )",
    ];

    $baseParameters = [];

    if ($isTechnician) {
        $baseWhere[] = 'ma.performed_by = :current_user_id';

        $baseParameters['current_user_id'] =
            $currentUserId;
    }

    if ($search !== '') {
        $baseWhere[] = "(
            m.alternative_code LIKE :search_alternative
            OR m.motor_name LIKE :search_motor
            OR m.machine_code LIKE :search_machine
            OR p.name LIKE :search_period
            OR inspector.name LIKE :search_inspector
            OR COALESCE(performer.name, '') LIKE :search_performer
            OR COALESCE(ma.work_order_reference, '') LIKE :search_work_order
        )";

        $searchValue = '%' . $search . '%';

        $baseParameters['search_alternative'] =
            $searchValue;

        $baseParameters['search_motor'] =
            $searchValue;

        $baseParameters['search_machine'] =
            $searchValue;

        $baseParameters['search_period'] =
            $searchValue;

        $baseParameters['search_inspector'] =
            $searchValue;

        $baseParameters['search_performer'] =
            $searchValue;

        $baseParameters['search_work_order'] =
            $searchValue;
    }

    if ($periodId !== null) {
        $baseWhere[] = 'p.id = :period_id';

        $baseParameters['period_id'] =
            $periodId;
    }

    /*
 * Ringkasan Status
 * Ringkasan mengikuti kata kunci, periode, dan pembatasan peran, tetapi
 * tidak mengikuti filter status agar setiap kelompok tetap dapat
 * dibandingkan.
 * maintenance_action_map memastikan satu baris pemeriksaan tetap muncul
 * satu kali walaupun database lama pernah mempunyai tindakan ganda.
 */

    $summaryStatement = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,

            COALESCE(SUM(
                CASE
                    WHEN NOT {$validTopsisSql}
                    THEN 1
                    ELSE 0
                END
            ), 0) AS waiting_topsis,

            COALESCE(SUM(
                CASE
                    WHEN {$validTopsisSql}
                         AND COALESCE(action_map.action_record_count, 0) = 0
                    THEN 1
                    ELSE 0
                END
            ), 0) AS not_recorded,

            COALESCE(SUM(
                CASE
                    WHEN COALESCE(action_map.action_record_count, 0) = 1
                         AND ma.status IN (
                             'pending',
                             'in_progress'
                         )
                    THEN 1
                    ELSE 0
                END
            ), 0) AS in_process,

            COALESCE(SUM(
                CASE
                    WHEN COALESCE(action_map.action_record_count, 0) = 1
                         AND ma.status = 'completed'
                    THEN 1
                    ELSE 0
                END
            ), 0) AS completed,

            COALESCE(SUM(
                CASE
                    WHEN COALESCE(action_map.action_record_count, 0) = 1
                         AND ma.status <> 'completed'
                         AND ma.target_date IS NOT NULL
                         AND ma.target_date <> ''
                         AND date(ma.target_date) < date('now', '+7 hours')
                    THEN 1
                    ELSE 0
                END
            ), 0) AS overdue,

            COALESCE(SUM(
                CASE
                    WHEN COALESCE(action_map.action_record_count, 0) > 1
                         OR (
                             ma.id IS NOT NULL
                             AND (
                                 COALESCE(ma.priority, '') NOT IN (
                                     'immediate',
                                     'scheduled',
                                     'monitoring'
                                 )
                                 OR COALESCE(ma.status, '') NOT IN (
                                     'pending',
                                     'in_progress',
                                     'completed'
                                 )
                                 OR COALESCE(creator.role, '') <> 'supervisor'
                                 OR (
                                     ma.performed_by IS NOT NULL
                                     AND COALESCE(performer.role, '') <> 'technician'
                                 )
                             )
                         )
                    THEN 1
                    ELSE 0
                END
            ), 0) AS invalid_data

         FROM assessments AS a

         INNER JOIN assessment_periods AS p
            ON p.id = a.period_id

         INNER JOIN machines AS m
            ON m.id = a.machine_id

         INNER JOIN users AS inspector
            ON inspector.id = a.inspector_id

         LEFT JOIN topsis_results AS tr
            ON tr.assessment_id = a.id
           AND tr.period_id = a.period_id

         LEFT JOIN (
            SELECT
                assessment_id,
                COUNT(*) AS action_record_count,
                MAX(id) AS latest_action_id

            FROM maintenance_actions

            GROUP BY assessment_id
         ) AS action_map
            ON action_map.assessment_id = a.id

         LEFT JOIN maintenance_actions AS ma
            ON ma.id = action_map.latest_action_id

         LEFT JOIN users AS creator
            ON creator.id = ma.created_by

         LEFT JOIN users AS performer
            ON performer.id = ma.performed_by

         WHERE " . implode(
            ' AND ',
            $baseWhere
        )
    );

    $summaryStatement->execute(
        $baseParameters
    );

    $summaryRow = $summaryStatement->fetch();

    if (is_array($summaryRow)) {
        $summary = [
            'total' => (int) (
                $summaryRow['total']
                ?? 0
            ),

            'waiting_topsis' => (int) (
                $summaryRow['waiting_topsis']
                ?? 0
            ),

            'not_recorded' => (int) (
                $summaryRow['not_recorded']
                ?? 0
            ),

            'in_process' => (int) (
                $summaryRow['in_process']
                ?? 0
            ),

            'completed' => (int) (
                $summaryRow['completed']
                ?? 0
            ),

            'overdue' => (int) (
                $summaryRow['overdue']
                ?? 0
            ),

            'invalid_data' => (int) (
                $summaryRow['invalid_data']
                ?? 0
            ),
        ];
    }

    // Tambahkan Filter Status pada Daftar

    $listWhere = $baseWhere;
    $listParameters = $baseParameters;

    if ($actionStatus === 'waiting_topsis') {
        $listWhere[] = "NOT {$validTopsisSql}";
    } elseif ($actionStatus === 'not_recorded') {
        $listWhere[] = $validTopsisSql;
        $listWhere[] = 'COALESCE(action_map.action_record_count, 0) = 0';
    } elseif ($actionStatus === 'overdue') {
        $listWhere[] = 'COALESCE(action_map.action_record_count, 0) = 1';
        $listWhere[] = "ma.status <> 'completed'";
        $listWhere[] = "ma.target_date IS NOT NULL AND ma.target_date <> ''";
        $listWhere[] = "date(ma.target_date) < date('now', '+7 hours')";
    } elseif ($actionStatus === 'invalid_data') {
        $listWhere[] = "(
            COALESCE(action_map.action_record_count, 0) > 1
            OR (
                ma.id IS NOT NULL
                AND (
                    COALESCE(ma.priority, '') NOT IN (
                        'immediate',
                        'scheduled',
                        'monitoring'
                    )
                    OR COALESCE(ma.status, '') NOT IN (
                        'pending',
                        'in_progress',
                        'completed'
                    )
                    OR COALESCE(creator.role, '') <> 'supervisor'
                    OR (
                        ma.performed_by IS NOT NULL
                        AND COALESCE(performer.role, '') <> 'technician'
                    )
                )
            )
        )";
    } elseif ($actionStatus !== '') {
        $listWhere[] = 'COALESCE(action_map.action_record_count, 0) = 1';
        $listWhere[] = 'ma.status = :action_status';

        $listParameters['action_status'] =
            $actionStatus;
    }

    // Daftar Riwayat

    $statement = $pdo->prepare(
        "SELECT
            a.id AS assessment_id,
            a.inspection_date,
            a.inspection_time,
            a.status AS assessment_status,
            a.inspector_id,

            p.id AS period_id,
            p.name AS period_name,
            p.start_date AS period_start_date,
            p.end_date AS period_end_date,
            p.status AS period_status,

            m.id AS machine_id,
            m.alternative_code,
            m.machine_code,
            m.machine_number,
            m.motor_name,
            m.motor_function,

            inspector.name AS inspector_name,

            tr.id AS topsis_result_id,
            tr.positive_distance,
            tr.negative_distance,
            tr.ranking,
            tr.rank_group,
            tr.preference_value,
            tr.priority_label,
            tr.calculated_at AS topsis_calculated_at,

            COALESCE(
                action_map.action_record_count,
                0
            ) AS action_record_count,

            ma.id AS action_id,
            ma.recommendation,
            ma.priority AS action_priority,
            ma.status AS action_status,
            ma.target_date,
            ma.completed_date,
            ma.work_order_reference,
            ma.performed_by,
            ma.created_by,

            creator.name AS action_creator_name,
            creator.role AS action_creator_role,

            performer.name AS performed_by_name,
            performer.role AS performer_role

         FROM assessments AS a

         INNER JOIN assessment_periods AS p
            ON p.id = a.period_id

         INNER JOIN machines AS m
            ON m.id = a.machine_id

         INNER JOIN users AS inspector
            ON inspector.id = a.inspector_id

         LEFT JOIN topsis_results AS tr
            ON tr.assessment_id = a.id
           AND tr.period_id = a.period_id

         LEFT JOIN (
            SELECT
                assessment_id,
                COUNT(*) AS action_record_count,
                MAX(id) AS latest_action_id

            FROM maintenance_actions

            GROUP BY assessment_id
         ) AS action_map
            ON action_map.assessment_id = a.id

         LEFT JOIN maintenance_actions AS ma
            ON ma.id = action_map.latest_action_id

         LEFT JOIN users AS creator
            ON creator.id = ma.created_by

         LEFT JOIN users AS performer
            ON performer.id = ma.performed_by

         WHERE " . implode(
            ' AND ',
            $listWhere
        ) . "

         ORDER BY
            p.start_date DESC,
            p.id DESC,

            CASE
                WHEN {$validTopsisSql} THEN 0
                ELSE 1
            END,

            tr.ranking ASC,
            tr.rank_group ASC,
            tr.preference_value DESC,
            m.alternative_code COLLATE NOCASE ASC,
            a.id ASC"
    );

    $statement->execute(
        $listParameters
    );

    $rows = $statement->fetchAll();
} catch (Throwable $exception) {
    error_log(
        'Riwayat pemeliharaan gagal dimuat: '
        . $exception->getMessage()
    );

    $pageError = (bool) app_config(
        'debug',
        false
    )
        ? $exception->getMessage()
        : 'Data riwayat pemeliharaan belum dapat dimuat.';
}

// Helper Validasi Baris

$isTopsisAvailable = static function (
    array $row
): bool {
    $assessmentStatus = (string) (
        $row['assessment_status']
        ?? ''
    );

    $positiveDistance = $row['positive_distance']
        ?? null;

    $negativeDistance = $row['negative_distance']
        ?? null;

    $preferenceValue = $row['preference_value']
        ?? null;

    $ranking = $row['ranking']
        ?? null;

    $rankGroup = $row['rank_group']
        ?? null;

    if (
        ($row['topsis_result_id'] ?? null) === null
        || $assessmentStatus !== 'processed'
        || !is_numeric((string) $positiveDistance)
        || !is_numeric((string) $negativeDistance)
        || !is_numeric((string) $preferenceValue)
        || !is_numeric((string) $ranking)
        || !is_numeric((string) $rankGroup)
    ) {
        return false;
    }

    $positiveDistance = (float) $positiveDistance;
    $negativeDistance = (float) $negativeDistance;
    $preferenceValue = (float) $preferenceValue;
    $ranking = (int) $ranking;
    $rankGroup = (int) $rankGroup;

    return is_finite($positiveDistance)
        && is_finite($negativeDistance)
        && is_finite($preferenceValue)
        && $positiveDistance >= 0
        && $negativeDistance >= 0
        && $preferenceValue >= 0
        && $preferenceValue <= 1
        && $ranking >= 1
        && $rankGroup >= 1;
};

$isActionStructurallyValid = static function (
    array $row
) use (
    $allowedActionStatuses,
    $allowedActionPriorities
): bool {
    $actionCount = (int) (
        $row['action_record_count']
        ?? 0
    );

    if ($actionCount === 0) {
        return true;
    }

    if (
        $actionCount !== 1
        || ($row['action_id'] ?? null) === null
    ) {
        return false;
    }

    $actionPriority = (string) (
        $row['action_priority']
        ?? ''
    );

    $actionStatus = (string) (
        $row['action_status']
        ?? ''
    );

    $creatorRole = (string) (
        $row['action_creator_role']
        ?? ''
    );

    $performedBy = $row['performed_by']
        ?? null;

    $performerRole = (string) (
        $row['performer_role']
        ?? ''
    );

    if (
        !in_array(
            $actionPriority,
            $allowedActionPriorities,
            true
        )
        || !in_array(
            $actionStatus,
            $allowedActionStatuses,
            true
        )
        || $creatorRole !== 'supervisor'
    ) {
        return false;
    }

    if (
        $performedBy !== null
        && (int) $performedBy > 0
        && $performerRole !== 'technician'
    ) {
        return false;
    }

    return true;
};

// Metadata Tampilan Status Tindakan

$actionStatusMeta = static function (
    ?string $status,
    bool $hasTopsis,
    bool $hasAction,
    bool $actionValid
): array {
    if (!$hasTopsis) {
        return [
            'label' => 'Menunggu TOPSIS',
            'class' => 'dark',
        ];
    }

    if (!$actionValid) {
        return [
            'label' => 'Data Perlu Dicek',
            'class' => 'danger',
        ];
    }

    if (!$hasAction) {
        return [
            'label' => 'Belum Dicatat',
            'class' => 'secondary',
        ];
    }

    return match ($status) {
        'pending' => [
            'label' => 'Menunggu',
            'class' => 'warning',
        ],

        'in_progress' => [
            'label' => 'Dikerjakan',
            'class' => 'info',
        ],

        'completed' => [
            'label' => 'Selesai',
            'class' => 'success',
        ],

        default => [
            'label' => 'Data Perlu Dicek',
            'class' => 'danger',
        ],
    };
};

// Metadata Prioritas Tindakan

$priorityMeta = static function (
    ?string $priority
): array {
    return match ($priority) {
        'immediate' => [
            'label' => 'Segera',
            'class' => 'danger',
        ],

        'scheduled' => [
            'label' => 'Terjadwal',
            'class' => 'warning',
        ],

        'monitoring' => [
            'label' => 'Monitoring',
            'class' => 'info',
        ],

        default => [
            'label' => '-',
            'class' => 'secondary',
        ],
    };
};

// Metadata Kelompok Peringkat

$rankGroupMeta = static function (
    mixed $rankGroup
): array {
    $group = is_numeric((string) $rankGroup)
        ? (int) $rankGroup
        : null;

    return match ($group) {
        1 => [
            'label' => 'Prioritas Utama',
            'class' => 'danger',
        ],

        2 => [
            'label' => 'Prioritas Tinggi',
            'class' => 'warning',
        ],

        3 => [
            'label' => 'Prioritas Menengah',
            'class' => 'info',
        ],

        4, 5, 6 => [
            'label' => 'Prioritas Berikutnya',
            'class' => 'secondary',
        ],

        default => [
            'label' => 'Belum Diperingkat',
            'class' => 'secondary',
        ],
    };
};

$todayDate = date('Y-m-d');

// Konfigurasi Halaman

$pageTitle = 'Riwayat Pemeliharaan';

$pageDescription = $isTechnician
    ? 'Tindakan pemeliharaan yang ditugaskan kepada akun Teknisi yang sedang login.'
    : 'Riwayat pemeriksaan, peringkat TOPSIS, rekomendasi, dan tindak lanjut pemeliharaan motor.';

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

        <?php if ($pageError !== null): ?>
            <div class="alert alert-danger" role="alert">
                <strong class="d-block mb-1">
                    Data belum dapat dimuat
                </strong>

                <?= e($pageError) ?>
            </div>
        <?php endif; ?>

        <section class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <h1 class="page-title mb-1">
                    Riwayat Pemeliharaan
                </h1>

                <p class="page-description mb-0">
                    <?= e($pageDescription) ?>
                </p>
            </div>

            <span class="badge text-bg-light border p-2">
                Akses <?= e(
                    auth_role_label($currentRole)
                ) ?>
            </span>
        </section>

        <?php if ($isAdmin): ?>
            <div class="alert alert-secondary mb-4" role="status">
                Administrator dapat melihat seluruh riwayat,
                hasil TOPSIS, rekomendasi, dan realisasi tindakan.
                Administrator tidak dapat membuat atau mengubah tindakan.
            </div>
        <?php elseif ($isSupervisor): ?>
            <div class="alert alert-info mb-4" role="status">
                Supervisor membuat rekomendasi setelah hasil TOPSIS tersedia,
                menetapkan prioritas serta Teknisi pelaksana, kemudian
                memantau realisasi tindak lanjut.
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-4" role="status">
                Teknisi hanya melihat tindakan yang secara langsung
                ditugaskan kepada akun ini melalui kolom pelaksana.
                Tindakan yang belum ditugaskan tidak ditampilkan meskipun
                parameter URL diubah.
            </div>
        <?php endif; ?>

        <?php
        $cards = $isTechnician
            ? [
                [
                    'label' => 'Tugas Saya',
                    'value' => $summary['total'],
                    'note' => 'Ditugaskan Supervisor',
                ],
                [
                    'label' => 'Menunggu',
                    'value' => $summary['in_process'],
                    'note' => $summary['overdue'] > 0 ? $summary['overdue'] . ' terlambat' : 'Menunggu atau dikerjakan',
                ],
                [
                    'label' => 'Selesai',
                    'value' => $summary['completed'],
                    'note' => 'Tindakan telah ditutup',
                ],
                [
                    'label' => 'Data Perlu Dicek',
                    'value' => $summary['invalid_data'],
                    'note' => 'Hubungi Supervisor/Admin',
                ],
            ]
            : [
                [
                    'label' => 'Total Riwayat',
                    'value' => $summary['total'],
                    'note' => 'Pemeriksaan disetujui',
                ],
                [
                    'label' => 'Menunggu TOPSIS',
                    'value' => $summary['waiting_topsis'],
                    'note' => 'Belum memiliki peringkat',
                ],
                [
                    'label' => 'Belum Dicatat',
                    'value' => $summary['not_recorded'],
                    'note' => 'Menunggu rekomendasi',
                ],
                [
                    'label' => 'Dalam Proses',
                    'value' => $summary['in_process'],
                    'note' => $summary['overdue'] > 0 ? $summary['overdue'] . ' terlambat' : 'Menunggu atau dikerjakan',
                ],
                [
                    'label' => 'Selesai',
                    'value' => $summary['completed'],
                    'note' => 'Tindakan telah ditutup',
                ],
                [
                    'label' => 'Data Perlu Dicek',
                    'value' => $summary['invalid_data'],
                    'note' => 'Struktur tindakan tidak valid',
                ],
            ];
        ?>

        <section class="<?= $isTechnician ? 'row row-cols-2 row-cols-xl-4 g-3 mb-4' : 'row row-cols-2 row-cols-md-3 row-cols-xl-6 g-3 mb-4' ?>" aria-label="Ringkasan tindak lanjut">
            <?php foreach ($cards as $card): ?>
                <div class="col">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <p class="small text-body-secondary mb-2">
                                <?= e($card['label']) ?>
                            </p>

                            <p class="display-6 fw-semibold mb-1">
                                <?= e($card['value']) ?>
                            </p>

                            <small class="text-body-secondary">
                                <?= e($card['note']) ?>
                            </small>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </section>

        <?php if ($summary['invalid_data'] > 0): ?>
            <div class="alert alert-warning mb-4" role="status">
                Terdapat
                <strong><?= e($summary['invalid_data']) ?></strong>
                riwayat dengan tindakan ganda, status tidak valid,
                pembuat bukan Supervisor, atau pelaksana bukan Teknisi.
                Data tersebut perlu diperiksa sebelum diperbarui.
            </div>
        <?php endif; ?>

        <section class="card border-0 shadow-sm mb-4" aria-labelledby="history-filter-title">
            <div class="card-body">
                <h2 id="history-filter-title" class="h6 mb-3">
                    Cari dan Saring Riwayat
                </h2>

                <form method="get" action="<?= e(base_url( 'riwayat/index.php' )) ?>" class="row g-3 align-items-end">
                    <div class="col-xl-4">
                        <label for="q" class="form-label">
                            Kata Kunci
                        </label>

                        <input type="search" id="q" name="q" class="form-control" value="<?= e($search) ?>" maxlength="100" placeholder="A1, motor, kode mesin, periode, Teknisi, atau WO">
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <label for="period_id" class="form-label">
                            Periode
                        </label>

                        <select id="period_id" name="period_id" class="form-select">
                            <option value="">
                                Semua Periode
                            </option>

                            <?php foreach (
                                $periods
                                as $period
                            ): ?>
                                <option value="<?= e( $period['id'] ) ?>" <?= selected( $periodId, (int) $period['id'] ) ?>>
                                    <?= e(
                                        $period['name']
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <label for="status" class="form-label">
                            Status Tindakan
                        </label>

                        <select id="status" name="status" class="form-select">
                            <option value="">
                                Semua Status
                            </option>

                            <?php if (!$isTechnician): ?>
                                <option value="waiting_topsis" <?= selected( $actionStatus, 'waiting_topsis' ) ?>>
                                    Menunggu TOPSIS
                                </option>

                                <option value="not_recorded" <?= selected( $actionStatus, 'not_recorded' ) ?>>
                                    Belum Dicatat
                                </option>
                            <?php endif; ?>

                            <option value="pending" <?= selected( $actionStatus, 'pending' ) ?>>
                                Menunggu
                            </option>

                            <option value="in_progress" <?= selected( $actionStatus, 'in_progress' ) ?>>
                                Dikerjakan
                            </option>

                            <option value="completed" <?= selected( $actionStatus, 'completed' ) ?>>
                                Selesai
                            </option>

                            <option value="overdue" <?= selected( $actionStatus, 'overdue' ) ?>>
                                Terlambat
                            </option>

                            <?php if (!$isTechnician): ?>
                                <option value="invalid_data" <?= selected( $actionStatus, 'invalid_data' ) ?>>
                                    Data Perlu Dicek
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="col-xl-2">
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                Terapkan
                            </button>

                            <a href="<?= e(base_url( 'riwayat/index.php' )) ?>" class="btn btn-outline-secondary">
                                Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <section class="card border-0 shadow-sm" aria-labelledby="history-list-title">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center gap-2">
                <div>
                    <h2 id="history-list-title" class="h5 mb-1">
                        <?= $isTechnician
                            ? 'Daftar Tindakan Saya'
                            : 'Daftar Riwayat' ?>
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        <?= e(count($rows)) ?>
                        data sesuai filter.
                    </p>
                </div>
            </div>

            <div class="card-body p-0">
                <?php if ($rows === []): ?>
                    <div class="text-center text-body-secondary p-5">
                        <?= $isTechnician
                            ? 'Belum ada tindakan yang ditugaskan kepada akun Teknisi ini.'
                            : 'Belum ada riwayat pemeriksaan yang sesuai.' ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Periode</th>
                                    <th>Motor</th>
                                    <th>Pemeriksaan</th>

                                    <th class="text-center">
                                        Peringkat
                                    </th>

                                    <th>Status Tindakan</th>
                                    <th>Target</th>

                                    <th class="text-end">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach (
                                    $rows
                                    as $row
                                ): ?>
                                    <?php
                                    $hasTopsis =
                                        $isTopsisAvailable($row);

                                    $actionRecordCount = (int) (
                                        $row['action_record_count']
                                        ?? 0
                                    );

                                    $hasAction =
                                        $actionRecordCount === 1
                                        && $row['action_id'] !== null;

                                    $actionValid =
                                        $isActionStructurallyValid($row);

                                    $statusInfo =
                                        $actionStatusMeta(
                                            $row['action_status'] !== null
                                                ? (string) $row['action_status']
                                                : null,
                                            $hasTopsis,
                                            $hasAction,
                                            $actionValid
                                        );

                                    $priorityInfo =
                                        $priorityMeta(
                                            $row['action_priority'] !== null
                                                ? (string) $row['action_priority']
                                                : null
                                        );

                                    $rankInfo =
                                        $rankGroupMeta(
                                            $row['rank_group']
                                            ?? null
                                        );

                                    $assignedTechnicianId =
                                        $row['performed_by'] !== null
                                            ? (int) $row['performed_by']
                                            : null;

                                    $technicianCanEdit =
                                        $isTechnician
                                        && $hasAction
                                        && $actionValid
                                        && $assignedTechnicianId
                                            === $currentUserId;

                                    $supervisorCanEdit =
                                        $isSupervisor
                                        && $hasTopsis
                                        && $actionValid;
                                    ?>

                                    <tr class="<?= !$actionValid ? 'table-danger' : '' ?>">
                                        <td>
                                            <strong class="d-block">
                                                <?= e(
                                                    $row['period_name']
                                                ) ?>
                                            </strong>

                                            <small class="text-body-secondary">
                                                <?= e(format_date_id(
                                                    $row[
                                                        'period_start_date'
                                                    ]
                                                )) ?>
                                            </small>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= e(
                                                    $row[
                                                        'alternative_code'
                                                    ]
                                                ) ?>
                                            </strong>

                                            <span class="text-body-secondary">
                                                —
                                                <?= e(
                                                    $row['motor_name']
                                                ) ?>
                                            </span>

                                            <small class="d-block text-body-secondary">
                                                <?= e(
                                                    $row['machine_code']
                                                ) ?>
                                            </small>
                                        </td>

                                        <td>
                                            <?= e(format_date_id(
                                                $row['inspection_date']
                                            )) ?>

                                            <small class="d-block text-body-secondary">
                                                oleh
                                                <?= e(
                                                    $row['inspector_name']
                                                ) ?>
                                            </small>
                                        </td>

                                        <td class="text-center">
                                            <?php if ($hasTopsis): ?>
                                                <span class="badge text-bg-primary">
                                                    #<?= e(
                                                        $row['ranking']
                                                    ) ?>
                                                </span>

                                                <span class="badge text-bg-<?= e( $rankInfo['class'] ) ?> ms-1">
                                                    Kelompok
                                                    <?= e(
                                                        $row['rank_group']
                                                    ) ?>
                                                </span>

                                                <small class="d-block text-body-secondary mt-1">
                                                    V =
                                                    <?= e(format_number_id(
                                                        $row[
                                                            'preference_value'
                                                        ],
                                                        4
                                                    )) ?>
                                                </small>

                                                <small class="d-block text-body-secondary">
                                                    <?= e(
                                                        $row['priority_label']
                                                        ?? $rankInfo['label']
                                                    ) ?>
                                                </small>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary">
                                                    Belum tersedia
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <span class="badge text-bg-<?= e( $statusInfo['class'] ) ?>">
                                                <?= e(
                                                    $statusInfo['label']
                                                ) ?>
                                            </span>

                                            <?php if (
                                                $hasAction
                                                && $actionValid
                                            ): ?>
                                                <span class="badge text-bg-<?= e( $priorityInfo['class'] ) ?> ms-1">
                                                    <?= e(
                                                        $priorityInfo['label']
                                                    ) ?>
                                                </span>
                                            <?php endif; ?>

                                            <?php if (
                                                !empty(
                                                    $row[
                                                        'performed_by_name'
                                                    ]
                                                )
                                            ): ?>
                                                <small class="d-block text-body-secondary mt-1">
                                                    Pelaksana:
                                                    <?= e(
                                                        $row[
                                                            'performed_by_name'
                                                        ]
                                                    ) ?>
                                                </small>
                                            <?php endif; ?>

                                            <?php if (
                                                !empty(
                                                    $row[
                                                        'work_order_reference'
                                                    ]
                                                )
                                            ): ?>
                                                <small class="d-block text-body-secondary">
                                                    WO:
                                                    <?= e(
                                                        $row[
                                                            'work_order_reference'
                                                        ]
                                                    ) ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if (
                                                !empty(
                                                    $row['target_date']
                                                )
                                            ): ?>
                                                <?= e(format_date_id(
                                                    $row['target_date']
                                                )) ?>
                                                <?php
                                                $rowTargetDate = (string) ($row['target_date'] ?? '');
                                                $rowActionStatus = (string) ($row['action_status'] ?? '');
                                                $rowIsOverdue = $hasAction && $actionValid && $rowActionStatus !== 'completed'
                                                    && $rowTargetDate !== '' && $rowTargetDate < $todayDate;
                                                if ($rowIsOverdue):
                                                    $targetTimestamp = strtotime($rowTargetDate . ' 00:00:00');
                                                    $todayTimestamp = strtotime($todayDate . ' 00:00:00');
                                                    $overdueDays = ($targetTimestamp !== false && $todayTimestamp !== false)
                                                        ? max(1, (int) floor(($todayTimestamp - $targetTimestamp) / 86400))
                                                        : 1;
                                                ?>
                                                    <span class="badge text-bg-danger d-block mt-1" style="width: fit-content;">
                                                        Terlambat <?= e((string) $overdueDays) ?> hari
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-body-secondary">
                                                    -
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-end">
                                            <div class="history-action-buttons">
                                                <a href="<?= e(base_url( 'riwayat/detail.php?id=' . (int) $row[ 'assessment_id' ] )) ?>" class="btn btn-sm btn-outline-primary">
                                                    Lihat
                                                </a>

                                                <?php if (!$actionValid): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" disabled title="Data tindakan perlu diperiksa">
                                                        Perlu Dicek
                                                    </button>

                                                <?php elseif (!$hasTopsis): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Hasil TOPSIS belum tersedia">
                                                        Menunggu TOPSIS
                                                    </button>

                                                <?php elseif ($isAdmin): ?>
                                                    <span class="badge text-bg-light border align-self-center px-3 py-2">
                                                        Hanya Lihat
                                                    </span>

                                                <?php elseif (
                                                    $supervisorCanEdit
                                                ): ?>
                                                    <a href="<?= e(base_url( 'riwayat/tindakan.php?id=' . (int) $row[ 'assessment_id' ] )) ?>" class="btn btn-sm btn-primary">
                                                        <?= $hasAction
                                                            ? 'Ubah Tindakan'
                                                            : 'Buat Tindakan' ?>
                                                    </a>

                                                <?php elseif (
                                                    $technicianCanEdit
                                                ): ?>
                                                    <a href="<?= e(base_url( 'riwayat/tindakan.php?id=' . (int) $row[ 'assessment_id' ] )) ?>" class="btn btn-sm btn-primary">
                                                        <?= (string) (
                                                            $row[
                                                                'action_status'
                                                            ]
                                                            ?? ''
                                                        ) === 'completed'
                                                            ? 'Perbarui Catatan'
                                                            : 'Catat Pelaksanaan' ?>
                                                    </a>

                                                <?php elseif ($isTechnician): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Tindakan tidak ditugaskan kepada akun ini">
                                                        Tidak Ditugaskan
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <div class="alert alert-light border mt-4 mb-0" role="status">
            <strong>Catatan:</strong>

            <?php if ($isSupervisor): ?>
                rekomendasi hanya dapat dibuat setelah hasil TOPSIS valid.
                Supervisor wajib menetapkan prioritas dan Teknisi pelaksana.
            <?php elseif ($isTechnician): ?>
                pelaksanaan hanya dapat diperbarui oleh Teknisi yang tercatat
                pada kolom <em>performed_by</em> untuk tindakan tersebut.
            <?php else: ?>
                Administrator dapat memantau seluruh riwayat, tetapi tidak
                dapat mengubah rekomendasi atau pelaksanaan tindakan.
            <?php endif; ?>
        </div>
    </main>
</div>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

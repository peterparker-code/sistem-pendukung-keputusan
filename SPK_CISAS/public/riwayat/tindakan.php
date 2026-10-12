<?php

declare(strict_types=1);

/*
 * Form dan Proses Tindak Lanjut Pemeliharaan
 * Supervisor membuat rekomendasi, menentukan prioritas, target tanggal,
 * dan Teknisi pelaksana. Teknisi mencatat pekerjaan serta penyelesaiannya.
 * Tampilan form dan proses POST disatukan di dalam file ini.
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'helpers'
    . DIRECTORY_SEPARATOR . 'auth.php';

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'helpers'
    . DIRECTORY_SEPARATOR . 'audit.php';

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'services'
    . DIRECTORY_SEPARATOR . 'WorkflowService.php';

// Batasi Akses Pengguna

auth_require_role([
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

// Identitas Pengguna yang Login

$currentUserId = auth_id();
$currentRole = auth_role();

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

// Konfigurasi Final Tindak Lanjut

$allowedActionPriorities = [
    'immediate',
    'scheduled',
    'monitoring',
];

$allowedActionStatuses = [
    'pending',
    'in_progress',
    'completed',
];

/*
 * Validasi ID Pemeriksaan
 * Saat halaman dibuka, ID diperoleh dari GET.
 * Saat form dikirim, ID diperoleh dari POST.
 */

$assessmentIdInput = is_post()
    ? ($_POST['assessment_id'] ?? null)
    : ($_GET['id'] ?? null);

$assessmentId = filter_var(
    $assessmentIdInput,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if (
    $assessmentId === false
    || $assessmentId === null
) {
    flash_error(
        'ID riwayat pemeriksaan tidak valid.'
    );

    redirect('riwayat/index.php');
}

$assessmentId = (int) $assessmentId;

// Nama Token CSRF

$csrfFormName =
    'maintenance_action_' . $assessmentId;

/*
 * Ambil Pemeriksaan, Hasil TOPSIS, dan Tindakan
 * Admin tidak dapat membuka halaman ini karena role sudah dibatasi.
 * Supervisor dapat membuka seluruh riwayat yang sudah disetujui.
 * Teknisi hanya dapat membuka tindakan yang benar-benar ditugaskan
 * kepada akunnya melalui maintenance_actions.performed_by.
 */

$detailWhere = [
    'a.id = :assessment_id',

    "a.status IN (
        'validated',
        'processed'
    )",

    "m.alternative_code IN (
        'A1',
        'A2',
        'A3',
        'A4',
        'A5',
        'A6'
    )",
];

$detailParameters = [
    'assessment_id' => $assessmentId,
];

if ($isTechnician) {
    $detailWhere[] =
        'ma.performed_by = :current_user_id';

    $detailParameters[
        'current_user_id'
    ] = $currentUserId;
}

$assessmentStatement = $pdo->prepare(
    "SELECT
        a.id AS assessment_id,
        a.inspection_date,
        a.status AS assessment_status,

        p.id AS period_id,
        p.name AS period_name,
        p.status AS period_status,

        m.id AS machine_id,
        m.alternative_code,
        m.machine_code,
        m.motor_name,
        m.motor_function,

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
        ma.priority,
        ma.action_description,
        ma.target_date,
        ma.status,
        ma.completed_date,
        ma.additional_note,
        ma.work_order_reference,
        ma.created_by,
        ma.performed_by,
        ma.created_at AS action_created_at,
        ma.updated_at AS action_updated_at,

        creator.name AS action_creator_name,
        creator.role AS action_creator_role,

        performer.name AS performed_by_name,
        performer.role AS performer_role

     FROM assessments AS a

     INNER JOIN assessment_periods AS p
        ON p.id = a.period_id

     INNER JOIN machines AS m
        ON m.id = a.machine_id

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
        $detailWhere
    ) . "

     LIMIT 1"
);

$assessmentStatement->execute(
    $detailParameters
);

$assessment = $assessmentStatement->fetch();

if (!is_array($assessment)) {
    flash_error(
        $isTechnician
            ? 'Tindak lanjut tidak ditemukan atau tidak ditugaskan kepada akun Teknisi ini.'
            : 'Pemeriksaan tidak ditemukan atau belum divalidasi.'
    );

    redirect('riwayat/index.php');
}

// Validasi Struktur TOPSIS

$positiveDistance =
    $assessment['positive_distance']
    ?? null;

$negativeDistance =
    $assessment['negative_distance']
    ?? null;

$preferenceValue =
    $assessment['preference_value']
    ?? null;

$ranking =
    $assessment['ranking']
    ?? null;

$rankGroup =
    $assessment['rank_group']
    ?? null;

$hasValidTopsis =
    $assessment['topsis_result_id'] !== null

    && (string) (
        $assessment['assessment_status']
        ?? ''
    ) === 'processed'

    && is_numeric(
        (string) $positiveDistance
    )

    && is_numeric(
        (string) $negativeDistance
    )

    && is_numeric(
        (string) $preferenceValue
    )

    && is_numeric(
        (string) $ranking
    )

    && is_numeric(
        (string) $rankGroup
    )

    && is_finite(
        (float) $positiveDistance
    )

    && is_finite(
        (float) $negativeDistance
    )

    && is_finite(
        (float) $preferenceValue
    )

    && (float) $positiveDistance >= 0

    && (float) $negativeDistance >= 0

    && (float) $preferenceValue >= 0

    && (float) $preferenceValue <= 1

    && (int) $ranking >= 1

    && (int) $rankGroup >= 1;

// Validasi Satu Tindakan per Pemeriksaan

$actionRecordCount =
    (int) (
        $assessment['action_record_count']
        ?? 0
    );

if ($actionRecordCount > 1) {
    flash_error(
        'Terdapat lebih dari satu tindakan pemeliharaan '
        . 'untuk pemeriksaan ini. Perbaiki data ganda '
        . 'sebelum melanjutkan.'
    );

    redirect(
        'riwayat/detail.php?id='
        . $assessmentId
    );
}

$hasExistingAction =
    $actionRecordCount === 1

    && $assessment['action_id']
        !== null;

// Validasi Struktur Tindakan yang Sudah Ada

if ($hasExistingAction) {
    $storedPriority = (string) (
        $assessment['priority']
        ?? ''
    );

    $storedStatus = (string) (
        $assessment['status']
        ?? ''
    );

    $storedRecommendation = trim(
        (string) (
            $assessment['recommendation']
            ?? ''
        )
    );

    $storedCreatorRole = (string) (
        $assessment['action_creator_role']
        ?? ''
    );

    $storedPerformedBy =
        $assessment['performed_by'] !== null
            ? (int) $assessment['performed_by']
            : null;

    $storedPerformerRole = (string) (
        $assessment['performer_role']
        ?? ''
    );

    $existingActionValid =
        $storedRecommendation !== ''

        && in_array(
            $storedPriority,
            $allowedActionPriorities,
            true
        )

        && in_array(
            $storedStatus,
            $allowedActionStatuses,
            true
        )

        && $storedCreatorRole
            === 'supervisor'

        && $storedPerformedBy !== null

        && $storedPerformedBy > 0

        && $storedPerformerRole
            === 'technician';

    if (!$existingActionValid) {
        flash_error(
            'Data tindakan pemeliharaan tidak sesuai '
            . 'dengan pembagian peran atau status final.'
        );

        redirect(
            'riwayat/detail.php?id='
            . $assessmentId
        );
    }
}

/*
 * Tindak Lanjut Hanya Setelah Perhitungan TOPSIS
 * Rekomendasi pemeliharaan harus mengacu pada hasil TOPSIS yang lengkap
 * dan valid, bukan hanya keberadaan satu baris topsis_results.
 */

if (!$hasValidTopsis) {
    flash_warning(
        'Tindak lanjut belum dapat dibuat karena '
        . 'hasil TOPSIS belum lengkap atau belum valid.'
    );

    redirect(
        'riwayat/detail.php?id='
        . $assessmentId
    );
}

// Teknisi Menunggu Rekomendasi Supervisor

if (
    $isTechnician
    && !$hasExistingAction
) {
    flash_warning(
        'Supervisor harus membuat rekomendasi '
        . 'terlebih dahulu.'
    );

    redirect(
        'riwayat/detail.php?id='
        . $assessmentId
    );
}

// Batasi Teknisi Berdasarkan Penugasan

$assignedTechnicianId =
    $assessment['performed_by'] !== null
        ? (int) $assessment['performed_by']
        : null;

if (
    $isTechnician
    && $assignedTechnicianId
        !== $currentUserId
) {
    flash_error(
        'Tindak lanjut ini tidak ditugaskan '
        . 'kepada akun Teknisi yang sedang login.'
    );

    redirect('403.php');
}

/*
 * Ambil Daftar Teknisi Aktif
 * Supervisor dapat memilih Teknisi pelaksana dari seluruh akun Teknisi aktif.
 * Teknisi yang sudah tidak bertugas dapat dinonaktifkan melalui menu Pengguna.
 */

$technicianStatement = $pdo->query(
    "SELECT
        id,
        name,
        username

     FROM users

     WHERE role = 'technician'
       AND is_active = 1

     ORDER BY name COLLATE NOCASE, id"
);

$activeTechnicians = $technicianStatement->fetchAll();

if ($isSupervisor && $activeTechnicians === []) {
    flash_error(
        'Belum ada Teknisi Maintenance aktif. Tambahkan atau aktifkan Teknisi terlebih dahulu.'
    );

    redirect(
        'riwayat/detail.php?id=' . $assessmentId
    );
}

$activeTechniciansById = [];

foreach ($activeTechnicians as $technicianRow) {
    $activeTechniciansById[(int) $technicianRow['id']] = $technicianRow;
}

// Fungsi Bantuan Validasi

$addError = static function (
    array &$errors,
    string $field,
    string $message
): void {
    $errors[$field][] = $message;
};

$validDate = static function (
    ?string $value
): bool {
    if (
        $value === null
        || $value === ''
    ) {
        return true;
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $value
    );

    $dateErrors =
        DateTimeImmutable::getLastErrors();

    $hasDateErrors = is_array($dateErrors)
        && (
            (int) (
                $dateErrors['warning_count']
                ?? 0
            ) > 0

            || (int) (
                $dateErrors['error_count']
                ?? 0
            ) > 0
        );

    return $date !== false
        && !$hasDateErrors
        && $date->format('Y-m-d') === $value;
};

// Proses Simpan atau Ubah Tindakan

if (is_post()) {
    // Ambil Input Form

    $recommendation = trim(
        input_string(
            $_POST,
            'recommendation',
            ''
        ) ?? ''
    );

    $priority = trim(
        input_string(
            $_POST,
            'priority',
            ''
        ) ?? ''
    );

    $actionDescription = trim(
        input_string(
            $_POST,
            'action_description',
            ''
        ) ?? ''
    );

    $targetDate = input_string(
        $_POST,
        'target_date'
    );

    $status = trim(
        input_string(
            $_POST,
            'status',
            ''
        ) ?? ''
    );

    $completedDate = input_string(
        $_POST,
        'completed_date'
    );

    $additionalNote = input_string(
        $_POST,
        'additional_note'
    );

    $workOrderReference = input_string(
        $_POST,
        'work_order_reference'
    );

    /*
    | Supervisor memilih Teknisi aktif. Teknisi sendiri tetap menggunakan
    | akun login dan tidak dapat mengubah penugasannya.
    */
    $performedBy = $isSupervisor
        ? filter_var(
            $_POST['performed_by'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        )
        : $currentUserId;

    if ($performedBy === false) {
        $performedBy = null;
    }

    // Periksa Token CSRF

    if (!csrf_validate_request($csrfFormName)) {
        flash_error(
            'Token keamanan tidak valid atau telah '
            . 'kedaluwarsa. Silakan isi kembali formulir.'
        );

        flash_set_old_input($_POST);

        redirect(
            'riwayat/tindakan.php?id='
            . $assessmentId
        );
    }

    // Normalisasi Input Opsional

    $targetDate =
        $targetDate !== null
        && trim($targetDate) !== ''
            ? trim($targetDate)
            : null;

    $completedDate =
        $completedDate !== null
        && trim($completedDate) !== ''
            ? trim($completedDate)
            : null;

    $additionalNote =
        $additionalNote !== null
        && trim($additionalNote) !== ''
            ? trim($additionalNote)
            : null;

    $workOrderReference =
        $workOrderReference !== null
        && trim($workOrderReference) !== ''
            ? trim($workOrderReference)
            : null;

    /*
 * Kunci Kolom Berdasarkan Peran
 * Supervisor hanya mengelola keputusan:
 * - rekomendasi;
 * - prioritas;
 * - target;
 * - Teknisi pelaksana yang dipilih dari akun aktif;
 * - referensi Work Order.
 * Teknisi hanya mengelola realisasi:
 * - deskripsi tindakan;
 * - status;
 * - tanggal selesai;
 * - catatan tambahan;
 * - referensi Work Order.
 */

    if ($isTechnician) {
        $recommendation = (string) (
            $assessment['recommendation']
            ?? ''
        );

        $priority = (string) (
            $assessment['priority']
            ?? ''
        );

        $targetDate =
            $assessment['target_date'] !== null
                ? (string) $assessment['target_date']
                : null;

        $performedBy = $currentUserId;
    } else {
        /*
        | Supervisor tidak boleh mengubah realisasi pekerjaan.
        */

        if ($hasExistingAction) {
            $status = (string) (
                $assessment['status']
                ?? 'pending'
            );

            $actionDescription = trim(
                (string) (
                    $assessment['action_description']
                    ?? ''
                )
            );

            $completedDate =
                $assessment['completed_date'] !== null
                    ? (string) $assessment['completed_date']
                    : null;

            $additionalNote =
                $assessment['additional_note'] !== null
                    ? (string) $assessment['additional_note']
                    : null;
        } else {
            $status = 'pending';
            $actionDescription = '';
            $completedDate = null;
            $additionalNote = null;
        }
    }

    // Validasi Form

    $errors = [];

    // Validasi Rekomendasi

    if ($recommendation === '') {
        $addError(
            $errors,
            'recommendation',
            'Rekomendasi wajib diisi oleh Supervisor.'
        );
    } elseif (
        strlen($recommendation) < 5
        || strlen($recommendation) > 1000
    ) {
        $addError(
            $errors,
            'recommendation',
            'Rekomendasi harus berisi '
            . '5 sampai 1000 karakter.'
        );
    }

    // Validasi Prioritas

    if (
        !in_array(
            $priority,
            $allowedActionPriorities,
            true
        )
    ) {
        $addError(
            $errors,
            'priority',
            'Prioritas tindakan tidak valid.'
        );
    }

    // Validasi Status

    if (
        !in_array(
            $status,
            $allowedActionStatuses,
            true
        )
    ) {
        $addError(
            $errors,
            'status',
            'Status tindakan tidak valid.'
        );
    }

    // Validasi Target Tanggal

    if (!$validDate($targetDate)) {
        $addError(
            $errors,
            'target_date',
            'Target tanggal tidak valid.'
        );
    } elseif (
        $targetDate !== null
        && $targetDate
            < (string) $assessment['inspection_date']
    ) {
        $addError(
            $errors,
            'target_date',
            'Target tanggal tidak boleh lebih awal '
            . 'dari tanggal pemeriksaan.'
        );
    }

    /*
 * Validasi Teknisi Pelaksana
 * Tindakan final selalu harus mempunyai satu Teknisi pelaksana,
 * termasuk ketika status masih Menunggu.
 */

    if (
        $performedBy === null
        || $performedBy < 1
    ) {
        $addError(
            $errors,
            'performed_by',
            'Teknisi pelaksana wajib ditentukan.'
        );
    } else {
        $technicianValidationStatement = $pdo->prepare(
            "SELECT role, is_active
             FROM users
             WHERE id = :user_id
             LIMIT 1"
        );
        $technicianValidationStatement->execute(['user_id' => $performedBy]);
        $technicianData = $technicianValidationStatement->fetch();

        $sameCompletedAssignee =
            $hasExistingAction
            && (string) ($assessment['status'] ?? 'pending') === 'completed'
            && $assignedTechnicianId === (int) $performedBy;

        $technicianIsValid =
            is_array($technicianData)
            && (string) ($technicianData['role'] ?? '') === 'technician'
            && ((int) ($technicianData['is_active'] ?? 0) === 1 || $sameCompletedAssignee);

        if (!$technicianIsValid) {
            $addError(
                $errors,
                'performed_by',
                'Teknisi pelaksana tidak valid atau sudah tidak aktif.'
            );
        }
    }

    // Larangan Mengganti Pelaksana Setelah Pekerjaan Selesai

    if (
        $isSupervisor
        && $hasExistingAction
        && (string) (
            $assessment['status']
            ?? 'pending'
        ) === 'completed'
        && $assignedTechnicianId !== null
        && $performedBy !== $assignedTechnicianId
    ) {
        $addError(
            $errors,
            'performed_by',
            'Teknisi pelaksana tidak dapat diganti setelah pekerjaan selesai.'
        );
    }

    // Validasi Deskripsi Tindakan

    if (strlen($actionDescription) > 1500) {
        $addError(
            $errors,
            'action_description',
            'Tindakan maksimal 1500 karakter.'
        );
    }

    if (
        $status === 'in_progress'
        && strlen($actionDescription) < 5
    ) {
        $addError(
            $errors,
            'action_description',
            'Jelaskan tindakan yang sedang dilakukan.'
        );
    }

    if ($status === 'completed') {
        if ($completedDate === null) {
            $addError(
                $errors,
                'completed_date',
                'Tanggal selesai wajib diisi '
                . 'ketika status Selesai.'
            );
        }

        if (strlen($actionDescription) < 5) {
            $addError(
                $errors,
                'action_description',
                'Jelaskan tindakan yang telah diselesaikan.'
            );
        }
    } else {
        $completedDate = null;
    }

    /*
 * Validasi Perpindahan Status Teknisi
 * Status tidak boleh mundur:
 * pending     -> pending / in_progress / completed
 * in_progress -> in_progress / completed
 * completed   -> completed
 */

    if (
        $isTechnician
        && $hasExistingAction
    ) {
        $storedStatus = (string) (
            $assessment['status']
            ?? 'pending'
        );

        if (!WorkflowService::canTransition(
            'action',
            $storedStatus,
            $status
        )) {
            $addError(
                $errors,
                'status',
                'Status tindakan tidak boleh dikembalikan '
                . 'ke tahap sebelumnya.'
            );
        }
    }

    // Validasi Tanggal Selesai

    if (!$validDate($completedDate)) {
        $addError(
            $errors,
            'completed_date',
            'Tanggal selesai tidak valid.'
        );
    } elseif (
        $completedDate !== null
        && $completedDate
            < (string) $assessment['inspection_date']
    ) {
        $addError(
            $errors,
            'completed_date',
            'Tanggal selesai tidak boleh lebih awal '
            . 'dari tanggal pemeriksaan.'
        );
    } elseif (
        $completedDate !== null
        && $completedDate > date('Y-m-d')
    ) {
        $addError(
            $errors,
            'completed_date',
            'Tanggal selesai tidak boleh '
            . 'melebihi tanggal hari ini.'
        );
    }

    // Validasi Catatan Tambahan

    if (
        $additionalNote !== null
        && strlen($additionalNote) > 1000
    ) {
        $addError(
            $errors,
            'additional_note',
            'Catatan tambahan maksimal 1000 karakter.'
        );
    }

    // Validasi Referensi Work Order

    if (
        $workOrderReference !== null
        && strlen($workOrderReference) > 100
    ) {
        $addError(
            $errors,
            'work_order_reference',
            'Referensi Work Order maksimal 100 karakter.'
        );
    }

    // Kembali ke Form Apabila Validasi Gagal

    if ($errors !== []) {
        flash_set_errors($errors);
        flash_set_old_input($_POST);

        flash_error(
            'Data tindak lanjut belum dapat disimpan. '
            . 'Periksa kembali kolom yang ditandai.'
        );

        redirect(
            'riwayat/tindakan.php?id='
            . $assessmentId
        );
    }

    // Simpan Data Menggunakan Transaksi

    try {
        $pdo->beginTransaction();

        // Periksa Ulang Data di Dalam Transaksi

        $currentActionStatement = $pdo->prepare(
            "SELECT
                COUNT(*) AS action_count,
                MAX(id) AS latest_action_id

             FROM maintenance_actions

             WHERE assessment_id = :assessment_id"
        );

        $currentActionStatement->execute([
            'assessment_id' => $assessmentId,
        ]);

        $currentActionData =
            $currentActionStatement->fetch();

        $currentActionCount = is_array(
            $currentActionData
        )
            ? (int) (
                $currentActionData['action_count']
                ?? 0
            )
            : 0;

        $currentActionId = is_array(
            $currentActionData
        )
            && $currentActionData[
                'latest_action_id'
            ] !== null
                ? (int) $currentActionData[
                    'latest_action_id'
                ]
                : null;

        if ($currentActionCount > 1) {
            throw new RuntimeException(
                'Terdapat tindakan ganda untuk pemeriksaan ini.'
            );
        }

        $savedActionId = null;
        $previousActionStatus = $hasExistingAction
            ? (string) ($assessment['status'] ?? 'pending')
            : null;

        // Supervisor Membuat atau Mengubah Keputusan

        if ($isSupervisor) {
            if ($currentActionCount === 1) {
                if (
                    !$hasExistingAction
                    || $currentActionId
                        !== (int) $assessment['action_id']
                ) {
                    throw new RuntimeException(
                        'Data tindakan telah berubah. '
                        . 'Muat ulang halaman sebelum menyimpan.'
                    );
                }

                $statement = $pdo->prepare(
                    "UPDATE maintenance_actions

                     SET recommendation = :recommendation,
                         priority = :priority,
                         target_date = :target_date,
                         work_order_reference = :work_order_reference,
                         performed_by = :performed_by,
                         updated_at = datetime('now', '+7 hours')

                     WHERE id = :action_id
                       AND assessment_id = :assessment_id"
                );

                $statement->execute([
                    'recommendation' =>
                        $recommendation,

                    'priority' =>
                        $priority,

                    'target_date' =>
                        $targetDate,

                    'work_order_reference' =>
                        $workOrderReference,

                    'performed_by' =>
                        $performedBy,

                    'action_id' =>
                        $currentActionId,

                    'assessment_id' =>
                        $assessmentId,
                ]);

                $savedActionId = $currentActionId;
            } else {
                $statement = $pdo->prepare(
                    "INSERT INTO maintenance_actions (
                        assessment_id,
                        recommendation,
                        priority,
                        action_description,
                        target_date,
                        status,
                        completed_date,
                        additional_note,
                        work_order_reference,
                        created_by,
                        performed_by,
                        created_at,
                        updated_at
                     ) VALUES (
                        :assessment_id,
                        :recommendation,
                        :priority,
                        NULL,
                        :target_date,
                        'pending',
                        NULL,
                        NULL,
                        :work_order_reference,
                        :created_by,
                        :performed_by,
                        datetime('now', '+7 hours'),
                        datetime('now', '+7 hours')
                     )"
                );

                $statement->execute([
                    'assessment_id' =>
                        $assessmentId,

                    'recommendation' =>
                        $recommendation,

                    'priority' =>
                        $priority,

                    'target_date' =>
                        $targetDate,

                    'work_order_reference' =>
                        $workOrderReference,

                    'created_by' =>
                        $currentUserId,

                    'performed_by' =>
                        $performedBy,
                ]);

                $savedActionId = (int) $pdo->lastInsertId();
            }
        } else {
            // Teknisi Hanya Memperbarui Realisasi Miliknya

            if (
                $currentActionCount !== 1
                || $currentActionId
                    !== (int) $assessment['action_id']
            ) {
                throw new RuntimeException(
                    'Data tindakan telah berubah. '
                    . 'Muat ulang halaman sebelum menyimpan.'
                );
            }

            $statement = $pdo->prepare(
                "UPDATE maintenance_actions

                 SET action_description = :action_description,
                     status = :status,
                     completed_date = :completed_date,
                     additional_note = :additional_note,
                     work_order_reference = :work_order_reference,
                     updated_at = datetime('now', '+7 hours')

                 WHERE id = :action_id
                   AND assessment_id = :assessment_id
                   AND performed_by = :current_user_id"
            );

            $statement->execute([
                'action_description' =>
                    $actionDescription !== ''
                        ? $actionDescription
                        : null,

                'status' =>
                    $status,

                'completed_date' =>
                    $completedDate,

                'additional_note' =>
                    $additionalNote,

                'work_order_reference' =>
                    $workOrderReference,

                'action_id' =>
                    $currentActionId,

                'assessment_id' =>
                    $assessmentId,

                'current_user_id' =>
                    $currentUserId,
            ]);

            if ($statement->rowCount() !== 1) {
                throw new RuntimeException(
                    'Tindakan tidak ditugaskan kepada akun Teknisi ini.'
                );
            }

            $savedActionId = $currentActionId;
        }

        audit_log(
            $pdo,
            $currentUserId,
            $isSupervisor
                ? ($hasExistingAction ? 'maintenance_recommendation_updated' : 'maintenance_recommendation_created')
                : 'maintenance_execution_updated',
            'maintenance_action',
            $savedActionId,
            $isSupervisor
                ? 'Supervisor menyimpan rekomendasi tindak lanjut untuk ' . (string) ($assessment['alternative_code'] ?? '') . '.'
                : 'Teknisi memperbarui pelaksanaan tindak lanjut untuk ' . (string) ($assessment['alternative_code'] ?? '') . '.',
            $hasExistingAction
                ? [
                    'status' => $previousActionStatus,
                    'priority' => $assessment['priority'] ?? null,
                    'performed_by' => $assessment['performed_by'] ?? null,
                ]
                : null,
            [
                'assessment_id' => $assessmentId,
                'alternative_code' => $assessment['alternative_code'] ?? null,
                'priority' => $isSupervisor ? $priority : ($assessment['priority'] ?? null),
                'status' => $isSupervisor ? ($previousActionStatus ?? 'pending') : $status,
                'performed_by' => $isSupervisor ? $performedBy : $currentUserId,
                'target_date' => $isSupervisor ? $targetDate : ($assessment['target_date'] ?? null),
                'completed_date' => $isSupervisor ? ($assessment['completed_date'] ?? null) : $completedDate,
            ]
        );

        $pdo->commit();

        flash_success(
            $isSupervisor
                ? (
                    $hasExistingAction
                        ? 'Rekomendasi dan penugasan '
                            . 'berhasil diperbarui.'
                        : 'Rekomendasi dan penugasan '
                            . 'berhasil dibuat.'
                )
                : 'Pelaksanaan tindakan berhasil diperbarui.'
        );

        redirect(
            'riwayat/detail.php?id='
            . $assessmentId
        );
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Tindak lanjut pemeliharaan gagal disimpan: '
            . $exception->getMessage()
        );

        flash_set_old_input($_POST);

        flash_error(
            (bool) app_config('debug', false)
                ? 'Tindak lanjut gagal disimpan: '
                    . $exception->getMessage()
                : 'Tindak lanjut gagal disimpan. '
                    . 'Silakan coba kembali.'
        );

        redirect(
            'riwayat/tindakan.php?id='
            . $assessmentId
        );
    }
}

// Ambil Pesan Validasi dan Input Lama

$errors = flash_get_errors();
$old = flash_get_old_input();

$fieldValue = static function (
    string $key,
    mixed $default = null
) use ($old): mixed {
    return array_key_exists($key, $old)
        ? $old[$key]
        : $default;
};

$recommendation = (string) $fieldValue(
    'recommendation',
    $assessment['recommendation'] ?? ''
);

$priority = (string) $fieldValue(
    'priority',
    $assessment['priority'] ?? 'scheduled'
);

$actionDescription = (string) $fieldValue(
    'action_description',
    $assessment['action_description'] ?? ''
);

$targetDate = (string) $fieldValue(
    'target_date',
    $assessment['target_date'] ?? ''
);

$status = (string) $fieldValue(
    'status',
    $assessment['status'] ?? 'pending'
);

$completedDate = (string) $fieldValue(
    'completed_date',
    $assessment['completed_date'] ?? ''
);

$additionalNote = (string) $fieldValue(
    'additional_note',
    $assessment['additional_note'] ?? ''
);

$workOrderReference = (string) $fieldValue(
    'work_order_reference',
    $assessment['work_order_reference'] ?? ''
);

$performedBy = (string) $fieldValue(
    'performed_by',
    $assignedTechnicianId ?? ($isTechnician ? $currentUserId : '')
);

// Pastikan Input Lama Tidak Membuka Kolom yang Dikunci

if ($isSupervisor) {
    $actionDescription = (string) (
        $assessment['action_description']
        ?? ''
    );

    $status = (string) (
        $assessment['status']
        ?? 'pending'
    );

    $completedDate = (string) (
        $assessment['completed_date']
        ?? ''
    );

    $additionalNote = (string) (
        $assessment['additional_note']
        ?? ''
    );
} else {
    $recommendation = (string) (
        $assessment['recommendation']
        ?? ''
    );

    $priority = (string) (
        $assessment['priority']
        ?? 'scheduled'
    );

    $targetDate = (string) (
        $assessment['target_date']
        ?? ''
    );

    $performedBy = (string) $currentUserId;
}

// Fungsi Tampilan Kesalahan Form

$errorText = static function (
    array $errors,
    string $field
): string {
    if (
        !isset($errors[$field])
        || !is_array($errors[$field])
    ) {
        return '';
    }

    return (string) (
        $errors[$field][0]
        ?? ''
    );
};

$isInvalid = static function (
    array $errors,
    string $field
): string {
    return isset($errors[$field])
        ? ' is-invalid'
        : '';
};

// Konfigurasi Halaman

$pageTitle = $hasExistingAction
    ? 'Ubah Tindak Lanjut'
    : 'Buat Tindak Lanjut';

$pageDescription =
    'Pencatatan rekomendasi dan realisasi '
    . 'pemeliharaan motor.';

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

        <section class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <h1 class="page-title mb-1">
                    <?= e($pageTitle) ?>
                </h1>

                <p class="page-description mb-0">
                    <?= e(
                        $assessment['alternative_code']
                    ) ?>

                    —

                    <?= e(
                        $assessment['motor_name']
                    ) ?>,

                    <?= e(
                        $assessment['period_name']
                    ) ?>
                </p>
            </div>

            <div class="page-header-actions">
                <a href="<?= e(base_url( 'riwayat/detail.php?id=' . $assessmentId )) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>

                <span class="badge text-bg-primary p-2">
                    Peringkat
                    #<?= e($assessment['ranking']) ?>

                    · Kelompok
                    <?= e($assessment['rank_group']) ?>

                    · V =

                    <?= e(format_number_id(
                        $assessment['preference_value'],
                        4
                    )) ?>
                </span>
            </div>
        </section>

        <?php if ($isSupervisor): ?>
            <div class="alert alert-info mb-4" role="status">
                Supervisor menetapkan rekomendasi,
                prioritas, target tanggal, dan referensi Work Order.
                Supervisor memilih Teknisi Maintenance aktif yang akan menerima tugas.
                Realisasi pekerjaan hanya diperbarui
                oleh Teknisi yang ditugaskan.
            </div>
        <?php else: ?>
            <div class="alert alert-warning mb-4" role="status">
                Rekomendasi, prioritas, dan target
                tanggal dikunci karena ditetapkan
                Supervisor. Teknisi mengisi pelaksanaan
                dan status penyelesaiannya.
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(base_url( 'riwayat/tindakan.php?id=' . $assessmentId )) ?>">
            <?= csrf_field($csrfFormName) ?>

            <input type="hidden" name="assessment_id" value="<?= e($assessmentId) ?>">

            <div class="row g-4">
                <div class="col-xl-8">
                    <section class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h2 class="h5 mb-1">
                                Rekomendasi Pemeliharaan
                            </h2>

                            <p class="small text-body-secondary mb-0">
                                Arahan resmi tindak lanjut
                                berdasarkan hasil TOPSIS.
                            </p>
                        </div>

                        <div class="card-body p-4">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label for="recommendation" class="form-label">
                                        Rekomendasi

                                        <span class="text-danger">
                                            *
                                        </span>
                                    </label>

                                    <textarea id="recommendation" name="recommendation" rows="4" maxlength="1000" class="form-control<?= e( $isInvalid( $errors, 'recommendation' ) ) ?>" <?= $isTechnician ? 'readonly' : 'required' ?> placeholder="Contoh: lakukan pemeriksaan bearing dan pelumasan sebelum motor kembali beroperasi." ><?= e( $recommendation ) ?></textarea> <?php if ( $errorText( $errors, 'recommendation' ) !== '' ): ?> <div class="invalid-feedback">
                                            <?= e($errorText(
                                                $errors,
                                                'recommendation'
                                            )) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-md-6">
                                    <label for="priority" class="form-label">
                                        Prioritas

                                        <span class="text-danger">
                                            *
                                        </span>
                                    </label>

                                    <select id="priority" name="priority" class="form-select<?= e( $isInvalid( $errors, 'priority' ) ) ?>" <?= $isTechnician ? 'disabled' : 'required' ?>>
                                        <option value="immediate" <?= selected( $priority, 'immediate' ) ?>>
                                            Segera
                                        </option>

                                        <option value="scheduled" <?= selected( $priority, 'scheduled' ) ?>>
                                            Terjadwal
                                        </option>

                                        <option value="monitoring" <?= selected( $priority, 'monitoring' ) ?>>
                                            Monitoring
                                        </option>
                                    </select>

                                    <?php if ($isTechnician): ?>
                                        <input type="hidden" name="priority" value="<?= e( $priority ) ?>">
                                    <?php endif; ?>

                                    <?php if (
                                        $errorText(
                                            $errors,
                                            'priority'
                                        ) !== ''
                                    ): ?>
                                        <div class="invalid-feedback">
                                            <?= e($errorText(
                                                $errors,
                                                'priority'
                                            )) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-md-6">
                                    <label for="target_date" class="form-label">
                                        Target Tanggal
                                    </label>

                                    <input type="date" id="target_date" name="target_date" value="<?= e( $targetDate ) ?>" class="form-control<?= e( $isInvalid( $errors, 'target_date' ) ) ?>" <?= $isTechnician ? 'readonly' : '' ?>>

                                    <?php if (
                                        $errorText(
                                            $errors,
                                            'target_date'
                                        ) !== ''
                                    ): ?>
                                        <div class="invalid-feedback">
                                            <?= e($errorText(
                                                $errors,
                                                'target_date'
                                            )) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-md-6">
                                    <label for="performed_by" class="form-label">
                                        Teknisi Pelaksana
                                        <span class="text-danger">*</span>
                                    </label>

                                    <?php if ($isSupervisor): ?>
                                        <select id="performed_by" name="performed_by" class="form-select<?= e($isInvalid($errors, 'performed_by')) ?>" required <?= ($hasExistingAction && (string) ($assessment['status'] ?? 'pending') === 'completed') ? 'disabled' : '' ?>>
                                            <option value="">-- Pilih Teknisi --</option>
                                            <?php foreach ($activeTechnicians as $technicianRow): ?>
                                                <?php $technicianId = (int) $technicianRow['id']; ?>
                                                <option value="<?= e($technicianId) ?>" <?= selected( (string) ( input_string($old ?? [], 'performed_by', '') ?: ($assignedTechnicianId ?? '') ), (string) $technicianId ) ?>>
                                                    <?= e((string) $technicianRow['name']) ?>
                                                    (<?= e((string) $technicianRow['username']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <?php if ($hasExistingAction && (string) ($assessment['status'] ?? 'pending') === 'completed'): ?>
                                            <input type="hidden" name="performed_by" value="<?= e($assignedTechnicianId) ?>">
                                            <div class="form-text">
                                                Penugasan dikunci setelah pekerjaan berstatus selesai.
                                            </div>
                                        <?php else: ?>
                                            <div class="form-text">
                                                Pilih Teknisi Maintenance aktif yang akan melaksanakan rekomendasi.
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <input type="text" id="performed_by" class="form-control" value="<?= e((string) ($assessment['performed_by_name'] ?? auth_name() ?? 'Teknisi Maintenance')) ?>" readonly>
                                    <?php endif; ?>

                                    <?php if ($errorText($errors, 'performed_by') !== ''): ?>
                                        <div class="text-danger small mt-1">
                                            <?= e($errorText($errors, 'performed_by')) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-md-6">
                                    <label for="work_order_reference" class="form-label">
                                        Referensi Work Order
                                    </label>

                                    <input type="text" id="work_order_reference" name="work_order_reference" value="<?= e( $workOrderReference ) ?>" maxlength="100" class="form-control<?= e( $isInvalid( $errors, 'work_order_reference' ) ) ?>" placeholder="Contoh: WO-MTC-2026-001">

                                    <?php if (
                                        $errorText(
                                            $errors,
                                            'work_order_reference'
                                        ) !== ''
                                    ): ?>
                                        <div class="invalid-feedback">
                                            <?= e($errorText(
                                                $errors,
                                                'work_order_reference'
                                            )) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h2 class="h5 mb-1">
                                Pelaksanaan Tindakan
                            </h2>

                            <p class="small text-body-secondary mb-0">
                                Isi berdasarkan pekerjaan
                                yang benar-benar dilakukan.
                            </p>
                        </div>

                        <div class="card-body p-4">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label for="action_description" class="form-label">
                                        Tindakan yang Dilakukan
                                    </label>

                                    <textarea id="action_description" name="action_description" rows="4" maxlength="1500" class="form-control<?= e( $isInvalid( $errors, 'action_description' ) ) ?>" <?= $isSupervisor ? 'readonly' : '' ?> placeholder="Jelaskan pemeriksaan, perbaikan, penggantian komponen, atau tindakan monitoring." ><?= e( $actionDescription ) ?></textarea> <?php if ( $errorText( $errors, 'action_description' ) !== '' ): ?> <div class="invalid-feedback">
                                            <?= e($errorText(
                                                $errors,
                                                'action_description'
                                            )) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-md-6">
                                    <label for="status" class="form-label">
                                        Status Tindakan

                                        <span class="text-danger">
                                            *
                                        </span>
                                    </label>

                                    <select id="status" name="status" required class="form-select<?= e( $isInvalid( $errors, 'status' ) ) ?>" <?= $isSupervisor ? 'disabled' : '' ?>>
                                        <option value="pending" <?= selected( $status, 'pending' ) ?>>
                                            Menunggu
                                        </option>

                                        <option value="in_progress" <?= selected( $status, 'in_progress' ) ?>>
                                            Dikerjakan
                                        </option>

                                        <option value="completed" <?= selected( $status, 'completed' ) ?>>
                                            Selesai
                                        </option>
                                    </select>

                                    <?php if ($isSupervisor): ?>
                                        <input type="hidden" name="status" value="<?= e($status) ?>">
                                    <?php endif; ?>

                                    <?php if (
                                        $errorText(
                                            $errors,
                                            'status'
                                        ) !== ''
                                    ): ?>
                                        <div class="invalid-feedback">
                                            <?= e($errorText(
                                                $errors,
                                                'status'
                                            )) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-md-6">
                                    <label for="completed_date" class="form-label">
                                        Tanggal Selesai
                                    </label>

                                    <input type="date" id="completed_date" name="completed_date" value="<?= e( $completedDate ) ?>" class="form-control<?= e( $isInvalid( $errors, 'completed_date' ) ) ?>" <?= $isSupervisor ? 'readonly' : '' ?>>

                                    <div class="form-text">
                                        Wajib diisi ketika
                                        status Selesai.
                                    </div>

                                    <?php if (
                                        $errorText(
                                            $errors,
                                            'completed_date'
                                        ) !== ''
                                    ): ?>
                                        <div class="invalid-feedback">
                                            <?= e($errorText(
                                                $errors,
                                                'completed_date'
                                            )) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-12">
                                    <label for="additional_note" class="form-label">
                                        Catatan Tambahan
                                    </label>

                                    <textarea id="additional_note" name="additional_note" rows="3" maxlength="1000" class="form-control<?= e( $isInvalid( $errors, 'additional_note' ) ) ?>" <?= $isSupervisor ? 'readonly' : '' ?> placeholder="Catatan hasil uji ulang, kebutuhan suku cadang, atau pemantauan lanjutan." ><?= e( $additionalNote ) ?></textarea> <?php if ( $errorText( $errors, 'additional_note' ) !== '' ): ?> <div class="invalid-feedback">
                                            <?= e($errorText(
                                                $errors,
                                                'additional_note'
                                            )) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="col-xl-4">
                    <section class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h2 class="h5 mb-0">
                                Ringkasan Motor
                            </h2>
                        </div>

                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-5 text-body-secondary fw-normal">
                                    Alternatif
                                </dt>

                                <dd class="col-7 fw-semibold">
                                    <?= e(
                                        $assessment[
                                            'alternative_code'
                                        ]
                                    ) ?>
                                </dd>

                                <dt class="col-5 text-body-secondary fw-normal">
                                    Kode Mesin
                                </dt>

                                <dd class="col-7">
                                    <?= e(
                                        $assessment[
                                            'machine_code'
                                        ]
                                    ) ?>
                                </dd>

                                <dt class="col-5 text-body-secondary fw-normal">
                                    Tanggal
                                </dt>

                                <dd class="col-7">
                                    <?= e(format_date_id(
                                        $assessment[
                                            'inspection_date'
                                        ]
                                    )) ?>
                                </dd>

                                <dt class="col-5 text-body-secondary fw-normal">
                                    Peringkat
                                </dt>

                                <dd class="col-7">
                                    #<?= e(
                                        $assessment['ranking']
                                    ) ?>
                                </dd>

                                <dt class="col-5 text-body-secondary fw-normal">
                                    Kelompok Peringkat
                                </dt>

                                <dd class="col-7">
                                    <?= e(
                                        $assessment['rank_group']
                                    ) ?>
                                </dd>

                                <dt class="col-5 text-body-secondary fw-normal">
                                    Label TOPSIS
                                </dt>

                                <dd class="col-7">
                                    <?= e(
                                        $assessment[
                                            'priority_label'
                                        ]
                                        ?? '-'
                                    ) ?>
                                </dd>
                            </dl>
                        </div>
                    </section>

                    <section class="card border-0 shadow-sm position-sticky" style="top: 1rem;">
                        <div class="card-body">
                            <h2 class="h6 mb-3">
                                Simpan Perubahan
                            </h2>

                            <p class="small text-body-secondary">
                                <?= $isSupervisor
                                    ? 'Pastikan rekomendasi, prioritas, target, dan penugasan Teknisi sudah benar.'
                                    : 'Pastikan isi pelaksanaan sesuai pekerjaan aktual yang benar-benar dilakukan.' ?>
                            </p>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <?= $isSupervisor
                                        ? 'Simpan Rekomendasi'
                                        : 'Simpan Pelaksanaan' ?>
                                </button>

                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </form>
    </main>
</div>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

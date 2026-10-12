<?php

declare(strict_types=1);

/*
 * Ubah Data Motor Sistem SPK PT CISAS
 * Halaman ini hanya dapat diakses Administrator.
 * Identitas alternatif A1 sampai A6 dikunci oleh sistem.
 * Administrator hanya dapat mengubah nama motor, data nameplate,
 * lokasi, status, dan catatan motor.
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'helpers'
    . DIRECTORY_SEPARATOR . 'auth.php';

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'helpers'
    . DIRECTORY_SEPARATOR . 'validation.php';

auth_require_role('admin');

$pdo = require dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'config'
    . DIRECTORY_SEPARATOR . 'database.php';

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun Administrator sudah tidak aktif atau tidak ditemukan.'
    );

    redirect('login.php');
}

// Validasi ID Motor

$machineId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if ($machineId === false) {
    $notFoundMessage =
        'ID motor tidak valid atau tidak tersedia.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR . '404.php';

    exit;
}

$machineId = (int) $machineId;

// Ambil Data Motor

$machineStatement = $pdo->prepare(
    "SELECT
        id,
        alternative_code,
        machine_code,
        machine_number,
        motor_function,
        motor_name,
        brand,
        voltage,
        rated_current,
        rated_frequency,
        rated_rpm,
        power_hp,
        number_of_poles,
        location,
        status,
        notes,
        created_at,
        updated_at

     FROM machines

     WHERE id = :id

     LIMIT 1"
);

$machineStatement->execute([
    'id' => $machineId,
]);

$machine = $machineStatement->fetch();

if (!is_array($machine)) {
    $notFoundMessage =
        'Data motor yang akan diubah tidak ditemukan.';

    require dirname(__DIR__)
        . DIRECTORY_SEPARATOR . '404.php';

    exit;
}

// Pemetaan Identitas Tetap A1 sampai A6

$identityMap = [
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

$alternativeCode = (string) (
    $machine['alternative_code']
    ?? ''
);

$expectedIdentity =
    $identityMap[$alternativeCode]
    ?? null;

if (!is_array($expectedIdentity)) {
    flash_error(
        'Kode alternatif motor tidak sesuai dengan A1 sampai A6.'
    );

    redirect('motor/index.php');
}

$machineCode = (string) (
    $expectedIdentity['machine_code']
    ?? ''
);

$machineNumber = (int) (
    $expectedIdentity['machine_number']
    ?? 0
);

$motorFunction = (string) (
    $expectedIdentity['motor_function']
    ?? ''
);

// Pastikan Identitas Database Sesuai Alternatif

if (
    (string) (
        $machine['machine_code']
        ?? ''
    ) !== $machineCode

    || (int) (
        $machine['machine_number']
        ?? 0
    ) !== $machineNumber

    || (string) (
        $machine['motor_function']
        ?? ''
    ) !== $motorFunction
) {
    flash_error(
        'Identitas motor tidak sesuai dengan kode alternatif. '
        . 'Jalankan reset database menggunakan schema dan seed terbaru.'
    );

    redirect('motor/index.php');
}

// Nama Token CSRF

$csrfFormName =
    'motor_edit_'
    . $machineId;

// Mengubah Nilai Kosong Menjadi NULL

$nullableText = static function (
    mixed $value
): ?string {
    if ($value === null) {
        return null;
    }

    $text = trim(
        (string) $value
    );

    return $text === ''
        ? null
        : $text;
};

$nullableDecimal = static function (
    mixed $value
): ?float {
    if ($value === null) {
        return null;
    }

    $text = trim(
        (string) $value
    );

    if ($text === '') {
        return null;
    }

    $normalized = str_replace(
        ',',
        '.',
        $text
    );

    return (float) $normalized;
};

$nullableInteger = static function (
    mixed $value
): ?int {
    if ($value === null) {
        return null;
    }

    $text = trim(
        (string) $value
    );

    return $text === ''
        ? null
        : (int) $text;
};

// Proses Form

if (is_post()) {
    // Ambil Input

    $motorName = input_string(
        $_POST,
        'motor_name',
        ''
    ) ?? '';

    $brand = input_string(
        $_POST,
        'brand',
        ''
    ) ?? '';

    $voltage = input_string(
        $_POST,
        'voltage',
        ''
    ) ?? '';

    $ratedCurrent = input_string(
        $_POST,
        'rated_current',
        ''
    ) ?? '';

    $ratedFrequency = input_string(
        $_POST,
        'rated_frequency',
        ''
    ) ?? '';

    $ratedRpm = input_string(
        $_POST,
        'rated_rpm',
        ''
    ) ?? '';

    $powerHp = input_string(
        $_POST,
        'power_hp',
        ''
    ) ?? '';

    $numberOfPoles = input_string(
        $_POST,
        'number_of_poles',
        ''
    ) ?? '';

    $location = input_string(
        $_POST,
        'location',
        ''
    ) ?? '';

    $status = input_string(
        $_POST,
        'status',
        ''
    ) ?? '';

    $notes = input_string(
        $_POST,
        'notes',
        ''
    ) ?? '';

    // Simpan Input Lama

    $oldInput = [
        'motor_name' =>
            $motorName,

        'brand' =>
            $brand,

        'voltage' =>
            $voltage,

        'rated_current' =>
            $ratedCurrent,

        'rated_frequency' =>
            $ratedFrequency,

        'rated_rpm' =>
            $ratedRpm,

        'power_hp' =>
            $powerHp,

        'number_of_poles' =>
            $numberOfPoles,

        'location' =>
            $location,

        'status' =>
            $status,

        'notes' =>
            $notes,
    ];

    // Validasi CSRF

    if (
        !csrf_validate_request(
            $csrfFormName
        )
    ) {
        flash_set_old_input(
            $oldInput
        );

        flash_error(
            'Token keamanan tidak valid atau telah kedaluwarsa. '
            . 'Silakan isi kembali formulir.'
        );

        redirect(
            'motor/edit.php?id='
            . $machineId
        );
    }

    // Validasi Input

    $errors = [];

    validation_add_error(
        $errors,
        'motor_name',
        validation_text(
            $motorName,
            'Nama motor',
            3,
            150,
            true
        )
    );

    validation_add_error(
        $errors,
        'brand',
        validation_text(
            $brand,
            'Merek motor',
            0,
            100,
            false
        )
    );

    validation_add_error(
        $errors,
        'voltage',
        validation_text(
            $voltage,
            'Tegangan nameplate',
            0,
            50,
            false
        )
    );

    validation_add_error(
        $errors,
        'rated_current',
        validation_decimal(
            $ratedCurrent,
            'Arus nominal',
            0.01,
            10000,
            false
        )
    );

    validation_add_error(
        $errors,
        'rated_frequency',
        validation_decimal(
            $ratedFrequency,
            'Frekuensi nominal',
            1,
            1000,
            false
        )
    );

    validation_add_error(
        $errors,
        'rated_rpm',
        validation_integer(
            $ratedRpm,
            'RPM nominal',
            1,
            100000,
            false
        )
    );

    validation_add_error(
        $errors,
        'power_hp',
        validation_decimal(
            $powerHp,
            'Daya motor',
            0.01,
            100000,
            false
        )
    );

    validation_add_error(
        $errors,
        'number_of_poles',
        validation_integer(
            $numberOfPoles,
            'Jumlah kutub',
            1,
            100,
            false
        )
    );

    validation_add_error(
        $errors,
        'location',
        validation_text(
            $location,
            'Lokasi motor',
            0,
            150,
            false
        )
    );

    validation_add_error(
        $errors,
        'status',
        validation_choice(
            $status,
            'Status motor',
            [
                'active',
                'inactive',
            ],
            true
        )
    );

    validation_add_error(
        $errors,
        'notes',
        validation_text(
            $notes,
            'Catatan motor',
            0,
            1500,
            false
        )
    );

    // Cegah Penonaktifan Motor pada Periode Aktif

    if ($status === 'inactive') {
        $activeAssessmentStatement =
            $pdo->prepare(
                "SELECT COUNT(*)

                 FROM assessments AS a

                 INNER JOIN assessment_periods AS p
                    ON p.id = a.period_id

                 WHERE a.machine_id = :machine_id
                   AND p.status = 'active'"
            );

        $activeAssessmentStatement->execute([
            'machine_id' =>
                $machineId,
        ]);

        if (
            (int) $activeAssessmentStatement
                ->fetchColumn()
            > 0
        ) {
            validation_add_error(
                $errors,
                'status',
                'Motor tidak dapat dinonaktifkan karena sudah digunakan '
                . 'pada periode pemeriksaan yang masih aktif.'
            );
        }
    }

    // Kembali apabila Validasi Gagal

    if (
        validation_has_errors(
            $errors
        )
    ) {
        flash_set_errors(
            $errors
        );

        flash_set_old_input(
            $oldInput
        );

        flash_error(
            'Perubahan data motor belum dapat disimpan. '
            . 'Periksa kembali formulir.'
        );

        redirect(
            'motor/edit.php?id='
            . $machineId
        );
    }

    // Simpan Perubahan

    try {
        $updateStatement = $pdo->prepare(
            "UPDATE machines

             SET
                motor_name =
                    :motor_name,

                brand =
                    :brand,

                voltage =
                    :voltage,

                rated_current =
                    :rated_current,

                rated_frequency =
                    :rated_frequency,

                rated_rpm =
                    :rated_rpm,

                power_hp =
                    :power_hp,

                number_of_poles =
                    :number_of_poles,

                location =
                    :location,

                status =
                    :status,

                notes =
                    :notes,

                updated_at =
                    datetime('now', '+7 hours')

             WHERE id =
                   :id

               AND alternative_code =
                   :alternative_code

               AND machine_code =
                   :machine_code

               AND machine_number =
                   :machine_number

               AND motor_function =
                   :motor_function"
        );

        $updateStatement->execute([
            'motor_name' =>
                $motorName,

            'brand' =>
                $nullableText(
                    $brand
                ),

            'voltage' =>
                $nullableText(
                    $voltage
                ),

            'rated_current' =>
                $nullableDecimal(
                    $ratedCurrent
                ),

            'rated_frequency' =>
                $nullableDecimal(
                    $ratedFrequency
                ),

            'rated_rpm' =>
                $nullableInteger(
                    $ratedRpm
                ),

            'power_hp' =>
                $nullableDecimal(
                    $powerHp
                ),

            'number_of_poles' =>
                $nullableInteger(
                    $numberOfPoles
                ),

            'location' =>
                $nullableText(
                    $location
                ),

            'status' =>
                $status,

            'notes' =>
                $nullableText(
                    $notes
                ),

            'id' =>
                $machineId,

            'alternative_code' =>
                $alternativeCode,

            'machine_code' =>
                $machineCode,

            'machine_number' =>
                $machineNumber,

            'motor_function' =>
                $motorFunction,
        ]);

        /*
 * Periksa Identitas jika rowCount Nol
 * rowCount dapat bernilai nol jika nilai yang disimpan sama
 * dengan nilai sebelumnya.
 */

        if (
            $updateStatement->rowCount()
            < 1
        ) {
            $identityCheckStatement =
                $pdo->prepare(
                    "SELECT COUNT(*)

                     FROM machines

                     WHERE id =
                           :id

                       AND alternative_code =
                           :alternative_code

                       AND machine_code =
                           :machine_code

                       AND machine_number =
                           :machine_number

                       AND motor_function =
                           :motor_function"
                );

            $identityCheckStatement->execute([
                'id' =>
                    $machineId,

                'alternative_code' =>
                    $alternativeCode,

                'machine_code' =>
                    $machineCode,

                'machine_number' =>
                    $machineNumber,

                'motor_function' =>
                    $motorFunction,
            ]);

            if (
                (int) $identityCheckStatement
                    ->fetchColumn()
                !== 1
            ) {
                throw new RuntimeException(
                    'Identitas motor berubah atau tidak sesuai.'
                );
            }
        }

        flash_success(
            'Data motor '
            . $alternativeCode
            . ' berhasil diperbarui.'
        );

        redirect(
            'motor/detail.php?id='
            . $machineId
        );
    } catch (Throwable $exception) {
        error_log(
            'Ubah motor SPK PT CISAS gagal: '
            . $exception->getMessage()
        );

        flash_set_old_input(
            $oldInput
        );

        $exceptionMessage =
            strtolower(
                $exception->getMessage()
            );

        if (
            str_contains(
                $exceptionMessage,
                'identitas motor'
            )

            || str_contains(
                $exceptionMessage,
                'check constraint'
            )
        ) {
            flash_error(
                'Identitas A1 sampai A6 tidak boleh diubah. '
                . 'Muat ulang halaman atau jalankan reset database '
                . 'jika identitas data lama tidak sesuai.'
            );
        } else {
            flash_error(
                'Terjadi gangguan saat menyimpan perubahan motor. '
                . 'Silakan coba kembali.'
            );
        }

        redirect(
            'motor/edit.php?id='
            . $machineId
        );
    }
}

// Nilai Form

$errors =
    flash_get_errors();

$oldInput =
    flash_get_old_input();

$getFormValue = static function (
    array $oldInput,
    array $machine,
    string $key,
    string $default = ''
): string {
    if (
        array_key_exists(
            $key,
            $oldInput
        )
    ) {
        $value =
            $oldInput[$key];

        return is_scalar($value)
            ? (string) $value
            : $default;
    }

    $value =
        $machine[$key]
        ?? $default;

    return $value === null
        ? ''
        : (string) $value;
};

$motorNameValue =
    $getFormValue(
        $oldInput,
        $machine,
        'motor_name'
    );

$brandValue =
    $getFormValue(
        $oldInput,
        $machine,
        'brand'
    );

$voltageValue =
    $getFormValue(
        $oldInput,
        $machine,
        'voltage'
    );

$ratedCurrentValue =
    $getFormValue(
        $oldInput,
        $machine,
        'rated_current'
    );

$ratedFrequencyValue =
    $getFormValue(
        $oldInput,
        $machine,
        'rated_frequency'
    );

$ratedRpmValue =
    $getFormValue(
        $oldInput,
        $machine,
        'rated_rpm'
    );

$powerHpValue =
    $getFormValue(
        $oldInput,
        $machine,
        'power_hp'
    );

$numberOfPolesValue =
    $getFormValue(
        $oldInput,
        $machine,
        'number_of_poles'
    );

$locationValue =
    $getFormValue(
        $oldInput,
        $machine,
        'location'
    );

$statusValue =
    $getFormValue(
        $oldInput,
        $machine,
        'status',
        'active'
    );

$notesValue =
    $getFormValue(
        $oldInput,
        $machine,
        'notes'
    );

$motorFunctionLabel = match (
    $motorFunction
) {
    'scraper' =>
        'Scraper',

    'homogenizer' =>
        'Homogenizer',

    default =>
        '-',
};

// Konfigurasi Halaman

$pageTitle =
    'Ubah Motor '
    . $alternativeCode;

$pageDescription =
    'Perubahan nama, data nameplate, lokasi, status, dan catatan '
    . 'motor mesin mixer pada Sistem SPK PT CISAS.';

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

        <!-- Judul Halaman -->
        <section class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary text-white fw-bold" style="width: 52px; height: 52px;">
                        <?= e(
                            $alternativeCode
                        ) ?>
                    </span>

                    <span class="badge text-bg-light border">
                        Identitas alternatif tetap
                    </span>
                </div>

                <h1 class="page-title">
                    Ubah Data Motor
                </h1>

                <p class="page-description">
                    Perbarui nama, data nameplate,
                    lokasi, status, dan catatan motor.
                </p>
            </div>

            <div class="page-header-actions">
                <a href="<?= e( base_url( 'motor/detail.php?id=' . $machineId ) ) ?>" class="btn btn-outline-secondary">
                    Kembali
                </a>
            </div>
        </section>

        <!-- Informasi Identitas Tetap -->
        <div class="alert alert-info mb-4" role="status">
            Identitas
            <strong>
                <?= e(
                    $alternativeCode
                ) ?>
            </strong>
            telah dikunci. Kode mesin, nomor mesin, dan fungsi motor
            tidak dapat diubah karena merupakan identitas alternatif
            penelitian.
        </div>

        <form method="post" action="<?= e( base_url( 'motor/edit.php?id=' . $machineId ) ) ?>">
            <?= csrf_field(
                $csrfFormName
            ) ?>

            <div class="row g-4">

                <!-- Identitas Motor -->
                <div class="col-xl-6">
                    <section class="card border-0 shadow-sm h-100" aria-labelledby="motor-identity-title">
                        <div class="card-header bg-white py-3">
                            <h2 id="motor-identity-title" class="h5 mb-1">
                                Identitas Motor
                            </h2>

                            <p class="small text-body-secondary mb-0">
                                Identitas alternatif dikunci oleh sistem.
                            </p>
                        </div>

                        <div class="card-body p-4">
                            <div class="row g-3">

                                <!-- Kode Alternatif -->
                                <div class="col-md-4">
                                    <label for="alternative_code" class="form-label">
                                        Kode Alternatif
                                    </label>

                                    <input type="text" id="alternative_code" class="form-control" value="<?= e( $alternativeCode ) ?>" readonly>
                                </div>

                                <!-- Kode Mesin -->
                                <div class="col-md-8">
                                    <label for="machine_code" class="form-label">
                                        Kode Mesin
                                    </label>

                                    <input type="text" id="machine_code" class="form-control" value="<?= e( $machineCode ) ?>" readonly>
                                </div>

                                <!-- Nomor Mesin -->
                                <div class="col-md-6">
                                    <label for="machine_number" class="form-label">
                                        Nomor Mesin
                                    </label>

                                    <input type="text" id="machine_number" class="form-control" value="<?= e( $machineNumber ) ?>" readonly>
                                </div>

                                <!-- Fungsi Motor -->
                                <div class="col-md-6">
                                    <label for="motor_function" class="form-label">
                                        Fungsi Motor
                                    </label>

                                    <input type="text" id="motor_function" class="form-control" value="<?= e( $motorFunctionLabel ) ?>" readonly>
                                </div>

                                <!-- Nama Motor -->
                                <div class="col-12">
                                    <label for="motor_name" class="form-label required-label">
                                        Nama Motor
                                    </label>

                                    <input type="text" id="motor_name" name="motor_name" class="form-control <?= e( validation_class( $errors, 'motor_name' ) ) ?>" value="<?= e( $motorNameValue ) ?>" minlength="3" maxlength="150" required>

                                    <?php
                                    $motorNameError =
                                        validation_first_error(
                                            $errors,
                                            'motor_name'
                                        );
                                    ?>

                                    <?php if (
                                        $motorNameError
                                        !== null
                                    ): ?>
                                        <div class="invalid-feedback">
                                            <?= e(
                                                $motorNameError
                                            ) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Lokasi -->
                                <div class="col-md-8">
                                    <label for="location" class="form-label">
                                        Lokasi Motor
                                    </label>

                                    <input type="text" id="location" name="location" class="form-control <?= e( validation_class( $errors, 'location' ) ) ?>" value="<?= e( $locationValue ) ?>" maxlength="150">

                                    <?php
                                    $locationError =
                                        validation_first_error(
                                            $errors,
                                            'location'
                                        );
                                    ?>

                                    <?php if (
                                        $locationError
                                        !== null
                                    ): ?>
                                        <div class="invalid-feedback">
                                            <?= e(
                                                $locationError
                                            ) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Status -->
                                <div class="col-md-4">
                                    <label for="status" class="form-label required-label">
                                        Status
                                    </label>

                                    <select id="status" name="status" class="form-select <?= e( validation_class( $errors, 'status' ) ) ?>" required>
                                        <option value="active" <?= selected( $statusValue, 'active' ) ?>>
                                            Aktif
                                        </option>

                                        <option value="inactive" <?= selected( $statusValue, 'inactive' ) ?>>
                                            Tidak Aktif
                                        </option>
                                    </select>

                                    <?php
                                    $statusError =
                                        validation_first_error(
                                            $errors,
                                            'status'
                                        );
                                    ?>

                                    <?php if (
                                        $statusError
                                        !== null
                                    ): ?>
                                        <div class="invalid-feedback">
                                            <?= e(
                                                $statusError
                                            ) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Catatan -->
                                <div class="col-12">
                                    <label for="notes" class="form-label">
                                        Catatan Motor
                                    </label>

                                    <textarea
                                        id="notes"
                                        name="notes"
                                        class="form-control <?= e(
                                            validation_class(
                                                $errors,
                                                'notes'
                                            )
                                        ) ?>"
                                        rows="5"
                                        maxlength="1500"
                                        placeholder="Tambahkan catatan bila diperlukan."
                                    ><?= e(
                                        $notesValue
                                    ) ?></textarea>

                                    <?php
                                    $notesError =
                                        validation_first_error(
                                            $errors,
                                            'notes'
                                        );
                                    ?>

                                    <?php if (
                                        $notesError
                                        !== null
                                    ): ?>
                                        <div class="invalid-feedback">
                                            <?= e(
                                                $notesError
                                            ) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="form-text">
                                            Maksimal 1.500 karakter.
                                        </div>
                                    <?php endif; ?>
                                </div>

                            </div>
                        </div>
                    </section>
                </div>

                <!-- Data Nameplate -->
                <div class="col-xl-6">
                    <section class="card border-0 shadow-sm h-100" aria-labelledby="nameplate-title">
                        <div class="card-header bg-white py-3">
                            <h2 id="nameplate-title" class="h5 mb-1">
                                Data Nameplate
                            </h2>

                            <p class="small text-body-secondary mb-0">
                                Isi berdasarkan nameplate motor
                                yang sebenarnya.
                            </p>
                        </div>

                        <div class="card-body p-4">
                            <div class="row g-3">

                                <!-- Merek -->
                                <div class="col-md-6">
                                    <label for="brand" class="form-label">
                                        Merek Motor
                                    </label>

                                    <input type="text" id="brand" name="brand" class="form-control <?= e( validation_class( $errors, 'brand' ) ) ?>" value="<?= e( $brandValue ) ?>" maxlength="100">

                                    <?php
                                    $brandError =
                                        validation_first_error(
                                            $errors,
                                            'brand'
                                        );
                                    ?>

                                    <?php if (
                                        $brandError
                                        !== null
                                    ): ?>
                                        <div class="invalid-feedback">
                                            <?= e(
                                                $brandError
                                            ) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Tegangan -->
                                <div class="col-md-6">
                                    <label for="voltage" class="form-label">
                                        Tegangan Nameplate
                                    </label>

                                    <input type="text" id="voltage" name="voltage" class="form-control <?= e( validation_class( $errors, 'voltage' ) ) ?>" value="<?= e( $voltageValue ) ?>" maxlength="50" placeholder="Contoh: 220/380 V">

                                    <?php
                                    $voltageError =
                                        validation_first_error(
                                            $errors,
                                            'voltage'
                                        );
                                    ?>

                                    <?php if (
                                        $voltageError
                                        !== null
                                    ): ?>
                                        <div class="invalid-feedback">
                                            <?= e(
                                                $voltageError
                                            ) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Arus Nominal -->
                                <div class="col-md-6">
                                    <label for="rated_current" class="form-label">
                                        Arus Nominal
                                    </label>

                                    <div class="input-group has-validation">
                                        <input type="text" inputmode="decimal" id="rated_current" name="rated_current" class="form-control <?= e( validation_class( $errors, 'rated_current' ) ) ?>" value="<?= e( $ratedCurrentValue ) ?>" placeholder="Contoh: 8,7">

                                        <span class="input-group-text">
                                            A
                                        </span>

                                        <?php
                                        $ratedCurrentError =
                                            validation_first_error(
                                                $errors,
                                                'rated_current'
                                            );
                                        ?>

                                        <?php if (
                                            $ratedCurrentError
                                            !== null
                                        ): ?>
                                            <div class="invalid-feedback">
                                                <?= e(
                                                    $ratedCurrentError
                                                ) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Frekuensi Nominal -->
                                <div class="col-md-6">
                                    <label for="rated_frequency" class="form-label">
                                        Frekuensi Nominal
                                    </label>

                                    <div class="input-group has-validation">
                                        <input type="text" inputmode="decimal" id="rated_frequency" name="rated_frequency" class="form-control <?= e( validation_class( $errors, 'rated_frequency' ) ) ?>" value="<?= e( $ratedFrequencyValue ) ?>" placeholder="Contoh: 50">

                                        <span class="input-group-text">
                                            Hz
                                        </span>

                                        <?php
                                        $ratedFrequencyError =
                                            validation_first_error(
                                                $errors,
                                                'rated_frequency'
                                            );
                                        ?>

                                        <?php if (
                                            $ratedFrequencyError
                                            !== null
                                        ): ?>
                                            <div class="invalid-feedback">
                                                <?= e(
                                                    $ratedFrequencyError
                                                ) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- RPM Nominal -->
                                <div class="col-md-6">
                                    <label for="rated_rpm" class="form-label">
                                        RPM Nominal
                                    </label>

                                    <div class="input-group has-validation">
                                        <input type="number" id="rated_rpm" name="rated_rpm" class="form-control <?= e( validation_class( $errors, 'rated_rpm' ) ) ?>" value="<?= e( $ratedRpmValue ) ?>" min="1" max="100000" step="1">

                                        <span class="input-group-text">
                                            RPM
                                        </span>

                                        <?php
                                        $ratedRpmError =
                                            validation_first_error(
                                                $errors,
                                                'rated_rpm'
                                            );
                                        ?>

                                        <?php if (
                                            $ratedRpmError
                                            !== null
                                        ): ?>
                                            <div class="invalid-feedback">
                                                <?= e(
                                                    $ratedRpmError
                                                ) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Daya Motor -->
                                <div class="col-md-6">
                                    <label for="power_hp" class="form-label">
                                        Daya Motor
                                    </label>

                                    <div class="input-group has-validation">
                                        <input type="text" inputmode="decimal" id="power_hp" name="power_hp" class="form-control <?= e( validation_class( $errors, 'power_hp' ) ) ?>" value="<?= e( $powerHpValue ) ?>" placeholder="Contoh: 5,5">

                                        <span class="input-group-text">
                                            HP
                                        </span>

                                        <?php
                                        $powerHpError =
                                            validation_first_error(
                                                $errors,
                                                'power_hp'
                                            );
                                        ?>

                                        <?php if (
                                            $powerHpError
                                            !== null
                                        ): ?>
                                            <div class="invalid-feedback">
                                                <?= e(
                                                    $powerHpError
                                                ) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Jumlah Kutub -->
                                <div class="col-md-6">
                                    <label for="number_of_poles" class="form-label">
                                        Jumlah Kutub
                                    </label>

                                    <input type="number" id="number_of_poles" name="number_of_poles" class="form-control <?= e( validation_class( $errors, 'number_of_poles' ) ) ?>" value="<?= e( $numberOfPolesValue ) ?>" min="1" max="100" step="1" placeholder="Isi bila tersedia">

                                    <?php
                                    $numberOfPolesError =
                                        validation_first_error(
                                            $errors,
                                            'number_of_poles'
                                        );
                                    ?>

                                    <?php if (
                                        $numberOfPolesError
                                        !== null
                                    ): ?>
                                        <div class="invalid-feedback">
                                            <?= e(
                                                $numberOfPolesError
                                            ) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="form-text">
                                            Data pendukung nameplate dan
                                            tidak digunakan dalam rumus C3.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Informasi Kelengkapan -->
                                <div class="col-12">
                                    <div class="alert alert-warning mb-0" role="status">
                                        Arus nominal, frekuensi nominal,
                                        dan RPM nominal harus dilengkapi
                                        berdasarkan nameplate sebelum
                                        pemeriksaan dikirim ke Supervisor.
                                    </div>
                                </div>

                            </div>
                        </div>
                    </section>
                </div>

                <!-- Tombol Simpan -->
                <div class="col-12">
                    <section class="card border-0 shadow-sm" aria-labelledby="save-motor-title">
                        <div class="card-body p-4">
                            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                                <div>
                                    <h2 id="save-motor-title" class="h5 mb-1">
                                        Simpan Perubahan
                                    </h2>

                                    <p class="text-body-secondary mb-0">
                                        Pastikan data nameplate sesuai
                                        dengan motor yang sebenarnya.
                                    </p>
                                </div>

                                <div class="d-grid d-sm-flex gap-2">
                                    <button type="submit" class="btn btn-primary" data-loading-text="Menyimpan...">
                                        Simpan Perubahan
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

            </div>
        </form>

        <p class="page-meta-card mt-3 mb-0">
            Data dibuat:

            <?= e(
                format_date_id(
                    $machine['created_at']
                    ?? null,
                    true
                )
            ) ?>

            &middot;

            terakhir diperbarui:

            <?= e(
                format_date_id(
                    $machine['updated_at']
                    ?? null,
                    true
                )
            ) ?>
        </p>
    </main>
</div>

<?php

require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';

?>

<?php

declare(strict_types=1);

/*
 * Daftar Motor Sistem SPK PT CISAS
 * Halaman ini dapat diakses oleh:
 * - Administrator;
 * - Supervisor Maintenance;
 * - Teknisi Maintenance.
 * Fungsinya:
 * - menampilkan enam motor tetap A1 sampai A6;
 * - mencari motor berdasarkan kode atau nama;
 * - menyaring berdasarkan status;
 * - menampilkan ringkasan data motor;
 * - menyediakan akses detail untuk seluruh pengguna;
 * - menyediakan akses ubah hanya untuk Administrator.
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'helpers'
    . DIRECTORY_SEPARATOR
    . 'auth.php';

// Batasi Akses Sesuai Peran

auth_require_role([
    'admin',
    'supervisor',
    'technician',
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

$currentRole = auth_role();

$isAdmin = auth_is_admin();

// Ambil Filter

$search = input_string(
    $_GET,
    'q',
    ''
) ?? '';

$statusFilter = input_string(
    $_GET,
    'status',
    ''
) ?? '';

$allowedStatuses = [
    '',
    'active',
    'inactive',
];

if (
    !in_array(
        $statusFilter,
        $allowedStatuses,
        true
    )
) {
    $statusFilter = '';
}

// Nilai Awal

$machines = [];

$summary = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
];

$pageError = null;

// Ambil Data Motor

try {
    // Ringkasan Motor

    $summaryStatement = $pdo->query(
        "SELECT
            COUNT(*) AS total,

            SUM(
                CASE
                    WHEN status = 'active'
                    THEN 1
                    ELSE 0
                END
            ) AS active,

            SUM(
                CASE
                    WHEN status = 'inactive'
                    THEN 1
                    ELSE 0
                END
            ) AS inactive

         FROM machines"
    );

    $summaryData = $summaryStatement->fetch();

    if (is_array($summaryData)) {
        $summary['total'] = (int) (
            $summaryData['total']
            ?? 0
        );

        $summary['active'] = (int) (
            $summaryData['active']
            ?? 0
        );

        $summary['inactive'] = (int) (
            $summaryData['inactive']
            ?? 0
        );
    }

    // Susun Query Daftar Motor

    $conditions = [];

    $parameters = [];

    if ($search !== '') {
        $conditions[] = "(
            lower(alternative_code)
                LIKE lower(:search_code)
            OR
            lower(motor_name)
                LIKE lower(:search_name)
        )";

        $searchPattern = '%'
            . $search
            . '%';

        $parameters['search_code'] =
            $searchPattern;

        $parameters['search_name'] =
            $searchPattern;
    }

    if ($statusFilter !== '') {
        $conditions[] =
            'status = :status';

        $parameters['status'] =
            $statusFilter;
    }

    $machineSql = "
        SELECT
            id,
            alternative_code,
            motor_name,
            status

        FROM machines
    ";

    if ($conditions !== []) {
        $machineSql .= "\nWHERE "
            . implode(
                "\nAND ",
                $conditions
            );
    }

    $machineSql .= "
        ORDER BY
            alternative_code COLLATE NOCASE,
            id
    ";

    $machineStatement = $pdo->prepare(
        $machineSql
    );

    $machineStatement->execute(
        $parameters
    );

    $machines =
        $machineStatement->fetchAll();
} catch (Throwable $exception) {
    error_log(
        'Daftar motor SPK PT CISAS gagal: '
        . $exception->getMessage()
    );

    $pageError =
        'Data motor belum dapat dimuat. '
        . 'Periksa kembali koneksi dan struktur database.';
}

// Membaca Jenis Motor dari Nama

$resolveMotorType = static function (
    string $motorName
): string {
    $normalizedName = strtolower(
        $motorName
    );

    if (
        str_contains(
            $normalizedName,
            'scraper'
        )
    ) {
        return 'Scraper';
    }

    if (
        str_contains(
            $normalizedName,
            'homogenizer'
        )
    ) {
        return 'Homogenizer';
    }

    return 'Tidak diketahui';
};

// Konfigurasi Halaman

$pageTitle = 'Data Motor';

$pageDescription =
    'Daftar enam motor mesin mixer yang menjadi alternatif '
    . 'pada Sistem SPK PT CISAS.';

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
                    Data motor belum dapat dimuat
                </strong>

                <?= e($pageError) ?>
            </div>
        <?php endif; ?>

        <!-- Judul halaman -->
        <section class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <h1 class="page-title">
                    Data Motor
                </h1>

                <p class="page-description">
                    Enam motor tetap yang dinilai sebagai
                    alternatif A1 sampai A6.
                </p>
            </div>

            <?php if ($isAdmin): ?>
                <span class="badge text-bg-light border p-2">
                    Pengelolaan oleh Administrator
                </span>
            <?php else: ?>
                <span class="badge text-bg-light border p-2">
                    Akses lihat data
                </span>
            <?php endif; ?>
        </section>

        <!-- Informasi ruang lingkup -->
        <div class="alert alert-info mb-4" role="status">
            Sistem menggunakan tepat enam motor.
            Data motor tidak ditambah atau dihapus;
            Administrator hanya memperbarui identitas
            dan data nameplate apabila diperlukan.
        </div>

        <!-- Ringkasan -->
        <section class="row g-3 mb-4" aria-label="Ringkasan data motor">
            <div class="col-sm-4">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Total motor
                        </p>

                        <p class="display-6 fw-semibold mb-1">
                            <?= e($summary['total']) ?>
                        </p>

                        <small class="text-body-secondary">
                            Target sistem: 6 alternatif
                        </small>
                    </div>
                </article>
            </div>

            <div class="col-sm-4">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Motor aktif
                        </p>

                        <p class="display-6 fw-semibold mb-1">
                            <?= e($summary['active']) ?>
                        </p>

                        <small class="text-body-secondary">
                            Dapat digunakan dalam pemeriksaan
                        </small>
                    </div>
                </article>
            </div>

            <div class="col-sm-4">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Motor tidak aktif
                        </p>

                        <p class="display-6 fw-semibold mb-1">
                            <?= e($summary['inactive']) ?>
                        </p>

                        <small class="text-body-secondary">
                            Tidak dipakai pada pemeriksaan baru
                        </small>
                    </div>
                </article>
            </div>
        </section>

        <!-- Filter -->
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="motor-filter-title">
            <div class="card-body">
                <h2 id="motor-filter-title" class="h6 mb-3">
                    Cari dan Saring Motor
                </h2>

                <form method="get" action="<?= e( base_url( 'motor/index.php' ) ) ?>" class="row g-3 align-items-end">
                    <div class="col-md-7">
                        <label for="q" class="form-label">
                            Kode atau Nama Motor
                        </label>

                        <input type="search" id="q" name="q" class="form-control" value="<?= e($search) ?>" maxlength="100" placeholder="Contoh: A1 atau Homogenizer">
                    </div>

                    <div class="col-md-3">
                        <label for="status" class="form-label">
                            Status
                        </label>

                        <select id="status" name="status" class="form-select">
                            <option value="">
                                Semua Status
                            </option>

                            <option value="active" <?= selected( $statusFilter, 'active' ) ?>>
                                Aktif
                            </option>

                            <option value="inactive" <?= selected( $statusFilter, 'inactive' ) ?>>
                                Tidak Aktif
                            </option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                Terapkan
                            </button>

                            <a href="<?= e( base_url( 'motor/index.php' ) ) ?>" class="btn btn-outline-secondary">
                                Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <!-- Daftar motor -->
        <section class="card border-0 shadow-sm" aria-labelledby="motor-list-title">
            <div class="card-header bg-white py-3 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                <div>
                    <h2 id="motor-list-title" class="h5 mb-1">
                        Daftar Motor
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Ditemukan
                        <?= e(count($machines)) ?>
                        motor sesuai filter.
                    </p>
                </div>

                <?php if (
                    $search !== ''
                    || $statusFilter !== ''
                ): ?>
                    <span class="badge text-bg-light border">
                        Filter aktif
                    </span>
                <?php endif; ?>
            </div>

            <div class="card-body p-0">
                <?php if ($machines === []): ?>

                    <div class="table-empty-state" role="status">
                        <h3 class="h6 mb-2">
                            Data motor tidak ditemukan
                        </h3>

                        <p class="mb-3">
                            Ubah kata pencarian atau
                            reset filter.
                        </p>

                        <a href="<?= e( base_url( 'motor/index.php' ) ) ?>" class="btn btn-outline-primary">
                            Reset Filter
                        </a>
                    </div>

                <?php else: ?>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">
                                        No.
                                    </th>

                                    <th scope="col">
                                        Alternatif
                                    </th>

                                    <th scope="col">
                                        Nama Motor
                                    </th>

                                    <th scope="col">
                                        Jenis Motor
                                    </th>

                                    <th scope="col">
                                        Status
                                    </th>

                                    <th scope="col" class="text-end">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach (
                                    $machines
                                    as $index => $machine
                                ): ?>
                                    <?php
                                    $machineId = (int)
                                        $machine['id'];

                                    $alternativeCode =
                                        (string) (
                                            $machine[
                                                'alternative_code'
                                            ]
                                            ?? '-'
                                        );

                                    $motorName =
                                        (string) (
                                            $machine[
                                                'motor_name'
                                            ]
                                            ?? '-'
                                        );

                                    $machineStatus =
                                        (string) (
                                            $machine[
                                                'status'
                                            ]
                                            ?? 'inactive'
                                        );

                                    $motorType =
                                        $resolveMotorType(
                                            $motorName
                                        );
                                    ?>

                                    <tr>
                                        <td>
                                            <?= e($index + 1) ?>
                                        </td>

                                        <td>
                                            <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary fw-bold" style="width: 48px; height: 48px;">
                                                <?= e(
                                                    $alternativeCode
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <strong class="d-block">
                                                <?= e($motorName) ?>
                                            </strong>

                                            <small class="text-body-secondary">
                                                ID motor:
                                                <?= e($machineId) ?>
                                            </small>
                                        </td>

                                        <td>
                                            <?= e($motorType) ?>
                                        </td>

                                        <td>
                                            <?php if (
                                                $machineStatus
                                                === 'active'
                                            ): ?>
                                                <span class="status-badge status-success">
                                                    Aktif
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge status-danger">
                                                    Tidak Aktif
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-end">
                                            <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                                <a href="<?= e( base_url( 'motor/detail.php?id=' . $machineId ) ) ?>" class="btn btn-sm btn-outline-secondary">
                                                    Lihat
                                                </a>

                                                <?php if ($isAdmin): ?>
                                                    <a href="<?= e( base_url( 'motor/edit.php?id=' . $machineId ) ) ?>" class="btn btn-sm btn-outline-primary">
                                                        Ubah
                                                    </a>
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

        <p class="small text-body-secondary mt-3 mb-0">
            Halaman ini menampilkan data identitas utama.
            Spesifikasi nameplate akan ditampilkan pada
            halaman detail motor.
        </p>
    </main>
</div>

<?php
require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';
?>

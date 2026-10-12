<?php

declare(strict_types=1);

/*
 * Daftar Pengguna Sistem SPK PT CISAS
 * Halaman ini hanya dapat diakses oleh Administrator.
 * Fungsinya:
 * - menampilkan seluruh akun pengguna;
 * - mencari pengguna berdasarkan nama atau username;
 * - menyaring berdasarkan peran dan status;
 * - menampilkan ringkasan jumlah akun;
 * - menyediakan tautan tambah dan ubah pengguna.
 */

require_once dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'helpers'
    . DIRECTORY_SEPARATOR
    . 'auth.php';

// Batasi Akses Hanya untuk Admin

$currentUser = auth_require_role(
    'admin'
);

// Koneksi Database

$pdo = require dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'app'
    . DIRECTORY_SEPARATOR
    . 'config'
    . DIRECTORY_SEPARATOR
    . 'database.php';

// Perbarui Informasi Pengguna Login

if (!auth_refresh_user($pdo)) {
    flash_error(
        'Akun Administrator sudah tidak aktif atau tidak ditemukan.'
    );

    redirect(
        'login.php'
    );
}

$currentUserId = auth_id();

// Ambil Filter dari URL

$search = input_string(
    $_GET,
    'q',
    ''
) ?? '';

$roleFilter = input_string(
    $_GET,
    'role',
    ''
) ?? '';

$statusFilter = input_string(
    $_GET,
    'status',
    ''
) ?? '';

$allowedRoles = [
    '',
    'admin',
    'supervisor',
    'technician',
];

$allowedStatuses = [
    '',
    'active',
    'inactive',
];

if (!in_array($roleFilter, $allowedRoles, true)) {
    $roleFilter = '';
}

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

// Nilai Awal

$users = [];

$summary = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'admin' => 0,
    'supervisor' => 0,
    'technician' => 0,
];

$pageError = null;

// Ambil Data Pengguna

try {
    // Ringkasan Seluruh Akun

    $summaryStatement = $pdo->query(
        "SELECT
            COUNT(*) AS total,

            SUM(
                CASE
                    WHEN is_active = 1 THEN 1
                    ELSE 0
                END
            ) AS active,

            SUM(
                CASE
                    WHEN is_active = 0 THEN 1
                    ELSE 0
                END
            ) AS inactive,

            SUM(
                CASE
                    WHEN role = 'admin' THEN 1
                    ELSE 0
                END
            ) AS admin,

            SUM(
                CASE
                    WHEN role = 'supervisor' THEN 1
                    ELSE 0
                END
            ) AS supervisor,

            SUM(
                CASE
                    WHEN role = 'technician' THEN 1
                    ELSE 0
                END
            ) AS technician

         FROM users"
    );

    $summaryData = $summaryStatement->fetch();

    if (is_array($summaryData)) {
        foreach (array_keys($summary) as $key) {
            $summary[$key] = intval(
                $summaryData[$key] ?? 0
            );
        }
    }

    // Susun Query Daftar Pengguna

    $conditions = [];
    $parameters = [];

    if ($search !== '') {
        $conditions[] = "(
            instr(
                lower(name),
                lower(:search)
            ) > 0
            OR
            instr(
                lower(username),
                lower(:search)
            ) > 0
        )";

        $parameters['search'] = $search;
    }

    if ($roleFilter !== '') {
        $conditions[] = 'role = :role';

        $parameters['role'] = $roleFilter;
    }

    if ($statusFilter !== '') {
        $conditions[] = 'is_active = :is_active';

        $parameters['is_active'] =
            $statusFilter === 'active'
                ? 1
                : 0;
    }

    $userSql = "
        SELECT
            id,
            name,
            username,
            role,
            is_active,
            created_at,
            updated_at

        FROM users
    ";

    if ($conditions !== []) {
        $userSql .= "\nWHERE "
            . implode(
                "\nAND ",
                $conditions
            );
    }

    $userSql .= "
        ORDER BY
            CASE role
                WHEN 'admin' THEN 1
                WHEN 'supervisor' THEN 2
                WHEN 'technician' THEN 3
                ELSE 4
            END,
            name COLLATE NOCASE,
            id
    ";

    $userStatement = $pdo->prepare(
        $userSql
    );

    $userStatement->execute(
        $parameters
    );

    $users = $userStatement->fetchAll();
} catch (Throwable $exception) {
    error_log(
        'Daftar pengguna SPK PT CISAS gagal: '
        . $exception->getMessage()
    );

    $pageError =
        'Data pengguna belum dapat dimuat. '
        . 'Periksa kembali koneksi dan struktur database.';
}

$userRolesComplete =
    $summary['admin'] === 1
    && $summary['supervisor'] === 1
    && $summary['technician'] >= 1;

$userRoleStructureInvalid =
    $summary['admin'] !== 1
    || $summary['supervisor'] !== 1
    || $summary['technician'] < 1;

// Konfigurasi Tampilan Peran

$roleConfigurations = [
    'admin' => [
        'label' => 'Administrator',
        'class' => 'primary',
    ],

    'supervisor' => [
        'label' => 'Supervisor Maintenance',
        'class' => 'success',
    ],

    'technician' => [
        'label' => 'Teknisi Maintenance',
        'class' => 'warning',
    ],
];

// Konfigurasi Halaman

$pageTitle = 'Data Pengguna';

$pageDescription =
    'Pengelolaan akun Administrator, Supervisor Maintenance, '
    . 'dan Teknisi Maintenance pada Sistem SPK PT CISAS.';

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
                    Data pengguna belum dapat dimuat
                </strong>

                <?= e($pageError) ?>
            </div>
        <?php endif; ?>

        <?php if ($userRoleStructureInvalid): ?>
            <div class="alert alert-danger mb-4" role="alert">
                Struktur akun inti belum lengkap. Sistem memerlukan satu
                Administrator, satu Supervisor Maintenance, dan minimal satu Teknisi Maintenance.
            </div>
        <?php elseif ($userRolesComplete): ?>
            <div class="alert alert-success mb-4" role="status">
                Akun inti tersedia. Administrator dapat menambah Teknisi Maintenance
                baru sesuai kebutuhan PT CISAS.
            </div>
        <?php endif; ?>

        <!-- Judul halaman -->
        <section class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
            <div>
                <h1 class="page-title">
                    Data Pengguna
                </h1>

                <p class="page-description">
                    Kelola akun dan hak akses pengguna sistem.
                </p>
            </div>

            <a href="<?= e(base_url('pengguna/create.php')) ?>" class="btn btn-primary">
                Tambah Teknisi
            </a>
        </section>

        <!-- Ringkasan akun -->
        <section class="row g-3 mb-4" aria-label="Ringkasan akun pengguna">
            <div class="col-6 col-lg-3">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Total akun
                        </p>

                        <p class="display-6 fw-semibold mb-1">
                            <?= e($summary['total']) ?>
                        </p>

                        <small class="text-body-secondary">
                            Seluruh pengguna
                        </small>
                    </div>
                </article>
            </div>

            <div class="col-6 col-lg-3">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Akun aktif
                        </p>

                        <p class="display-6 fw-semibold mb-1">
                            <?= e($summary['active']) ?>
                        </p>

                        <small class="text-body-secondary">
                            Dapat masuk ke sistem
                        </small>
                    </div>
                </article>
            </div>

            <div class="col-6 col-lg-3">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Supervisor
                        </p>

                        <p class="display-6 fw-semibold mb-1">
                            <?= e($summary['supervisor']) ?>
                        </p>

                        <small class="text-body-secondary">
                            Responden ahli AHP
                        </small>
                    </div>
                </article>
            </div>

            <div class="col-6 col-lg-3">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <p class="small text-body-secondary mb-2">
                            Teknisi
                        </p>

                        <p class="display-6 fw-semibold mb-1">
                            <?= e($summary['technician']) ?>
                        </p>

                        <small class="text-body-secondary">
                            Pelaksana pemeriksaan motor
                        </small>
                    </div>
                </article>
            </div>
        </section>

        <!-- Filter -->
        <section class="card border-0 shadow-sm mb-4" aria-labelledby="user-filter-title">
            <div class="card-body">
                <h2 id="user-filter-title" class="h6 mb-3">
                    Cari dan Saring Pengguna
                </h2>

                <form method="get" action="<?= e( base_url( 'pengguna/index.php' ) ) ?>" class="row g-3 align-items-end">
                    <div class="col-lg-5">
                        <label for="q" class="form-label">
                            Nama atau Username
                        </label>

                        <input type="search" id="q" name="q" class="form-control" value="<?= e($search) ?>" maxlength="100" placeholder="Contoh: supervisor">
                    </div>

                    <div class="col-md-5 col-lg-3">
                        <label for="role" class="form-label">
                            Peran
                        </label>

                        <select id="role" name="role" class="form-select">
                            <option value="">
                                Semua Peran
                            </option>

                            <option value="admin" <?= selected( $roleFilter, 'admin' ) ?>>
                                Administrator
                            </option>

                            <option value="supervisor" <?= selected( $roleFilter, 'supervisor' ) ?>>
                                Supervisor Maintenance
                            </option>

                            <option value="technician" <?= selected( $roleFilter, 'technician' ) ?>>
                                Teknisi Maintenance
                            </option>
                        </select>
                    </div>

                    <div class="col-md-4 col-lg-2">
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
                                Nonaktif
                            </option>
                        </select>
                    </div>

                    <div class="col-md-3 col-lg-2">
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                Terapkan
                            </button>

                            <a href="<?= e( base_url( 'pengguna/index.php' ) ) ?>" class="btn btn-outline-secondary">
                                Reset
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <!-- Tabel pengguna -->
        <section class="card border-0 shadow-sm" aria-labelledby="user-list-title">
            <div class="card-header bg-white py-3 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                <div>
                    <h2 id="user-list-title" class="h5 mb-1">
                        Daftar Pengguna
                    </h2>

                    <p class="small text-body-secondary mb-0">
                        Ditemukan
                        <?= e(count($users)) ?>
                        akun sesuai filter.
                    </p>
                </div>

                <?php if (
                    $search !== ''
                    || $roleFilter !== ''
                    || $statusFilter !== ''
                ): ?>
                    <span class="badge text-bg-light border">
                        Filter aktif
                    </span>
                <?php endif; ?>
            </div>

            <div class="card-body p-0">
                <?php if ($users === []): ?>
                    <div class="table-empty-state" role="status">
                        <h3 class="h6 mb-2">
                            Data pengguna tidak ditemukan
                        </h3>

                        <p class="mb-3">
                            Ubah kata pencarian atau reset filter.
                        </p>

                        <a href="<?= e( base_url( 'pengguna/index.php' ) ) ?>" class="btn btn-outline-primary">
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
                                        Pengguna
                                    </th>

                                    <th scope="col">
                                        Username
                                    </th>

                                    <th scope="col">
                                        Peran
                                    </th>

                                    <th scope="col">
                                        Status
                                    </th>

                                    <th scope="col">
                                        Terakhir Diperbarui
                                    </th>

                                    <th scope="col" class="text-end">
                                        Aksi
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($users as $index => $user): ?>
                                    <?php
                                    $userId = intval(
                                        $user['id']
                                    );

                                    $userRole = (string) (
                                        $user['role']
                                        ?? ''
                                    );

                                    $roleConfiguration =
                                        $roleConfigurations[
                                            $userRole
                                        ]
                                        ?? [
                                            'label' => 'Tidak Dikenal',
                                            'class' => 'secondary',
                                        ];

                                    $userIsActive = intval(
                                        $user['is_active']
                                    ) === 1;

                                    $isCurrentAccount =
                                        $currentUserId !== null
                                        && $userId === $currentUserId;
                                    ?>

                                    <tr>
                                        <td>
                                            <?= e($index + 1) ?>
                                        </td>

                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary fw-bold flex-shrink-0" style="width: 40px; height: 40px;" aria-hidden="true">
                                                    <?= e(
                                                        strtoupper(
                                                            substr(
                                                                trim(
                                                                    (string)
                                                                    $user['name']
                                                                ),
                                                                0,
                                                                1
                                                            )
                                                        )
                                                    ) ?>
                                                </span>

                                                <div>
                                                    <strong class="d-block">
                                                        <?= e(
                                                            $user['name']
                                                        ) ?>
                                                    </strong>

                                                    <?php if ($isCurrentAccount): ?>
                                                        <small class="text-primary">
                                                            Akun Anda
                                                        </small>
                                                    <?php else: ?>
                                                        <small class="text-body-secondary">
                                                            Dibuat
                                                            <?= e(
                                                                format_date_id(
                                                                    $user[
                                                                        'created_at'
                                                                    ]
                                                                )
                                                            ) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>

                                        <td>
                                            <code>
                                                <?= e(
                                                    $user['username']
                                                ) ?>
                                            </code>
                                        </td>

                                        <td>
                                            <span class="badge text-bg-<?= e( $roleConfiguration[ 'class' ] ) ?>">
                                                <?= e(
                                                    $roleConfiguration[
                                                        'label'
                                                    ]
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php if ($userIsActive): ?>
                                                <span class="status-badge status-success">
                                                    Aktif
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge status-danger">
                                                    Tidak Aktif
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= e(
                                                format_date_id(
                                                    $user['updated_at'],
                                                    true
                                                )
                                            ) ?>
                                        </td>

                                        <td class="text-end">
                                            <a href="<?= e( base_url( 'pengguna/edit.php?id=' . $userId ) ) ?>" class="btn btn-sm btn-outline-primary">
                                                Ubah
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

        <p class="small text-body-secondary mt-3 mb-0">
            Administrator dan Supervisor tetap aktif dan tunggal. Akun Teknisi dapat ditambah, diperbarui,
            atau dinonaktifkan tanpa menghapus riwayat pemeriksaan dan tindakan.
        </p>
    </main>
</div>

<?php
require TEMPLATE_PATH
    . DIRECTORY_SEPARATOR
    . 'footer.php';
?>

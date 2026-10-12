<?php

declare(strict_types=1);

/*
 * Autentikasi Sistem SPK PT CISAS
 * File ini menangani:
 * - login pengguna;
 * - penyimpanan pengguna pada session;
 * - pemeriksaan status login;
 * - pembatasan akses berdasarkan peran;
 * - pemeriksaan akun aktif;
 * - kedaluwarsa session;
 * - regenerasi ID session;
 * - pemeriksaan kepemilikan dan penugasan objek;
 * - logout.
 */

require_once dirname(__DIR__)
    . DIRECTORY_SEPARATOR
    . 'config'
    . DIRECTORY_SEPARATOR
    . 'app.php';

require_once __DIR__
    . DIRECTORY_SEPARATOR
    . 'functions.php';

require_once __DIR__
    . DIRECTORY_SEPARATOR
    . 'security.php';

require_once __DIR__
    . DIRECTORY_SEPARATOR
    . 'flash.php';

require_once __DIR__
    . DIRECTORY_SEPARATOR
    . 'csrf.php';

// Konstanta Autentikasi

const AUTH_SESSION_KEY = '_spk_cisas_auth';

/*
 * Peran Final Sistem
 * Sistem SPK PT CISAS hanya menggunakan:
 * - admin;
 * - supervisor;
 * - technician.
 */

const AUTH_ALLOWED_ROLES = [
    'admin',
    'supervisor',
    'technician',
];

/*
 * Batas Waktu Session
 * AUTH_IDLE_TIMEOUT:
 * Pengguna otomatis keluar jika tidak aktif selama 2 jam.
 * AUTH_REGENERATE_INTERVAL:
 * ID session diperbarui setiap 30 menit selama pengguna aktif.
 */

const AUTH_IDLE_TIMEOUT = 7200;

// Sesi tetap berakhir maksimal setelah 8 jam walaupun pengguna terus aktif.
const AUTH_ABSOLUTE_TIMEOUT = 28800;

const AUTH_REGENERATE_INTERVAL = 1800;

// Proteksi percobaan login berulang pada pasangan username + IP.
const AUTH_LOGIN_MAX_FAILURES = 5;
const AUTH_LOGIN_WINDOW_MINUTES = 15;

/*
 * Penanda Inisialisasi pada Request yang Sama
 * Satu halaman dapat memanggil auth_user(), auth_id(), dan auth_role()
 * berulang kali. Penanda ini mencegah pemeriksaan timeout dan regenerasi
 * session dijalankan berkali-kali dalam request yang sama.
 */

function auth_is_initialized(): bool
{
    static $initialized = false;

    if ($initialized) {
        return true;
    }

    $initialized = true;

    return false;
}

// Validasi Peran Sistem

function auth_role_is_valid(
    mixed $role
): bool {
    if (!is_string($role)) {
        return false;
    }

    $role = trim($role);

    if (
        $role === ''
        || !in_array(
            $role,
            AUTH_ALLOWED_ROLES,
            true
        )
    ) {
        return false;
    }

    $configuredRoles = app_config(
        'roles',
        []
    );

    return is_array($configuredRoles)
        && array_key_exists(
            $role,
            $configuredRoles
        );
}

// Normalisasi Data Pengguna untuk Session

function auth_normalize_session_user(
    array $user
): ?array {
    $userId = filter_var(
        $user['id'] ?? null,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    $name = trim(
        (string) (
            $user['name']
            ?? ''
        )
    );

    $username = trim(
        (string) (
            $user['username']
            ?? ''
        )
    );

    $role = trim(
        (string) (
            $user['role']
            ?? ''
        )
    );

    if (
        $userId === false
        || $userId === null
        || $name === ''
        || $username === ''
        || !auth_role_is_valid($role)
    ) {
        return null;
    }

    return [
        'id' => (int) $userId,
        'name' => $name,
        'username' => $username,
        'role' => $role,
    ];
}

/*
 * Hapus Status Autentikasi dari Session
 * Flash message tidak dihapus agar informasi logout atau session berakhir
 * masih dapat ditampilkan pada halaman login.
 */

function auth_clear_session_data(): void
{
    flash_session_start();

    unset(
        $_SESSION[AUTH_SESSION_KEY]
    );

    csrf_clear_all();
}

// Memulai dan Memeriksa Session Autentikasi

function auth_initialize(): void
{
    flash_session_start();

    // Hindari Inisialisasi Berulang pada Request yang Sama

    if (auth_is_initialized()) {
        return;
    }

    if (!isset($_SESSION[AUTH_SESSION_KEY])) {
        return;
    }

    if (!is_array($_SESSION[AUTH_SESSION_KEY])) {
        auth_clear_session_data();

        return;
    }

    $authData = $_SESSION[AUTH_SESSION_KEY];

    if (
        !isset(
            $authData['user'],
            $authData['authenticated_at'],
            $authData['last_activity'],
            $authData['regenerated_at']
        )
        || !is_array($authData['user'])
    ) {
        auth_clear_session_data();

        return;
    }

    $normalizedUser = auth_normalize_session_user(
        $authData['user']
    );

    $authenticatedAt = filter_var(
        $authData['authenticated_at'],
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    $lastActivity = filter_var(
        $authData['last_activity'],
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    $regeneratedAt = filter_var(
        $authData['regenerated_at'],
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    if (
        $normalizedUser === null
        || $authenticatedAt === false
        || $authenticatedAt === null
        || $lastActivity === false
        || $lastActivity === null
        || $regeneratedAt === false
        || $regeneratedAt === null
    ) {
        auth_clear_session_data();

        return;
    }

    $currentTime = time();

    // Periksa Batas Absolut Session

    if (
        (
            $currentTime - (int) $authenticatedAt
        ) > AUTH_ABSOLUTE_TIMEOUT
    ) {
        auth_clear_session_data();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        flash_warning(
            'Sesi login telah mencapai batas maksimum 8 jam. '
            . 'Silakan masuk kembali.'
        );

        return;
    }

    // Periksa Waktu Tidak Aktif

    if (
        (
            $currentTime - (int) $lastActivity
        ) > AUTH_IDLE_TIMEOUT
    ) {
        auth_clear_session_data();

        // Putar ID Session Setelah Status Login Dihapus

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        flash_warning(
            'Sesi login telah berakhir karena tidak ada aktivitas. '
            . 'Silakan masuk kembali.'
        );

        return;
    }

    // Pastikan Data Session Menggunakan Bentuk yang Bersih

    $_SESSION[AUTH_SESSION_KEY]['user'] =
        $normalizedUser;

    $_SESSION[AUTH_SESSION_KEY]['authenticated_at'] =
        (int) $authenticatedAt;

    // Perbarui ID Session Secara Berkala

    if (
        (
            $currentTime - (int) $regeneratedAt
        ) > AUTH_REGENERATE_INTERVAL
        && session_status() === PHP_SESSION_ACTIVE
        && session_regenerate_id(true)
    ) {
        $_SESSION[AUTH_SESSION_KEY]['regenerated_at'] =
            $currentTime;
    }

    // Perbarui Waktu Aktivitas

    $_SESSION[AUTH_SESSION_KEY]['last_activity'] =
        $currentTime;
}

/** Periksa apakah tabel proteksi login tersedia. */
function auth_login_attempt_table_exists(PDO $pdo): bool
{
    try {
        $statement = $pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='login_attempts'"
        );
        return (int) $statement->fetchColumn() === 1;
    } catch (Throwable) {
        return false;
    }
}

/** Status pembatasan login untuk kombinasi username dan alamat IP. */
function auth_login_throttle_status(PDO $pdo, string $username, string $ipAddress): array
{
    $username = trim($username);
    $ipAddress = trim($ipAddress) !== '' ? trim($ipAddress) : 'unknown';

    if ($username === '' || !auth_login_attempt_table_exists($pdo)) {
        return ['locked' => false, 'failures' => 0, 'max_failures' => AUTH_LOGIN_MAX_FAILURES];
    }

    $modifier = '-' . AUTH_LOGIN_WINDOW_MINUTES . ' minutes';
    $statement = $pdo->prepare(
        "SELECT COUNT(*)
         FROM login_attempts la
         WHERE lower(la.username) = lower(:username)
           AND la.ip_address = :ip_address
           AND la.was_successful = 0
           AND la.attempted_at >= datetime('now', '+7 hours', :window_modifier)
           AND la.attempted_at > COALESCE(
               (SELECT MAX(success.attempted_at)
                FROM login_attempts success
                WHERE lower(success.username) = lower(:username_success)
                  AND success.ip_address = :ip_success
                  AND success.was_successful = 1),
               '1970-01-01 00:00:00'
           )"
    );
    $statement->execute([
        'username' => $username,
        'ip_address' => $ipAddress,
        'window_modifier' => $modifier,
        'username_success' => $username,
        'ip_success' => $ipAddress,
    ]);
    $failures = (int) $statement->fetchColumn();

    return [
        'locked' => $failures >= AUTH_LOGIN_MAX_FAILURES,
        'failures' => $failures,
        'max_failures' => AUTH_LOGIN_MAX_FAILURES,
        'window_minutes' => AUTH_LOGIN_WINDOW_MINUTES,
    ];
}

/** Simpan satu percobaan login tanpa pernah menyimpan password. */
function auth_record_login_attempt(
    PDO $pdo,
    string $username,
    string $ipAddress,
    bool $wasSuccessful
): void {
    if (!auth_login_attempt_table_exists($pdo)) {
        return;
    }

    try {
        $statement = $pdo->prepare(
            "INSERT INTO login_attempts (username, ip_address, was_successful, attempted_at)
             VALUES (:username, :ip_address, :was_successful, datetime('now', '+7 hours'))"
        );
        $statement->execute([
            'username' => function_exists('mb_substr')
                ? mb_substr(trim($username), 0, 100)
                : substr(trim($username), 0, 100),
            'ip_address' => function_exists('mb_substr')
                ? mb_substr(trim($ipAddress) !== '' ? trim($ipAddress) : 'unknown', 0, 64)
                : substr(trim($ipAddress) !== '' ? trim($ipAddress) : 'unknown', 0, 64),
            'was_successful' => $wasSuccessful ? 1 : 0,
        ]);

        // Menjaga tabel tetap kecil tanpa memengaruhi proses login bila pembersihan gagal.
        $pdo->exec(
            "DELETE FROM login_attempts
             WHERE attempted_at < datetime('now', '+7 hours', '-30 days')"
        );
    } catch (Throwable $exception) {
        error_log('Pencatatan percobaan login gagal: ' . $exception->getMessage());
    }
}

/*
 * Proses Login
 * Fungsi mengembalikan true jika username dan password benar.
 * Pesan kegagalan dibuat umum agar tidak membocorkan apakah username tersedia.
 */

function auth_attempt(
    PDO $pdo,
    string $username,
    string $password
): bool {
    auth_initialize();

    $username = trim($username);

    if (
        $username === ''
        || $password === ''
        || strlen($username) > 100
        || strlen($password) > 4096
    ) {
        return false;
    }

    /*
 * Ambil Pengguna
 * Status aktif diperiksa setelah data ditemukan agar pesan kegagalan tetap
 * sama antara username yang tidak ada, akun nonaktif, dan password salah.
 */

    $statement = $pdo->prepare(
        "SELECT
            id,
            name,
            username,
            password_hash,
            role,
            is_active

         FROM users

         WHERE username = :username

         LIMIT 1"
    );

    $statement->execute([
        'username' => $username,
    ]);

    $user = $statement->fetch();

    if (!is_array($user)) {
        return false;
    }

    $passwordHash = (string) (
        $user['password_hash']
        ?? ''
    );

    if (
        $passwordHash === ''
        || !password_verify(
            $password,
            $passwordHash
        )
    ) {
        return false;
    }

    if (
        (int) (
            $user['is_active']
            ?? 0
        ) !== 1
    ) {
        return false;
    }

    $sessionUser = auth_normalize_session_user(
        $user
    );

    if ($sessionUser === null) {
        return false;
    }

    // Perbarui Session ID Sebelum Menyimpan Status Login

    if (
        session_status() !== PHP_SESSION_ACTIVE
        || !session_regenerate_id(true)
    ) {
        throw new RuntimeException(
            'Session login tidak berhasil diperbarui.'
        );
    }

    // Hapus Status Login dan Token CSRF Sebelumnya

    unset(
        $_SESSION[AUTH_SESSION_KEY]
    );

    csrf_clear_all();

    $currentTime = time();

    /*
 * Simpan Data Pengguna
 * Password hash tidak dimasukkan ke dalam session.
 */

    $_SESSION[AUTH_SESSION_KEY] = [
        'user' => $sessionUser,

        'authenticated_at' => $currentTime,

        'last_activity' => $currentTime,

        'regenerated_at' => $currentTime,
    ];

    // Perbarui Hash Password jika Diperlukan

    if (
        password_needs_rehash(
            $passwordHash,
            PASSWORD_DEFAULT
        )
    ) {
        $newPasswordHash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        if (is_string($newPasswordHash)) {
            $updateStatement = $pdo->prepare(
                "UPDATE users

                 SET
                    password_hash = :password_hash,
                    updated_at = datetime('now', '+7 hours')

                 WHERE id = :id"
            );

            $updateStatement->execute([
                'password_hash' =>
                    $newPasswordHash,

                'id' =>
                    $sessionUser['id'],
            ]);
        }
    }

    return true;
}

// Mengambil Data Pengguna yang Sedang Login

function auth_user(): ?array
{
    auth_initialize();

    $user = $_SESSION[AUTH_SESSION_KEY]['user']
        ?? null;

    if (!is_array($user)) {
        return null;
    }

    return auth_normalize_session_user(
        $user
    );
}

// Pemeriksaan Status Login

function auth_check(): bool
{
    return auth_user() !== null;
}

// Pemeriksaan Status Tamu

function auth_guest(): bool
{
    return !auth_check();
}

// Mengambil ID Pengguna

function auth_id(): ?int
{
    $user = auth_user();

    return $user !== null
        ? (int) $user['id']
        : null;
}

// Mengambil Nama Pengguna

function auth_name(): ?string
{
    $user = auth_user();

    return $user !== null
        ? (string) $user['name']
        : null;
}

// Mengambil Username

function auth_username(): ?string
{
    $user = auth_user();

    return $user !== null
        ? (string) $user['username']
        : null;
}

// Mengambil Peran Pengguna

function auth_role(): ?string
{
    $user = auth_user();

    return $user !== null
        ? (string) $user['role']
        : null;
}

// Mengambil Label Peran

function auth_role_label(
    ?string $role = null
): string {
    $role ??= auth_role();

    if ($role === null) {
        return 'Tamu';
    }

    $fallbackLabels = [
        'admin' =>
            'Administrator',

        'supervisor' =>
            'Supervisor Maintenance',

        'technician' =>
            'Teknisi Maintenance',
    ];

    if (!auth_role_is_valid($role)) {
        return 'Peran Tidak Dikenal';
    }

    return (string) app_config(
        'roles.' . $role,
        $fallbackLabels[$role]
            ?? 'Peran Tidak Dikenal'
    );
}

// Tingkat Otoritas Sistem
//
// Catatan penting:
// - Administrator memiliki tingkat otoritas sistem tertinggi.
// - Nilai tingkat otoritas tidak menggantikan pemeriksaan role spesifik
//   pada proses bisnis. Contoh: input AHP tetap hanya Supervisor dan
//   pemeriksaan motor tetap hanya Teknisi.

function auth_role_rank(
    ?string $role = null
): int {
    $role ??= auth_role();

    if (
        $role === null
        || !auth_role_is_valid($role)
    ) {
        return 0;
    }

    return (int) app_config(
        'role_hierarchy.' . $role,
        0
    );
}

function auth_has_system_authority_at_least(
    string $minimumRole
): bool {
    if (!auth_role_is_valid($minimumRole)) {
        return false;
    }

    return auth_role_rank()
        >= auth_role_rank($minimumRole);
}

// Normalisasi Daftar Peran

function auth_normalize_roles(
    string|array $roles
): array {
    $roleList = is_array($roles)
        ? $roles
        : [$roles];

    $normalizedRoles = [];

    foreach ($roleList as $role) {
        if (!is_string($role)) {
            continue;
        }

        $role = trim($role);

        if (
            $role === ''
            || !auth_role_is_valid($role)
        ) {
            continue;
        }

        $normalizedRoles[$role] = true;
    }

    return array_keys(
        $normalizedRoles
    );
}

/*
 * Pemeriksaan Peran
 * Contoh:
 * auth_has_role('admin');
 * auth_has_role([
 * 'admin',
 * 'supervisor',
 * ]);
 */

function auth_has_role(
    string|array $roles
): bool {
    $currentRole = auth_role();

    if ($currentRole === null) {
        return false;
    }

    $roleList = auth_normalize_roles(
        $roles
    );

    return $roleList !== []
        && in_array(
            $currentRole,
            $roleList,
            true
        );
}

// Pemeriksaan Peran Khusus

function auth_is_admin(): bool
{
    return auth_has_role(
        'admin'
    );
}

function auth_is_supervisor(): bool
{
    return auth_has_role(
        'supervisor'
    );
}

function auth_is_technician(): bool
{
    return auth_has_role(
        'technician'
    );
}

/*
 * Memperbarui Data Pengguna dari Database
 * Fungsi dipanggil pada halaman internal untuk memastikan:
 * - akun masih tersedia;
 * - akun masih aktif;
 * - role masih valid;
 * - nama atau username terbaru digunakan.
 */

function auth_refresh_user(
    PDO $pdo
): bool {
    auth_initialize();

    $sessionUser = auth_user();

    if ($sessionUser === null) {
        return false;
    }

    $statement = $pdo->prepare(
        "SELECT
            id,
            name,
            username,
            role,
            is_active

         FROM users

         WHERE id = :id

         LIMIT 1"
    );

    $statement->execute([
        'id' =>
            (int) $sessionUser['id'],
    ]);

    $user = $statement->fetch();

    if (
        !is_array($user)
        || (int) (
            $user['is_active']
            ?? 0
        ) !== 1
    ) {
        auth_logout(false);

        return false;
    }

    $normalizedUser = auth_normalize_session_user(
        $user
    );

    if ($normalizedUser === null) {
        auth_logout(false);

        return false;
    }

    // Jika ID Database Tidak Sama, Session Tidak Dipercaya

    if (
        (int) $normalizedUser['id']
        !== (int) $sessionUser['id']
    ) {
        auth_logout(false);

        return false;
    }

    $_SESSION[AUTH_SESSION_KEY]['user'] =
        $normalizedUser;

    $_SESSION[AUTH_SESSION_KEY]['last_activity'] =
        time();

    return true;
}

// Mengharuskan Pengguna Sudah Login

function auth_require_login(
    string $loginPath = 'login.php'
): array {
    $user = auth_user();

    if ($user === null) {
        flash_warning(
            'Silakan masuk terlebih dahulu '
            . 'untuk mengakses halaman tersebut.'
        );

        redirect($loginPath);
    }

    return $user;
}

// Mengharuskan Pengguna Belum Login

function auth_require_guest(
    string $dashboardPath = 'dashboard.php'
): void {
    if (auth_check()) {
        redirect($dashboardPath);
    }
}

/*
 * Mengharuskan Peran Tertentu
 * Contoh:
 * auth_require_role('admin');
 * auth_require_role([
 * 'admin',
 * 'supervisor',
 * ]);
 */

function auth_require_role(
    string|array $roles,
    string $forbiddenPath = '403.php'
): array {
    $user = auth_require_login();

    $allowedRoles = auth_normalize_roles(
        $roles
    );

    if (
        $allowedRoles === []
        || !in_array(
            (string) $user['role'],
            $allowedRoles,
            true
        )
    ) {
        flash_error(
            'Anda tidak memiliki izin '
            . 'untuk mengakses halaman tersebut.'
        );

        redirect($forbiddenPath);
    }

    return $user;
}

/*
 * Pemeriksaan Kepemilikan Objek
 * Digunakan untuk halaman yang boleh dibuka oleh pemilik data sendiri,
 * sedangkan Admin dan Supervisor memperoleh akses pengawasan.
 * Contoh:
 * auth_can_access_owner(
 * $assessment['inspector_id']
 * );
 */

function auth_can_access_owner(
    ?int $ownerUserId,
    array $privilegedRoles = [
        'admin',
        'supervisor',
    ]
): bool {
    $currentUserId = auth_id();

    if (
        $currentUserId === null
        || $ownerUserId === null
        || $ownerUserId < 1
    ) {
        return false;
    }

    if (auth_has_role(
        $privilegedRoles
    )) {
        return true;
    }

    return $currentUserId
        === $ownerUserId;
}

// Mengharuskan Kepemilikan atau Peran Pengawas

function auth_require_owner(
    ?int $ownerUserId,
    array $privilegedRoles = [
        'admin',
        'supervisor',
    ],
    string $forbiddenPath = '403.php'
): void {
    auth_require_login();

    if (!auth_can_access_owner(
        $ownerUserId,
        $privilegedRoles
    )) {
        flash_error(
            'Anda tidak memiliki izin '
            . 'untuk mengakses data tersebut.'
        );

        redirect($forbiddenPath);
    }
}

/*
 * Pemeriksaan Penugasan Teknisi
 * Supervisor dan Admin boleh mengawasi penugasan.
 * Teknisi hanya boleh mengakses tindakan yang performed_by-nya sama dengan
 * akun yang sedang login.
 */

function auth_can_access_assignment(
    ?int $assignedTechnicianId,
    array $privilegedRoles = [
        'admin',
        'supervisor',
    ]
): bool {
    $currentUserId = auth_id();

    if ($currentUserId === null) {
        return false;
    }

    if (auth_has_role(
        $privilegedRoles
    )) {
        return true;
    }

    return auth_is_technician()
        && $assignedTechnicianId !== null
        && $assignedTechnicianId > 0
        && $currentUserId
            === $assignedTechnicianId;
}

// Mengharuskan Penugasan Teknisi atau Peran Pengawas

function auth_require_assignment(
    ?int $assignedTechnicianId,
    array $privilegedRoles = [
        'admin',
        'supervisor',
    ],
    string $forbiddenPath = '403.php'
): void {
    auth_require_login();

    if (!auth_can_access_assignment(
        $assignedTechnicianId,
        $privilegedRoles
    )) {
        flash_error(
            'Tindakan tersebut tidak ditugaskan '
            . 'kepada akun Anda.'
        );

        redirect($forbiddenPath);
    }
}

/*
 * Logout
 * Data autentikasi dan token CSRF dihapus.
 * Data flash tetap dipertahankan agar pesan logout dapat ditampilkan.
 */

function auth_logout(
    bool $regenerateSessionId = true
): void {
    flash_session_start();

    auth_clear_session_data();

    if (
        $regenerateSessionId
        && session_status() === PHP_SESSION_ACTIVE
    ) {
        session_regenerate_id(true);
    }
}

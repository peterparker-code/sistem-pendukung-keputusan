<?php

declare(strict_types=1);

/*
 * Fungsi Umum Sistem SPK PT CISAS
 * File ini berisi fungsi yang dapat digunakan oleh berbagai modul:
 * - membaca konfigurasi;
 * - escaping HTML;
 * - membuat URL;
 * - redirect;
 * - membaca input;
 * - format angka dan tanggal;
 * - helper elemen form.
 */

/*
 * Membaca Konfigurasi Aplikasi
 * Contoh:
 * app_config('short_name');
 * app_config('roles.admin');
 * app_config('total_machines', 6);
 */

function app_config(
    ?string $key = null,
    mixed $default = null
): mixed {
    static $config = null;

    if ($config === null) {
        $configPath = dirname(__DIR__)
            . DIRECTORY_SEPARATOR
            . 'config'
            . DIRECTORY_SEPARATOR
            . 'app.php';

        if (!is_file($configPath)) {
            throw new RuntimeException(
                'File konfigurasi aplikasi tidak ditemukan.'
            );
        }

        $loadedConfig = require $configPath;

        if (!is_array($loadedConfig)) {
            throw new RuntimeException(
                'Konfigurasi aplikasi harus berupa array.'
            );
        }

        $config = $loadedConfig;
    }

    if ($key === null || trim($key) === '') {
        return $config;
    }

    $value = $config;

    foreach (explode('.', $key) as $segment) {
        if (
            !is_array($value)
            || !array_key_exists($segment, $value)
        ) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
}

/*
 * Escaping HTML
 * Digunakan setiap kali menampilkan data dari database atau input pengguna.
 * Contoh:
 * <?= e($user['name']) ?>
 */

function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }

    if (
        !is_scalar($value)
        && !$value instanceof Stringable
    ) {
        return '';
    }

    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/*
 * Membuat URL Aplikasi
 * Contoh:
 * base_url();
 * base_url('login.php');
 * base_url('motor/index.php');
 */

function base_url(string $path = ''): string
{
    $baseUrl = rtrim(
        (string) app_config(
            'base_url',
            'http://localhost:8000'
        ),
        '/'
    );

    $cleanPath = ltrim(
        trim($path),
        '/'
    );

    if ($cleanPath === '') {
        return $baseUrl;
    }

    return $baseUrl
        . '/'
        . $cleanPath;
}

/*
 * Redirect
 * Contoh:
 * redirect('login.php');
 * redirect('dashboard.php');
 */

function redirect(
    string $path,
    int $statusCode = 302
): never {
    $allowedStatusCodes = [
        301,
        302,
        303,
        307,
        308,
    ];

    if (
        !in_array(
            $statusCode,
            $allowedStatusCodes,
            true
        )
    ) {
        throw new InvalidArgumentException(
            'Status redirect tidak valid.'
        );
    }

    $isAbsoluteUrl = preg_match(
        '#^https?://#i',
        $path
    ) === 1;

    $location = $isAbsoluteUrl
        ? $path
        : base_url($path);

    if (
        str_contains($location, "\r")
        || str_contains($location, "\n")
    ) {
        throw new InvalidArgumentException(
            'Lokasi redirect tidak valid.'
        );
    }

    if (headers_sent($file, $line)) {
        throw new RuntimeException(
            'Redirect gagal karena output telah dikirim pada '
            . $file
            . ':'
            . $line
            . '.'
        );
    }

    header(
        'Location: ' . $location,
        true,
        $statusCode
    );

    exit;
}

// Pemeriksaan Metode Request

function request_method(): string
{
    return strtoupper(
        $_SERVER['REQUEST_METHOD'] ?? 'GET'
    );
}

function is_get(): bool
{
    return request_method() === 'GET';
}

function is_post(): bool
{
    return request_method() === 'POST';
}

/*
 * Membaca Input String
 * Contoh:
 * $username = input_string($_POST, 'username');
 */

function input_string(
    array $source,
    string $key,
    ?string $default = null
): ?string {
    if (!array_key_exists($key, $source)) {
        return $default;
    }

    $value = $source[$key];

    if (
        !is_scalar($value)
        && !$value instanceof Stringable
    ) {
        return $default;
    }

    $value = trim(
        (string) $value
    );

    return $value === ''
        ? $default
        : $value;
}

// Membaca Input Integer

function input_int(
    array $source,
    string $key,
    ?int $default = null
): ?int {
    $value = input_string(
        $source,
        $key
    );

    if ($value === null) {
        return $default;
    }

    $filteredValue = filter_var(
        $value,
        FILTER_VALIDATE_INT
    );

    if ($filteredValue === false) {
        return $default;
    }

    return (int) $filteredValue;
}

/*
 * Membaca Input Desimal
 * Mendukung input:
 * 8.7
 * 8,7
 */

function input_float(
    array $source,
    string $key,
    ?float $default = null
): ?float {
    $value = input_string(
        $source,
        $key
    );

    if ($value === null) {
        return $default;
    }

    $normalizedValue = str_replace(
        ',',
        '.',
        $value
    );

    $filteredValue = filter_var(
        $normalizedValue,
        FILTER_VALIDATE_FLOAT
    );

    if ($filteredValue === false) {
        return $default;
    }

    return (float) $filteredValue;
}

/*
 * Format Angka Indonesia
 * Contoh:
 * format_number_id(1234.56);
 * Hasil: 1.234,56
 */

function format_number_id(
    int|float|null $value,
    int $decimals = 2
): string {
    if ($value === null) {
        return '-';
    }

    $decimals = max(
        0,
        $decimals
    );

    return number_format(
        (float) $value,
        $decimals,
        ',',
        '.'
    );
}

// Format Persentase

function format_percentage(
    int|float|null $value,
    int $decimals = 2
): string {
    if ($value === null) {
        return '-';
    }

    return format_number_id(
        $value,
        $decimals
    ) . '%';
}

/*
 * Format Tanggal Bahasa Indonesia
 * Contoh:
 * format_date_id('2026-07-26');
 * Hasil: 26 Juli 2026
 */

function format_date_id(
    ?string $value,
    bool $withTime = false
): string {
    if (
        $value === null
        || trim($value) === ''
    ) {
        return '-';
    }

    try {
        $date = new DateTimeImmutable(
            $value
        );
    } catch (Throwable) {
        return '-';
    }

    $monthNames = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    $monthNumber = (int) $date->format('n');

    $formattedDate = $date->format('j')
        . ' '
        . $monthNames[$monthNumber]
        . ' '
        . $date->format('Y');

    if ($withTime) {
        $formattedDate .= ' '
            . $date->format('H:i');
    }

    return $formattedDate;
}

/*
 * Helper Elemen Select
 * Contoh:
 * <option value="admin"
 * <?= selected($role, 'admin') ?>>
 */

function selected(
    mixed $currentValue,
    mixed $expectedValue
): string {
    return (string) $currentValue
        === (string) $expectedValue
        ? 'selected'
        : '';
}

/*
 * Helper Checkbox dan Radio
 * Contoh:
 * <input type="radio"
 * <?= checked($score, 1) ?>>
 */

function checked(
    mixed $currentValue,
    mixed $expectedValue = true
): string {
    return (string) $currentValue
        === (string) $expectedValue
        ? 'checked'
        : '';
}

// Membatasi Panjang Teks

function limit_text(
    ?string $value,
    int $maximumLength = 100
): string {
    if (
        $value === null
        || trim($value) === ''
    ) {
        return '-';
    }

    $value = trim($value);
    $maximumLength = max(1, $maximumLength);

    if (
        function_exists('mb_strlen')
        && function_exists('mb_substr')
    ) {
        if (
            mb_strlen(
                $value,
                'UTF-8'
            ) <= $maximumLength
        ) {
            return $value;
        }

        return mb_substr(
            $value,
            0,
            $maximumLength,
            'UTF-8'
        ) . '...';
    }

    if (strlen($value) <= $maximumLength) {
        return $value;
    }

    return substr(
        $value,
        0,
        $maximumLength
    ) . '...';
}
/*
 * Kesiapan Data Master Enam Motor
 * Mengembalikan status kelengkapan A1-A6 untuk memulai periode aktif.
 * Data wajib: identitas alternatif, status aktif, arus nominal,
 * frekuensi nominal, dan RPM nominal.
 */

function machine_master_readiness(
    PDO $pdo
): array {
    $expected = [
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

    $statement = $pdo->query(
        "SELECT
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
             'A1', 'A2', 'A3', 'A4', 'A5', 'A6'
         )
         ORDER BY alternative_code COLLATE NOCASE"
    );

    $rows = $statement->fetchAll();
    $byCode = [];
    $duplicates = [];

    foreach ($rows as $row) {
        $code = strtoupper(
            trim((string) ($row['alternative_code'] ?? ''))
        );

        if (isset($byCode[$code])) {
            $duplicates[] = $code;
            continue;
        }

        $byCode[$code] = $row;
    }

    $missing = [];

    foreach ($expected as $code => $identity) {
        if (!isset($byCode[$code])) {
            $missing[] = $code . ': data motor tidak ditemukan';
            continue;
        }

        $row = $byCode[$code];

        if ((string) ($row['status'] ?? '') !== 'active') {
            $missing[] = $code . ': motor tidak aktif';
        }

        if (
            (string) ($row['machine_code'] ?? '')
                !== $identity['machine_code']
            || (int) ($row['machine_number'] ?? 0)
                !== $identity['machine_number']
            || (string) ($row['motor_function'] ?? '')
                !== $identity['motor_function']
        ) {
            $missing[] = $code . ': identitas alternatif tidak sesuai';
        }

        if (trim((string) ($row['motor_name'] ?? '')) === '') {
            $missing[] = $code . ': nama motor kosong';
        }

        foreach (
            [
                'rated_current' => 'arus nominal',
                'rated_frequency' => 'frekuensi nominal',
                'rated_rpm' => 'RPM nominal',
            ]
            as $field => $label
        ) {
            $value = $row[$field] ?? null;

            if (
                $value === null
                || $value === ''
                || !is_numeric((string) $value)
                || !is_finite((float) $value)
                || (float) $value <= 0
            ) {
                $missing[] = $code . ': ' . $label . ' belum lengkap';
            }
        }
    }

    foreach (array_unique($duplicates) as $duplicateCode) {
        $missing[] = $duplicateCode . ': alternatif terduplikasi';
    }

    return [
        'ready' => $missing === [],
        'ready_count' => count($expected)
            - count(array_unique(array_map(
                static fn (string $message): string =>
                    explode(':', $message, 2)[0],
                $missing
            ))),
        'expected_count' => count($expected),
        'missing' => array_values(array_unique($missing)),
    ];
}

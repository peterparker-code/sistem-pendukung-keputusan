<?php

declare(strict_types=1);

/*
 * Flash Message Sistem SPK PT CISAS
 * File ini digunakan untuk menyimpan data sementara di session:
 * - pesan sukses;
 * - pesan informasi;
 * - pesan peringatan;
 * - pesan kesalahan;
 * - kesalahan validasi;
 * - input lama dari form.
 * Data flash hanya digunakan untuk permintaan berikutnya dan dapat
 * dihapus setelah selesai ditampilkan.
 */

// Nama Penyimpanan Flash

const FLASH_SESSION_KEY = '_spk_cisas_flash';

// Memulai Session

function flash_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (session_status() === PHP_SESSION_DISABLED) {
        throw new RuntimeException(
            'Fitur session PHP tidak tersedia.'
        );
    }

    if (headers_sent($file, $line)) {
        throw new RuntimeException(
            'Session tidak dapat dimulai karena output sudah dikirim pada '
            . $file
            . ':'
            . $line
            . '.'
        );
    }

    // Periksa Apakah Koneksi Menggunakan HTTPS

    $httpsValue = strtolower(
        (string) ($_SERVER['HTTPS'] ?? '')
    );

    $isHttps = in_array(
        $httpsValue,
        [
            'on',
            '1',
            'true',
        ],
        true
    ) || (
        (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
    );

    // Nama Session Aplikasi

    session_name(
        'SPK_CISAS_SESSION'
    );

    // Jalankan Session

    $sessionStarted = session_start([
        'use_strict_mode' => true,
        'use_only_cookies' => true,
        'cookie_httponly' => true,
        'cookie_secure' => $isHttps,
        'cookie_samesite' => 'Lax',
        'cookie_lifetime' => 0,
        'cookie_path' => '/',
    ]);

    if ($sessionStarted !== true) {
        throw new RuntimeException(
            'Session aplikasi tidak berhasil dimulai.'
        );
    }
}

// Menyiapkan Struktur Penyimpanan Flash

function flash_initialize(): void
{
    flash_session_start();

    if (
        !isset($_SESSION[FLASH_SESSION_KEY])
        || !is_array($_SESSION[FLASH_SESSION_KEY])
    ) {
        $_SESSION[FLASH_SESSION_KEY] = [];
    }

    $defaultStorage = [
        'messages' => [],
        'errors' => [],
        'old_input' => [],
    ];

    foreach (
        $defaultStorage
        as $key => $defaultValue
    ) {
        if (
            !isset(
                $_SESSION[FLASH_SESSION_KEY][$key]
            )
            || !is_array(
                $_SESSION[FLASH_SESSION_KEY][$key]
            )
        ) {
            $_SESSION[FLASH_SESSION_KEY][$key] =
                $defaultValue;
        }
    }
}

/*
 * Menyimpan Pesan Flash
 * Tipe yang didukung mengikuti class alert Bootstrap:
 * - success;
 * - info;
 * - warning;
 * - danger.
 */

function flash_add(
    string $type,
    string $message
): void {
    flash_initialize();

    $allowedTypes = [
        'success',
        'info',
        'warning',
        'danger',
    ];

    if (
        !in_array(
            $type,
            $allowedTypes,
            true
        )
    ) {
        throw new InvalidArgumentException(
            'Tipe pesan flash tidak valid.'
        );
    }

    $message = trim(
        $message
    );

    if ($message === '') {
        throw new InvalidArgumentException(
            'Isi pesan flash tidak boleh kosong.'
        );
    }

    $_SESSION[FLASH_SESSION_KEY]['messages'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

// Pesan Sukses

function flash_success(string $message): void
{
    flash_add(
        'success',
        $message
    );
}

// Pesan Informasi

function flash_info(string $message): void
{
    flash_add(
        'info',
        $message
    );
}

// Pesan Peringatan

function flash_warning(string $message): void
{
    flash_add(
        'warning',
        $message
    );
}

// Pesan Kesalahan

function flash_error(string $message): void
{
    flash_add(
        'danger',
        $message
    );
}

// Memeriksa Pesan Flash

function flash_has_messages(): bool
{
    flash_initialize();

    return $_SESSION[FLASH_SESSION_KEY]['messages']
        !== [];
}

/*
 * Mengambil Pesan Flash
 * Jika $consume bernilai true, pesan dihapus setelah diambil.
 */

function flash_get_messages(
    bool $consume = true
): array {
    flash_initialize();

    $messages =
        $_SESSION[FLASH_SESSION_KEY]['messages'];

    if ($consume) {
        $_SESSION[FLASH_SESSION_KEY]['messages'] = [];
    }

    return $messages;
}

// Menghapus Seluruh Pesan Flash

function flash_clear_messages(): void
{
    flash_initialize();

    $_SESSION[FLASH_SESSION_KEY]['messages'] = [];
}

/*
 * Menyimpan Kesalahan Validasi
 * Bentuk data:
 * [
 * 'username' => [
 * 'Username wajib diisi.',
 * ],
 * ]
 */

function flash_set_errors(array $errors): void
{
    flash_initialize();

    $normalizedErrors = [];

    foreach ($errors as $field => $messages) {
        $fieldName = trim(
            (string) $field
        );

        if ($fieldName === '') {
            continue;
        }

        if (is_string($messages)) {
            $messages = [
                $messages,
            ];
        }

        if (!is_array($messages)) {
            continue;
        }

        foreach ($messages as $message) {
            if (
                !is_scalar($message)
                && !$message instanceof Stringable
            ) {
                continue;
            }

            $messageText = trim(
                (string) $message
            );

            if ($messageText === '') {
                continue;
            }

            $normalizedErrors[$fieldName][] =
                $messageText;
        }
    }

    $_SESSION[FLASH_SESSION_KEY]['errors'] =
        $normalizedErrors;
}

// Memeriksa Kesalahan Validasi

function flash_has_errors(): bool
{
    flash_initialize();

    return $_SESSION[FLASH_SESSION_KEY]['errors']
        !== [];
}

// Mengambil Kesalahan Validasi

function flash_get_errors(
    bool $consume = true
): array {
    flash_initialize();

    $errors =
        $_SESSION[FLASH_SESSION_KEY]['errors'];

    if ($consume) {
        $_SESSION[FLASH_SESSION_KEY]['errors'] = [];
    }

    return $errors;
}

// Menghapus Kesalahan Validasi

function flash_clear_errors(): void
{
    flash_initialize();

    $_SESSION[FLASH_SESSION_KEY]['errors'] = [];
}

/*
 * Menyaring Input Lama
 * Password dan token keamanan tidak boleh disimpan sebagai input lama.
 */

function flash_filter_old_input(
    array $input,
    array $excludedKeys
): array {
    $filteredInput = [];

    foreach ($input as $key => $value) {
        $keyString = (string) $key;

        if (
            in_array(
                strtolower($keyString),
                $excludedKeys,
                true
            )
        ) {
            continue;
        }

        if (is_array($value)) {
            $filteredInput[$key] =
                flash_filter_old_input(
                    $value,
                    $excludedKeys
                );

            continue;
        }

        if (
            is_scalar($value)
            || $value === null
        ) {
            $filteredInput[$key] = $value;
        }
    }

    return $filteredInput;
}

// Menyimpan Input Lama

function flash_set_old_input(
    array $input,
    array $additionalExcludedKeys = []
): void {
    flash_initialize();

    $defaultExcludedKeys = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'new_password_confirmation',
        'csrf_token',
        '_token',
    ];

    $excludedKeys = array_values(
        array_unique(
            array_map(
                static fn (mixed $key): string
                    => strtolower(
                        trim(
                            (string) $key
                        )
                    ),
                array_merge(
                    $defaultExcludedKeys,
                    $additionalExcludedKeys
                )
            )
        )
    );

    $_SESSION[FLASH_SESSION_KEY]['old_input'] =
        flash_filter_old_input(
            $input,
            $excludedKeys
        );
}

// Memeriksa Input Lama

function flash_has_old_input(): bool
{
    flash_initialize();

    return $_SESSION[FLASH_SESSION_KEY]['old_input']
        !== [];
}

// Mengambil Input Lama

function flash_get_old_input(
    bool $consume = true
): array {
    flash_initialize();

    $oldInput =
        $_SESSION[FLASH_SESSION_KEY]['old_input'];

    if ($consume) {
        $_SESSION[FLASH_SESSION_KEY]['old_input'] = [];
    }

    return $oldInput;
}

// Menghapus Input Lama

function flash_clear_old_input(): void
{
    flash_initialize();

    $_SESSION[FLASH_SESSION_KEY]['old_input'] = [];
}

// Menghapus Seluruh Data Flash

function flash_clear_all(): void
{
    flash_initialize();

    /*
 * Hapus Hanya Data Flash
 * Data login pengguna tidak ikut dihapus.
 */

    unset(
        $_SESSION[FLASH_SESSION_KEY]
    );

    $_SESSION[FLASH_SESSION_KEY] = [
        'messages' => [],
        'errors' => [],
        'old_input' => [],
    ];
}

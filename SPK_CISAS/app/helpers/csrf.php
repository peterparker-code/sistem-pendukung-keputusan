<?php

declare(strict_types=1);

/*
 * Perlindungan CSRF Sistem SPK PT CISAS
 * File ini digunakan untuk:
 * - membuat token CSRF;
 * - menyimpan token di session;
 * - menampilkan hidden input pada form;
 * - memvalidasi token dari request POST;
 * - menghapus token setelah berhasil digunakan;
 * - menghapus token yang sudah kedaluwarsa.
 */

require_once __DIR__
    . DIRECTORY_SEPARATOR
    . 'functions.php';

require_once __DIR__
    . DIRECTORY_SEPARATOR
    . 'flash.php';

// Konstanta CSRF

const CSRF_SESSION_KEY = '_spk_cisas_csrf';

const CSRF_INPUT_NAME = '_csrf_token';

const CSRF_TOKEN_LIFETIME = 7200;

// Memulai Penyimpanan CSRF

function csrf_initialize(): void
{
    flash_session_start();

    if (
        !isset($_SESSION[CSRF_SESSION_KEY])
        || !is_array($_SESSION[CSRF_SESSION_KEY])
    ) {
        $_SESSION[CSRF_SESSION_KEY] = [];
    }

    csrf_remove_expired_tokens();
}

/*
 * Normalisasi Nama Form
 * Contoh:
 * login
 * motor_create
 * assessment_save
 */

function csrf_normalize_form_name(
    string $formName
): string {
    $formName = strtolower(
        trim($formName)
    );

    if ($formName === '') {
        return 'default';
    }

    if (
        preg_match(
            '/^[a-z0-9._-]+$/',
            $formName
        ) !== 1
    ) {
        throw new InvalidArgumentException(
            'Nama form CSRF tidak valid.'
        );
    }

    return $formName;
}

// Membuat Token Acak

function csrf_generate_token(): string
{
    try {
        return bin2hex(
            random_bytes(32)
        );
    } catch (Throwable $exception) {
        throw new RuntimeException(
            'Token keamanan tidak berhasil dibuat.',
            0,
            $exception
        );
    }
}

// Menghapus Token Kedaluwarsa

function csrf_remove_expired_tokens(): void
{
    flash_session_start();

    if (
        !isset($_SESSION[CSRF_SESSION_KEY])
        || !is_array($_SESSION[CSRF_SESSION_KEY])
    ) {
        $_SESSION[CSRF_SESSION_KEY] = [];

        return;
    }

    $currentTime = time();

    foreach (
        $_SESSION[CSRF_SESSION_KEY]
        as $formName => $tokenData
    ) {
        if (
            !is_array($tokenData)
            || !isset(
                $tokenData['token'],
                $tokenData['created_at']
            )
        ) {
            unset(
                $_SESSION[CSRF_SESSION_KEY][$formName]
            );

            continue;
        }

        $createdAt = (int) $tokenData['created_at'];

        if (
            $createdAt <= 0
            || (
                $currentTime - $createdAt
            ) > CSRF_TOKEN_LIFETIME
        ) {
            unset(
                $_SESSION[CSRF_SESSION_KEY][$formName]
            );
        }
    }
}

/*
 * Membuat atau Mengambil Token Form
 * Jika $regenerate bernilai true, token lama diganti.
 */

function csrf_token(
    string $formName = 'default',
    bool $regenerate = false
): string {
    csrf_initialize();

    $normalizedFormName =
        csrf_normalize_form_name(
            $formName
        );

    $tokenExists = isset(
        $_SESSION[CSRF_SESSION_KEY]
            [$normalizedFormName]['token']
    );

    if (
        $regenerate
        || !$tokenExists
    ) {
        $_SESSION[CSRF_SESSION_KEY]
            [$normalizedFormName] = [
                'token' => csrf_generate_token(),
                'created_at' => time(),
            ];
    }

    return (string) $_SESSION[CSRF_SESSION_KEY]
        [$normalizedFormName]['token'];
}

/*
 * Menampilkan Hidden Input CSRF
 * Contoh penggunaan dalam form:
 * <?= csrf_field('login') ?>
 */

function csrf_field(
    string $formName = 'default'
): string {
    $token = csrf_token(
        $formName
    );

    return '<input type="hidden" name="'
        . e(CSRF_INPUT_NAME)
        . '" value="'
        . e($token)
        . '">';
}

// Mengambil Token dari Request

function csrf_request_token(): ?string
{
    $token = $_POST[CSRF_INPUT_NAME]
        ?? null;

    if (
        !is_scalar($token)
        && !$token instanceof Stringable
    ) {
        return null;
    }

    $token = trim(
        (string) $token
    );

    return $token === ''
        ? null
        : $token;
}

// Memeriksa Apakah Token Tersedia

function csrf_has_token(
    string $formName = 'default'
): bool {
    csrf_initialize();

    $normalizedFormName =
        csrf_normalize_form_name(
            $formName
        );

    return isset(
        $_SESSION[CSRF_SESSION_KEY]
            [$normalizedFormName]['token']
    );
}

/*
 * Memvalidasi Token
 * Jika $consume bernilai true, token dihapus setelah berhasil digunakan.
 * Form berikutnya akan memperoleh token baru.
 */

function csrf_validate(
    ?string $submittedToken,
    string $formName = 'default',
    bool $consume = true
): bool {
    csrf_initialize();

    if (
        $submittedToken === null
        || trim($submittedToken) === ''
    ) {
        return false;
    }

    $normalizedFormName =
        csrf_normalize_form_name(
            $formName
        );

    $storedData = $_SESSION[CSRF_SESSION_KEY]
        [$normalizedFormName]
        ?? null;

    if (
        !is_array($storedData)
        || !isset(
            $storedData['token'],
            $storedData['created_at']
        )
    ) {
        return false;
    }

    $storedToken = (string) $storedData['token'];
    $createdAt = (int) $storedData['created_at'];

    if (
        $storedToken === ''
        || $createdAt <= 0
    ) {
        csrf_forget_token(
            $normalizedFormName
        );

        return false;
    }

    if (
        time() - $createdAt
        > CSRF_TOKEN_LIFETIME
    ) {
        csrf_forget_token(
            $normalizedFormName
        );

        return false;
    }

    /*
 * Perbandingan Aman
 * Token yang tersimpan ditempatkan sebagai parameter pertama.
 * Token dari pengguna ditempatkan sebagai parameter kedua.
 */

    $isValid = hash_equals(
        $storedToken,
        $submittedToken
    );

    if (
        $isValid
        && $consume
    ) {
        csrf_forget_token(
            $normalizedFormName
        );
    }

    return $isValid;
}

// Memvalidasi Token dari Request POST

function csrf_validate_request(
    string $formName = 'default',
    bool $consume = true
): bool {
    if (!is_post()) {
        return false;
    }

    return csrf_validate(
        csrf_request_token(),
        $formName,
        $consume
    );
}

/*
 * Memastikan Request Memiliki Token yang Benar
 * Fungsi ini dapat digunakan pada file pemrosesan form.
 * Contoh:
 * csrf_require_valid_request('motor_update');
 */

function csrf_require_valid_request(
    string $formName = 'default',
    bool $consume = true
): void {
    if (!is_post()) {
        throw new RuntimeException(
            'Metode request tidak diizinkan.'
        );
    }

    if (
        !csrf_validate_request(
            $formName,
            $consume
        )
    ) {
        throw new RuntimeException(
            'Token keamanan tidak valid atau telah kedaluwarsa.'
        );
    }
}

// Menghapus Token Satu Form

function csrf_forget_token(
    string $formName = 'default'
): void {
    csrf_initialize();

    $normalizedFormName =
        csrf_normalize_form_name(
            $formName
        );

    unset(
        $_SESSION[CSRF_SESSION_KEY]
            [$normalizedFormName]
    );
}

// Memperbarui Token

function csrf_regenerate(
    string $formName = 'default'
): string {
    return csrf_token(
        $formName,
        true
    );
}

/*
 * Menghapus Seluruh Token CSRF
 * Dapat digunakan saat pengguna logout.
 */

function csrf_clear_all(): void
{
    flash_session_start();

    $_SESSION[CSRF_SESSION_KEY] = [];
}

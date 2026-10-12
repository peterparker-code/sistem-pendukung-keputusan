<?php

declare(strict_types=1);

/*
 * Template Pesan Sistem SPK PT CISAS
 * File ini menampilkan:
 * - pesan flash sukses;
 * - pesan flash informasi;
 * - pesan flash peringatan;
 * - pesan flash kesalahan;
 * - ringkasan kesalahan validasi.
 * Pesan yang telah ditampilkan akan dihapus dari session.
 */

// Pastikan Helper Tersedia

if (!function_exists('e')) {
    require_once dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . 'app'
        . DIRECTORY_SEPARATOR
        . 'helpers'
        . DIRECTORY_SEPARATOR
        . 'functions.php';
}

if (!function_exists('flash_get_messages')) {
    require_once dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . 'app'
        . DIRECTORY_SEPARATOR
        . 'helpers'
        . DIRECTORY_SEPARATOR
        . 'flash.php';
}

/*
 * Ambil Pesan Flash
 * Halaman dapat menentukan variabel $alertMessages secara manual.
 * Jika tidak tersedia, pesan diambil dari session flash.
 */

$displayMessages = isset($alertMessages)
    && is_array($alertMessages)
        ? $alertMessages
        : flash_get_messages();

/*
 * Ambil Kesalahan Validasi
 * Halaman dapat memakai variabel:
 * $errors = [
 * 'username' => [
 * 'Username wajib diisi.',
 * ],
 * ];
 * Jika $errors tidak tersedia, kesalahan diambil dari session flash.
 */

$displayErrors = isset($errors)
    && is_array($errors)
        ? $errors
        : flash_get_errors();

// Konfigurasi Tampilan Pesan

$alertConfigurations = [
    'success' => [
        'class' => 'success',
        'title' => 'Berhasil',
    ],

    'info' => [
        'class' => 'info',
        'title' => 'Informasi',
    ],

    'warning' => [
        'class' => 'warning',
        'title' => 'Perhatian',
    ],

    'danger' => [
        'class' => 'danger',
        'title' => 'Terjadi Kesalahan',
    ],
];

// Normalisasi Pesan Flash

$normalizedMessages = [];

foreach ($displayMessages as $messageData) {
    if (!is_array($messageData)) {
        continue;
    }

    $type = isset($messageData['type'])
        ? trim((string) $messageData['type'])
        : '';

    $message = isset($messageData['message'])
        ? trim((string) $messageData['message'])
        : '';

    if (
        $message === ''
        || !isset($alertConfigurations[$type])
    ) {
        continue;
    }

    $normalizedMessages[] = [
        'type' => $type,
        'message' => $message,
    ];
}

// Normalisasi Kesalahan Validasi

$normalizedErrors = [];

foreach ($displayErrors as $field => $messages) {
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

        $normalizedErrors[] = $messageText;
    }
}
?>

<?php if ($normalizedMessages !== []): ?>
    <div class="alert-container" aria-label="Pemberitahuan sistem">
        <?php foreach ($normalizedMessages as $index => $messageData): ?>
            <?php
            $type = $messageData['type'];

            $configuration =
                $alertConfigurations[$type];

            $alertId = 'system-alert-'
                . ($index + 1);
            ?>

            <div id="<?= e($alertId) ?>" class="alert alert-<?= e($configuration['class']) ?> alert-dismissible fade show" role="alert">
                <div class="d-flex align-items-start gap-2">
                    <div class="flex-grow-1">
                        <strong class="d-block mb-1">
                            <?= e($configuration['title']) ?>
                        </strong>

                        <div>
                            <?= e($messageData['message']) ?>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"
                        aria-label="Tutup pemberitahuan"
                    ></button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($normalizedErrors !== []): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert" aria-labelledby="validation-error-title">
        <div class="d-flex align-items-start gap-2">
            <div class="flex-grow-1">
                <strong id="validation-error-title" class="d-block mb-2">
                    Data belum dapat diproses
                </strong>

                <p class="mb-2">
                    Periksa kembali bagian berikut:
                </p>

                <ul class="mb-0 ps-4">
                    <?php foreach ($normalizedErrors as $errorMessage): ?>
                        <li>
                            <?= e($errorMessage) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
                aria-label="Tutup daftar kesalahan"
            ></button>
        </div>
    </div>
<?php endif; ?>

<?php

declare(strict_types=1);

/*
 * Validasi Sistem SPK PT CISAS
 * File ini menangani validasi:
 * - pengguna;
 * - data motor;
 * - periode pemeriksaan;
 * - pengukuran C1, C2, dan C3;
 * - checklist C4 dan C5;
 * - status checklist;
 * - kelengkapan pemeriksaan.
 */

require_once __DIR__
    . DIRECTORY_SEPARATOR
    . 'functions.php';

/*
 * Pemeriksaan Nilai Kosong
 * Angka 0 tidak dianggap kosong.
 */

function validation_is_empty(mixed $value): bool
{
    if ($value === null) {
        return true;
    }

    if (is_string($value)) {
        return trim($value) === '';
    }

    if (is_array($value)) {
        return $value === [];
    }

    return false;
}

// Menambahkan Kesalahan

function validation_add_error(
    array &$errors,
    string $field,
    ?string $message
): void {
    if ($message === null) {
        return;
    }

    if (!isset($errors[$field])) {
        $errors[$field] = [];
    }

    $errors[$field][] = $message;
}

// Menggabungkan Kesalahan

function validation_merge_errors(
    array &$errors,
    array $newErrors
): void {
    foreach ($newErrors as $field => $messages) {
        if (!is_array($messages)) {
            continue;
        }

        foreach ($messages as $message) {
            if (is_string($message)) {
                validation_add_error(
                    $errors,
                    (string) $field,
                    $message
                );
            }
        }
    }
}

// Pemeriksaan Kesalahan

function validation_has_errors(array $errors): bool
{
    foreach ($errors as $messages) {
        if (
            is_array($messages)
            && $messages !== []
        ) {
            return true;
        }
    }

    return false;
}

// Mengambil Kesalahan Pertama

function validation_first_error(
    array $errors,
    string $field
): ?string {
    if (
        !isset($errors[$field])
        || !is_array($errors[$field])
        || $errors[$field] === []
    ) {
        return null;
    }

    $firstError = reset(
        $errors[$field]
    );

    return is_string($firstError)
        ? $firstError
        : null;
}

// Mengambil Semua Pesan Kesalahan

function validation_all_errors(
    array $errors
): array {
    $allErrors = [];

    foreach ($errors as $messages) {
        if (!is_array($messages)) {
            continue;
        }

        foreach ($messages as $message) {
            if (is_string($message)) {
                $allErrors[] = $message;
            }
        }
    }

    return $allErrors;
}

// Class Bootstrap untuk Form

function validation_class(
    array $errors,
    string $field
): string {
    return validation_first_error(
        $errors,
        $field
    ) !== null
        ? 'is-invalid'
        : '';
}

// Validasi Wajib Isi

function validation_required(
    mixed $value,
    string $label
): ?string {
    if (validation_is_empty($value)) {
        return $label . ' wajib diisi.';
    }

    return null;
}

// Menghitung Panjang Teks

function validation_text_length(
    string $value
): int {
    if (function_exists('mb_strlen')) {
        return mb_strlen(
            $value,
            'UTF-8'
        );
    }

    return strlen($value);
}

// Validasi Teks

function validation_text(
    mixed $value,
    string $label,
    int $minimumLength = 0,
    int $maximumLength = 255,
    bool $required = true
): ?string {
    if (validation_is_empty($value)) {
        return $required
            ? $label . ' wajib diisi.'
            : null;
    }

    if (
        !is_scalar($value)
        && !$value instanceof Stringable
    ) {
        return $label
            . ' harus berupa teks.';
    }

    $text = trim(
        (string) $value
    );

    $length = validation_text_length(
        $text
    );

    if (
        $minimumLength > 0
        && $length < $minimumLength
    ) {
        return $label
            . ' minimal '
            . $minimumLength
            . ' karakter.';
    }

    if ($length > $maximumLength) {
        return $label
            . ' maksimal '
            . $maximumLength
            . ' karakter.';
    }

    return null;
}

// Validasi Integer

function validation_integer(
    mixed $value,
    string $label,
    ?int $minimum = null,
    ?int $maximum = null,
    bool $required = true
): ?string {
    if (validation_is_empty($value)) {
        return $required
            ? $label . ' wajib diisi.'
            : null;
    }

    if (is_array($value)) {
        return $label
            . ' harus berupa bilangan bulat.';
    }

    $validatedValue = filter_var(
        $value,
        FILTER_VALIDATE_INT
    );

    if ($validatedValue === false) {
        return $label
            . ' harus berupa bilangan bulat.';
    }

    $integerValue = (int) $validatedValue;

    if (
        $minimum !== null
        && $integerValue < $minimum
    ) {
        return $label
            . ' minimal '
            . $minimum
            . '.';
    }

    if (
        $maximum !== null
        && $integerValue > $maximum
    ) {
        return $label
            . ' maksimal '
            . $maximum
            . '.';
    }

    return null;
}

/*
 * Normalisasi Desimal
 * Mendukung angka:
 * 8.7
 * 8,7
 */

function validation_normalize_decimal(
    mixed $value
): ?float {
    if (validation_is_empty($value)) {
        return null;
    }

    if (is_array($value)) {
        return null;
    }

    $normalizedValue = str_replace(
        ',',
        '.',
        trim((string) $value)
    );

    $validatedValue = filter_var(
        $normalizedValue,
        FILTER_VALIDATE_FLOAT
    );

    if ($validatedValue === false) {
        return null;
    }

    $floatValue = (float) $validatedValue;

    if (!is_finite($floatValue)) {
        return null;
    }

    return $floatValue;
}

// Validasi Desimal

function validation_decimal(
    mixed $value,
    string $label,
    ?float $minimum = null,
    ?float $maximum = null,
    bool $required = true
): ?string {
    if (validation_is_empty($value)) {
        return $required
            ? $label . ' wajib diisi.'
            : null;
    }

    $floatValue = validation_normalize_decimal(
        $value
    );

    if ($floatValue === null) {
        return $label
            . ' harus berupa angka.';
    }

    if (
        $minimum !== null
        && $floatValue < $minimum
    ) {
        return $label
            . ' minimal '
            . format_number_id($minimum)
            . '.';
    }

    if (
        $maximum !== null
        && $floatValue > $maximum
    ) {
        return $label
            . ' maksimal '
            . format_number_id($maximum)
            . '.';
    }

    return null;
}

// Validasi Pilihan

function validation_choice(
    mixed $value,
    string $label,
    array $allowedValues,
    bool $required = true
): ?string {
    if (validation_is_empty($value)) {
        return $required
            ? $label . ' wajib dipilih.'
            : null;
    }

    if (
        !is_scalar($value)
        && !$value instanceof Stringable
    ) {
        return $label . ' tidak valid.';
    }

    $stringValue = (string) $value;

    $normalizedAllowedValues = array_map(
        static fn (mixed $item): string
            => (string) $item,
        $allowedValues
    );

    if (
        !in_array(
            $stringValue,
            $normalizedAllowedValues,
            true
        )
    ) {
        return $label . ' tidak valid.';
    }

    return null;
}

/*
 * Validasi Tanggal
 * Format: YYYY-MM-DD
 */

function validation_date(
    mixed $value,
    string $label,
    bool $required = true,
    string $format = 'Y-m-d'
): ?string {
    if (validation_is_empty($value)) {
        return $required
            ? $label . ' wajib diisi.'
            : null;
    }

    if (
        !is_scalar($value)
        && !$value instanceof Stringable
    ) {
        return $label . ' tidak valid.';
    }

    $dateValue = trim(
        (string) $value
    );

    $date = DateTimeImmutable::createFromFormat(
        '!' . $format,
        $dateValue
    );

    $dateErrors =
        DateTimeImmutable::getLastErrors();

    $hasDateError =
        $dateErrors !== false
        && (
            $dateErrors['warning_count'] > 0
            || $dateErrors['error_count'] > 0
        );

    if (
        $date === false
        || $hasDateError
        || $date->format($format) !== $dateValue
    ) {
        return $label . ' tidak valid.';
    }

    return null;
}

/*
 * Validasi Waktu
 * Format: HH:MM
 */

function validation_time(
    mixed $value,
    string $label,
    bool $required = true
): ?string {
    if (validation_is_empty($value)) {
        return $required
            ? $label . ' wajib diisi.'
            : null;
    }

    if (
        !is_scalar($value)
        && !$value instanceof Stringable
    ) {
        return $label . ' tidak valid.';
    }

    $timeValue = trim(
        (string) $value
    );

    $time = DateTimeImmutable::createFromFormat(
        '!H:i',
        $timeValue
    );

    $timeErrors =
        DateTimeImmutable::getLastErrors();

    $hasTimeError =
        $timeErrors !== false
        && (
            $timeErrors['warning_count'] > 0
            || $timeErrors['error_count'] > 0
        );

    if (
        $time === false
        || $hasTimeError
        || $time->format('H:i') !== $timeValue
    ) {
        return $label . ' tidak valid.';
    }

    return null;
}

// Validasi Rentang Tanggal

function validation_date_range(
    mixed $startDate,
    mixed $endDate,
    string $startLabel = 'Tanggal mulai',
    string $endLabel = 'Tanggal selesai'
): ?string {
    if (
        validation_is_empty($startDate)
        || validation_is_empty($endDate)
    ) {
        return null;
    }

    if (
        validation_date(
            $startDate,
            $startLabel
        ) !== null
        || validation_date(
            $endDate,
            $endLabel
        ) !== null
    ) {
        return null;
    }

    $start = new DateTimeImmutable(
        (string) $startDate
    );

    $end = new DateTimeImmutable(
        (string) $endDate
    );

    if ($end < $start) {
        return $endLabel
            . ' tidak boleh sebelum '
            . strtolower($startLabel)
            . '.';
    }

    return null;
}

// Validasi Username

function validation_username(
    mixed $value,
    string $label = 'Username'
): ?string {
    $textError = validation_text(
        $value,
        $label,
        3,
        30,
        true
    );

    if ($textError !== null) {
        return $textError;
    }

    $username = trim(
        (string) $value
    );

    if (
        preg_match(
            '/^[A-Za-z0-9._-]+$/',
            $username
        ) !== 1
    ) {
        return $label
            . ' hanya boleh berisi huruf, angka, titik, '
            . 'garis bawah, dan tanda hubung.';
    }

    return null;
}

// Validasi Password

function validation_password(
    mixed $value,
    string $label = 'Password',
    int $minimumLength = 8,
    bool $required = true
): ?string {
    return validation_text(
        $value,
        $label,
        $minimumLength,
        255,
        $required
    );
}

// Validasi Peran Pengguna

function validation_role(
    mixed $value
): ?string {
    return validation_choice(
        $value,
        'Peran pengguna',
        [
            'admin',
            'supervisor',
            'technician',
        ]
    );
}

// Validasi Status Periode

function validation_period_status(
    mixed $value
): ?string {
    return validation_choice(
        $value,
        'Status periode',
        [
            'draft',
            'active',
            'completed',
        ]
    );
}

// Validasi Status Pemeriksaan

function validation_assessment_status(
    mixed $value
): ?string {
    return validation_choice(
        $value,
        'Status pemeriksaan',
        [
            'draft',
            'completed',
            'validated',
            'returned',
            'processed',
        ]
    );
}

// Validasi Kondisi Operasi Motor

function validation_operating_status(
    mixed $value
): ?string {
    return validation_choice(
        $value,
        'Kondisi operasi',
        [
            'running',
            'stopped',
        ]
    );
}

// Validasi Kondisi Beban

function validation_load_condition(
    mixed $value,
    bool $required = true
): ?string {
    return validation_choice(
        $value,
        'Kondisi beban',
        [
            'no_load',
            'light',
            'normal',
            'high',
        ],
        $required
    );
}

/*
 * Validasi Kondisi Checklist
 * Normal          = normal
 * Perlu Perhatian = warning
 * Bahaya          = danger
 * Tidak berlaku   = not_applicable
 * Belum diperiksa = not_checked
 * Bagian kosong boleh disimpan sementara sebagai Draft.
 * Tidak Tersedia hanya boleh digunakan jika $allowsNa bernilai true.
 */

function validation_checklist_condition(
    mixed $value,
    string $label,
    bool $allowsNa = false
): ?string {
    $allowedConditions = [
        'normal',
        'warning',
        'danger',
        'not_checked',
    ];

    if ($allowsNa) {
        $allowedConditions[] =
            'not_applicable';
    }

    return validation_choice(
        $value,
        $label,
        $allowedConditions
    );
}

/*
 * Mengubah Kondisi Checklist Menjadi Skor
 * Normal          = 1
 * Perlu Perhatian = 2
 * Bahaya          = 3
 * Tidak berlaku   = NULL
 * Belum diperiksa = NULL
 */

function checklist_score_from_condition(
    string $condition,
    bool $allowsNa = false
): ?int {
    return match ($condition) {
        'normal' => 1,

        'warning' => 2,

        'danger' => 3,

        'not_checked' => null,

        'not_applicable' => $allowsNa
            ? null
            : throw new InvalidArgumentException(
                'Bagian ini tidak mendukung pilihan Tidak Tersedia.'
            ),

        default => throw new InvalidArgumentException(
            'Kondisi checklist tidak valid.'
        ),
    };
}

// Label Kondisi Checklist

function checklist_condition_label(
    string $condition
): string {
    return match ($condition) {
        'normal' => 'Normal',
        'warning' => 'Perlu Perhatian',
        'danger' => 'Bahaya',
        'not_applicable' => 'Tidak Tersedia',
        'not_checked' => 'Belum diisi',
        default => 'Tidak diketahui',
    };
}

/*
 * Validasi Temuan atau Keterangan
 * Temuan wajib untuk:
 * - Perlu Perhatian;
 * - Bahaya;
 * - Belum diperiksa.
 */

function validation_finding_note(
    mixed $condition,
    mixed $findingNote,
    string $label = 'Temuan atau keterangan'
): ?string {
    if (
        !in_array(
            $condition,
            [
                'warning',
                'danger',
                'not_checked',
            ],
            true
        )
    ) {
        return null;
    }

    return validation_text(
        $findingNote,
        $label,
        3,
        1000,
        true
    );
}

// Validasi Satu Item Checklist

function validation_checklist_item(
    mixed $condition,
    mixed $findingNote,
    string $label,
    bool $allowsNa = false
): array {
    $errors = [];

    validation_add_error(
        $errors,
        'condition',
        validation_checklist_condition(
            $condition,
            $label,
            $allowsNa
        )
    );

    if (
        !validation_is_empty($condition)
        && validation_first_error(
            $errors,
            'condition'
        ) === null
    ) {
        validation_add_error(
            $errors,
            'finding_note',
            validation_finding_note(
                $condition,
                $findingNote
            )
        );
    }

    return $errors;
}

/*
 * Validasi Kelengkapan Checklist
 * Pemeriksaan berstatus completed, validated, atau processed
 * seluruh checklist wajib terisi.
 */

function validation_checklist_completion(
    array $conditions,
    string $assessmentStatus
): ?string {
    if (
        !in_array(
            $assessmentStatus,
            [
                'completed',
                'validated',
                'processed',
            ],
            true
        )
    ) {
        return null;
    }

    if ($conditions === []) {
        return 'Checklist pemeriksaan belum diisi.';
    }

    foreach ($conditions as $condition) {
        if ($condition === 'not_checked') {
            return 'Pemeriksaan masih mempunyai indikator '
                . 'bagian checklist yang belum diisi.';
        }
    }

    return null;
}

// Menghitung Rekap Kondisi Checklist

function checklist_condition_summary(
    array $conditions
): array {
    $summary = [
        'normal_count' => 0,
        'warning_count' => 0,
        'danger_count' => 0,
        'na_count' => 0,
        'not_checked_count' => 0,
        'evaluated_count' => 0,
        'total_score' => 0,
        'average' => null,
        'has_danger' => false,
        'is_complete' => true,
    ];

    foreach ($conditions as $condition) {
        switch ($condition) {
            case 'normal':
                $summary['normal_count']++;
                $summary['evaluated_count']++;
                $summary['total_score'] += 1;
                break;

            case 'warning':
                $summary['warning_count']++;
                $summary['evaluated_count']++;
                $summary['total_score'] += 2;
                break;

            case 'danger':
                $summary['danger_count']++;
                $summary['evaluated_count']++;
                $summary['total_score'] += 3;
                $summary['has_danger'] = true;
                break;

            case 'not_applicable':
                $summary['na_count']++;
                break;

            case 'not_checked':
                $summary['not_checked_count']++;
                $summary['is_complete'] = false;
                break;
        }
    }

    if ($summary['evaluated_count'] > 0) {
        $summary['average'] =
            $summary['total_score']
            / $summary['evaluated_count'];
    }

    return $summary;
}

/*
 * Validasi Tiga Hasil Pengukuran
 * Contoh prefix:
 * temperature
 * current
 * rpm
 * Field:
 * temperature_1
 * temperature_2
 * temperature_3
 */

function validation_measurement_triplet(
    array $source,
    string $fieldPrefix,
    string $label,
    float $minimum = 0,
    ?float $maximum = null,
    bool $required = true
): array {
    $errors = [];

    for ($index = 1; $index <= 3; $index++) {
        $field = $fieldPrefix
            . '_'
            . $index;

        $fieldLabel = $label
            . ' '
            . $index;

        validation_add_error(
            $errors,
            $field,
            validation_decimal(
                $source[$field] ?? null,
                $fieldLabel,
                $minimum,
                $maximum,
                $required
            )
        );
    }

    return $errors;
}

<?php

declare(strict_types=1);

/*
 * Footer Sistem SPK PT. CISAS
 * Footer visual dibuat ringkas agar tidak mengulangi:
 * - logo perusahaan;
 * - nama metode;
 * - deskripsi aplikasi yang terlalu panjang.
 * File ini juga memuat:
 * - Bootstrap JavaScript Bundle;
 * - JavaScript utama aplikasi;
 * - JavaScript tambahan per halaman.
 */

// Pastikan Helper Tersedia

if (!function_exists('base_url')) {
    require_once dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . 'app'
        . DIRECTORY_SEPARATOR
        . 'helpers'
        . DIRECTORY_SEPARATOR
        . 'functions.php';
}

// Tahun Berjalan

$currentYear =
    date('Y');

/*
 * Tentukan Apakah Footer Visual Ditampilkan
 * Halaman login tetap menggunakan file ini untuk memuat JavaScript,
 * tetapi footer visual tidak ditampilkan pada halaman login.
 */

$footerBodyClass = isset($bodyClass)
    && is_string($bodyClass)
        ? $bodyClass
        : '';

$showApplicationFooter =
    !str_contains(
        $footerBodyClass,
        'login-page'
    );

/*
 * JavaScript Tambahan Per Halaman
 * Contoh penggunaan dari halaman:
 * $additionalScripts = [
 * ];
 */

$additionalScripts = isset($additionalScripts)
    && is_array($additionalScripts)
        ? $additionalScripts
        : [];

// Bersihkan dan Hilangkan Duplikasi Script

$additionalScripts = array_values(
    array_unique(
        array_filter(
            $additionalScripts,

            static function (
                mixed $scriptPath
            ): bool {
                return is_string($scriptPath)
                    && trim($scriptPath) !== '';
            }
        )
    )
);

?>

<?php if (
    $showApplicationFooter
): ?>

    <footer class="app-footer app-footer-compact border-top mt-auto" aria-label="Informasi sistem">
        <div class="container-fluid px-3 px-lg-4">
            <div class="app-footer-compact-content">

                <p class="app-footer-copyright mb-0">
                    &copy;
                    <?= e(
                        $currentYear
                    ) ?>

                    PT. CISAS
                </p>

                <span class="app-footer-separator" aria-hidden="true">
                    &middot;
                </span>

                <p class="app-footer-description mb-0">
                    Sistem Pendukung Keputusan
                    Pemeliharaan Motor Mesin Mixer
                </p>

            </div>
        </div>
    </footer>

<?php endif; ?>

<!-- Bootstrap JavaScript Bundle -->
<script
    src="<?= e(base_url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"
></script>

<!-- JavaScript utama sistem -->
<script
    src="<?= e(
        base_url(
            'assets/js/app.js'
        )
    ) ?>"
></script>

<?php foreach (
    $additionalScripts
    as $scriptPath
): ?>

    <?php

    $scriptPath =
        trim(
            $scriptPath
        );

    $isAbsoluteScriptUrl =
        preg_match(
            '#^https?://#i',
            $scriptPath
        ) === 1;

    $scriptUrl = $isAbsoluteScriptUrl
        ? $scriptPath
        : base_url(
            ltrim(
                $scriptPath,
                '/'
            )
        );

    ?>

    <script
        src="<?= e(
            $scriptUrl
        ) ?>"
    ></script>

<?php endforeach; ?>

</body>
</html>

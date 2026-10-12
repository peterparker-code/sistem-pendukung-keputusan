<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/helpers/auth.php';

auth_require_role(['admin', 'supervisor']);
$pdo = require dirname(__DIR__, 2) . '/app/config/database.php';

if (!auth_refresh_user($pdo)) {
    flash_error('Akun pengguna sudah tidak aktif atau tidak ditemukan.');
    redirect('login.php');
}

$periods = $pdo->query(
    "SELECT id, name, status, start_date, end_date
     FROM assessment_periods
     ORDER BY CASE status WHEN 'active' THEN 1 WHEN 'draft' THEN 2 ELSE 3 END, start_date DESC, id DESC"
)->fetchAll();

$criteriaRows = $pdo->query(
    "SELECT id, code, name, description, display_order
     FROM criteria
     ORDER BY display_order, id"
)->fetchAll();
$criteriaById = [];
$criteriaByCode = [];
foreach ($criteriaRows as $criterion) {
    $criteriaById[(int) $criterion['id']] = $criterion;
    $criteriaByCode[(string) $criterion['code']] = $criterion;
}

$periodId = filter_var($_GET['period_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($periodId === false || $periodId === null) {
    $periodId = $periods !== [] ? (int) $periods[0]['id'] : null;
}
$method = input_string($_GET, 'method', '') ?? '';
if (!in_array($method, ['', 'ahp', 'topsis'], true)) {
    $method = '';
}

$selectedPeriod = null;
foreach ($periods as $period) {
    if ((int) $period['id'] === (int) $periodId) {
        $selectedPeriod = $period;
        break;
    }
}

$runs = [];
if ($selectedPeriod !== null) {
    $params = ['period_id' => (int) $selectedPeriod['id']];
    $methodSql = '';
    if ($method !== '') {
        $methodSql = 'AND cr.method = :method';
        $params['method'] = $method;
    }
    $stmt = $pdo->prepare(
        "SELECT cr.*, u.name AS calculated_by_name, u.role AS calculated_by_role
         FROM calculation_runs cr
         JOIN users u ON u.id = cr.calculated_by
         WHERE cr.period_id = :period_id {$methodSql}
         ORDER BY cr.created_at DESC, cr.id DESC"
    );
    $stmt->execute($params);
    $runs = $stmt->fetchAll();
}

$totalRunCount = count($runs);
$ahpRunCount = count(array_filter($runs, static fn (array $run): bool => (string) ($run['method'] ?? '') === 'ahp'));
$topsisRunCount = count(array_filter($runs, static fn (array $run): bool => (string) ($run['method'] ?? '') === 'topsis'));

$decode = static function (?string $json): array {
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
};

$criterionCodeFromComparison = static function (array $comparison, string $side) use ($criteriaById): string {
    $codeKey = 'criterion_' . $side . '_code';
    if (!empty($comparison[$codeKey])) {
        return (string) $comparison[$codeKey];
    }
    $idKey = 'criterion_' . $side . '_id';
    $id = (int) ($comparison[$idKey] ?? 0);
    return isset($criteriaById[$id]) ? (string) $criteriaById[$id]['code'] : '-';
};

$criterionNameFromComparison = static function (array $comparison, string $side) use ($criteriaById, $criteriaByCode, $criterionCodeFromComparison): string {
    $code = $criterionCodeFromComparison($comparison, $side);
    if (isset($criteriaByCode[$code])) {
        return (string) $criteriaByCode[$code]['name'];
    }
    $id = (int) ($comparison['criterion_' . $side . '_id'] ?? 0);
    return isset($criteriaById[$id]) ? (string) $criteriaById[$id]['name'] : $code;
};

$comparisonMeaning = static function (string $leftCode, string $rightCode, float $value): string {
    if (!is_finite($value) || $value <= 0) {
        return 'Nilai perbandingan tidak valid';
    }
    if (abs($value - 1.0) < 0.0000001) {
        return $leftCode . ' dan ' . $rightCode . ' sama penting';
    }
    if ($value > 1) {
        return $leftCode . ' lebih diprioritaskan daripada ' . $rightCode . ' (×' . format_number_id($value, 2) . ')';
    }
    $ratio = 1 / $value;
    return $rightCode . ' lebih diprioritaskan daripada ' . $leftCode . ' (×' . format_number_id($ratio, 2) . ')';
};

$pageTitle = 'Riwayat Perhitungan';
$pageDescription = 'Riwayat hasil AHP dan TOPSIS dalam tampilan yang mudah dibaca.';
$bodyClass = 'd-flex flex-column min-vh-100';
require TEMPLATE_PATH . '/header.php';
require TEMPLATE_PATH . '/navbar.php';
?>
<div class="d-lg-flex flex-grow-1">
    <?php require TEMPLATE_PATH . '/sidebar.php'; ?>
    <main id="main-content" class="flex-grow-1 p-3 p-lg-4 overflow-hidden">
        <?php require TEMPLATE_PATH . '/alert.php'; ?>

        <section class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3 mb-4">
            <div>
                <h1 class="page-title">Riwayat Perhitungan</h1>
                <p class="page-description mb-0">Melihat hasil perhitungan AHP dan TOPSIS yang pernah dijalankan tanpa menampilkan data teknis mentah.</p>
            </div>
            <?php if ($selectedPeriod !== null): ?>
                <span class="badge text-bg-light border px-3 py-2"><?= e((string) $selectedPeriod['name']) ?></span>
            <?php endif; ?>
        </section>

        <?php if ($periods !== []): ?>
            <section class="row g-3 mb-4" aria-label="Ringkasan riwayat perhitungan">
                <div class="col-md-4">
                    <article class="card border-0 shadow-sm summary-card h-100">
                        <div class="card-body p-4">
                            <p class="small text-body-secondary mb-2">Total Perhitungan</p>
                            <p class="display-6 fw-semibold mb-1"><?= e($totalRunCount) ?></p>
                            <small class="text-body-secondary">Seluruh AHP dan TOPSIS pada periode terpilih</small>
                        </div>
                    </article>
                </div>
                <div class="col-md-4">
                    <article class="card border-0 shadow-sm summary-card h-100">
                        <div class="card-body p-4">
                            <p class="small text-body-secondary mb-2">Perhitungan AHP</p>
                            <p class="display-6 fw-semibold mb-1"><?= e($ahpRunCount) ?></p>
                            <small class="text-body-secondary">Riwayat pembobotan lima kriteria</small>
                        </div>
                    </article>
                </div>
                <div class="col-md-4">
                    <article class="card border-0 shadow-sm summary-card h-100">
                        <div class="card-body p-4">
                            <p class="small text-body-secondary mb-2">Perhitungan TOPSIS</p>
                            <p class="display-6 fw-semibold mb-1"><?= e($topsisRunCount) ?></p>
                            <small class="text-body-secondary">Riwayat hasil pemeringkatan enam motor</small>
                        </div>
                    </article>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($periods === []): ?>
            <section class="card border-0 shadow-sm">
                <div class="card-body p-5 text-center">
                    <h2 class="h5">Periode belum tersedia</h2>
                    <p class="text-body-secondary mb-0">Buat periode pemeriksaan terlebih dahulu.</p>
                </div>
            </section>
        <?php else: ?>
            <section class="card border-0 shadow-sm mb-4" aria-labelledby="calculation-history-filter-title">
                <div class="card-body p-4">
                    <h2 id="calculation-history-filter-title" class="h6 mb-3">Cari dan Saring Riwayat</h2>
                    <form method="get" action="<?= e(base_url('perhitungan/riwayat.php')) ?>" class="row g-3 align-items-end">
                        <div class="col-lg-6">
                            <label class="form-label" for="period_id">Periode</label>
                            <select class="form-select" id="period_id" name="period_id">
                                <?php foreach ($periods as $period): ?>
                                    <option value="<?= e((int) $period['id']) ?>" <?= selected((string) $periodId, (string) $period['id']) ?>><?= e((string) $period['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label" for="method">Metode</label>
                            <select class="form-select" id="method" name="method">
                                <option value="">AHP & TOPSIS</option>
                                <option value="ahp" <?= selected($method, 'ahp') ?>>AHP</option>
                                <option value="topsis" <?= selected($method, 'topsis') ?>>TOPSIS</option>
                            </select>
                        </div>
                        <div class="col-lg-3">
                            <div class="filter-action-row">
                                <button class="btn btn-primary" type="submit">Terapkan</button>
                                <a class="btn btn-outline-secondary" href="<?= e(base_url('perhitungan/riwayat.php')) ?>">Reset</a>
                            </div>
                        </div>
                    </form>
                </div>
            </section>

            <section class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom px-4 py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div>
                        <h2 class="h5 mb-1">Daftar Perhitungan</h2>
                        <p class="text-body-secondary small mb-0"><?= e(count($runs)) ?> perhitungan tersimpan. Label <strong>Final Saat Ini</strong> menunjukkan hasil yang sedang digunakan sistem.</p>
                    </div>
                    <?php if ($method !== ''): ?>
                        <span class="badge text-bg-light border">Filter <?= e(strtoupper($method)) ?></span>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Metode</th>
                                <th>Perhitungan</th>
                                <th>Status</th>
                                <th>Pelaksana</th>
                                <th>Hasil Utama</th>
                                <th>Waktu</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($runs === []): ?>
                            <tr><td colspan="7" class="text-center text-body-secondary py-5">Belum ada riwayat perhitungan untuk periode ini.</td></tr>
                        <?php else: ?>
                            <?php foreach ($runs as $run): ?>
                                <?php
                                $summary = $decode($run['summary_json'] ?? null);
                                $methodCode = strtoupper((string) $run['method']);
                                $summaryText = '-';
                                if ($run['method'] === 'ahp') {
                                    $cr = $summary['consistency_ratio'] ?? null;
                                    $summaryText = $cr !== null
                                        ? 'CR ' . format_number_id((float) $cr, 4) . ' · ' . ((bool) ($summary['is_consistent'] ?? false) ? 'Konsisten' : 'Tidak konsisten')
                                        : '-';
                                } else {
                                    $top = $summary['top_alternative'] ?? null;
                                    $pref = $summary['top_preference'] ?? null;
                                    $summaryText = $top !== null
                                        ? 'Prioritas 1: ' . $top . ($pref !== null ? ' · V=' . format_number_id((float) $pref, 4) : '')
                                        : '-';
                                }
                                ?>
                                <tr>
                                    <td><span class="badge <?= $run['method'] === 'ahp' ? 'text-bg-primary' : 'text-bg-success' ?>"><?= e($methodCode) ?></span></td>
                                    <td><strong>#<?= e((int) $run['run_number']) ?></strong></td>
                                    <td><?= (int) $run['is_final'] === 1 ? '<span class="badge text-bg-success">Final Saat Ini</span>' : '<span class="badge text-bg-light border">Riwayat Lama</span>' ?></td>
                                    <td><strong class="d-block"><?= e((string) $run['calculated_by_name']) ?></strong><small class="text-body-secondary"><?= e(auth_role_label((string) $run['calculated_by_role'])) ?></small></td>
                                    <td><?= e($summaryText) ?></td>
                                    <td class="text-nowrap"><?= e(format_date_id($run['created_at'] ?? null, true)) ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary table-action-button" data-bs-toggle="modal" data-bs-target="#calculationSnapshotModal<?= e((int) $run['id']) ?>">
                                            Lihat Hasil
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <?php foreach ($runs as $run): ?>
                <?php
                $modalSummary = $decode($run['summary_json'] ?? null);
                $modalInput = $decode($run['input_snapshot_json'] ?? null);
                $modalOutput = $decode($run['output_snapshot_json'] ?? null);
                $modalMethodCode = strtoupper((string) ($run['method'] ?? ''));
                $note = trim((string) ($modalInput['note'] ?? ''));
                $modalStatus = (int) $run['is_final'] === 1 ? 'Final Saat Ini' : 'Riwayat Lama';
                ?>
                <div class="modal fade" id="calculationSnapshotModal<?= e((int) $run['id']) ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header align-items-start">
                                <div>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                        <span class="badge <?= $run['method'] === 'ahp' ? 'text-bg-primary' : 'text-bg-success' ?>"><?= e($modalMethodCode) ?></span>
                                        <span class="badge <?= (int) $run['is_final'] === 1 ? 'text-bg-success' : 'text-bg-light border' ?>"><?= e($modalStatus) ?></span>
                                    </div>
                                    <h2 class="modal-title h5 mb-1">Hasil Perhitungan <?= e($modalMethodCode) ?> #<?= e((int) $run['run_number']) ?></h2>
                                    <p class="small text-body-secondary mb-0"><?= e(format_date_id($run['created_at'] ?? null, true)) ?> · <?= e((string) $run['calculated_by_name']) ?></p>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                            </div>

                            <div class="modal-body p-4">
                                <div class="alert alert-light border mb-4 readable-history-help" role="note">
                                    <strong>Riwayat ini adalah rekaman hasil saat perhitungan dijalankan.</strong>
                                    Data di bawah hanya untuk dibaca dan tidak mengubah hasil AHP/TOPSIS yang aktif.
                                </div>

                                <?php if ($run['method'] === 'ahp'): ?>
                                    <?php
                                    $cr = isset($modalSummary['consistency_ratio']) ? (float) $modalSummary['consistency_ratio'] : null;
                                    $isConsistent = (bool) ($modalSummary['is_consistent'] ?? false);
                                    $comparisons = isset($modalInput['comparisons']) && is_array($modalInput['comparisons']) ? $modalInput['comparisons'] : [];
                                    $weights = isset($modalOutput['weights']) && is_array($modalOutput['weights']) ? $modalOutput['weights'] : [];
                                    ?>

                                    <section class="mb-4" aria-label="Ringkasan konsistensi AHP">
                                        <h3 class="h6 mb-3">Ringkasan Konsistensi</h3>
                                        <div class="row g-3">
                                            <div class="col-sm-6 col-xl-3"><div class="readable-history-kpi"><span>Rasio Konsistensi (CR)</span><strong><?= $cr !== null ? e(format_number_id($cr, 4)) : '-' ?></strong></div></div>
                                            <div class="col-sm-6 col-xl-3"><div class="readable-history-kpi"><span>Status</span><strong class="<?= $isConsistent ? 'text-success' : 'text-danger' ?>"><?= $isConsistent ? 'Konsisten' : 'Tidak Konsisten' ?></strong></div></div>
                                            <div class="col-sm-6 col-xl-3"><div class="readable-history-kpi"><span>Lambda Maksimum</span><strong><?= isset($modalSummary['lambda_max']) ? e(format_number_id((float) $modalSummary['lambda_max'], 4)) : '-' ?></strong></div></div>
                                            <div class="col-sm-6 col-xl-3"><div class="readable-history-kpi"><span>Consistency Index (CI)</span><strong><?= isset($modalSummary['consistency_index']) ? e(format_number_id((float) $modalSummary['consistency_index'], 4)) : '-' ?></strong></div></div>
                                        </div>
                                    </section>

                                    <section class="mb-4">
                                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-2 mb-3">
                                            <div>
                                                <h3 class="h6 mb-1">Bobot Kriteria</h3>
                                                <p class="small text-body-secondary mb-0">Semakin besar bobot, semakin besar pengaruh kriteria pada pemeringkatan TOPSIS.</p>
                                            </div>
                                        </div>
                                        <div class="table-responsive border rounded-3 overflow-hidden">
                                            <table class="table table-sm align-middle mb-0 readable-history-table">
                                                <thead class="table-light"><tr><th>Kriteria</th><th>Nama Kriteria</th><th class="text-end">Bobot</th><th class="text-end">Persentase</th></tr></thead>
                                                <tbody>
                                                <?php if ($weights === []): ?>
                                                    <tr><td colspan="4" class="text-center text-body-secondary py-4">Bobot tidak tersedia pada rekaman ini.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach ($weights as $index => $weight): ?>
                                                        <?php
                                                        $code = (string) ($weight['criterion_code'] ?? '');
                                                        if ($code === '' && isset($weight['criterion_id'], $criteriaById[(int) $weight['criterion_id']])) {
                                                            $code = (string) $criteriaById[(int) $weight['criterion_id']]['code'];
                                                        }
                                                        if ($code === '' && isset($criteriaRows[$index])) {
                                                            $code = (string) $criteriaRows[$index]['code'];
                                                        }
                                                        $name = (string) ($weight['criterion_name'] ?? ($criteriaByCode[$code]['name'] ?? $code));
                                                        $value = (float) ($weight['weight'] ?? 0);
                                                        ?>
                                                        <tr>
                                                            <td><strong><?= e($code) ?></strong></td>
                                                            <td><?= e($name) ?></td>
                                                            <td class="text-end fw-semibold"><?= e(format_number_id($value, 6)) ?></td>
                                                            <td class="text-end"><?= e(format_number_id($value * 100, 2)) ?>%</td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>

                                    <section>
                                        <div class="mb-3">
                                            <h3 class="h6 mb-1">Perbandingan Berpasangan</h3>
                                            <p class="small text-body-secondary mb-0">Menunjukkan penilaian Supervisor terhadap tingkat kepentingan antar-kriteria.</p>
                                        </div>
                                        <div class="table-responsive border rounded-3 overflow-hidden">
                                            <table class="table table-sm align-middle mb-0 readable-history-table">
                                                <thead class="table-light"><tr><th>Pasangan</th><th>Nama Kriteria</th><th class="text-center">Nilai</th><th>Arti Penilaian</th></tr></thead>
                                                <tbody>
                                                <?php if ($comparisons === []): ?>
                                                    <tr><td colspan="4" class="text-center text-body-secondary py-4">Data perbandingan tidak tersedia pada rekaman ini.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach ($comparisons as $comparison): ?>
                                                        <?php
                                                        if (!is_array($comparison)) { continue; }
                                                        $leftCode = $criterionCodeFromComparison($comparison, 'left');
                                                        $rightCode = $criterionCodeFromComparison($comparison, 'right');
                                                        $leftName = $criterionNameFromComparison($comparison, 'left');
                                                        $rightName = $criterionNameFromComparison($comparison, 'right');
                                                        $comparisonValue = (float) ($comparison['comparison_value'] ?? 0);
                                                        ?>
                                                        <tr>
                                                            <td class="text-nowrap"><strong><?= e($leftCode) ?> : <?= e($rightCode) ?></strong></td>
                                                            <td><span class="d-block"><?= e($leftName) ?></span><span class="text-body-secondary small">dibandingkan dengan <?= e($rightName) ?></span></td>
                                                            <td class="text-center fw-semibold"><?= e(format_number_id($comparisonValue, 4)) ?></td>
                                                            <td><?= e($comparisonMeaning($leftCode, $rightCode, $comparisonValue)) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>

                                <?php else: ?>
                                    <?php
                                    $alternatives = isset($modalInput['alternatives']) && is_array($modalInput['alternatives']) ? $modalInput['alternatives'] : [];
                                    $weightsMap = isset($modalInput['weights']) && is_array($modalInput['weights']) ? $modalInput['weights'] : [];
                                    $results = isset($modalOutput['results']) && is_array($modalOutput['results']) ? $modalOutput['results'] : [];
                                    $topResult = $results[0] ?? [];
                                    ?>

                                    <section class="mb-4" aria-label="Ringkasan hasil TOPSIS">
                                        <h3 class="h6 mb-3">Ringkasan Hasil</h3>
                                        <div class="row g-3">
                                            <div class="col-sm-6 col-xl-4"><div class="readable-history-kpi"><span>Prioritas Pertama</span><strong><?= e((string) ($modalSummary['top_alternative'] ?? ($topResult['alternative_code'] ?? '-'))) ?></strong></div></div>
                                            <div class="col-sm-6 col-xl-4"><div class="readable-history-kpi"><span>Nilai Preferensi Tertinggi</span><strong><?= isset($modalSummary['top_preference']) ? e(format_number_id((float) $modalSummary['top_preference'], 6)) : '-' ?></strong></div></div>
                                            <div class="col-sm-6 col-xl-4"><div class="readable-history-kpi"><span>Alternatif Dinilai</span><strong><?= e((int) ($modalSummary['result_count'] ?? count($results))) ?> motor</strong></div></div>
                                        </div>
                                    </section>

                                    <section class="mb-4">
                                        <div class="mb-3">
                                            <h3 class="h6 mb-1">Bobot AHP yang Digunakan</h3>
                                            <p class="small text-body-secondary mb-0">Bobot inilah yang digunakan untuk menghitung pemeringkatan TOPSIS pada saat itu.</p>
                                        </div>
                                        <div class="row g-2">
                                            <?php foreach (['C1', 'C2', 'C3', 'C4', 'C5'] as $code): ?>
                                                <?php $weightValue = isset($weightsMap[$code]) ? (float) $weightsMap[$code] : null; ?>
                                                <div class="col-sm-6 col-lg">
                                                    <div class="readable-weight-item h-100">
                                                        <strong><?= e($code) ?></strong>
                                                        <span><?= e((string) ($criteriaByCode[$code]['name'] ?? $code)) ?></span>
                                                        <b><?= $weightValue !== null ? e(format_number_id($weightValue * 100, 2)) . '%' : '-' ?></b>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>

                                    <section class="mb-4">
                                        <div class="mb-3">
                                            <h3 class="h6 mb-1">Data Alternatif yang Dihitung</h3>
                                            <p class="small text-body-secondary mb-0">Nilai akhir C1–C5 dari setiap motor pada saat TOPSIS dijalankan.</p>
                                        </div>
                                        <div class="table-responsive border rounded-3 overflow-hidden">
                                            <table class="table table-sm align-middle mb-0 readable-history-table">
                                                <thead class="table-light"><tr><th>Alternatif</th><th>Motor</th><th class="text-end">C1</th><th class="text-end">C2</th><th class="text-end">C3</th><th class="text-end">C4</th><th class="text-end">C5</th></tr></thead>
                                                <tbody>
                                                <?php if ($alternatives === []): ?>
                                                    <tr><td colspan="7" class="text-center text-body-secondary py-4">Data alternatif tidak tersedia pada rekaman ini.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach ($alternatives as $alternative): ?>
                                                        <?php if (!is_array($alternative)) { continue; } ?>
                                                        <tr>
                                                            <td><strong><?= e((string) ($alternative['alternative_code'] ?? '-')) ?></strong></td>
                                                            <td><?= e((string) ($alternative['motor_name'] ?? '-')) ?></td>
                                                            <?php foreach (['c1_value', 'c2_value', 'c3_value', 'c4_value', 'c5_value'] as $field): ?>
                                                                <td class="text-end"><?= isset($alternative[$field]) ? e(format_number_id((float) $alternative[$field], 4)) : '-' ?></td>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>

                                    <section>
                                        <div class="mb-3">
                                            <h3 class="h6 mb-1">Hasil Pemeringkatan</h3>
                                            <p class="small text-body-secondary mb-0">Nilai preferensi (V) yang lebih besar menunjukkan prioritas pemeliharaan yang lebih tinggi.</p>
                                        </div>
                                        <div class="table-responsive border rounded-3 overflow-hidden">
                                            <table class="table table-sm align-middle mb-0 readable-history-table">
                                                <thead class="table-light"><tr><th>Peringkat</th><th>Alternatif</th><th>Motor</th><th class="text-end">D+</th><th class="text-end">D−</th><th class="text-end">Nilai Preferensi (V)</th></tr></thead>
                                                <tbody>
                                                <?php if ($results === []): ?>
                                                    <tr><td colspan="6" class="text-center text-body-secondary py-4">Hasil pemeringkatan tidak tersedia pada rekaman ini.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach ($results as $result): ?>
                                                        <?php if (!is_array($result)) { continue; } ?>
                                                        <tr class="<?= (int) ($result['ranking'] ?? 0) === 1 ? 'readable-history-rank-one' : '' ?>">
                                                            <td><span class="readable-rank-badge"><?= e((int) ($result['ranking'] ?? 0)) ?></span></td>
                                                            <td><strong><?= e((string) ($result['alternative_code'] ?? '-')) ?></strong></td>
                                                            <td><?= e((string) ($result['motor_name'] ?? '-')) ?></td>
                                                            <td class="text-end"><?= isset($result['positive_distance']) ? e(format_number_id((float) $result['positive_distance'], 6)) : '-' ?></td>
                                                            <td class="text-end"><?= isset($result['negative_distance']) ? e(format_number_id((float) $result['negative_distance'], 6)) : '-' ?></td>
                                                            <td class="text-end fw-bold"><?= isset($result['preference_value']) ? e(format_number_id((float) $result['preference_value'], 6)) : '-' ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>
                                <?php endif; ?>

                                <?php if ($note !== ''): ?>
                                    <div class="readable-history-note mt-4">
                                        <strong>Catatan rekaman</strong>
                                        <span><?= e($note) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</div>
<?php require TEMPLATE_PATH . '/footer.php'; ?>

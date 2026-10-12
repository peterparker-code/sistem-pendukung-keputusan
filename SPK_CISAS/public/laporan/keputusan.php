<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/helpers/auth.php';

auth_require_role(['admin', 'supervisor']);
$pdo = require dirname(__DIR__, 2) . '/app/config/database.php';

if (!auth_refresh_user($pdo)) {
    flash_error('Akun pengguna sudah tidak aktif atau tidak ditemukan.');
    redirect('login.php');
}

$currentRole = auth_role();
$currentUserName = auth_name() ?? 'Pengguna';
$currentRoleLabel = auth_role_label($currentRole);
$printedAt = date('Y-m-d H:i:s');

$periodId = filter_var($_GET['period_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($periodId === false || $periodId === null) {
    $notFoundMessage = 'ID periode laporan keputusan tidak valid.';
    require dirname(__DIR__) . '/404.php';
    exit;
}
$periodId = (int) $periodId;

$periodStatement = $pdo->prepare(
    "SELECT ap.*, u.name AS created_by_name
     FROM assessment_periods ap
     JOIN users u ON u.id = ap.created_by
     WHERE ap.id = :id LIMIT 1"
);
$periodStatement->execute(['id' => $periodId]);
$period = $periodStatement->fetch();
if (!is_array($period)) {
    $notFoundMessage = 'Periode pemeriksaan tidak ditemukan.';
    require dirname(__DIR__) . '/404.php';
    exit;
}

$assessmentStatement = $pdo->prepare(
    "SELECT a.id, a.status, a.inspection_date, a.inspection_time,
            m.alternative_code, m.motor_name, m.machine_code,
            u.name AS inspector_name,
            s.c1_value, s.c2_value, s.c3_value, s.c4_value, s.c5_value,
            s.c4_has_danger, s.c5_has_danger
     FROM assessments a
     JOIN machines m ON m.id = a.machine_id
     JOIN users u ON u.id = a.inspector_id
     LEFT JOIN assessment_scores s ON s.assessment_id = a.id
     WHERE a.period_id = :period_id
     ORDER BY m.alternative_code"
);
$assessmentStatement->execute(['period_id' => $periodId]);
$assessments = $assessmentStatement->fetchAll();

$weightStatement = $pdo->prepare(
    "SELECT c.code, c.name, c.description, ar.weight
     FROM ahp_results ar
     JOIN criteria c ON c.id = ar.criterion_id
     WHERE ar.period_id = :period_id
     ORDER BY c.display_order"
);
$weightStatement->execute(['period_id' => $periodId]);
$weights = $weightStatement->fetchAll();

$summaryStatement = $pdo->prepare(
    "SELECT hs.*, u.name AS assessor_name
     FROM ahp_summaries hs
     JOIN users u ON u.id = hs.assessor_id
     WHERE hs.period_id = :period_id LIMIT 1"
);
$summaryStatement->execute(['period_id' => $periodId]);
$ahpSummary = $summaryStatement->fetch();
$ahpSummary = is_array($ahpSummary) ? $ahpSummary : null;

$rankingStatement = $pdo->prepare(
    "SELECT tr.ranking, tr.rank_group, tr.preference_value, tr.priority_label,
            tr.positive_distance, tr.negative_distance, tr.calculated_at,
            a.id AS assessment_id, m.alternative_code, m.motor_name,
            ma.priority AS action_priority, ma.recommendation, ma.action_description,
            ma.target_date, ma.status AS action_status, ma.completed_date,
            performer.name AS performed_by_name
     FROM topsis_results tr
     JOIN assessments a ON a.id = tr.assessment_id
     JOIN machines m ON m.id = a.machine_id
     LEFT JOIN maintenance_actions ma ON ma.assessment_id = a.id
     LEFT JOIN users performer ON performer.id = ma.performed_by
     WHERE tr.period_id = :period_id
     ORDER BY tr.ranking ASC, tr.preference_value DESC, m.alternative_code"
);
$rankingStatement->execute(['period_id' => $periodId]);
$rankings = $rankingStatement->fetchAll();

$runCountStatement = $pdo->prepare(
    "SELECT method, COUNT(*) AS total FROM calculation_runs WHERE period_id=:period_id GROUP BY method"
);
$runCountStatement->execute(['period_id' => $periodId]);
$runCounts = ['ahp' => 0, 'topsis' => 0];
foreach ($runCountStatement->fetchAll() as $row) {
    $runCounts[(string) $row['method']] = (int) $row['total'];
}

$reportAvailable = count($assessments) === 6
    && count($weights) === 5
    && $ahpSummary !== null
    && count($rankings) === 6;

$assessmentStatusLabel = static fn (string $status): string => match ($status) {
    'draft' => 'Draft',
    'completed' => 'Menunggu Validasi',
    'returned' => 'Dikembalikan',
    'validated' => 'Disetujui',
    'processed' => 'Diproses TOPSIS',
    default => $status,
};
$actionStatusLabel = static fn (?string $status): string => match ($status) {
    'pending' => 'Menunggu',
    'in_progress' => 'Dikerjakan',
    'completed' => 'Selesai',
    null, '' => 'Belum Ada',
    default => (string) $status,
};
$priorityLabel = static fn (?string $priority): string => match ($priority) {
    'immediate' => 'Segera',
    'scheduled' => 'Terjadwal',
    'monitoring' => 'Monitoring',
    null, '' => '-',
    default => (string) $priority,
};

$assessmentCount = count($assessments);
$validatedAssessmentCount = count(array_filter(
    $assessments,
    static fn (array $row): bool => in_array(
        (string) ($row['status'] ?? ''),
        ['validated', 'processed'],
        true
    )
));
$completedActionCount = count(array_filter(
    $rankings,
    static fn (array $row): bool => (string) ($row['action_status'] ?? '') === 'completed'
));

$pageTitle = 'Laporan Akhir Keputusan';
$pageDescription = 'Ringkasan akhir keputusan pemeliharaan berdasarkan pemeriksaan, AHP, TOPSIS, dan tindak lanjut.';
$bodyClass = 'd-flex flex-column min-vh-100';
$includePrintCss = true;
require TEMPLATE_PATH . '/header.php';
require TEMPLATE_PATH . '/navbar.php';
?>
<div class="d-lg-flex flex-grow-1">
    <?php require TEMPLATE_PATH . '/sidebar.php'; ?>
    <main id="main-content" class="flex-grow-1 p-3 p-lg-4 overflow-hidden">
        <?php require TEMPLATE_PATH . '/alert.php'; ?>

        <section class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3 mb-4 d-print-none">
            <div>
                <h1 class="page-title">Laporan Akhir Keputusan</h1>
                <p class="page-description mb-0">Ringkasan akhir hasil SPK tanpa mengulang seluruh detail perhitungan pada laporan pemeriksaan, AHP, dan TOPSIS.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?= e(base_url('laporan/peringkat.php?period_id=' . $periodId)) ?>">Kembali</a>
                <button type="button" class="btn btn-primary" data-print <?= !$reportAvailable ? 'disabled' : '' ?>>Cetak Laporan Akhir</button>
            </div>
        </section>

        <?php if (!$reportAvailable): ?>
            <div class="alert alert-warning d-print-none">
                <strong class="d-block mb-1">Laporan akhir belum lengkap</strong>
                Laporan akhir membutuhkan 6 pemeriksaan, 5 bobot AHP, ringkasan konsistensi, dan 6 hasil TOPSIS.
            </div>
        <?php endif; ?>

        <article class="card border-0 shadow-sm report-sheet">
            <div class="card-body p-4 p-lg-5">
                <header class="print-report-header d-none d-print-block">
                    <div class="print-report-brand-row">
                        <img
                            src="<?= e(base_url('assets/images/logo_cisas.webp')) ?>"
                            alt="Logo PT CISAS"
                            class="print-report-logo"
                        >

                        <div class="print-report-brand-copy">
                            <strong>PT CITRASEMESTA ASRISEJATI</strong>
                            <span>Sistem Pendukung Keputusan Pemeliharaan Motor Mesin Mixer</span>
                        </div>
                    </div>

                    <div class="print-report-title-block">
                        <h1>LAPORAN AKHIR KEPUTUSAN PEMELIHARAAN MOTOR MESIN MIXER</h1>
                        <p>
                            <?= e((string) $period['name']) ?>
                            · <?= e(format_date_id($period['start_date'] ?? null)) ?>
                            s.d. <?= e(format_date_id($period['end_date'] ?? null)) ?>
                        </p>
                    </div>
                </header>

                <header class="text-center border-bottom pb-4 mb-4 d-print-none">
                    <h2 class="h4 mb-1">PT. CISAS</h2>
                    <h3 class="h5 mb-2">Laporan Akhir Keputusan Pemeliharaan Motor Mesin Mixer</h3>
                    <p class="text-body-secondary mb-0"><?= e((string) $period['name']) ?> · <?= e(format_date_id($period['start_date'] ?? null)) ?> s.d. <?= e(format_date_id($period['end_date'] ?? null)) ?></p>
                </header>

                <section class="mb-5 report-final-section">
                    <h3 class="h5 mb-3">1. Ringkasan Pemeriksaan</h3>
                    <div class="row g-3">
                        <div class="col-6 col-lg-3">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-body-secondary">Motor diperiksa</div>
                                <strong class="fs-5"><?= e($assessmentCount) ?> / 6</strong>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-body-secondary">Disetujui</div>
                                <strong class="fs-5"><?= e($validatedAssessmentCount) ?> / 6</strong>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-body-secondary">Hasil TOPSIS</div>
                                <strong class="fs-5"><?= e(count($rankings)) ?> / 6</strong>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-body-secondary">Tindakan selesai</div>
                                <strong class="fs-5"><?= e($completedActionCount) ?> / <?= e(count($rankings)) ?></strong>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="mb-5 report-final-section">
                    <h3 class="h5 mb-3">2. Ringkasan Pembobotan AHP</h3>
                    <div class="row g-4">
                        <div class="col-lg-8">
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Kriteria</th>
                                            <th>Nama Kriteria</th>
                                            <th class="text-end">Bobot</th>
                                            <th class="text-end">Persentase</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($weights === []): ?>
                                            <tr><td colspan="4" class="text-center text-body-secondary">Bobot AHP belum tersedia.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($weights as $weight): ?>
                                                <tr>
                                                    <td><strong><?= e((string) $weight['code']) ?></strong></td>
                                                    <td><?= e((string) $weight['name']) ?></td>
                                                    <td class="text-end"><?= e(format_number_id((float) $weight['weight'], 6)) ?></td>
                                                    <td class="text-end"><?= e(format_number_id((float) $weight['weight'] * 100, 2)) ?>%</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="border rounded p-4 h-100">
                                <div class="small text-body-secondary mb-1">Rasio Konsistensi (CR)</div>
                                <?php if ($ahpSummary === null): ?>
                                    <strong class="fs-5">-</strong>
                                    <p class="text-body-secondary small mb-0 mt-2">Ringkasan AHP belum tersedia.</p>
                                <?php else: ?>
                                    <strong class="fs-5"><?= e(format_number_id((float) $ahpSummary['consistency_ratio'], 6)) ?></strong>
                                    <div class="mt-2">
                                        <span class="badge text-bg-<?= (int) $ahpSummary['is_consistent'] === 1 ? 'success' : 'danger' ?>">
                                            <?= (int) $ahpSummary['is_consistent'] === 1 ? 'Konsisten' : 'Tidak Konsisten' ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="mb-5 report-final-section">
                    <div class="d-flex justify-content-between align-items-end gap-3 mb-3">
                        <div>
                            <h3 class="h5 mb-1">3. Hasil Prioritas Pemeliharaan</h3>
                            <p class="small text-body-secondary mb-0">Detail matriks, solusi ideal, dan jarak D+/D− tetap tersedia pada Laporan Perhitungan TOPSIS.</p>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Alternatif</th>
                                    <th>Motor</th>
                                    <th class="text-end">Nilai Preferensi</th>
                                    <th>Prioritas</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rankings === []): ?>
                                    <tr><td colspan="5" class="text-center text-body-secondary">Hasil TOPSIS belum tersedia.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($rankings as $row): ?>
                                        <tr class="<?= (int) $row['ranking'] === 1 ? 'table-success' : '' ?>">
                                            <td><strong><?= e((int) $row['ranking']) ?></strong></td>
                                            <td><strong><?= e((string) $row['alternative_code']) ?></strong></td>
                                            <td><?= e((string) $row['motor_name']) ?></td>
                                            <td class="text-end"><strong><?= e(format_number_id((float) $row['preference_value'], 6)) ?></strong></td>
                                            <td><?= e((string) ($row['priority_label'] ?? '-')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <?php if ($rankings !== []): ?>
                    <?php $first = $rankings[0]; ?>
                    <section class="mb-5 report-final-section">
                        <h3 class="h5 mb-3">4. Keputusan Utama</h3>
                        <div class="border rounded p-4 bg-body-tertiary">
                            <div class="small text-body-secondary mb-1">Prioritas pemeliharaan pertama</div>
                            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
                                <div>
                                    <strong class="fs-5"><?= e((string) $first['alternative_code']) ?> — <?= e((string) $first['motor_name']) ?></strong>
                                    <p class="mb-0 mt-1 text-body-secondary">Alternatif ini memperoleh nilai preferensi TOPSIS tertinggi pada periode terpilih.</p>
                                </div>
                                <div class="text-lg-end">
                                    <div class="small text-body-secondary">Nilai Preferensi</div>
                                    <strong class="fs-4"><?= e(format_number_id((float) $first['preference_value'], 6)) ?></strong>
                                </div>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="mb-4 report-final-section">
                    <h3 class="h5 mb-3">5. Tindak Lanjut Pemeliharaan</h3>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Alternatif</th>
                                    <th>Prioritas Tindakan</th>
                                    <th>Rekomendasi</th>
                                    <th>Pelaksana</th>
                                    <th>Status</th>
                                    <th>Target/Selesai</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rankings === []): ?>
                                    <tr><td colspan="7" class="text-center text-body-secondary">Belum tersedia.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($rankings as $row): ?>
                                        <tr>
                                            <td><?= e((int) $row['ranking']) ?></td>
                                            <td><strong><?= e((string) $row['alternative_code']) ?></strong></td>
                                            <td><?= e($priorityLabel($row['action_priority'] ?? null)) ?></td>
                                            <td><?= e((string) ($row['recommendation'] ?? 'Belum dibuat')) ?></td>
                                            <td><?= e((string) ($row['performed_by_name'] ?? '-')) ?></td>
                                            <td><?= e($actionStatusLabel($row['action_status'] ?? null)) ?></td>
                                            <td><?= e(format_date_id($row['completed_date'] ?? $row['target_date'] ?? null)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="d-none d-print-block mt-5" aria-label="Pengesahan laporan">
                    <div class="row text-center">
                        <div class="col-6">
                            <p class="mb-5">
                                Dicetak oleh,
                                <br>
                                <?= e($currentRoleLabel) ?>
                            </p>

                            <p class="mb-0">
                                <strong><?= e($currentUserName) ?></strong>
                            </p>
                        </div>

                        <div class="col-6">
                            <p class="mb-5">
                                Mengetahui,
                                <br>
                                Supervisor Maintenance
                            </p>

                            <p class="mb-0">
                                <strong><?= e((string) ($ahpSummary['assessor_name'] ?? 'Supervisor Maintenance')) ?></strong>
                            </p>
                        </div>
                    </div>

                    <p class="print-generated-at mb-0">
                        Dicetak pada <?= e(format_date_id($printedAt, true)) ?>
                    </p>
                </section>

                <div class="border-top pt-3 mt-4 small text-body-secondary d-print-none">
                    Laporan akhir ini merangkum hasil keputusan. Rincian pemeriksaan, perhitungan AHP, dan tahapan TOPSIS tersedia pada masing-masing laporan modul.
                </div>
            </div>
        </article>
    </main>
</div>
<?php require TEMPLATE_PATH . '/footer.php'; ?>

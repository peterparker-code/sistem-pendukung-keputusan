<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/helpers/auth.php';

/*
 * Log aktivitas tetap tersimpan sebagai audit trail, tetapi halaman ini hanya
 * ditampilkan untuk Administrator. Tampilan sengaja dibuat berorientasi pada
 * bahasa manusia: aktivitas, pelaku, waktu, dan perubahan penting.
 */
auth_require_role(['admin']);

$pdo = require dirname(__DIR__, 2) . '/app/config/database.php';
if (!auth_refresh_user($pdo)) {
    flash_error('Akun pengguna sudah tidak aktif atau tidak ditemukan.');
    redirect('login.php');
}

$search = input_string($_GET, 'q', '') ?? '';
$actionFilter = input_string($_GET, 'action', '') ?? '';
$roleFilter = input_string($_GET, 'role', '') ?? '';

$allowedRoles = ['', 'admin', 'supervisor', 'technician', 'system'];
if (!in_array($roleFilter, $allowedRoles, true)) {
    $roleFilter = '';
}

$actionRows = $pdo->query(
    "SELECT DISTINCT action FROM audit_logs WHERE action <> '' ORDER BY action"
)->fetchAll(PDO::FETCH_COLUMN);
$allowedActions = array_values(array_filter(array_map('strval', $actionRows)));
if ($actionFilter !== '' && !in_array($actionFilter, $allowedActions, true)) {
    $actionFilter = '';
}

$conditions = [];
$params = [];
if ($search !== '') {
    $conditions[] = "(lower(COALESCE(al.actor_name,'')) LIKE lower(:search)
        OR lower(COALESCE(al.description,'')) LIKE lower(:search)
        OR lower(al.action) LIKE lower(:search)
        OR lower(al.entity_type) LIKE lower(:search))";
    $params['search'] = '%' . $search . '%';
}
if ($actionFilter !== '') {
    $conditions[] = 'al.action = :action';
    $params['action'] = $actionFilter;
}
if ($roleFilter !== '') {
    $conditions[] = 'al.actor_role = :role';
    $params['role'] = $roleFilter;
}

$where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
$statement = $pdo->prepare(
    "SELECT al.* FROM audit_logs al {$where}
     ORDER BY al.created_at DESC, al.id DESC LIMIT 200"
);
$statement->execute($params);
$logs = $statement->fetchAll();

$totalLogs = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
$todayLogs = (int) $pdo->query(
    "SELECT COUNT(*) FROM audit_logs WHERE date(created_at)=date('now', '+7 hours')"
)->fetchColumn();
$calculationLogs = (int) $pdo->query(
    "SELECT COUNT(*) FROM audit_logs WHERE action IN ('ahp_calculated','topsis_calculated')"
)->fetchColumn();

$userMap = [];
foreach ($pdo->query('SELECT id, name FROM users ORDER BY id')->fetchAll() as $row) {
    $userMap[(int) $row['id']] = (string) $row['name'];
}
$periodMap = [];
foreach ($pdo->query('SELECT id, name FROM assessment_periods ORDER BY id')->fetchAll() as $row) {
    $periodMap[(int) $row['id']] = (string) $row['name'];
}
$machineMap = [];
foreach ($pdo->query('SELECT id, alternative_code, motor_name FROM machines ORDER BY id')->fetchAll() as $row) {
    $machineMap[(int) $row['id']] = trim((string) $row['alternative_code'] . ' · ' . (string) $row['motor_name']);
}

$decodeAuditData = static function (?string $json): array {
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
};

$actionLabel = static function (string $action): string {
    return match ($action) {
        'login_success' => 'Login Berhasil',
        'login_failed' => 'Login Gagal',
        'assessment_created' => 'Pemeriksaan Dibuat',
        'assessment_updated' => 'Pemeriksaan Diubah',
        'assessment_submitted' => 'Pemeriksaan Dikirim',
        'assessment_validated' => 'Pemeriksaan Disetujui',
        'assessment_returned' => 'Pemeriksaan Dikembalikan',
        'ahp_comparisons_saved' => 'Perbandingan AHP Disimpan',
        'ahp_calculated' => 'Perhitungan AHP Selesai',
        'topsis_calculated' => 'Perhitungan TOPSIS Selesai',
        'maintenance_action_saved' => 'Tindak Lanjut Disimpan',
        'maintenance_recommendation_created' => 'Rekomendasi Dibuat',
        'maintenance_recommendation_updated' => 'Rekomendasi Diubah',
        'maintenance_execution_updated' => 'Pelaksanaan Diperbarui',
        'system_upgrade' => 'Pembaruan Sistem',
        'period_created' => 'Periode Dibuat',
        'period_updated' => 'Periode Diubah',
        default => ucwords(str_replace('_', ' ', $action)),
    };
};

$moduleLabel = static function (array $log): string {
    $action = (string) ($log['action'] ?? '');
    $entityType = (string) ($log['entity_type'] ?? '');

    if (str_starts_with($action, 'login_') || $entityType === 'authentication') {
        return 'Autentikasi';
    }
    if (str_starts_with($action, 'ahp_')) {
        return 'Pembobotan AHP';
    }
    if ($action === 'topsis_calculated') {
        return 'Pemeringkatan TOPSIS';
    }
    if (str_starts_with($action, 'maintenance_') || $entityType === 'maintenance_action') {
        return 'Tindak Lanjut';
    }
    if (str_starts_with($action, 'assessment_') || $entityType === 'assessment') {
        return 'Pemeriksaan Motor';
    }
    if (str_starts_with($action, 'period_') || $entityType === 'assessment_period') {
        return 'Periode Pemeriksaan';
    }
    if ($entityType === 'user') {
        return 'Pengguna';
    }
    return 'Sistem';
};

$fieldLabel = static function (string $field): string {
    return match ($field) {
        'name' => 'Nama',
        'username' => 'Username',
        'reason' => 'Alasan',
        'start_date' => 'Tanggal Mulai',
        'end_date' => 'Tanggal Selesai',
        'status' => 'Status',
        'period_id' => 'Periode',
        'machine_id' => 'Motor',
        'assessment_id' => 'Pemeriksaan',
        'alternative_code' => 'Kode Alternatif',
        'inspection_date' => 'Tanggal Pemeriksaan',
        'supervisor_note' => 'Catatan Supervisor',
        'comparison_count' => 'Jumlah Perbandingan AHP',
        'comparisons' => 'Perbandingan AHP',
        'calculation_run_id' => 'Riwayat Perhitungan',
        'consistency_ratio' => 'Rasio Konsistensi (CR)',
        'is_consistent' => 'Status Konsistensi',
        'top_alternative' => 'Prioritas Pertama',
        'top_preference' => 'Nilai Preferensi Tertinggi',
        'priority' => 'Prioritas',
        'performed_by' => 'Pelaksana',
        'target_date' => 'Target Pengerjaan',
        'completed_date' => 'Tanggal Selesai',
        'password_policy_changed' => 'Kebijakan Password Diubah',
        'migration' => 'Pembaruan Database',
        default => ucwords(str_replace('_', ' ', $field)),
    };
};

$statusLabel = static function (string $status): string {
    return match ($status) {
        'draft' => 'Draft',
        'active' => 'Aktif',
        'completed' => 'Menunggu Validasi',
        'approved' => 'Disetujui',
        'returned' => 'Dikembalikan',
        'topsis_processed' => 'Diproses TOPSIS',
        'pending' => 'Menunggu',
        'in_progress' => 'Dalam Proses',
        'done' => 'Selesai',
        'cancelled' => 'Dibatalkan',
        default => $status !== '' ? ucwords(str_replace('_', ' ', $status)) : '-',
    };
};

$formatAuditValue = static function (string $field, mixed $value) use ($userMap, $periodMap, $machineMap, $statusLabel): string {
    if ($value === null || $value === '') {
        return '-';
    }
    if ($field === 'status') {
        return $statusLabel((string) $value);
    }
    if ($field === 'reason' && (string) $value === 'rate_limited') {
        return 'Batas percobaan login tercapai';
    }
    if ($field === 'performed_by') {
        $id = (int) $value;
        return $userMap[$id] ?? ('Pengguna #' . $id);
    }
    if ($field === 'period_id') {
        $id = (int) $value;
        return $periodMap[$id] ?? ('Periode #' . $id);
    }
    if ($field === 'machine_id') {
        $id = (int) $value;
        return $machineMap[$id] ?? ('Motor #' . $id);
    }
    if ($field === 'assessment_id') {
        return 'Pemeriksaan #' . (int) $value;
    }
    if ($field === 'calculation_run_id') {
        return 'Perhitungan #' . (int) $value;
    }
    if ($field === 'is_consistent') {
        return (bool) $value ? 'Konsisten' : 'Tidak Konsisten';
    }
    if ($field === 'password_policy_changed') {
        return (bool) $value ? 'Ya' : 'Tidak';
    }
    if ($field === 'migration') {
        return 'Pembaruan struktur database berhasil diterapkan';
    }
    if ($field === 'comparisons' && is_array($value)) {
        return count($value) . ' perbandingan tersimpan';
    }
    if (is_array($value)) {
        return count($value) . ' data tersimpan';
    }
    if (is_bool($value)) {
        return $value ? 'Ya' : 'Tidak';
    }
    if (in_array($field, ['consistency_ratio', 'top_preference'], true) && is_numeric($value)) {
        return format_number_id((float) $value, 6);
    }
    if (is_float($value)) {
        return format_number_id($value, 4);
    }
    return (string) $value;
};

$activitySummary = static function (array $log, array $oldData, array $newData) use ($formatAuditValue): string {
    $action = (string) ($log['action'] ?? '');
    $actor = trim((string) ($log['actor_name'] ?? ''));
    $actor = $actor !== '' ? $actor : 'Sistem';
    $alternative = trim((string) ($newData['alternative_code'] ?? $oldData['alternative_code'] ?? ''));
    $periodName = trim((string) ($newData['name'] ?? $oldData['name'] ?? ''));

    return match ($action) {
        'login_success' => $actor . ' berhasil masuk ke sistem.',
        'login_failed' => 'Percobaan login untuk ' . ($actor !== 'Sistem' ? $actor : 'pengguna') . ' tidak berhasil.',
        'assessment_created' => 'Draft pemeriksaan' . ($alternative !== '' ? ' ' . $alternative : '') . ' dibuat dan siap dilengkapi.',
        'assessment_updated' => 'Data pemeriksaan' . ($alternative !== '' ? ' ' . $alternative : '') . ' diperbarui oleh Teknisi.',
        'assessment_submitted' => 'Pemeriksaan' . ($alternative !== '' ? ' ' . $alternative : '') . ' dikirim kepada Supervisor untuk divalidasi.',
        'assessment_validated' => 'Pemeriksaan' . ($alternative !== '' ? ' ' . $alternative : '') . ' disetujui dan dapat digunakan pada proses SPK.',
        'assessment_returned' => 'Pemeriksaan' . ($alternative !== '' ? ' ' . $alternative : '') . ' dikembalikan kepada Teknisi untuk diperbaiki.',
        'ahp_comparisons_saved' => 'Perbandingan berpasangan AHP berhasil disimpan.',
        'ahp_calculated' => 'Perhitungan AHP selesai dengan CR ' . $formatAuditValue('consistency_ratio', $newData['consistency_ratio'] ?? null) . ' (' . $formatAuditValue('is_consistent', $newData['is_consistent'] ?? null) . ').',
        'topsis_calculated' => 'Perhitungan TOPSIS selesai. Prioritas pertama adalah ' . ($newData['top_alternative'] ?? '-') . ' dengan nilai preferensi ' . $formatAuditValue('top_preference', $newData['top_preference'] ?? null) . '.',
        'period_created' => 'Periode pemeriksaan' . ($periodName !== '' ? ' “' . $periodName . '”' : '') . ' berhasil dibuat.',
        'period_updated' => 'Data periode pemeriksaan' . ($periodName !== '' ? ' “' . $periodName . '”' : '') . ' diperbarui.',
        'maintenance_recommendation_created' => 'Supervisor membuat rekomendasi tindak lanjut pemeliharaan.',
        'maintenance_recommendation_updated' => 'Supervisor memperbarui rekomendasi tindak lanjut pemeliharaan.',
        'maintenance_execution_updated' => 'Teknisi memperbarui pelaksanaan tindak lanjut pemeliharaan.',
        'maintenance_action_saved' => 'Data tindak lanjut pemeliharaan disimpan.',
        'system_upgrade' => 'Sistem mencatat pembaruan aplikasi dan database.',
        default => trim((string) ($log['description'] ?? '')) !== ''
            ? trim((string) $log['description'])
            : 'Aktivitas sistem tercatat.',
    };
};

$activityTone = static function (string $action): string {
    return match ($action) {
        'login_failed', 'assessment_returned' => 'warning',
        'assessment_validated', 'ahp_calculated', 'topsis_calculated', 'login_success' => 'success',
        'system_upgrade' => 'info',
        default => 'neutral',
    };
};

$activityIcon = static function (string $action): string {
    return match ($action) {
        'login_success', 'login_failed' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'assessment_created', 'assessment_updated', 'assessment_submitted', 'assessment_validated', 'assessment_returned' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 11l2 2 4-4M7 3h10a2 2 0 0 1 2 2v16H5V5a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'ahp_comparisons_saved', 'ahp_calculated' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m12 3 8 4-8 4-8-4 8-4Zm-8 9 8 4 8-4M4 17l8 4 8-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'topsis_calculated' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 19V9m5 10V5m5 14v-7m5 7V3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        'period_created', 'period_updated' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 3v3m12-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'maintenance_action_saved', 'maintenance_recommendation_created', 'maintenance_recommendation_updated', 'maintenance_execution_updated' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M14.7 6.3a4 4 0 0 0-5 5L3 18l3 3 6.7-6.7a4 4 0 0 0 5-5l-2.3 2.3-3-3 2.3-2.3Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        default => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 8v4l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    };
};

$pageTitle = 'Log Aktivitas';
$pageDescription = 'Jejak aktivitas penting sistem untuk kebutuhan administrasi dan pemeriksaan.';
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
                <h1 class="page-title">Log Aktivitas</h1>
                <p class="page-description mb-0">Catatan aktivitas penting ditampilkan dalam bahasa yang mudah dibaca. Detail teknis tetap tersimpan di database audit.</p>
            </div>
            <span class="badge text-bg-light border px-3 py-2">Maks. 200 aktivitas terbaru</span>
        </section>

        <section class="row g-3 mb-4">
            <div class="col-md-4"><div class="card border-0 shadow-sm summary-card h-100"><div class="card-body p-4"><div class="text-body-secondary small mb-1">Total Aktivitas</div><div class="fs-3 fw-bold"><?= e($totalLogs) ?></div><small class="text-body-secondary">Seluruh catatan yang tersimpan</small></div></div></div>
            <div class="col-md-4"><div class="card border-0 shadow-sm summary-card h-100"><div class="card-body p-4"><div class="text-body-secondary small mb-1">Aktivitas Hari Ini</div><div class="fs-3 fw-bold"><?= e($todayLogs) ?></div><small class="text-body-secondary">Aktivitas pada tanggal hari ini</small></div></div></div>
            <div class="col-md-4"><div class="card border-0 shadow-sm summary-card h-100"><div class="card-body p-4"><div class="text-body-secondary small mb-1">Perhitungan Tercatat</div><div class="fs-3 fw-bold"><?= e($calculationLogs) ?></div><small class="text-body-secondary">Proses AHP dan TOPSIS</small></div></div></div>
        </section>

        <section class="card border-0 shadow-sm mb-4" aria-labelledby="audit-filter-title">
            <div class="card-body p-4">
                <h2 id="audit-filter-title" class="h6 mb-3">Cari dan Saring Aktivitas</h2>
                <form method="get" action="<?= e(base_url('audit/index.php')) ?>" class="row g-3 align-items-end">
                    <div class="col-xl-4 col-lg-5">
                        <label for="q" class="form-label">Kata Kunci</label>
                        <input id="q" name="q" type="search" class="form-control" value="<?= e($search) ?>" placeholder="Nama pengguna atau aktivitas">
                    </div>
                    <div class="col-xl-3 col-lg-3">
                        <label for="action" class="form-label">Aktivitas</label>
                        <select id="action" name="action" class="form-select">
                            <option value="">Semua Aktivitas</option>
                            <?php foreach ($allowedActions as $action): ?>
                                <option value="<?= e($action) ?>" <?= selected($actionFilter, $action) ?>><?= e($actionLabel($action)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-xl-2 col-lg-2">
                        <label for="role" class="form-label">Peran</label>
                        <select id="role" name="role" class="form-select">
                            <option value="">Semua Peran</option>
                            <option value="admin" <?= selected($roleFilter, 'admin') ?>>Administrator</option>
                            <option value="supervisor" <?= selected($roleFilter, 'supervisor') ?>>Supervisor</option>
                            <option value="technician" <?= selected($roleFilter, 'technician') ?>>Teknisi</option>
                            <option value="system" <?= selected($roleFilter, 'system') ?>>Sistem</option>
                        </select>
                    </div>
                    <div class="col-xl-3 col-lg-2">
                        <div class="filter-action-row">
                            <button class="btn btn-primary" type="submit">Terapkan</button>
                            <a class="btn btn-outline-secondary" href="<?= e(base_url('audit/index.php')) ?>">Reset</a>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <section class="card border-0 shadow-sm activity-log-card">
            <div class="card-header bg-white border-bottom py-3 px-4 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                <div>
                    <h2 class="h5 mb-1">Riwayat Aktivitas</h2>
                    <p class="text-body-secondary small mb-0"><?= e(count($logs)) ?> aktivitas sesuai filter.</p>
                </div>
                <?php if ($search !== '' || $actionFilter !== '' || $roleFilter !== ''): ?>
                    <span class="badge text-bg-light border">Filter aktif</span>
                <?php endif; ?>
            </div>

            <?php if ($logs === []): ?>
                <div class="text-center text-body-secondary py-5 px-4">Belum ada aktivitas yang sesuai filter.</div>
            <?php else: ?>
                <div class="activity-log-list">
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $oldData = $decodeAuditData($log['old_data'] ?? null);
                        $newData = $decodeAuditData($log['new_data'] ?? null);
                        $hasDetail = $oldData !== [] || $newData !== [];
                        $actorRole = trim((string) ($log['actor_role'] ?? ''));
                        $actorRoleLabel = $actorRole === 'system'
                            ? 'Sistem'
                            : ($actorRole !== '' ? auth_role_label($actorRole) : 'Tidak diketahui');
                        $summary = $activitySummary($log, $oldData, $newData);
                        $tone = $activityTone((string) $log['action']);
                        $module = $moduleLabel($log);

                        $changedPreview = [];
                        $previewFields = array_values(array_unique(array_merge(array_keys($oldData), array_keys($newData))));
                        foreach ($previewFields as $field) {
                            if (in_array($field, ['comparisons', 'migration', 'password_policy_changed', 'calculation_run_id'], true)) {
                                continue;
                            }
                            $beforeExists = array_key_exists($field, $oldData);
                            $afterExists = array_key_exists($field, $newData);
                            $before = $beforeExists ? $formatAuditValue((string) $field, $oldData[$field]) : '-';
                            $after = $afterExists ? $formatAuditValue((string) $field, $newData[$field]) : '-';
                            if ($beforeExists && $afterExists && $before === $after) {
                                continue;
                            }
                            $changedPreview[] = [
                                'label' => $fieldLabel((string) $field),
                                'before' => $before,
                                'after' => $after,
                                'is_change' => $beforeExists && $afterExists,
                            ];
                            if (count($changedPreview) >= 2) {
                                break;
                            }
                        }
                        ?>
                        <article class="activity-log-item">
                            <div class="activity-log-icon activity-tone-<?= e($tone) ?>">
                                <?= $activityIcon((string) $log['action']) ?>
                            </div>
                            <div class="activity-log-main">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span class="activity-module-badge"><?= e($module) ?></span>
                                    <span class="activity-action-label"><?= e($actionLabel((string) $log['action'])) ?></span>
                                </div>
                                <h3 class="activity-summary mb-2"><?= e($summary) ?></h3>
                                <div class="activity-meta mb-2">
                                    <strong><?= e($log['actor_name'] ?: 'Sistem/Tamu') ?></strong>
                                    <span>·</span>
                                    <span><?= e($actorRoleLabel) ?></span>
                                    <span>·</span>
                                    <span><?= e(format_date_id($log['created_at'] ?? null, true)) ?></span>
                                </div>

                                <?php if ($changedPreview !== []): ?>
                                    <div class="activity-change-preview">
                                        <?php foreach ($changedPreview as $preview): ?>
                                            <div class="activity-change-chip">
                                                <span class="activity-change-label"><?= e($preview['label']) ?></span>
                                                <?php if ($preview['is_change']): ?>
                                                    <span><?= e($preview['before']) ?></span>
                                                    <span class="activity-change-arrow">→</span>
                                                <?php endif; ?>
                                                <strong><?= e($preview['after']) ?></strong>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="activity-log-action">
                                <?php if ($hasDetail): ?>
                                    <button type="button" class="btn btn-outline-primary table-action-button" data-bs-toggle="modal" data-bs-target="#auditDetailModal<?= e((int) $log['id']) ?>">
                                        Lihat Rincian
                                    </button>
                                <?php else: ?>
                                    <span class="text-body-secondary small">Tanpa rincian</span>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php foreach ($logs as $log): ?>
            <?php
            $modalOldData = $decodeAuditData($log['old_data'] ?? null);
            $modalNewData = $decodeAuditData($log['new_data'] ?? null);
            $modalHasDetail = $modalOldData !== [] || $modalNewData !== [];
            $allFields = array_values(array_unique(array_merge(array_keys($modalOldData), array_keys($modalNewData))));
            $isChange = $modalOldData !== [] && $modalNewData !== [];
            $modalSummary = $activitySummary($log, $modalOldData, $modalNewData);
            $modalModule = $moduleLabel($log);
            $modalActorRole = trim((string) ($log['actor_role'] ?? ''));
            $modalActorRoleLabel = $modalActorRole === 'system'
                ? 'Sistem'
                : ($modalActorRole !== '' ? auth_role_label($modalActorRole) : 'Tidak diketahui');
            ?>
            <?php if ($modalHasDetail): ?>
                <div class="modal fade" id="auditDetailModal<?= e((int) $log['id']) ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content audit-readable-modal">
                            <div class="modal-header align-items-start">
                                <div>
                                    <span class="activity-module-badge d-inline-flex mb-2"><?= e($modalModule) ?></span>
                                    <h2 class="modal-title h5 mb-1"><?= e($actionLabel((string) $log['action'])) ?></h2>
                                    <p class="small text-body-secondary mb-0">Rincian aktivitas dalam format yang mudah dibaca.</p>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                            </div>
                            <div class="modal-body p-4">
                                <section class="audit-summary-box mb-4">
                                    <span class="audit-section-kicker">Ringkasan</span>
                                    <p class="mb-0 fw-semibold"><?= e($modalSummary) ?></p>
                                </section>

                                <section class="mb-4">
                                    <h3 class="h6 mb-3">Informasi Aktivitas</h3>
                                    <div class="row g-3">
                                        <div class="col-sm-6 col-xl-3"><div class="audit-info-box"><span>Waktu</span><strong><?= e(format_date_id($log['created_at'] ?? null, true)) ?></strong></div></div>
                                        <div class="col-sm-6 col-xl-3"><div class="audit-info-box"><span>Pengguna</span><strong><?= e($log['actor_name'] ?: 'Sistem/Tamu') ?></strong></div></div>
                                        <div class="col-sm-6 col-xl-3"><div class="audit-info-box"><span>Peran</span><strong><?= e($modalActorRoleLabel) ?></strong></div></div>
                                        <div class="col-sm-6 col-xl-3"><div class="audit-info-box"><span>Modul</span><strong><?= e($modalModule) ?></strong></div></div>
                                    </div>
                                </section>

                                <section>
                                    <div class="mb-3">
                                        <h3 class="h6 mb-1"><?= $isChange ? 'Apa yang Berubah?' : 'Data yang Tercatat' ?></h3>
                                        <p class="small text-body-secondary mb-0"><?= $isChange ? 'Hanya informasi yang berubah yang ditampilkan.' : 'Informasi penting yang tersimpan pada aktivitas ini.' ?></p>
                                    </div>

                                    <div class="table-responsive border rounded-3 overflow-hidden">
                                        <table class="table align-middle mb-0 audit-change-table">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Informasi</th>
                                                    <?php if ($isChange): ?><th>Sebelum</th><?php endif; ?>
                                                    <th><?= $isChange ? 'Sesudah' : 'Nilai' ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php
                                            $shownRows = 0;
                                            foreach ($allFields as $field):
                                                $beforeExists = array_key_exists($field, $modalOldData);
                                                $afterExists = array_key_exists($field, $modalNewData);
                                                $before = $beforeExists ? $formatAuditValue((string) $field, $modalOldData[$field]) : '-';
                                                $after = $afterExists ? $formatAuditValue((string) $field, $modalNewData[$field]) : '-';
                                                if ($isChange && $beforeExists && $afterExists && $before === $after) {
                                                    continue;
                                                }
                                                $shownRows++;
                                            ?>
                                                <tr>
                                                    <td><strong><?= e($fieldLabel((string) $field)) ?></strong></td>
                                                    <?php if ($isChange): ?><td><span class="audit-value-before"><?= e($before) ?></span></td><?php endif; ?>
                                                    <td><span class="audit-value-after"><?= e($after) ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if ($shownRows === 0): ?>
                                                <tr><td colspan="<?= $isChange ? '3' : '2' ?>" class="text-center text-body-secondary py-4">Tidak ada perubahan data yang perlu ditampilkan.</td></tr>
                                            <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </main>
</div>

<?php require TEMPLATE_PATH . '/footer.php'; ?>

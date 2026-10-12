-- ============================================================================
-- SISTEM PENDUKUNG KEPUTUSAN PT CISAS
-- File     : database/schema.sql
-- Fungsi   : Membuat seluruh struktur tabel sistem
-- Database : SQLite
-- Struktur inti mencakup pengguna, pemeriksaan, AHP, TOPSIS, tindakan, audit, dan histori perhitungan.
-- ============================================================================

PRAGMA foreign_keys = ON;

BEGIN TRANSACTION;

-- ============================================================================
-- 1. TABEL PENGGUNA
-- ============================================================================

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY,

    name TEXT NOT NULL
        CHECK (
            length(trim(name)) > 0
        ),

    username TEXT NOT NULL
        COLLATE NOCASE
        UNIQUE
        CHECK (
            length(trim(username)) >= 3
        ),

    password_hash TEXT NOT NULL
        CHECK (
            length(trim(password_hash)) > 0
        ),

    role TEXT NOT NULL
        CHECK (
            role IN (
                'admin',
                'supervisor',
                'technician'
            )
        ),

    is_active INTEGER NOT NULL DEFAULT 1
        CHECK (
            is_active IN (0, 1)
        ),

    created_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours'))
);

CREATE INDEX IF NOT EXISTS idx_users_role
ON users(role);

CREATE INDEX IF NOT EXISTS idx_users_active
ON users(is_active);

-- Administrator dan Supervisor tetap tunggal. Teknisi boleh lebih dari satu.
CREATE UNIQUE INDEX IF NOT EXISTS idx_one_admin
ON users(role)
WHERE role = 'admin';

CREATE UNIQUE INDEX IF NOT EXISTS idx_one_supervisor
ON users(role)
WHERE role = 'supervisor';

-- Peran akun dikunci setelah dibuat agar riwayat aktivitas tetap jelas.
CREATE TRIGGER IF NOT EXISTS trg_users_role_immutable
BEFORE UPDATE OF role ON users
WHEN NEW.role <> OLD.role
BEGIN
    SELECT RAISE(
        ABORT,
        'Peran akun tidak dapat diubah. Buat akun Teknisi baru jika diperlukan.'
    );
END;

-- Administrator dan Supervisor wajib aktif. Teknisi boleh dinonaktifkan.
CREATE TRIGGER IF NOT EXISTS trg_users_must_stay_active
BEFORE UPDATE OF is_active ON users
WHEN OLD.role IN ('admin', 'supervisor')
 AND NEW.is_active <> 1
BEGIN
    SELECT RAISE(
        ABORT,
        'Akun Administrator dan Supervisor wajib tetap aktif.'
    );
END;

-- Akun tidak dihapus agar riwayat pemeriksaan/tindakan tetap utuh.
-- Teknisi yang sudah tidak digunakan cukup dinonaktifkan.
CREATE TRIGGER IF NOT EXISTS trg_users_no_delete
BEFORE DELETE ON users
BEGIN
    SELECT RAISE(
        ABORT,
        'Akun tidak dapat dihapus. Nonaktifkan akun Teknisi jika sudah tidak digunakan.'
    );
END;

-- ============================================================================
-- 2. TABEL DATA MASTER MOTOR
-- ============================================================================

CREATE TABLE IF NOT EXISTS machines (
    id INTEGER PRIMARY KEY,

    alternative_code TEXT NOT NULL UNIQUE
        CHECK (
            alternative_code IN (
                'A1',
                'A2',
                'A3',
                'A4',
                'A5',
                'A6'
            )
        ),

    machine_code TEXT NOT NULL
        CHECK (
            machine_code IN (
                'MIX-01',
                'MIX-02',
                'MIX-03'
            )
        ),

    machine_number INTEGER NOT NULL
        CHECK (
            machine_number BETWEEN 1 AND 3
        ),

    motor_function TEXT NOT NULL
        CHECK (
            motor_function IN (
                'scraper',
                'homogenizer'
            )
        ),

    motor_name TEXT NOT NULL
        CHECK (
            length(trim(motor_name)) > 0
        ),

    brand TEXT,

    voltage TEXT,

    rated_current REAL
        CHECK (
            rated_current IS NULL
            OR rated_current > 0
        ),

    rated_frequency REAL
        CHECK (
            rated_frequency IS NULL
            OR rated_frequency > 0
        ),

    rated_rpm REAL
        CHECK (
            rated_rpm IS NULL
            OR rated_rpm > 0
        ),

    power_hp REAL
        CHECK (
            power_hp IS NULL
            OR power_hp > 0
        ),

    number_of_poles INTEGER
        CHECK (
            number_of_poles IS NULL
            OR number_of_poles > 0
        ),

    location TEXT,

    status TEXT NOT NULL DEFAULT 'active'
        CHECK (
            status IN (
                'active',
                'inactive'
            )
        ),

    notes TEXT,

    created_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),

    UNIQUE (
        machine_number,
        motor_function
    ),

    -- Identitas A1 sampai A6 dikunci agar tidak dapat tertukar.
    CHECK (
        (
            alternative_code = 'A1'
            AND machine_code = 'MIX-01'
            AND machine_number = 1
            AND motor_function = 'scraper'
        )
        OR
        (
            alternative_code = 'A2'
            AND machine_code = 'MIX-01'
            AND machine_number = 1
            AND motor_function = 'homogenizer'
        )
        OR
        (
            alternative_code = 'A3'
            AND machine_code = 'MIX-02'
            AND machine_number = 2
            AND motor_function = 'scraper'
        )
        OR
        (
            alternative_code = 'A4'
            AND machine_code = 'MIX-02'
            AND machine_number = 2
            AND motor_function = 'homogenizer'
        )
        OR
        (
            alternative_code = 'A5'
            AND machine_code = 'MIX-03'
            AND machine_number = 3
            AND motor_function = 'scraper'
        )
        OR
        (
            alternative_code = 'A6'
            AND machine_code = 'MIX-03'
            AND machine_number = 3
            AND motor_function = 'homogenizer'
        )
    )
);

CREATE INDEX IF NOT EXISTS idx_machines_status
ON machines(status);

CREATE INDEX IF NOT EXISTS idx_machines_machine_number
ON machines(machine_number);

-- ============================================================================
-- 3. TABEL KRITERIA
-- ============================================================================

CREATE TABLE IF NOT EXISTS criteria (
    id INTEGER PRIMARY KEY,

    code TEXT NOT NULL UNIQUE
        CHECK (
            code IN (
                'C1',
                'C2',
                'C3',
                'C4',
                'C5'
            )
        ),

    name TEXT NOT NULL
        CHECK (
            length(trim(name)) > 0
        ),

    description TEXT,

    display_order INTEGER NOT NULL UNIQUE
        CHECK (
            display_order BETWEEN 1 AND 5
        ),

    is_active INTEGER NOT NULL DEFAULT 1
        CHECK (
            is_active IN (0, 1)
        ),

    created_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours'))
);

-- ============================================================================
-- 4. TABEL PERIODE PEMERIKSAAN
-- ============================================================================

CREATE TABLE IF NOT EXISTS assessment_periods (
    id INTEGER PRIMARY KEY,

    name TEXT NOT NULL
        COLLATE NOCASE
        UNIQUE
        CHECK (
            length(trim(name)) > 0
        ),

    start_date TEXT NOT NULL,

    end_date TEXT NOT NULL,

    status TEXT NOT NULL DEFAULT 'draft'
        CHECK (
            status IN (
                'draft',
                'active',
                'completed'
            )
        ),

    created_by INTEGER NOT NULL,

    created_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),

    CHECK (
        end_date >= start_date
    ),

    FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_assessment_periods_status
ON assessment_periods(status);

CREATE INDEX IF NOT EXISTS idx_assessment_periods_dates
ON assessment_periods(
    start_date,
    end_date
);

-- Hanya satu periode yang boleh aktif.
CREATE UNIQUE INDEX IF NOT EXISTS idx_one_active_period
ON assessment_periods(status)
WHERE status = 'active';

-- ============================================================================
-- 5. TABEL PEMERIKSAAN UTAMA
-- ============================================================================

CREATE TABLE IF NOT EXISTS assessments (
    id INTEGER PRIMARY KEY,

    period_id INTEGER NOT NULL,

    machine_id INTEGER NOT NULL,

    inspection_date TEXT NOT NULL,

    inspection_time TEXT NOT NULL,

    inspector_id INTEGER NOT NULL,

    operating_status TEXT NOT NULL
        CHECK (
            operating_status IN (
                'running',
                'stopped'
            )
        ),

    load_condition TEXT NOT NULL
        CHECK (
            load_condition IN (
                'no_load',
                'light',
                'normal',
                'high'
            )
        ),

    operating_notes TEXT,

    /*
    Status internal:

    draft
    = masih disimpan oleh Teknisi.

    completed
    = telah dikirim kepada Supervisor.

    validated
    = telah disetujui Supervisor.

    returned
    = dikembalikan kepada Teknisi.

    processed
    = telah diproses menggunakan TOPSIS.
    */

    status TEXT NOT NULL DEFAULT 'draft'
        CHECK (
            status IN (
                'draft',
                'completed',
                'validated',
                'returned',
                'processed'
            )
        ),

    validated_by INTEGER,

    validated_at TEXT,

    supervisor_note TEXT,

    created_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),

    UNIQUE (
        period_id,
        machine_id
    ),

    CHECK (
        status NOT IN (
            'validated',
            'processed'
        )
        OR (
            validated_by IS NOT NULL
            AND validated_at IS NOT NULL
        )
    ),

    FOREIGN KEY (period_id)
        REFERENCES assessment_periods(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    FOREIGN KEY (machine_id)
        REFERENCES machines(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    FOREIGN KEY (inspector_id)
        REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    FOREIGN KEY (validated_by)
        REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_assessments_period
ON assessments(period_id);

CREATE INDEX IF NOT EXISTS idx_assessments_machine
ON assessments(machine_id);

CREATE INDEX IF NOT EXISTS idx_assessments_inspector
ON assessments(inspector_id);

CREATE INDEX IF NOT EXISTS idx_assessments_status
ON assessments(status);

-- ============================================================================
-- 6. TABEL PENGUKURAN C1, C2, DAN C3
-- ============================================================================

CREATE TABLE IF NOT EXISTS measurements (
    id INTEGER PRIMARY KEY,

    assessment_id INTEGER NOT NULL UNIQUE,

    -- ------------------------------------------------------------------------
    -- C1: SUHU PERMUKAAN MOTOR
    -- ------------------------------------------------------------------------

    temperature_1 REAL
        CHECK (
            temperature_1 IS NULL
            OR temperature_1 >= 0
        ),

    temperature_2 REAL
        CHECK (
            temperature_2 IS NULL
            OR temperature_2 >= 0
        ),

    temperature_3 REAL
        CHECK (
            temperature_3 IS NULL
            OR temperature_3 >= 0
        ),

    temperature_average REAL
        CHECK (
            temperature_average IS NULL
            OR temperature_average >= 0
        ),

    temperature_measurement_point TEXT,

    -- ------------------------------------------------------------------------
    -- C2: PERSENTASE ARUS MOTOR
    -- ------------------------------------------------------------------------

    current_1 REAL
        CHECK (
            current_1 IS NULL
            OR current_1 >= 0
        ),

    current_2 REAL
        CHECK (
            current_2 IS NULL
            OR current_2 >= 0
        ),

    current_3 REAL
        CHECK (
            current_3 IS NULL
            OR current_3 >= 0
        ),

    current_average REAL
        CHECK (
            current_average IS NULL
            OR current_average >= 0
        ),

    -- Salinan arus nominal ketika pemeriksaan dilakukan.
    rated_current REAL
        CHECK (
            rated_current IS NULL
            OR rated_current > 0
        ),

    current_percentage REAL
        CHECK (
            current_percentage IS NULL
            OR current_percentage >= 0
        ),

    -- ------------------------------------------------------------------------
    -- C3: DEVIASI RPM
    -- ------------------------------------------------------------------------

    operating_frequency REAL
        CHECK (
            operating_frequency IS NULL
            OR operating_frequency > 0
        ),

    frequency_source TEXT,

    -- Salinan data nameplate ketika pemeriksaan dilakukan.
    rated_frequency REAL
        CHECK (
            rated_frequency IS NULL
            OR rated_frequency > 0
        ),

    rated_rpm REAL
        CHECK (
            rated_rpm IS NULL
            OR rated_rpm > 0
        ),

    rpm_1 REAL
        CHECK (
            rpm_1 IS NULL
            OR rpm_1 >= 0
        ),

    rpm_2 REAL
        CHECK (
            rpm_2 IS NULL
            OR rpm_2 >= 0
        ),

    rpm_3 REAL
        CHECK (
            rpm_3 IS NULL
            OR rpm_3 >= 0
        ),

    rpm_average REAL
        CHECK (
            rpm_average IS NULL
            OR rpm_average >= 0
        ),

    /*
    RPM acuan dihitung menggunakan:

    RPM nominal ×
    (frekuensi operasi / frekuensi nominal)
    */

    rpm_reference REAL
        CHECK (
            rpm_reference IS NULL
            OR rpm_reference > 0
        ),

    rpm_reference_method TEXT
        CHECK (
            rpm_reference_method IS NULL
            OR rpm_reference_method IN (
                'frequency_adjusted_nameplate',
                'manual',
                'historical'
            )
        ),

    rpm_measurement_point TEXT,

    rpm_deviation_percentage REAL
        CHECK (
            rpm_deviation_percentage IS NULL
            OR rpm_deviation_percentage >= 0
        ),

    created_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),

    FOREIGN KEY (assessment_id)
        REFERENCES assessments(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

-- ============================================================================
-- 7. TABEL ITEM CHECKLIST C4 DAN C5
-- ============================================================================

CREATE TABLE IF NOT EXISTS checklist_items (
    id INTEGER PRIMARY KEY,

    criterion_id INTEGER NOT NULL,

    item_code TEXT NOT NULL UNIQUE
        CHECK (
            length(trim(item_code)) > 0
        ),

    item_name TEXT NOT NULL
        CHECK (
            length(trim(item_name)) > 0
        ),

    inspected_part TEXT NOT NULL
        CHECK (
            length(trim(inspected_part)) > 0
        ),

    normal_description TEXT NOT NULL
        CHECK (
            length(trim(normal_description)) > 0
        ),

    warning_description TEXT NOT NULL
        CHECK (
            length(trim(warning_description)) > 0
        ),

    danger_description TEXT NOT NULL
        CHECK (
            length(trim(danger_description)) > 0
        ),

    allows_na INTEGER NOT NULL DEFAULT 0
        CHECK (
            allows_na IN (0, 1)
        ),

    display_order INTEGER NOT NULL
        CHECK (
            display_order BETWEEN 1 AND 6
        ),

    is_active INTEGER NOT NULL DEFAULT 1
        CHECK (
            is_active IN (0, 1)
        ),

    created_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),

    UNIQUE (
        criterion_id,
        display_order
    ),

    FOREIGN KEY (criterion_id)
        REFERENCES criteria(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_checklist_items_criterion
ON checklist_items(criterion_id);

CREATE INDEX IF NOT EXISTS idx_checklist_items_active
ON checklist_items(is_active);

-- ============================================================================
-- 8. TABEL HASIL CHECKLIST C4 DAN C5
-- ============================================================================

CREATE TABLE IF NOT EXISTS checklist_results (
    id INTEGER PRIMARY KEY,

    assessment_id INTEGER NOT NULL,

    checklist_item_id INTEGER NOT NULL,

    condition_level TEXT NOT NULL DEFAULT 'not_checked'
        CHECK (
            condition_level IN (
                'normal',
                'warning',
                'danger',
                'not_applicable',
                'not_checked'
            )
        ),

    score INTEGER
        CHECK (
            score IS NULL
            OR score BETWEEN 1 AND 3
        ),

    finding_note TEXT,

    created_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),

    UNIQUE (
        assessment_id,
        checklist_item_id
    ),

    -- Pasangan kondisi dan skor harus sesuai.
    CHECK (
        (
            condition_level = 'normal'
            AND score = 1
        )
        OR
        (
            condition_level = 'warning'
            AND score = 2
        )
        OR
        (
            condition_level = 'danger'
            AND score = 3
        )
        OR
        (
            condition_level IN (
                'not_applicable',
                'not_checked'
            )
            AND score IS NULL
        )
    ),

    /*
    Keterangan wajib hanya untuk:

    warning = Perlu Perhatian
    danger  = Bahaya

    not_checked merupakan status internal untuk bagian kosong ketika masih Draft.
    */

    CHECK (
        condition_level NOT IN (
            'warning',
            'danger'
        )
        OR (
            finding_note IS NOT NULL
            AND length(trim(finding_note)) > 0
        )
    ),

    FOREIGN KEY (assessment_id)
        REFERENCES assessments(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    FOREIGN KEY (checklist_item_id)
        REFERENCES checklist_items(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_checklist_results_assessment
ON checklist_results(assessment_id);

CREATE INDEX IF NOT EXISTS idx_checklist_results_item
ON checklist_results(checklist_item_id);

CREATE INDEX IF NOT EXISTS idx_checklist_results_condition
ON checklist_results(condition_level);

-- ============================================================================
-- 9. TRIGGER PEMBATASAN PILIHAN TIDAK TERSEDIA
-- ============================================================================

CREATE TRIGGER IF NOT EXISTS trg_checklist_na_insert
BEFORE INSERT ON checklist_results
WHEN
    NEW.condition_level = 'not_applicable'
    AND NOT EXISTS (
        SELECT 1

        FROM checklist_items

        WHERE checklist_items.id =
              NEW.checklist_item_id

          AND checklist_items.allows_na = 1
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'Indikator ini tidak mendukung pilihan Tidak Tersedia.'
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_checklist_na_update
BEFORE UPDATE OF
    condition_level,
    checklist_item_id
ON checklist_results
WHEN
    NEW.condition_level = 'not_applicable'
    AND NOT EXISTS (
        SELECT 1

        FROM checklist_items

        WHERE checklist_items.id =
              NEW.checklist_item_id

          AND checklist_items.allows_na = 1
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'Indikator ini tidak mendukung pilihan Tidak Tersedia.'
    );
END;

-- ============================================================================
-- 10. TABEL NILAI AKHIR C1 SAMPAI C5
-- ============================================================================

CREATE TABLE IF NOT EXISTS assessment_scores (
    id INTEGER PRIMARY KEY,

    assessment_id INTEGER NOT NULL UNIQUE,

    c1_value REAL NOT NULL
        CHECK (
            c1_value >= 0
        ),

    c2_value REAL NOT NULL
        CHECK (
            c2_value >= 0
        ),

    c3_value REAL NOT NULL
        CHECK (
            c3_value >= 0
        ),

    -- ------------------------------------------------------------------------
    -- REKAP C4: KONDISI MEKANIKAL
    -- ------------------------------------------------------------------------

    c4_total_score INTEGER NOT NULL,

    c4_evaluated_count INTEGER NOT NULL
        CHECK (
            c4_evaluated_count BETWEEN 1 AND 6
        ),

    c4_na_count INTEGER NOT NULL DEFAULT 0
        CHECK (
            c4_na_count BETWEEN 0 AND 6
        ),

    c4_not_checked_count INTEGER NOT NULL DEFAULT 0
        CHECK (
            c4_not_checked_count BETWEEN 0 AND 6
        ),

    c4_value REAL NOT NULL
        CHECK (
            c4_value BETWEEN 1 AND 3
        ),

    c4_has_danger INTEGER NOT NULL DEFAULT 0
        CHECK (
            c4_has_danger IN (0, 1)
        ),

    -- ------------------------------------------------------------------------
    -- REKAP C5: KONDISI ELEKTRIKAL
    -- ------------------------------------------------------------------------

    c5_total_score INTEGER NOT NULL,

    c5_evaluated_count INTEGER NOT NULL
        CHECK (
            c5_evaluated_count BETWEEN 1 AND 6
        ),

    c5_na_count INTEGER NOT NULL DEFAULT 0
        CHECK (
            c5_na_count BETWEEN 0 AND 6
        ),

    c5_not_checked_count INTEGER NOT NULL DEFAULT 0
        CHECK (
            c5_not_checked_count BETWEEN 0 AND 6
        ),

    c5_value REAL NOT NULL
        CHECK (
            c5_value BETWEEN 1 AND 3
        ),

    c5_has_danger INTEGER NOT NULL DEFAULT 0
        CHECK (
            c5_has_danger IN (0, 1)
        ),

    calculated_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),

    CHECK (
        c4_total_score >= c4_evaluated_count
        AND c4_total_score <= c4_evaluated_count * 3
    ),

    CHECK (
        c5_total_score >= c5_evaluated_count
        AND c5_total_score <= c5_evaluated_count * 3
    ),

    CHECK (
        c4_evaluated_count
        + c4_na_count
        + c4_not_checked_count
        = 6
    ),

    CHECK (
        c5_evaluated_count
        + c5_na_count
        + c5_not_checked_count
        = 6
    ),

    FOREIGN KEY (assessment_id)
        REFERENCES assessments(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

-- ============================================================================
-- 11. TRIGGER KELENGKAPAN PEMERIKSAAN
-- ============================================================================

/*
Pemeriksaan tidak dapat berubah menjadi:

completed
validated
processed

apabila:

1. Belum memiliki seluruh hasil checklist aktif.
2. Masih terdapat checklist wajib berstatus not_checked.
3. Data pengukuran C1, C2, dan C3 belum lengkap.
4. Nilai turunan pengukuran belum dihitung.
5. Nilai C1 sampai C5 belum disimpan.
*/

CREATE TRIGGER IF NOT EXISTS trg_assessment_completion_check
BEFORE UPDATE OF status
ON assessments
WHEN
    NEW.status IN (
        'completed',
        'validated',
        'processed'
    )
    AND (
        -- Seluruh checklist aktif harus mempunyai hasil.
        EXISTS (
            SELECT 1

            FROM checklist_items AS ci

            WHERE ci.is_active = 1

              AND NOT EXISTS (
                  SELECT 1

                  FROM checklist_results AS cr

                  WHERE cr.assessment_id = NEW.id

                    AND cr.checklist_item_id =
                        ci.id
              )
        )

        -- Tidak boleh ada checklist aktif yang belum diisi.
        OR EXISTS (
            SELECT 1

            FROM checklist_results AS cr

            INNER JOIN checklist_items AS ci
                ON ci.id =
                   cr.checklist_item_id

            WHERE cr.assessment_id = NEW.id

              AND ci.is_active = 1

              AND cr.condition_level =
                  'not_checked'
        )

        -- Data pengukuran dan nilai turunannya harus lengkap.
        OR NOT EXISTS (
            SELECT 1

            FROM measurements AS ms

            WHERE ms.assessment_id = NEW.id

              AND ms.temperature_1 IS NOT NULL

              AND ms.temperature_2 IS NOT NULL

              AND ms.temperature_3 IS NOT NULL

              AND ms.temperature_average IS NOT NULL

              AND ms.current_1 IS NOT NULL

              AND ms.current_2 IS NOT NULL

              AND ms.current_3 IS NOT NULL

              AND ms.current_average IS NOT NULL

              AND ms.rated_current IS NOT NULL

              AND ms.current_percentage IS NOT NULL

              AND ms.operating_frequency IS NOT NULL

              AND ms.rated_frequency IS NOT NULL

              AND ms.rated_rpm IS NOT NULL

              AND ms.rpm_1 IS NOT NULL

              AND ms.rpm_2 IS NOT NULL

              AND ms.rpm_3 IS NOT NULL

              AND ms.rpm_average IS NOT NULL

              AND ms.rpm_reference IS NOT NULL

              AND ms.rpm_deviation_percentage
                  IS NOT NULL
        )

        -- Nilai C1 sampai C5 harus sudah tersedia.
        OR NOT EXISTS (
            SELECT 1

            FROM assessment_scores AS s

            WHERE s.assessment_id = NEW.id

              AND s.c1_value IS NOT NULL

              AND s.c2_value IS NOT NULL

              AND s.c3_value IS NOT NULL

              AND s.c4_value IS NOT NULL

              AND s.c5_value IS NOT NULL

              AND s.c4_not_checked_count = 0

              AND s.c5_not_checked_count = 0
        )
    )
BEGIN
    SELECT RAISE(
        ABORT,
        'Pemeriksaan belum lengkap. Simpan pengukuran, checklist, dan nilai C1-C5 sebelum mengirim.'
    );
END;

-- ============================================================================
-- 12. TABEL PERBANDINGAN BERPASANGAN AHP
-- ============================================================================

CREATE TABLE IF NOT EXISTS ahp_comparisons (
    id INTEGER PRIMARY KEY,

    period_id INTEGER NOT NULL,

    criterion_left_id INTEGER NOT NULL,

    criterion_right_id INTEGER NOT NULL,

    comparison_value REAL NOT NULL
        CHECK (
            abs(comparison_value - 0.1111111111111111) < 0.000000001
            OR abs(comparison_value - 0.14285714285714285) < 0.000000001
            OR abs(comparison_value - 0.20) < 0.000000001
            OR abs(comparison_value - 0.3333333333333333) < 0.000000001
            OR abs(comparison_value - 1.00) < 0.000000001
            OR abs(comparison_value - 3.00) < 0.000000001
            OR abs(comparison_value - 5.00) < 0.000000001
            OR abs(comparison_value - 7.00) < 0.000000001
            OR abs(comparison_value - 9.00) < 0.000000001
        ),

    assessor_id INTEGER NOT NULL,

    created_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),

    CHECK (
        criterion_left_id < criterion_right_id
    ),

    UNIQUE (
        period_id,
        criterion_left_id,
        criterion_right_id
    ),

    FOREIGN KEY (period_id)
        REFERENCES assessment_periods(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    FOREIGN KEY (criterion_left_id)
        REFERENCES criteria(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    FOREIGN KEY (criterion_right_id)
        REFERENCES criteria(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    FOREIGN KEY (assessor_id)
        REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_ahp_comparisons_period
ON ahp_comparisons(period_id);

CREATE INDEX IF NOT EXISTS idx_ahp_comparisons_assessor
ON ahp_comparisons(assessor_id);

-- ============================================================================
-- 13. TABEL BOBOT HASIL AHP
-- ============================================================================

CREATE TABLE IF NOT EXISTS ahp_results (
    id INTEGER PRIMARY KEY,

    period_id INTEGER NOT NULL,

    criterion_id INTEGER NOT NULL,

    weight REAL NOT NULL
        CHECK (
            weight BETWEEN 0 AND 1
        ),

    calculated_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),

    UNIQUE (
        period_id,
        criterion_id
    ),

    FOREIGN KEY (period_id)
        REFERENCES assessment_periods(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    FOREIGN KEY (criterion_id)
        REFERENCES criteria(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_ahp_results_period
ON ahp_results(period_id);

-- ============================================================================
-- 14. TABEL RINGKASAN KONSISTENSI AHP
-- ============================================================================

CREATE TABLE IF NOT EXISTS ahp_summaries (
    id INTEGER PRIMARY KEY,

    period_id INTEGER NOT NULL UNIQUE,

    assessor_id INTEGER NOT NULL,

    lambda_max REAL NOT NULL,

    consistency_index REAL NOT NULL,

    random_index REAL NOT NULL,

    consistency_ratio REAL NOT NULL,

    is_consistent INTEGER NOT NULL
        CHECK (
            is_consistent IN (0, 1)
        ),

    calculated_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),

    FOREIGN KEY (period_id)
        REFERENCES assessment_periods(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    FOREIGN KEY (assessor_id)
        REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

-- ============================================================================
-- 15. TABEL HASIL TOPSIS
-- ============================================================================

CREATE TABLE IF NOT EXISTS topsis_results (
    id INTEGER PRIMARY KEY,

    period_id INTEGER NOT NULL,

    assessment_id INTEGER NOT NULL,

    positive_distance REAL NOT NULL
        CHECK (
            positive_distance >= 0
        ),

    negative_distance REAL NOT NULL
        CHECK (
            negative_distance >= 0
        ),

    preference_value REAL NOT NULL
        CHECK (
            preference_value BETWEEN 0 AND 1
        ),

    ranking INTEGER
        CHECK (
            ranking IS NULL
            OR ranking >= 1
        ),

    rank_group INTEGER
        CHECK (
            rank_group IS NULL
            OR rank_group >= 1
        ),

    priority_label TEXT,

    calculated_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),

    UNIQUE (
        period_id,
        assessment_id
    ),

    UNIQUE (
        period_id,
        ranking,
        assessment_id
    ),

    FOREIGN KEY (period_id)
        REFERENCES assessment_periods(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    FOREIGN KEY (assessment_id)
        REFERENCES assessments(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_topsis_results_period
ON topsis_results(period_id);

CREATE INDEX IF NOT EXISTS idx_topsis_results_ranking
ON topsis_results(
    period_id,
    ranking
);

CREATE INDEX IF NOT EXISTS idx_topsis_results_rank_group
ON topsis_results(
    period_id,
    rank_group
);

-- ============================================================================
-- 16. TABEL TINDAK LANJ PEMELIHARAAN
-- ============================================================================

CREATE TABLE IF NOT EXISTS maintenance_actions (
    id INTEGER PRIMARY KEY,

    assessment_id INTEGER NOT NULL UNIQUE,

    recommendation TEXT NOT NULL
        CHECK (
            length(trim(recommendation)) > 0
        ),

    priority TEXT NOT NULL
        CHECK (
            priority IN (
                'immediate',
                'scheduled',
                'monitoring'
            )
        ),

    action_description TEXT,

    target_date TEXT,

    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (
            status IN (
                'pending',
                'in_progress',
                'completed'
            )
        ),

    completed_date TEXT,

    additional_note TEXT,

    work_order_reference TEXT,

    created_by INTEGER NOT NULL,

    performed_by INTEGER NOT NULL,

    created_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),

    CHECK (
        status <> 'completed'
        OR completed_date IS NOT NULL
    ),

    FOREIGN KEY (assessment_id)
        REFERENCES assessments(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,

    FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,

    FOREIGN KEY (performed_by)
        REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_maintenance_actions_status
ON maintenance_actions(status);

CREATE INDEX IF NOT EXISTS idx_maintenance_actions_priority
ON maintenance_actions(priority);

CREATE INDEX IF NOT EXISTS idx_maintenance_actions_performed_by
ON maintenance_actions(performed_by);

-- ============================================================================
-- 17. TABEL PENGUATAN AUDIT, HISTORI PERHITUNGAN, DAN LOGIN
-- ============================================================================

-- Penguatan sistem SPK PT CISAS - 09 Agustus 2026
-- Idempotent: aman dijalankan berulang kali.

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY,
    actor_id INTEGER,
    actor_name TEXT,
    actor_role TEXT,
    action TEXT NOT NULL CHECK (length(trim(action)) > 0),
    entity_type TEXT NOT NULL CHECK (length(trim(entity_type)) > 0),
    entity_id INTEGER,
    description TEXT,
    old_data TEXT,
    new_data TEXT,
    ip_address TEXT,
    user_agent TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),
    FOREIGN KEY (actor_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at ON audit_logs(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_logs_actor ON audit_logs(actor_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_logs_entity ON audit_logs(entity_type, entity_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_logs_action ON audit_logs(action, created_at DESC);

CREATE TABLE IF NOT EXISTS calculation_runs (
    id INTEGER PRIMARY KEY,
    period_id INTEGER NOT NULL,
    method TEXT NOT NULL CHECK (method IN ('ahp', 'topsis')),
    run_number INTEGER NOT NULL CHECK (run_number >= 1),
    calculated_by INTEGER NOT NULL,
    method_version TEXT NOT NULL,
    is_final INTEGER NOT NULL DEFAULT 1 CHECK (is_final IN (0, 1)),
    summary_json TEXT,
    input_snapshot_json TEXT,
    output_snapshot_json TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),
    UNIQUE (period_id, method, run_number),
    FOREIGN KEY (period_id) REFERENCES assessment_periods(id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (calculated_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_calculation_runs_one_final
ON calculation_runs(period_id, method)
WHERE is_final = 1;

CREATE INDEX IF NOT EXISTS idx_calculation_runs_period_method
ON calculation_runs(period_id, method, created_at DESC);

CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY,
    username TEXT NOT NULL COLLATE NOCASE,
    ip_address TEXT NOT NULL,
    was_successful INTEGER NOT NULL DEFAULT 0 CHECK (was_successful IN (0, 1)),
    attempted_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours'))
);

CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup
ON login_attempts(username, ip_address, attempted_at DESC);

CREATE INDEX IF NOT EXISTS idx_login_attempts_time
ON login_attempts(attempted_at DESC);


-- ============================================================================
-- 17. TRIGGER INTEGRITAS PERAN
-- ============================================================================

-- Pemeriksaan hanya dapat dibuat oleh Teknisi Maintenance.
CREATE TRIGGER IF NOT EXISTS trg_assessment_inspector_insert
BEFORE INSERT ON assessments
WHEN NOT EXISTS (
    SELECT 1
    FROM users
    WHERE id = NEW.inspector_id
      AND role = 'technician'
      AND is_active = 1
)
BEGIN
    SELECT RAISE(
        ABORT,
        'Pemeriksa harus merupakan Teknisi Maintenance aktif.'
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_assessment_inspector_update
BEFORE UPDATE OF inspector_id ON assessments
WHEN NOT EXISTS (
    SELECT 1
    FROM users
    WHERE id = NEW.inspector_id
      AND role = 'technician'
      AND is_active = 1
)
BEGIN
    SELECT RAISE(
        ABORT,
        'Pemeriksa harus merupakan Teknisi Maintenance aktif.'
    );
END;

-- Validasi hanya dapat dilakukan oleh Supervisor Maintenance aktif.
CREATE TRIGGER IF NOT EXISTS trg_assessment_validator_update
BEFORE UPDATE OF validated_by ON assessments
WHEN NEW.validated_by IS NOT NULL
 AND NOT EXISTS (
    SELECT 1
    FROM users
    WHERE id = NEW.validated_by
      AND role = 'supervisor'
      AND is_active = 1
)
BEGIN
    SELECT RAISE(
        ABORT,
        'Validator harus merupakan Supervisor Maintenance aktif.'
    );
END;

-- Periode hanya dapat dibuat oleh Administrator aktif.
CREATE TRIGGER IF NOT EXISTS trg_period_creator_insert
BEFORE INSERT ON assessment_periods
WHEN NOT EXISTS (
    SELECT 1
    FROM users
    WHERE id = NEW.created_by
      AND role = 'admin'
      AND is_active = 1
)
BEGIN
    SELECT RAISE(
        ABORT,
        'Periode harus dibuat oleh Administrator aktif.'
    );
END;

-- Perbandingan dan ringkasan AHP hanya berasal dari Supervisor aktif.
CREATE TRIGGER IF NOT EXISTS trg_ahp_assessor_insert
BEFORE INSERT ON ahp_comparisons
WHEN NOT EXISTS (
    SELECT 1
    FROM users
    WHERE id = NEW.assessor_id
      AND role = 'supervisor'
      AND is_active = 1
)
BEGIN
    SELECT RAISE(
        ABORT,
        'Responden AHP harus merupakan Supervisor Maintenance aktif.'
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_ahp_assessor_update
BEFORE UPDATE OF assessor_id ON ahp_comparisons
WHEN NOT EXISTS (
    SELECT 1
    FROM users
    WHERE id = NEW.assessor_id
      AND role = 'supervisor'
      AND is_active = 1
)
BEGIN
    SELECT RAISE(
        ABORT,
        'Responden AHP harus merupakan Supervisor Maintenance aktif.'
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_ahp_summary_assessor_insert
BEFORE INSERT ON ahp_summaries
WHEN NOT EXISTS (
    SELECT 1
    FROM users
    WHERE id = NEW.assessor_id
      AND role = 'supervisor'
      AND is_active = 1
)
BEGIN
    SELECT RAISE(
        ABORT,
        'Ringkasan AHP harus menggunakan Supervisor Maintenance aktif.'
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_ahp_summary_assessor_update
BEFORE UPDATE OF assessor_id ON ahp_summaries
WHEN NOT EXISTS (
    SELECT 1
    FROM users
    WHERE id = NEW.assessor_id
      AND role = 'supervisor'
      AND is_active = 1
)
BEGIN
    SELECT RAISE(
        ABORT,
        'Ringkasan AHP harus menggunakan Supervisor Maintenance aktif.'
    );
END;

-- Rekomendasi dibuat Supervisor dan ditugaskan kepada Teknisi aktif.
CREATE TRIGGER IF NOT EXISTS trg_maintenance_action_roles_insert
BEFORE INSERT ON maintenance_actions
WHEN NOT EXISTS (
    SELECT 1
    FROM users
    WHERE id = NEW.created_by
      AND role = 'supervisor'
      AND is_active = 1
)
OR NOT EXISTS (
    SELECT 1
    FROM users
    WHERE id = NEW.performed_by
      AND role = 'technician'
      AND is_active = 1
)
BEGIN
    SELECT RAISE(
        ABORT,
        'Rekomendasi harus dibuat Supervisor dan ditugaskan kepada Teknisi aktif.'
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_maintenance_action_roles_update
BEFORE UPDATE OF created_by, performed_by ON maintenance_actions
WHEN NOT EXISTS (
    SELECT 1
    FROM users
    WHERE id = NEW.created_by
      AND role = 'supervisor'
      AND is_active = 1
)
OR NOT EXISTS (
    SELECT 1
    FROM users
    WHERE id = NEW.performed_by
      AND role = 'technician'
      AND is_active = 1
)
BEGIN
    SELECT RAISE(
        ABORT,
        'Rekomendasi harus dibuat Supervisor dan ditugaskan kepada Teknisi aktif.'
    );
END;

-- ============================================================================
-- TRIGGER TRANSISI STATUS
-- ============================================================================

CREATE TRIGGER IF NOT EXISTS trg_period_status_transition
BEFORE UPDATE OF status ON assessment_periods
WHEN NOT (
    (OLD.status = 'draft' AND NEW.status IN ('draft', 'active'))
    OR
    (OLD.status = 'active' AND NEW.status IN ('active', 'completed'))
    OR
    (OLD.status = 'completed' AND NEW.status = 'completed')
)
BEGIN
    SELECT RAISE(
        ABORT,
        'Transisi status periode tidak diizinkan.'
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_assessment_status_transition
BEFORE UPDATE OF status ON assessments
WHEN NOT (
    (OLD.status = 'draft' AND NEW.status IN ('draft', 'completed'))
    OR
    (OLD.status = 'returned' AND NEW.status IN ('returned', 'draft', 'completed'))
    OR
    (OLD.status = 'completed' AND NEW.status IN ('completed', 'returned', 'validated'))
    OR
    (OLD.status = 'validated' AND NEW.status IN ('validated', 'processed'))
    OR
    (OLD.status = 'processed' AND NEW.status = 'processed')
)
BEGIN
    SELECT RAISE(
        ABORT,
        'Transisi status pemeriksaan tidak diizinkan.'
    );
END;

CREATE TRIGGER IF NOT EXISTS trg_action_status_transition
BEFORE UPDATE OF status ON maintenance_actions
WHEN NOT (
    (OLD.status = 'pending' AND NEW.status IN ('pending', 'in_progress', 'completed'))
    OR
    (OLD.status = 'in_progress' AND NEW.status IN ('in_progress', 'completed'))
    OR
    (OLD.status = 'completed' AND NEW.status = 'completed')
)
BEGIN
    SELECT RAISE(
        ABORT,
        'Transisi status tindak lanjut tidak diizinkan.'
    );
END;

COMMIT;

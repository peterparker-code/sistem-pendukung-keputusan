-- Batasi input perbandingan pada intensitas utama Saaty: 1, 3, 5, 7, 9 dan nilai kebalikannya.
-- Nilai 2, 4, 6, 8 tidak digunakan pada UI agar penilaian lebih ringkas.

PRAGMA foreign_keys = OFF;
BEGIN TRANSACTION;

DROP TABLE IF EXISTS ahp_comparisons_scale_new;

CREATE TABLE ahp_comparisons_scale_new (
    id INTEGER PRIMARY KEY,
    period_id INTEGER NOT NULL,
    criterion_left_id INTEGER NOT NULL,
    criterion_right_id INTEGER NOT NULL,
    comparison_value REAL NOT NULL CHECK (
        abs(comparison_value - 0.1111111111111111) < 0.000000001 OR
        abs(comparison_value - 0.14285714285714285) < 0.000000001 OR
        abs(comparison_value - 0.20) < 0.000000001 OR
        abs(comparison_value - 0.3333333333333333) < 0.000000001 OR
        abs(comparison_value - 1.00) < 0.000000001 OR
        abs(comparison_value - 3.00) < 0.000000001 OR
        abs(comparison_value - 5.00) < 0.000000001 OR
        abs(comparison_value - 7.00) < 0.000000001 OR
        abs(comparison_value - 9.00) < 0.000000001
    ),
    assessor_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now', '+7 hours')),
    CHECK (criterion_left_id < criterion_right_id),
    UNIQUE (period_id, criterion_left_id, criterion_right_id),
    FOREIGN KEY (period_id) REFERENCES assessment_periods(id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (criterion_left_id) REFERENCES criteria(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (criterion_right_id) REFERENCES criteria(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (assessor_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT
);

INSERT INTO ahp_comparisons_scale_new
(id, period_id, criterion_left_id, criterion_right_id, comparison_value, assessor_id, created_at, updated_at)
SELECT id, period_id, criterion_left_id, criterion_right_id, comparison_value, assessor_id, created_at, updated_at
FROM ahp_comparisons;

DROP TABLE ahp_comparisons;
ALTER TABLE ahp_comparisons_scale_new RENAME TO ahp_comparisons;

CREATE INDEX IF NOT EXISTS idx_ahp_comparisons_period ON ahp_comparisons(period_id);
CREATE INDEX IF NOT EXISTS idx_ahp_comparisons_assessor ON ahp_comparisons(assessor_id);

CREATE TRIGGER IF NOT EXISTS trg_ahp_assessor_insert
BEFORE INSERT ON ahp_comparisons
WHEN NOT EXISTS (
    SELECT 1 FROM users
    WHERE id = NEW.assessor_id AND role = 'supervisor' AND is_active = 1
)
BEGIN
    SELECT RAISE(ABORT, 'Responden AHP harus merupakan Supervisor Maintenance aktif.');
END;

CREATE TRIGGER IF NOT EXISTS trg_ahp_assessor_update
BEFORE UPDATE OF assessor_id ON ahp_comparisons
WHEN NOT EXISTS (
    SELECT 1 FROM users
    WHERE id = NEW.assessor_id AND role = 'supervisor' AND is_active = 1
)
BEGIN
    SELECT RAISE(ABORT, 'Responden AHP harus merupakan Supervisor Maintenance aktif.');
END;

COMMIT;
PRAGMA foreign_keys = ON;

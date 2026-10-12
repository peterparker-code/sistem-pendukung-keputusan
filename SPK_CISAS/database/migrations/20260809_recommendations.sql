-- Penguatan sistem SPK PT CISAS - 09 Agustus 2026
-- Idempotent: aman dijalankan berulang kali.

PRAGMA foreign_keys = ON;

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

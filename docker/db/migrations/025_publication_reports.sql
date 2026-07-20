CREATE TABLE IF NOT EXISTS publication_reports (
    id SERIAL PRIMARY KEY,
    reporter_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    target_type VARCHAR(24) NOT NULL,
    target_id INTEGER NOT NULL,
    reason_category VARCHAR(32) NOT NULL,
    details TEXT NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'open',
    resolved_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    resolved_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    resolution_note TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT publication_reports_target_type_check
        CHECK (target_type IN ('publication', 'comment', 'user')),
    CONSTRAINT publication_reports_reason_check
        CHECK (reason_category IN ('adult', 'violence', 'harassment', 'spam', 'copyright', 'other')),
    CONSTRAINT publication_reports_status_check
        CHECK (status IN ('open', 'resolved', 'dismissed')),
    CONSTRAINT publication_reports_details_check
        CHECK (char_length(details) <= 1000),
    UNIQUE (reporter_user_id, target_type, target_id)
);

DROP TRIGGER IF EXISTS trg_publication_reports_updated_at ON publication_reports;
CREATE TRIGGER trg_publication_reports_updated_at
BEFORE UPDATE ON publication_reports
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

CREATE INDEX IF NOT EXISTS idx_publication_reports_target
    ON publication_reports (target_type, target_id, status, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_publication_reports_status
    ON publication_reports (status, created_at DESC);

INSERT INTO social_feature_settings (key, enabled, value, description) VALUES
    ('reports.auto_adult_threshold', TRUE, '15', 'Liczba otwartych zgloszen publikacji wymagana do automatycznego oznaczenia +18.')
ON CONFLICT (key) DO NOTHING;

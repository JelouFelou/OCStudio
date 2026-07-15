CREATE TABLE IF NOT EXISTS admin_activity_logs (
    id SERIAL PRIMARY KEY,
    admin_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(80) NOT NULL,
    target_type VARCHAR(80),
    target_id INTEGER,
    details TEXT,
    ip_address VARCHAR(64),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_admin_activity_logs_created_at
    ON admin_activity_logs (created_at DESC);

CREATE INDEX IF NOT EXISTS idx_admin_activity_logs_admin_user_id
    ON admin_activity_logs (admin_user_id);

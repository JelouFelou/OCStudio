CREATE TABLE IF NOT EXISTS account_types (
    id SERIAL PRIMARY KEY,
    slug VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL,
    is_admin BOOLEAN NOT NULL DEFAULT FALSE,
    is_builtin BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    storage_quota_mb INTEGER NOT NULL DEFAULT 500 CHECK (storage_quota_mb >= 1 AND storage_quota_mb <= 1048576),
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO account_types (id, slug, name, is_admin, is_builtin, is_active, storage_quota_mb)
VALUES
    (0, 'user', 'User', FALSE, TRUE, TRUE, COALESCE(NULLIF((SELECT value FROM social_feature_settings WHERE key = 'storage.user_quota_mb'), '')::INTEGER, 500)),
    (1, 'admin', 'Admin', TRUE, TRUE, TRUE, COALESCE(NULLIF((SELECT value FROM social_feature_settings WHERE key = 'storage.admin_quota_mb'), '')::INTEGER, 500))
ON CONFLICT (id) DO UPDATE
SET slug = EXCLUDED.slug,
    name = EXCLUDED.name,
    is_admin = EXCLUDED.is_admin,
    is_builtin = TRUE,
    is_active = TRUE,
    updated_at = CURRENT_TIMESTAMP;

SELECT setval(pg_get_serial_sequence('account_types', 'id'), GREATEST((SELECT MAX(id) FROM account_types), 1));

CREATE TABLE IF NOT EXISTS account_type_feature_permissions (
    account_type_id INTEGER NOT NULL REFERENCES account_types(id) ON DELETE CASCADE,
    feature_key VARCHAR(80) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (account_type_id, feature_key)
);

INSERT INTO account_type_feature_permissions (account_type_id, feature_key, enabled)
SELECT at.id, sf.key, TRUE
FROM account_types at
CROSS JOIN social_feature_settings sf
WHERE sf.key NOT IN ('auth.login.enabled', 'auth.offline_user_id')
ON CONFLICT (account_type_id, feature_key) DO NOTHING;

CREATE INDEX IF NOT EXISTS idx_users_account_type
    ON users (account_type);

CREATE INDEX IF NOT EXISTS idx_account_type_permissions_feature
    ON account_type_feature_permissions (feature_key);

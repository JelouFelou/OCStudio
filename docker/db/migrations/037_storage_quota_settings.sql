INSERT INTO social_feature_settings (key, enabled, value, description) VALUES
    ('storage.user_quota_mb', TRUE, '500', 'Maksymalna suma prywatnych zdjec zwyklego uzytkownika w megabajtach.'),
    ('storage.admin_quota_mb', TRUE, '500', 'Maksymalna suma prywatnych zdjec administratora w megabajtach.')
ON CONFLICT (key) DO NOTHING;

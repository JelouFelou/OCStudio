CREATE TABLE IF NOT EXISTS site_effect_settings (
    key VARCHAR(64) PRIMARY KEY,
    value TEXT NOT NULL DEFAULT '',
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE worlds
    ADD COLUMN IF NOT EXISTS background_effect VARCHAR(32) NOT NULL DEFAULT 'none',
    ADD COLUMN IF NOT EXISTS effect_symbols TEXT DEFAULT '';

INSERT INTO site_effect_settings (key, value) VALUES
    ('effect_mode', 'auto'),
    ('effect_symbols', ''),
    ('effect_intensity', 'medium')
ON CONFLICT (key) DO NOTHING;

ALTER TABLE worlds
    ADD COLUMN IF NOT EXISTS effect_intensity VARCHAR(16) NOT NULL DEFAULT 'medium',
    ADD COLUMN IF NOT EXISTS effect_size VARCHAR(16) NOT NULL DEFAULT 'medium',
    ADD COLUMN IF NOT EXISTS effect_layer VARCHAR(16) NOT NULL DEFAULT 'under';

INSERT INTO site_effect_settings (key, value) VALUES
    ('effect_size', 'medium'),
    ('effect_layer', 'under')
ON CONFLICT (key) DO NOTHING;

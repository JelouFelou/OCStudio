CREATE TABLE IF NOT EXISTS site_effect_dates (
    id SERIAL PRIMARY KEY,
    date_value VARCHAR(10) NOT NULL,
    date_start VARCHAR(10),
    date_end VARCHAR(10),
    effect VARCHAR(32) NOT NULL,
    symbols TEXT DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO site_effect_settings (key, value)
VALUES ('effect_dates_seeded', '0')
ON CONFLICT (key) DO NOTHING;

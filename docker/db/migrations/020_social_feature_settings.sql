CREATE TABLE IF NOT EXISTS social_feature_settings (
    key VARCHAR(80) PRIMARY KEY,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    value TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO social_feature_settings (key, enabled, value, description) VALUES
    ('community.enabled', TRUE, '', 'Glowny przelacznik funkcji spolecznosciowych.'),
    ('publications.enabled', TRUE, '', 'Publikowanie i aktualizowanie publicznych tresci.'),
    ('comments.enabled', TRUE, '', 'Komentarze pod publikacjami.'),
    ('reactions.enabled', TRUE, '', 'Reakcje emoji pod publikacjami.'),
    ('follows.enabled', TRUE, '', 'Obserwowanie uzytkownikow.'),
    ('messages.enabled', TRUE, '', 'Wiadomosci prywatne.'),
    ('reports.enabled', TRUE, '', 'Zgloszenia tresci i uzytkownikow.'),
    ('copying.enabled', TRUE, '', 'Kopiowanie publicznych snapshotow.'),
    ('public_search.enabled', TRUE, '', 'Wyszukiwanie publicznych tresci innych uzytkownikow.'),
    ('new_publications.require_review', FALSE, '', 'Nowe publikacje wymagaja recznej akceptacji administracji.'),
    ('new_users.social_cooldown_hours', TRUE, '0', 'Liczba godzin ograniczen spolecznosciowych dla nowych kont.')
ON CONFLICT (key) DO NOTHING;

CREATE INDEX IF NOT EXISTS idx_social_feature_settings_updated_at
    ON social_feature_settings (updated_at DESC);

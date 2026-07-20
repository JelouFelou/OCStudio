INSERT INTO social_feature_settings (key, enabled, value, description) VALUES
    ('characters.enabled', TRUE, '', 'Tworzenie oraz edycja postaci, folderow i szablonow postaci.'),
    ('relations.enabled', TRUE, '', 'Tworzenie oraz edycja tablic relacji.'),
    ('stories.enabled', TRUE, '', 'Tworzenie oraz edycja historii.'),
    ('gallery.enabled', TRUE, '', 'Galeria oraz przesylanie zdjec z komputera lub biblioteki.'),
    ('auth.login.enabled', TRUE, '', 'Klasyczne logowanie uzytkownikow. Po wylaczeniu strona uzywa konta trybu offline.'),
    ('auth.offline_user_id', TRUE, '0', 'ID konta uzywanego automatycznie, gdy logowanie jest wylaczone.'),
    ('storage.user_quota_mb', TRUE, '500', 'Maksymalna suma prywatnych zdjec zwyklego uzytkownika w megabajtach.'),
    ('storage.admin_quota_mb', TRUE, '500', 'Maksymalna suma prywatnych zdjec administratora w megabajtach.')
ON CONFLICT (key) DO NOTHING;

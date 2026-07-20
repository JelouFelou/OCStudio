INSERT INTO social_feature_settings (key, enabled, value, description) VALUES
    ('backup.reminder.enabled', TRUE, '', 'Pokazuje adminowi przypomnienie, gdy od ostatniego backupu minie ustawiony czas.'),
    ('backup.reminder_interval_days', TRUE, '7', 'Liczba dni po ostatnim backupie, po ktorej panel admina przypomina o kopii.')
ON CONFLICT (key) DO NOTHING;

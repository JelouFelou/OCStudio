ALTER TABLE site_effect_dates
    ADD COLUMN IF NOT EXISTS date_start VARCHAR(10),
    ADD COLUMN IF NOT EXISTS date_end VARCHAR(10);

UPDATE site_effect_dates
SET date_start = date_value
WHERE date_start IS NULL;

UPDATE site_effect_dates
SET date_end = date_value
WHERE date_end IS NULL;

DELETE FROM site_effect_dates
WHERE effect = 'snow'
  AND date_value ~ '^12-[0-9]{2}$'
  AND date_value <> '12-01';

UPDATE site_effect_dates
SET date_value = '12-01',
    date_start = '12-01',
    date_end = '12-30'
WHERE effect = 'snow'
  AND date_value = '12-01';

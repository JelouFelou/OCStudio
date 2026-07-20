ALTER TABLE users
    ADD COLUMN IF NOT EXISTS locale VARCHAR(5) NOT NULL DEFAULT 'pl';

ALTER TABLE users
    DROP CONSTRAINT IF EXISTS users_locale_check;

ALTER TABLE users
    ADD CONSTRAINT users_locale_check CHECK (locale IN ('pl', 'en'));

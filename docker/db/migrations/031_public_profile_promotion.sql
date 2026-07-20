ALTER TABLE users
    ADD COLUMN IF NOT EXISTS promote_public_profile BOOLEAN NOT NULL DEFAULT TRUE;

CREATE INDEX IF NOT EXISTS idx_users_public_profile_promotion
    ON users (promote_public_profile, is_active, deletion_scheduled_at);

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS avatar_image_asset_id INTEGER DEFAULT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'users_avatar_image_asset_fkey'
    ) THEN
        ALTER TABLE users
            ADD CONSTRAINT users_avatar_image_asset_fkey
            FOREIGN KEY (avatar_image_asset_id) REFERENCES image_assets(id) ON DELETE SET NULL;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_users_avatar_image_asset
    ON users (avatar_image_asset_id);

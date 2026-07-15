ALTER TABLE image_assets
    ADD COLUMN IF NOT EXISTS visibility VARCHAR(20) NOT NULL DEFAULT 'normal';

UPDATE image_assets
SET visibility = 'normal'
WHERE visibility IS NULL OR visibility NOT IN ('normal', 'hidden', 'adult');

ALTER TABLE stories
    ADD COLUMN IF NOT EXISTS image_fit VARCHAR(20) NOT NULL DEFAULT 'cover',
    ADD COLUMN IF NOT EXISTS image_focus_x SMALLINT NOT NULL DEFAULT 50,
    ADD COLUMN IF NOT EXISTS image_focus_y SMALLINT NOT NULL DEFAULT 50,
    ADD COLUMN IF NOT EXISTS image_zoom NUMERIC(4,2) NOT NULL DEFAULT 1;

UPDATE stories
SET image_fit = 'cover'
WHERE image_fit IS NULL OR image_fit NOT IN ('cover', 'contain');

UPDATE stories
SET image_focus_x = 50
WHERE image_focus_x IS NULL;

UPDATE stories
SET image_focus_y = 50
WHERE image_focus_y IS NULL;

UPDATE stories
SET image_zoom = 1
WHERE image_zoom IS NULL;

ALTER TABLE stories
    ADD COLUMN IF NOT EXISTS card_image_fit VARCHAR(20) NOT NULL DEFAULT 'cover',
    ADD COLUMN IF NOT EXISTS card_image_focus_x SMALLINT NOT NULL DEFAULT 50,
    ADD COLUMN IF NOT EXISTS card_image_focus_y SMALLINT NOT NULL DEFAULT 50,
    ADD COLUMN IF NOT EXISTS card_image_zoom NUMERIC(4,2) NOT NULL DEFAULT 1;

UPDATE stories
SET card_image_fit = COALESCE(NULLIF(card_image_fit, ''), image_fit, 'cover')
WHERE card_image_fit IS NULL OR card_image_fit NOT IN ('cover', 'contain');

UPDATE stories
SET card_image_focus_x = COALESCE(card_image_focus_x, image_focus_x, 50)
WHERE card_image_focus_x IS NULL;

UPDATE stories
SET card_image_focus_y = COALESCE(card_image_focus_y, image_focus_y, 50)
WHERE card_image_focus_y IS NULL;

UPDATE stories
SET card_image_zoom = COALESCE(card_image_zoom, image_zoom, 1)
WHERE card_image_zoom IS NULL;

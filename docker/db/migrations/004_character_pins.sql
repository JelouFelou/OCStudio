ALTER TABLE characters
    ADD COLUMN IF NOT EXISTS is_pinned BOOLEAN NOT NULL DEFAULT FALSE;

UPDATE characters
SET is_pinned = FALSE
WHERE COALESCE(is_main_character, FALSE) = TRUE
  AND COALESCE(is_pinned, FALSE) = TRUE;

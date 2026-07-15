ALTER TABLE story_characters
    ADD COLUMN IF NOT EXISTS id_variant INTEGER DEFAULT NULL;

DO $$
DECLARE
    existing_constraint TEXT;
BEGIN
    SELECT con.conname INTO existing_constraint
    FROM pg_constraint con
    JOIN pg_class rel ON rel.oid = con.conrelid
    WHERE rel.relname = 'story_characters'
      AND con.contype = 'u'
      AND (
          SELECT array_agg(att.attname ORDER BY att.attnum)
          FROM unnest(con.conkey) key(attnum)
          JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = key.attnum
      )::TEXT[] = ARRAY['id_story', 'id_character'];

    IF existing_constraint IS NOT NULL THEN
        EXECUTE format('ALTER TABLE story_characters DROP CONSTRAINT %I', existing_constraint);
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'story_characters_id_variant_fkey'
    ) THEN
        ALTER TABLE story_characters
            ADD CONSTRAINT story_characters_id_variant_fkey
            FOREIGN KEY (id_variant) REFERENCES character_variants(id) ON DELETE SET NULL;
    END IF;
END $$;

DROP INDEX IF EXISTS story_characters_unique_default_variant;
DROP INDEX IF EXISTS story_characters_unique_variant;

CREATE UNIQUE INDEX IF NOT EXISTS story_characters_unique_default_variant
    ON story_characters (id_story, id_character)
    WHERE id_variant IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS story_characters_unique_variant
    ON story_characters (id_story, id_character, id_variant)
    WHERE id_variant IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_story_characters_variant ON story_characters(id_variant);

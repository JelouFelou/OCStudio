ALTER TABLE character_relations
    ADD COLUMN IF NOT EXISTS character_a_variant_id INTEGER DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS character_b_variant_id INTEGER DEFAULT NULL;

ALTER TABLE relation_board_characters
    ADD COLUMN IF NOT EXISTS id_variant INTEGER DEFAULT NULL;

ALTER TABLE relation_tree_nodes
    ADD COLUMN IF NOT EXISTS id_variant INTEGER DEFAULT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'character_relations_a_variant_fkey'
    ) THEN
        ALTER TABLE character_relations
            ADD CONSTRAINT character_relations_a_variant_fkey
            FOREIGN KEY (character_a_variant_id) REFERENCES character_variants(id) ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'character_relations_b_variant_fkey'
    ) THEN
        ALTER TABLE character_relations
            ADD CONSTRAINT character_relations_b_variant_fkey
            FOREIGN KEY (character_b_variant_id) REFERENCES character_variants(id) ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'relation_board_characters_variant_fkey'
    ) THEN
        ALTER TABLE relation_board_characters
            ADD CONSTRAINT relation_board_characters_variant_fkey
            FOREIGN KEY (id_variant) REFERENCES character_variants(id) ON DELETE SET NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'relation_tree_nodes_variant_fkey'
    ) THEN
        ALTER TABLE relation_tree_nodes
            ADD CONSTRAINT relation_tree_nodes_variant_fkey
            FOREIGN KEY (id_variant) REFERENCES character_variants(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
DECLARE
    constraint_name TEXT;
BEGIN
    SELECT con.conname INTO constraint_name
    FROM pg_constraint con
    JOIN pg_class rel ON rel.oid = con.conrelid
    WHERE rel.relname = 'relation_board_characters'
      AND con.contype = 'u'
      AND (
          SELECT array_agg(att.attname ORDER BY att.attnum)
          FROM unnest(con.conkey) key(attnum)
          JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = key.attnum
      )::TEXT[] = ARRAY['id_board', 'id_character'];

    IF constraint_name IS NOT NULL THEN
        EXECUTE format('ALTER TABLE relation_board_characters DROP CONSTRAINT %I', constraint_name);
    END IF;
END $$;

DO $$
DECLARE
    constraint_name TEXT;
BEGIN
    FOR constraint_name IN
        SELECT con.conname
        FROM pg_constraint con
        JOIN pg_class rel ON rel.oid = con.conrelid
        WHERE rel.relname = 'character_relations'
          AND con.contype = 'c'
    LOOP
        EXECUTE format('ALTER TABLE character_relations DROP CONSTRAINT %I', constraint_name);
    END LOOP;
END $$;

DROP INDEX IF EXISTS uniq_character_relations_pair;
DROP INDEX IF EXISTS uniq_relation_tree_nodes_scope;
DROP INDEX IF EXISTS uniq_relation_tree_nodes_board;
DROP INDEX IF EXISTS uniq_relation_board_characters_entity;

ALTER TABLE character_relations
    DROP CONSTRAINT IF EXISTS character_relations_entity_check;

ALTER TABLE character_relations
    ADD CONSTRAINT character_relations_entity_check
    CHECK (
        (character_a_id::TEXT || ':' || COALESCE(character_a_variant_id, 0)::TEXT)
        <>
        (character_b_id::TEXT || ':' || COALESCE(character_b_variant_id, 0)::TEXT)
    );

CREATE UNIQUE INDEX IF NOT EXISTS uniq_character_relations_pair
    ON character_relations (
        id_user,
        LEAST(
            character_a_id::TEXT || ':' || COALESCE(character_a_variant_id, 0)::TEXT,
            character_b_id::TEXT || ':' || COALESCE(character_b_variant_id, 0)::TEXT
        ),
        GREATEST(
            character_a_id::TEXT || ':' || COALESCE(character_a_variant_id, 0)::TEXT,
            character_b_id::TEXT || ':' || COALESCE(character_b_variant_id, 0)::TEXT
        )
    );

CREATE UNIQUE INDEX IF NOT EXISTS uniq_relation_board_characters_entity
    ON relation_board_characters (id_board, id_character, COALESCE(id_variant, 0));

CREATE UNIQUE INDEX IF NOT EXISTS uniq_relation_tree_nodes_scope
    ON relation_tree_nodes (id_user, COALESCE(id_world, 0), id_character, COALESCE(id_variant, 0))
    WHERE id_board IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uniq_relation_tree_nodes_board
    ON relation_tree_nodes (id_user, id_board, id_character, COALESCE(id_variant, 0))
    WHERE id_board IS NOT NULL;

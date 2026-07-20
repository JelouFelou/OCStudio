ALTER TABLE templates
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP;

DROP TRIGGER IF EXISTS trg_templates_updated_at ON templates;

CREATE TRIGGER trg_templates_updated_at
BEFORE UPDATE ON templates
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

ALTER TABLE publications
    ADD COLUMN IF NOT EXISTS template_id INTEGER REFERENCES templates(id) ON DELETE CASCADE;

DROP INDEX IF EXISTS uniq_publications_active_template;

ALTER TABLE publications
    DROP CONSTRAINT IF EXISTS publications_content_type_check,
    DROP CONSTRAINT IF EXISTS publications_single_source_check,
    DROP CONSTRAINT IF EXISTS publications_source_matches_type_check;

ALTER TABLE publications
    ADD CONSTRAINT publications_content_type_check
        CHECK (content_type IN ('character', 'story', 'image', 'relation_board', 'template')),
    ADD CONSTRAINT publications_single_source_check
        CHECK (
            (CASE WHEN character_id IS NULL THEN 0 ELSE 1 END) +
            (CASE WHEN story_id IS NULL THEN 0 ELSE 1 END) +
            (CASE WHEN image_asset_id IS NULL THEN 0 ELSE 1 END) +
            (CASE WHEN relation_board_id IS NULL THEN 0 ELSE 1 END) +
            (CASE WHEN template_id IS NULL THEN 0 ELSE 1 END) = 1
        ),
    ADD CONSTRAINT publications_source_matches_type_check
        CHECK (
            (content_type = 'character' AND character_id IS NOT NULL AND story_id IS NULL AND image_asset_id IS NULL AND relation_board_id IS NULL AND template_id IS NULL)
            OR (content_type = 'story' AND story_id IS NOT NULL AND character_id IS NULL AND image_asset_id IS NULL AND relation_board_id IS NULL AND selected_variant_id IS NULL AND template_id IS NULL)
            OR (content_type = 'image' AND image_asset_id IS NOT NULL AND character_id IS NULL AND story_id IS NULL AND relation_board_id IS NULL AND selected_variant_id IS NULL AND template_id IS NULL)
            OR (content_type = 'relation_board' AND relation_board_id IS NOT NULL AND character_id IS NULL AND story_id IS NULL AND image_asset_id IS NULL AND selected_variant_id IS NULL AND template_id IS NULL)
            OR (content_type = 'template' AND template_id IS NOT NULL AND character_id IS NULL AND story_id IS NULL AND image_asset_id IS NULL AND relation_board_id IS NULL AND selected_variant_id IS NULL)
        );

CREATE UNIQUE INDEX IF NOT EXISTS uniq_publications_active_template
    ON publications (owner_user_id, template_id)
    WHERE content_type = 'template' AND status = 'published';

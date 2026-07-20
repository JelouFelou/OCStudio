CREATE TABLE IF NOT EXISTS publications (
    id SERIAL PRIMARY KEY,
    public_id UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    owner_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    content_type VARCHAR(32) NOT NULL,
    character_id INTEGER REFERENCES characters(id) ON DELETE CASCADE,
    story_id INTEGER REFERENCES stories(id) ON DELETE CASCADE,
    image_asset_id INTEGER REFERENCES image_assets(id) ON DELETE CASCADE,
    relation_board_id INTEGER REFERENCES relation_boards(id) ON DELETE CASCADE,
    selected_variant_id INTEGER REFERENCES character_variants(id) ON DELETE SET NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'published',
    current_revision_id INTEGER,
    age_rating VARCHAR(16) NOT NULL DEFAULT 'general',
    age_rating_source VARCHAR(16) NOT NULL DEFAULT 'author',
    moderation_state VARCHAR(16) NOT NULL DEFAULT 'visible',
    moderation_reason TEXT NOT NULL DEFAULT '',
    moderated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    moderated_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    allow_copying BOOLEAN NOT NULL DEFAULT TRUE,
    published_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unpublished_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT publications_content_type_check
        CHECK (content_type IN ('character', 'story', 'image', 'relation_board')),
    CONSTRAINT publications_status_check
        CHECK (status IN ('published', 'unpublished')),
    CONSTRAINT publications_age_rating_check
        CHECK (age_rating IN ('general', 'adult')),
    CONSTRAINT publications_age_rating_source_check
        CHECK (age_rating_source IN ('author', 'automatic', 'admin')),
    CONSTRAINT publications_moderation_state_check
        CHECK (moderation_state IN ('visible', 'hidden')),
    CONSTRAINT publications_single_source_check
        CHECK (
            (CASE WHEN character_id IS NULL THEN 0 ELSE 1 END) +
            (CASE WHEN story_id IS NULL THEN 0 ELSE 1 END) +
            (CASE WHEN image_asset_id IS NULL THEN 0 ELSE 1 END) +
            (CASE WHEN relation_board_id IS NULL THEN 0 ELSE 1 END) = 1
        ),
    CONSTRAINT publications_source_matches_type_check
        CHECK (
            (content_type = 'character' AND character_id IS NOT NULL AND story_id IS NULL AND image_asset_id IS NULL AND relation_board_id IS NULL)
            OR (content_type = 'story' AND story_id IS NOT NULL AND character_id IS NULL AND image_asset_id IS NULL AND relation_board_id IS NULL AND selected_variant_id IS NULL)
            OR (content_type = 'image' AND image_asset_id IS NOT NULL AND character_id IS NULL AND story_id IS NULL AND relation_board_id IS NULL AND selected_variant_id IS NULL)
            OR (content_type = 'relation_board' AND relation_board_id IS NOT NULL AND character_id IS NULL AND story_id IS NULL AND image_asset_id IS NULL AND selected_variant_id IS NULL)
        )
);

CREATE TABLE IF NOT EXISTS publication_revisions (
    id SERIAL PRIMARY KEY,
    publication_id INTEGER NOT NULL REFERENCES publications(id) ON DELETE CASCADE,
    revision_number INTEGER NOT NULL,
    payload JSONB NOT NULL,
    search_text TEXT NOT NULL DEFAULT '',
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    change_reason VARCHAR(24) NOT NULL DEFAULT 'initial',
    CONSTRAINT publication_revisions_change_reason_check
        CHECK (change_reason IN ('initial', 'refresh', 'variant_switch', 'copy')),
    UNIQUE (publication_id, revision_number)
);

ALTER TABLE publications
    ADD CONSTRAINT publications_current_revision_fkey
    FOREIGN KEY (current_revision_id) REFERENCES publication_revisions(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS publication_media (
    id SERIAL PRIMARY KEY,
    revision_id INTEGER NOT NULL REFERENCES publication_revisions(id) ON DELETE CASCADE,
    image_asset_id INTEGER NOT NULL REFERENCES image_assets(id) ON DELETE CASCADE,
    role VARCHAR(32) NOT NULL DEFAULT 'body',
    order_number INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (revision_id, image_asset_id, role, order_number)
);

CREATE TABLE IF NOT EXISTS publication_filters (
    id SERIAL PRIMARY KEY,
    revision_id INTEGER NOT NULL REFERENCES publication_revisions(id) ON DELETE CASCADE,
    id_filter INTEGER NOT NULL REFERENCES filters(id) ON DELETE CASCADE,
    label_snapshot VARCHAR(100) NOT NULL DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (revision_id, id_filter)
);

CREATE UNIQUE INDEX IF NOT EXISTS uniq_publications_active_character
    ON publications (owner_user_id, character_id, COALESCE(selected_variant_id, 0))
    WHERE content_type = 'character' AND status = 'published';

CREATE UNIQUE INDEX IF NOT EXISTS uniq_publications_active_story
    ON publications (owner_user_id, story_id)
    WHERE content_type = 'story' AND status = 'published';

CREATE UNIQUE INDEX IF NOT EXISTS uniq_publications_active_image
    ON publications (owner_user_id, image_asset_id)
    WHERE content_type = 'image' AND status = 'published';

CREATE UNIQUE INDEX IF NOT EXISTS uniq_publications_active_relation_board
    ON publications (owner_user_id, relation_board_id)
    WHERE content_type = 'relation_board' AND status = 'published';

CREATE INDEX IF NOT EXISTS idx_publications_owner_type_status
    ON publications (owner_user_id, content_type, status, updated_at DESC);

CREATE INDEX IF NOT EXISTS idx_publications_public_visible
    ON publications (content_type, status, moderation_state, published_at DESC);

CREATE INDEX IF NOT EXISTS idx_publication_revisions_publication
    ON publication_revisions (publication_id, revision_number DESC);

CREATE INDEX IF NOT EXISTS idx_publication_revisions_search_text
    ON publication_revisions USING gin (to_tsvector('simple', search_text));

CREATE INDEX IF NOT EXISTS idx_publication_media_revision
    ON publication_media (revision_id);

CREATE INDEX IF NOT EXISTS idx_publication_filters_revision
    ON publication_filters (revision_id);

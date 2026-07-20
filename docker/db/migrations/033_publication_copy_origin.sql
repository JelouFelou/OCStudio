ALTER TABLE publications
    ADD COLUMN IF NOT EXISTS origin_publication_id INTEGER REFERENCES publications(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS origin_owner_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS origin_public_id UUID,
    ADD COLUMN IF NOT EXISTS origin_author_name VARCHAR(255) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS origin_title TEXT NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS origin_attribution_visible BOOLEAN NOT NULL DEFAULT TRUE;

CREATE INDEX IF NOT EXISTS idx_publications_origin_publication
    ON publications (origin_publication_id);

CREATE INDEX IF NOT EXISTS idx_publications_origin_owner
    ON publications (origin_owner_user_id);

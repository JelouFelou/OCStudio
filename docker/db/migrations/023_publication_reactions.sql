CREATE TABLE IF NOT EXISTS publication_reactions (
    id SERIAL PRIMARY KEY,
    publication_id INTEGER NOT NULL REFERENCES publications(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    reaction_type VARCHAR(16) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT publication_reactions_type_check
        CHECK (reaction_type IN ('like', 'love', 'laugh', 'wow', 'sad')),
    UNIQUE (publication_id, user_id)
);

DROP TRIGGER IF EXISTS trg_publication_reactions_updated_at ON publication_reactions;
CREATE TRIGGER trg_publication_reactions_updated_at
BEFORE UPDATE ON publication_reactions
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

CREATE INDEX IF NOT EXISTS idx_publication_reactions_publication
    ON publication_reactions (publication_id, reaction_type);

CREATE INDEX IF NOT EXISTS idx_publication_reactions_user
    ON publication_reactions (user_id, updated_at DESC);

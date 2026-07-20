CREATE TABLE IF NOT EXISTS publication_comments (
    id SERIAL PRIMARY KEY,
    publication_id INTEGER NOT NULL REFERENCES publications(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body TEXT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'visible',
    moderation_reason TEXT NOT NULL DEFAULT '',
    moderated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    moderated_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT publication_comments_status_check
        CHECK (status IN ('visible', 'hidden', 'deleted')),
    CONSTRAINT publication_comments_body_check
        CHECK (char_length(trim(body)) BETWEEN 2 AND 1000)
);

DROP TRIGGER IF EXISTS trg_publication_comments_updated_at ON publication_comments;
CREATE TRIGGER trg_publication_comments_updated_at
BEFORE UPDATE ON publication_comments
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

CREATE INDEX IF NOT EXISTS idx_publication_comments_publication
    ON publication_comments (publication_id, status, created_at ASC);

CREATE INDEX IF NOT EXISTS idx_publication_comments_user
    ON publication_comments (user_id, created_at DESC);

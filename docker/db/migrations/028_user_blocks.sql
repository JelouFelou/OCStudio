CREATE TABLE IF NOT EXISTS user_blocks (
    blocker_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    blocked_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    block_type VARCHAR(16) NOT NULL DEFAULT 'interaction',
    note TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (blocker_user_id, blocked_user_id),
    CONSTRAINT user_blocks_no_self_check CHECK (blocker_user_id <> blocked_user_id),
    CONSTRAINT user_blocks_type_check CHECK (block_type IN ('interaction', 'full'))
);

CREATE INDEX IF NOT EXISTS idx_user_blocks_blocked
    ON user_blocks (blocked_user_id, created_at DESC);

DROP TRIGGER IF EXISTS trg_user_blocks_updated_at ON user_blocks;
CREATE TRIGGER trg_user_blocks_updated_at
BEFORE UPDATE ON user_blocks
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

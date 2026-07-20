CREATE TABLE IF NOT EXISTS user_follows (
    follower_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    followed_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (follower_user_id, followed_user_id),
    CONSTRAINT user_follows_no_self_check CHECK (follower_user_id <> followed_user_id)
);

CREATE INDEX IF NOT EXISTS idx_user_follows_followed
    ON user_follows (followed_user_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_user_follows_follower_created
    ON user_follows (follower_user_id, created_at DESC);

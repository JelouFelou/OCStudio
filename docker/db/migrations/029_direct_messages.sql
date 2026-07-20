CREATE TABLE IF NOT EXISTS conversations (
    id SERIAL PRIMARY KEY,
    uuid UUID NOT NULL DEFAULT gen_random_uuid(),
    conversation_type VARCHAR(16) NOT NULL DEFAULT 'direct',
    created_by_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    peer_low_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    peer_high_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT conversations_uuid_unique UNIQUE (uuid),
    CONSTRAINT conversations_direct_type_check CHECK (conversation_type IN ('direct')),
    CONSTRAINT conversations_peer_order_check CHECK (peer_low_user_id < peer_high_user_id)
);

CREATE TABLE IF NOT EXISTS conversation_participants (
    conversation_id INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    last_read_message_id INTEGER DEFAULT NULL,
    muted_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    left_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (conversation_id, user_id)
);

CREATE TABLE IF NOT EXISTS messages (
    id SERIAL PRIMARY KEY,
    conversation_id INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    sender_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    deleted_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    CONSTRAINT messages_body_length_check CHECK (char_length(body) BETWEEN 1 AND 2000)
);

CREATE UNIQUE INDEX IF NOT EXISTS uniq_conversations_direct_pair
    ON conversations (peer_low_user_id, peer_high_user_id)
    WHERE conversation_type = 'direct';

CREATE INDEX IF NOT EXISTS idx_conversations_updated_at
    ON conversations (updated_at DESC);

CREATE INDEX IF NOT EXISTS idx_conversation_participants_user
    ON conversation_participants (user_id, left_at);

CREATE INDEX IF NOT EXISTS idx_messages_conversation_created
    ON messages (conversation_id, created_at DESC, id DESC);

CREATE TRIGGER trg_conversations_updated_at
BEFORE UPDATE ON conversations
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

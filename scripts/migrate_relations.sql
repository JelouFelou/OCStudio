CREATE TABLE IF NOT EXISTS relation_types (
    id SERIAL PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL,
    icon VARCHAR(80) NOT NULL,
    color_hex VARCHAR(7) NOT NULL,
    is_custom BOOLEAN NOT NULL DEFAULT FALSE,
    order_number INTEGER NOT NULL DEFAULT 0
);

INSERT INTO relation_types (code, name, icon, color_hex, is_custom, order_number) VALUES
    ('family', 'Rodzina', 'fa-solid fa-people-roof', '#4F8FD9', FALSE, 1),
    ('partners', 'Partnerzy', 'fa-solid fa-heart', '#E2557B', FALSE, 2),
    ('friends', 'Przyjaciele', 'fa-solid fa-handshake-angle', '#27AE60', FALSE, 3),
    ('allies', 'Sojusznicy', 'fa-solid fa-shield-halved', '#7B61FF', FALSE, 4),
    ('rivals', 'Rywale', 'fa-solid fa-bolt', '#F39C12', FALSE, 5),
    ('enemies', 'Wrogowie', 'fa-solid fa-skull-crossbones', '#E74C3C', FALSE, 6),
    ('custom', 'Custom', 'fa-solid fa-star', '#8E44AD', TRUE, 7)
ON CONFLICT (code) DO UPDATE SET
    name = EXCLUDED.name,
    icon = EXCLUDED.icon,
    color_hex = EXCLUDED.color_hex,
    is_custom = EXCLUDED.is_custom,
    order_number = EXCLUDED.order_number;

CREATE TABLE IF NOT EXISTS character_relations (
    id SERIAL PRIMARY KEY,
    id_user INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    character_a_id INTEGER NOT NULL REFERENCES characters(id) ON DELETE CASCADE,
    character_b_id INTEGER NOT NULL REFERENCES characters(id) ON DELETE CASCADE,
    relation_type_id INTEGER NOT NULL REFERENCES relation_types(id) ON DELETE RESTRICT,
    custom_name VARCHAR(100) DEFAULT NULL,
    note TEXT DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CHECK (character_a_id <> character_b_id)
);

CREATE UNIQUE INDEX IF NOT EXISTS uniq_character_relations_pair
    ON character_relations (id_user, LEAST(character_a_id, character_b_id), GREATEST(character_a_id, character_b_id));

CREATE TABLE IF NOT EXISTS relation_boards (
    id SERIAL PRIMARY KEY,
    id_user INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name VARCHAR(120) NOT NULL,
    description TEXT DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS relation_board_worlds (
    id SERIAL PRIMARY KEY,
    id_board INTEGER NOT NULL REFERENCES relation_boards(id) ON DELETE CASCADE,
    id_world INTEGER NOT NULL REFERENCES worlds(id) ON DELETE CASCADE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (id_board, id_world)
);

CREATE TABLE IF NOT EXISTS relation_board_characters (
    id SERIAL PRIMARY KEY,
    id_board INTEGER NOT NULL REFERENCES relation_boards(id) ON DELETE CASCADE,
    id_character INTEGER NOT NULL REFERENCES characters(id) ON DELETE CASCADE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (id_board, id_character)
);

CREATE TABLE IF NOT EXISTS relation_tree_nodes (
    id SERIAL PRIMARY KEY,
    id_user INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    id_world INTEGER DEFAULT NULL REFERENCES worlds(id) ON DELETE CASCADE,
    id_character INTEGER NOT NULL REFERENCES characters(id) ON DELETE CASCADE,
    position_x NUMERIC(10,2) NOT NULL DEFAULT 0,
    position_y NUMERIC(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE relation_tree_nodes
    ADD COLUMN IF NOT EXISTS id_board INTEGER DEFAULT NULL REFERENCES relation_boards(id) ON DELETE CASCADE;

DROP INDEX IF EXISTS uniq_relation_tree_nodes_scope;
CREATE UNIQUE INDEX IF NOT EXISTS uniq_relation_tree_nodes_scope
    ON relation_tree_nodes (id_user, COALESCE(id_world, 0), id_character)
    WHERE id_board IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uniq_relation_tree_nodes_board
    ON relation_tree_nodes (id_user, id_board, id_character)
    WHERE id_board IS NOT NULL;

CREATE TABLE IF NOT EXISTS relation_tree_rules (
    id SERIAL PRIMARY KEY,
    id_user INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    id_world INTEGER DEFAULT NULL REFERENCES worlds(id) ON DELETE CASCADE,
    excluded_world_id INTEGER NOT NULL REFERENCES worlds(id) ON DELETE CASCADE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS uniq_relation_tree_rules_scope
    ON relation_tree_rules (id_user, COALESCE(id_world, 0), excluded_world_id);

CREATE TABLE IF NOT EXISTS relation_tree_character_exceptions (
    id SERIAL PRIMARY KEY,
    id_user INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    id_world INTEGER DEFAULT NULL REFERENCES worlds(id) ON DELETE CASCADE,
    id_character INTEGER NOT NULL REFERENCES characters(id) ON DELETE CASCADE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS uniq_relation_tree_exceptions_scope
    ON relation_tree_character_exceptions (id_user, COALESCE(id_world, 0), id_character);

CREATE INDEX IF NOT EXISTS idx_character_relations_user ON character_relations(id_user);
CREATE INDEX IF NOT EXISTS idx_relation_tree_nodes_user_world ON relation_tree_nodes(id_user, id_world);
CREATE INDEX IF NOT EXISTS idx_relation_tree_rules_user_world ON relation_tree_rules(id_user, id_world);

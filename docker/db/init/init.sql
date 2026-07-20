DROP TABLE IF EXISTS user_blocked_filters CASCADE;
DROP TABLE IF EXISTS content_filters CASCADE;
DROP TABLE IF EXISTS image_asset_filters CASCADE;
DROP TABLE IF EXISTS filter_aliases CASCADE;
DROP TABLE IF EXISTS image_assets CASCADE;
DROP TABLE IF EXISTS relation_tree_character_exceptions CASCADE;
DROP TABLE IF EXISTS relation_tree_rules CASCADE;
DROP TABLE IF EXISTS relation_tree_nodes CASCADE;
DROP TABLE IF EXISTS relation_board_characters CASCADE;
DROP TABLE IF EXISTS relation_board_worlds CASCADE;
DROP TABLE IF EXISTS relation_boards CASCADE;
DROP TABLE IF EXISTS character_relations CASCADE;
DROP TABLE IF EXISTS relation_types CASCADE;
DROP TABLE IF EXISTS world_filters CASCADE;
DROP TABLE IF EXISTS character_filters CASCADE;
DROP TABLE IF EXISTS filters CASCADE;
DROP TABLE IF EXISTS character_statuses CASCADE;
DROP TABLE IF EXISTS character_field_values CASCADE;
DROP TABLE IF EXISTS character_variant_field_values CASCADE;
DROP TABLE IF EXISTS character_variants CASCADE;
DROP TABLE IF EXISTS characters CASCADE;
DROP TABLE IF EXISTS publication_comments CASCADE;
DROP TABLE IF EXISTS publication_reactions CASCADE;
DROP TABLE IF EXISTS publication_reports CASCADE;
DROP TABLE IF EXISTS notifications CASCADE;
DROP TABLE IF EXISTS messages CASCADE;
DROP TABLE IF EXISTS conversation_participants CASCADE;
DROP TABLE IF EXISTS conversations CASCADE;
DROP TABLE IF EXISTS user_follows CASCADE;
DROP TABLE IF EXISTS user_blocks CASCADE;
DROP TABLE IF EXISTS template_fields CASCADE;
DROP TABLE IF EXISTS templates CASCADE;
DROP TABLE IF EXISTS worlds CASCADE;
DROP TABLE IF EXISTS account_type_feature_permissions CASCADE;
DROP TABLE IF EXISTS account_types CASCADE;
DROP TABLE IF EXISTS users CASCADE;

CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    email VARCHAR(254) UNIQUE NOT NULL,
    password TEXT NOT NULL,
    firstname VARCHAR(50) NOT NULL,
    lastname VARCHAR(50) DEFAULT '',
    username VARCHAR(50),
    bio TEXT DEFAULT '',
    account_type INTEGER NOT NULL DEFAULT 0,
    banned_until TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    ban_reason TEXT DEFAULT NULL,
    deletion_scheduled_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    locale VARCHAR(5) NOT NULL DEFAULT 'pl' CHECK (locale IN ('pl', 'en')),
    promote_public_profile BOOLEAN NOT NULL DEFAULT TRUE,
    copy_attribution_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    avatar_image_asset_id INTEGER DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS account_types (
    id SERIAL PRIMARY KEY,
    slug VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL,
    is_admin BOOLEAN NOT NULL DEFAULT FALSE,
    is_builtin BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    storage_quota_mb INTEGER NOT NULL DEFAULT 500 CHECK (storage_quota_mb >= 1 AND storage_quota_mb <= 1048576),
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO account_types (id, slug, name, is_admin, is_builtin, is_active, storage_quota_mb)
VALUES
    (0, 'user', 'User', FALSE, TRUE, TRUE, 500),
    (1, 'admin', 'Admin', TRUE, TRUE, TRUE, 500)
ON CONFLICT (id) DO NOTHING;

SELECT setval(pg_get_serial_sequence('account_types', 'id'), GREATEST((SELECT MAX(id) FROM account_types), 1));

CREATE TABLE character_statuses (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    color_hex VARCHAR(7) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO character_statuses (name, color_hex) VALUES 
    ('Do zrobienia', '#E74C3C'),
    ('W trakcie', '#F39C12'),
    ('Gotowa', '#27AE60');

CREATE TABLE templates (
    id SERIAL PRIMARY KEY,
    public_id UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT '',
    date_calendar_type VARCHAR(24) NOT NULL DEFAULT 'real',
    date_settings TEXT NOT NULL DEFAULT '',
    current_world_date TEXT NOT NULL DEFAULT '',
    txt_export_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    txt_export_template TEXT NOT NULL DEFAULT '',
    is_hidden BOOLEAN NOT NULL DEFAULT FALSE,
    id_user INTEGER NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_user) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE template_fields (
    id SERIAL PRIMARY KEY,
    id_template INTEGER NOT NULL,
    label VARCHAR(100) NOT NULL,
    field_type VARCHAR(50) NOT NULL DEFAULT 'text',
    location VARCHAR(20) NOT NULL DEFAULT 'left',
    order_number INTEGER NOT NULL DEFAULT 0,
    placeholder TEXT DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_template) REFERENCES templates(id) ON DELETE CASCADE
);

CREATE TABLE worlds (
    id SERIAL PRIMARY KEY,
    public_id UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT '',
    image VARCHAR(255) DEFAULT 'default.jpg',
    icon_color VARCHAR(7) NOT NULL DEFAULT '#7B61FF',
    background_effect VARCHAR(32) NOT NULL DEFAULT 'none',
    effect_symbols TEXT DEFAULT '',
    effect_intensity VARCHAR(16) NOT NULL DEFAULT 'medium',
    effect_size VARCHAR(16) NOT NULL DEFAULT 'medium',
    effect_layer VARCHAR(16) NOT NULL DEFAULT 'under',
    is_hidden BOOLEAN NOT NULL DEFAULT FALSE,
    id_user INTEGER NOT NULL,
    parent_id INTEGER DEFAULT NULL,
    status_id INTEGER DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_user) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES worlds(id) ON DELETE CASCADE,
    FOREIGN KEY (status_id) REFERENCES character_statuses(id) ON DELETE SET NULL
);

  CREATE TABLE characters (
      id SERIAL PRIMARY KEY,
      public_id UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
      name VARCHAR(100) NOT NULL,
      intro TEXT DEFAULT '',
      description TEXT DEFAULT '',
      image VARCHAR(255) DEFAULT 'default.jpg',
      image_display_mode VARCHAR(20) NOT NULL DEFAULT 'square',
      image_fit VARCHAR(20) NOT NULL DEFAULT 'cover',
      image_focus_x SMALLINT NOT NULL DEFAULT 50,
      image_focus_y SMALLINT NOT NULL DEFAULT 50,
      image_zoom NUMERIC(4,2) NOT NULL DEFAULT 1,
      is_hidden BOOLEAN NOT NULL DEFAULT FALSE,
      is_main_character BOOLEAN NOT NULL DEFAULT FALSE,
      is_pinned BOOLEAN NOT NULL DEFAULT FALSE,
      id_user INTEGER NOT NULL,
    id_template INTEGER,
    id_world INTEGER DEFAULT NULL,
    status_id INTEGER DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_user) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (id_template) REFERENCES templates(id) ON DELETE SET NULL,
    FOREIGN KEY (id_world) REFERENCES worlds(id) ON DELETE SET NULL,
    FOREIGN KEY (status_id) REFERENCES character_statuses(id) ON DELETE SET NULL
);

CREATE TABLE character_field_values (
    id SERIAL PRIMARY KEY,
    id_character INTEGER NOT NULL,
    id_template_field INTEGER NOT NULL,
    value TEXT DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_character) REFERENCES characters(id) ON DELETE CASCADE,
    FOREIGN KEY (id_template_field) REFERENCES template_fields(id) ON DELETE CASCADE,
    UNIQUE (id_character, id_template_field)
);

CREATE TABLE character_variants (
    id SERIAL PRIMARY KEY,
    id_character INTEGER NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT '',
    image VARCHAR(255),
    is_adult BOOLEAN NOT NULL DEFAULT FALSE,
    is_hidden BOOLEAN NOT NULL DEFAULT FALSE,
    image_fit VARCHAR(20) NOT NULL DEFAULT 'cover',
    image_focus_x SMALLINT NOT NULL DEFAULT 50,
    image_focus_y SMALLINT NOT NULL DEFAULT 50,
    image_zoom NUMERIC(4,2) NOT NULL DEFAULT 1,
    order_number INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_character) REFERENCES characters(id) ON DELETE CASCADE
);

CREATE TABLE character_variant_field_values (
    id SERIAL PRIMARY KEY,
    id_variant INTEGER NOT NULL,
    id_template_field INTEGER NOT NULL,
    value TEXT DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_variant) REFERENCES character_variants(id) ON DELETE CASCADE,
    FOREIGN KEY (id_template_field) REFERENCES template_fields(id) ON DELETE CASCADE,
    UNIQUE (id_variant, id_template_field)
);

-- ═════════════════════════════════════════════════════════════════════
-- FILTRY I STATUSY
-- ═════════════════════════════════════════════════════════════════════

CREATE TABLE filters (
    id SERIAL PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    is_public BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    id_user INTEGER DEFAULT NULL
);

CREATE TABLE filter_aliases (
    id SERIAL PRIMARY KEY,
    id_filter INTEGER NOT NULL,
    alias VARCHAR(100) NOT NULL,
    language VARCHAR(8) NOT NULL DEFAULT 'en',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_filter) REFERENCES filters(id) ON DELETE CASCADE,
    UNIQUE (alias, language)
);

CREATE TABLE image_assets (
    id SERIAL PRIMARY KEY,
    id_user INTEGER NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) DEFAULT '',
    mime_type VARCHAR(80) NOT NULL,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    sha256 CHAR(64) NOT NULL,
    description TEXT DEFAULT '',
    visibility VARCHAR(20) NOT NULL DEFAULT 'normal',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_user) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE (id_user, filename),
    UNIQUE (id_user, sha256)
);

CREATE TABLE image_asset_filters (
    id SERIAL PRIMARY KEY,
    id_image INTEGER NOT NULL,
    id_filter INTEGER NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_image) REFERENCES image_assets(id) ON DELETE CASCADE,
    FOREIGN KEY (id_filter) REFERENCES filters(id) ON DELETE CASCADE,
    UNIQUE (id_image, id_filter)
);

CREATE TABLE content_filters (
    id SERIAL PRIMARY KEY,
    object_type VARCHAR(40) NOT NULL,
    object_id INTEGER NOT NULL,
    id_filter INTEGER NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_filter) REFERENCES filters(id) ON DELETE CASCADE,
    UNIQUE (object_type, object_id, id_filter)
);

CREATE TABLE character_filters (
    id SERIAL PRIMARY KEY,
    id_character INTEGER NOT NULL,
    id_filter INTEGER NOT NULL,
    is_inherited BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_character) REFERENCES characters(id) ON DELETE CASCADE,
    FOREIGN KEY (id_filter) REFERENCES filters(id) ON DELETE CASCADE,
    UNIQUE (id_character, id_filter)
);

CREATE TABLE world_filters (
    id SERIAL PRIMARY KEY,
    id_world INTEGER NOT NULL,
    id_filter INTEGER NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_world) REFERENCES worlds(id) ON DELETE CASCADE,
    FOREIGN KEY (id_filter) REFERENCES filters(id) ON DELETE CASCADE,
    UNIQUE (id_world, id_filter)
);

CREATE TABLE user_blocked_filters (
    id SERIAL PRIMARY KEY,
    id_user INTEGER NOT NULL,
    id_filter INTEGER NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_user) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (id_filter) REFERENCES filters(id) ON DELETE CASCADE,
    UNIQUE (id_user, id_filter)
);

CREATE TABLE relation_types (
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
    ('custom', 'Custom', 'fa-solid fa-star', '#8E44AD', TRUE, 7);

CREATE TABLE character_relations (
    id SERIAL PRIMARY KEY,
    id_user INTEGER NOT NULL,
    character_a_id INTEGER NOT NULL,
    character_a_variant_id INTEGER DEFAULT NULL,
    character_b_id INTEGER NOT NULL,
    character_b_variant_id INTEGER DEFAULT NULL,
    relation_type_id INTEGER NOT NULL,
    custom_name VARCHAR(100) DEFAULT NULL,
    custom_icon VARCHAR(16) DEFAULT NULL,
    custom_color_hex VARCHAR(7) DEFAULT NULL,
    is_nsfw BOOLEAN NOT NULL DEFAULT FALSE,
    note TEXT DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_user) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (character_a_id) REFERENCES characters(id) ON DELETE CASCADE,
    FOREIGN KEY (character_a_variant_id) REFERENCES character_variants(id) ON DELETE SET NULL,
    FOREIGN KEY (character_b_id) REFERENCES characters(id) ON DELETE CASCADE,
    FOREIGN KEY (character_b_variant_id) REFERENCES character_variants(id) ON DELETE SET NULL,
    FOREIGN KEY (relation_type_id) REFERENCES relation_types(id) ON DELETE RESTRICT,
    CHECK (
        (character_a_id::TEXT || ':' || COALESCE(character_a_variant_id, 0)::TEXT)
        <>
        (character_b_id::TEXT || ':' || COALESCE(character_b_variant_id, 0)::TEXT)
    )
);

CREATE UNIQUE INDEX uniq_character_relations_pair
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

CREATE TABLE relation_boards (
    id SERIAL PRIMARY KEY,
    public_id UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    id_user INTEGER NOT NULL,
    name VARCHAR(120) NOT NULL,
    description TEXT DEFAULT '',
    is_hidden BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_user) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE relation_board_worlds (
    id SERIAL PRIMARY KEY,
    id_board INTEGER NOT NULL,
    id_world INTEGER NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_board) REFERENCES relation_boards(id) ON DELETE CASCADE,
    FOREIGN KEY (id_world) REFERENCES worlds(id) ON DELETE CASCADE,
    UNIQUE (id_board, id_world)
);

CREATE TABLE relation_board_characters (
    id SERIAL PRIMARY KEY,
    id_board INTEGER NOT NULL,
    id_character INTEGER NOT NULL,
    id_variant INTEGER DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_board) REFERENCES relation_boards(id) ON DELETE CASCADE,
    FOREIGN KEY (id_character) REFERENCES characters(id) ON DELETE CASCADE,
    FOREIGN KEY (id_variant) REFERENCES character_variants(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX uniq_relation_board_characters_entity
    ON relation_board_characters (id_board, id_character, COALESCE(id_variant, 0));

CREATE TABLE relation_tree_nodes (
    id SERIAL PRIMARY KEY,
    id_user INTEGER NOT NULL,
    id_board INTEGER DEFAULT NULL,
    id_world INTEGER DEFAULT NULL,
    id_character INTEGER NOT NULL,
    id_variant INTEGER DEFAULT NULL,
    position_x NUMERIC(10,2) NOT NULL DEFAULT 0,
    position_y NUMERIC(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_user) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (id_board) REFERENCES relation_boards(id) ON DELETE CASCADE,
    FOREIGN KEY (id_world) REFERENCES worlds(id) ON DELETE CASCADE,
    FOREIGN KEY (id_character) REFERENCES characters(id) ON DELETE CASCADE,
    FOREIGN KEY (id_variant) REFERENCES character_variants(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX uniq_relation_tree_nodes_scope
    ON relation_tree_nodes (id_user, COALESCE(id_world, 0), id_character, COALESCE(id_variant, 0))
    WHERE id_board IS NULL;
CREATE UNIQUE INDEX uniq_relation_tree_nodes_board
    ON relation_tree_nodes (id_user, id_board, id_character, COALESCE(id_variant, 0))
    WHERE id_board IS NOT NULL;

CREATE TABLE relation_tree_rules (
    id SERIAL PRIMARY KEY,
    id_user INTEGER NOT NULL,
    id_world INTEGER DEFAULT NULL,
    excluded_world_id INTEGER NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_user) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (id_world) REFERENCES worlds(id) ON DELETE CASCADE,
    FOREIGN KEY (excluded_world_id) REFERENCES worlds(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX uniq_relation_tree_rules_scope
    ON relation_tree_rules (id_user, COALESCE(id_world, 0), excluded_world_id);

CREATE TABLE relation_tree_character_exceptions (
    id SERIAL PRIMARY KEY,
    id_user INTEGER NOT NULL,
    id_world INTEGER DEFAULT NULL,
    id_character INTEGER NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_user) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (id_world) REFERENCES worlds(id) ON DELETE CASCADE,
    FOREIGN KEY (id_character) REFERENCES characters(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX uniq_relation_tree_exceptions_scope
    ON relation_tree_character_exceptions (id_user, COALESCE(id_world, 0), id_character);

-- Domyslne globalne filtry i aliasy PL/EN
INSERT INTO filters (slug, name, label, is_active, is_public) VALUES
    ('woman', 'woman', 'kobieta', TRUE, TRUE),
    ('man', 'man', 'facet', TRUE, TRUE),
    ('young', 'young', 'mlody', TRUE, TRUE),
    ('adult', 'adult', 'dorosly', TRUE, TRUE),
    ('foot', 'foot', 'stopa', TRUE, TRUE),
    ('beach', 'beach', 'plaza', TRUE, TRUE),
    ('sea', 'sea', 'morze', TRUE, TRUE),
    ('nsfw', 'nsfw', 'NSFW', TRUE, TRUE),
    ('sfw', 'sfw', 'SFW', TRUE, TRUE),
    ('violence', 'violence', 'przemoc', TRUE, TRUE),
    ('magic', 'magic', 'magia', TRUE, TRUE),
    ('warrior', 'warrior', 'wojownik', TRUE, TRUE),
    ('elf', 'elf', 'elf', TRUE, TRUE),
    ('human', 'human', 'czlowiek', TRUE, TRUE),
    ('vampire', 'vampire', 'wampir', TRUE, TRUE)
ON CONFLICT (slug) DO NOTHING;

INSERT INTO filter_aliases (id_filter, alias, language)
SELECT filters.id, aliases.alias, aliases.language
FROM filters
JOIN (VALUES
    ('woman', 'woman', 'en'), ('woman', 'kobieta', 'pl'),
    ('man', 'man', 'en'), ('man', 'facet', 'pl'), ('man', 'mezczyzna', 'pl'), ('man', 'mężczyzna', 'pl'),
    ('young', 'young', 'en'), ('young', 'mlody', 'pl'), ('young', 'młody', 'pl'),
    ('adult', 'adult', 'en'), ('adult', 'dorosly', 'pl'), ('adult', 'dorosły', 'pl'),
    ('foot', 'foot', 'en'), ('foot', 'feet', 'en'), ('foot', 'stopa', 'pl'), ('foot', 'stopy', 'pl'),
    ('beach', 'beach', 'en'), ('beach', 'plaza', 'pl'), ('beach', 'plaża', 'pl'),
    ('sea', 'sea', 'en'), ('sea', 'morze', 'pl'),
    ('nsfw', 'nsfw', 'en'), ('sfw', 'sfw', 'en'),
    ('violence', 'violence', 'en'), ('violence', 'przemoc', 'pl'),
    ('magic', 'magic', 'en'), ('magic', 'magia', 'pl'),
    ('warrior', 'warrior', 'en'), ('warrior', 'wojownik', 'pl'),
    ('elf', 'elf', 'en'), ('human', 'human', 'en'), ('human', 'czlowiek', 'pl'), ('human', 'człowiek', 'pl'),
    ('vampire', 'vampire', 'en'), ('vampire', 'wampir', 'pl')
) AS aliases(slug, alias, language) ON aliases.slug = filters.slug
ON CONFLICT (alias, language) DO NOTHING;

CREATE INDEX idx_character_statuses_name ON character_statuses(name);
CREATE INDEX idx_filters_name ON filters(name);
CREATE INDEX idx_filter_aliases_alias ON filter_aliases(alias);
CREATE INDEX idx_image_assets_user ON image_assets(id_user);
ALTER TABLE users
    ADD CONSTRAINT users_avatar_image_asset_fkey
    FOREIGN KEY (avatar_image_asset_id) REFERENCES image_assets(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_users_avatar_image_asset ON users (avatar_image_asset_id);
CREATE INDEX idx_content_filters_object ON content_filters(object_type, object_id);
CREATE INDEX idx_character_filters_character ON character_filters(id_character);
CREATE INDEX idx_character_filters_filter ON character_filters(id_filter);
CREATE INDEX idx_world_filters_world ON world_filters(id_world);
CREATE INDEX idx_user_blocked_filters_user ON user_blocked_filters(id_user);
CREATE INDEX idx_character_relations_user ON character_relations(id_user);
CREATE INDEX idx_relation_tree_nodes_user_world ON relation_tree_nodes(id_user, id_world);
CREATE INDEX idx_relation_tree_rules_user_world ON relation_tree_rules(id_user, id_world);

-- ═════════════════════════════════════════════════════════════════════
-- HISTORIE (STORIES)
-- ═════════════════════════════════════════════════════════════════════

DROP TABLE IF EXISTS story_field_values CASCADE;
DROP TABLE IF EXISTS story_character_pseudonym_mapping CASCADE;
DROP TABLE IF EXISTS story_characters CASCADE;
DROP TABLE IF EXISTS story_fields CASCADE;
DROP TABLE IF EXISTS stories CASCADE;
DROP TABLE IF EXISTS story_folders CASCADE;

CREATE TABLE story_folders (
    id SERIAL PRIMARY KEY,
    id_user INTEGER NOT NULL,
    id_world INTEGER NOT NULL,
    name VARCHAR(100) NOT NULL,
    parent_id INTEGER DEFAULT NULL,
    order_number INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_user) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (id_world) REFERENCES worlds(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES story_folders(id) ON DELETE CASCADE
);

CREATE TABLE stories (
    id SERIAL PRIMARY KEY,
    public_id UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    id_user INTEGER NOT NULL,
    id_world INTEGER NOT NULL,
    id_folder INTEGER DEFAULT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT DEFAULT '',
    story_date TEXT NOT NULL DEFAULT '',
    image VARCHAR(255) DEFAULT 'default_story.jpg',
    image_fit VARCHAR(20) NOT NULL DEFAULT 'cover',
    image_focus_x SMALLINT NOT NULL DEFAULT 50,
    image_focus_y SMALLINT NOT NULL DEFAULT 50,
    image_zoom NUMERIC(4,2) NOT NULL DEFAULT 1,
    card_image_fit VARCHAR(20) NOT NULL DEFAULT 'cover',
    card_image_focus_x SMALLINT NOT NULL DEFAULT 50,
    card_image_focus_y SMALLINT NOT NULL DEFAULT 50,
    card_image_zoom NUMERIC(4,2) NOT NULL DEFAULT 1,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    order_number INTEGER NOT NULL DEFAULT 0,
    is_hidden BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_user) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (id_world) REFERENCES worlds(id) ON DELETE CASCADE,
    FOREIGN KEY (id_folder) REFERENCES story_folders(id) ON DELETE SET NULL
);

CREATE TABLE story_fields (
    id SERIAL PRIMARY KEY,
    id_story INTEGER NOT NULL,
    label VARCHAR(100) NOT NULL,
    field_type VARCHAR(50) NOT NULL DEFAULT 'text',
    order_number INTEGER NOT NULL DEFAULT 0,
    placeholder TEXT DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_story) REFERENCES stories(id) ON DELETE CASCADE
);

CREATE TABLE story_field_values (
    id SERIAL PRIMARY KEY,
    id_story INTEGER NOT NULL,
    id_story_field INTEGER NOT NULL,
    value TEXT DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_story) REFERENCES stories(id) ON DELETE CASCADE,
    FOREIGN KEY (id_story_field) REFERENCES story_fields(id) ON DELETE CASCADE,
    UNIQUE (id_story, id_story_field)
);

CREATE TABLE story_characters (
    id SERIAL PRIMARY KEY,
    id_story INTEGER NOT NULL,
    id_character INTEGER NOT NULL,
    id_variant INTEGER DEFAULT NULL,
    pseudonym_field_id INTEGER DEFAULT NULL,
    order_number INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_story) REFERENCES stories(id) ON DELETE CASCADE,
    FOREIGN KEY (id_character) REFERENCES characters(id) ON DELETE CASCADE,
    FOREIGN KEY (id_variant) REFERENCES character_variants(id) ON DELETE SET NULL,
    FOREIGN KEY (pseudonym_field_id) REFERENCES template_fields(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX story_characters_unique_default_variant
    ON story_characters (id_story, id_character)
    WHERE id_variant IS NULL;

CREATE UNIQUE INDEX story_characters_unique_variant
    ON story_characters (id_story, id_character, id_variant)
    WHERE id_variant IS NOT NULL;

CREATE TABLE story_character_pseudonym_mapping (
    id SERIAL PRIMARY KEY,
    id_story_character INTEGER NOT NULL,
    pseudonym VARCHAR(100) NOT NULL,
    is_excluded BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_story_character) REFERENCES story_characters(id) ON DELETE CASCADE
);

CREATE INDEX idx_story_folders_user ON story_folders(id_user);
CREATE INDEX idx_story_folders_world ON story_folders(id_world);
CREATE INDEX idx_story_folders_parent ON story_folders(parent_id);
CREATE INDEX idx_stories_user ON stories(id_user);
CREATE INDEX idx_stories_world ON stories(id_world);
CREATE INDEX idx_stories_folder ON stories(id_folder);
CREATE INDEX idx_story_fields_story ON story_fields(id_story);
CREATE INDEX idx_story_field_values_story ON story_field_values(id_story);
CREATE INDEX idx_story_characters_story ON story_characters(id_story);
CREATE INDEX idx_story_characters_character ON story_characters(id_character);
CREATE INDEX idx_story_character_pseudonyms_story_char ON story_character_pseudonym_mapping(id_story_character);

CREATE TABLE IF NOT EXISTS site_effect_settings (
    key VARCHAR(64) PRIMARY KEY,
    value TEXT NOT NULL DEFAULT '',
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS site_effect_dates (
    id SERIAL PRIMARY KEY,
    date_value VARCHAR(10) NOT NULL,
    date_start VARCHAR(10),
    date_end VARCHAR(10),
    effect VARCHAR(32) NOT NULL,
    symbols TEXT DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO site_effect_settings (key, value) VALUES
    ('effect_mode', 'auto'),
    ('effect_symbols', ''),
    ('effect_intensity', 'medium'),
    ('effect_size', 'medium'),
    ('effect_layer', 'under'),
    ('effect_dates_seeded', '0')
ON CONFLICT (key) DO NOTHING;

CREATE TABLE IF NOT EXISTS social_feature_settings (
    key VARCHAR(80) PRIMARY KEY,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    value TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO social_feature_settings (key, enabled, value, description) VALUES
    ('community.enabled', TRUE, '', 'Glowny przelacznik funkcji spolecznosciowych.'),
    ('characters.enabled', TRUE, '', 'Tworzenie oraz edycja postaci, folderow i szablonow postaci.'),
    ('relations.enabled', TRUE, '', 'Tworzenie oraz edycja tablic relacji.'),
    ('stories.enabled', TRUE, '', 'Tworzenie oraz edycja historii.'),
    ('gallery.enabled', TRUE, '', 'Galeria oraz przesylanie zdjec z komputera lub biblioteki.'),
    ('auth.login.enabled', TRUE, '', 'Klasyczne logowanie uzytkownikow. Po wylaczeniu strona uzywa konta trybu offline.'),
    ('auth.offline_user_id', TRUE, '0', 'ID konta uzywanego automatycznie, gdy logowanie jest wylaczone.'),
    ('publications.enabled', TRUE, '', 'Publikowanie i aktualizowanie publicznych tresci.'),
    ('comments.enabled', TRUE, '', 'Komentarze pod publikacjami.'),
    ('reactions.enabled', TRUE, '', 'Reakcje emoji pod publikacjami.'),
    ('follows.enabled', TRUE, '', 'Obserwowanie uzytkownikow.'),
    ('messages.enabled', TRUE, '', 'Wiadomosci prywatne.'),
    ('reports.enabled', TRUE, '', 'Zgloszenia tresci i uzytkownikow.'),
    ('copying.enabled', TRUE, '', 'Kopiowanie publicznych snapshotow.'),
    ('public_search.enabled', TRUE, '', 'Wyszukiwanie publicznych tresci innych uzytkownikow.'),
    ('new_publications.require_review', FALSE, '', 'Nowe publikacje wymagaja recznej akceptacji administracji.'),
    ('new_users.social_cooldown_hours', TRUE, '0', 'Liczba godzin ograniczen spolecznosciowych dla nowych kont.'),
    ('reports.auto_adult_threshold', TRUE, '15', 'Liczba otwartych zgloszen publikacji wymagana do automatycznego oznaczenia +18.'),
    ('storage.user_quota_mb', TRUE, '500', 'Maksymalna suma prywatnych zdjec zwyklego uzytkownika w megabajtach.'),
    ('storage.admin_quota_mb', TRUE, '500', 'Maksymalna suma prywatnych zdjec administratora w megabajtach.'),
    ('backup.reminder.enabled', TRUE, '', 'Pokazuje adminowi przypomnienie, gdy od ostatniego backupu minie ustawiony czas.'),
    ('backup.reminder_interval_days', TRUE, '7', 'Liczba dni po ostatnim backupie, po ktorej panel admina przypomina o kopii.')
ON CONFLICT (key) DO NOTHING;

CREATE TABLE IF NOT EXISTS account_type_feature_permissions (
    account_type_id INTEGER NOT NULL REFERENCES account_types(id) ON DELETE CASCADE,
    feature_key VARCHAR(80) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (account_type_id, feature_key)
);

INSERT INTO account_type_feature_permissions (account_type_id, feature_key, enabled)
SELECT at.id, sf.key, TRUE
FROM account_types at
CROSS JOIN social_feature_settings sf
WHERE sf.key NOT IN ('auth.login.enabled', 'auth.offline_user_id')
ON CONFLICT (account_type_id, feature_key) DO NOTHING;

CREATE INDEX IF NOT EXISTS idx_social_feature_settings_updated_at
    ON social_feature_settings (updated_at DESC);

CREATE INDEX IF NOT EXISTS idx_users_account_type
    ON users (account_type);

CREATE INDEX IF NOT EXISTS idx_account_type_permissions_feature
    ON account_type_feature_permissions (feature_key);

CREATE TABLE IF NOT EXISTS publications (
    id SERIAL PRIMARY KEY,
    public_id UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    owner_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    content_type VARCHAR(32) NOT NULL,
    character_id INTEGER REFERENCES characters(id) ON DELETE CASCADE,
    story_id INTEGER REFERENCES stories(id) ON DELETE CASCADE,
    image_asset_id INTEGER REFERENCES image_assets(id) ON DELETE CASCADE,
    relation_board_id INTEGER REFERENCES relation_boards(id) ON DELETE CASCADE,
    template_id INTEGER REFERENCES templates(id) ON DELETE CASCADE,
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
    origin_publication_id INTEGER REFERENCES publications(id) ON DELETE SET NULL,
    origin_owner_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    origin_public_id UUID,
    origin_author_name VARCHAR(255) NOT NULL DEFAULT '',
    origin_title TEXT NOT NULL DEFAULT '',
    origin_attribution_visible BOOLEAN NOT NULL DEFAULT TRUE,
    published_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unpublished_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT publications_content_type_check
        CHECK (content_type IN ('character', 'story', 'image', 'relation_board', 'template')),
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
            (CASE WHEN relation_board_id IS NULL THEN 0 ELSE 1 END) +
            (CASE WHEN template_id IS NULL THEN 0 ELSE 1 END) = 1
        ),
    CONSTRAINT publications_source_matches_type_check
        CHECK (
            (content_type = 'character' AND character_id IS NOT NULL AND story_id IS NULL AND image_asset_id IS NULL AND relation_board_id IS NULL AND template_id IS NULL)
            OR (content_type = 'story' AND story_id IS NOT NULL AND character_id IS NULL AND image_asset_id IS NULL AND relation_board_id IS NULL AND selected_variant_id IS NULL AND template_id IS NULL)
            OR (content_type = 'image' AND image_asset_id IS NOT NULL AND character_id IS NULL AND story_id IS NULL AND relation_board_id IS NULL AND selected_variant_id IS NULL AND template_id IS NULL)
            OR (content_type = 'relation_board' AND relation_board_id IS NOT NULL AND character_id IS NULL AND story_id IS NULL AND image_asset_id IS NULL AND selected_variant_id IS NULL AND template_id IS NULL)
            OR (content_type = 'template' AND template_id IS NOT NULL AND character_id IS NULL AND story_id IS NULL AND image_asset_id IS NULL AND relation_board_id IS NULL AND selected_variant_id IS NULL)
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

CREATE TABLE IF NOT EXISTS publication_reports (
    id SERIAL PRIMARY KEY,
    reporter_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    target_type VARCHAR(24) NOT NULL,
    target_id INTEGER NOT NULL,
    reason_category VARCHAR(32) NOT NULL,
    details TEXT NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'open',
    resolved_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    resolved_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    resolution_note TEXT NOT NULL DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT publication_reports_target_type_check
        CHECK (target_type IN ('publication', 'comment', 'user')),
    CONSTRAINT publication_reports_reason_check
        CHECK (reason_category IN ('adult', 'violence', 'harassment', 'spam', 'copyright', 'other')),
    CONSTRAINT publication_reports_status_check
        CHECK (status IN ('open', 'resolved', 'dismissed')),
    CONSTRAINT publication_reports_details_check
        CHECK (char_length(details) <= 1000),
    UNIQUE (reporter_user_id, target_type, target_id)
);

CREATE TABLE IF NOT EXISTS notifications (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    actor_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    type VARCHAR(40) NOT NULL,
    title VARCHAR(160) NOT NULL,
    body TEXT NOT NULL DEFAULT '',
    target_type VARCHAR(40) NOT NULL DEFAULT '',
    target_id INTEGER DEFAULT NULL,
    url TEXT NOT NULL DEFAULT '',
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    read_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

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

CREATE TABLE IF NOT EXISTS user_follows (
    follower_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    followed_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (follower_user_id, followed_user_id),
    CONSTRAINT user_follows_no_self_check CHECK (follower_user_id <> followed_user_id)
);

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

CREATE UNIQUE INDEX IF NOT EXISTS uniq_publications_active_template
    ON publications (owner_user_id, template_id)
    WHERE content_type = 'template' AND status = 'published';

CREATE INDEX IF NOT EXISTS idx_publications_owner_type_status
    ON publications (owner_user_id, content_type, status, updated_at DESC);

CREATE INDEX IF NOT EXISTS idx_publications_public_visible
    ON publications (content_type, status, moderation_state, published_at DESC);

CREATE INDEX IF NOT EXISTS idx_publications_origin_publication
    ON publications (origin_publication_id);

CREATE INDEX IF NOT EXISTS idx_publications_origin_owner
    ON publications (origin_owner_user_id);

CREATE INDEX IF NOT EXISTS idx_publication_revisions_publication
    ON publication_revisions (publication_id, revision_number DESC);

CREATE INDEX IF NOT EXISTS idx_publication_revisions_search_text
    ON publication_revisions USING gin (to_tsvector('simple', search_text));

CREATE INDEX IF NOT EXISTS idx_publication_media_revision
    ON publication_media (revision_id);

CREATE INDEX IF NOT EXISTS idx_publication_filters_revision
    ON publication_filters (revision_id);

CREATE INDEX IF NOT EXISTS idx_publication_reactions_publication
    ON publication_reactions (publication_id, reaction_type);

CREATE INDEX IF NOT EXISTS idx_publication_reactions_user
    ON publication_reactions (user_id, updated_at DESC);

CREATE INDEX IF NOT EXISTS idx_publication_comments_publication
    ON publication_comments (publication_id, status, created_at ASC);

CREATE INDEX IF NOT EXISTS idx_publication_comments_user
    ON publication_comments (user_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_publication_reports_target
    ON publication_reports (target_type, target_id, status, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_publication_reports_status
    ON publication_reports (status, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_notifications_user_read_created
    ON notifications (user_id, read_at, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_notifications_target
    ON notifications (target_type, target_id, created_at DESC);

CREATE UNIQUE INDEX IF NOT EXISTS uniq_conversations_direct_pair
    ON conversations (peer_low_user_id, peer_high_user_id)
    WHERE conversation_type = 'direct';

CREATE INDEX IF NOT EXISTS idx_conversations_updated_at
    ON conversations (updated_at DESC);

CREATE INDEX IF NOT EXISTS idx_conversation_participants_user
    ON conversation_participants (user_id, left_at);

CREATE INDEX IF NOT EXISTS idx_messages_conversation_created
    ON messages (conversation_id, created_at DESC, id DESC);

CREATE INDEX IF NOT EXISTS idx_user_follows_followed
    ON user_follows (followed_user_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_user_follows_follower_created
    ON user_follows (follower_user_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_user_blocks_blocked
    ON user_blocks (blocked_user_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_users_public_profile_promotion
    ON users (promote_public_profile, is_active, deletion_scheduled_at);

-- Widok podsumowujacy konto uzytkownika dla paneli administracyjnych i raportow.
CREATE OR REPLACE VIEW user_account_summary AS
SELECT
    u.id,
    u.email,
    u.username,
    u.account_type,
    u.created_at,
    u.banned_until,
    COUNT(DISTINCT c.id) AS character_count,
    COUNT(DISTINCT t.id) AS template_count,
    COUNT(DISTINCT w.id) AS world_count
FROM users u
LEFT JOIN characters c ON c.id_user = u.id
LEFT JOIN templates t ON t.id_user = u.id
LEFT JOIN worlds w ON w.id_user = u.id
GROUP BY u.id, u.email, u.username, u.account_type, u.created_at, u.banned_until;

-- Funkcja sprawdzajaca, czy konto jest aktualnie zablokowane.
CREATE OR REPLACE FUNCTION is_account_currently_banned(banned_until TIMESTAMP WITH TIME ZONE)
RETURNS BOOLEAN
LANGUAGE plpgsql
AS $$
BEGIN
    RETURN banned_until IS NOT NULL AND banned_until > NOW();
END;
$$;

-- Funkcja triggera uzupelniajaca pusta nazwe uzytkownika na podstawie emaila.
CREATE OR REPLACE FUNCTION set_default_username_from_email()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.username IS NULL OR BTRIM(NEW.username) = '' THEN
        NEW.username := SPLIT_PART(NEW.email, '@', 1);
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_set_default_username_from_email ON users;

CREATE TRIGGER trg_set_default_username_from_email
BEFORE INSERT OR UPDATE OF email, username ON users
FOR EACH ROW
EXECUTE FUNCTION set_default_username_from_email();

CREATE OR REPLACE FUNCTION set_updated_at_column()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at := CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_characters_updated_at
BEFORE UPDATE ON characters
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

CREATE TRIGGER trg_character_variants_updated_at
BEFORE UPDATE ON character_variants
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

CREATE TRIGGER trg_character_field_values_updated_at
BEFORE UPDATE ON character_field_values
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

CREATE TRIGGER trg_character_variant_field_values_updated_at
BEFORE UPDATE ON character_variant_field_values
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

CREATE TRIGGER trg_publication_reactions_updated_at
BEFORE UPDATE ON publication_reactions
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

CREATE TRIGGER trg_publication_comments_updated_at
BEFORE UPDATE ON publication_comments
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

CREATE TRIGGER trg_publication_reports_updated_at
BEFORE UPDATE ON publication_reports
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

CREATE TRIGGER trg_user_blocks_updated_at
BEFORE UPDATE ON user_blocks
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

CREATE TRIGGER trg_conversations_updated_at
BEFORE UPDATE ON conversations
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

CREATE TRIGGER trg_templates_updated_at
BEFORE UPDATE ON templates
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

CREATE OR REPLACE FUNCTION touch_character_from_field_value()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    target_character_id INTEGER;
BEGIN
    target_character_id := COALESCE(NEW.id_character, OLD.id_character);
    IF target_character_id IS NOT NULL THEN
        UPDATE characters
        SET updated_at = CURRENT_TIMESTAMP
        WHERE id = target_character_id;
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$;

CREATE TRIGGER trg_touch_character_from_field_value
AFTER INSERT OR UPDATE OR DELETE ON character_field_values
FOR EACH ROW
EXECUTE FUNCTION touch_character_from_field_value();

CREATE OR REPLACE FUNCTION touch_variant_from_field_value()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    target_variant_id INTEGER;
BEGIN
    target_variant_id := COALESCE(NEW.id_variant, OLD.id_variant);
    IF target_variant_id IS NOT NULL THEN
        UPDATE character_variants
        SET updated_at = CURRENT_TIMESTAMP
        WHERE id = target_variant_id;
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$;

CREATE TRIGGER trg_touch_variant_from_field_value
AFTER INSERT OR UPDATE OR DELETE ON character_variant_field_values
FOR EACH ROW
EXECUTE FUNCTION touch_variant_from_field_value();

CREATE OR REPLACE FUNCTION touch_character_from_content_filter()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    target_type TEXT;
    target_id INTEGER;
    target_character_id INTEGER;
BEGIN
    target_type := COALESCE(NEW.object_type, OLD.object_type);
    target_id := COALESCE(NEW.object_id, OLD.object_id);

    IF target_type = 'character' THEN
        target_character_id := target_id;
    ELSIF target_type = 'character_variant' THEN
        UPDATE character_variants
        SET updated_at = CURRENT_TIMESTAMP
        WHERE id = target_id;
    END IF;

    IF target_character_id IS NOT NULL THEN
        UPDATE characters
        SET updated_at = CURRENT_TIMESTAMP
        WHERE id = target_character_id;
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$;

CREATE TRIGGER trg_touch_character_from_content_filter
AFTER INSERT OR UPDATE OR DELETE ON content_filters
FOR EACH ROW
EXECUTE FUNCTION touch_character_from_content_filter();

-- Migration baseline for databases created from this init script.
CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(255) PRIMARY KEY,
    applied_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO schema_migrations (version) VALUES
    ('001_stories.sql'),
    ('002_story_cover_framing.sql'),
    ('003_story_card_cover_framing.sql'),
    ('004_character_pins.sql'),
    ('005_image_asset_visibility.sql'),
    ('006_page_effects.sql'),
    ('007_site_effect_dates.sql'),
    ('008_site_effect_date_ranges.sql'),
    ('009_effect_controls.sql'),
    ('010_template_date_settings.sql'),
    ('011_story_dates.sql'),
    ('012_story_timeline_branches.sql'),
    ('013_story_timeline_positions.sql'),
    ('014_admin_activity_logs.sql'),
    ('015_character_variant_visibility.sql'),
    ('016_character_variant_identity.sql'),
    ('017_story_character_variants.sql'),
    ('018_relation_variants.sql'),
    ('019_user_locale.sql'),
    ('020_social_feature_settings.sql'),
    ('021_publications.sql'),
    ('022_character_publication_freshness.sql'),
    ('023_publication_reactions.sql'),
    ('024_publication_comments.sql'),
    ('025_publication_reports.sql'),
    ('026_notifications.sql'),
    ('027_user_follows.sql'),
    ('028_user_blocks.sql'),
    ('029_direct_messages.sql'),
    ('030_template_publications.sql'),
    ('031_public_profile_promotion.sql'),
    ('032_user_profile_avatar.sql'),
    ('033_publication_copy_origin.sql'),
    ('034_user_copy_attribution.sql'),
    ('035_site_feature_controls.sql'),
    ('036_filter_alias_language_scope.sql'),
    ('037_storage_quota_settings.sql'),
    ('038_dynamic_account_types.sql'),
    ('039_template_txt_export_format.sql'),
    ('040_backup_reminder_settings.sql')
ON CONFLICT (version) DO NOTHING;

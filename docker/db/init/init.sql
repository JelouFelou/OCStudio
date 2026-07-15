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
DROP TABLE IF EXISTS template_fields CASCADE;
DROP TABLE IF EXISTS templates CASCADE;
DROP TABLE IF EXISTS worlds CASCADE;
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
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE
);

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
    FOREIGN KEY (id_character) REFERENCES characters(id) ON DELETE CASCADE
);

CREATE TABLE character_variant_field_values (
    id SERIAL PRIMARY KEY,
    id_variant INTEGER NOT NULL,
    id_template_field INTEGER NOT NULL,
    value TEXT DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_variant) REFERENCES character_variants(id) ON DELETE CASCADE,
    FOREIGN KEY (id_template_field) REFERENCES template_fields(id) ON DELETE CASCADE,
    UNIQUE (id_variant, id_template_field)
);

INSERT INTO users (email, password, firstname, lastname, bio)
VALUES (
    'demo@example.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'Demo',
    'User',
    'Konto testowe'
);

INSERT INTO users (email, password, firstname, lastname, username, bio)
VALUES (
    'user@example.com',
    '$2y$10$cNY.2LCZirhKbjxnAfGJYOftY3Kk8vWJtPOr1YreYIfpyozXhVK1y',
    'Test',
    'User',
    'testuser',
    'Konto do testow Postmana'
);

INSERT INTO templates (name, description, id_user)
VALUES (
    'Fantasy Character',
    'Podstawowy szablon postaci fantasy.',
    1
);

INSERT INTO template_fields (id_template, label, field_type, location, order_number, placeholder)
VALUES
    (1, 'Rasa', 'text', 'left', 0, ''),
    (1, 'Klasa', 'text', 'left', 1, ''),
    (1, 'Charakter', 'textarea', 'right', 0, ''),
    (1, 'Umiejetnosci', 'list', 'right', 1, '');

INSERT INTO characters (name, intro, description, image, id_user, id_template)
VALUES
    ('Alysia Thorne', 'Elfia lowczyni z mrocznych lasow.', 'Elfia lowczyni.', 'char1.png', 1, 1),
    ('Kaelen Frost', 'Mag lodu wygnany ze swojej wiezy.', 'Mag lodu.', 'char2.png', 1, 1);

INSERT INTO character_field_values (id_character, id_template_field, value)
VALUES
    (1, 1, 'Elf'),
    (1, 2, 'Lowczyni'),
    (1, 3, 'Spokojna, uwazna i nieufna wobec obcych.'),
    (2, 1, 'Czlowiek'),
    (2, 2, 'Mag lodu'),
    (2, 3, 'Ambitny i zdystansowany.');

INSERT INTO character_variants (id_character, name, image, order_number)
VALUES
    (2, 'Forma wilkolaka', NULL, 0);

INSERT INTO character_variant_field_values (id_variant, id_template_field, value)
VALUES
    (1, 1, 'Wilkolak'),
    (1, 2, 'Bestia lodu');

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
    alias VARCHAR(100) NOT NULL UNIQUE,
    language VARCHAR(8) NOT NULL DEFAULT 'en',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_filter) REFERENCES filters(id) ON DELETE CASCADE
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
ON CONFLICT (alias) DO NOTHING;

CREATE INDEX idx_character_statuses_name ON character_statuses(name);
CREATE INDEX idx_filters_name ON filters(name);
CREATE INDEX idx_filter_aliases_alias ON filter_aliases(alias);
CREATE INDEX idx_image_assets_user ON image_assets(id_user);
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

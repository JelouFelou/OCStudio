CREATE TABLE IF NOT EXISTS story_folders (
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

CREATE TABLE IF NOT EXISTS stories (
    id SERIAL PRIMARY KEY,
    public_id UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
    id_user INTEGER NOT NULL,
    id_world INTEGER NOT NULL,
    id_folder INTEGER DEFAULT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT DEFAULT '',
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

CREATE TABLE IF NOT EXISTS story_fields (
    id SERIAL PRIMARY KEY,
    id_story INTEGER NOT NULL,
    label VARCHAR(100) NOT NULL,
    field_type VARCHAR(50) NOT NULL DEFAULT 'text',
    order_number INTEGER NOT NULL DEFAULT 0,
    placeholder TEXT DEFAULT '',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_story) REFERENCES stories(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS story_field_values (
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

CREATE TABLE IF NOT EXISTS story_characters (
    id SERIAL PRIMARY KEY,
    id_story INTEGER NOT NULL,
    id_character INTEGER NOT NULL,
    pseudonym_field_id INTEGER DEFAULT NULL,
    order_number INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_story) REFERENCES stories(id) ON DELETE CASCADE,
    FOREIGN KEY (id_character) REFERENCES characters(id) ON DELETE CASCADE,
    FOREIGN KEY (pseudonym_field_id) REFERENCES template_fields(id) ON DELETE SET NULL,
    UNIQUE (id_story, id_character)
);

CREATE TABLE IF NOT EXISTS story_character_pseudonym_mapping (
    id SERIAL PRIMARY KEY,
    id_story_character INTEGER NOT NULL,
    pseudonym VARCHAR(100) NOT NULL,
    is_excluded BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_story_character) REFERENCES story_characters(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_story_folders_user ON story_folders(id_user);
CREATE INDEX IF NOT EXISTS idx_story_folders_world ON story_folders(id_world);
CREATE INDEX IF NOT EXISTS idx_story_folders_parent ON story_folders(parent_id);
CREATE INDEX IF NOT EXISTS idx_stories_user ON stories(id_user);
CREATE INDEX IF NOT EXISTS idx_stories_world ON stories(id_world);
CREATE INDEX IF NOT EXISTS idx_stories_folder ON stories(id_folder);
CREATE INDEX IF NOT EXISTS idx_story_fields_story ON story_fields(id_story);
CREATE INDEX IF NOT EXISTS idx_story_field_values_story ON story_field_values(id_story);
CREATE INDEX IF NOT EXISTS idx_story_characters_story ON story_characters(id_story);
CREATE INDEX IF NOT EXISTS idx_story_characters_character ON story_characters(id_character);
CREATE INDEX IF NOT EXISTS idx_story_character_pseudonyms_story_char ON story_character_pseudonym_mapping(id_story_character);

ALTER TABLE characters
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE character_variants
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE character_field_values
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE character_variant_field_values
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP;

UPDATE characters
SET updated_at = COALESCE(updated_at, created_at, CURRENT_TIMESTAMP);

UPDATE character_variants
SET updated_at = COALESCE(updated_at, created_at, CURRENT_TIMESTAMP);

UPDATE character_field_values
SET updated_at = COALESCE(updated_at, created_at, CURRENT_TIMESTAMP);

UPDATE character_variant_field_values
SET updated_at = COALESCE(updated_at, created_at, CURRENT_TIMESTAMP);

CREATE OR REPLACE FUNCTION set_updated_at_column()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at := CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_characters_updated_at ON characters;
CREATE TRIGGER trg_characters_updated_at
BEFORE UPDATE ON characters
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

DROP TRIGGER IF EXISTS trg_character_variants_updated_at ON character_variants;
CREATE TRIGGER trg_character_variants_updated_at
BEFORE UPDATE ON character_variants
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

DROP TRIGGER IF EXISTS trg_character_field_values_updated_at ON character_field_values;
CREATE TRIGGER trg_character_field_values_updated_at
BEFORE UPDATE ON character_field_values
FOR EACH ROW
EXECUTE FUNCTION set_updated_at_column();

DROP TRIGGER IF EXISTS trg_character_variant_field_values_updated_at ON character_variant_field_values;
CREATE TRIGGER trg_character_variant_field_values_updated_at
BEFORE UPDATE ON character_variant_field_values
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

DROP TRIGGER IF EXISTS trg_touch_character_from_field_value ON character_field_values;
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

DROP TRIGGER IF EXISTS trg_touch_variant_from_field_value ON character_variant_field_values;
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

DROP TRIGGER IF EXISTS trg_touch_character_from_content_filter ON content_filters;
CREATE TRIGGER trg_touch_character_from_content_filter
AFTER INSERT OR UPDATE OR DELETE ON content_filters
FOR EACH ROW
EXECUTE FUNCTION touch_character_from_content_filter();

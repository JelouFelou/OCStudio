ALTER TABLE filter_aliases
    DROP CONSTRAINT IF EXISTS filter_aliases_alias_key;

ALTER TABLE filter_aliases
    ADD CONSTRAINT filter_aliases_alias_language_key UNIQUE (alias, language);

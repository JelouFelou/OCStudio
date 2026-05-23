<?php

require_once 'Repository.php';
require_once __DIR__ . '/../models/Filter.php';

class FilterRepository extends Repository
{
    /**
     * Zwraca filtry dostępne dla użytkownika:
     * - publiczne (is_public = TRUE)
     * - własne użytkownika (id_user = $userId)
     */
    public function getAvailableFilters(int $userId): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT * FROM filters WHERE is_public = TRUE OR id_user = :userId ORDER BY name ASC'
        );
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->hydrate($row);
        }

        return $result;
    }

    /**
     * Zwraca filtry przypisane do konkretnej postaci
     * (nie uwzględnia dziedziczonych z folderu)
     */
    public function getCharacterDirectFilters(int $characterId): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT f.* FROM filters f
            JOIN character_filters cf ON f.id = cf.id_filter
            WHERE cf.id_character = :characterId AND cf.is_inherited = FALSE
            ORDER BY f.name ASC'
        );
        $stmt->bindParam(':characterId', $characterId, PDO::PARAM_INT);
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->hydrate($row);
        }

        return $result;
    }

    /**
     * Zwraca dziedziczone filtry postaci (z folderu)
     */
    public function getCharacterInheritedFilters(int $characterId): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT f.* FROM filters f
            JOIN character_filters cf ON f.id = cf.id_filter
            WHERE cf.id_character = :characterId AND cf.is_inherited = TRUE
            ORDER BY f.name ASC'
        );
        $stmt->bindParam(':characterId', $characterId, PDO::PARAM_INT);
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->hydrate($row);
        }

        return $result;
    }

    /**
     * Zwraca wszystkie filtry postaci (bezpośrednie + dziedziczone)
     */
    public function getAllCharacterFilters(int $characterId): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT f.* FROM filters f
            JOIN character_filters cf ON f.id = cf.id_filter
            WHERE cf.id_character = :characterId
            ORDER BY f.name ASC'
        );
        $stmt->bindParam(':characterId', $characterId, PDO::PARAM_INT);
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->hydrate($row);
        }

        return $result;
    }

    /**
     * Zwraca filtry folderu
     */
    public function getWorldFilters(int $worldId): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT f.* FROM filters f
            JOIN world_filters wf ON f.id = wf.id_filter
            WHERE wf.id_world = :worldId
            ORDER BY f.name ASC'
        );
        $stmt->bindParam(':worldId', $worldId, PDO::PARAM_INT);
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->hydrate($row);
        }

        return $result;
    }

    public function getFilterById(int $id): ?Filter
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT * FROM filters WHERE id = :id'
        );
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Szuka lub tworzy filtr
     * Zwraca ID filtera
     */
    public function getOrCreateFilter(string $name, int $userId, bool $isPublic = false): int
    {
        // Szukaj istniejącego
        $stmt = $this->database->connect()->prepare(
            'SELECT id FROM filters WHERE name = :name AND (id_user = :userId OR is_public = TRUE)'
        );
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            return (int)$result['id'];
        }

        // Stwórz nowy
        $stmt = $this->database->connect()->prepare(
            'INSERT INTO filters (name, id_user, is_public) VALUES (:name, :userId, :isPublic) RETURNING id'
        );
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':isPublic', $isPublic, PDO::PARAM_BOOL);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /**
     * Przypisuje filtr do postaci
     */
    public function addCharacterFilter(int $characterId, int $filterId, bool $isInherited = false): void
    {
        $stmt = $this->database->connect()->prepare(
            'INSERT INTO character_filters (id_character, id_filter, is_inherited)
            VALUES (:characterId, :filterId, :isInherited)
            ON CONFLICT (id_character, id_filter) DO UPDATE SET is_inherited = :isInherited'
        );
        $stmt->bindParam(':characterId', $characterId, PDO::PARAM_INT);
        $stmt->bindParam(':filterId', $filterId, PDO::PARAM_INT);
        $stmt->bindParam(':isInherited', $isInherited, PDO::PARAM_BOOL);
        $stmt->execute();
    }

    /**
     * Usuwa filtr z postaci
     */
    public function removeCharacterFilter(int $characterId, int $filterId): void
    {
        $stmt = $this->database->connect()->prepare(
            'DELETE FROM character_filters WHERE id_character = :characterId AND id_filter = :filterId'
        );
        $stmt->bindParam(':characterId', $characterId, PDO::PARAM_INT);
        $stmt->bindParam(':filterId', $filterId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Przypisuje filtr do folderu
     */
    public function addWorldFilter(int $worldId, int $filterId): void
    {
        $stmt = $this->database->connect()->prepare(
            'INSERT INTO world_filters (id_world, id_filter)
            VALUES (:worldId, :filterId)
            ON CONFLICT (id_world, id_filter) DO NOTHING'
        );
        $stmt->bindParam(':worldId', $worldId, PDO::PARAM_INT);
        $stmt->bindParam(':filterId', $filterId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Zwraca zablokowane filtry użytkownika
     */
    public function getUserBlockedFilters(int $userId): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT f.* FROM filters f
            JOIN user_blocked_filters ubf ON f.id = ubf.id_filter
            WHERE ubf.id_user = :userId
            ORDER BY f.name ASC'
        );
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->hydrate($row);
        }

        return $result;
    }

    /**
     * Blokuje filtr dla użytkownika
     */
    public function blockFilter(int $userId, int $filterId): void
    {
        $stmt = $this->database->connect()->prepare(
            'INSERT INTO user_blocked_filters (id_user, id_filter)
            VALUES (:userId, :filterId)
            ON CONFLICT (id_user, id_filter) DO NOTHING'
        );
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':filterId', $filterId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Odblokowuje filtr dla użytkownika
     */
    public function unblockFilter(int $userId, int $filterId): void
    {
        $stmt = $this->database->connect()->prepare(
            'DELETE FROM user_blocked_filters WHERE id_user = :userId AND id_filter = :filterId'
        );
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':filterId', $filterId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Szuka filtrów podobnych do danego tekstu
     * Używa ILIKE dla fuzzy match
     */
    public function searchFilters(string $query, int $userId): array
    {
        $searchQuery = '%' . strtolower($query) . '%';
        $stmt = $this->database->connect()->prepare(
            'SELECT * FROM filters 
            WHERE (is_public = TRUE OR id_user = :userId)
            AND LOWER(name) ILIKE :query
            ORDER BY name ASC
            LIMIT 10'
        );
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':query', $searchQuery);
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->hydrate($row);
        }

        return $result;
    }

    private function hydrate(array $row): Filter
    {
        return new Filter(
            $row['name'],
            $row['id_user'],
            $row['is_public'],
            $row['id']
        );
    }
}

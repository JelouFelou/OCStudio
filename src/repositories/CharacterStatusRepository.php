<?php

require_once 'Repository.php';
require_once __DIR__ . '/../models/CharacterStatus.php';

class CharacterStatusRepository extends Repository
{
    public function getAllStatuses(): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT * FROM character_statuses ORDER BY id ASC'
        );
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->hydrate($row);
        }

        return $result;
    }

    public function getStatusById(int $id): ?CharacterStatus
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT * FROM character_statuses WHERE id = :id'
        );
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    public function getStatusByName(string $name): ?CharacterStatus
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT * FROM character_statuses WHERE name = :name'
        );
        $stmt->bindParam(':name', $name);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    private function hydrate(array $row): CharacterStatus
    {
        return new CharacterStatus(
            $row['name'],
            $row['color_hex'],
            $row['id']
        );
    }
}

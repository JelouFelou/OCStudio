<?php

require_once 'Repository.php';
require_once __DIR__ . '/../models/Template.php';

class TemplateRepository extends Repository
{
    public function getTemplate(int $id): ?Template
    {
        $stmt = $this->database->connect()->prepare('
            SELECT * FROM templates WHERE id = :id
        ');
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            return null;
        }

        return new Template(
            $template['name'],
            $template['description'],
            $template['id_user'],
            $template['id'],
            $this->getTemplateFields($id)
        );
    }

    public function getTemplateFields(int $templateId): array
    {
        $stmt = $this->database->connect()->prepare('
            SELECT * FROM template_fields
            WHERE id_template = :id
            ORDER BY location DESC, order_number ASC
        ');
        $stmt->bindParam(':id', $templateId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTemplatesByUserId(int $userId): array
    {
        $result = [];
        $stmt = $this->database->connect()->prepare('
            SELECT * FROM templates WHERE id_user = :userId
        ');
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $template) {
            $result[] = new Template(
                $template['name'],
                $template['description'],
                $template['id_user'],
                $template['id']
            );
        }

        return $result;
    }

    public function addTemplate(string $name, string $description, int $userId, array $fields): void
    {
        $db = $this->database->connect();

        try {
            $db->beginTransaction();

            $stmt = $db->prepare('
                INSERT INTO templates (name, description, id_user)
                VALUES (?, ?, ?) RETURNING id
            ');
            $stmt->execute([$name, $description, $userId]);
            $templateId = $stmt->fetchColumn();

            $stmtField = $db->prepare('
                INSERT INTO template_fields (id_template, label, field_type, location, order_number, placeholder)
                VALUES (?, ?, ?, ?, ?, ?)
            ');

            foreach ($fields as $index => $field) {
                $stmtField->execute([
                    $templateId,
                    $field['label'],
                    $field['type']        ?? $field['field_type'] ?? 'text',
                    $field['location']    ?? 'left',
                    $index,
                    $field['placeholder'] ?? '',
                ]);
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function deleteTemplate(int $id, int $userId): bool
    {
        $stmt = $this->database->connect()->prepare('
            DELETE FROM templates WHERE id = :id AND id_user = :userId
        ');
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function getTemplateWithFields(int $id): ?array
    {
        $stmt = $this->database->connect()->prepare('
            SELECT * FROM templates WHERE id = :id
        ');
        $stmt->execute(['id' => $id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            return null;
        }

        $stmtFields = $this->database->connect()->prepare('
            SELECT * FROM template_fields WHERE id_template = :id ORDER BY order_number ASC
        ');
        $stmtFields->execute(['id' => $id]);
        $template['fields'] = $stmtFields->fetchAll(PDO::FETCH_ASSOC);

        return $template;
    }

    public function updateTemplate(int $id, string $name, string $description, array $fields): void
    {
        $db = $this->database->connect();

        try {
            $db->beginTransaction();

            $stmt = $db->prepare('UPDATE templates SET name = ?, description = ? WHERE id = ?');
            $stmt->execute([$name, $description, $id]);

            $existingFieldIds = $this->getTemplateFieldIdsForUpdate($db, $id);
            $keptFieldIds = [];
            $hasNewFields = false;

            $stmtUpdateField = $db->prepare('
                UPDATE template_fields
                SET label = ?, field_type = ?, location = ?, order_number = ?, placeholder = ?
                WHERE id = ? AND id_template = ?
            ');
            $stmtField = $db->prepare('
                INSERT INTO template_fields (id_template, label, field_type, location, order_number, placeholder)
                VALUES (?, ?, ?, ?, ?, ?)
                RETURNING id
            ');

            foreach ($fields as $index => $field) {
                $fieldId = isset($field['id']) && $field['id'] !== '' ? (int)$field['id'] : null;

                if ($fieldId && in_array($fieldId, $existingFieldIds, true)) {
                    $stmtUpdateField->execute([
                        $field['label'],
                        $field['type']        ?? $field['field_type'] ?? 'text',
                        $field['location']    ?? 'left',
                        $index,
                        $field['placeholder'] ?? '',
                        $fieldId,
                        $id,
                    ]);
                    $keptFieldIds[] = $fieldId;
                    continue;
                }

                $stmtField->execute([
                    $id,
                    $field['label'],
                    $field['type']        ?? $field['field_type'] ?? 'text',
                    $field['location']    ?? 'left',
                    $index,
                    $field['placeholder'] ?? '',
                ]);
                $keptFieldIds[] = (int)$stmtField->fetchColumn();
                $hasNewFields = true;
            }

            $this->deleteRemovedFields($db, $id, $keptFieldIds);

            if ($hasNewFields) {
                $this->moveReadyCharactersBackToInProgress($db, $id);
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    private function getTemplateFieldIdsForUpdate(PDO $db, int $templateId): array
    {
        $stmt = $db->prepare('
            SELECT id
            FROM template_fields
            WHERE id_template = ?
            FOR UPDATE
        ');
        $stmt->execute([$templateId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function deleteRemovedFields(PDO $db, int $templateId, array $keptFieldIds): void
    {
        if (!$keptFieldIds) {
            $stmtDel = $db->prepare('DELETE FROM template_fields WHERE id_template = ?');
            $stmtDel->execute([$templateId]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($keptFieldIds), '?'));
        $stmtDel = $db->prepare("
            DELETE FROM template_fields
            WHERE id_template = ?
              AND id NOT IN ($placeholders)
        ");
        $stmtDel->execute(array_merge([$templateId], $keptFieldIds));
    }

    private function moveReadyCharactersBackToInProgress(PDO $db, int $templateId): void
    {
        $stmt = $db->prepare("
            UPDATE characters
            SET status_id = in_progress.id
            FROM character_statuses ready, character_statuses in_progress
            WHERE characters.id_template = ?
              AND characters.status_id = ready.id
              AND ready.name = 'Gotowa'
              AND in_progress.name = 'W trakcie'
        ");
        $stmt->execute([$templateId]);
    }
}

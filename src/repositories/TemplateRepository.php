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
            $this->getTemplateFields($id),
            $template['is_hidden'] ?? false,
            $template['public_id'] ?? null,
            $template['date_calendar_type'] ?? 'real',
            $template['date_settings'] ?? '',
            $template['current_world_date'] ?? '',
            !empty($template['txt_export_enabled']),
            $template['txt_export_template'] ?? ''
        );
    }

    public function getTemplateByIdAndUserId(int $id, int $userId): ?Template
    {
        $stmt = $this->database->connect()->prepare('
            SELECT * FROM templates WHERE id = :id AND id_user = :userId
        ');
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
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
            $this->getTemplateFields($id),
            $template['is_hidden'] ?? false,
            $template['public_id'] ?? null,
            $template['date_calendar_type'] ?? 'real',
            $template['date_settings'] ?? '',
            $template['current_world_date'] ?? '',
            !empty($template['txt_export_enabled']),
            $template['txt_export_template'] ?? ''
        );
    }

    public function getTemplateByPublicIdAndUserId(string $publicId, int $userId): ?Template
    {
        $stmt = $this->database->connect()->prepare('
            SELECT * FROM templates WHERE public_id::text = :publicId AND id_user = :userId
        ');
        $stmt->bindValue(':publicId', $publicId);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
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
            $this->getTemplateFields((int)$template['id']),
            $template['is_hidden'] ?? false,
            $template['public_id'] ?? null,
            $template['date_calendar_type'] ?? 'real',
            $template['date_settings'] ?? '',
            $template['current_world_date'] ?? '',
            !empty($template['txt_export_enabled']),
            $template['txt_export_template'] ?? ''
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

    public function getTemplatesByUserId(int $userId, array $blockedFilterIds = [], bool $includeHidden = false): array
    {
        $result = [];
        $blockedFilterIds = array_values(array_unique(array_filter(array_map('intval', $blockedFilterIds))));
        $blockedClause = '';
        if (!empty($blockedFilterIds)) {
            $ids = implode(',', $blockedFilterIds);
            $blockedClause = " AND NOT EXISTS (
                SELECT 1 FROM content_filters cf
                WHERE cf.object_type = 'template'
                  AND cf.object_id = t.id
                  AND cf.id_filter IN ({$ids})
            )";
        }

        $stmt = $this->database->connect()->prepare('
            SELECT t.* FROM templates t
            WHERE t.id_user = :userId ' . $blockedClause . ($includeHidden ? '' : ' AND COALESCE(t.is_hidden, FALSE) = FALSE') . '
            ORDER BY t.name ASC
        ');
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $template) {
            $result[] = new Template(
                $template['name'],
                $template['description'],
                $template['id_user'],
                $template['id'],
                [],
                $template['is_hidden'] ?? false,
                $template['public_id'] ?? null,
                $template['date_calendar_type'] ?? 'real',
                $template['date_settings'] ?? '',
                $template['current_world_date'] ?? '',
                !empty($template['txt_export_enabled']),
                $template['txt_export_template'] ?? ''
            );
        }

        return $result;
    }

    public function searchGlobalTemplates(int $userId, string $query, array $blockedFilterIds = [], bool $includeHidden = false, bool $includeAdult = false, int $limit = 6): array
    {
        $blockedFilterIds = array_values(array_unique(array_filter(array_map('intval', $blockedFilterIds))));
        $blockedClause = '';
        if (!empty($blockedFilterIds)) {
            $ids = implode(',', $blockedFilterIds);
            $blockedClause = " AND NOT EXISTS (
                SELECT 1 FROM content_filters blocked_cf
                WHERE blocked_cf.object_type = 'template'
                  AND blocked_cf.object_id = t.id
                  AND blocked_cf.id_filter IN ({$ids})
            )";
        }
        $adultClause = $includeAdult ? '' : " AND NOT EXISTS (
            SELECT 1
            FROM content_filters adult_cf
            JOIN filters adult_f ON adult_f.id = adult_cf.id_filter
            WHERE adult_cf.object_type = 'template'
              AND adult_cf.object_id = t.id
              AND (
                LOWER(COALESCE(adult_f.slug, '')) IN ('adult', 'nsfw', '+18', '18+')
                OR LOWER(COALESCE(adult_f.name, '')) IN ('adult', 'nsfw', '+18', '18+')
                OR LOWER(COALESCE(adult_f.label, '')) IN ('adult', 'nsfw', '+18', '18+')
              )
        )";

        $stmt = $this->database->connect()->prepare("
            SELECT DISTINCT t.*
            FROM templates t
            LEFT JOIN template_fields tf ON tf.id_template = t.id
            LEFT JOIN content_filters cf ON cf.object_type = 'template' AND cf.object_id = t.id
            LEFT JOIN filters f ON f.id = cf.id_filter
            WHERE t.id_user = :userId
              " . ($includeHidden ? '' : ' AND COALESCE(t.is_hidden, FALSE) = FALSE') . "
              {$blockedClause}
              {$adultClause}
              AND (
                LOWER(t.name) LIKE :q
                OR LOWER(COALESCE(t.description, '')) LIKE :q
                OR LOWER(COALESCE(tf.label, '')) LIKE :q
                OR LOWER(COALESCE(tf.field_type, '')) LIKE :q
                OR LOWER(COALESCE(tf.placeholder, '')) LIKE :q
                OR LOWER(COALESCE(f.name, '')) LIKE :q
                OR LOWER(COALESCE(f.slug, '')) LIKE :q
                OR LOWER(COALESCE(f.label, '')) LIKE :q
              )
            ORDER BY t.name ASC, t.id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':q', '%' . mb_strtolower(trim($query)) . '%');
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn($template) => new Template(
            $template['name'],
            $template['description'],
            $template['id_user'],
            $template['id'],
            [],
            $template['is_hidden'] ?? false,
            $template['public_id'] ?? null,
            $template['date_calendar_type'] ?? 'real',
            $template['date_settings'] ?? '',
            $template['current_world_date'] ?? '',
            !empty($template['txt_export_enabled']),
            $template['txt_export_template'] ?? ''
        ), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function addTemplate(string $name, string $description, int $userId, array $fields, string $dateCalendarType = 'real', string $dateSettings = '', string $currentWorldDate = '', bool $txtExportEnabled = false, string $txtExportTemplate = ''): int
    {
        $db = $this->database->connect();

        try {
            $db->beginTransaction();

            $stmt = $db->prepare('
                INSERT INTO templates (name, description, id_user, date_calendar_type, date_settings, current_world_date, txt_export_enabled, txt_export_template)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id
            ');
            $stmt->execute([$name, $description, $userId, $dateCalendarType, $dateSettings, $currentWorldDate, $txtExportEnabled ? 1 : 0, $txtExportTemplate]);
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
            return (int)$templateId;
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

    public function setHidden(int $templateId, int $userId, bool $hidden): void
    {
        $stmt = $this->database->connect()->prepare(
            'UPDATE templates SET is_hidden = :hidden WHERE id = :id AND id_user = :userId'
        );
        $stmt->execute([
            ':hidden' => $hidden ? 1 : 0,
            ':id' => $templateId,
            ':userId' => $userId,
        ]);
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

    public function getTemplateWithFieldsByUserId(int $id, int $userId): ?array
    {
        $stmt = $this->database->connect()->prepare('
            SELECT * FROM templates WHERE id = :id AND id_user = :userId
        ');
        $stmt->execute(['id' => $id, 'userId' => $userId]);
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

    public function getTemplateWithFieldsByPublicIdAndUserId(string $publicId, int $userId): ?array
    {
        $stmt = $this->database->connect()->prepare('
            SELECT * FROM templates WHERE public_id::text = :publicId AND id_user = :userId
        ');
        $stmt->execute(['publicId' => $publicId, 'userId' => $userId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            return null;
        }

        $stmtFields = $this->database->connect()->prepare('
            SELECT * FROM template_fields WHERE id_template = :id ORDER BY order_number ASC
        ');
        $stmtFields->execute(['id' => $template['id']]);
        $template['fields'] = $stmtFields->fetchAll(PDO::FETCH_ASSOC);

        return $template;
    }

    public function updateTemplate(int $id, string $name, string $description, array $fields, string $dateCalendarType = 'real', string $dateSettings = '', string $currentWorldDate = '', bool $txtExportEnabled = false, string $txtExportTemplate = ''): void
    {
        $db = $this->database->connect();

        try {
            $db->beginTransaction();

            $stmt = $db->prepare('
                UPDATE templates
                SET name = ?, description = ?, date_calendar_type = ?, date_settings = ?, current_world_date = ?, txt_export_enabled = ?, txt_export_template = ?
                WHERE id = ?
            ');
            $stmt->execute([$name, $description, $dateCalendarType, $dateSettings, $currentWorldDate, $txtExportEnabled ? 1 : 0, $txtExportTemplate, $id]);

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

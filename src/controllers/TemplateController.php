<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/TemplateRepository.php';
require_once __DIR__ . '/../repositories/FilterRepository.php';
require_once __DIR__ . '/../repositories/PublicationRepository.php';

class TemplateController extends AppController
{
    private $templateRepository;
    private $filterRepository;
    private PublicationRepository $publicationRepository;

    public function __construct()
    {
        $this->templateRepository = new TemplateRepository();
        $this->filterRepository = new FilterRepository();
        $this->publicationRepository = new PublicationRepository();
    }

    public function templates()
    {
        $this->requireLogin();

        $blockedFilterIds = $this->filterRepository->blockedFilterIds((int)$_SESSION['user_id']);
        $templates = $this->templateRepository->getTemplatesByUserId(
            (int)$_SESSION['user_id'],
            $blockedFilterIds,
            !empty($this->getUserInterfaceSettings()['revealHidden'])
        );
        $templateIds = array_map(fn($template) => (int)$template->getId(), $templates);

        $this->render('templates', [
            'title' => 'OCStudio - Szablony postaci',
            'templates' => $templates,
            'templatePublications' => $this->publicationRepository->ownedTemplatePublicationMap((int)$_SESSION['user_id'], $templateIds),
        ]);
    }

    public function createTemplate()
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Tworzenie szablonow postaci jest obecnie wylaczone.');

        if ($this->isPost()) {
            $name        = $_POST['template_name'];
            $description = $_POST['template_description'];
            $userId      = $_SESSION['user_id'];
            $templateRaw = $_POST['template_id'] ?? null;
            $templateId  = $this->templateIdFromPublicOrLegacyId($templateRaw, (int)$userId);
            $dateCalendarType = $this->cleanDateCalendarType($_POST['date_calendar_type'] ?? 'real');
            $dateSettings = $this->cleanDateSettings($_POST['date_settings'] ?? '');
            $currentWorldDate = trim((string)($_POST['current_world_date'] ?? ''));
            $txtExportEnabled = !empty($_POST['txt_export_enabled']);
            $txtExportTemplate = $this->cleanTxtExportTemplate($_POST['txt_export_template'] ?? '');

            // Zbieramy pola – teraz także placeholder (JSON z wierszami tabeli lub pusty string)
            $fields      = [];
            $labels      = $_POST['field_labels']       ?? [];
            $fieldIds    = $_POST['field_ids']          ?? [];
            $locations   = $_POST['field_locations']    ?? [];
            $types       = $_POST['field_types']        ?? [];
            $placeholders = $_POST['field_placeholders'] ?? [];

            foreach ($labels as $index => $label) {
                $fields[] = [
                    'id'          => $fieldIds[$index]    ?? null,
                    'label'       => trim((string)$label),
                    'location'    => $locations[$index]    ?? 'left',
                    'type'        => $types[$index]        ?? 'text',
                    'placeholder' => $placeholders[$index] ?? '',
                ];
            }

            try {
                $templateTags = $this->filterRepository->validateMinimumTags($_POST['template_tags'] ?? '');
                $templateFilterIds = array_map(fn($tag) => (int)$tag['id'], $templateTags);
                if (trim((string)$templateRaw) !== '' && !$templateId) {
                    throw new RuntimeException('Szablon nie znaleziony.');
                }
                if ($templateId) {
                    if (!$this->templateRepository->getTemplateWithFieldsByUserId((int)$templateId, (int)$userId)) {
                        http_response_code(404);
                        $this->render('create_template', [
                            'title' => 'Nowy Szablon postaci',
                            'template' => null,
                            'messages' => ['Szablon nie znaleziony.']
                        ]);
                        return;
                    }
                    $this->templateRepository->updateTemplate((int) $templateId, $name, $description, $fields, $dateCalendarType, $dateSettings, $currentWorldDate, $txtExportEnabled, $txtExportTemplate);
                    $this->filterRepository->replaceObjectFilters('template', (int)$templateId, $templateFilterIds);
                } else {
                    $newTemplateId = $this->templateRepository->addTemplate($name, $description, $userId, $fields, $dateCalendarType, $dateSettings, $currentWorldDate, $txtExportEnabled, $txtExportTemplate);
                    $this->filterRepository->replaceObjectFilters('template', $newTemplateId, $templateFilterIds);
                }
                header("Location: /templates");
            } catch (Exception $e) {
                $this->render('create_template', [
                    'title' => 'Nowy Szablon postaci',
                    'template' => null,
                    'messages' => [$e->getMessage()]
                ]);
            }
            return;
        }

        $this->render('create_template', [
            'title'    => 'Nowy Szablon postaci',
            'template' => null
        ]);
    }

    public function deleteTemplate()
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Usuwanie szablonow jest obecnie wylaczone.');

        if (!$this->isPost()) {
            http_response_code(405);
            header('Location: /templates');
            exit();
        }

        $this->validateCsrf();

        $template = $this->templateFromPublicOrLegacyId($_POST['id'] ?? null, (int)$_SESSION['user_id']);
        if ($template) {
            $confirmation = trim((string)($_POST['confirmation'] ?? ''));
            if ($confirmation !== (string)$template['name']) {
                http_response_code(400);
                echo 'Nazwa szablonu nie zgadza sie.';
                exit();
            }

            $this->templateRepository->deleteTemplate((int)$template['id'], (int) $_SESSION['user_id']);
        }

        http_response_code(302);
        header("Location: /templates");
        exit();
    }

    public function duplicateTemplate()
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Tworzenie szablonow jest obecnie wylaczone.');

        if ($this->isPost()) {
            $id      = $this->templateIdFromPublicOrLegacyId($_POST['id'] ?? null, (int)$_SESSION['user_id']);
            $newName = $_POST['new_name'];

            $original = $id ? $this->templateRepository->getTemplateWithFieldsByUserId($id, (int)$_SESSION['user_id']) : null;
            if ($original) {
                $this->templateRepository->addTemplate(
                    $newName,
                    $original['description'],
                    $_SESSION['user_id'],
                    $original['fields'],
                    $original['date_calendar_type'] ?? 'real',
                    $original['date_settings'] ?? '',
                    $original['current_world_date'] ?? '',
                    !empty($original['txt_export_enabled']),
                    (string)($original['txt_export_template'] ?? '')
                );
            }
        }
        header("Location: /templates");
    }

    public function editTemplate()
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Edycja szablonow jest obecnie wylaczona.');

        $template = $this->templateFromPublicOrLegacyId($_GET['id'] ?? null, (int)$_SESSION['user_id']);

        if (!$template) {
            http_response_code(404);
            header('Location: /templates');
            exit();
        }

        $templateFilters = [];
        if (!empty($template['id'])) {
            $templateFilters = $this->filterRepository->getObjectFilters('template', (int)$template['id']);
        }

        $this->render('create_template', [
            'template' => $template,
            'isEdit'   => true,
            'templateFilters' => $templateFilters
        ]);
    }

    public function toggleHidden(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Edycja szablonow jest obecnie wylaczona.', true);

        $input = $this->requireJsonPost();
        $templateId = (int)($input['templateId'] ?? 0);
        if ($templateId <= 0) {
            $this->jsonError('Brak szablonu.');
        }

        $this->templateRepository->setHidden(
            $templateId,
            (int)$_SESSION['user_id'],
            !empty($input['hidden'])
        );

        $this->jsonResponse(['success' => true]);
    }

    private function templateFromPublicOrLegacyId(mixed $raw, int $userId): ?array
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }

        return ctype_digit($raw)
            ? $this->templateRepository->getTemplateWithFieldsByUserId((int)$raw, $userId)
            : $this->templateRepository->getTemplateWithFieldsByPublicIdAndUserId($raw, $userId);
    }

    private function templateIdFromPublicOrLegacyId(mixed $raw, int $userId): ?int
    {
        $template = $this->templateFromPublicOrLegacyId($raw, $userId);
        return $template ? (int)$template['id'] : null;
    }

    private function cleanDateCalendarType(mixed $raw): string
    {
        $type = (string)$raw;
        return in_array($type, ['real', 'fictional', 'era'], true) ? $type : 'real';
    }

    private function cleanDateSettings(mixed $raw): string
    {
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            $data = [];
        }

        $months = [];
        foreach (($data['months'] ?? []) as $month) {
            if (!is_array($month)) {
                continue;
            }
            $name = trim((string)($month['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $months[] = [
                'name' => $name,
                'days' => max(1, min(999, (int)($month['days'] ?? 30))),
            ];
        }
        if (empty($months)) {
            $months = [
                ['name' => 'Styczen', 'days' => 31],
                ['name' => 'Luty', 'days' => 28],
                ['name' => 'Marzec', 'days' => 31],
                ['name' => 'Kwiecien', 'days' => 30],
                ['name' => 'Maj', 'days' => 31],
                ['name' => 'Czerwiec', 'days' => 30],
                ['name' => 'Lipiec', 'days' => 31],
                ['name' => 'Sierpien', 'days' => 31],
                ['name' => 'Wrzesien', 'days' => 30],
                ['name' => 'Pazdziernik', 'days' => 31],
                ['name' => 'Listopad', 'days' => 30],
                ['name' => 'Grudzien', 'days' => 31],
            ];
        }

        $eras = [];
        foreach (($data['eras'] ?? []) as $era) {
            $era = trim((string)$era);
            if ($era !== '') {
                $eras[] = $era;
            }
        }

        return json_encode([
            'type' => 'date',
            'months' => $months,
            'eras' => array_values(array_unique($eras)),
            'defaultYear' => trim((string)($data['defaultYear'] ?? date('Y'))),
            'currentDateMode' => in_array(($data['currentDateMode'] ?? 'fixed'), ['fixed', 'real_today', 'auto'], true)
                ? $data['currentDateMode']
                : 'fixed',
            'currentDateAnchor' => trim((string)($data['currentDateAnchor'] ?? '')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function cleanTxtExportTemplate(mixed $raw): string
    {
        $value = trim((string)$raw);
        if (mb_strlen($value) > 12000) {
            $value = mb_substr($value, 0, 12000);
        }

        return $value;
    }
}

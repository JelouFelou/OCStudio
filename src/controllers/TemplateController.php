<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/TemplateRepository.php';

class TemplateController extends AppController
{
    private $templateRepository;

    public function __construct()
    {
        $this->templateRepository = new TemplateRepository();
    }

    public function templates()
    {
        $this->requireLogin();

        $templates = $this->templateRepository->getTemplatesByUserId($_SESSION['user_id']);

        $this->render('templates', [
            'title' => 'OCStudio - Szablony postaci',
            'templates' => $templates
        ]);
    }

    public function createTemplate()
    {
        $this->requireLogin();

        if ($this->isPost()) {
            $name        = $_POST['template_name'];
            $description = $_POST['template_description'];
            $userId      = $_SESSION['user_id'];
            $templateId  = $_POST['template_id'] ?? null;

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
                    'label'       => $label,
                    'location'    => $locations[$index]    ?? 'left',
                    'type'        => $types[$index]        ?? 'text',
                    'placeholder' => $placeholders[$index] ?? '',
                ];
            }

            try {
                if ($templateId) {
                    $this->templateRepository->updateTemplate((int) $templateId, $name, $description, $fields);
                } else {
                    $this->templateRepository->addTemplate($name, $description, $userId, $fields);
                }
                header("Location: /templates");
            } catch (Exception $e) {
                header("Location: /dashboard");
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
        $id = $_GET['id'];
        $this->templateRepository->deleteTemplate($id, $_SESSION['user_id']);
        header("Location: /templates");
    }

    public function duplicateTemplate()
    {
        if ($this->isPost()) {
            $id      = $_POST['id'];
            $newName = $_POST['new_name'];

            $original = $this->templateRepository->getTemplateWithFields($id);
            if ($original) {
                $this->templateRepository->addTemplate(
                    $newName,
                    $original['description'],
                    $_SESSION['user_id'],
                    $original['fields']
                );
            }
        }
        header("Location: /templates");
    }

    public function editTemplate()
    {
        $id       = $_GET['id'];
        $template = $this->templateRepository->getTemplateWithFields($id);

        $this->render('create_template', [
            'template' => $template,
            'isEdit'   => true
        ]);
    }
}

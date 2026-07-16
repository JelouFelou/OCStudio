<?php

require_once 'src/controllers/SecurityController.php';
require_once 'src/controllers/DashboardController.php';
require_once 'src/controllers/TemplateController.php';
require_once 'src/controllers/CharacterController.php';
require_once 'src/controllers/FileController.php';
require_once 'src/controllers/AdminController.php';
require_once 'src/controllers/SettingsController.php';
require_once 'src/controllers/RelationController.php';
require_once 'src/controllers/StoryController.php';

class Routing
{
    public static $routes = [
        "login" => [
            "controller" => "SecurityController",
            "action" => "login"
        ],
        "dashboard" => [
            "controller" => "DashboardController",
            "action" => "index"
        ],
        "" => [
            "controller" => "SecurityController",
            "action" => "login"
        ],
        "register" => [
            "controller" => "SecurityController",
            "action" => "register"
        ],
        "forgot-password" => [
            "controller" => "SecurityController",
            "action" => "forgotPassword"
        ],
        'createTemplate' => [
            'controller' => 'TemplateController',
            'action' => 'createTemplate'
        ],
        'templates' => [
            'controller' => 'TemplateController',
            'action' => 'templates'
        ],
        'admin' => [
            'controller' => 'AdminController',
            'action' => 'index'
        ],
        'admin/ban' => [
            'controller' => 'AdminController',
            'action' => 'banUser'
        ],
        'admin/unban' => [
            'controller' => 'AdminController',
            'action' => 'unbanUser'
        ],
        'admin/delete-schedule' => [
            'controller' => 'AdminController',
            'action' => 'scheduleDeleteUser'
        ],
        'admin/delete-cancel' => [
            'controller' => 'AdminController',
            'action' => 'cancelDeleteUser'
        ],
        'admin/effects' => [
            'controller' => 'AdminController',
            'action' => 'saveEffects'
        ],
        'admin/backup' => [
            'controller' => 'AdminController',
            'action' => 'backupDatabase'
        ],
        'admin/import' => [
            'controller' => 'AdminController',
            'action' => 'importDatabase'
        ],
        'settings' => [
            'controller' => 'SettingsController',
            'action' => 'index'
        ],
        'settings/export' => [
            'controller' => 'SettingsController',
            'action' => 'exportAccount'
        ],
        'settings/import' => [
            'controller' => 'SettingsController',
            'action' => 'importAccount'
        ],
        'relations' => [
            'controller' => 'RelationController',
            'action' => 'index'
        ],
        'relations/editor' => [
            'controller' => 'RelationController',
            'action' => 'editor'
        ],
        'gallery' => [
            'controller' => 'FileController',
            'action' => 'gallery'
        ],
        'deleteTemplate' => [
            'controller' => 'TemplateController',
            'action' => 'deleteTemplate'
        ],
        'duplicateTemplate' => [
            'controller' => 'TemplateController',
            'action' => 'duplicateTemplate'
        ],
        'editTemplate' => [
            'controller' => 'TemplateController',
            'action' => 'editTemplate'
        ],
        'createCharacter' => [
            'controller' => 'CharacterController',
            'action' => 'createCharacter'
        ],
        'getTemplateData' => [
            'controller' => 'CharacterController',
            'action' => 'getTemplateData'
        ],
        'editCharacter' => [
            'controller' => 'CharacterController',
            'action' => 'editCharacter'
        ],
        'viewCharacter' => [
            'controller' => 'CharacterController',
            'action' => 'viewCharacter'
        ],
        'uploadFile' => [
            'controller' => 'FileController',
            'action' => 'uploadFile'
        ],
        'api/images' => [
            'controller' => 'FileController',
            'action' => 'listImages'
        ],
        'api/images/upload' => [
            'controller' => 'FileController',
            'action' => 'uploadImage'
        ],
        'api/images/tags' => [
            'controller' => 'FileController',
            'action' => 'updateImageTags'
        ],
        'api/images/visibility' => [
            'controller' => 'FileController',
            'action' => 'updateImageVisibility'
        ],
        'api/images/delete' => [
            'controller' => 'FileController',
            'action' => 'deleteImage'
        ],
        'api/images/usage' => [
            'controller' => 'FileController',
            'action' => 'imageUsage'
        ],
        'api/images/merge' => [
            'controller' => 'FileController',
            'action' => 'mergeImages'
        ],
        'api/filters/resolve' => [
            'controller' => 'FileController',
            'action' => 'resolveFilters'
        ],
        // --- Postacie / foldery ---
        'characters' => [
            'controller' => 'CharacterController',
            'action' => 'characters'
        ],
        'api/worlds' => [
            'controller' => 'CharacterController',
            'action' => 'createWorld'
        ],
        'api/worlds/rename' => [
            'controller' => 'CharacterController',
            'action' => 'renameWorld'
        ],
        'api/worlds/delete' => [
            'controller' => 'CharacterController',
            'action' => 'deleteWorld'
        ],
        'api/worlds/assign' => [
            'controller' => 'CharacterController',
            'action' => 'assignCharacterToWorld'
        ],
        'api/characters/assign' => [
            'controller' => 'CharacterController',
            'action' => 'assignCharacterToWorld'
        ],
        'api/characters/status' => [
            'controller' => 'CharacterController',
            'action' => 'updateCharacterStatus'
        ],
        'api/characters/filters/add' => [
            'controller' => 'CharacterController',
            'action' => 'addCharacterFilter'
        ],
        'api/characters/filters/remove' => [
            'controller' => 'CharacterController',
            'action' => 'removeCharacterFilter'
        ],
        'api/characters/search' => [
            'controller' => 'CharacterController',
            'action' => 'searchCharacters'
        ],
        'api/filters/search' => [
            'controller' => 'CharacterController',
            'action' => 'searchFilters'
        ],
        'api/filters/toggle-block' => [
            'controller' => 'CharacterController',
            'action' => 'toggleBlockFilter'
        ],
        'api/characters/restoreImage' => [
            'controller' => 'CharacterController', 
            'action' => 'restoreDefaultImage'
        ],
        'api/characters/duplicate' => [
            'controller' => 'CharacterController',
            'action' => 'duplicateCharacter'
        ],
        'api/characters/delete' => [
            'controller' => 'CharacterController',
            'action' => 'deleteCharacter'
        ],
        'api/characters/hidden' => [
            'controller' => 'CharacterController',
            'action' => 'toggleCharacterHidden'
        ],
        'api/characters/pinned' => [
            'controller' => 'CharacterController',
            'action' => 'toggleCharacterPinned'
        ],
        'api/worlds/hidden' => [
            'controller' => 'CharacterController',
            'action' => 'toggleWorldHidden'
        ],
        'api/templates/hidden' => [
            'controller' => 'TemplateController',
            'action' => 'toggleHidden'
        ],
        'api/relations/tree' => [
            'controller' => 'RelationController',
            'action' => 'tree'
        ],
        'api/relation-boards' => [
            'controller' => 'RelationController',
            'action' => 'saveBoard'
        ],
        'api/relation-boards/duplicate' => [
            'controller' => 'RelationController',
            'action' => 'duplicateBoard'
        ],
        'api/relation-boards/delete' => [
            'controller' => 'RelationController',
            'action' => 'deleteBoard'
        ],
        'api/relation-boards/hidden' => [
            'controller' => 'RelationController',
            'action' => 'toggleBoardHidden'
        ],
        'api/relations/node' => [
            'controller' => 'RelationController',
            'action' => 'addNode'
        ],
        'api/relations/node/position' => [
            'controller' => 'RelationController',
            'action' => 'updateNodePosition'
        ],
        'api/relations/node/remove' => [
            'controller' => 'RelationController',
            'action' => 'removeNode'
        ],
        'api/relations' => [
            'controller' => 'RelationController',
            'action' => 'saveRelation'
        ],
        'api/relations/delete' => [
            'controller' => 'RelationController',
            'action' => 'deleteRelation'
        ],
        'api/relations/rules' => [
            'controller' => 'RelationController',
            'action' => 'saveRules'
        ],
        'api/search' => [
            'controller' => 'CharacterController',
            'action' => 'globalSearch'
        ],

        // ═══ HISTORIE (STORIES) ═══
        'stories' => [
            'controller' => 'StoryController',
            'action' => 'stories'
        ],
        'createStory' => [
            'controller' => 'StoryController',
            'action' => 'createStory'
        ],
        'editStory' => [
            'controller' => 'StoryController',
            'action' => 'editStory'
        ],
        'viewStory' => [
            'controller' => 'StoryController',
            'action' => 'viewStory'
        ],
        'deleteStory' => [
            'controller' => 'StoryController',
            'action' => 'deleteStory'
        ],
        'getStoryData' => [
            'controller' => 'StoryController',
            'action' => 'getStoryData'
        ],
        'saveStoryField' => [
            'controller' => 'StoryController',
            'action' => 'saveStoryField'
        ],
        'addCharacterToStory' => [
            'controller' => 'StoryController',
            'action' => 'addCharacterToStory'
        ],
        'removeCharacterFromStory' => [
            'controller' => 'StoryController',
            'action' => 'removeCharacterFromStory'
        ],
        'updateStoryCharacterPseudonyms' => [
            'controller' => 'StoryController',
            'action' => 'updateStoryCharacterPseudonyms'
        ],
        'api/stories/status' => [
            'controller' => 'StoryController',
            'action' => 'updateStoryStatus'
        ],
        'api/stories/duplicate' => [
            'controller' => 'StoryController',
            'action' => 'duplicateStory'
        ],
        'api/stories/hidden' => [
            'controller' => 'StoryController',
            'action' => 'toggleStoryHidden'
        ],
        'api/stories/timeline-position' => [
            'controller' => 'StoryController',
            'action' => 'updateTimelinePosition'
        ],
    ];

    public static function run(string $path)
    {
        if (preg_match('#^character/([^/]+)$#', $path, $matches)) {
            $_GET['id'] = $matches[1];
            $controllerObj = new CharacterController();
            $controllerObj->viewCharacter();
            return;
        }

        if (preg_match('#^editCharacter/([^/]+)$#', $path, $matches)) {
            $_GET['id'] = $matches[1];
            $controllerObj = new CharacterController();
            $controllerObj->editCharacter();
            return;
        }

        if (preg_match('#^characters/([^/]+)$#', $path, $matches)) {
            $_GET['world'] = $matches[1];
            $controllerObj = new CharacterController();
            $controllerObj->characters();
            return;
        }

        if (preg_match('#^templates/([^/]+)/edit$#', $path, $matches)) {
            $_GET['id'] = $matches[1];
            $controllerObj = new TemplateController();
            $controllerObj->editTemplate();
            return;
        }

        if (preg_match('#^templates/([^/]+)/delete$#', $path, $matches)) {
            $_GET['id'] = $matches[1];
            $controllerObj = new TemplateController();
            $controllerObj->deleteTemplate();
            return;
        }

        if (preg_match('#^relations/([^/]+)$#', $path, $matches)) {
            $_GET['board'] = $matches[1];
            $controllerObj = new RelationController();
            $controllerObj->editor();
            return;
        }

        if (preg_match('#^story/([^/]+)$#', $path, $matches)) {
            $_GET['id'] = $matches[1];
            $controllerObj = new StoryController();
            $controllerObj->viewStory();
            return;
        }

        if (preg_match('#^editStory/([^/]+)$#', $path, $matches)) {
            $_GET['id'] = $matches[1];
            $controllerObj = new StoryController();
            $controllerObj->editStory();
            return;
        }

        if (preg_match('#^stories/([^/]+)$#', $path, $matches)) {
            $_GET['world'] = $matches[1];
            $controllerObj = new StoryController();
            $controllerObj->stories();
            return;
        }

        if (array_key_exists($path, self::$routes)) {
            $controller = self::$routes[$path]["controller"];
            $action     = self::$routes[$path]["action"];

            $controllerObj = new $controller;
            $controllerObj->$action();
            return;
        }

        http_response_code(404);
        include 'public/views/404.html';
    }
}

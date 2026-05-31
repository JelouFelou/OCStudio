<?php

require_once 'src/controllers/SecurityController.php';
require_once 'src/controllers/DashboardController.php';
require_once 'src/controllers/TemplateController.php';
require_once 'src/controllers/CharacterController.php';
require_once 'src/controllers/FileController.php';
require_once 'src/controllers/AdminController.php';
require_once 'src/controllers/SettingsController.php';
require_once 'src/controllers/RelationController.php';

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
        'settings' => [
            'controller' => 'SettingsController',
            'action' => 'index'
        ],
        'relations' => [
            'controller' => 'RelationController',
            'action' => 'index'
        ],
        'relations/editor' => [
            'controller' => 'RelationController',
            'action' => 'editor'
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
    ];

    public static function run(string $path)
    {
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

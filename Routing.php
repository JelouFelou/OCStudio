<?php

require_once 'src/controllers/SecurityController.php';
require_once 'src/controllers/DashboardController.php';
require_once 'src/controllers/TemplateController.php';
require_once 'src/controllers/CharacterController.php';
require_once 'src/controllers/FileController.php';

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
        'createTemplate' => [
            'controller' => 'TemplateController',
            'action' => 'createTemplate'
        ],
        'templates' => [
            'controller' => 'TemplateController',
            'action' => 'templates'
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
        '/api/search' => [
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
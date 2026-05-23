<?php

class AppController {
    protected function isGet(): bool
    {
        return $_SERVER["REQUEST_METHOD"] === 'GET';
    }

    protected function isPost(): bool
    {
        return $_SERVER["REQUEST_METHOD"] === 'POST';
    }

    protected function requireLogin()
    {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            $scheme = (
                (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            ) ? 'https' : 'http';
            $url = "{$scheme}://$_SERVER[HTTP_HOST]";
            header("Location: {$url}/login");
            exit();
        }
    }
 
    protected function render(?string $template = null, array $variables = [])
    {
        // Określamy ścieżkę bazową do widoków
        $basePath = __DIR__.'/../../public/views/';
        
        $templatePath = $basePath . $template . '.html';
        $headPath = $basePath . 'partials/head.html';
        $navPath = $basePath . 'partials/nav.html';
        
        // Pobierz światy użytkownika dla nav (tylko jeśli jest zalogowany)
        if ($template !== 'login' && $template !== 'register' && isset($_SESSION['user_id'])) {
            if (!isset($variables['worlds'])) {
                require_once __DIR__ . '/../repositories/WorldRepository.php';
                $worldRepository = new WorldRepository();
                // Pobierz tylko pierwsze podfoldery (bez root-a)
                $variables['worlds'] = $worldRepository->getChildWorlds($_SESSION['user_id'], null);
            }
        }
        
        if(file_exists($templatePath)){
            extract($variables);
            ob_start();
            
            // Dołączamy nagłówek
            if (file_exists($headPath)) {
                include $headPath;
            }

            
            
            // Jeśli to nie jest login, dołączamy nawigację
            if ($template !== 'login' && $template !== 'register' && file_exists($navPath)) {
                include $navPath;
            }

            include $templatePath;

            // Domykamy tagi, jeśli to nie login
            if ($template !== 'login') {
                echo '    </main>'; 
                echo '</div>';      
            }
            
            echo '</body></html>';
            
            $output = ob_get_clean();
        } else {
            // Prosta informacja o braku pliku dla debugowania
            $output = "Błąd: Nie znaleziono pliku widoku: " . $templatePath;
        }
        
        echo $output;
    }
}

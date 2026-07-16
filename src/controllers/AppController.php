<?php

class AppController {
    private const AUTH_TEMPLATES = ['login', 'register', 'forgot_password'];

    protected function isGet(): bool
    {
        return $_SERVER["REQUEST_METHOD"] === 'GET';
    }

    protected function isPost(): bool
    {
        return $_SERVER["REQUEST_METHOD"] === 'POST';
    }

    protected function requireJsonPost(): array
    {
        header('Content-Type: application/json');

        if (!$this->isPost()) {
            $this->jsonResponse(['error' => 'Method not allowed'], 405);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        return is_array($input) ? $input : [];
    }

    protected function jsonResponse(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    protected function jsonError(string $message, int $status = 400, array $extra = []): void
    {
        $this->jsonResponse(array_merge(['error' => $message], $extra), $status);
    }

    protected function jsonException(Throwable $e, string $fallbackMessage = 'Nie udalo sie wykonac operacji.'): void
    {
        $code = $e->getCode();
        $status = ($code >= 400 && $code <= 599) ? $code : 500;
        $message = $status === 500 ? $fallbackMessage : $e->getMessage();

        $this->jsonError($message, $status);
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

        $this->enforceActiveAccount((int) $_SESSION['user_id']);
    }

    protected function requireAdmin(): void
    {
        $this->requireLogin();

        require_once __DIR__ . '/../repositories/UserRepository.php';
        $userRepository = new UsersRepository();
        $user = $userRepository->getUserById((int) $_SESSION['user_id']);

        if (!$user || !$user->isAdmin()) {
            http_response_code(403);
            echo 'Brak dostepu.';
            exit();
        }

        $_SESSION['account_type'] = $user->getAccountType();
    }

    protected function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    protected function validateCsrf(): void
    {
        $token = $_POST['csrf_token'] ?? '';
        if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            echo 'Nieprawidlowy token formularza.';
            exit();
        }
    }
 
    protected function render(?string $template = null, array $variables = [])
    {
        // Określamy ścieżkę bazową do widoków
        $basePath = __DIR__.'/../../public/views/';
        $__viewName = (string)$template;
        $variables = $this->prepareLayoutVariables($__viewName, $variables);
        
        $templatePath = $basePath . $__viewName . '.html';
        $headPath = $basePath . 'partials/head.html';
        $mediaFramePath = $basePath . 'partials/media-frame.html';
        $mediaUploadPath = $basePath . 'partials/media-upload.html';
        $navPath = $basePath . 'partials/nav.html';
        
        // Pobierz światy użytkownika dla nav (tylko jeśli jest zalogowany)
        if(file_exists($templatePath)){
            extract($variables);
            ob_start();
            if (file_exists($mediaFramePath)) {
                require_once $mediaFramePath;
            }
            if (file_exists($mediaUploadPath)) {
                require_once $mediaUploadPath;
            }
            
            // Dołączamy nagłówek
            if (file_exists($headPath)) {
                include $headPath;
            }

            
            
            // Jeśli to nie jest login, dołączamy nawigację
            if (!$this->isAuthTemplate($__viewName) && file_exists($navPath)) {
                include $navPath;
            }

            include $templatePath;

            // Domykamy tagi, jeśli to nie login
            if (!$this->isAuthTemplate($__viewName)) {
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

    private function prepareLayoutVariables(?string $template, array $variables): array
    {
        $variables['userSettings'] = $variables['userSettings'] ?? $this->getUserInterfaceSettings();

        if ($this->isAuthTemplate($template) || !isset($_SESSION['user_id'])) {
            return $variables;
        }

        $userId = (int) $_SESSION['user_id'];

        if (!isset($variables['worlds'])) {
            require_once __DIR__ . '/../repositories/WorldRepository.php';
            $worldRepository = new WorldRepository();
            $variables['worlds'] = $worldRepository->getChildWorlds(
                $userId,
                null,
                !empty($variables['userSettings']['revealHidden'])
            );
        }

        if (!isset($variables['storage'])) {
            $variables['storage'] = $this->getUserStorageStats($userId);
        }

        if (!isset($variables['adultImageFilenames'])) {
            $variables['adultImageFilenames'] = $this->getAdultImageFilenames($userId);
        }

        if (!isset($variables['isAdmin'])) {
            $variables['isAdmin'] = (int)($_SESSION['account_type'] ?? 0) === 1;
        }

        if (!isset($variables['pageEffect'])) {
            $variables['pageEffect'] = $this->getActivePageEffect();
        }

        return $variables;
    }

    private function isAuthTemplate(?string $template): bool
    {
        return in_array($template, self::AUTH_TEMPLATES, true);
    }

    private function getAdultImageFilenames(int $userId): array
    {
        try {
            require_once __DIR__ . '/../repositories/ImageRepository.php';
            return (new ImageRepository())->listAdultFilenames($userId);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function getActivePageEffect(): array
    {
        try {
            require_once __DIR__ . '/../repositories/SiteEffectRepository.php';
            return (new SiteEffectRepository())->activeEffect();
        } catch (Throwable $e) {
            return ['name' => 'none', 'symbols' => '', 'intensity' => 'medium'];
        }
    }

    protected function getUserStorageStats(int $userId): array
    {
        $limitBytes = 500 * 1024 * 1024;
        $bytes = 0;

        try {
            require_once __DIR__ . '/../repositories/ImageRepository.php';
            $bytes = (new ImageRepository())->getStorageBytes($userId);
        } catch (Throwable $e) {
            $bytes = 0;
        }

        $percent = $limitBytes > 0 ? (int) round(($bytes / $limitBytes) * 100) : 0;
        $usedMb = $bytes / 1024 / 1024;

        if ($percent >= 85) {
            $color = '#E74C3C';
        } elseif ($percent >= 50) {
            $color = '#F39C12';
        } else {
            $color = '#27AE60';
        }

        return [
            'usedMb' => $this->formatMegabytes($usedMb),
            'limitMb' => 500,
            'percent' => $percent,
            'barPercent' => min($percent, 100),
            'color' => $color,
            'isExceeded' => $bytes > $limitBytes,
        ];
    }

    protected function getUserInterfaceSettings(): array
    {
        $theme = $_COOKIE['oc_theme'] ?? 'light';
        if (!in_array($theme, ['light', 'dark'], true)) {
            $theme = 'light';
        }

        $accent = $_COOKIE['oc_accent'] ?? 'orange';
        if (!in_array($accent, ['orange', 'green', 'blue', 'purple', 'rose'], true)) {
            $accent = 'orange';
        }

        $columns = (int)($_COOKIE['oc_columns'] ?? 4);
        $columns = max(4, min(10, $columns));

        return [
            'theme' => $theme,
            'accent' => $accent,
            'columns' => $columns,
            'revealHidden' => ($_COOKIE['oc_reveal_hidden'] ?? '0') === '1',
            'revealAdultImages' => ($_COOKIE['oc_reveal_adult_images'] ?? '0') === '1',
            'rememberCharacterVariant' => ($_COOKIE['oc_remember_character_variant'] ?? '0') === '1',
            'defaultCharacterImage' => $theme === 'dark' ? 'default_dark.png' : 'default.png',
        ];
    }

    private function formatMegabytes(float $megabytes): string
    {
        if ($megabytes >= 10) {
            return number_format($megabytes, 0, '.', '') . ' MB';
        }

        return number_format($megabytes, 1, '.', '') . ' MB';
    }

    private function enforceActiveAccount(int $userId): void
    {
        require_once __DIR__ . '/../repositories/UserRepository.php';
        $userRepository = new UsersRepository();
        $user = $userRepository->getUserById($userId);

        if (!$user) {
            $this->destroySession();
            header('Location: /login');
            exit();
        }

        $_SESSION['account_type'] = $user->getAccountType();

        if ($this->isUserBanned($user)) {
            $message = 'Konto zablokowane do ' . $user->getBannedUntil();
            if ($user->getBanReason()) {
                $message .= '. Powod: ' . $user->getBanReason();
            }

            $this->destroySession();
            http_response_code(403);
            $this->render('login', ['messages' => [$message]]);
            exit();
        }
    }

    protected function isUserBanned(User $user): bool
    {
        $bannedUntil = $user->getBannedUntil();
        if (!$bannedUntil) {
            return false;
        }

        return strtotime($bannedUntil) > time();
    }

    protected function destroySession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        setcookie('oc_keep_logged_in', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => (
                (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            ),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_destroy();
    }
}

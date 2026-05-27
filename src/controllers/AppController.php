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
        $variables['userSettings'] = $variables['userSettings'] ?? $this->getUserInterfaceSettings();
        $authTemplates = ['login', 'register', 'forgot_password'];
        
        $templatePath = $basePath . $template . '.html';
        $headPath = $basePath . 'partials/head.html';
        $navPath = $basePath . 'partials/nav.html';
        
        // Pobierz światy użytkownika dla nav (tylko jeśli jest zalogowany)
        if (!in_array($template, $authTemplates, true) && isset($_SESSION['user_id'])) {
            if (!isset($variables['worlds'])) {
                require_once __DIR__ . '/../repositories/WorldRepository.php';
                $worldRepository = new WorldRepository();
                // Pobierz tylko pierwsze podfoldery (bez root-a)
                $variables['worlds'] = $worldRepository->getChildWorlds($_SESSION['user_id'], null);
            }

            if (!isset($variables['storage'])) {
                $variables['storage'] = $this->getUserStorageStats((int) $_SESSION['user_id']);
            }

            if (!isset($variables['isAdmin'])) {
                $variables['isAdmin'] = (int)($_SESSION['account_type'] ?? 0) === 1;
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
            if (!in_array($template, $authTemplates, true) && file_exists($navPath)) {
                include $navPath;
            }

            include $templatePath;

            // Domykamy tagi, jeśli to nie login
            if (!in_array($template, $authTemplates, true)) {
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

    protected function getUserStorageStats(int $userId): array
    {
        $limitBytes = 500 * 1024 * 1024;
        $bytes = 0;
        $filenames = [];

        try {
            require_once __DIR__ . '/../repositories/CharacterRepository.php';
            $characterRepository = new CharacterRepository();

            foreach ($characterRepository->getCharactersByUserId($userId) as $character) {
                $this->collectImageFilename($filenames, $character->getImage());

                foreach ($characterRepository->getCharacterVariants($character->getId()) as $variant) {
                    $this->collectImageFilename($filenames, $variant['image'] ?? null);
                }
            }

            $uploadDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
            foreach (array_keys($filenames) as $filename) {
                $path = $uploadDir . $filename;
                if (is_file($path)) {
                    $bytes += filesize($path) ?: 0;
                }
            }
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

    private function collectImageFilename(array &$filenames, ?string $image): void
    {
        $image = trim((string) $image);
        if ($image === '' || in_array($image, ['default.png', 'default.jpg', 'default_dark.png'], true)) {
            return;
        }

        $filename = basename($image);
        if ($filename !== '' && $filename === $image) {
            $filenames[$filename] = true;
        }
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

    private function destroySession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}

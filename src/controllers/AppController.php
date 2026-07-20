<?php

require_once __DIR__ . '/../services/LocaleService.php';
require_once __DIR__ . '/../repositories/SocialFeatureSettingsRepository.php';
require_once __DIR__ . '/../repositories/AccountTypeRepository.php';

class AppController {
    private const AUTH_TEMPLATES = ['login', 'register', 'forgot_password'];
    private const PUBLIC_TEMPLATES = ['public_publication', 'public_profile'];
    private const OFFLINE_DISABLED_FEATURES = [
        'community.enabled',
        'publications.enabled',
        'comments.enabled',
        'reactions.enabled',
        'follows.enabled',
        'messages.enabled',
        'reports.enabled',
        'copying.enabled',
        'public_search.enabled',
    ];
    private ?SocialFeatureSettingsRepository $siteFeatureSettingsRepository = null;

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

        $this->validateCsrfRequest(true);

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
        $this->applyOfflineUserSession();

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

    protected function requireFeatureEnabled(string $key, string $message, bool $json = false): void
    {
        if ($this->isFeatureEnabled($key)) {
            return;
        }

        if ($json) {
            $this->jsonError($message, 403);
        }

        http_response_code(403);
        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        exit();
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
        if (!$this->isValidCsrfRequest()) {
            http_response_code(403);
            echo 'Nieprawidlowy token formularza.';
            exit();
        }
    }

    protected function validateCsrfRequest(bool $json = false): void
    {
        if ($this->isValidCsrfRequest()) {
            return;
        }

        if ($json) {
            $this->jsonError('Nieprawidlowy token formularza.', 403);
        }

        http_response_code(403);
        echo 'Nieprawidlowy token formularza.';
        exit();
    }

    private function isValidCsrfRequest(): bool
    {
        $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');
        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');

        return $token !== '' && $sessionToken !== '' && hash_equals($sessionToken, $token);
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
        $chatWidgetPath = $basePath . 'partials/chat-widget.html';
        
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
            if (!$this->isChromelessTemplate($__viewName) && file_exists($navPath)) {
                include $navPath;
            }

            include $templatePath;

            // Domykamy tagi, jeśli to nie login
            if (!$this->isChromelessTemplate($__viewName)
                && isset($_SESSION['user_id'])
                && empty($variables['isOfflineMode'])
                && !empty($variables['siteFeatures']['messages'])
                && file_exists($chatWidgetPath)
            ) {
                include $chatWidgetPath;
            }

            if (!$this->isChromelessTemplate($__viewName)) {
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
        $locale = $this->currentLocale();
        $variables['userSettings'] = $variables['userSettings'] ?? $this->getUserInterfaceSettings();
        $variables['csrfToken'] = $variables['csrfToken'] ?? (isset($_SESSION['user_id']) ? $this->csrfToken() : '');
        $variables['locale'] = $variables['locale'] ?? $locale;
        $variables['supportedLocales'] = LocaleService::SUPPORTED_LOCALES;
        $variables['t'] = $variables['t'] ?? fn(string $key, array $params = []) => LocaleService::translate($key, $locale, $params);
        $variables['siteFeatureSettings'] = $variables['siteFeatureSettings'] ?? $this->siteFeatureSettings();
        $variables['isOfflineMode'] = $this->isOfflineMode();
        $variables['siteFeatures'] = $this->siteFeatureFlags();

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
            try {
                require_once __DIR__ . '/../repositories/UserRepository.php';
                $currentUser = (new UsersRepository())->getUserById($userId);
                $variables['isAdmin'] = $currentUser ? $currentUser->isAdmin() : false;
            } catch (Throwable $e) {
                $variables['isAdmin'] = (int)($_SESSION['account_type'] ?? 0) === 1;
            }
        }

        if (!isset($variables['currentUserProfile'])) {
            try {
                require_once __DIR__ . '/../repositories/UserRepository.php';
                $variables['currentUserProfile'] = (new UsersRepository())->getPublicProfileById($userId) ?? [];
            } catch (Throwable $e) {
                $variables['currentUserProfile'] = [];
            }
        }

        if (!isset($variables['pageEffect'])) {
            $variables['pageEffect'] = $this->getActivePageEffect();
        }

        return $variables;
    }

    protected function isOfflineMode(): bool
    {
        return !$this->featureSettingsRepository()->isEnabled('auth.login.enabled');
    }

    protected function isFeatureEnabled(string $key): bool
    {
        if ($this->isOfflineMode() && in_array($key, self::OFFLINE_DISABLED_FEATURES, true)) {
            return false;
        }

        if (isset($_SESSION['account_type'])) {
            return $this->featureSettingsRepository()->isEnabledForAccountType($key, (int)$_SESSION['account_type']);
        }

        return $this->featureSettingsRepository()->isEnabled($key);
    }

    protected function offlineUserId(): int
    {
        return $this->featureSettingsRepository()->integerValue('auth.offline_user_id', 0);
    }

    protected function applyOfflineUserSession(): bool
    {
        if (!$this->isOfflineMode()) {
            return false;
        }

        $offlineUserId = $this->offlineUserId();
        if ($offlineUserId <= 0) {
            return false;
        }

        require_once __DIR__ . '/../repositories/UserRepository.php';
        $user = (new UsersRepository())->getUserById($offlineUserId);
        if (!$user || $this->isUserBanned($user)) {
            return false;
        }

        $this->storeUserSession($user);

        return true;
    }

    protected function storeUserSession(User $user): void
    {
        $_SESSION['user_id'] = $user->getId();
        $_SESSION['email'] = $user->getEmail();
        $_SESSION['username'] = $user->getUsername();
        $_SESSION['first_name'] = $user->getFirstName();
        $_SESSION['last_name'] = $user->getLastName();
        $_SESSION['account_type'] = $user->getAccountType();
        $_SESSION['account_type_name'] = (new AccountTypeRepository())->nameForAccountType($user->getAccountType());
        $_SESSION['locale'] = $user->getLocale();
    }

    protected function siteFeatureSettings(): array
    {
        return $this->featureSettingsRepository()->all();
    }

    protected function siteFeatureFlags(): array
    {
        return [
            'community' => $this->isFeatureEnabled('community.enabled'),
            'publications' => $this->isFeatureEnabled('publications.enabled'),
            'comments' => $this->isFeatureEnabled('comments.enabled'),
            'reactions' => $this->isFeatureEnabled('reactions.enabled'),
            'follows' => $this->isFeatureEnabled('follows.enabled'),
            'messages' => $this->isFeatureEnabled('messages.enabled'),
            'reports' => $this->isFeatureEnabled('reports.enabled'),
            'copying' => $this->isFeatureEnabled('copying.enabled'),
            'publicSearch' => $this->isFeatureEnabled('public_search.enabled'),
            'characters' => $this->isFeatureEnabled('characters.enabled'),
            'relations' => $this->isFeatureEnabled('relations.enabled'),
            'stories' => $this->isFeatureEnabled('stories.enabled'),
            'gallery' => $this->isFeatureEnabled('gallery.enabled'),
            'login' => $this->isFeatureEnabled('auth.login.enabled'),
            'offlineMode' => $this->isOfflineMode(),
        ];
    }

    private function featureSettingsRepository(): SocialFeatureSettingsRepository
    {
        if ($this->siteFeatureSettingsRepository === null) {
            $this->siteFeatureSettingsRepository = new SocialFeatureSettingsRepository();
        }

        return $this->siteFeatureSettingsRepository;
    }

    private function isAuthTemplate(?string $template): bool
    {
        if (in_array($template, self::AUTH_TEMPLATES, true)) {
            return true;
        }

        return in_array($template, self::PUBLIC_TEMPLATES, true) && !isset($_SESSION['user_id']);
    }

    private function isChromelessTemplate(?string $template): bool
    {
        if ($this->isAuthTemplate($template)) {
            return true;
        }

        return $template === 'public_publication' && ($_GET['embed'] ?? '') === '1';
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
        $limitMb = $this->getUserStorageLimitMb($userId);
        $limitBytes = $limitMb * 1024 * 1024;
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
            'limitMb' => $limitMb,
            'percent' => $percent,
            'barPercent' => min($percent, 100),
            'color' => $color,
            'isExceeded' => $bytes > $limitBytes,
        ];
    }

    protected function getUserStorageLimitMb(int $userId): int
    {
        $accountType = 0;
        try {
            require_once __DIR__ . '/../repositories/UserRepository.php';
            $user = (new UsersRepository())->getUserById($userId);
            if ($user) {
                $accountType = $user->getAccountType();
            }
        } catch (Throwable $e) {
            if ((int)($_SESSION['user_id'] ?? 0) === $userId) {
                $accountType = (int)($_SESSION['account_type'] ?? 0);
            }
        }

        return $this->featureSettingsRepository()->storageQuotaMbForAccountType($accountType);
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

    protected function currentLocale(): string
    {
        return LocaleService::resolve(
            $_SESSION['locale'] ?? null,
            $_COOKIE['oc_locale'] ?? null,
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null
        );
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
        $_SESSION['account_type_name'] = (new AccountTypeRepository())->nameForAccountType($user->getAccountType());
        $_SESSION['locale'] = $user->getLocale();

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

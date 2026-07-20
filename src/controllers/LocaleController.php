<?php

require_once 'AppController.php';
require_once __DIR__ . '/../services/LocaleService.php';
require_once __DIR__ . '/../repositories/UserRepository.php';

class LocaleController extends AppController
{
    public function setLocale(): void
    {
        $locale = LocaleService::normalize($_POST['locale'] ?? $_GET['locale'] ?? null);
        $returnTo = $this->safeReturnUrl($_POST['return_to'] ?? $_GET['return_to'] ?? '/login');

        $_SESSION['locale'] = $locale;
        setcookie('oc_locale', $locale, [
            'expires' => time() + 60 * 60 * 24 * 365,
            'path' => '/',
            'samesite' => 'Lax',
        ]);

        if (isset($_SESSION['user_id'])) {
            (new UsersRepository())->setLocale((int)$_SESSION['user_id'], $locale);
        }

        header('Location: ' . $returnTo);
        exit();
    }

    private function safeReturnUrl(mixed $raw): string
    {
        $url = trim((string)$raw);
        if ($url === '') {
            return '/login';
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return '/login';
        }

        if (isset($parts['host']) && strcasecmp($parts['host'], $_SERVER['HTTP_HOST'] ?? '') !== 0) {
            return '/login';
        }

        $path = $parts['path'] ?? '';
        if ($path === '' || $path[0] !== '/') {
            return '/login';
        }

        return $path . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
    }
}

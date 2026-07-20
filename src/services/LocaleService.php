<?php

class LocaleService
{
    public const DEFAULT_LOCALE = 'pl';
    public const SUPPORTED_LOCALES = ['pl', 'en'];

    private static array $catalogues = [];

    public static function normalize(?string $locale): string
    {
        $locale = strtolower(trim((string)$locale));
        $locale = str_replace('_', '-', $locale);
        $short = substr($locale, 0, 2);

        return in_array($short, self::SUPPORTED_LOCALES, true) ? $short : self::DEFAULT_LOCALE;
    }

    public static function resolve(?string $sessionLocale, ?string $cookieLocale, ?string $acceptLanguage): string
    {
        if ($sessionLocale) {
            return self::normalize($sessionLocale);
        }

        if ($cookieLocale) {
            return self::normalize($cookieLocale);
        }

        foreach (explode(',', (string)$acceptLanguage) as $part) {
            $locale = self::normalize(explode(';', $part)[0] ?? '');
            if (in_array($locale, self::SUPPORTED_LOCALES, true)) {
                return $locale;
            }
        }

        return self::DEFAULT_LOCALE;
    }

    public static function translate(string $key, string $locale, array $params = []): string
    {
        $locale = self::normalize($locale);
        $value = self::catalogue($locale)[$key] ?? self::catalogue(self::DEFAULT_LOCALE)[$key] ?? '[[' . $key . ']]';

        foreach ($params as $name => $replacement) {
            $value = str_replace(':' . $name, (string)$replacement, $value);
        }

        return $value;
    }

    private static function catalogue(string $locale): array
    {
        if (!isset(self::$catalogues[$locale])) {
            $path = __DIR__ . '/../i18n/' . $locale . '.php';
            self::$catalogues[$locale] = is_file($path) ? require $path : [];
        }

        return self::$catalogues[$locale];
    }
}

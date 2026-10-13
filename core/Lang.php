<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Minimal translation layer for framework messages (validation today,
 * more tomorrow).
 *
 * Locale comes from config('app.locale', 'en'). Language files live in
 * lang/{locale}.php and return a nested array. Missing keys fall back
 * to the English file, then to the key itself.
 *
 * Placeholders use the :name syntax:
 *   Lang::line('validation.required', ['field' => 'email'])
 */
class Lang
{
    /** Base language tags written right-to-left. */
    protected const RTL = ['ar', 'he', 'fa', 'ur', 'ps', 'sd', 'ug', 'yi', 'ku', 'dv'];

    /** @var array<string, array> locale => loaded lines */
    protected static array $loaded = [];

    /** Current locale (config('app.locale'), default en) */
    public static function locale(): string
    {
        $locale = (string) App::getConfig('app.locale', 'en');
        return $locale !== '' ? $locale : 'en';
    }

    /** Translate a dot-key with :placeholder replacement. */
    public static function line(string $key, array $replace = []): string
    {
        $locale = static::locale();
        $line = static::lookup($key, $locale);
        if ($line === null && $locale !== 'en') {
            $line = static::lookup($key, 'en'); // fallback locale
        }
        if ($line === null) {
            return $key;
        }
        foreach ($replace as $search => $value) {
            $line = str_replace(':' . $search, (string) $value, $line);
        }
        return $line;
    }

    /**
     * Resolve a dot-path inside a loaded locale file. Supports both nested
     * arrays and flat keys with dots ('min.string' inside 'validation').
     */
    protected static function lookup(string $key, string $locale): ?string
    {
        if (!isset(static::$loaded[$locale])) {
            $file = App::basePath('') . '/lang/' . $locale . '.php';
            $data = is_file($file) ? require $file : [];
            static::$loaded[$locale] = is_array($data) ? $data : [];
        }
        $segments = explode('.', $key);
        $value = static::$loaded[$locale];
        $count = count($segments);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($value)) {
                return null;
            }
            $remaining = implode('.', array_slice($segments, $i));
            if ($remaining !== '' && array_key_exists($remaining, $value) && !is_array($value[$remaining])) {
                return is_string($value[$remaining]) ? $value[$remaining] : null;
            }
            if (!array_key_exists($segments[$i], $value)) {
                return null;
            }
            $value = $value[$segments[$i]];
        }
        return is_string($value) ? $value : null;
    }

    /** Does this locale (default: current) read right-to-left? */
    public static function isRtl(?string $locale = null): bool
    {
        $locale = $locale ?? static::locale();
        $base = strtolower(trim((string) preg_split('/[-_]/', $locale)[0]));
        return in_array($base, static::RTL, true);
    }

    /** Reset loaded translations (mainly for tests). */
    public static function reset(): void
    {
        static::$loaded = [];
    }
}

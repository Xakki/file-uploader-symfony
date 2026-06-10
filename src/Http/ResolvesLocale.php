<?php

declare(strict_types=1);

namespace Xakki\SymfonyFileUploader\Http;

/**
 * Resolves the request locale per Upload Protocol §5.1: the request `locale` field
 * (only when it is in the configured allow-list) → the bundle's `locale` default → `en`.
 * Used by the controllers so server-produced messages render via the shared core catalog.
 */
trait ResolvesLocale
{
    /**
     * @param  array<string, mixed>  $config  The bundle config (carries `locales` + `locale`).
     * @param  mixed  $requested  Raw `locale` field value from the request, if any.
     */
    private function resolveLocale(array $config, mixed $requested): string
    {
        $allowed = is_array($config['locales'] ?? null) ? $config['locales'] : [];

        if (is_string($requested) && $requested !== '' && in_array($requested, $allowed, true)) {
            return $requested;
        }

        $default = $config['locale'] ?? 'en';

        return is_string($default) && $default !== '' ? $default : 'en';
    }
}

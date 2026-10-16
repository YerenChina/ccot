<?php

namespace BushcraftCN\CCOT;

use Flarum\Foundation\ValidationException;
use Flarum\Settings\Event\Saving;

final class FooterSettings
{
    public const URL_KEY = 'bushcraftcn-ccot.organization_url';

    /** Public links must use an absolute web URL without embedded credentials. */
    public static function url(mixed $value): string
    {
        if (! is_string($value)) return '';
        $value = trim($value);
        $parts = parse_url($value);
        return strlen($value) <= 2048 && filter_var($value, FILTER_VALIDATE_URL)
            && is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']) ? $value : '';
    }

    public function handle(Saving $event): void
    {
        if (! array_key_exists(self::URL_KEY, $event->settings)) return;
        $value = $event->settings[self::URL_KEY];
        if (! is_string($value) || (trim($value) !== '' && self::url($value) === '')) {
            throw new ValidationException([self::URL_KEY => I18n::t('api.enter_a_complete_http_or_https_organization_url_without_a')]);
        }
        $event->settings[self::URL_KEY] = trim($value);
    }
}

<?php

namespace BushcraftCN\CCOT;

use Flarum\Settings\SettingsRepositoryInterface;

final class Registration
{
    public static function isOpen(SettingsRepositoryInterface $settings): bool
    {
        return (bool) $settings->get('allow_sign_up', true);
    }

    public static function requireOpen(SettingsRepositoryInterface $settings): void
    {
        if (! self::isOpen($settings)) throw new Problem(I18n::t('api.registration_is_paused_existing_accounts_can_still_log_in_or'), 403);
    }
}

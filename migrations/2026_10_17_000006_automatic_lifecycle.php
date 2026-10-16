<?php
return [
    'up' => function ($schema) {
        try {
            resolve(\BushcraftCN\CCOT\Setup::class)->prepare();
            resolve(\BushcraftCN\CCOT\Upgrade::class)->run();
            resolve(\BushcraftCN\CCOT\Setup::class)->detachLegacy();
        } catch (\Throwable $error) {
            throw new \Flarum\Foundation\ValidationException(['ccotSetup'=>\BushcraftCN\CCOT\I18n::t('api.automatic_setup_failed')]);
        }
    },
    // Native Flarum purge also runs migrations when CCOT is already disabled.
    'down' => function ($schema) { resolve(\BushcraftCN\CCOT\AccountLifecycle::class)->assertAuthorized(); },
];

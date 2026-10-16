<?php

namespace BushcraftCN\CCOT;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extend\LifecycleInterface;
use Flarum\Extension\Extension;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;

final class Lifecycle implements ExtenderInterface, LifecycleInterface
{
    public function extend(Container $container, ?Extension $extension = null): void {}

    public function onEnable(Container $container, Extension $extension): void
    {
        $settings = $container->make(SettingsRepositoryInterface::class);
        $keys = ['display_name_driver', 'flarum-nicknames.unique', 'flarum-nicknames.random_username', 'flarum-nicknames.set_on_registration', 'allow_sign_up'];
        $before = array_combine($keys, array_map(fn ($key) => $settings->get($key), $keys));
        try {
            $this->configure($container);
            $settings->set('allow_sign_up', '1');
        } catch (\Throwable $error) {
            // Flarum persists the enabled list before invoking this lifecycle hook.
            // Remove only this extension; migration rollback would destroy existing site data.
            $enabled = json_decode($settings->get('extensions_enabled', '[]'), true) ?: [];
            $settings->set('extensions_enabled', json_encode(array_values(array_filter($enabled, fn ($id) => $id !== $extension->getId())), JSON_THROW_ON_ERROR));
            foreach ($before as $key => $value) {
                if ($value === null) $settings->delete($key);
                else $settings->set($key, $value);
            }
            throw new \Flarum\Foundation\ValidationException(['ccotSetup'=>I18n::t('api.automatic_setup_failed')]);
        }
    }

    /** Repair prerequisites without changing the current registration switch. */
    public function configure(Container $container): void
    {
        $settings = $container->make(SettingsRepositoryInterface::class);
        if ((json_decode($settings->get('bushcraftcn-ccot.uninstall', '{}'), true)['phase'] ?? '') === 'purging') throw new \RuntimeException('CCOT: finish the interrupted uninstall before enabling');
        $setup = $container->make(Setup::class);
        $setup->prepare();
        $setup->detachLegacy();
        $container->make(Crypto::class);
        foreach (['display_name_driver' => 'nickname', 'flarum-nicknames.unique' => '1', 'flarum-nicknames.random_username' => '1', 'flarum-nicknames.set_on_registration' => '1'] as $key => $value) $settings->set($key, $value);
    }

    public function onDisable(Container $container, Extension $extension): void
    {
        // No site-level middleware survives disable. Native username login remains available.
        $container->make(Setup::class)->detachLegacy();
        $settings = $container->make(SettingsRepositoryInterface::class);
        $settings->set('allow_sign_up', '0');
        $settings->set('flarum-nicknames.random_username', '0');
    }
}

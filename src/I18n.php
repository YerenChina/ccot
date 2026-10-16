<?php

namespace BushcraftCN\CCOT;

use Flarum\Locale\PrefixedYamlFileLoader;
use Flarum\Locale\Translator;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Container\Container;

final class I18n
{
    private static array $fallbacks = [];

    public static function locale(): string
    {
        $container = Container::getInstance();
        return $container->bound(TranslatorInterface::class) ? $container->make(TranslatorInterface::class)->getLocale() : 'zh-Hans';
    }

    public static function t(string $key, array $parameters = []): string
    {
        $id = 'bushcraftcn-ccot.'.$key;
        $container = Container::getInstance();
        if ($container->bound(TranslatorInterface::class)) {
            $translated = $container->make(TranslatorInterface::class)->trans($id, $parameters);
            if ($translated !== $id) return $translated;
        }
        // Activation, migrations and isolated maintenance may run before locale resources load.
        $locale = self::locale();
        if (! isset(self::$fallbacks[$locale])) {
            $translator = new Translator($locale);
            $translator->setFallbackLocales(['en']);
            $translator->addLoader('prefixed_yaml', new PrefixedYamlFileLoader());
            foreach (['en', 'zh-Hans'] as $language) $translator->addResource('prefixed_yaml', ['file'=>__DIR__.'/../locale/'.$language.'.yml', 'prefix'=>''], $language, 'messages+intl-icu');
            self::$fallbacks[$locale] = $translator;
        }
        return self::$fallbacks[$locale]->trans($id, $parameters);
    }
}
